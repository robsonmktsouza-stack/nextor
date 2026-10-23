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
            'destination'=>'internal',
            'presence'=>'not_applicable',
            'purpose'=>'normal',
            'final_consumer'=>false,
            'has_referenced_document'=>false,
            'inform_issue_datetime'=>true,
            'inform_exit_datetime'=>false,
            'inform_expected_delivery_date'=>false,
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
            if($nfeDraft->status!=='draft')abort(409,'NF-e já encaminhada ao processamento fiscal.');
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
        abort_unless($nfeDraft->status==='draft',409);
        return $this->validateAndRedirect($nfeDraft);
    }

    public function destroy(NfeDraft $nfeDraft)
    {
        abort_unless($nfeDraft->status==='draft',409);
        $nfeDraft->delete();
        return redirect()->route('fiscal.nfe.drafts.index')->with('success','Rascunho da NF-e excluído.');
    }

    private function validateAndRedirect(NfeDraft $draft)
    {
        abort_unless($draft->status==='draft',409);
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

        if($draft->has_referenced_document && empty($draft->references)) {
            $errors[]='Informe ao menos um documento referenciado.';
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
            'stock_quantity'=>(float)$product->stock_quantity,
            'control_stock'=>(bool)$product->control_stock,
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
            'delivery_addresses'=>$customer->deliveryAddresses->map(fn($a)=>[
                'id'=>$a->id,'name'=>$a->name,'document'=>$a->document,
                'zip_code'=>$a->zip_code,'address'=>$a->address,
                'address_number'=>$a->address_number,'address_complement'=>$a->address_complement,
                'district'=>$a->district,'city'=>$a->city,'state'=>$a->state,
                'city_ibge_code'=>$a->city_ibge_code,
            ])->values()->all(),
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
            'presence_default'=>$nature->presence_default,
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
            'taxDefaults'=>AppSetting::groupValues('tax',[]),
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
            'destination'=>['nullable',Rule::in(['internal','interstate','foreign'])],
            'presence'=>['required',Rule::in(['not_applicable','presential','internet','phone','delivery_home','outside_establishment','other'])],
            'purpose'=>['nullable',Rule::in(['normal','complementary','adjustment','return','credit_note','debit_note'])],
            'substitute_state_registration'=>['nullable','string','max:30'],
            'has_referenced_document'=>['nullable','boolean'],
            'inform_issue_datetime'=>['nullable','boolean'],
            'inform_exit_datetime'=>['nullable','boolean'],
            'inform_expected_delivery_date'=>['nullable','boolean'],
            'issue_date'=>['nullable','date'],
            'issue_time'=>['nullable','date_format:H:i'],
            'exit_date'=>['nullable','date'],
            'exit_time'=>['nullable','date_format:H:i'],
            'expected_delivery_date'=>['nullable','date'],
            'government_purchase'=>['nullable','boolean'],
            'advance_payment'=>['nullable','boolean'],
            'different_delivery'=>['nullable','boolean'],
            'delivery_name'=>['nullable','string','max:190'],
            'delivery_document'=>['nullable','string','max:20'],
            'delivery_zip_code'=>['nullable','string','max:9'],
            'delivery_address'=>['nullable','string','max:190'],
            'delivery_address_number'=>['nullable','string','max:30'],
            'delivery_address_complement'=>['nullable','string','max:120'],
            'delivery_district'=>['nullable','string','max:120'],
            'delivery_city'=>['nullable','string','max:120'],
            'delivery_state'=>['nullable','string','size:2'],
            'delivery_city_ibge_code'=>['nullable','string','max:7'],
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
            'payments_json'=>['nullable','string'],
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
        $payments=$this->validatedPayments($request,$data,$totals);
        $destination=$data['destination'] ?? $this->resolveDestination($customer,$company);
        $purpose=$data['purpose'] ?? ($nature->purpose ?: 'normal');
        $hasReferencedDocument=$request->boolean('has_referenced_document');
        $informIssueDatetime=$request->boolean('inform_issue_datetime',true);
        $informExitDatetime=$request->boolean('inform_exit_datetime');
        $informExpectedDeliveryDate=$request->boolean('inform_expected_delivery_date');

        if($informIssueDatetime && empty($data['issue_date'])) {
            throw ValidationException::withMessages(['issue_date'=>'Informe a data de emissão.']);
        }
        if($informExitDatetime && empty($data['exit_date'])) {
            throw ValidationException::withMessages(['exit_date'=>'Informe a data de saída/entrada.']);
        }
        if($informExpectedDeliveryDate && empty($data['expected_delivery_date'])) {
            throw ValidationException::withMessages(['expected_delivery_date'=>'Informe a previsão de entrega.']);
        }

        $references=$hasReferencedDocument
            ? $this->validatedReferences($data['references_json'] ?? null)
            : [];

        $differentDelivery=$request->boolean('different_delivery');
        $delivery=null;
        if($differentDelivery){
            foreach(['zip_code','address','address_number','district','city','state','city_ibge_code'] as $field){
                if(trim((string)($data['delivery_'.$field]??''))===''){
                    throw ValidationException::withMessages(['delivery_'.$field=>'Informe o endereço de entrega completo.']);
                }
            }
            $zip=preg_replace('/\D/','',(string)$data['delivery_zip_code']);
            $cityCode=preg_replace('/\D/','',(string)$data['delivery_city_ibge_code']);
            $document=preg_replace('/\D/','',(string)($data['delivery_document']??''));
            if(strlen($zip)!==8 || strlen($cityCode)!==7 || !preg_match('/^[A-Z]{2}$/',strtoupper($data['delivery_state']))
                || ($document!==''&&!in_array(strlen($document),[11,14],true))) {
                throw ValidationException::withMessages(['different_delivery'=>'Confira CEP, município, UF e CPF/CNPJ do local de entrega.']);
            }
            $delivery=[
                'name'=>$data['delivery_name']??null,'document'=>$document?:null,
                'zip_code'=>$zip,'address'=>$data['delivery_address'],
                'address_number'=>$data['delivery_address_number'],
                'address_complement'=>$data['delivery_address_complement']??null,
                'district'=>$data['delivery_district'],'city'=>$data['delivery_city'],
                'state'=>strtoupper($data['delivery_state']),'city_ibge_code'=>$cityCode,
            ];
        }
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
            'substitute_state_registration'=>$data['substitute_state_registration'] ?? null,
            'has_referenced_document'=>$hasReferencedDocument,
            'inform_issue_datetime'=>$informIssueDatetime,
            'inform_exit_datetime'=>$informExitDatetime,
            'inform_expected_delivery_date'=>$informExpectedDeliveryDate,
            'issue_date'=>$informIssueDatetime ? $data['issue_date'] : now()->toDateString(),
            'issue_time'=>$informIssueDatetime ? ($data['issue_time'] ?? null) : now()->format('H:i'),
            'exit_date'=>$informExitDatetime ? ($data['exit_date'] ?? null) : null,
            'exit_time'=>$informExitDatetime ? ($data['exit_time'] ?? null) : null,
            'expected_delivery_date'=>$informExpectedDeliveryDate ? ($data['expected_delivery_date'] ?? null) : null,
            'government_purchase'=>$request->boolean('government_purchase'),
            'advance_payment'=>$request->boolean('advance_payment'),
            'different_delivery'=>$differentDelivery,
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
            'delivery_snapshot'=>$delivery,
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
                'original_value'=>round($totals['total']+$totals['discount'],2),
                'discount'=>$totals['discount'],
                'net_value'=>$totals['total'],
            ],
            'duplicates'=>$this->decodeArray($data['duplicates_json'] ?? null,'duplicatas'),
            'payments'=>$payments,
            'references'=>$references,
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
            $parts=[];
            foreach(['freight','insurance','other_expenses','discount'] as $part){
                $value=$item[$part]??0;
                if(!is_numeric($value)||(float)$value<0||(float)$value>99999999){
                    throw ValidationException::withMessages(['items_json'=>'Confira os valores adicionais dos itens da NF-e.']);
                }
                $parts[$part]=round((float)$value,2);
            }
            $gross=round($quantity*$dimensionQuantity*$unitPrice,2);
            if($parts['discount']>$gross+$parts['freight']+$parts['insurance']+$parts['other_expenses']){
                throw ValidationException::withMessages(['items_json'=>'O desconto do item supera seu valor.']);
            }
            $lineTotal=max(0,$gross+$parts['freight']+$parts['insurance']+$parts['other_expenses']-$parts['discount']);

            $taxData=array_replace(
                is_array($product?->tax_defaults) ? $product->tax_defaults : [],
                is_array($item['tax_data'] ?? null) ? $item['tax_data'] : []
            );

            $cfop=trim((string)($item['cfop'] ?? ''));
            if($cfop==='') {
                $cfop=$this->resolveItemCfop(
                    $nature,
                    $draft->destination,
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
                'freight'=>$parts['freight'],
                'insurance'=>$parts['insurance'],
                'other_expenses'=>$parts['other_expenses'],
                'discount'=>$parts['discount'],
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
            'freight'=>(float)$item->freight,
            'insurance'=>(float)$item->insurance,
            'other_expenses'=>(float)$item->other_expenses,
            'discount'=>(float)$item->discount,
            'tax_data'=>$item->tax_data ?? [],
        ])->all();

        $freight=(float)data_get($draft->transport_data,'freight_value',0);
        $totals=$this->calculateTotals($storedItems,(float)$draft->discount,(float)$draft->surcharge,$freight);

        $draft->update([
            'totals'=>$totals,
            'invoice_data'=>[
                'number'=>null,
                'original_value'=>round($totals['total']+$totals['discount'],2),
                'discount'=>$totals['discount'],
                'net_value'=>$totals['total'],
            ],
        ]);
    }

    private function resolveItemCfop(
        ?OperationNature $nature,
        ?string $destination,
        string $operationType,
        array $taxData
    ): ?string {
        $interstate=$destination==='interstate';
        $foreign=$destination==='foreign';

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
            'insurance'=>0.0,
            'other_expenses'=>0.0,
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
            foreach(['freight','insurance','other_expenses'] as $field){
                $totals[$field]+=max(0,(float)($item[$field]??0));
            }
            $totals['discount']+=max(0,(float)($item['discount']??0));

            $tax=is_array($item['tax_data'] ?? null) ? $item['tax_data'] : [];
            $ipiRate=max(0,(float)($tax['ipi_rate'] ?? 0));
            if($ipiRate>0) $totals['ipi']+=$value*($ipiRate/100);
        }

        $totals['total']=max(
            0,
            $totals['products']+$totals['freight']+$totals['insurance']+$totals['other_expenses']
                +$totals['surcharge']+$totals['ipi']-$totals['discount']
        );

        return array_map(fn($value)=>round($value,2),$totals);
    }

    private function validatedPayments(Request $request,array $data,array $totals): array
    {
        $additional=$this->decodeArray($data['payments_json']??null,'pagamentos');
        if(count($additional)>19){
            throw ValidationException::withMessages(['payments_json'=>'Limite de 20 formas de pagamento por NF-e.']);
        }
        $paymentType=(string)$data['payment_type'];
        if($paymentType==='90' && $additional){
            throw ValidationException::withMessages(['payments_json'=>'Sem pagamento não pode ser combinado com outras formas.']);
        }
        $totalCents=(int)round((float)$totals['total']*100,0,PHP_ROUND_HALF_UP);
        $additionalCents=0;
        $normalized=[];
        foreach($additional as $index=>$payment){
            if(!is_array($payment)||!in_array((string)($payment['type']??''),array_keys(self::PAYMENT_TYPES),true)
                ||(string)($payment['type']??'')==='90'
                ||!is_numeric($payment['amount']??null)
                ||!in_array((string)($payment['condition']??'a_vista'),['a_vista','30_dias','personalizado'],true)){
                throw ValidationException::withMessages(['payments_json'=>'Confira as formas de pagamento adicionais.']);
            }
            $amount=(float)$payment['amount'];
            if($amount<=0||$amount>999999999){
                throw ValidationException::withMessages(['payments_json'=>'Valor de pagamento inválido.']);
            }
            $cents=(int)round($amount*100,0,PHP_ROUND_HALF_UP);
            $additionalCents+=$cents;
            $type=(string)$payment['type'];
            $description=trim((string)($payment['other_description']??''));
            if($type==='99' && ($description===''||mb_strlen($description)>120)){
                throw ValidationException::withMessages(['payments_json'=>'Informe a descrição do pagamento Outros.']);
            }
            $normalized[]=[
                'type'=>$type,'condition'=>$payment['condition']??'a_vista',
                'amount'=>$cents/100,
                'other_description'=>$type==='99'?$description:null,
                'card_brand'=>in_array($type,['03','04'],true)?($payment['card_brand']??null):null,
                'acquirer_document'=>in_array($type,['03','04'],true)?($payment['acquirer_document']??null):null,
                'authorization_code'=>in_array($type,['03','04'],true)?($payment['authorization_code']??null):null,
            ];
        }
        if($additionalCents>$totalCents){
            throw ValidationException::withMessages(['payments_json'=>'Os pagamentos adicionais superam o total da NF-e.']);
        }
        $main=$paymentType==='90'?0:$totalCents-$additionalCents;
        if($paymentType!=='90' && $main<=0){
            throw ValidationException::withMessages(['payment_type'=>'O pagamento principal deve possuir valor positivo.']);
        }
        $first=[
            'type'=>$paymentType,'condition'=>$data['payment_condition'],
            'amount'=>$main/100,
            'other_description'=>$paymentType==='99'?($data['payment_other_description']??null):null,
            'card_brand'=>in_array($paymentType,['03','04'],true)?($data['card_brand']??null):null,
            'acquirer_document'=>in_array($paymentType,['03','04'],true)?($data['card_acquirer_document']??null):null,
            'authorization_code'=>in_array($paymentType,['03','04'],true)?($data['card_authorization_code']??null):null,
        ];
        if($paymentType==='99' && trim((string)$first['other_description'])===''){
            throw ValidationException::withMessages(['payment_other_description'=>'Informe a descrição do pagamento Outros.']);
        }
        return array_merge([$first],$normalized);
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
