<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\NfeDraft;
use App\Models\NfeDraftItem;
use App\Services\Fiscal\Nfe55DraftTotalsService;
use App\Services\Fiscal\Nfe55TaxRuleApplicationService;
use App\Services\Fiscal\NfeTaxMath;
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
        $environment=app(\App\Services\Fiscal\FiscalDocumentSettings::class)->environment('nfe');
        $numbering=app(\App\Services\Fiscal\FiscalDocumentSettings::class)->nfeNumbering($environment);
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
            'environment'=>$environment,
            'series'=>$numbering['series'],
        ]));
    }

    public function store(Request $request)
    {
        // Compatibilidade com integrações legadas: a tela nova envia o modo
        // rascunho explicitamente; os demais clientes mantêm a regra estrita.
        if($request->input('draft_action')!=='draft'){
            $draft=DB::transaction(function()use($request){
                $draft=NfeDraft::query()->create(['user_id'=>auth()->id()]+$this->draftData($request));
                $this->replaceItems($draft,$request);
                return $draft->refresh();
            });
            return $request->input('after_save')==='validate'
                ?$this->validateAndRedirect($draft)
                :redirect()->route('fiscal.nfe.edit',$draft)->with('success','Rascunho da NF-e salvo.');
        }
        $draft=$this->savePartialDraft($request);

        if($request->input('after_save')==='validate') {
            return $this->validateSavedForm($request,$draft);
        }

        return redirect()->route('fiscal.nfe.edit',$draft)
            ->with('success','Rascunho salvo. Você pode continuar o preenchimento depois.');
    }

    public function edit(NfeDraft $nfeDraft)
    {
        $nfeDraft->load('items');
        return $this->editor($nfeDraft);
    }

    /** Read-only calculation: it never creates a draft or reserves numbering. */
    public function previewTaxes(Request $request)
    {
        $data=$request->validate([
            'items_json'=>['required','string','max:400000'],
            'operation_type'=>['required',Rule::in(['outbound','inbound'])],
            'destination'=>['required',Rule::in(['internal','interstate','foreign'])],
            'purpose'=>['required',Rule::in(['normal','complementary','adjustment','return','credit_note','debit_note'])],
            'fiscal_operation'=>['nullable',Rule::in(['sale','devolution','transfer','bonus','gift','sample','remittance','return_of_remittance','export','import'])],
            'customer_id'=>['nullable','integer','exists:customers,id'],
            'operation_nature_id'=>['nullable','integer','exists:operation_natures,id'],
            'issue_date'=>['nullable','date'],
            'final_consumer'=>['nullable','boolean'],
            'discount'=>['nullable','numeric','min:0','max:99999999'],
            'surcharge'=>['nullable','numeric','min:0','max:99999999'],
            'freight_value'=>['nullable','numeric','min:0','max:99999999'],
        ]);
        $company=CompanySetting::query()->first();
        if(!$company)throw ValidationException::withMessages(['company'=>'Cadastre o emitente antes de calcular os tributos.']);
        $customer=isset($data['customer_id'])?Customer::query()->find($data['customer_id']):null;
        $items=$this->resolveFormTaxes($this->decodeArray($data['items_json'],'itens'),$data,$company,$customer);
        $totals=$this->calculateTotals($items,(float)($data['discount']??0),
            (float)($data['surcharge']??0),(float)($data['freight_value']??0),$data['operation_type'],$data['issue_date']??null,$data['purpose']??'normal');
        $documentErrors=[];
        try {
            // The same builder checks operation semantics and base composition;
            // transient models keep this preview free of database mutations.
            $draft=new NfeDraft(['status'=>'draft']+$this->draftData($request));
            $draft->setRelation('items',collect($items)->map(fn($item)=>new NfeDraftItem($item)));
            app(\App\Services\Fiscal\Nfe55IniBuilder::class)->build($draft,$company,1,12345678);
        }catch(ValidationException $error){
            $documentErrors=array_values(array_unique(array_merge(...array_values($error->errors()))));
        }catch(\RuntimeException $error){$documentErrors[]=$error->getMessage();}
        $rules=[];
        foreach($items as $index=>$item){
            $trace=data_get($item,'tax_data.fiscal_rule_trace',[]);
            if(!is_array($trace)||!isset($trace['id']))continue;
            $rules[]=['item'=>$index+1,'id'=>$trace['id'],
                'name'=>data_get($trace,'snapshot.name','Regra fiscal'),
                'revision'=>$trace['revision'],'legal_reference'=>$trace['legal_reference']??'',
                'legal_source'=>$trace['legal_source']??'',
                'catalog_version'=>data_get($trace,'snapshot.catalog_version'),
                'cfop'=>$item['cfop']??null,
                'icms_cst'=>data_get($item,'tax_data.icms_cst'),
                'csosn'=>data_get($item,'tax_data.icms_csosn'),
                'pis_cst'=>data_get($item,'tax_data.pis_cst'),
                'cofins_cst'=>data_get($item,'tax_data.cofins_cst'),
                'overrides'=>array_keys($trace['overrides']??[])];
        }
        return response()->json(['totals'=>$totals,'document_errors'=>$documentErrors,'applied_rules'=>$rules]);
    }

    public function update(Request $request, NfeDraft $nfeDraft)
    {
        if($request->input('draft_action')!=='draft'){
            DB::transaction(function()use($request,$nfeDraft){
                $locked=$this->editableDraft($nfeDraft);
                $locked->update($this->draftData($request,$locked)+['validated_at'=>null]);
                $this->replaceItems($locked,$request);
            });
            return $request->input('after_save')==='validate'
                ?$this->validateAndRedirect($nfeDraft->refresh())
                :redirect()->route('fiscal.nfe.edit',$nfeDraft)->with('success','Rascunho da NF-e atualizado.');
        }
        $this->savePartialDraft($request,$nfeDraft);

        if($request->input('after_save')==='validate') {
            return $this->validateSavedForm($request,$nfeDraft->refresh());
        }

        return redirect()->route('fiscal.nfe.edit',$nfeDraft)
            ->with('success','Rascunho atualizado. Nenhuma nota foi enviada.');
    }

    /** Guarda o trabalho, mesmo sem cliente, natureza, pagamento ou impostos completos.
     *  Nenhum número fiscal é reservado e nenhuma NF-e é transmitida.
     */
    private function savePartialDraft(Request $request,?NfeDraft $existing=null): NfeDraft
    {
        $values=$request->except(['_token','_method','after_save','draft_action',
            'status','document_number','access_key','protocol','numeric_code','validated_at',
            'transmission_attempted_at','authorized_at','cancellation_status']);
        $snapshot=[];
        $totalChars=0;
        if(count($values)>110){
            throw ValidationException::withMessages(['nfe'=>'Há campos demais na nota. Reabra o formulário e tente novamente.']);
        }
        foreach($values as $key=>$value){
            if(!preg_match('/^[a-z][a-z0-9_]{0,79}$/D',(string)$key) || (!is_string($value)&&!is_numeric($value)&&!is_bool($value)))continue;
            if(strlen((string)$value)>400000){
                throw ValidationException::withMessages(['nfe'=>'A nota contém informações muito extensas. Revise os itens.']);
            }
            $totalChars+=strlen((string)$value);
            if($totalChars>600000){
                throw ValidationException::withMessages(['nfe'=>'A nota contém informações muito extensas. Revise os itens.']);
            }
            $snapshot[$key]=(string)$value;
        }

        $rawItems=(string)($snapshot['items_json']??'[]');
        $items=json_decode($rawItems,true);
        if(!is_array($items)||!array_is_list($items)||count($items)>80){
            throw ValidationException::withMessages(['items_json'=>'Não foi possível salvar a lista de produtos. Revise os itens.']);
        }
        $now=now()->toDateString();
        $rawDate=(string)($snapshot['issue_date']??'');
        $issueDate=preg_match('/^\\d{4}-\\d{2}-\\d{2}$/',$rawDate)===1
            &&checkdate((int)substr($rawDate,5,2),(int)substr($rawDate,8,2),(int)substr($rawDate,0,4))
            ? $rawDate:$now;
        $rawType=(string)($snapshot['operation_type']??'');
        $rawDestination=(string)($snapshot['destination']??'');
        $candidateNature=(int)($snapshot['operation_nature_id']??0);
        $candidateCustomer=(int)($snapshot['customer_id']??0);
        $company=CompanySetting::current();

        return DB::transaction(function()use($request,$existing,$snapshot,$items,$issueDate,$rawType,$rawDestination,$candidateNature,$candidateCustomer,$company){
            $draft=$existing ? $this->editableDraft($existing) : new NfeDraft(['status'=>'draft']);

            $nature=OperationNature::query()->find($candidateNature);
            $customer=Customer::query()->find($candidateCustomer);
            $changes=[
                'user_id'=>auth()->id(),
                'operation_nature_id'=>$nature?->id,
                'customer_id'=>$customer?->id,
                'operation_type'=>in_array($rawType,['outbound','inbound'],true)?$rawType:'outbound',
                'destination'=>in_array($rawDestination,['internal','interstate','foreign'],true)?$rawDestination:'internal',
                'issue_date'=>$issueDate,
                'issue_time'=>$this->minuteTime($snapshot['issue_time']??null),
                'series'=>$draft->series ?? app(\App\Services\Fiscal\FiscalDocumentSettings::class)->nfeNumbering($draft->environment ?: app(\App\Services\Fiscal\FiscalDocumentSettings::class)->environment('nfe'))['series'],
                'environment'=>$draft->environment ?: app(\App\Services\Fiscal\FiscalDocumentSettings::class)->environment('nfe'),
                'validated_at'=>null,
                'custom_fields'=>array_replace($draft->custom_fields??[],['_editor_form'=>$snapshot]),
            ];
            if(!$draft->exists){
                $changes['environment']=app(\App\Services\Fiscal\FiscalDocumentSettings::class)->environment('nfe');
                $changes['emitter_snapshot']=$company->only([
                    'document','legal_name','trade_name','state_registration','state','city',
                    'city_ibge_code','zip_code','address','address_number','district','crt',
                ]);
            }
            $draft->fill($changes)->save();

            // Formulário completo: gravar integralmente a NF-e, inclusive
            // destinatário, transporte, pagamentos, totais e dados fiscais.
            // Um erro não cancela o rascunho: a versão parcial continua salva.
            try {
                DB::transaction(function()use($request,$draft,$snapshot){
                    $complete=$this->draftData($request,$draft);
                    $complete['custom_fields']=array_replace(
                        $draft->custom_fields??[],$complete['custom_fields']??[],['_editor_form'=>$snapshot]
                    );
                    $draft->update($complete+['validated_at'=>null]);
                    $this->replaceItems($draft,$request);
                });
                return $draft->refresh();
            }catch(ValidationException $error){
                // Uma tentativa parcial não deve deixar valores do objeto
                // Eloquent que já foram revertidos pelo savepoint.
                $draft->refresh();
            }

            // Produtos com os dados mínimos corretos passam a constar também da
            // listagem e dos totais. Se um item ainda está incompleto, o
            // snapshot integral continua salvo até a validação final.
            if($items===[]){
                $draft->items()->delete();
                $draft->update(['totals'=>['total'=>0,'discount'=>0,'surcharge'=>0]]);
            }else{
                try {
                    DB::transaction(fn()=> $this->replaceItems($draft,$request));
                    $draft->update(['totals'=>$this->calculateTotals(
                        $items,max(0,(float)($snapshot['discount']??0)),
                        max(0,(float)($snapshot['surcharge']??0)),
                        max(0,(float)($snapshot['freight_value']??0))
                    )]);
                } catch(ValidationException $error){
                    // Ainda é rascunho, não uma nota pronta para envio.
                    // Não descartar valores digitados por causa de um item parcial.
                }
            }
            return $draft->refresh();
        },3);
    }

    private function validateSavedForm(Request $request,NfeDraft $draft)
    {
        try {
            DB::transaction(function()use($request,$draft){
                $locked=$this->editableDraft($draft);
                $locked->update($this->draftData($request,$locked)+['validated_at'=>null]);
                $this->replaceItems($locked,$request);
            },3);
        }catch(ValidationException $error){
            return redirect()->route('fiscal.nfe.edit',$draft)
                ->withErrors($error->errors())->withInput();
        }
        return $this->validateAndRedirect($draft->refresh());
    }

    public function validateDraft(Request $request,NfeDraft $nfeDraft)
    {
        return DB::transaction(function()use($request,$nfeDraft){
            $draft=$this->editableDraft($nfeDraft);
            $saved=(array)data_get($draft->custom_fields,'_editor_form',[]);
            if($saved!==[]){
                $request->replace($saved);
                return $this->validateSavedForm($request,$draft);
            }
            return $this->validateAndRedirect($draft);
        },3);
    }

    public function destroy(NfeDraft $nfeDraft)
    {
        DB::transaction(fn()=> $this->editableDraft($nfeDraft)->delete(),3);
        return redirect()->route('fiscal.nfe.drafts.index')->with('success','Rascunho da NF-e excluído.');
    }

    private function validateAndRedirect(NfeDraft $draft)
    {
        return DB::transaction(function()use($draft){
            return $this->validateLockedDraft($this->editableDraft($draft));
        },3);
    }

    /** Every draft mutation shares the emission reservation row lock. */
    private function editableDraft(NfeDraft $draft): NfeDraft
    {
        $locked=NfeDraft::query()->lockForUpdate()->findOrFail($draft->id);
        abort_unless($locked->status==='draft' && $locked->document_number===null
            && $locked->access_key===null && $locked->transmission_attempted_at===null,
            409,'Esta NF-e já foi reservada para emissão e não pode mais ser alterada.');
        return $locked;
    }

    private function validateLockedDraft(NfeDraft $draft)
    {
        $draft->load(['items','customer','operationNature']);
        $errors=[];
        try {
            app(Nfe55TaxRuleApplicationService::class)->apply($draft,CompanySetting::current());
            $totals=$this->calculateTotals($draft->items->map(fn($item)=>$item->toArray())->all(),
                (float)$draft->discount,(float)$draft->surcharge,
                (float)data_get($draft->transport_data,'freight_value',0),$draft->operation_type,$draft->issue_date?->toDateString(),$draft->purpose);
            $draft->update(['totals'=>$totals,'invoice_data'=>array_replace($draft->invoice_data??[],[
                'original_value'=>round($totals['total']+$totals['discount'],2),
                'discount'=>$totals['discount'],'net_value'=>$totals['total'],
            ])]);
        }catch(\RuntimeException $error){$errors[]=$error->getMessage();}

        if(!$draft->operationNature) $errors[]='Selecione uma natureza de operação.';
        elseif($draft->operation_type!==$draft->operationNature->operation_type){
            $errors[]='A natureza selecionada não corresponde ao tipo de operação (entrada ou saída).';
        }
        if(!$draft->customer) $errors[]='Selecione o destinatário.';
        if(!$draft->customer?->document && !$draft->customer?->foreign_id) {
            $errors[]='O destinatário está sem CPF/CNPJ ou identificação de estrangeiro.';
        }

        $recipientUf=strtoupper((string)($draft->customer?->state??''));
        $emitterUf=strtoupper((string)CompanySetting::current()->state);
        if($recipientUf!=='' && $emitterUf!==''){
            if($draft->destination==='internal' && $recipientUf!==$emitterUf){
                $errors[]='O destino está como operação interna, mas o destinatário é de outra UF.';
            }
            if($draft->destination==='interstate' && $recipientUf===$emitterUf){
                $errors[]='O destino está como interestadual, mas emitente e destinatário são da mesma UF.';
            }
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

        if($draft->inform_issue_datetime && (!$draft->issue_date || !$draft->issue_time)) {
            $errors[]='Informe data e horário de emissão.';
        }
        if($draft->inform_exit_datetime && (!$draft->exit_date || !$draft->exit_time)) {
            $errors[]='Informe data e horário de saída ou entrada.';
        }
        try {
            app(\App\Services\Fiscal\Nfe55OperationPolicy::class)->validate($draft,CompanySetting::current());
        }catch(\RuntimeException $error){$errors[]=$error->getMessage();}

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

        if(!$errors) {
            try {
                // Usa as mesmas regras de construção fiscal do envio,
                // sem reservar numeração nem transmitir à SEFAZ.
                app(\App\Services\Fiscal\Nfe55IniBuilder::class)->build(
                    $draft,CompanySetting::current(),
                    app(\App\Services\Fiscal\FiscalDocumentSettings::class)->nfeNumbering((string)$draft->environment)['next_number'],
                    12345678
                );
            } catch (\Throwable $error) {
                \Illuminate\Support\Facades\Log::warning('NF-e: conferência fiscal incompleta',[
                    'draft_id'=>$draft->id,
                    'type'=>get_class($error),
                ]);
                $errors[]=$error instanceof \RuntimeException?$error->getMessage():
                    'Confira a classificação fiscal e os impostos dos produtos antes de emitir. Esta combinação ainda não está pronta para envio.';
            }
        }

        if($errors) {
            // Uma nota já marcada como validada pode tornar-se inconsistente
            // se os cadastros relacionados forem modificados.
            if($draft->validated_at!==null)$draft->update(['validated_at'=>null]);
            return redirect()->route('fiscal.nfe.edit',$draft)
                ->with('warning',"A NF-e ainda possui pendências:\n".implode("\n",$errors));
        }

        $draft->update(['validated_at'=>now()]);
        return redirect()->route('fiscal.nfe.edit',$draft)
            ->with('success','Dados obrigatórios conferidos. Ao emitir, o sistema verificará a nota antes do envio.');
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

        $carriers=Customer::query()->where('is_carrier',true)->orderBy('name')->get();

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
            'trade_name'=>$customer->trade_name,
            'email'=>$customer->email,
            'phone'=>$customer->phone,
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
            'carriers'=>$carriers,
            'products'=>$products,
            'natures'=>$natures,
            'productData'=>$productData,
            'customerData'=>$customerData,
            'natureData'=>$natureData,
            'taxDefaults'=>AppSetting::groupValues('tax',[]),
            'paymentTypes'=>self::PAYMENT_TYPES,
            'cardBrands'=>self::CARD_BRANDS,
            'isEditing'=>$draft->exists,
            'nextNumber'=>(int)AppSetting::value('nfe',$draft->environment==='production'?'production_next_number':'next_number',$draft->environment==='production'?null:1),
        ]);
    }

    private function minuteTime(?string $time): ?string
    {
        // MySQL TIME pode voltar com segundos, embora o editor use HH:mm.
        // Mantemos apenas as horas e os minutos, sem alterar o horário.
        return $time === null || $time === '' ? null : substr($time,0,5);
    }

    private function draftData(Request $request,?NfeDraft $existing=null): array
    {
        $environment=$existing?->environment ?: app(\App\Services\Fiscal\FiscalDocumentSettings::class)->environment('nfe');
        $series=$existing?->series ?? app(\App\Services\Fiscal\FiscalDocumentSettings::class)->nfeNumbering($environment)['series'];
        $data=$request->validate([
            'operation_nature_id'=>['required','integer','exists:operation_natures,id'],
            'customer_id'=>['required','integer','exists:customers,id'],
            'operation_type'=>['required',Rule::in(['outbound','inbound'])],
            'destination'=>['nullable',Rule::in(['internal','interstate','foreign'])],
            'presence'=>['required',Rule::in(['not_applicable','presential','internet','phone','delivery_home','outside_establishment','other'])],
            'purpose'=>['nullable',Rule::in(['normal','complementary','adjustment','return','credit_note','debit_note'])],
            'fiscal_operation'=>['nullable',Rule::in(['sale','devolution','transfer','bonus','gift','sample','remittance','return_of_remittance','export','import'])],
            'substitute_state_registration'=>['nullable','string','max:30'],
            'has_referenced_document'=>['nullable','boolean'],
            'inform_issue_datetime'=>['nullable','boolean'],
            'inform_exit_datetime'=>['nullable','boolean'],
            'inform_expected_delivery_date'=>['nullable','boolean'],
            'issue_date'=>['nullable','date'],
            'issue_time'=>['nullable','date_format:H:i,H:i:s'],
            'exit_date'=>['nullable','date'],
            'exit_time'=>['nullable','date_format:H:i,H:i:s'],
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
            'discount'=>['nullable','numeric','min:0','max:99999999'],
            'surcharge'=>['nullable','numeric','min:0','max:99999999'],

            'freight_mode'=>['required',Rule::in(['0','1','2','3','4','9'])],
            'freight_value'=>['nullable','numeric','min:0','max:99999999'],
            'carrier_id'=>['nullable','integer',Rule::exists('customers','id')->where('is_carrier',true)],
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
            'card_integration_type'=>['nullable',Rule::in(['1','2'])],
            'card_brand'=>['nullable',Rule::in(array_keys(self::CARD_BRANDS))],
            'card_acquirer_document'=>['nullable','string','max:20'],
            'card_authorization_code'=>['nullable','string','max:40'],

            'additional_info'=>['nullable','string','max:10000'],
            'tax_authority_info'=>['nullable','string','max:2000'],
            'export_exit_uf'=>['nullable','string','size:2'],
            'export_exit_location'=>['nullable','string','max:60'],
            'export_dispatch_location'=>['nullable','string','max:60'],
            'final_consumer'=>['nullable','boolean'],
            'duplicates_json'=>['nullable','string'],
            'payments_json'=>['nullable','string'],
            'references_json'=>['nullable','string'],
            'items_json'=>['required','string'],
        ], [
            'issue_time.date_format'=>'Confira a hora de emissão. Utilize um horário válido, como 11:24.',
            'exit_time.date_format'=>'Confira a hora de saída ou entrada.',
            'operation_nature_id.required'=>'Selecione a natureza da operação.',
            'operation_nature_id.exists'=>'A natureza selecionada não está disponível. Selecione outra.',
            'customer_id.required'=>'Selecione o destinatário.',
            'customer_id.exists'=>'O destinatário selecionado não está disponível.',
        ]);

        $company=CompanySetting::current();
        $customer=Customer::query()->findOrFail((int)$data['customer_id']);
        $nature=OperationNature::query()->findOrFail((int)$data['operation_nature_id']);
        $carrier=$request->filled('carrier_id')
            ? Customer::query()->find((int)$request->input('carrier_id'))
            : null;

        $data['destination']=$data['destination']??$this->resolveDestination($customer,$company);
        $data['purpose']=$data['purpose']??($nature->purpose?:'normal');
        $items=$this->resolveFormTaxes($this->decodeArray($data['items_json'],'itens'),$data,$company,$customer);
        $discount=max(0,(float)($data['discount'] ?? 0));
        $surcharge=max(0,(float)($data['surcharge'] ?? 0));
        $freight=max(0,(float)($data['freight_value'] ?? 0));
        $totals=$this->calculateTotals($items,$discount,$surcharge,$freight,$data['operation_type'],$data['issue_date']??null,$data['purpose']??'normal');
        $payments=$this->validatedPayments($request,$data,$totals);
        $duplicates=$this->validatedDuplicates($data['duplicates_json'] ?? null,$totals);
        $usesMercantileDuplicate=false;
        foreach($payments as $payment){
            if((string)$payment['type']==='14')$usesMercantileDuplicate=true;
        }
        if((bool)$duplicates!==$usesMercantileDuplicate){
            throw ValidationException::withMessages([
                'duplicates_json'=>$duplicates
                    ? 'Para emitir duplicatas, selecione Duplicata Mercantil como uma forma de pagamento.'
                    : 'Para Duplicata Mercantil, informe as parcelas de cobrança.',
            ]);
        }
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
            'operation_type'=>$data['operation_type'],
            'destination'=>$destination,
            'presence'=>$data['presence'],
            'purpose'=>$purpose,
            'final_consumer'=>$request->has('final_consumer')?$request->boolean('final_consumer'):(bool)$customer->final_consumer,
            'substitute_state_registration'=>$data['substitute_state_registration'] ?? null,
            'has_referenced_document'=>$hasReferencedDocument,
            'inform_issue_datetime'=>$informIssueDatetime,
            'inform_exit_datetime'=>$informExitDatetime,
            'inform_expected_delivery_date'=>$informExpectedDeliveryDate,
            'issue_date'=>$informIssueDatetime ? $data['issue_date'] : now()->toDateString(),
            'issue_time'=>$informIssueDatetime ? $this->minuteTime($data['issue_time'] ?? null) : now()->format('H:i'),
            'exit_date'=>$informExitDatetime ? ($data['exit_date'] ?? null) : null,
            'exit_time'=>$informExitDatetime ? $this->minuteTime($data['exit_time'] ?? null) : null,
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
            'series'=>$series,
            'environment'=>$environment,
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
            'duplicates'=>$duplicates,
            'payments'=>$payments,
            'references'=>$references,
            'custom_fields'=>['fiscal_operation'=>$data['fiscal_operation']??'sale',
                'foreign_trade'=>[
                    'export_exit_uf'=>strtoupper((string)($data['export_exit_uf']??'')),
                    'export_exit_location'=>$data['export_exit_location']??'',
                    'export_dispatch_location'=>$data['export_dispatch_location']??'',
                ]],
            'additional_info'=>$data['additional_info'] ?? $nature->additional_info,
            'tax_authority_info'=>$data['tax_authority_info']??null,
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

            foreach(['quantity','dimension_quantity','unit_price'] as $valueField){
                $numeric=$item[$valueField]??($valueField==='dimension_quantity'?1:null);
                if(!is_numeric($numeric)
                    ||($valueField!=='unit_price'&&(float)$numeric<=0)
                    ||($valueField==='unit_price'&&(float)$numeric<0)){
                    throw ValidationException::withMessages([
                        'items_json'=>'Confira quantidade, fator e valor unitário dos produtos.',
                    ]);
                }
            }
            try {
                $quantity=NfeTaxMath::format(NfeTaxMath::scaled($item['quantity']??0,4,'Quantidade'),4);
                $dimensionQuantity=NfeTaxMath::format(NfeTaxMath::scaled($item['dimension_quantity']??1,4,'Fator dimensional'),4);
                $unitPrice=NfeTaxMath::format(NfeTaxMath::scaled($item['unit_price']??0,4,'Preço unitário'),4);
                $line=NfeTaxMath::line($quantity,$dimensionQuantity,$unitPrice);
            }catch(\RuntimeException $error){
                throw ValidationException::withMessages(['items_json'=>$error->getMessage()]);
            }
            $parts=[];$partsCents=[];
            foreach(['freight','insurance','other_expenses','discount'] as $part){
                $value=$item[$part]??0;
                if(!is_numeric($value)||(float)$value<0||(float)$value>99999999){
                    throw ValidationException::withMessages(['items_json'=>'Confira os valores adicionais dos itens da NF-e.']);
                }
                try {$partsCents[$part]=NfeTaxMath::scaled($value,2,'Valor adicional do item');}
                catch(\RuntimeException $error){throw ValidationException::withMessages(['items_json'=>$error->getMessage()]);}
                $parts[$part]=$partsCents[$part]/100;
            }
            $gross=$line['gross_cents']/100;
            if($partsCents['discount']>$line['gross_cents']+$partsCents['freight']+$partsCents['insurance']+$partsCents['other_expenses']){
                throw ValidationException::withMessages(['items_json'=>'O desconto do item supera seu valor.']);
            }
            $lineTotal=($line['gross_cents']+$partsCents['freight']+$partsCents['insurance']+$partsCents['other_expenses']-$partsCents['discount'])/100;

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

        $draft->load('items');
        try {
            app(Nfe55TaxRuleApplicationService::class)->apply($draft,$company,true,true);
        }catch(\RuntimeException $error){
            throw ValidationException::withMessages(['items_json'=>$error->getMessage()]);
        }
        $storedItems=$draft->items->map(fn($item)=>[
            'quantity'=>$item->quantity,
            'dimension_quantity'=>$item->dimension_quantity,
            'unit_price'=>$item->unit_price,
            'freight'=>(float)$item->freight,
            'insurance'=>(float)$item->insurance,
            'other_expenses'=>(float)$item->other_expenses,
            'discount'=>(float)$item->discount,
            'tax_data'=>$item->tax_data ?? [],
            'special_data'=>$item->special_data ?? [],
        ])->all();

        $freight=(float)data_get($draft->transport_data,'freight_value',0);
        $totals=$this->calculateTotals($storedItems,(float)$draft->discount,(float)$draft->surcharge,$freight,$draft->operation_type,$draft->issue_date?->toDateString(),$draft->purpose);

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

    private function resolveFormTaxes(array $items,array $data,CompanySetting $company,?Customer $customer): array
    {
        $draft=new NfeDraft([
            'status'=>'draft','operation_type'=>$data['operation_type']??'outbound',
            'operation_nature_id'=>$data['operation_nature_id']??null,
            'destination'=>$data['destination']??($customer?$this->resolveDestination($customer,$company):'internal'),
            'purpose'=>$data['purpose']??'normal','issue_date'=>$data['issue_date']??now()->toDateString(),
            'recipient_snapshot'=>$customer?->toArray()??[],
            'final_consumer'=>array_key_exists('final_consumer',$data)?(bool)$data['final_consumer']:(bool)$customer?->final_consumer,
            'custom_fields'=>['fiscal_operation'=>$data['fiscal_operation']??'sale'],
        ]);
        $draft->setRelation('customer',$customer);
        $nature=isset($data['operation_nature_id'])?OperationNature::query()->find($data['operation_nature_id']):null;
        $draft->setRelation('operationNature',$nature);
        $draft->setRelation('items',collect($items)->map(function($item,$index)use($draft,$nature){
            if(!is_array($item))throw ValidationException::withMessages(['items_json'=>'Confira os produtos da NF-e.']);
            $product=isset($item['product_id'])?Product::query()->find($item['product_id']):null;
            $item['tax_data']=array_replace($product?->tax_defaults??[],is_array($item['tax_data']??null)?$item['tax_data']:[]);
            foreach(['ncm','cest','origin','unit','tax_unit'] as $key){
                if(!isset($item[$key])||$item[$key]==='')$item[$key]=$product?->{$key};
            }
            if(empty($item['cfop']))$item['cfop']=$this->resolveItemCfop($nature,$draft->destination,$draft->operation_type,$item['tax_data']);
            $item['item_number']=$index+1;
            return new NfeDraftItem($item);
        }));
        try {
            app(Nfe55TaxRuleApplicationService::class)->apply($draft,$company,false);
        }catch(\RuntimeException $error){
            throw ValidationException::withMessages(['items_json'=>$error->getMessage()]);
        }
        return $draft->items->map(fn($item)=>$item->toArray())->all();
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

        $productKey=$foreign
            ? ($operationType==='inbound'?'cfop_inbound_foreign':'cfop_outbound_foreign')
            : ($operationType==='inbound'
                ? ($interstate ? 'cfop_inbound_interstate' : 'cfop_inbound_internal')
                : ($interstate ? 'cfop_outbound_interstate' : 'cfop_outbound_internal'));

        $productCfop=trim((string)($taxData[$productKey] ?? ($foreign?($taxData['cfop_foreign']??null):null) ?? $taxData['cfop'] ?? ''));

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

    private function calculateTotals(array $items,float $discount=0,float $surcharge=0,float $freight=0,string $direction='outbound',?string $issueDate=null,string $purpose='normal'): array
    {
        try {
            return app(Nfe55DraftTotalsService::class)->calculate($items,[
                'discount'=>$discount,'surcharge'=>$surcharge,'freight'=>$freight,
                'crt'=>(string)CompanySetting::current()->crt,
                'operation_type'=>$direction,'purpose'=>$purpose,
                'issue_date'=>$issueDate??now()->toDateString(),
            ]);
        }catch(\RuntimeException $error){
            $field=str_contains(mb_strtolower($error->getMessage()),'desconto geral')?'discount':'items_json';
            throw ValidationException::withMessages([$field=>$error->getMessage()]);
        }
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
            if(!is_array($payment)||!in_array((string)($payment['type']??''),array_map('strval',array_keys(self::PAYMENT_TYPES)),true)
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
            $normalized[]=array_merge([
                'type'=>$type,'condition'=>$payment['condition']??'a_vista',
                'amount'=>$cents/100,
                'other_description'=>$type==='99'?$description:null,
            ],$this->cardPaymentFields($type,$payment,'payments_json'));
        }
        if($additionalCents>$totalCents){
            throw ValidationException::withMessages(['payments_json'=>'Os pagamentos adicionais superam o total da NF-e.']);
        }
        $main=$paymentType==='90'?0:$totalCents-$additionalCents;
        if($paymentType!=='90' && $main<=0){
            throw ValidationException::withMessages(['payment_type'=>'O pagamento principal deve possuir valor positivo.']);
        }
        $first=array_merge([
            'type'=>$paymentType,'condition'=>$data['payment_condition'],
            'amount'=>$main/100,
            'other_description'=>$paymentType==='99'?($data['payment_other_description']??null):null,
        ],$this->cardPaymentFields($paymentType,[
            'integration_type'=>$data['card_integration_type']??null,
            'card_brand'=>$data['card_brand']??null,
            'acquirer_document'=>$data['card_acquirer_document']??null,
            'authorization_code'=>$data['card_authorization_code']??null,
        ],'card_integration_type'));
        if($paymentType==='99' && trim((string)$first['other_description'])===''){
            throw ValidationException::withMessages(['payment_other_description'=>'Informe a descrição do pagamento Outros.']);
        }
        return array_merge([$first],$normalized);
    }

    private function cardPaymentFields(string $type,array $payment,string $field): array
    {
        $empty=[
            'integration_type'=>null,'card_brand'=>null,
            'acquirer_document'=>null,'authorization_code'=>null,
        ];
        if(!in_array($type,['03','04'],true))return $empty;
        $integration=(string)($payment['integration_type']??'');
        $brand=(string)($payment['card_brand']??'');
        $cnpj=preg_replace('/\D/','',(string)($payment['acquirer_document']??''));
        $authorization=trim((string)($payment['authorization_code']??''));
        $brandCodes=array_map('strval',array_keys(self::CARD_BRANDS));
        if(!in_array($integration,['1','2'],true)){
            throw ValidationException::withMessages([$field=>'Selecione se o pagamento com cartão é integrado (TEF) ou não integrado (POS).']);
        }
        if($brand!==''&&!in_array($brand,$brandCodes,true)){
            throw ValidationException::withMessages([$field=>'Bandeira do cartão inválida.']);
        }
        if(($cnpj!==''&&strlen($cnpj)!==14)||mb_strlen($authorization)>40){
            throw ValidationException::withMessages([$field=>'Confira o CNPJ da credenciadora e o código da autorização.']);
        }
        if($integration==='1'&&($cnpj===''||$authorization==='')){
            throw ValidationException::withMessages([$field=>'Pagamento integrado exige CNPJ da credenciadora e autorização da transação.']);
        }
        return [
            'integration_type'=>$integration,
            'card_brand'=>$brand?:null,
            'acquirer_document'=>$cnpj?:null,
            'authorization_code'=>$authorization?:null,
        ];
    }

    private function validatedDuplicates(?string $json,array $totals): array
    {
        $duplicates=$this->decodeArray($json,'duplicatas');
        if(count($duplicates)>120){
            throw ValidationException::withMessages([
                'duplicates_json'=>'Uma NF-e permite no máximo 120 duplicatas.',
            ]);
        }
        $normalized=[];$sum=0;$previousDue=null;$numbers=[];
        foreach($duplicates as $index=>$duplicate){
            if(!is_array($duplicate)||!is_numeric($duplicate['value']??null)){
                throw ValidationException::withMessages(['duplicates_json'=>'Confira os valores das duplicatas.']);
            }
            $value=(float)$duplicate['value'];
            if(!is_finite($value)||$value<=0||$value>999999999){
                throw ValidationException::withMessages(['duplicates_json'=>'O valor de cada duplicata deve ser positivo.']);
            }
            $due=(string)($duplicate['due_date']??'');
            $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$due);
            if(!$date||$date->format('Y-m-d')!==$due){
                throw ValidationException::withMessages(['duplicates_json'=>'Informe uma data de vencimento válida em cada duplicata.']);
            }
            if($previousDue!==null&&strcmp($due,$previousDue)<0){
                throw ValidationException::withMessages(['duplicates_json'=>'Informe as duplicatas em ordem de vencimento.']);
            }
            $previousDue=$due;
            $number=trim((string)($duplicate['number']??''));
            if($number!==''&&(mb_strlen($number)>3||!preg_match('/^[A-Za-z0-9]{1,3}$/',$number))){
                throw ValidationException::withMessages(['duplicates_json'=>'Confira o número da duplicata (até 3 letras ou números).']);
            }
            $number=$number!==''?$number:str_pad((string)($index+1),3,'0',STR_PAD_LEFT);
            if(isset($numbers[$number])){
                throw ValidationException::withMessages(['duplicates_json'=>'Os números das duplicatas não podem se repetir.']);
            }
            $numbers[$number]=true;
            $cents=(int)round($value*100,0,PHP_ROUND_HALF_UP);
            $sum+=$cents;
            $normalized[]=[
                'number'=>$number,
                'due_date'=>$due,'value'=>$cents/100,
            ];
        }
        if($normalized&&$sum!==(int)round((float)$totals['total']*100,0,PHP_ROUND_HALF_UP)){
            throw ValidationException::withMessages([
                'duplicates_json'=>'O total das duplicatas precisa conferir com o valor da NF-e.',
            ]);
        }
        return $normalized;
    }

    private function validatedReferences(?string $json): array
    {
        $references=$this->decodeArray($json,'documentos referenciados');

        $normalized=[];$seen=[];
        foreach($references as $reference) {
            if(!is_array($reference)){
                throw ValidationException::withMessages(['references_json'=>'Documento referenciado inválido.']);
            }
            $type=strtoupper(trim((string)($reference['type']??'NFE')));
            $key=preg_replace('/\D/','',(string)($reference['key']??''));
            if(!in_array($type,['NFE','CTE'],true)||strlen($key)!==44){
                throw ValidationException::withMessages([
                    'references_json'=>'Informe uma chave de 44 dígitos de NF-e ou CT-e.',
                ]);
            }
            if(isset($seen[$type.':'.$key])){
                throw ValidationException::withMessages(['references_json'=>'Este documento já foi referenciado.']);
            }
            $seen[$type.':'.$key]=true;
            $normalized[]=['type'=>$type,'key'=>$key];
        }
        return $normalized;
    }

    private function decodeArray(?string $json,string $label): array
    {
        if($json===null || trim($json)==='') return [];
        $decoded=json_decode($json,true);
        $limit=$label==='itens'?80:500;
        if(!is_array($decoded)||!array_is_list($decoded)||count($decoded)>$limit) {
            throw ValidationException::withMessages([$label=>"Conteúdo inválido em {$label}."]);
        }
        return $decoded;
    }
}
