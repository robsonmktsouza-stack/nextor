<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\NfeDraft;
use App\Models\OperationNature;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class NfeDraftController extends Controller
{
    private const PAYMENT_TYPES=[
        '01'=>'Dinheiro',
        '02'=>'Cheque',
        '03'=>'Cartão de Crédito',
        '04'=>'Cartão de Débito',
        '05'=>'Crédito Loja',
        '06'=>'Crediário',
        '10'=>'Vale Alimentação',
        '11'=>'Vale Refeição',
        '12'=>'Vale Presente',
        '13'=>'Vale Combustível',
        '14'=>'Duplicata Mercantil',
        '15'=>'Boleto Bancário',
        '16'=>'Depósito Bancário',
        '17'=>'Pagamento Instantâneo (PIX)',
        '90'=>'Sem Pagamento',
        '99'=>'Outros',
    ];

    private const CARD_BRANDS=[
        '01'=>'Visa',
        '02'=>'Mastercard',
        '03'=>'American Express',
        '04'=>'Sorocred',
        '05'=>'Diners Club',
        '06'=>'Elo',
        '07'=>'Hipercard',
        '08'=>'Aura',
        '09'=>'Cabal',
        '99'=>'Outros',
    ];

    public function index(Request $request)
    {
        $term=trim((string)$request->query('search',''));

        $drafts=NfeDraft::query()
            ->with(['customer','operationNature','user'])
            ->when($term,fn($query)=>$query->where(function($where) use($term) {
                $like='%'.$term.'%';
                $where->where('id','like',$like)
                    ->orWhereHas('customer',fn($customer)=>$customer->where('name','like',$like))
                    ->orWhereHas('operationNature',fn($nature)=>$nature->where('name','like',$like));
            }))
            ->latest('updated_at')
            ->paginate(AppSetting::tablePerPage($request))
            ->withQueryString();

        return view('fiscal.nfe.drafts',compact('drafts','term'));
    }

    public function create()
    {
        return $this->editor(new NfeDraft([
            'status'=>'draft',
            'operation_type'=>'outbound',
            'destination'=>'auto',
            'presence'=>'presential',
            'purpose'=>'normal',
            'final_consumer'=>false,
            'issue_date'=>now()->toDateString(),
            'issue_time'=>now()->format('H:i'),
            'discount'=>0,
            'surcharge'=>0,
            'payment_type'=>(string)AppSetting::value('operations','default_payment_method','01'),
            'payment_condition'=>'a_vista',
            'environment'=>AppSetting::value('nfe','environment',AppSetting::value('fiscal','default_environment','homologation')),
            'series'=>(int)AppSetting::value('nfe','series',1),
        ]));
    }

    public function store(Request $request)
    {
        $draft=DB::transaction(function() use($request) {
            $draft=NfeDraft::query()->create(['user_id'=>auth()->id()]+$this->draftData($request));
            $this->replaceItems($draft,$request);
            return $draft->refresh();
        });

        if($request->input('after_save')==='validate') {
            return $this->validateAndRedirect($draft);
        }

        return redirect()->route('fiscal.nfe.edit',$draft)->with('success','Rascunho da NF-e salvo.');
    }

    public function edit(NfeDraft $nfeDraft)
    {
        $nfeDraft->load('items');
        return $this->editor($nfeDraft);
    }

    public function update(Request $request, NfeDraft $nfeDraft)
    {
        DB::transaction(function() use($request,$nfeDraft) {
            $nfeDraft->update($this->draftData($request)+['validated_at'=>null]);
            $this->replaceItems($nfeDraft,$request);
        });

        if($request->input('after_save')==='validate') {
            return $this->validateAndRedirect($nfeDraft->refresh());
        }

        return redirect()->route('fiscal.nfe.edit',$nfeDraft)->with('success','Rascunho da NF-e atualizado.');
    }

    public function validateDraft(NfeDraft $nfeDraft)
    {
        return $this->validateAndRedirect($nfeDraft);
    }

    public function destroy(NfeDraft $nfeDraft)
    {
        $nfeDraft->delete();
        return redirect()->route('fiscal.nfe.drafts.index')->with('success','Rascunho da NF-e excluído.');
    }

    private function validateAndRedirect(NfeDraft $draft)
    {
        $draft->load(['items','customer','operationNature']);
        $errors=[];

        if(!$draft->operationNature) $errors[]='Selecione uma natureza de operação.';
        if(!$draft->customer) $errors[]='Selecione o destinatário.';
        if(!$draft->customer?->document && !$draft->customer?->foreign_id) {
            $errors[]='O destinatário está sem CPF/CNPJ ou identificação de estrangeiro.';
        }

        $foreign=$this->isForeignCustomer($draft->customer);
        if($foreign) {
            if(!$draft->customer?->country_code) $errors[]='Destinatário do exterior sem código do país.';
            if(!$draft->customer?->foreign_id) $errors[]='Destinatário do exterior sem identificação de estrangeiro.';
        } else {
            if(!$draft->customer?->state) $errors[]='O destinatário está sem UF.';
            if(!$draft->customer?->city) $errors[]='O destinatário está sem município.';
            if(!$draft->customer?->city_ibge_code) $errors[]='O destinatário está sem código IBGE do município.';
        }

        if($draft->items->isEmpty()) $errors[]='Adicione pelo menos um produto.';

        foreach($draft->items as $item) {
            $prefix='Item '.$item->item_number.' — '.$item->product_name.': ';
            $tax=$item->tax_data ?? [];
            $issRate=(float)($tax['iss_rate'] ?? 0);

            if($item->ncm===null || trim((string)$item->ncm)==='') $errors[]=$prefix.'NCM não informado.';
            if($item->cfop===null || trim((string)$item->cfop)==='') $errors[]=$prefix.'CFOP não informado.';
            if($item->origin===null || trim((string)$item->origin)==='') $errors[]=$prefix.'origem da mercadoria não informada.';
            if($item->unit===null || trim((string)$item->unit)==='') $errors[]=$prefix.'unidade comercial não informada.';

            $inbound=$draft->operation_type==='inbound';
            $icmsCst=$inbound ? ($tax['icms_cst_inbound'] ?? $tax['icms_cst'] ?? null) : ($tax['icms_cst'] ?? null);
            $icmsCsosn=$inbound ? ($tax['icms_csosn_inbound'] ?? $tax['icms_csosn'] ?? null) : ($tax['icms_csosn'] ?? null);
            $pisCst=$inbound ? ($tax['pis_cst_inbound'] ?? $tax['pis_cst'] ?? null) : ($tax['pis_cst'] ?? null);
            $cofinsCst=$inbound ? ($tax['cofins_cst_inbound'] ?? $tax['cofins_cst'] ?? null) : ($tax['cofins_cst'] ?? null);
            $ipiCst=$inbound ? ($tax['ipi_cst_inbound'] ?? $tax['ipi_cst'] ?? null) : ($tax['ipi_cst'] ?? null);

            if($issRate<=0 && empty($icmsCst) && empty($icmsCsosn)) {
                $errors[]=$prefix.'CST/CSOSN do ICMS não informado.';
            }
            if(empty($pisCst)) $errors[]=$prefix.'CST do PIS não informado.';
            if(empty($cofinsCst)) $errors[]=$prefix.'CST da COFINS não informado.';
            if(empty($ipiCst)) $errors[]=$prefix.'CST do IPI não informado.';
        }

        if($errors) {
            return redirect()->route('fiscal.nfe.edit',$draft)
                ->with('warning',"A NF-e ainda possui pendências:\n".implode("\n",$errors));
        }

        $draft->update(['validated_at'=>now()]);
        return redirect()->route('fiscal.nfe.edit',$draft)
            ->with('success','Rascunho validado estruturalmente. A validação fiscal final será executada pelo ACBr antes da emissão.');
    }

    private function editor(NfeDraft $draft)
    {
        $draft->loadMissing('items');

        $customers=Customer::query()
            ->where('is_customer',true)
            ->with('deliveryAddresses')
            ->orderBy('name')
            ->get();

        $products=Product::query()
            ->where('is_active',true)
            ->orderBy('name')
            ->get();

        $natures=OperationNature::query()
            ->where('is_active',true)
            ->orderBy('name')
            ->get();

        $company=CompanySetting::current();

        $productData=$products->map(fn($product)=>[
            'id'=>$product->id,
            'name'=>$product->name,
            'sku'=>$product->sku,
            'sale_price'=>(float)$product->sale_price,
            'origin'=>$product->origin,
            'ean_gtin'=>$product->ean_gtin,
            'unit'=>$product->unit,
            'tax_unit'=>$product->tax_unit,
            'net_weight'=>$product->net_weight,
            'gross_weight'=>$product->gross_weight,
            'ncm'=>$product->ncm,
            'cest'=>$product->cest,
            'ipi_exception'=>$product->ipi_exception,
            'fiscal_benefit_code'=>$product->fiscal_benefit_code,
            'nfe_notes'=>$product->nfe_notes,
            'tax_defaults'=>$product->tax_defaults ?? [],
        ])->values()->all();

        $customerData=$customers->map(fn($customer)=>[
            'id'=>$customer->id,
            'name'=>$customer->name,
            'document'=>$customer->document,
            'foreign_id'=>$customer->foreign_id,
            'state_registration'=>$customer->state_registration,
            'ie_indicator'=>$customer->ie_indicator,
            'final_consumer'=>(bool)$customer->final_consumer,
            'zip_code'=>$customer->zip_code,
            'state'=>$customer->state,
            'city'=>$customer->city,
            'city_ibge_code'=>$customer->city_ibge_code,
            'country_code'=>$customer->country_code,
            'country_name'=>$customer->country_name,
            'address'=>$customer->address,
            'address_number'=>$customer->address_number,
            'address_complement'=>$customer->address_complement,
            'district'=>$customer->district,
        ])->values()->all();

        $natureData=$natures->map(fn($nature)=>[
            'id'=>$nature->id,
            'operation_type'=>$nature->operation_type,
            'purpose'=>$nature->purpose,
            'cfop_outbound_internal'=>$nature->cfop_internal,
            'cfop_outbound_interstate'=>$nature->cfop_interstate,
            'cfop_inbound_internal'=>$nature->cfop_inbound_internal,
            'cfop_inbound_interstate'=>$nature->cfop_inbound_interstate,
            'cfop_foreign'=>$nature->cfop_foreign,
            'override_product_cfop'=>(bool)$nature->override_product_cfop,
            'move_stock'=>(bool)$nature->move_stock,
            'additional_info'=>$nature->additional_info,
        ])->values()->all();

        return view('fiscal.nfe.editor',[
            'draft'=>$draft,
            'company'=>$company,
            'customers'=>$customers,
            'products'=>$products,
            'natures'=>$natures,
            'productData'=>$productData,
            'customerData'=>$customerData,
            'natureData'=>$natureData,
            'paymentTypes'=>self::PAYMENT_TYPES,
            'cardBrands'=>self::CARD_BRANDS,
            'isEditing'=>$draft->exists,
            'nextNumber'=>(int)AppSetting::value('nfe','next_number',1),
        ]);
    }

    private function draftData(Request $request): array
    {
        $data=$request->validate([
            'operation_nature_id'=>['required','integer','exists:operation_natures,id'],
            'customer_id'=>['required','integer','exists:customers,id'],
            'operation_type'=>['required',Rule::in(['outbound','inbound'])],
            'presence'=>['required',Rule::in(['not_applicable','presential','internet','phone','outside_establishment','other'])],
            'issue_date'=>['required','date'],
            'issue_time'=>['nullable','date_format:H:i'],
            'expected_delivery_date'=>['nullable','date'],
            'discount'=>['nullable','numeric','min:0'],
            'surcharge'=>['nullable','numeric','min:0'],

            'freight_mode'=>['required',Rule::in(['0','1','2','3','4','9'])],
            'freight_value'=>['nullable','numeric','min:0'],
            'carrier_id'=>['nullable','integer','exists:customers,id'],
            'vehicle_plate'=>['nullable','string','max:12'],
            'vehicle_state'=>['nullable','string','size:2'],
            'volume_quantity'=>['nullable','numeric','min:0'],
            'volume_species'=>['nullable','string','max:60'],
            'volume_numbering'=>['nullable','string','max:120'],
            'net_weight'=>['nullable','numeric','min:0'],
            'gross_weight'=>['nullable','numeric','min:0'],

            'payment_type'=>['required',Rule::in(array_keys(self::PAYMENT_TYPES))],
            'payment_condition'=>['required',Rule::in(['a_vista','30_dias','personalizado'])],
            'payment_other_description'=>['nullable','string','max:120'],
            'card_brand'=>['nullable',Rule::in(array_keys(self::CARD_BRANDS))],
            'card_acquirer_document'=>['nullable','string','max:20'],
            'card_authorization_code'=>['nullable','string','max:40'],

            'additional_info'=>['nullable','string','max:10000'],
            'duplicates_json'=>['nullable','string'],
            'references_json'=>['nullable','string'],
            'items_json'=>['required','string'],
        ]);

        $company=CompanySetting::current();
        $customer=Customer::query()->findOrFail((int)$data['customer_id']);
        $nature=OperationNature::query()->findOrFail((int)$data['operation_nature_id']);
        $carrier=$request->filled('carrier_id')
            ? Customer::query()->find((int)$request->input('carrier_id'))
            : null;

        $items=$this->decodeArray($data['items_json'],'itens');
        $discount=max(0,(float)($data['discount'] ?? 0));
        $surcharge=max(0,(float)($data['surcharge'] ?? 0));
        $freight=max(0,(float)($data['freight_value'] ?? 0));
        $totals=$this->calculateTotals($items,$discount,$surcharge,$freight);
        $destination=$this->resolveDestination($customer,$company);
        $purpose=$nature->purpose ?: 'normal';

        return [
            'operation_nature_id'=>$nature->id,
            'customer_id'=>$customer->id,
            'user_id'=>auth()->id(),
            'status'=>'draft',
            'operation_type'=>$data['operation_type'],
            'destination'=>$destination,
            'presence'=>$data['presence'],
            'purpose'=>$purpose,
            'final_consumer'=>(bool)$customer->final_consumer,
            'issue_date'=>$data['issue_date'],
            'issue_time'=>$data['issue_time'] ?? null,
            'exit_date'=>null,
            'exit_time'=>null,
            'expected_delivery_date'=>$data['expected_delivery_date'] ?? null,
            'government_purchase'=>false,
            'advance_payment'=>false,
            'different_delivery'=>false,
            'discount'=>$discount,
            'surcharge'=>$surcharge,
            'payment_type'=>$data['payment_type'],
            'payment_condition'=>$data['payment_condition'],
            'payment_other_description'=>$data['payment_other_description'] ?? null,
            'card_brand'=>$data['card_brand'] ?? null,
            'card_acquirer_document'=>$data['card_acquirer_document'] ?? null,
            'card_authorization_code'=>$data['card_authorization_code'] ?? null,
            'series'=>(int)AppSetting::value('nfe','series',1),
            'document_number'=>null,
            'environment'=>(string)AppSetting::value('nfe','environment',AppSetting::value('fiscal','default_environment','homologation')),
            'emitter_snapshot'=>$company->only([
                'document','legal_name','trade_name','state_registration','municipal_registration','cnae_main',
                'phone','email','zip_code','state','city','city_ibge_code','address','address_number',
                'address_complement','district','tax_regime','crt'
            ]),
            'recipient_snapshot'=>$customer->only([
                'name','trade_name','document','foreign_id','email','phone','zip_code','state','city',
                'city_ibge_code','country_code','country_name','address','address_number','address_complement',
                'district','final_consumer','ie_indicator','state_registration','substitute_state_registration',
                'municipal_registration','suframa'
            ]),
            'delivery_snapshot'=>null,
            'transport_data'=>[
                'freight_mode'=>$data['freight_mode'],
                'freight_value'=>$freight,
                'carrier_id'=>$carrier?->id,
                'carrier'=>$carrier?->only([
                    'name','document','state_registration','rntrc','state','city','city_ibge_code',
                    'address','address_number'
                ]),
                'vehicle_plate'=>$data['vehicle_plate'] ?? null,
                'vehicle_state'=>$data['vehicle_state'] ?? null,
                'volume_quantity'=>$data['volume_quantity'] ?? null,
                'volume_species'=>$data['volume_species'] ?? null,
                'volume_numbering'=>$data['volume_numbering'] ?? null,
                'net_weight'=>$data['net_weight'] ?? null,
                'gross_weight'=>$data['gross_weight'] ?? null,
            ],
            'invoice_data'=>[
                'number'=>null,
                'original_value'=>round($totals['products']+$surcharge,2),
                'discount'=>$discount,
                'net_value'=>round($totals['products']-$discount+$surcharge,2),
            ],
            'duplicates'=>$this->decodeArray($data['duplicates_json'] ?? null,'duplicatas'),
            'payments'=>[[
                'type'=>$data['payment_type'],
                'condition'=>$data['payment_condition'],
                'amount'=>$data['payment_type']==='90' ? 0 : round($totals['products']-$discount+$surcharge,2),
                'other_description'=>$data['payment_other_description'] ?? null,
                'card_brand'=>$data['card_brand'] ?? null,
                'acquirer_document'=>$data['card_acquirer_document'] ?? null,
                'authorization_code'=>$data['card_authorization_code'] ?? null,
            ]],
            'references'=>$this->validatedReferences($data['references_json'] ?? null),
            'custom_fields'=>[],
            'additional_info'=>$data['additional_info'] ?? $nature->additional_info,
            'tax_authority_info'=>null,
            'totals'=>$totals,
        ];
    }

    private function replaceItems(NfeDraft $draft, Request $request): void
    {
        $items=$this->decodeArray((string)$request->input('items_json'),'itens');
        if(!$items) {
            throw ValidationException::withMessages(['items_json'=>'Adicione pelo menos um produto à NF-e.']);
        }

        $draft->items()->delete();
        $nature=OperationNature::query()->find($draft->operation_nature_id);
        $customer=Customer::query()->find($draft->customer_id);
        $company=CompanySetting::current();

        foreach(array_values($items) as $index=>$item) {
            $productId=(int)($item['product_id'] ?? 0);
            $product=$productId>0 ? Product::query()->find($productId) : null;

            if($productId>0 && !$product) {
                throw ValidationException::withMessages([
                    'items_json'=>'Um dos produtos vinculados à NF-e não existe mais. Remova o vínculo ou selecione outro produto.',
                ]);
            }

            $productName=trim((string)($item['product_name'] ?? $product?->name ?? ''));
            if($productName==='') {
                throw ValidationException::withMessages([
                    'items_json'=>'Todo item da NF-e precisa ter uma descrição.',
                ]);
            }

            $quantity=max(0,(float)($item['quantity'] ?? 0));
            $dimensionQuantity=max(0,(float)($item['dimension_quantity'] ?? 1));
            if($dimensionQuantity<=0) $dimensionQuantity=1;
            $unitPrice=max(0,(float)($item['unit_price'] ?? 0));
            $lineTotal=max(0,$quantity*$dimensionQuantity*$unitPrice);

            $taxData=array_replace(
                is_array($product?->tax_defaults) ? $product->tax_defaults : [],
                is_array($item['tax_data'] ?? null) ? $item['tax_data'] : []
            );

            $cfop=trim((string)($item['cfop'] ?? ''));
            if($cfop==='') {
                $cfop=$this->resolveItemCfop(
                    $nature,
                    $customer,
                    $company,
                    $draft->operation_type,
                    $taxData
                );
            }

            $draft->items()->create([
                'product_id'=>$product?->id,
                'item_number'=>$index+1,
                'product_name'=>$productName,
                'product_sku'=>$item['product_sku'] ?? $product?->sku,
                'quantity'=>$quantity,
                'dimension_quantity'=>$dimensionQuantity,
                'unit_price'=>$unitPrice,
                'freight'=>0,
                'insurance'=>0,
                'other_expenses'=>0,
                'discount'=>0,
                'line_total'=>$lineTotal,
                'cfop'=>$cfop ?: null,
                'origin'=>$item['origin'] ?? $taxData['origin'] ?? $product?->origin,
                'ean_gtin'=>$item['ean_gtin'] ?? $product?->ean_gtin,
                'unit'=>$item['unit'] ?? $product?->unit,
                'tax_unit'=>$item['tax_unit'] ?? $product?->tax_unit ?? $product?->unit,
                'ncm'=>$item['ncm'] ?? $product?->ncm,
                'cest'=>$item['cest'] ?? $product?->cest,
                'ipi_exception'=>$item['ipi_exception'] ?? $product?->ipi_exception,
                'fiscal_benefit_code'=>$item['fiscal_benefit_code'] ?? $product?->fiscal_benefit_code,
                'purchase_order'=>$item['purchase_order'] ?? null,
                'purchase_order_item'=>$item['purchase_order_item'] ?? null,
                'notes'=>$item['notes'] ?? $product?->nfe_notes,
                'tax_data'=>$taxData,
                'special_data'=>is_array($item['special_data'] ?? null) ? $item['special_data'] : [],
            ]);
        }

        $storedItems=$draft->items()->get()->map(fn($item)=>[
            'quantity'=>(float)$item->quantity,
            'dimension_quantity'=>(float)$item->dimension_quantity,
            'unit_price'=>(float)$item->unit_price,
            'tax_data'=>$item->tax_data ?? [],
        ])->all();

        $freight=(float)data_get($draft->transport_data,'freight_value',0);
        $totals=$this->calculateTotals($storedItems,(float)$draft->discount,(float)$draft->surcharge,$freight);

        $draft->update([
            'totals'=>$totals,
            'invoice_data'=>[
                'number'=>null,
                'original_value'=>round($totals['products']+(float)$draft->surcharge,2),
                'discount'=>(float)$draft->discount,
                'net_value'=>round($totals['products']-(float)$draft->discount+(float)$draft->surcharge,2),
            ],
            'payments'=>[[
                'type'=>$draft->payment_type,
                'condition'=>$draft->payment_condition,
                'amount'=>$draft->payment_type==='90' ? 0 : round($totals['products']-(float)$draft->discount+(float)$draft->surcharge,2),
                'other_description'=>$draft->payment_other_description,
                'card_brand'=>$draft->card_brand,
                'acquirer_document'=>$draft->card_acquirer_document,
                'authorization_code'=>$draft->card_authorization_code,
            ]],
        ]);
    }

    private function resolveItemCfop(
        ?OperationNature $nature,
        ?Customer $customer,
        CompanySetting $company,
        string $operationType,
        array $taxData
    ): ?string {
        $interstate=$customer?->state && $company->state && $customer->state!==$company->state;
        $foreign=$this->isForeignCustomer($customer);

        $natureCfop=null;
        if($nature) {
            if($foreign && $nature->cfop_foreign) {
                $natureCfop=$nature->cfop_foreign;
            } elseif($operationType==='inbound') {
                $natureCfop=$interstate
                    ? ($nature->cfop_inbound_interstate ?: $nature->cfop_inbound_internal)
                    : $nature->cfop_inbound_internal;
            } else {
                $natureCfop=$interstate
                    ? ($nature->cfop_interstate ?: $nature->cfop_internal)
                    : $nature->cfop_internal;
            }
        }

        $productKey=$operationType==='inbound'
            ? ($interstate ? 'cfop_inbound_interstate' : 'cfop_inbound_internal')
            : ($interstate ? 'cfop_outbound_interstate' : 'cfop_outbound_internal');

        $productCfop=trim((string)($taxData[$productKey] ?? $taxData['cfop'] ?? ''));

        if($nature?->override_product_cfop) return $natureCfop ?: ($productCfop ?: null);
        return $productCfop ?: $natureCfop;
    }

    private function resolveDestination(Customer $customer, CompanySetting $company): string
    {
        if($this->isForeignCustomer($customer)) return 'foreign';
        if($customer->state && $company->state && $customer->state!==$company->state) return 'interstate';
        return 'internal';
    }

    private function isForeignCustomer(?Customer $customer): bool
    {
        if(!$customer) return false;
        $country=preg_replace('/\D/','',(string)$customer->country_code);
        return $customer->state==='EX' || ($country!=='' && $country!=='1058');
    }

    private function calculateTotals(array $items,float $discount=0,float $surcharge=0,float $freight=0): array
    {
        $totals=[
            'products'=>0.0,
            'freight'=>max(0,$freight),
            'discount'=>max(0,$discount),
            'surcharge'=>max(0,$surcharge),
            'ipi'=>0.0,
            'total'=>0.0,
        ];

        foreach($items as $item) {
            $quantity=max(0,(float)($item['quantity'] ?? 0));
            $dimension=max(0,(float)($item['dimension_quantity'] ?? 1));
            if($dimension<=0) $dimension=1;
            $value=$quantity*$dimension*max(0,(float)($item['unit_price'] ?? 0));
            $totals['products']+=$value;

            $tax=is_array($item['tax_data'] ?? null) ? $item['tax_data'] : [];
            $ipiRate=max(0,(float)($tax['ipi_rate'] ?? 0));
            if($ipiRate>0) $totals['ipi']+=$value*($ipiRate/100);
        }

        $totals['total']=max(
            0,
            $totals['products']+$totals['freight']+$totals['surcharge']+$totals['ipi']-$totals['discount']
        );

        return array_map(fn($value)=>round($value,2),$totals);
    }

    private function validatedReferences(?string $json): array
    {
        $references=$this->decodeArray($json,'documentos referenciados');

        foreach($references as $reference) {
            $key=preg_replace('/\D/','',(string)($reference['key'] ?? ''));
            if(strlen($key)!==44) {
                throw ValidationException::withMessages([
                    'references_json'=>'Toda NF-e referenciada deve possuir chave de acesso com 44 dígitos.',
                ]);
            }
        }

        return array_values(array_map(fn($reference)=>[
            'key'=>preg_replace('/\D/','',(string)($reference['key'] ?? '')),
        ],$references));
    }

    private function decodeArray(?string $json,string $label): array
    {
        if($json===null || trim($json)==='') return [];
        $decoded=json_decode($json,true);
        if(!is_array($decoded)) {
            throw ValidationException::withMessages([$label=>"Conteúdo inválido em {$label}."]);
        }
        return $decoded;
    }
}
