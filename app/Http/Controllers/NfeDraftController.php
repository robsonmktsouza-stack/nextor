<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\NfeDraft;
use App\Models\OperationNature;
use App\Models\PaymentMethod;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class NfeDraftController extends Controller
{
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
            'presence'=>'not_applicable',
            'purpose'=>'normal',
            'final_consumer'=>false,
            'issue_date'=>now()->toDateString(),
            'issue_time'=>now()->format('H:i'),
            'environment'=>AppSetting::value('nfe','environment',AppSetting::value('fiscal','default_environment','homologation')),
            'series'=>(int)AppSetting::value('nfe','series',1),
        ]));
    }

    public function store(Request $request)
    {
        $draft=DB::transaction(function() use($request) {
            $draft=NfeDraft::query()->create(['user_id'=>auth()->id()]+$this->draftData($request));
            $this->replaceItems($draft,$request);
            return $draft;
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
            return $this->validateAndRedirect($nfeDraft);
        }

        return redirect()->route('fiscal.nfe.edit',$nfeDraft)->with('success','Rascunho da NF-e atualizado.');
    }

    public function validateDraft(NfeDraft $nfeDraft)
    {
        return $this->validateAndRedirect($nfeDraft);
    }

    private function validateAndRedirect(NfeDraft $nfeDraft)
    {
        $nfeDraft->load(['items','customer','operationNature']);
        $errors=[];

        if(!$nfeDraft->operationNature) $errors[]='Selecione uma natureza de operação.';
        if(!$nfeDraft->customer) $errors[]='Selecione o destinatário.';
        if(!$nfeDraft->customer?->document) $errors[]='O destinatário está sem CPF/CNPJ.';
        if(!$nfeDraft->customer?->state) $errors[]='O destinatário está sem UF.';
        if($nfeDraft->items->isEmpty()) $errors[]='Adicione pelo menos um produto.';

        foreach($nfeDraft->items as $item) {
            $prefix='Item '.$item->item_number.' — '.$item->product_name.': ';
            if(!$item->ncm) $errors[]=$prefix.'NCM não informado.';
            if(!$item->cfop) $errors[]=$prefix.'CFOP não informado.';
            if(!$item->origin) $errors[]=$prefix.'origem da mercadoria não informada.';
            if(!$item->unit) $errors[]=$prefix.'unidade comercial não informada.';

            $tax=$item->tax_data ?? [];
            if(empty($tax['icms_cst']) && empty($tax['icms_csosn'])) {
                $errors[]=$prefix.'CST/CSOSN do ICMS não informado.';
            }
            if(empty($tax['pis_cst'])) $errors[]=$prefix.'CST do PIS não informado.';
            if(empty($tax['cofins_cst'])) $errors[]=$prefix.'CST da COFINS não informado.';
        }

        if($errors) {
            return redirect()->route('fiscal.nfe.edit',$nfeDraft)
                ->with('warning',"A NF-e ainda possui pendências:\n".implode("\n",$errors));
        }

        $nfeDraft->update(['validated_at'=>now()]);
        return redirect()->route('fiscal.nfe.edit',$nfeDraft)
            ->with('success','Rascunho validado estruturalmente. A validação fiscal final será feita pelo ACBr antes da emissão.');
    }

    public function destroy(NfeDraft $nfeDraft)
    {
        $nfeDraft->delete();
        return redirect()->route('fiscal.index',['tab'=>'nfe'])->with('success','Rascunho da NF-e excluído.');
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
        $paymentMethods=PaymentMethod::query()
            ->where('is_active',true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return view('fiscal.nfe.editor',[
            'draft'=>$draft,
            'company'=>$company,
            'customers'=>$customers,
            'products'=>$products,
            'natures'=>$natures,
            'paymentMethods'=>$paymentMethods,
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
            'destination'=>['required',Rule::in(['auto','internal','interstate','foreign'])],
            'presence'=>['required',Rule::in(['not_applicable','presential','internet','phone','outside_establishment','other'])],
            'purpose'=>['required',Rule::in(['normal','complementary','adjustment','return'])],
            'final_consumer'=>['nullable','boolean'],
            'issue_date'=>['required','date'],
            'issue_time'=>['nullable','date_format:H:i'],
            'exit_date'=>['nullable','date'],
            'exit_time'=>['nullable','date_format:H:i'],
            'expected_delivery_date'=>['nullable','date'],
            'government_purchase'=>['nullable','boolean'],
            'advance_payment'=>['nullable','boolean'],
            'different_delivery'=>['nullable','boolean'],
            'delivery_address_id'=>['nullable','integer','exists:customer_delivery_addresses,id'],
            'freight_mode'=>['required','string','max:40'],
            'carrier_id'=>['nullable','integer','exists:customers,id'],
            'vehicle_plate'=>['nullable','string','max:12'],
            'vehicle_state'=>['nullable','string','size:2'],
            'volume_quantity'=>['nullable','numeric','min:0'],
            'volume_species'=>['nullable','string','max:60'],
            'volume_numbering'=>['nullable','string','max:120'],
            'net_weight'=>['nullable','numeric','min:0'],
            'gross_weight'=>['nullable','numeric','min:0'],
            'invoice_number'=>['nullable','string','max:60'],
            'invoice_original_value'=>['nullable','numeric','min:0'],
            'invoice_discount'=>['nullable','numeric','min:0'],
            'invoice_net_value'=>['nullable','numeric','min:0'],
            'additional_info'=>['nullable','string','max:10000'],
            'tax_authority_info'=>['nullable','string','max:10000'],
            'duplicates_json'=>['nullable','string'],
            'payments_json'=>['nullable','string'],
            'references_json'=>['nullable','string'],
            'custom_fields_json'=>['nullable','string'],
            'items_json'=>['required','string'],
        ]);

        $company=CompanySetting::current();
        $customer=Customer::query()->with('deliveryAddresses')->findOrFail((int)$data['customer_id']);
        $nature=OperationNature::query()->findOrFail((int)$data['operation_nature_id']);

        $delivery=null;
        if($request->boolean('different_delivery') && $request->filled('delivery_address_id')) {
            $deliveryAddress=$customer->deliveryAddresses->firstWhere('id',(int)$request->input('delivery_address_id'));
            $delivery=$deliveryAddress?->only([
                'id','name','document','state_registration','zip_code','state','city','address',
                'address_number','address_complement','district','email','phone'
            ]);
        }

        $carrier=$request->filled('carrier_id')
            ? Customer::query()->find((int)$request->input('carrier_id'))
            : null;

        return [
            'operation_nature_id'=>(int)$data['operation_nature_id'],
            'customer_id'=>(int)$data['customer_id'],
            'user_id'=>auth()->id(),
            'status'=>'draft',
            'operation_type'=>$data['operation_type'],
            'destination'=>$data['destination'],
            'presence'=>$data['presence'],
            'purpose'=>$data['purpose'],
            'final_consumer'=>$request->boolean('final_consumer'),
            'issue_date'=>$data['issue_date'],
            'issue_time'=>$data['issue_time'] ?? null,
            'exit_date'=>$data['exit_date'] ?? null,
            'exit_time'=>$data['exit_time'] ?? null,
            'expected_delivery_date'=>$data['expected_delivery_date'] ?? null,
            'government_purchase'=>$request->boolean('government_purchase'),
            'advance_payment'=>$request->boolean('advance_payment'),
            'different_delivery'=>$request->boolean('different_delivery'),
            'series'=>(int)AppSetting::value('nfe','series',1),
            'document_number'=>null,
            'environment'=>(string)AppSetting::value('nfe','environment',AppSetting::value('fiscal','default_environment','homologation')),
            'emitter_snapshot'=>$company->only([
                'document','legal_name','trade_name','state_registration','municipal_registration','cnae_main',
                'phone','email','zip_code','state','city','city_ibge_code','address','address_number',
                'address_complement','district','tax_regime','crt'
            ]),
            'recipient_snapshot'=>$customer->only([
                'name','trade_name','document','email','phone','zip_code','state','city','address',
                'address_number','address_complement','district','final_consumer','ie_indicator',
                'state_registration','substitute_state_registration','municipal_registration','suframa'
            ]),
            'delivery_snapshot'=>$delivery,
            'transport_data'=>[
                'freight_mode'=>$data['freight_mode'],
                'carrier_id'=>$carrier?->id,
                'carrier'=>$carrier?->only(['name','document','state_registration','rntrc','state','city','address','address_number']),
                'vehicle_plate'=>$data['vehicle_plate'] ?? null,
                'vehicle_state'=>$data['vehicle_state'] ?? null,
                'volume_quantity'=>$data['volume_quantity'] ?? null,
                'volume_species'=>$data['volume_species'] ?? null,
                'volume_numbering'=>$data['volume_numbering'] ?? null,
                'net_weight'=>$data['net_weight'] ?? null,
                'gross_weight'=>$data['gross_weight'] ?? null,
            ],
            'invoice_data'=>[
                'number'=>$data['invoice_number'] ?? null,
                'original_value'=>$data['invoice_original_value'] ?? null,
                'discount'=>$data['invoice_discount'] ?? null,
                'net_value'=>$data['invoice_net_value'] ?? null,
            ],
            'duplicates'=>$this->decodeArray($data['duplicates_json'] ?? null,'duplicatas'),
            'payments'=>$this->decodeArray($data['payments_json'] ?? null,'pagamentos'),
            'references'=>$this->decodeArray($data['references_json'] ?? null,'documentos referenciados'),
            'custom_fields'=>$this->decodeArray($data['custom_fields_json'] ?? null,'campos de uso livre'),
            'additional_info'=>$data['additional_info'] ?? $nature->additional_info,
            'tax_authority_info'=>$data['tax_authority_info'] ?? $nature->tax_authority_info,
            'totals'=>$this->calculateTotals($this->decodeArray($data['items_json'],'itens')),
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
            $product=Product::query()->find($productId);
            if(!$product) {
                throw ValidationException::withMessages(['items_json'=>'Um dos produtos selecionados não existe mais.']);
            }

            $quantity=max(0,(float)($item['quantity'] ?? 0));
            $unitPrice=max(0,(float)($item['unit_price'] ?? 0));
            $freight=max(0,(float)($item['freight'] ?? 0));
            $insurance=max(0,(float)($item['insurance'] ?? 0));
            $other=max(0,(float)($item['other_expenses'] ?? 0));
            $discount=max(0,(float)($item['discount'] ?? 0));
            $lineTotal=max(0,($quantity*$unitPrice)+$freight+$insurance+$other-$discount);

            $taxData=is_array($item['tax_data'] ?? null) ? $item['tax_data'] : ($product->tax_defaults ?? []);
            $cfop=trim((string)($item['cfop'] ?? ''));
            if($cfop==='' && $nature) {
                $cfop=$this->resolveNatureCfop($nature,$customer,$company);
            }

            $draft->items()->create([
                'product_id'=>$product->id,
                'item_number'=>$index+1,
                'product_name'=>$item['product_name'] ?? $product->name,
                'product_sku'=>$item['product_sku'] ?? $product->sku,
                'quantity'=>$quantity,
                'unit_price'=>$unitPrice,
                'freight'=>$freight,
                'insurance'=>$insurance,
                'other_expenses'=>$other,
                'discount'=>$discount,
                'line_total'=>$lineTotal,
                'cfop'=>$cfop ?: null,
                'origin'=>$item['origin'] ?? $product->origin,
                'ean_gtin'=>$item['ean_gtin'] ?? $product->ean_gtin,
                'unit'=>$item['unit'] ?? $product->unit,
                'tax_unit'=>$item['tax_unit'] ?? $product->tax_unit,
                'ncm'=>$item['ncm'] ?? $product->ncm,
                'cest'=>$item['cest'] ?? $product->cest,
                'ipi_exception'=>$item['ipi_exception'] ?? $product->ipi_exception,
                'fiscal_benefit_code'=>$item['fiscal_benefit_code'] ?? $product->fiscal_benefit_code,
                'purchase_order'=>$item['purchase_order'] ?? null,
                'purchase_order_item'=>$item['purchase_order_item'] ?? null,
                'notes'=>$item['notes'] ?? $product->nfe_notes,
                'tax_data'=>$taxData,
                'special_data'=>is_array($item['special_data'] ?? null) ? $item['special_data'] : [],
            ]);
        }

        $draft->update(['totals'=>$this->calculateTotals($draft->items()->get()->map(fn($item)=>[
            'quantity'=>(float)$item->quantity,
            'unit_price'=>(float)$item->unit_price,
            'freight'=>(float)$item->freight,
            'insurance'=>(float)$item->insurance,
            'other_expenses'=>(float)$item->other_expenses,
            'discount'=>(float)$item->discount,
        ])->all())]);
    }

    private function resolveNatureCfop(OperationNature $nature, ?Customer $customer, CompanySetting $company): ?string
    {
        if(!$customer) return $nature->cfop_internal;
        if(!$customer->state || !$company->state) return $nature->cfop_internal;
        if($customer->state===$company->state) return $nature->cfop_internal;
        return $nature->cfop_interstate ?: $nature->cfop_internal;
    }

    private function calculateTotals(array $items): array
    {
        $totals=['products'=>0.0,'freight'=>0.0,'insurance'=>0.0,'other_expenses'=>0.0,'discount'=>0.0,'total'=>0.0];
        foreach($items as $item) {
            $q=max(0,(float)($item['quantity'] ?? 0));
            $price=max(0,(float)($item['unit_price'] ?? 0));
            $totals['products']+=$q*$price;
            $totals['freight']+=max(0,(float)($item['freight'] ?? 0));
            $totals['insurance']+=max(0,(float)($item['insurance'] ?? 0));
            $totals['other_expenses']+=max(0,(float)($item['other_expenses'] ?? 0));
            $totals['discount']+=max(0,(float)($item['discount'] ?? 0));
        }
        $totals['total']=$totals['products']+$totals['freight']+$totals['insurance']+$totals['other_expenses']-$totals['discount'];
        return array_map(fn($value)=>round($value,2),$totals);
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
