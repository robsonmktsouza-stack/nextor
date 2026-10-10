@extends('layouts.app')
@php
  $readOnly=isset($readOnlyDocument);
  $canCancelArchived=$readOnly
    && $readOnlyDocument->status==='authorized'
    && !$readOnlyDocument->cancellation_status
    && !$readOnlyDocument->cancelled_at
    && $readOnlyDocument->authorized_at
    && !$readOnlyDocument->authorized_at->isFuture()
    && $readOnlyDocument->authorized_at->copy()->addMinutes(30)->isFuture()
    && preg_match('/^\d{44}$/',(string)$readOnlyDocument->access_key)===1
    && preg_match('/^\d{15}$/',(string)$readOnlyDocument->protocol)===1
    && ($readOnlyDocument->environment!=='production'
      || (bool)\App\Models\AppSetting::value('nfce','advanced_operations_production_approved',false));
@endphp
@section('title','Fiscal')
@section('titleMeta',$readOnly?'NFC-e — somente leitura':'Nova NFC-e')
@section('content')
@include('fiscal._nav',['tab'=>'nfce','tabs'=>[
    'nfe'=>['label'=>'NF-e'],'nfse'=>['label'=>'NFS-e'],
    'cte'=>['label'=>'CT-e'],'mdfe'=>['label'=>'MDF-e'],'nfce'=>['label'=>'NFC-e'],
]])

<div class="nextor-modal-layer nfe-modal-layer is-open {{ $readOnly?'nfce-readonly':'' }}" id="nfceEditor" aria-hidden="false">
  <div class="erp-dialog nfe-workspace-dialog nextor-modal-window nfe-fixed-shell" role="dialog" aria-modal="true" aria-labelledby="nfceEditorTitle">
    <form method="{{ $readOnly?'get':'post' }}" action="{{ $readOnly?'#':route('fiscal.nfce.store') }}" id="nfceEditorForm" data-readonly="{{ $readOnly?'1':'0' }}" autocomplete="off" novalidate>
      @csrf
      <div class="dialog-header">
        <div><h2 id="nfceEditorTitle">{{ $readOnly?'NFC-e '.$readOnlyDocument->series.'/'.$readOnlyDocument->document_number:'Nova NFC-e' }}</h2>
          @if($readOnly)<p class="nfce-readonly-caption">Documento {{ $readOnlyDocument->status==='cancelled'?'cancelado':'autorizado' }} · campos bloqueados para alteração</p>@endif</div>
        <a class="close-dialog" href="{{ route('fiscal.index',['tab'=>'nfce']) }}" aria-label="Fechar">@include('partials.icon',['name'=>'x','size'=>18])</a>
      </div>

      <div class="dialog-body nfe-workspace-body">
        @if($errors->any())
          <div class="alert alert-danger" role="alert">
            {{ $errors->first() }}
          </div>
        @endif

        <div id="nfceFormError" class="nfce-form-error" role="alert" hidden></div>

        <div class="nfe-emitter-summary">
          <div class="nfe-emitter-logo">
            @if($company->logo_path)<img src="{{ route('company.logo') }}" alt="">@endif
          </div>
          <div class="nfe-emitter-identity">
            <div class="nfe-emitter-name">
              <strong>{{ $company->legal_name ?: 'Empresa não configurada' }}</strong>
              @if($company->trade_name)<span>{{ $company->trade_name }}</span>@endif
            </div>
            <div class="nfe-emitter-address">
              {{ collect([$company->address,$company->address_number,$company->district,$company->city,$company->state])->filter()->implode(' · ') }}
            </div>
            <div class="nfe-emitter-docs">
              <span><b>CNPJ:</b> {{ $company->document ?: '—' }}</span>
              <span><b>IE:</b> {{ $company->state_registration ?: '—' }}</span>
              <span><b>CRT:</b> {{ $company->crt ?: '—' }}</span>
            </div>
          </div>
          <div class="nfe-document-summary">
            <span>Número da NFC-e</span>
            <strong>{{ $readOnly?str_pad((string)$readOnlyDocument->document_number,9,'0',STR_PAD_LEFT):'Automático' }}</strong>
            <div>
              <span>Série <b>{{ str_pad((string)($readOnly?$readOnlyDocument->series:\App\Models\AppSetting::value('nfce','series',1)),3,'0',STR_PAD_LEFT) }}</b></span>
              <span>Ambiente <b>{{ ($readOnly?$readOnlyDocument->environment:$settings->environment('nfce'))==='production' ? 'Produção' : 'Homologação' }}</b></span>
            </div>
          </div>
        </div>

        @if($readOnly)
          <div class="nfce-readonly-fiscal-bar">
            <span class="status {{ $readOnlyDocument->status==='authorized'?'status-ok':'status-muted' }}">{{ $readOnlyDocument->status==='authorized'?'Autorizada':'Cancelada' }}</span>
            <span><b>Chave:</b> <span class="nfce-readonly-key">{{ $readOnlyDocument->access_key ?: '—' }}</span></span>
            <span><b>Protocolo:</b> {{ $readOnlyDocument->protocol ?: '—' }}</span>
            @if($readOnlyDocument->authorized_at)<span><b>Autorizada em:</b> {{ $readOnlyDocument->authorized_at->format('d/m/Y H:i:s') }}</span>@endif
            @if($readOnlyDocument->emission_mode==='offline')<span><b>Emissão:</b> Contingência offline</span>@endif
            @if($readOnlyDocument->cancelled_at)<span><b>Cancelada em:</b> {{ $readOnlyDocument->cancelled_at->format('d/m/Y H:i:s') }}</span>@endif
            @if(!$xmlLoaded)<span>XML arquivado indisponível: informações comerciais do registro original.</span>@endif
          </div>
        @endif
        <div class="editor-tabs nfe-editor-tabs" role="tablist" aria-label="Seções da NFC-e" data-nfce-tabs>
          <button type="button" role="tab" id="nfceTab-general" aria-controls="nfcePanel-general" aria-selected="true" tabindex="0" class="editor-tab active" data-nfce-tab="general">Dados gerais</button>
          <button type="button" role="tab" id="nfceTab-consumer" aria-controls="nfcePanel-consumer" aria-selected="false" tabindex="-1" class="editor-tab" data-nfce-tab="consumer">Consumidor</button>
          <button type="button" role="tab" id="nfceTab-products" aria-controls="nfcePanel-products" aria-selected="false" tabindex="-1" class="editor-tab" data-nfce-tab="products">Produtos</button>
          <button type="button" role="tab" id="nfceTab-payment" aria-controls="nfcePanel-payment" aria-selected="false" tabindex="-1" class="editor-tab" data-nfce-tab="payment">Pagamento</button>
          <button type="button" role="tab" id="nfceTab-summary" aria-controls="nfcePanel-summary" aria-selected="false" tabindex="-1" class="editor-tab" data-nfce-tab="summary">Resumo</button>
        </div>

        <section class="editor-tab-panel active" id="nfcePanel-general" role="tabpanel" aria-labelledby="nfceTab-general" data-nfce-panel="general">
          <div class="nfe-general-card">
            <div class="nfe-section-title"><h3>Dados gerais</h3></div>
            <div class="editor-grid cols-12">
              <label class="field col-6"><span>Origem da NFC-e</span>
                <select name="mode" id="nfceMode">
                  <option value="new" @selected(old('mode','new')==='new') @disabled(!$canCreateSale)>{{ $readOnly?'Venda original':'Nova venda' }}</option>
                  <option value="existing" @selected(old('mode')==='existing' || !$canCreateSale)>Selecionar venda existente</option>
                </select>
              </label>
              <label class="field col-3"><span>Natureza da operação</span>
                <input value="{{ $readOnly?$frozenNature:'Venda de mercadoria' }}" readonly>
              </label>
              <label class="field col-3"><span>Data da emissão</span>
                <input value="{{ $readOnly?$frozenIssueDate:now()->format('d/m/Y') }}" readonly>
              </label>
              <label class="field col-6"><span>Presença do comprador</span>
                <select name="presence">
                  <option value="presential" selected>Operação presencial</option>
                </select>
              </label>
            </div>
            <div id="nfceExistingBlock" class="nfce-existing-block" hidden>
              <label class="field"><span>Venda concluída</span>
                <input type="search" id="nfceSaleSearch" placeholder="Pesquisar pelo número ou consumidor" aria-label="Pesquisar venda concluída">
              </label>
              <label class="field"><span>Selecionar venda</span>
                <select name="sale_id" id="nfceSale">
                  <option value="">Selecione uma venda</option>
                  @foreach($sales as $sale)
                    <option value="{{ $sale->id }}" data-total="{{ $sale->total }}" @selected((string)old('sale_id')===(string)$sale->id)>
                      Venda #{{ $sale->id }} — {{ $sale->customer?->name ?: 'Consumidor não identificado' }} — R$ {{ number_format((float)$sale->total,2,',','.') }}
                    </option>
                  @endforeach
                </select>
              </label>
              <p class="nfce-muted">A nota será vinculada à venda já concluída, sem registrar novamente estoque ou financeiro.</p>
            </div>
          </div>
        </section>

        <section class="editor-tab-panel" id="nfcePanel-consumer" role="tabpanel" aria-labelledby="nfceTab-consumer" data-nfce-panel="consumer" hidden>
          <div class="nfe-general-card">
            <div class="nfe-section-title"><h3>Consumidor</h3></div>
            <div class="editor-grid cols-12">
              <label class="field col-6"><span>Cliente cadastrado</span>
                <select name="customer_id" id="nfceCustomer">
                  <option value="">Consumidor não identificado</option>
                  @foreach($customers as $customer)
                    <option value="{{ $customer->id }}" data-document="{{ $customer->document }}" data-name="{{ $customer->name }}" @selected((string)old('customer_id')===(string)$customer->id)>
                      {{ $customer->name }}{{ $customer->document ? ' — '.$customer->document : '' }}
                    </option>
                  @endforeach
                </select>
              </label>
              <label class="field col-3"><span>CPF / CNPJ</span>
                <input name="consumer_document" id="nfceConsumerDocument" inputmode="numeric" maxlength="20" value="{{ $readOnly?$frozenConsumerDocument:old('consumer_document') }}" placeholder="Opcional">
              </label>
              <label class="field col-3"><span>Nome</span>
                <input name="consumer_name" id="nfceConsumerName" maxlength="190" value="{{ $readOnly?$frozenConsumerName:old('consumer_name') }}" placeholder="Opcional">
              </label>
            </div>
          </div>
        </section>

        <section class="editor-tab-panel" id="nfcePanel-products" role="tabpanel" aria-labelledby="nfceTab-products" data-nfce-panel="products" hidden>
          <div class="nfe-general-card">
            <div class="nfe-section-title">
              <h3>Produtos</h3>
              <button type="button" id="nfceAddProduct" class="btn btn-secondary">@include('partials.icon',['name'=>'plus','size'=>15]) Adicionar produto</button>
            </div>
            <label class="field"><span>Buscar produto</span>
              <input type="search" id="nfceProductSearch" placeholder="Digite o nome, código ou código de barras" autocomplete="off">
            </label>
            <div id="nfceProductResults" class="nfce-search-results" role="listbox" hidden></div>
            <div class="nfce-item-table-wrap">
              <table class="nfce-item-table">
                <thead><tr><th>Produto</th><th>Quantidade</th><th>Preço</th><th>Desconto</th><th>Total</th><th></th></tr></thead>
                <tbody id="nfceItems"></tbody>
              </table>
            </div>
            <p class="nfce-muted" id="nfceNoItems">Nenhum produto adicionado.</p>
          </div>
        </section>

        <section class="editor-tab-panel" id="nfcePanel-payment" role="tabpanel" aria-labelledby="nfceTab-payment" data-nfce-panel="payment" hidden>
          <div class="nfe-general-card">
            <div class="nfe-section-title">
              <h3>Pagamento</h3>
              <button type="button" id="nfceAddPayment" class="btn btn-secondary">@include('partials.icon',['name'=>'plus','size'=>15]) Adicionar pagamento</button>
            </div>
            <div class="nfce-item-table-wrap">
              <table class="nfce-item-table">
                <thead><tr><th>Forma de pagamento</th><th>Valor</th><th></th></tr></thead>
                <tbody id="nfcePayments"></tbody>
              </table>
            </div>
            <div class="nfce-payment-subtotal">
              <span>Total informado</span><strong id="nfcePaymentTotal">R$ 0,00</strong>
            </div>
          </div>
        </section>

        <section class="editor-tab-panel" id="nfcePanel-summary" role="tabpanel" aria-labelledby="nfceTab-summary" data-nfce-panel="summary" hidden>
          <div class="nfe-general-card">
            <div class="nfe-section-title"><h3>Resumo da NFC-e</h3></div>
            <div class="nfce-summary-grid">
              <div><span>Subtotal</span><strong id="nfceSubtotal">R$ 0,00</strong></div>
              <div><span>Desconto</span><strong id="nfceDiscountTotal">R$ 0,00</strong></div>
              <div><span>Total da NFC-e</span><strong id="nfceGrandTotal">R$ 0,00</strong></div>
            </div>
            <label class="field nfce-note-field"><span>Observações da venda</span>
              <textarea name="notes" rows="3" maxlength="2000">{{ $readOnly?$frozenNotes:old('notes') }}</textarea>
            </label>
          </div>
        </section>
      </div>

      <div class="editor-savebar settings-savebar nfce-savebar">
        <div class="nfce-save-total">
          <span>Total da NFC-e</span>
          <strong id="nfceFixedTotal">R$ 0,00</strong>
        </div>
        <div class="nfce-save-buttons">
          <a href="{{ route('fiscal.index',['tab'=>'nfce']) }}" class="btn btn-secondary">{{ $readOnly?'Voltar':'Cancelar' }}</a>
          @if($readOnly)
            @if($readOnlyDocument->xml_path)
              <a href="{{ route('fiscal.nfce.xml',$readOnlyDocument) }}" class="btn btn-secondary" data-no-loading download>
                @include('partials.icon',['name'=>'download','size'=>16]) Baixar XML
              </a>
            @endif
            @if($readOnlyDocument->status==='authorized')
              <a href="{{ route('fiscal.nfce.danfe',$readOnlyDocument) }}" target="_blank" rel="noopener noreferrer" class="btn btn-secondary">
                @include('partials.icon',['name'=>'print','size'=>16]) Imprimir DANFE
              </a>
            @endif
            @if($canCancelArchived)
              <button type="button" class="btn btn-danger" data-dialog-open="nfceCancelDialog">Cancelar NFC-e</button>
            @endif
          @else
            <button type="submit" name="submit_mode" value="save" class="btn btn-secondary">Salvar para emissão</button>
            <button type="submit" name="submit_mode" value="issue" class="btn btn-success" id="nfceIssue">@include('partials.icon',['name'=>'check','size'=>16]) Emitir NFC-e</button>
          @endif
        </div>
      </div>
    </form>
  </div>
</div>

@if($canCancelArchived)
<dialog class="erp-dialog small-dialog" id="nfceCancelDialog" aria-labelledby="nfceCancelTitle">
  <form method="post" action="{{ route('fiscal.nfce.cancel',$readOnlyDocument) }}">
    @csrf
    <div class="dialog-header">
      <div><h2 id="nfceCancelTitle">Cancelar NFC-e {{ $readOnlyDocument->series }}/{{ $readOnlyDocument->document_number }}</h2></div>
      <button type="button" class="close-dialog" data-dialog-close aria-label="Fechar">×</button>
    </div>
    <div class="dialog-body">
      <p>Solicitar o cancelamento à SEFAZ. A nota só será cancelada depois que o evento for autorizado.</p>
      <label class="field"><span>Justificativa</span><textarea name="reason" minlength="15" maxlength="255" rows="3" required></textarea></label>
      <label class="field nfce-cancel-no-circulation"><input type="checkbox" name="no_circulation" value="1" required><span>Confirmo que a mercadoria não circulou.</span></label>
    </div>
    <div class="dialog-footer">
      <button type="button" class="btn btn-secondary" data-dialog-close>Voltar</button>
      <button type="submit" class="btn btn-danger">Solicitar cancelamento</button>
    </div>
  </form>
</dialog>
@endif

<script type="application/json" id="nfceProductsJson">@json($products)</script>
<script type="application/json" id="nfceMethodsJson">@json($methods)</script>
<script type="application/json" id="nfceOldItems">@json($readOnly?$frozenItems:old('items',[]))</script>
<script type="application/json" id="nfceOldPayments">@json($readOnly?$frozenPayments:old('payments',[]))</script>
<script defer src="{{ asset('js/nfce-editor.js') }}?v={{ filemtime(public_path('js/nfce-editor.js')) }}"></script>
@endsection
