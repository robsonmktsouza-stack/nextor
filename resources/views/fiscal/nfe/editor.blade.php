@extends('layouts.app')
@section('title','Fiscal')
@section('titleMeta','NF-e')
@section('content')
@include('fiscal._nav',['tab'=>'nfe','tabs'=>[
  'nfe'=>['label'=>'NF-e'],'nfse'=>['label'=>'NFS-e'],'cte'=>['label'=>'CT-e'],'nfce'=>['label'=>'NFC-e']
]])

@php
$transport=$draft->transport_data ?? [];
$freightMode=match((string)data_get($transport,'freight_mode','9')){
  'sender'=>'0','recipient'=>'1','third_party'=>'2','own_sender'=>'3','own_recipient'=>'4','none'=>'9',
  default=>(string)data_get($transport,'freight_mode','9'),
};

$existingItems=old('items_json')
  ? (json_decode(old('items_json'),true) ?: [])
  : $draft->items->map(fn($item)=>[
      'product_id'=>$item->product_id,
      'product_name'=>$item->product_name,
      'product_sku'=>$item->product_sku,
      'quantity'=>(float)$item->quantity,
      'dimension_quantity'=>(float)($item->dimension_quantity ?? 1),
      'unit_price'=>(float)$item->unit_price,
      'cfop'=>$item->cfop,
      'origin'=>$item->origin,
      'ean_gtin'=>$item->ean_gtin,
      'unit'=>$item->unit,
      'tax_unit'=>$item->tax_unit,
      'ncm'=>$item->ncm,
      'cest'=>$item->cest,
      'ipi_exception'=>$item->ipi_exception,
      'fiscal_benefit_code'=>$item->fiscal_benefit_code,
      'purchase_order'=>$item->purchase_order,
      'purchase_order_item'=>$item->purchase_order_item,
      'notes'=>$item->notes,
      'tax_data'=>$item->tax_data ?? [],
      'special_data'=>$item->special_data ?? [],
    ])->values()->all();

$existingDuplicates=old('duplicates_json')
  ? (json_decode(old('duplicates_json'),true) ?: [])
  : ($draft->duplicates ?? []);

$existingReferences=old('references_json')
  ? (json_decode(old('references_json'),true) ?: [])
  : ($draft->references ?? []);

$origins=[
 '0'=>'0 - Nacional',
 '1'=>'1 - Estrangeira: importação direta',
 '2'=>'2 - Estrangeira: adquirida no mercado interno',
 '3'=>'3 - Nacional com conteúdo de importação > 40% e <= 70%',
 '4'=>'4 - Nacional conforme processo produtivo básico',
 '5'=>'5 - Nacional com conteúdo de importação <= 40%',
 '6'=>'6 - Estrangeira: importação direta sem similar nacional',
 '7'=>'7 - Estrangeira: mercado interno sem similar nacional',
 '8'=>'8 - Nacional com conteúdo de importação > 70%',
];
@endphp

<div class="nextor-modal-layer nfe-modal-layer is-open" id="nfeEditorDialog" data-nfe-modal aria-hidden="false">
  <div class="erp-dialog nfe-workspace-dialog nextor-modal-window nfe-fixed-shell" role="dialog" aria-modal="true" aria-labelledby="nfeEditorTitle">
<form id="nfeDraftForm" method="post"
      action="{{ $isEditing ? route('fiscal.nfe.update',$draft) : route('fiscal.nfe.store') }}">
  @csrf
  @if($isEditing) @method('PUT') @endif

  <input type="hidden" name="items_json" id="nfeItemsJson" value="{{ old('items_json',json_encode($existingItems,JSON_UNESCAPED_UNICODE)) }}">
  <input type="hidden" name="duplicates_json" id="nfeDuplicatesJson" value="{{ old('duplicates_json',json_encode($existingDuplicates,JSON_UNESCAPED_UNICODE)) }}">
  <input type="hidden" name="references_json" id="nfeReferencesJson" value="{{ old('references_json',json_encode($existingReferences,JSON_UNESCAPED_UNICODE)) }}">

  <div class="dialog-header">
    <div>
      <h2 id="nfeEditorTitle">{{ $isEditing ? 'Editar NF-e em rascunho' : 'Criar nova NF-e' }}</h2>
    </div>
    <a class="close-dialog" href="{{ route('fiscal.index',['tab'=>'nfe']) }}" aria-label="Fechar">
      @include('partials.icon',['name'=>'x','size'=>18])
    </a>
  </div>

  <div class="dialog-body nfe-workspace-body">
    <div class="editor-tabs nfe-editor-tabs" data-tabs>
      <button type="button" class="editor-tab active" data-tab-target="nfe-general">Dados gerais</button>
      <button type="button" class="editor-tab" data-tab-target="nfe-items">Itens</button>
      <button type="button" class="editor-tab" data-tab-target="nfe-transport">Transporte</button>
      <button type="button" class="editor-tab" data-tab-target="nfe-payment">Pagamento</button>
      <button type="button" class="editor-tab" data-tab-target="nfe-other">Referências / observações</button>
    </div>

    <section class="editor-tab-panel active" data-tab-panel="nfe-general">
      <div class="nfe-emitter-summary">
        <div class="nfe-emitter-logo">
          @if($company->logo_path)
            <img src="{{ route('company.logo') }}" alt="">
          @endif
        </div>

        <div class="nfe-emitter-identity">
          <div class="nfe-emitter-name">
            <strong>{{ $company->legal_name ?: 'Dados da empresa incompletos' }}</strong>
            @if($company->trade_name)<span>{{ $company->trade_name }}</span>@endif
          </div>
          <div class="nfe-emitter-address">
            <span>{{ collect([$company->address,$company->address_number,$company->address_complement,$company->district])->filter()->implode(', ') ?: 'Endereço não informado' }}</span>
            <span>{{ collect([$company->city,$company->state,$company->zip_code])->filter()->implode(' - ') }}</span>
          </div>
          <div class="nfe-emitter-docs">
            <span><b>CNPJ:</b> {{ $company->document ?: '—' }}</span>
            <span><b>IE:</b> {{ $company->state_registration ?: '—' }}</span>
            <span><b>CRT:</b> {{ $company->crt ?: '—' }}</span>
          </div>
          @if(auth()->user()->canAccess('settings'))
            <a href="{{ route('settings.index',['tab'=>'general']) }}" class="nfe-emitter-edit">
              @include('partials.icon',['name'=>'edit','size'=>14]) Editar dados do emitente
            </a>
          @endif
        </div>

        <div class="nfe-document-summary">
          <span>Número da NF-e</span>
          <strong>{{ $draft->document_number ?: 'Automático' }}</strong>
          <div>
            <span>Série<b>{{ str_pad((string)($draft->series ?? 1),3,'0',STR_PAD_LEFT) }}</b></span>
            <span>Ambiente<b>{{ ($draft->environment ?? 'homologation')==='production' ? 'Produção' : 'Homologação' }}</b></span>
          </div>
        </div>
      </div>

      <div class="nfe-general-card">
        <div class="nfe-section-title">
          <h3>Dados gerais</h3>
          <button type="button" class="nfe-section-toggle" data-nfe-general-toggle>ocultar</button>
        </div>

        <div class="nfe-general-layout" id="nfeGeneralFields">
          <div class="nfe-general-fields">
            <div class="editor-grid cols-12">
              <label class="field col-12">
                <span class="nfe-field-title">
                  <span>Natureza da operação *</span>
                  <button type="button" class="nfe-clear-field" id="nfeClearNature">Limpar campo</button>
                </span>
                <div class="input-action-group">
                  <select name="operation_nature_id" id="nfeNature" required>
                    <option value="">Selecione</option>
                    @foreach($natures as $nature)
                      <option value="{{ $nature->id }}" @selected(old('operation_nature_id',$draft->operation_nature_id)===$nature->id)>
                        {{ $nature->cfop_internal ? $nature->cfop_internal.' - ' : '' }}{{ $nature->name }}
                      </option>
                    @endforeach
                  </select>
                  <a class="input-action-button" href="{{ route('fiscal.nfe.natures.index') }}" data-tooltip="Naturezas de Operação">
                    @include('partials.icon',['name'=>'settings','size'=>15])
                  </a>
                </div>
              </label>

              <label class="field col-4">
                <span>Tipo de operação</span>
                <select name="operation_type" id="nfeOperationType">
                  <option value="inbound" @selected(old('operation_type',$draft->operation_type)==='inbound')>Entrada</option>
                  <option value="outbound" @selected(old('operation_type',$draft->operation_type)==='outbound')>Saída</option>
                </select>
              </label>

              <label class="field col-4">
                <span>Destino da operação</span>
                <select name="destination" id="nfeDestination">
                  <option value="internal" @selected(old('destination',$draft->destination)==='internal' || old('destination',$draft->destination)==='auto')>Operação interna</option>
                  <option value="interstate" @selected(old('destination',$draft->destination)==='interstate')>Operação interestadual</option>
                  <option value="foreign" @selected(old('destination',$draft->destination)==='foreign')>Operação com exterior</option>
                </select>
              </label>

              <label class="field col-4">
                <span>Presença do comprador</span>
                <select name="presence" id="nfePresence">
                  <option value="not_applicable" @selected(old('presence',$draft->presence)==='not_applicable')>Não se aplica</option>
                  <option value="presential" @selected(old('presence',$draft->presence)==='presential')>Operação presencial</option>
                  <option value="internet" @selected(old('presence',$draft->presence)==='internet')>Operação não presencial, pela Internet</option>
                  <option value="phone" @selected(old('presence',$draft->presence)==='phone')>Operação não presencial, teleatendimento</option>
                  <option value="delivery_home" @selected(old('presence',$draft->presence)==='delivery_home')>NFC-e em operação com entrega a domicílio</option>
                  <option value="outside_establishment" @selected(old('presence',$draft->presence)==='outside_establishment')>Operação presencial, fora do estabelecimento</option>
                  <option value="other" @selected(old('presence',$draft->presence)==='other')>Operação não presencial, outros</option>
                </select>
              </label>

              <label class="field col-4">
                <span>Finalidade da emissão</span>
                <select name="purpose" id="nfePurpose">
                  <option value="normal" @selected(old('purpose',$draft->purpose)==='normal')>NF-e Normal</option>
                  <option value="complementary" @selected(old('purpose',$draft->purpose)==='complementary')>NF-e Complementar</option>
                  <option value="adjustment" @selected(old('purpose',$draft->purpose)==='adjustment')>NF-e de ajuste</option>
                  <option value="return" @selected(old('purpose',$draft->purpose)==='return')>Devolução de mercadoria</option>
                  <option value="credit_note" @selected(old('purpose',$draft->purpose)==='credit_note')>Nota de crédito</option>
                  <option value="debit_note" @selected(old('purpose',$draft->purpose)==='debit_note')>Nota de débito</option>
                </select>
              </label>

              <label class="field col-4">
                <span>Ins. Est. Subst. Trib.</span>
                <input name="substitute_state_registration" maxlength="30" value="{{ old('substitute_state_registration',$draft->substitute_state_registration) }}">
              </label>

              <div class="field col-4"></div>

              <div class="field col-4">
                <span>Possui documento referenciado?</span>
                <label class="switch-field">
                  <input type="hidden" name="has_referenced_document" value="0">
                  <input type="checkbox" name="has_referenced_document" id="nfeHasReferencedDocument" value="1"
                    @checked(old('has_referenced_document',$draft->has_referenced_document || !empty($draft->references)))>
                  <span class="switch-track"></span><strong data-switch-label>Sim</strong>
                </label>
              </div>

              <div class="field col-4">
                <span>Informar data de emissão</span>
                <label class="switch-field">
                  <input type="hidden" name="inform_issue_datetime" value="0">
                  <input type="checkbox" name="inform_issue_datetime" id="nfeInformIssueDatetime" value="1"
                    @checked(old('inform_issue_datetime',$draft->inform_issue_datetime ?? true))>
                  <span class="switch-track"></span><strong data-switch-label>Sim</strong>
                </label>
              </div>

              <div class="field col-4">
                <span>Informar data de saída</span>
                <label class="switch-field">
                  <input type="hidden" name="inform_exit_datetime" value="0">
                  <input type="checkbox" name="inform_exit_datetime" id="nfeInformExitDatetime" value="1"
                    @checked(old('inform_exit_datetime',$draft->inform_exit_datetime ?? false))>
                  <span class="switch-track"></span><strong data-switch-label>Sim</strong>
                </label>
              </div>

              <div class="field col-4">
                <span>Informar previsão de entrega</span>
                <label class="switch-field">
                  <input type="hidden" name="inform_expected_delivery_date" value="0">
                  <input type="checkbox" name="inform_expected_delivery_date" id="nfeInformExpectedDeliveryDate" value="1"
                    @checked(old('inform_expected_delivery_date',$draft->inform_expected_delivery_date ?? false))>
                  <span class="switch-track"></span><strong data-switch-label>Sim</strong>
                </label>
              </div>

              <div class="field col-4">
                <span>Compra governamental</span>
                <label class="switch-field">
                  <input type="hidden" name="government_purchase" value="0">
                  <input type="checkbox" name="government_purchase" value="1" @checked(old('government_purchase',$draft->government_purchase))>
                  <span class="switch-track"></span><strong data-switch-label>Sim</strong>
                </label>
              </div>

              <div class="field col-4">
                <span>Pagamento antecipado</span>
                <label class="switch-field">
                  <input type="hidden" name="advance_payment" value="0">
                  <input type="checkbox" name="advance_payment" value="1" @checked(old('advance_payment',$draft->advance_payment))>
                  <span class="switch-track"></span><strong data-switch-label>Sim</strong>
                </label>
              </div>
            </div>
          </div>

          <div class="nfe-general-dates">
            <label class="field" data-nfe-date-group="issue">
              <span>Data emissão NF (atual)</span>
              <input type="date" name="issue_date" id="nfeIssueDate" value="{{ old('issue_date',$draft->issue_date?->format('Y-m-d') ?? now()->format('Y-m-d')) }}">
            </label>
            <label class="field" data-nfe-date-group="issue">
              <span>Hora emissão NF (atual)</span>
              <input type="time" name="issue_time" id="nfeIssueTime" value="{{ old('issue_time',$draft->issue_time ?? now()->format('H:i')) }}">
            </label>
            <label class="field" data-nfe-date-group="exit">
              <span>Data saída/entrada (atual)</span>
              <input type="date" name="exit_date" id="nfeExitDate" value="{{ old('exit_date',$draft->exit_date?->format('Y-m-d')) }}">
            </label>
            <label class="field" data-nfe-date-group="exit">
              <span>Hora saída/entrada (atual)</span>
              <input type="time" name="exit_time" id="nfeExitTime" value="{{ old('exit_time',$draft->exit_time) }}">
            </label>
            <label class="field" data-nfe-date-group="delivery">
              <span>Previsão de entrega</span>
              <input type="date" name="expected_delivery_date" id="nfeExpectedDeliveryDate" value="{{ old('expected_delivery_date',$draft->expected_delivery_date?->format('Y-m-d')) }}">
            </label>
          </div>
        </div>
      </div>

      <div class="editor-panel nfe-recipient-panel">
        <h3>Dados do destinatário</h3>
        <div class="editor-grid cols-12">
          <label class="field col-8">
            <span>Destinatário *</span>
            <select name="customer_id" id="nfeCustomer" required>
              <option value="">Selecione o cliente</option>
              @foreach($customers as $customer)
                <option value="{{ $customer->id }}" @selected(old('customer_id',$draft->customer_id)===$customer->id)>
                  {{ $customer->name }}{{ $customer->document ? ' — '.$customer->document : '' }}
                </option>
              @endforeach
            </select>
          </label>

          <div class="field col-4">
            <span>Identificação</span>
            <div class="readonly-field" id="nfeRecipientSummary">Selecione um destinatário.</div>
          </div>

          <label class="field col-3"><span>Desconto da nota</span><input type="number" name="discount" id="nfeDiscount" step="0.01" min="0" value="{{ old('discount',$draft->discount ?? 0) }}"></label>
          <label class="field col-3"><span>Acréscimo da nota</span><input type="number" name="surcharge" id="nfeSurcharge" step="0.01" min="0" value="{{ old('surcharge',$draft->surcharge ?? 0) }}"></label>
        </div>
      </div>
    </section>

    <section class="editor-tab-panel" data-tab-panel="nfe-items" hidden>
      <div class="cms-card">
        <div class="grid-actionbar">
          <div class="grid-actions-left">
            <button type="button" class="grid-primary-action" id="nfeAddProduct">
              @include('partials.icon',['name'=>'plus','size'=>17]) <span>Adicionar</span> <kbd data-tutorial>F6</kbd>
            </button>
          </div>
        </div>
        <div class="table-scroll">
          <table class="cms-table">
            <thead>
              <tr>
                <th>Produto</th><th>CFOP</th><th>NCM</th><th>CSOSN/CST</th>
                <th>Qtd.</th><th>Unitário</th><th>Total</th><th class="action-cell"></th>
              </tr>
            </thead>
            <tbody id="nfeItemsBody"></tbody>
          </table>
        </div>
      </div>

      <div class="editor-panel">
        <h3>Totais da nota</h3>
        <div class="editor-grid cols-12">
          <div class="field col-2"><span>Produtos</span><div class="readonly-field" id="nfeTotalProducts">R$ 0,00</div></div>
          <div class="field col-2"><span>Frete</span><div class="readonly-field" id="nfeTotalFreight">R$ 0,00</div></div>
          <div class="field col-2"><span>IPI estimado</span><div class="readonly-field" id="nfeTotalIpi">R$ 0,00</div></div>
          <div class="field col-2"><span>Desconto</span><div class="readonly-field" id="nfeTotalDiscount">R$ 0,00</div></div>
          <div class="field col-2"><span>Acréscimo</span><div class="readonly-field" id="nfeTotalSurcharge">R$ 0,00</div></div>
          <div class="field col-2"><span>Total NF-e</span><div class="readonly-field"><strong id="nfeTotalInvoice">R$ 0,00</strong></div></div>
        </div>
      </div>
    </section>

    <section class="editor-tab-panel" data-tab-panel="nfe-transport" hidden>
      <div class="editor-panel">
        <h3>Transportadora e frete</h3>
        <div class="editor-grid cols-12">
          <label class="field col-4"><span>Modalidade do frete *</span>
            <select name="freight_mode" id="nfeFreightMode">
              <option value="9" @selected(old('freight_mode',$freightMode)==='9')>9 - Sem frete</option>
              <option value="0" @selected(old('freight_mode',$freightMode)==='0')>0 - Por conta do emitente</option>
              <option value="1" @selected(old('freight_mode',$freightMode)==='1')>1 - Por conta do destinatário</option>
              <option value="2" @selected(old('freight_mode',$freightMode)==='2')>2 - Por conta de terceiros</option>
              <option value="3" @selected(old('freight_mode',$freightMode)==='3')>3 - Próprio por conta do remetente</option>
              <option value="4" @selected(old('freight_mode',$freightMode)==='4')>4 - Próprio por conta do destinatário</option>
            </select>
          </label>

          <label class="field col-6"><span>Transportador</span>
            <select name="carrier_id">
              <option value="">Não informado</option>
              @foreach($customers->where('is_carrier',true) as $carrier)
                <option value="{{ $carrier->id }}" @selected(old('carrier_id',data_get($transport,'carrier_id'))==$carrier->id)>
                  {{ $carrier->name }}{{ $carrier->document ? ' — '.$carrier->document : '' }}
                </option>
              @endforeach
            </select>
          </label>

          <label class="field col-2"><span>Valor do frete</span><input type="number" name="freight_value" id="nfeFreightValue" min="0" step="0.01" value="{{ old('freight_value',data_get($transport,'freight_value',0)) }}"></label>

          <label class="field col-3"><span>Placa</span><input name="vehicle_plate" maxlength="12" value="{{ old('vehicle_plate',data_get($transport,'vehicle_plate')) }}"></label>
          <label class="field col-2"><span>UF do veículo</span><input name="vehicle_state" maxlength="2" value="{{ old('vehicle_state',data_get($transport,'vehicle_state')) }}"></label>
        </div>
      </div>

      <div class="editor-panel">
        <h3>Volumes transportados</h3>
        <div class="editor-grid cols-12">
          <label class="field col-2"><span>Quantidade</span><input type="number" min="0" step="1" name="volume_quantity" value="{{ old('volume_quantity',data_get($transport,'volume_quantity')) }}"></label>
          <label class="field col-3"><span>Espécie</span><input name="volume_species" value="{{ old('volume_species',data_get($transport,'volume_species')) }}"></label>
          <label class="field col-3"><span>Numeração</span><input name="volume_numbering" value="{{ old('volume_numbering',data_get($transport,'volume_numbering')) }}"></label>
          <label class="field col-2"><span>Peso líquido</span><input type="number" min="0" step="0.001" name="net_weight" value="{{ old('net_weight',data_get($transport,'net_weight')) }}"></label>
          <label class="field col-2"><span>Peso bruto</span><input type="number" min="0" step="0.001" name="gross_weight" value="{{ old('gross_weight',data_get($transport,'gross_weight')) }}"></label>
        </div>
      </div>
    </section>

    <section class="editor-tab-panel" data-tab-panel="nfe-payment" hidden>
      <div class="editor-panel">
        <h3>Pagamento</h3>
        <div class="editor-grid cols-12">
          <label class="field col-4"><span>Tipo de pagamento *</span>
            <select name="payment_type" id="nfePaymentType">
              @foreach($paymentTypes as $code=>$label)
                <option value="{{ $code }}" @selected(old('payment_type',$draft->payment_type ?: '01')===$code)>{{ $code }} - {{ $label }}</option>
              @endforeach
            </select>
          </label>

          <label class="field col-4"><span>Condição *</span>
            <select name="payment_condition" id="nfePaymentCondition">
              <option value="a_vista" @selected(old('payment_condition',$draft->payment_condition ?: 'a_vista')==='a_vista')>À vista</option>
              <option value="30_dias" @selected(old('payment_condition',$draft->payment_condition)==='30_dias')>30 dias</option>
              <option value="personalizado" @selected(old('payment_condition',$draft->payment_condition)==='personalizado')>Personalizado</option>
            </select>
          </label>

          <label class="field col-4" id="nfeOtherPaymentField"><span>Descrição quando “Outros”</span><input name="payment_other_description" value="{{ old('payment_other_description',$draft->payment_other_description) }}"></label>
        </div>
      </div>

      <div class="editor-panel" id="nfeCardPanel">
        <h3>Dados de cartão / pagamento eletrônico</h3>
        <div class="editor-grid cols-12">
          <label class="field col-4"><span>Bandeira</span>
            <select name="card_brand">
              <option value="">Não informada</option>
              @foreach($cardBrands as $code=>$label)
                <option value="{{ $code }}" @selected(old('card_brand',$draft->card_brand)===$code)>{{ $code }} - {{ $label }}</option>
              @endforeach
            </select>
          </label>
          <label class="field col-4"><span>CNPJ da credenciadora</span><input name="card_acquirer_document" value="{{ old('card_acquirer_document',$draft->card_acquirer_document) }}" maxlength="20"></label>
          <label class="field col-4"><span>Código de autorização</span><input name="card_authorization_code" value="{{ old('card_authorization_code',$draft->card_authorization_code) }}" maxlength="40"></label>
        </div>
      </div>

      <div class="editor-panel">
        <div class="delivery-card-title">
          <div>
            <h3>Duplicatas</h3>

          </div>
          <button type="button" class="btn btn-secondary" data-nfe-modal-open="nfeDuplicateDialog">@include('partials.icon',['name'=>'plus','size'=>15]) Adicionar</button>
        </div>
        <div class="table-scroll">
          <table class="cms-table">
            <thead><tr><th>Nº</th><th>Tipo pagamento</th><th>Vencimento</th><th>Valor</th><th class="action-cell"></th></tr></thead>
            <tbody id="nfeDuplicateBody"></tbody>
          </table>
        </div>

      </div>
    </section>

    <section class="editor-tab-panel" data-tab-panel="nfe-other" hidden>
      <div class="editor-panel">
        <div class="delivery-card-title">
          <div><h3>NF-e referenciadas</h3></div>
          <button type="button" class="btn btn-secondary" data-nfe-modal-open="nfeReferenceDialog">@include('partials.icon',['name'=>'plus','size'=>15]) Referenciar NF-e</button>
        </div>
        <div class="table-scroll">
          <table class="cms-table">
            <thead><tr><th>Chave de acesso</th><th class="action-cell"></th></tr></thead>
            <tbody id="nfeReferenceBody"></tbody>
          </table>
        </div>
      </div>

      <div class="editor-panel notes-panel">
        <label class="field">
          <span>Informação adicional</span>
          <textarea name="additional_info" rows="5" placeholder="Observação da operação">{{ old('additional_info',$draft->additional_info) }}</textarea>
        </label>

      </div>
    </section>
  </div>

  <div class="dialog-footer nfe-dialog-footer">
    <div class="nfe-dialog-status">
      <span class="status {{ $draft->validated_at?'status-ok':'status-muted' }}">{{ $draft->validated_at?'Validado':'Rascunho' }}</span>
      <strong id="nfeFooterTotal">R$ 0,00</strong>
    </div>
    <div>
      <a class="btn btn-secondary" href="{{ route('fiscal.index',['tab'=>'nfe']) }}">Cancelar <kbd data-tutorial>Esc</kbd></a>
      <button class="btn btn-primary" type="submit">Apenas salvar <kbd data-tutorial>Ctrl+S</kbd></button>
      <button class="btn btn-secondary" type="submit" name="after_save" value="validate" id="nfeValidateCurrent">Validar <kbd data-tutorial>F8</kbd></button>
      <button class="btn btn-success" type="button" disabled data-tooltip="Emissão indisponível">Emitir NF-e</button>
    </div>
  </div>
</form>
  </div>
</div>

<div class="nextor-modal-layer nfe-modal-layer" id="nfeItemDialog" data-nfe-modal aria-hidden="true" hidden>
  <div class="erp-dialog nfe-item-dialog nextor-modal-window" role="dialog" aria-modal="true" aria-labelledby="nfeItemTitle">
  <div class="dialog-header">
    <div><h2 id="nfeItemTitle">Item da NF-e</h2></div>
    <button type="button" data-nfe-modal-close class="close-dialog" aria-label="Fechar">@include('partials.icon',['name'=>'x'])</button>
  </div>

  <div class="dialog-body nfe-item-body">
    <input type="hidden" id="nfeItemIndex">

    <div class="editor-tabs nfe-item-tabs" data-nfe-item-tabs>
      <button type="button" class="editor-tab active" data-nfe-item-tab="item-general">Produto</button>
      <button type="button" class="editor-tab" data-nfe-item-tab="item-fiscal">Fiscal</button>
      <button type="button" class="editor-tab" data-nfe-item-tab="item-icms">ICMS / ST</button>
      <button type="button" class="editor-tab" data-nfe-item-tab="item-federal">PIS / COFINS / IPI</button>
      <button type="button" class="editor-tab" data-nfe-item-tab="item-difal">DIFAL / FCP</button>
      <button type="button" class="editor-tab" data-nfe-item-tab="item-specific">Específicos</button>
      <button type="button" class="editor-tab" data-nfe-item-tab="item-ibscbs">IBS / CBS</button>
    </div>

    <section class="editor-tab-panel active" data-nfe-item-panel="item-general">
      <div class="editor-panel">
        <h3>Dados comerciais</h3>
        <div class="editor-grid cols-12">
          <div class="field col-8">
            <span>Buscar produto cadastrado</span>
            <div class="sale-item-search-wrap nfe-product-search">
              <input type="text" class="sale-item-search" id="nfeItemProductSearch" autocomplete="off" placeholder="Buscar por nome, código, GTIN ou NCM">
              <input type="hidden" id="nfeItemProduct">
              <div class="sale-item-suggestions" id="nfeItemProductSuggestions" hidden></div>
            </div>
          </div>
          <div class="field col-4">
            <span>Cadastro de produtos</span>
            <a class="btn btn-light nfe-product-register" href="{{ route('products.create') }}" target="_blank" rel="noopener">
              @include('partials.icon',['name'=>'plus','size'=>15]) Cadastrar produto
            </a>
          </div>

          <label class="field col-8"><span>Descrição do item *</span><input id="nfeItemName" maxlength="190" placeholder="Descrição que será enviada na NF-e"></label>
          <label class="field col-4"><span>Código próprio</span><input id="nfeItemSku" maxlength="80" placeholder="Opcional"></label>

          <label class="field col-2"><span>Quantidade *</span><input id="nfeItemQuantity" type="number" step="0.0001" min="0.0001" value="1"></label>
          <label class="field col-2"><span>Fator / dimensão</span><input id="nfeItemDimensionQuantity" type="number" step="0.0001" min="0.0001" value="1"></label>
          <label class="field col-2"><span>Valor unitário *</span><input id="nfeItemUnitPrice" type="number" step="0.0001" min="0"></label>
          <div class="field col-2"><span>Subtotal</span><div class="readonly-field" id="nfeItemSubtotal">R$ 0,00</div></div>
          <label class="field col-3"><span>Descrição do pedido (xPed)</span><input id="nfeItemPurchaseOrder"></label>
          <label class="field col-1"><span>Item</span><input id="nfeItemPurchaseOrderItem"></label>
          <label class="field col-12"><span>Observação do item</span><input id="nfeItemNotes"></label>
        </div>

      </div>
    </section>

    <section class="editor-tab-panel" data-nfe-item-panel="item-fiscal" hidden>
      <div class="editor-panel">
        <h3>Identificação fiscal do produto</h3>
        <div class="editor-grid cols-12">
          <label class="field col-3"><span>NCM *</span><input id="nfeItemNcm" maxlength="10"></label>
          <label class="field col-3"><span>CEST</span><input id="nfeItemCest" maxlength="10"></label>
          <label class="field col-3"><span>Código benefício (cBenef)</span><input id="nfeItemBenefit" maxlength="40"></label>
          <label class="field col-3"><span>Exceção TIPI</span><input id="nfeItemIpiException" maxlength="20"></label>

          <label class="field col-6"><span>Origem *</span>
            <select id="nfeItemOrigin">@foreach($origins as $code=>$label)<option value="{{ $code }}">{{ $label }}</option>@endforeach</select>
          </label>
          <label class="field col-3"><span>EAN / GTIN</span><input id="nfeItemGtin" maxlength="32"></label>
          <label class="field col-3"><span>Unidade comercial *</span><input id="nfeItemUnit" maxlength="12"></label>

          <label class="field col-3"><span>Unidade tributável</span><input id="nfeItemTaxUnit" maxlength="12"></label>
          <label class="field col-3"><span>Qtd. tributável por unidade</span><input id="nfeTaxQuantityFactor" type="number" step="0.0001" min="0" value="1"></label>
          <label class="field col-3"><span>CFOP aplicado</span><input id="nfeItemCfop" maxlength="10"></label>
        </div>
      </div>

      <div class="editor-panel">
        <h3>CFOP cadastrado no produto</h3>
        <div class="editor-grid cols-12">
          <label class="field col-3"><span>Saída interna</span><input id="nfeCfopOutboundInternal" maxlength="10"></label>
          <label class="field col-3"><span>Saída interestadual</span><input id="nfeCfopOutboundInterstate" maxlength="10"></label>
          <label class="field col-3"><span>Entrada interna</span><input id="nfeCfopInboundInternal" maxlength="10"></label>
          <label class="field col-3"><span>Entrada interestadual</span><input id="nfeCfopInboundInterstate" maxlength="10"></label>
        </div>
      </div>
    </section>

    <section class="editor-tab-panel" data-nfe-item-panel="item-icms" hidden>
      <div class="editor-panel">
        <h3>ICMS</h3>
        <div class="editor-grid cols-12">
          <label class="field col-3"><span>CSOSN</span><input id="nfeTaxCsosn" maxlength="4"></label>
          <label class="field col-3"><span>CST ICMS</span><input id="nfeTaxIcmsCst" maxlength="4"></label>
          <label class="field col-3"><span>CSOSN exportação</span><input id="nfeTaxCsosnExport" maxlength="4"></label>
          <label class="field col-3"><span>CSOSN entrada</span><input id="nfeTaxCsosnInbound" maxlength="4"></label>
          <label class="field col-3"><span>CST ICMS entrada</span><input id="nfeTaxIcmsCstInbound" maxlength="4"></label>
          <label class="field col-3"><span>Alíquota ICMS %</span><input id="nfeTaxIcmsRate" type="number" step="0.0001"></label>

          <label class="field col-3"><span>Redução da BC %</span><input id="nfeTaxBaseReduction" type="number" step="0.0001"></label>
          <label class="field col-3"><span>Crédito Simples %</span><input id="nfeTaxSimpleCredit" type="number" step="0.0001"></label>
          <label class="field col-3"><span>Modalidade BC</span><input id="nfeTaxModBc"></label>
          <label class="field col-3"><span>Modalidade BC ST</span><input id="nfeTaxModBcSt"></label>

          <label class="field col-3"><span>ICMS ST %</span><input id="nfeTaxIcmsStRate" type="number" step="0.0001"></label>
          <label class="field col-3"><span>MVA %</span><input id="nfeTaxMvaRate" type="number" step="0.0001"></label>
        </div>
      </div>
    </section>

    <section class="editor-tab-panel" data-nfe-item-panel="item-federal" hidden>
      <div class="editor-panel">
        <h3>PIS / COFINS / IPI</h3>
        <div class="editor-grid cols-12">
          <label class="field col-2"><span>CST PIS *</span><input id="nfeTaxPisCst" maxlength="4"></label>
          <label class="field col-2"><span>PIS %</span><input id="nfeTaxPisRate" type="number" step="0.0001"></label>
          <label class="field col-2"><span>CST PIS entrada</span><input id="nfeTaxPisCstInbound" maxlength="4"></label>
          <label class="field col-2"><span>CST COFINS *</span><input id="nfeTaxCofinsCst" maxlength="4"></label>
          <label class="field col-2"><span>COFINS %</span><input id="nfeTaxCofinsRate" type="number" step="0.0001"></label>
          <label class="field col-2"><span>CST COFINS entrada</span><input id="nfeTaxCofinsCstInbound" maxlength="4"></label>
          <label class="field col-2"><span>CST IPI *</span><input id="nfeTaxIpiCst" maxlength="4"></label>
          <label class="field col-2"><span>IPI %</span><input id="nfeTaxIpiRate" type="number" step="0.0001"></label>
          <label class="field col-2"><span>CST IPI entrada</span><input id="nfeTaxIpiCstInbound" maxlength="4"></label>

          <label class="field col-3"><span>Enquadramento IPI (cEnq)</span><input id="nfeTaxIpiEnq" maxlength="10" value="999"></label>
          <label class="field col-3"><span>ISS %</span><input id="nfeTaxIssRate" type="number" step="0.0001"></label>
          <label class="field col-3"><span>Item lista de serviço</span><input id="nfeTaxServiceListCode"></label>
        </div>
      </div>
    </section>

    <section class="editor-tab-panel" data-nfe-item-panel="item-difal" hidden>
      <div class="editor-panel">
        <h3>ICMS destino / FCP</h3>

        <div class="editor-grid cols-12">
          <label class="field col-4"><span>ICMS interestadual %</span><input id="nfeTaxInterstateRate" type="number" step="0.0001"></label>
          <label class="field col-4"><span>ICMS interno destino %</span><input id="nfeTaxInternalRate" type="number" step="0.0001"></label>
          <label class="field col-4"><span>FCP interestadual %</span><input id="nfeTaxFcpRate" type="number" step="0.0001"></label>
        </div>
      </div>
    </section>

    <section class="editor-tab-panel" data-nfe-item-panel="item-specific" hidden>
      <div class="editor-panel">
        <h3>Combustível / ANP</h3>
        <div class="editor-grid cols-12">
          <div class="field col-3"><span>Derivado de petróleo?</span>
            <label class="switch-field"><input type="checkbox" id="nfeSpecialPetroleum"><span class="switch-track"></span><strong data-switch-label>Sim</strong></label>
          </div>
          <label class="field col-3"><span>Código ANP</span><input id="nfeSpecialAnpCode"></label>
          <label class="field col-6"><span>Descrição ANP</span><input id="nfeSpecialAnpDescription"></label>
          <label class="field col-3"><span>% GLP</span><input id="nfeSpecialGlp" type="number" step="0.0001"></label>
          <label class="field col-3"><span>% GNn</span><input id="nfeSpecialGnn" type="number" step="0.0001"></label>
          <label class="field col-3"><span>% GNi</span><input id="nfeSpecialGni" type="number" step="0.0001"></label>
          <label class="field col-3"><span>Valor de partida</span><input id="nfeSpecialStartingValue" type="number" step="0.0001"></label>
        </div>
      </div>

      <div class="editor-panel">
        <h3>Lote e produto específico</h3>
        <div class="editor-grid cols-12">
          <label class="field col-3"><span>Lote</span><input id="nfeSpecialBatch"></label>
          <label class="field col-3"><span>Vencimento</span><input id="nfeSpecialExpiry" type="date"></label>
          <label class="field col-3"><span>RENAVAM</span><input id="nfeSpecialRenavam"></label>
          <label class="field col-3"><span>Chassi</span><input id="nfeSpecialChassis"></label>
          <label class="field col-3"><span>Placa</span><input id="nfeSpecialPlate"></label>
          <label class="field col-3"><span>Combustível</span><input id="nfeSpecialFuel"></label>
          <label class="field col-3"><span>Ano/modelo</span><input id="nfeSpecialYearModel"></label>
          <label class="field col-3"><span>Cor</span><input id="nfeSpecialVehicleColor"></label>
        </div>
      </div>
    </section>

    <section class="editor-tab-panel" data-nfe-item-panel="item-ibscbs" hidden>
      <div class="editor-panel">
        <h3>IBS / CBS — estrutura NEXTOR</h3>

        <div class="editor-grid cols-12">
          <label class="field col-3"><span>CST IBS</span><input id="nfeTaxIbsCst"></label>
          <label class="field col-3"><span>CST CBS</span><input id="nfeTaxCbsCst"></label>
          <label class="field col-6"><span>Classificação tributária</span><input id="nfeTaxClassification"></label>
        </div>
      </div>
    </section>
  </div>

  <div class="dialog-footer">
    <button type="button" data-nfe-modal-close class="btn btn-secondary">Cancelar</button>
    <button type="button" class="btn btn-success" id="nfeSaveItem">Salvar item</button>
  </div>
  </div>
</div>

<div class="nextor-modal-layer nfe-modal-layer" id="nfeReferenceDialog" data-nfe-modal aria-hidden="true" hidden>
  <div class="erp-dialog small-dialog nextor-modal-window nfe-small-modal-window" role="dialog" aria-modal="true" aria-labelledby="nfeReferenceTitle">
  <div class="dialog-header">
    <div><h2 id="nfeReferenceTitle">Referenciar NF-e</h2><p>Informe a chave de acesso do documento referenciado.</p></div>
    <button type="button" data-nfe-modal-close class="close-dialog">@include('partials.icon',['name'=>'x'])</button>
  </div>
  <div class="dialog-body">
    <label class="field"><span>Chave de acesso</span><input id="nfeReferenceKey" maxlength="44"></label>
  </div>
  <div class="dialog-footer">
    <button type="button" data-nfe-modal-close class="btn btn-secondary">Cancelar</button>
    <button type="button" class="btn btn-success" id="nfeSaveReference">Adicionar</button>
  </div>
  </div>
</div>

<div class="nextor-modal-layer nfe-modal-layer" id="nfeDuplicateDialog" data-nfe-modal aria-hidden="true" hidden>
  <div class="erp-dialog small-dialog nextor-modal-window nfe-small-modal-window" role="dialog" aria-modal="true" aria-labelledby="nfeDuplicateTitle">
  <div class="dialog-header">
    <div><h2 id="nfeDuplicateTitle">Adicionar duplicata</h2><p>Parcela usada na cobrança da NF-e.</p></div>
    <button type="button" data-nfe-modal-close class="close-dialog">@include('partials.icon',['name'=>'x'])</button>
  </div>
  <div class="dialog-body">
    <div class="form-grid">
      <label class="field"><span>Número</span><input id="nfeDuplicateNumber"></label>
      <label class="field"><span>Vencimento</span><input id="nfeDuplicateDue" type="date"></label>
      <label class="field"><span>Tipo de pagamento</span>
        <select id="nfeDuplicatePaymentType">
          @foreach($paymentTypes as $code=>$label)<option value="{{ $code }}">{{ $code }} - {{ $label }}</option>@endforeach
        </select>
      </label>
      <label class="field"><span>Valor</span><input id="nfeDuplicateValue" type="number" min="0" step="0.01"></label>
    </div>
  </div>
  <div class="dialog-footer">
    <button type="button" data-nfe-modal-close class="btn btn-secondary">Cancelar</button>
    <button type="button" class="btn btn-success" id="nfeSaveDuplicate">Adicionar</button>
  </div>
  </div>
</div>

<script type="application/json" id="nfe-products-data">@json($productData)</script>
<script type="application/json" id="nfe-customers-data">@json($customerData)</script>
<script type="application/json" id="nfe-natures-data">@json($natureData)</script>
<script type="application/json" id="nfe-tax-defaults-data">@json($taxDefaults)</script>
<script type="application/json" id="nfe-emitter-state">@json($company->state)</script>
@endsection

@push('scripts')
<script src="{{ asset('js/nfe-editor.js') }}"></script>

@endpush
