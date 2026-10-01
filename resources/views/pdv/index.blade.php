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

    @if($recentSales->isNotEmpty())
    <div class="pdv-recent">
      <div class="pdv-recent-head">
        <h3>Últimas vendas do PDV</h3>
        <a href="{{ route('sales.index') }}">Ver histórico</a>
      </div>
      <div class="pdv-recent-list">
        @foreach($recentSales as $recent)
          <a href="{{ route('sales.show',$recent) }}" class="pdv-recent-item">
            <span>#{{ str_pad((string)$recent->id,5,'0',STR_PAD_LEFT) }}</span>
            <span>{{ $recent->customer?->name ?? 'Consumidor não identificado' }}</span>
            <strong>R$ {{ number_format((float)$recent->total,2,',','.') }}</strong>
          </a>
        @endforeach
      </div>
    </div>
    @endif
  </section>

  <aside class="pdv-checkout">
    <div class="pdv-checkout-head">
      <div>
        <span class="pdv-eyebrow">VENDA ATUAL</span>
        <h2>Carrinho</h2>
      </div>
      <span class="pdv-item-count" id="pdvItemCount">0 itens</span>
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

    <div class="pdv-checkout-fields">
      <label class="field">
        <span>Cliente <small>F4</small></span>
        <input type="hidden" name="customer_id" id="pdvCustomer" value="">
        <button type="button" class="pdv-picker-button" id="pdvCustomerButton">
          <span id="pdvCustomerLabel">Consumidor não identificado</span>
          <kbd>F4</kbd>
        </button>
      </label>

      <label class="field">
        <span>Forma de pagamento <small>F6</small></span>
        <input type="hidden" name="payment_method" id="pdvPaymentMethod" value="cash">
        <button type="button" class="pdv-picker-button" id="pdvPaymentButton">
          <span id="pdvPaymentLabel">Dinheiro</span>
          <kbd>F6</kbd>
        </button>
      </label>

      <label class="field pdv-cash-field" id="pdvCashField">
        <span>Valor recebido <small>F7</small></span>
        <input type="number" name="cash_received" id="pdvCashReceived"
               min="0" step="0.01" value="0" data-number-kind="money">
      </label>

      <div class="pdv-change" id="pdvChangeBox">
        <span>Troco</span>
        <strong id="pdvChange">R$ 0,00</strong>
      </div>

      <label class="field pdv-notes-field">
        <span>Observação</span>
        <input name="notes" maxlength="2000" placeholder="Opcional">
      </label>
    </div>

    <div id="pdvItemsPayload"></div>

    <button type="submit" class="pdv-finish" id="pdvFinish" disabled>
      <span>@include('partials.icon',['name'=>'check','size'=>19]) Finalizar venda</span>
      <kbd>F9</kbd>
    </button>

    <div class="pdv-shortcuts">
      <span><kbd>F2</kbd> Buscar</span>
      <span><kbd>F3</kbd> Quantidade</span>
      <span><kbd>F4</kbd> Cliente</span>
      <span><kbd>F5</kbd> Desconto</span>
      <span><kbd>F6</kbd> Pagamento</span>
      <span><kbd>F7</kbd> Recebido</span>
      <span><kbd>F8</kbd> Remover</span>
      <span><kbd>F9</kbd> Finalizar</span>
    </div>
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
    <button type="button" data-payment-value="cash" data-payment-label="Dinheiro" data-payment-key="1"><kbd>1</kbd><span>Dinheiro</span></button>
    <button type="button" data-payment-value="pix" data-payment-label="PIX" data-payment-key="2"><kbd>2</kbd><span>PIX</span></button>
    <button type="button" data-payment-value="debit_card" data-payment-label="Cartão de débito" data-payment-key="3"><kbd>3</kbd><span>Cartão de débito</span></button>
    <button type="button" data-payment-value="credit_card" data-payment-label="Cartão de crédito" data-payment-key="4"><kbd>4</kbd><span>Cartão de crédito</span></button>
    <button type="button" data-payment-value="bank_slip" data-payment-label="Boleto" data-payment-key="5"><kbd>5</kbd><span>Boleto</span></button>
    <button type="button" data-payment-value="bank_transfer" data-payment-label="Transferência" data-payment-key="6"><kbd>6</kbd><span>Transferência</span></button>
    <button type="button" data-payment-value="other" data-payment-label="Outro" data-payment-key="7"><kbd>7</kbd><span>Outro</span></button>
  </div>
  <div class="pdv-modal-help">
    <span><kbd>1–7</kbd> Selecionar</span>
    <span><kbd>↑</kbd><kbd>↓</kbd> Navegar</span>
    <span><kbd>Enter</kbd> Confirmar</span>
    <span><kbd>Esc</kbd> Fechar</span>
  </div>
</dialog>
</form>
@endsection

@push('scripts')
<script defer src="{{ asset('js/pdv.js') }}"></script>
@endpush
