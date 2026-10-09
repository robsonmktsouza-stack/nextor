@extends('layouts.app')
@section('title','Fiscal')
@section('titleMeta','Nova NFC-e')
@section('content')
@include('fiscal._nav',['tab'=>'nfce','tabs'=>[
    'nfe'=>['label'=>'NF-e'],'nfse'=>['label'=>'NFS-e'],
    'cte'=>['label'=>'CT-e'],'mdfe'=>['label'=>'MDF-e'],'nfce'=>['label'=>'NFC-e'],
]])

<div class="nextor-modal-layer nfe-modal-layer is-open" id="nfceEditor" aria-hidden="false">
  <div class="erp-dialog nfe-workspace-dialog nextor-modal-window nfe-fixed-shell" role="dialog" aria-modal="true" aria-labelledby="nfceEditorTitle">
    <form method="post" action="{{ route('fiscal.nfce.store') }}" id="nfceEditorForm" autocomplete="off" novalidate>
      @csrf
      <div class="dialog-header">
        <div><h2 id="nfceEditorTitle">Nova NFC-e</h2></div>
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
            <strong>Automático</strong>
            <div>
              <span>Série <b>{{ str_pad((string)\App\Models\AppSetting::value('nfce','series',1),3,'0',STR_PAD_LEFT) }}</b></span>
              <span>Ambiente <b>{{ $settings->environment('nfce')==='production' ? 'Produção' : 'Homologação' }}</b></span>
            </div>
          </div>
        </div>

        <div class="editor-tabs nfe-editor-tabs" data-nfce-tabs>
          <button type="button" class="editor-tab active" data-nfce-tab="general">Dados gerais</button>
          <button type="button" class="editor-tab" data-nfce-tab="consumer">Consumidor</button>
          <button type="button" class="editor-tab" data-nfce-tab="products">Produtos</button>
          <button type="button" class="editor-tab" data-nfce-tab="payment">Pagamento</button>
          <button type="button" class="editor-tab" data-nfce-tab="summary">Resumo</button>
        </div>

        <section class="editor-tab-panel active" data-nfce-panel="general">
          <div class="nfe-general-card">
            <div class="nfe-section-title"><h3>Dados gerais</h3></div>
            <div class="editor-grid cols-12">
              <label class="field col-6"><span>Origem da NFC-e</span>
                <select name="mode" id="nfceMode">
                  <option value="new" @selected(old('mode','new')==='new') @disabled(!$canCreateSale)>Nova venda</option>
                  <option value="existing" @selected(old('mode')==='existing' || !$canCreateSale)>Selecionar venda existente</option>
                </select>
              </label>
              <label class="field col-3"><span>Natureza da operação</span>
                <input value="Venda de mercadoria" readonly>
              </label>
              <label class="field col-3"><span>Data da emissão</span>
                <input value="{{ now()->format('d/m/Y') }}" readonly>
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

        <section class="editor-tab-panel" data-nfce-panel="consumer">
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
                <input name="consumer_document" id="nfceConsumerDocument" inputmode="numeric" maxlength="20" value="{{ old('consumer_document') }}" placeholder="Opcional">
              </label>
              <label class="field col-3"><span>Nome</span>
                <input name="consumer_name" id="nfceConsumerName" maxlength="190" value="{{ old('consumer_name') }}" placeholder="Opcional">
              </label>
            </div>
          </div>
        </section>

        <section class="editor-tab-panel" data-nfce-panel="products">
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

        <section class="editor-tab-panel" data-nfce-panel="payment">
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

        <section class="editor-tab-panel" data-nfce-panel="summary">
          <div class="nfe-general-card">
            <div class="nfe-section-title"><h3>Resumo da NFC-e</h3></div>
            <div class="nfce-summary-grid">
              <div><span>Subtotal</span><strong id="nfceSubtotal">R$ 0,00</strong></div>
              <div><span>Desconto</span><strong id="nfceDiscountTotal">R$ 0,00</strong></div>
              <div><span>Total da NFC-e</span><strong id="nfceGrandTotal">R$ 0,00</strong></div>
            </div>
            <label class="field nfce-note-field"><span>Observações da venda</span>
              <textarea name="notes" rows="3" maxlength="2000">{{ old('notes') }}</textarea>
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
          <a href="{{ route('fiscal.index',['tab'=>'nfce']) }}" class="btn btn-secondary">Cancelar</a>
          <button type="submit" name="submit_mode" value="save" class="btn btn-secondary">Salvar para emissão</button>
          <button type="submit" name="submit_mode" value="issue" class="btn btn-success" id="nfceIssue">@include('partials.icon',['name'=>'check','size'=>16]) Emitir NFC-e</button>
        </div>
      </div>
    </form>
  </div>
</div>

<script type="application/json" id="nfceProductsJson">@json($products)</script>
<script type="application/json" id="nfceMethodsJson">@json($methods)</script>
<script type="application/json" id="nfceOldItems">@json(old('items',[]))</script>
<script type="application/json" id="nfceOldPayments">@json(old('payments',[]))</script>
<script defer src="{{ asset('js/nfce-editor.js') }}"></script>
@endsection
