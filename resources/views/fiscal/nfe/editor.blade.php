@extends('layouts.app')
@section('title','Fiscal')
@section('titleMeta','NF-e')
@section('content')
@include('fiscal._nav',['tab'=>'nfe','tabs'=>[
  'nfe'=>['label'=>'NF-e'],'nfse'=>['label'=>'NFS-e'],'cte'=>['label'=>'CT-e'],'nfce'=>['label'=>'NFC-e']
]])

<section class="cms-card sales-module-card nfe-editor-underlay">
  <div class="empty-cell">Editor de NF-e aberto.</div>
</section>

@php
  $transport=$draft->transport_data ?? [];
  $invoice=$draft->invoice_data ?? [];

  $existingItems=old('items_json')
    ? (json_decode(old('items_json'),true) ?: [])
    : $draft->items->map(fn($item)=>[
        'id'=>$item->id,
        'product_id'=>$item->product_id,
        'product_name'=>$item->product_name,
        'product_sku'=>$item->product_sku,
        'quantity'=>(float)$item->quantity,
        'unit_price'=>(float)$item->unit_price,
        'freight'=>(float)$item->freight,
        'insurance'=>(float)$item->insurance,
        'other_expenses'=>(float)$item->other_expenses,
        'discount'=>(float)$item->discount,
        'line_total'=>(float)$item->line_total,
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
  $existingPayments=old('payments_json')
    ? (json_decode(old('payments_json'),true) ?: [])
    : ($draft->payments ?? []);
  $existingReferences=old('references_json')
    ? (json_decode(old('references_json'),true) ?: [])
    : ($draft->references ?? []);
  $existingCustomFields=old('custom_fields_json')
    ? (json_decode(old('custom_fields_json'),true) ?: [])
    : ($draft->custom_fields ?? []);

  $environmentLabel=($draft->environment ?? 'homologation')==='production'?'Produção':'Homologação';
@endphp

<dialog class="erp-dialog nfe-editor-dialog" id="nfeEditorDialog">
  <form id="nfeDraftForm" class="nfe-editor-form"
        method="post"
        action="{{ $isEditing ? route('fiscal.nfe.update',$draft) : route('fiscal.nfe.store') }}">
    @csrf
    @if($isEditing) @method('PUT') @endif

    <input type="hidden" name="items_json" id="nfeItemsJson" value="{{ old('items_json',json_encode($existingItems,JSON_UNESCAPED_UNICODE)) }}">
    <input type="hidden" name="duplicates_json" id="nfeDuplicatesJson" value="{{ old('duplicates_json',json_encode($existingDuplicates,JSON_UNESCAPED_UNICODE)) }}">
    <input type="hidden" name="payments_json" id="nfePaymentsJson" value="{{ old('payments_json',json_encode($existingPayments,JSON_UNESCAPED_UNICODE)) }}">
    <input type="hidden" name="references_json" id="nfeReferencesJson" value="{{ old('references_json',json_encode($existingReferences,JSON_UNESCAPED_UNICODE)) }}">
    <input type="hidden" name="custom_fields_json" id="nfeCustomFieldsJson" value="{{ old('custom_fields_json',json_encode($existingCustomFields,JSON_UNESCAPED_UNICODE)) }}">

    <header class="nfe-editor-header">
      <div class="nfe-editor-title">
        <div>
          <h2>{{ $isEditing ? 'Editando NF-e em rascunho' : 'Criando nova NF-e' }}</h2>
          <span>{{ $isEditing ? 'Rascunho #'.$draft->id : 'Novo documento fiscal' }}</span>
        </div>
        <a class="close-dialog" href="{{ route('fiscal.index',['tab'=>'nfe']) }}" aria-label="Fechar editor">@include('partials.icon',['name'=>'x','size'=>18])</a>
      </div>

      <div class="nfe-emitter-strip">
        <div class="nfe-emitter-data">
          <strong>{{ $company->legal_name ?: 'Configure os dados da empresa' }}</strong>
          @if($company->trade_name)<span>{{ $company->trade_name }}</span>@endif
          <span>{{ collect([$company->address,$company->address_number,$company->district,$company->city,$company->state])->filter()->implode(' · ') }}</span>
          <span><b>CNPJ:</b> {{ $company->document ?: '—' }} &nbsp; <b>IE:</b> {{ $company->state_registration ?: '—' }}</span>
          <span><b>CRT:</b> {{ $company->tax_regime ?: ($company->crt ?: '—') }}</span>
          @if(auth()->user()->canAccess('settings'))
            <a href="{{ route('settings.index',['tab'=>'company']) }}">Editar dados do emitente</a>
          @endif
        </div>

        <div class="nfe-number-box">
          <span>Número da NF-e</span>
          <strong>{{ $isEditing && $draft->document_number ? $draft->document_number : '(automático)' }}</strong>
          <div><span>Série <b>{{ $draft->series ?? 1 }}</b></span><span>Ambiente <b>{{ $environmentLabel }}</b></span></div>
        </div>
      </div>
    </header>

    <div class="nfe-editor-scroll">
      <details class="nfe-section" open>
        <summary><span>Dados gerais</span><small>Operação, finalidade e datas</small></summary>
        <div class="nfe-section-body">
          <div class="editor-grid cols-12">
            <label class="field col-8"><span>Natureza da operação *</span>
              <div class="input-action-group">
                <select name="operation_nature_id" id="nfeNature" required>
                  <option value="">Selecione</option>
                  @foreach($natures as $nature)
                    <option value="{{ $nature->id }}" @selected(old('operation_nature_id',$draft->operation_nature_id)==$nature->id)>{{ $nature->name }}</option>
                  @endforeach
                </select>
                <a class="input-action-button" href="{{ route('fiscal.nfe.natures.index') }}" data-tooltip="Gerenciar naturezas">@include('partials.icon',['name'=>'settings','size'=>15])</a>
              </div>
              @if($natures->isEmpty())<small class="field-warning">Cadastre uma natureza de operação antes de salvar a NF-e.</small>@endif
            </label>

            <label class="field col-2"><span>Tipo de operação *</span>
              <select name="operation_type" id="nfeOperationType">
                <option value="outbound" @selected(old('operation_type',$draft->operation_type)==='outbound')>Saída</option>
                <option value="inbound" @selected(old('operation_type',$draft->operation_type)==='inbound')>Entrada</option>
              </select>
            </label>

            <label class="field col-2"><span>Finalidade *</span>
              <select name="purpose" id="nfePurpose">
                <option value="normal" @selected(old('purpose',$draft->purpose)==='normal')>NF-e normal</option>
                <option value="complementary" @selected(old('purpose',$draft->purpose)==='complementary')>Complementar</option>
                <option value="adjustment" @selected(old('purpose',$draft->purpose)==='adjustment')>Ajuste</option>
                <option value="return" @selected(old('purpose',$draft->purpose)==='return')>Devolução</option>
              </select>
            </label>

            <label class="field col-4"><span>Destino da operação</span>
              <select name="destination" id="nfeDestination">
                <option value="auto" @selected(old('destination',$draft->destination)==='auto')>Automático pelo destinatário</option>
                <option value="internal" @selected(old('destination',$draft->destination)==='internal')>Operação interna</option>
                <option value="interstate" @selected(old('destination',$draft->destination)==='interstate')>Operação interestadual</option>
                <option value="foreign" @selected(old('destination',$draft->destination)==='foreign')>Operação com exterior</option>
              </select>
            </label>

            <label class="field col-4"><span>Presença do comprador</span>
              <select name="presence" id="nfePresence">
                <option value="not_applicable" @selected(old('presence',$draft->presence)==='not_applicable')>Não se aplica</option>
                <option value="presential" @selected(old('presence',$draft->presence)==='presential')>Presencial</option>
                <option value="internet" @selected(old('presence',$draft->presence)==='internet')>Internet</option>
                <option value="phone" @selected(old('presence',$draft->presence)==='phone')>Teleatendimento</option>
                <option value="outside_establishment" @selected(old('presence',$draft->presence)==='outside_establishment')>Presencial fora do estabelecimento</option>
                <option value="other" @selected(old('presence',$draft->presence)==='other')>Outros</option>
              </select>
            </label>

            <div class="field col-4"><span>Destinado a consumidor final?</span>
              <label class="switch-field">
                <input type="hidden" name="final_consumer" value="0">
                <input type="checkbox" name="final_consumer" id="nfeFinalConsumer" value="1" @checked(old('final_consumer',$draft->final_consumer))>
                <span class="switch-track"></span><strong data-switch-label>Sim</strong>
              </label>
            </div>

            <label class="field col-3"><span>Data de emissão *</span><input type="date" name="issue_date" value="{{ old('issue_date',$draft->issue_date?->format('Y-m-d') ?? now()->format('Y-m-d')) }}" required></label>
            <label class="field col-3"><span>Hora de emissão</span><input type="time" name="issue_time" value="{{ old('issue_time',$draft->issue_time ?? now()->format('H:i')) }}"></label>
            <label class="field col-3"><span>Data saída/entrada</span><input type="date" name="exit_date" value="{{ old('exit_date',$draft->exit_date?->format('Y-m-d')) }}"></label>
            <label class="field col-3"><span>Hora saída/entrada</span><input type="time" name="exit_time" value="{{ old('exit_time',$draft->exit_time) }}"></label>

            <label class="field col-3"><span>Previsão de entrega</span><input type="date" name="expected_delivery_date" value="{{ old('expected_delivery_date',$draft->expected_delivery_date?->format('Y-m-d')) }}"></label>

            <div class="field col-3"><span>Compra governamental</span>
              <label class="switch-field"><input type="hidden" name="government_purchase" value="0"><input type="checkbox" name="government_purchase" value="1" @checked(old('government_purchase',$draft->government_purchase))><span class="switch-track"></span><strong data-switch-label>Sim</strong></label>
            </div>
            <div class="field col-3"><span>Pagamento antecipado</span>
              <label class="switch-field"><input type="hidden" name="advance_payment" value="0"><input type="checkbox" name="advance_payment" value="1" @checked(old('advance_payment',$draft->advance_payment))><span class="switch-track"></span><strong data-switch-label>Sim</strong></label>
            </div>
            <div class="field col-3"><span>Documento referenciado</span>
              <button class="btn btn-secondary nfe-inline-action" type="button" data-dialog-open="nfeReferenceDialog">@include('partials.icon',['name'=>'plus','size'=>15]) Adicionar</button>
            </div>
          </div>

          <div class="nfe-mini-table" id="nfeReferenceList"></div>
        </div>
      </details>

      <details class="nfe-section" open>
        <summary><span>Dados do destinatário</span><small>Cadastro e endereço de entrega</small></summary>
        <div class="nfe-section-body">
          <div class="editor-grid cols-12">
            <label class="field col-7"><span>Razão / Nome destinatário *</span>
              <select name="customer_id" id="nfeCustomer" required>
                <option value="">Selecione o destinatário</option>
                @foreach($customers as $customer)
                  <option value="{{ $customer->id }}" @selected(old('customer_id',$draft->customer_id)==$customer->id)>
                    {{ $customer->name }}{{ $customer->document ? ' — '.$customer->document : '' }}
                  </option>
                @endforeach
              </select>
            </label>

            <div class="field col-2"><span>Consumidor final</span><div class="nfe-readonly-value" id="nfeCustomerFinal">—</div></div>

            <div class="field col-3"><span>Endereço de entrega diferente</span>
              <label class="switch-field">
                <input type="hidden" name="different_delivery" value="0">
                <input type="checkbox" name="different_delivery" id="nfeDifferentDelivery" value="1" @checked(old('different_delivery',$draft->different_delivery))>
                <span class="switch-track"></span><strong data-switch-label>Sim</strong>
              </label>
            </div>

            <div class="col-12 nfe-recipient-summary" id="nfeRecipientSummary">
              <span>Selecione um destinatário para visualizar CPF/CNPJ, IE e endereço.</span>
            </div>

            <label class="field col-12" id="nfeDeliveryField" hidden><span>Endereço de entrega</span>
              <select name="delivery_address_id" id="nfeDeliveryAddress">
                <option value="">Selecione</option>
              </select>
            </label>
          </div>
        </div>
      </details>

      <details class="nfe-section" open>
        <summary><span>Lista de produtos</span><small>Itens comerciais e tributação</small></summary>
        <div class="nfe-section-body nfe-products-body">
          <div class="nfe-product-toolbar">
            <button type="button" class="btn btn-primary" id="nfeAddProduct">@include('partials.icon',['name'=>'plus','size'=>16]) Adicionar produto <kbd data-tutorial>F6</kbd></button>
            <span>Dados fiscais vêm do cadastro do produto e podem ser ajustados por item.</span>
          </div>

          <div class="table-scroll">
            <table class="cms-table nfe-items-table">
              <thead><tr><th>Produto</th><th>CFOP</th><th>NCM</th><th>Qtd.</th><th>Unitário</th><th>Desconto</th><th>Total</th><th class="action-cell">Ações</th></tr></thead>
              <tbody id="nfeItemsBody"></tbody>
            </table>
          </div>
        </div>
      </details>

      <section class="nfe-total-section">
        <div class="nfe-total-title"><h3>Totais</h3><span>Calculados automaticamente pelos itens do rascunho.</span></div>
        <div class="nfe-total-grid">
          <div><span>Produtos</span><strong id="nfeTotalProducts">R$ 0,00</strong></div>
          <div><span>Frete</span><strong id="nfeTotalFreight">R$ 0,00</strong></div>
          <div><span>Seguro</span><strong id="nfeTotalInsurance">R$ 0,00</strong></div>
          <div><span>Outras despesas</span><strong id="nfeTotalOther">R$ 0,00</strong></div>
          <div><span>Desconto</span><strong id="nfeTotalDiscount">R$ 0,00</strong></div>
          <div class="nfe-total-main"><span>TOTAL DA NF</span><strong id="nfeTotalInvoice">R$ 0,00</strong></div>
        </div>
      </section>

      <details class="nfe-section">
        <summary><span>Retenções de impostos</span><small>Campos especiais, quando aplicáveis</small></summary>
        <div class="nfe-section-body">
          <div class="editor-grid cols-12">
            <label class="field col-3"><span>IRRF</span><input type="number" step="0.01" min="0" data-nfe-custom="retention_irrf" value="{{ data_get($existingCustomFields,'retention_irrf') }}"></label>
            <label class="field col-3"><span>INSS</span><input type="number" step="0.01" min="0" data-nfe-custom="retention_inss" value="{{ data_get($existingCustomFields,'retention_inss') }}"></label>
            <label class="field col-3"><span>CSLL</span><input type="number" step="0.01" min="0" data-nfe-custom="retention_csll" value="{{ data_get($existingCustomFields,'retention_csll') }}"></label>
            <label class="field col-3"><span>Outras retenções</span><input type="number" step="0.01" min="0" data-nfe-custom="retention_other" value="{{ data_get($existingCustomFields,'retention_other') }}"></label>
          </div>
        </div>
      </details>

      <details class="nfe-section" open>
        <summary><span>Fatura e duplicatas</span><small>Cobrança da operação</small></summary>
        <div class="nfe-section-body">
          <div class="editor-grid cols-12">
            <label class="field col-3"><span>Número da fatura</span><input name="invoice_number" value="{{ old('invoice_number',data_get($invoice,'number')) }}"></label>
            <label class="field col-3"><span>Valor original</span><input type="number" step="0.01" min="0" name="invoice_original_value" value="{{ old('invoice_original_value',data_get($invoice,'original_value')) }}"></label>
            <label class="field col-3"><span>Valor do desconto</span><input type="number" step="0.01" min="0" name="invoice_discount" value="{{ old('invoice_discount',data_get($invoice,'discount')) }}"></label>
            <label class="field col-3"><span>Valor líquido</span><input type="number" step="0.01" min="0" name="invoice_net_value" value="{{ old('invoice_net_value',data_get($invoice,'net_value')) }}"></label>
          </div>

          <div class="nfe-subtable-head"><strong>Duplicatas</strong><button type="button" class="btn btn-secondary" data-dialog-open="nfeDuplicateDialog">@include('partials.icon',['name'=>'plus','size'=>15]) Adicionar duplicata</button></div>
          <div class="nfe-mini-table" id="nfeDuplicateList"></div>
        </div>
      </details>

      <details class="nfe-section" open>
        <summary><span>Informações de pagamento</span><small>Meios e valores informados na NF-e</small></summary>
        <div class="nfe-section-body">
          <div class="nfe-subtable-head"><strong id="nfePaymentCount">0 pagamento(s)</strong><button type="button" class="btn btn-secondary" data-dialog-open="nfePaymentDialog">@include('partials.icon',['name'=>'plus','size'=>15]) Adicionar pagamento</button></div>
          <div class="nfe-mini-table" id="nfePaymentList"></div>
        </div>
      </details>

      <details class="nfe-section" open>
        <summary><span>Dados do transporte</span><small>Frete, transportador, veículo e volumes</small></summary>
        <div class="nfe-section-body">
          <div class="editor-grid cols-12">
            <label class="field col-4"><span>Modalidade do frete *</span>
              <select name="freight_mode">
                <option value="sender" @selected(old('freight_mode',data_get($transport,'freight_mode','none'))==='sender')>Por conta do emitente</option>
                <option value="recipient" @selected(old('freight_mode',data_get($transport,'freight_mode'))==='recipient')>Por conta do destinatário</option>
                <option value="third_party" @selected(old('freight_mode',data_get($transport,'freight_mode'))==='third_party')>Por conta de terceiros</option>
                <option value="own_sender" @selected(old('freight_mode',data_get($transport,'freight_mode'))==='own_sender')>Transporte próprio do emitente</option>
                <option value="own_recipient" @selected(old('freight_mode',data_get($transport,'freight_mode'))==='own_recipient')>Transporte próprio do destinatário</option>
                <option value="none" @selected(old('freight_mode',data_get($transport,'freight_mode','none'))==='none')>Sem ocorrência de transporte</option>
              </select>
            </label>

            <label class="field col-8"><span>Transportador</span>
              <select name="carrier_id">
                <option value="">Não informado</option>
                @foreach($customers->where('is_carrier',true) as $carrier)
                  <option value="{{ $carrier->id }}" @selected(old('carrier_id',data_get($transport,'carrier_id'))==$carrier->id)>{{ $carrier->name }}{{ $carrier->document?' — '.$carrier->document:'' }}</option>
                @endforeach
              </select>
            </label>

            <label class="field col-3"><span>Placa do veículo</span><input name="vehicle_plate" maxlength="12" value="{{ old('vehicle_plate',data_get($transport,'vehicle_plate')) }}"></label>
            <label class="field col-2"><span>UF veículo</span><input name="vehicle_state" maxlength="2" value="{{ old('vehicle_state',data_get($transport,'vehicle_state')) }}"></label>
            <label class="field col-2"><span>Volumes</span><input type="number" min="0" step="1" name="volume_quantity" value="{{ old('volume_quantity',data_get($transport,'volume_quantity')) }}"></label>
            <label class="field col-2"><span>Espécie</span><input name="volume_species" value="{{ old('volume_species',data_get($transport,'volume_species')) }}"></label>
            <label class="field col-3"><span>Numeração</span><input name="volume_numbering" value="{{ old('volume_numbering',data_get($transport,'volume_numbering')) }}"></label>

            <label class="field col-3"><span>Peso líquido (kg)</span><input type="number" min="0" step="0.001" name="net_weight" value="{{ old('net_weight',data_get($transport,'net_weight')) }}"></label>
            <label class="field col-3"><span>Peso bruto (kg)</span><input type="number" min="0" step="0.001" name="gross_weight" value="{{ old('gross_weight',data_get($transport,'gross_weight')) }}"></label>
          </div>
        </div>
      </details>

      <details class="nfe-section">
        <summary><span>Informações de compras</span><small>Pedidos e contratos específicos</small></summary>
        <div class="nfe-section-body">
          <label class="field"><span>Dados de compras</span><textarea rows="3" data-nfe-custom="purchase_info" placeholder="Pedido de compra, contrato ou informação específica">{{ data_get($existingCustomFields,'purchase_info') }}</textarea></label>
        </div>
      </details>

      <details class="nfe-section">
        <summary><span>Informações de agropecuária</span><small>Somente quando a operação exigir</small></summary>
        <div class="nfe-section-body">
          <label class="field"><span>Dados de agropecuária</span><textarea rows="3" data-nfe-custom="agriculture_info">{{ data_get($existingCustomFields,'agriculture_info') }}</textarea></label>
        </div>
      </details>

      <details class="nfe-section">
        <summary><span>Campos de uso livre</span><small>Informações internas do documento</small></summary>
        <div class="nfe-section-body">
          <label class="field"><span>Observação interna</span><textarea rows="3" data-nfe-custom="internal_note">{{ data_get($existingCustomFields,'internal_note') }}</textarea></label>
        </div>
      </details>

      <details class="nfe-section" open>
        <summary><span>Outras informações</span><small>Informações complementares e para o Fisco</small></summary>
        <div class="nfe-section-body">
          <div class="editor-grid cols-12">
            <label class="field col-6"><span>Informações complementares</span><textarea name="additional_info" rows="4">{{ old('additional_info',$draft->additional_info) }}</textarea></label>
            <label class="field col-6"><span>Informações para o Fisco</span><textarea name="tax_authority_info" rows="4">{{ old('tax_authority_info',$draft->tax_authority_info) }}</textarea></label>
          </div>
        </div>
      </details>
    </div>

    <footer class="nfe-editor-footer">
      <div class="nfe-editor-status">
        @if($draft->validated_at)
          <span class="status status-ok">Validado em {{ $draft->validated_at->format('d/m/Y H:i') }}</span>
        @else
          <span class="status status-muted">Rascunho</span>
        @endif
        <span class="nfe-footer-total" id="nfeFooterTotal">R$ 0,00</span>
      </div>

      <div class="nfe-editor-actions">
        <a class="btn btn-secondary" href="{{ route('fiscal.index',['tab'=>'nfe']) }}">Cancelar <kbd data-tutorial>Esc</kbd></a>
        <button class="btn btn-primary" type="submit">Apenas salvar <kbd data-tutorial>Ctrl+S</kbd></button>
        @if($isEditing)
          <button class="btn btn-secondary" type="submit" form="nfeValidateForm">Validar NF-e <kbd data-tutorial>F8</kbd></button>
        @else
          <button class="btn btn-secondary" type="button" disabled data-tooltip="Salve o rascunho antes de validar">Validar NF-e</button>
        @endif
        <button class="btn btn-success" type="button" disabled data-tooltip="Disponível após a integração ACBr">Emitir NF-e</button>
      </div>
    </footer>
  </form>
</dialog>

@if($isEditing)
<form id="nfeValidateForm" method="post" action="{{ route('fiscal.nfe.validate',$draft) }}">@csrf</form>
@endif

<dialog class="erp-dialog nfe-item-dialog" id="nfeItemDialog">
  <div class="dialog-header"><div><h2>Produto da NF-e</h2><p>Dados comerciais e tributários do item.</p></div><button type="button" data-dialog-close class="close-dialog">@include('partials.icon',['name'=>'x'])</button></div>
  <div class="dialog-body nfe-item-dialog-body">
    <input type="hidden" id="nfeItemIndex">

    <section class="editor-panel">
      <h3>Dados gerais</h3>
      <div class="editor-grid cols-12">
        <label class="field col-6"><span>Produto *</span>
          <select id="nfeItemProduct"><option value="">Selecione</option>@foreach($products as $product)<option value="{{ $product->id }}">{{ $product->name }} — {{ $product->sku }}</option>@endforeach</select>
        </label>
        <label class="field col-2"><span>Código próprio</span><input id="nfeItemSku" readonly></label>
        <label class="field col-2"><span>Quantidade *</span><input id="nfeItemQuantity" type="number" step="0.0001" min="0.0001" value="1"></label>
        <label class="field col-2"><span>Preço unit. *</span><input id="nfeItemUnitPrice" type="number" step="0.0001" min="0"></label>

        <label class="field col-3"><span>CFOP</span><input id="nfeItemCfop" maxlength="10"></label>
        <label class="field col-3"><span>Frete</span><input id="nfeItemFreight" type="number" step="0.01" min="0" value="0"></label>
        <label class="field col-2"><span>Seguro</span><input id="nfeItemInsurance" type="number" step="0.01" min="0" value="0"></label>
        <label class="field col-2"><span>Despesas</span><input id="nfeItemOther" type="number" step="0.01" min="0" value="0"></label>
        <label class="field col-2"><span>Desconto</span><input id="nfeItemDiscount" type="number" step="0.01" min="0" value="0"></label>

        <label class="field col-4"><span>Origem</span><input id="nfeItemOrigin" maxlength="2"></label>
        <label class="field col-4"><span>EAN/GTIN</span><input id="nfeItemGtin" maxlength="32"></label>
        <label class="field col-2"><span>Unidade</span><input id="nfeItemUnit" maxlength="12"></label>
        <label class="field col-2"><span>Unid. tributável</span><input id="nfeItemTaxUnit" maxlength="12"></label>

        <label class="field col-3"><span>NCM</span><input id="nfeItemNcm" maxlength="10"></label>
        <label class="field col-3"><span>CEST</span><input id="nfeItemCest" maxlength="10"></label>
        <label class="field col-3"><span>Exceção IPI</span><input id="nfeItemIpiException"></label>
        <label class="field col-3"><span>Benefício fiscal</span><input id="nfeItemBenefit"></label>

        <label class="field col-3"><span>Pedido de compra</span><input id="nfeItemPurchaseOrder"></label>
        <label class="field col-3"><span>Item do pedido</span><input id="nfeItemPurchaseOrderItem"></label>
        <label class="field col-6"><span>Observações do item</span><input id="nfeItemNotes"></label>
      </div>
    </section>

    <section class="editor-panel nfe-tax-panel">
      <div class="nfe-tax-title"><h3>Dados tributários</h3><span>Pré-carregados do produto; ajuste somente quando necessário.</span></div>
      <div class="nfe-tax-summary">
        <div><span>IPI</span><strong id="nfeTaxIpiSummary">—</strong></div>
        <div><span>ICMS</span><strong id="nfeTaxIcmsSummary">—</strong></div>
        <div><span>PIS</span><strong id="nfeTaxPisSummary">—</strong></div>
        <div><span>COFINS</span><strong id="nfeTaxCofinsSummary">—</strong></div>
        <div><span>IBS/CBS</span><strong id="nfeTaxIbsCbsSummary">—</strong></div>
      </div>

      <details class="nfe-item-detail" open>
        <summary>ICMS</summary>
        <div class="editor-grid cols-12">
          <label class="field col-3"><span>CSOSN</span><input id="nfeTaxCsosn"></label>
          <label class="field col-3"><span>CST ICMS</span><input id="nfeTaxIcmsCst"></label>
          <label class="field col-2"><span>Alíquota ICMS %</span><input id="nfeTaxIcmsRate" type="number" step="0.0001"></label>
          <label class="field col-2"><span>Crédito ICMS %</span><input id="nfeTaxIcmsCreditRate" type="number" step="0.0001"></label>
          <label class="field col-2"><span>FCP %</span><input id="nfeTaxFcpRate" type="number" step="0.0001"></label>
        </div>
      </details>

      <details class="nfe-item-detail">
        <summary>IPI / PIS / COFINS</summary>
        <div class="editor-grid cols-12">
          <label class="field col-2"><span>CST IPI</span><input id="nfeTaxIpiCst"></label>
          <label class="field col-2"><span>IPI %</span><input id="nfeTaxIpiRate" type="number" step="0.0001"></label>
          <label class="field col-2"><span>CST PIS</span><input id="nfeTaxPisCst"></label>
          <label class="field col-2"><span>PIS %</span><input id="nfeTaxPisRate" type="number" step="0.0001"></label>
          <label class="field col-2"><span>CST COFINS</span><input id="nfeTaxCofinsCst"></label>
          <label class="field col-2"><span>COFINS %</span><input id="nfeTaxCofinsRate" type="number" step="0.0001"></label>
        </div>
      </details>

      <details class="nfe-item-detail">
        <summary>IBS / CBS</summary>
        <div class="editor-grid cols-12">
          <label class="field col-3"><span>CST IBS</span><input id="nfeTaxIbsCst"></label>
          <label class="field col-3"><span>CST CBS</span><input id="nfeTaxCbsCst"></label>
          <label class="field col-6"><span>Classificação tributária</span><input id="nfeTaxClassification"></label>
        </div>
      </details>

      <details class="nfe-item-detail"><summary>Dados de controle de ST</summary><textarea id="nfeSpecialSt" rows="3" placeholder="Dados específicos de ST para esta operação"></textarea></details>
      <details class="nfe-item-detail"><summary>Dados de importação</summary><textarea id="nfeSpecialImport" rows="3" placeholder="DI, adições e dados de importação"></textarea></details>
      <details class="nfe-item-detail"><summary>Dados de exportação / drawback</summary><textarea id="nfeSpecialExport" rows="3"></textarea></details>
      <details class="nfe-item-detail"><summary>Produtos específicos</summary><textarea id="nfeSpecialProduct" rows="3" placeholder="Combustível, medicamento, veículo ou outros dados específicos"></textarea></details>
      <details class="nfe-item-detail"><summary>Rastreabilidade</summary><textarea id="nfeSpecialTrace" rows="3"></textarea></details>
      <details class="nfe-item-detail"><summary>Outros dados</summary><textarea id="nfeSpecialOther" rows="3"></textarea></details>
    </section>
  </div>
  <div class="dialog-footer">
    <button type="button" data-dialog-close class="btn btn-secondary">Cancelar</button>
    <button type="button" class="btn btn-success" id="nfeSaveItem">Salvar item</button>
  </div>
</dialog>

<dialog class="erp-dialog nfe-small-dialog" id="nfeReferenceDialog">
  <div class="dialog-header"><div><h2>Documento referenciado</h2><p>Adicione uma chave relacionada à operação.</p></div><button type="button" data-dialog-close class="close-dialog">@include('partials.icon',['name'=>'x'])</button></div>
  <div class="dialog-body"><div class="editor-grid cols-12">
    <label class="field col-4"><span>Tipo</span><select id="nfeReferenceType"><option value="nfe">NF-e</option><option value="nfce">NFC-e</option><option value="other">Outro documento</option></select></label>
    <label class="field col-8"><span>Chave / identificação</span><input id="nfeReferenceKey"></label>
  </div></div>
  <div class="dialog-footer"><button type="button" data-dialog-close class="btn btn-secondary">Cancelar</button><button type="button" class="btn btn-success" id="nfeSaveReference">Adicionar</button></div>
</dialog>

<dialog class="erp-dialog nfe-small-dialog" id="nfeDuplicateDialog">
  <div class="dialog-header"><div><h2>Nova duplicata</h2><p>Vencimento e valor da cobrança.</p></div><button type="button" data-dialog-close class="close-dialog">@include('partials.icon',['name'=>'x'])</button></div>
  <div class="dialog-body"><div class="editor-grid cols-12">
    <label class="field col-3"><span>Número</span><input id="nfeDuplicateNumber"></label>
    <label class="field col-5"><span>Vencimento</span><input id="nfeDuplicateDue" type="date"></label>
    <label class="field col-4"><span>Valor</span><input id="nfeDuplicateValue" type="number" min="0" step="0.01"></label>
  </div></div>
  <div class="dialog-footer"><button type="button" data-dialog-close class="btn btn-secondary">Cancelar</button><button type="button" class="btn btn-success" id="nfeSaveDuplicate">Adicionar</button></div>
</dialog>

<dialog class="erp-dialog nfe-small-dialog" id="nfePaymentDialog">
  <div class="dialog-header"><div><h2>Informação de pagamento</h2><p>Forma e valor informado no documento.</p></div><button type="button" data-dialog-close class="close-dialog">@include('partials.icon',['name'=>'x'])</button></div>
  <div class="dialog-body"><div class="editor-grid cols-12">
    <label class="field col-6"><span>Forma de pagamento</span><select id="nfePaymentMethod">@foreach($paymentMethods as $method)<option value="{{ $method->code }}">{{ $method->name }}</option>@endforeach</select></label>
    <label class="field col-3"><span>Vencimento</span><input id="nfePaymentDue" type="date"></label>
    <label class="field col-3"><span>Valor</span><input id="nfePaymentValue" type="number" min="0" step="0.01"></label>
    <label class="field col-4"><span>Tipo de integração</span><input id="nfePaymentIntegration" placeholder="Opcional"></label>
    <label class="field col-4"><span>Bandeira</span><input id="nfePaymentBrand" placeholder="Opcional"></label>
    <label class="field col-4"><span>Autorização</span><input id="nfePaymentAuthorization" placeholder="Opcional"></label>
  </div></div>
  <div class="dialog-footer"><button type="button" data-dialog-close class="btn btn-secondary">Cancelar</button><button type="button" class="btn btn-success" id="nfeSavePayment">Adicionar</button></div>
</dialog>

<script type="application/json" id="nfe-products-data">@json($products->map(fn($p)=>[
  'id'=>$p->id,'name'=>$p->name,'sku'=>$p->sku,'sale_price'=>(float)$p->sale_price,
  'origin'=>$p->origin,'ean_gtin'=>$p->ean_gtin,'unit'=>$p->unit,'tax_unit'=>$p->tax_unit,
  'ncm'=>$p->ncm,'cest'=>$p->cest,'ipi_exception'=>$p->ipi_exception,
  'fiscal_benefit_code'=>$p->fiscal_benefit_code,'nfe_notes'=>$p->nfe_notes,
  'tax_defaults'=>$p->tax_defaults ?? [],
])->values())</script>
<script type="application/json" id="nfe-customers-data">@json($customers->map(fn($c)=>[
  'id'=>$c->id,'name'=>$c->name,'document'=>$c->document,'state_registration'=>$c->state_registration,
  'ie_indicator'=>$c->ie_indicator,'final_consumer'=>(bool)$c->final_consumer,'zip_code'=>$c->zip_code,
  'state'=>$c->state,'city'=>$c->city,'address'=>$c->address,'address_number'=>$c->address_number,
  'district'=>$c->district,'delivery_addresses'=>$c->deliveryAddresses->map(fn($a)=>[
    'id'=>$a->id,'name'=>$a->name,'document'=>$a->document,'state'=>$a->state,'city'=>$a->city,
    'address'=>$a->address,'address_number'=>$a->address_number,'district'=>$a->district,
  ])->values(),
])->values())</script>
<script type="application/json" id="nfe-natures-data">@json($natures->map(fn($n)=>[
  'id'=>$n->id,'operation_type'=>$n->operation_type,'purpose'=>$n->purpose,
  'cfop_internal'=>$n->cfop_internal,'cfop_interstate'=>$n->cfop_interstate,'cfop_foreign'=>$n->cfop_foreign,
  'override_product_cfop'=>(bool)$n->override_product_cfop,'final_consumer_default'=>(bool)$n->final_consumer_default,
  'presence_default'=>$n->presence_default,'additional_info'=>$n->additional_info,'tax_authority_info'=>$n->tax_authority_info,
])->values())</script>
<script type="application/json" id="nfe-emitter-state">@json($company->state)</script>
@endsection

@push('scripts')
<script src="{{ asset('js/nfe-editor.js') }}"></script>
<script>
document.addEventListener('DOMContentLoaded',()=>document.getElementById('nfeEditorDialog')?.showModal());
</script>
@endpush
