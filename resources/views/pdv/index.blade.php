@extends('layouts.pdv')

@section('content')
<form id="pdvForm" method="post" action="{{ route('pdv.store') }}">
@csrf

@if(session('pdv_last_sale'))
<div class="pdv-last-sale">
  <div>
    @include('partials.icon',['name'=>'check','size'=>18])
    <span>Venda <strong>#{{ str_pad((string)session('pdv_last_sale'),5,'0',STR_PAD_LEFT) }}</strong> finalizada.</span>
    @if((float)session('pdv_change',0)>0)
      <span>Troco: <strong>R$ {{ number_format((float)session('pdv_change'),2,',','.') }}</strong></span>
    @endif
  </div>
  <a href="{{ route('sales.show',session('pdv_last_sale')) }}">Abrir venda</a>
</div>
@endif

<div class="pdv-workspace" id="pdvApp" data-search-url="{{ route('pdv.search') }}">
  <section class="pdv-catalog">
    <div class="pdv-search-panel">
      <div class="pdv-search-box">
        <span class="pdv-search-icon">@include('partials.icon',['name'=>'barcode','size'=>22])</span>
        <input id="pdvSearch" type="search" autocomplete="off"
               placeholder="Código, código de barras, produto ou serviço..." aria-label="Buscar item no PDV">
        <kbd>F2</kbd>
      </div>

      <div class="pdv-search-tools">
        <div class="pdv-kind-tabs" role="group" aria-label="Tipo de item">
          <button type="button" class="active" data-pdv-kind="all">Todos</button>
          <button type="button" data-pdv-kind="product">@include('partials.icon',['name'=>'products','size'=>15]) Produtos</button>
          <button type="button" data-pdv-kind="service">@include('partials.icon',['name'=>'services','size'=>15]) Serviços</button>
        </div>
        <div class="pdv-search-keyboard">
          <span><kbd>↑</kbd><kbd>↓</kbd> Navegar</span>
          <span><kbd>Enter</kbd> Adicionar</span>
        </div>
      </div>
    </div>

    <div class="pdv-results-head">
      <div>
        <h2>Itens</h2>
        <p id="pdvResultsCaption">Digite para localizar um produto ou serviço.</p>
      </div>
      <span class="pdv-scan-status">@include('partials.icon',['name'=>'barcode','size'=>15]) Busca / leitor prontos</span>
    </div>

    <div class="pdv-results" id="pdvResults">
      <div class="pdv-empty-state" id="pdvSearchEmpty">
        @include('partials.icon',['name'=>'search','size'=>28])
        <strong>Localize um item para começar</strong>
        <span>Use nome, SKU, EAN/GTIN ou item de serviço. O leitor de código de barras também pode digitar aqui.</span>
      </div>
    </div>

    <section class="pdv-command-center" aria-label="Atalhos rápidos do caixa">
      <div class="pdv-command-grid">
        <button type="button" class="pdv-command-button" id="pdvActionSearch"
                data-tooltip="Buscar item — F2 / Alt+1" aria-label="Buscar item">
          <span class="pdv-command-icon">@include('partials.icon',['name'=>'search','size'=>25])</span>
          <kbd>F2</kbd>
        </button>

        <button type="button" class="pdv-command-button" id="pdvActionQuantity"
                data-tooltip="Alterar quantidade — F3 / Alt+2" aria-label="Alterar quantidade">
          <span class="pdv-command-icon">@include('partials.icon',['name'=>'stock','size'=>25])</span>
          <kbd>F3</kbd>
        </button>

        <button type="button" class="pdv-command-button" id="pdvActionCustomer"
                data-tooltip="Selecionar cliente — F4 / Alt+3" aria-label="Selecionar cliente">
          <span class="pdv-command-icon">@include('partials.icon',['name'=>'customers','size'=>25])</span>
          <kbd>F4</kbd>
        </button>

        <button type="button" class="pdv-command-button" id="pdvActionDiscount"
                data-tooltip="Aplicar desconto — F5 / Alt+4" aria-label="Aplicar desconto">
          <span class="pdv-command-icon">@include('partials.icon',['name'=>'tag','size'=>25])</span>
          <kbd>F5</kbd>
        </button>

        <button type="button" class="pdv-command-button" id="pdvActionPayment"
                data-tooltip="Forma de pagamento — F6 / Alt+5" aria-label="Forma de pagamento">
          <span class="pdv-command-icon">@include('partials.icon',['name'=>'receipt','size'=>25])</span>
          <kbd>F6</kbd>
        </button>

        <button type="button" class="pdv-command-button" id="pdvActionCash"
                data-tooltip="Valor recebido e troco — F7 / Alt+6" aria-label="Valor recebido e troco">
          <span class="pdv-command-icon">@include('partials.icon',['name'=>'money','size'=>25])</span>
          <kbd>F7</kbd>
        </button>

        <button type="button" class="pdv-command-button danger" id="pdvActionRemove"
                data-tooltip="Remover item selecionado — F8 / Alt+7" aria-label="Remover item selecionado">
          <span class="pdv-command-icon">@include('partials.icon',['name'=>'trash','size'=>25])</span>
          <kbd>F8</kbd>
        </button>

        <button type="button" class="pdv-command-button success" id="pdvActionFinish"
                data-tooltip="Finalizar venda — F9 / Alt+8" aria-label="Finalizar venda">
          <span class="pdv-command-icon">@include('partials.icon',['name'=>'check','size'=>25])</span>
          <kbd>F9</kbd>
        </button>

        <button type="button" class="pdv-command-button" id="pdvActionNotes"
                data-tooltip="Observação da venda — F10 / Alt+9" aria-label="Observação da venda">
          <span class="pdv-command-icon">@include('partials.icon',['name'=>'edit','size'=>25])</span>
          <kbd>F10</kbd>
        </button>
      </div>
    </section>
  </section>

  <aside class="pdv-checkout">
    <div class="pdv-checkout-head">
      <div>
        <span class="pdv-eyebrow">VENDA ATUAL</span>
        <h2>Carrinho</h2>
      </div>
      <div class="pdv-cart-head-actions">
        <span class="pdv-cart-nav-hint"><kbd>Alt</kbd> + <kbd>↑</kbd><kbd>↓</kbd> selecionar</span>
        <span class="pdv-item-count" id="pdvItemCount">0 itens</span>
      </div>
    </div>

    <div class="pdv-cart" id="pdvCart">
      <div class="pdv-cart-empty" id="pdvCartEmpty">
        @include('partials.icon',['name'=>'sales','size'=>26])
        <strong>Carrinho vazio</strong>
        <span>Adicione um produto ou serviço.</span>
      </div>
    </div>

    <div class="pdv-summary">
      <div><span>Subtotal</span><strong id="pdvSubtotal">R$ 0,00</strong></div>
      <div><span>Descontos</span><strong id="pdvDiscountTotal">R$ 0,00</strong></div>
      <div class="pdv-grand-total"><span>Total</span><strong id="pdvTotal">R$ 0,00</strong></div>
    </div>

    <input type="hidden" name="customer_id" id="pdvCustomer" value="">
    <input type="hidden" name="payment_method" id="pdvPaymentMethod" value="{{ $pdvSettings['default_payment_method'] }}">
    <input type="hidden" name="cash_received" id="pdvCashReceived" value="0.00">
    <input type="hidden" name="notes" id="pdvNotes" value="">

    <div id="pdvItemsPayload"></div>

    <button type="submit" class="pdv-finish" id="pdvFinish" disabled>
      <span>@include('partials.icon',['name'=>'check','size'=>19]) Finalizar venda</span>
      <kbd>F9</kbd>
    </button>


  </aside>
</div>

<dialog class="pdv-quick-modal pdv-client-modal" id="pdvCustomerModal">
  <div class="pdv-modal-head">
    <div>
      <span>F4</span>
      <h2>Selecionar cliente</h2>
      <p>Pesquise por nome ou documento.</p>
    </div>
    <button type="button" class="pdv-modal-close" data-pdv-modal-close aria-label="Fechar">@include('partials.icon',['name'=>'x','size'=>18])</button>
  </div>
  <div class="pdv-modal-search">
    @include('partials.icon',['name'=>'search','size'=>18])
    <input type="search" id="pdvCustomerSearch" autocomplete="off" placeholder="Digite o nome ou CPF/CNPJ...">
  </div>
  <div class="pdv-client-list" id="pdvCustomerList">
    <button type="button" class="pdv-client-option" data-customer-id="" data-customer-search="consumidor não identificado">
      <span class="pdv-client-avatar">CF</span>
      <span><strong>Consumidor não identificado</strong><small>Venda sem cliente vinculado</small></span>
    </button>
    @foreach($customers as $customer)
      <button type="button" class="pdv-client-option"
              data-customer-id="{{ $customer->id }}"
              data-customer-search="{{ mb_strtolower($customer->name.' '.$customer->document) }}">
        <span class="pdv-client-avatar">{{ strtoupper(substr($customer->name,0,2)) }}</span>
        <span>
          <strong>{{ $customer->name }}</strong>
          <small>{{ $customer->document ?: 'Sem documento informado' }}</small>
        </span>
      </button>
    @endforeach
  </div>
  <div class="pdv-modal-help">
    <span><kbd>↑</kbd><kbd>↓</kbd> Navegar</span>
    <span><kbd>Enter</kbd> Selecionar</span>
    <span><kbd>Esc</kbd> Fechar</span>
  </div>
</dialog>

<dialog class="pdv-quick-modal pdv-payment-modal" id="pdvPaymentModal">
  <div class="pdv-modal-head">
    <div>
      <span>F6</span>
      <h2>Forma de pagamento</h2>
      <p>Use o número da opção ou as setas e Enter.</p>
    </div>
    <button type="button" class="pdv-modal-close" data-pdv-modal-close aria-label="Fechar">@include('partials.icon',['name'=>'x','size'=>18])</button>
  </div>
  <div class="pdv-payment-options" id="pdvPaymentOptions">
    @foreach($paymentMethods as $method)
      <button type="button"
              data-payment-value="{{ $method->code }}"
              data-payment-label="{{ $method->name }}"
              data-payment-kind="{{ $method->kind }}"
              @if($loop->iteration<=9) data-payment-key="{{ $loop->iteration }}" @endif>
        @if($loop->iteration<=9)<kbd>{{ $loop->iteration }}</kbd>@endif
        <span>{{ $method->name }}</span>
      </button>
    @endforeach
  </div>
  <div class="pdv-modal-help">
    <span><kbd>1–9</kbd> Selecionar</span>
    <span><kbd>↑</kbd><kbd>↓</kbd> Navegar</span>
    <span><kbd>Enter</kbd> Confirmar</span>
    <span><kbd>Esc</kbd> Fechar</span>
  </div>
</dialog>

<dialog class="pdv-quick-modal pdv-discount-modal" id="pdvDiscountModal">
  <div class="pdv-modal-head">
    <div>
      <span>F5</span>
      <h2>Desconto do item</h2>
      <p id="pdvDiscountItemName">Item selecionado</p>
    </div>
    <button type="button" class="pdv-modal-close" data-pdv-modal-close aria-label="Fechar">@include('partials.icon',['name'=>'x','size'=>18])</button>
  </div>

  <div class="pdv-discount-modal-body">
    <div class="pdv-discount-summary">
      <div><span>Valor do item</span><strong id="pdvDiscountGross">R$ 0,00</strong></div>
      <div><span>Após desconto</span><strong id="pdvDiscountNet">R$ 0,00</strong></div>
    </div>

    <label>
      <span>Desconto em reais</span>
      <input type="text" inputmode="decimal" id="pdvDiscountModalInput" autocomplete="off" value="0,00">
    </label>

    <button type="button" class="pdv-modal-primary" id="pdvDiscountApply">
      @include('partials.icon',['name'=>'check','size'=>18]) Aplicar desconto
    </button>
  </div>

  <div class="pdv-modal-help">
    <span><kbd>Enter</kbd> Aplicar</span>
    <span><kbd>Esc</kbd> Fechar</span>
  </div>
</dialog>

<dialog class="pdv-quick-modal pdv-cash-modal" id="pdvCashModal">
  <div class="pdv-modal-head">
    <div>
      <span>F7</span>
      <h2>Valor recebido</h2>
      <p>Informe quanto o cliente entregou em dinheiro.</p>
    </div>
    <button type="button" class="pdv-modal-close" data-pdv-modal-close aria-label="Fechar">@include('partials.icon',['name'=>'x','size'=>18])</button>
  </div>

  <div class="pdv-cash-modal-body">
    <div class="pdv-cash-total">
      <span>Total da venda</span>
      <strong id="pdvCashModalTotal">R$ 0,00</strong>
    </div>

    <label>
      <span>Valor recebido</span>
      <input type="text" inputmode="decimal" id="pdvCashModalInput" autocomplete="off" value="0,00">
    </label>

    <button type="button" class="pdv-cash-exact" id="pdvCashExact">
      Usar valor exato da venda
    </button>

    <div class="pdv-cash-change">
      <span>Troco</span>
      <strong id="pdvCashModalChange">R$ 0,00</strong>
    </div>

    <button type="button" class="pdv-modal-primary" id="pdvCashApply">
      @include('partials.icon',['name'=>'check','size'=>18]) Aplicar valor
    </button>
  </div>

  <div class="pdv-modal-help">
    <span><kbd>Enter</kbd> Aplicar</span>
    <span><kbd>Esc</kbd> Fechar</span>
  </div>
</dialog>

<dialog class="pdv-quick-modal pdv-notes-modal" id="pdvNotesModal">
  <div class="pdv-modal-head">
    <div>
      <span>F10</span>
      <h2>Observação da venda</h2>
      <p>Anotação opcional vinculada à operação atual.</p>
    </div>
    <button type="button" class="pdv-modal-close" data-pdv-modal-close aria-label="Fechar">@include('partials.icon',['name'=>'x','size'=>18])</button>
  </div>

  <div class="pdv-notes-modal-body">
    <textarea id="pdvNotesModalInput" rows="6" maxlength="2000" placeholder="Digite a observação..."></textarea>
    <button type="button" class="pdv-modal-primary" id="pdvNotesApply">
      @include('partials.icon',['name'=>'check','size'=>18]) Salvar observação
    </button>
  </div>

  <div class="pdv-modal-help">
    <span><kbd>Ctrl</kbd> + <kbd>Enter</kbd> Salvar</span>
    <span><kbd>Esc</kbd> Fechar</span>
  </div>
</dialog>
</form>
@endsection

@push('scripts')
<script defer src="{{ asset('js/pdv.js') }}"></script>
@endpush
