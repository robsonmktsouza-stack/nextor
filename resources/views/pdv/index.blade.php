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
        <select name="customer_id" id="pdvCustomer">
          <option value="">Consumidor não identificado</option>
          @foreach($customers as $customer)
            <option value="{{ $customer->id }}">{{ $customer->name }}{{ $customer->document ? ' — '.$customer->document : '' }}</option>
          @endforeach
        </select>
      </label>

      <label class="field">
        <span>Forma de pagamento <small>F6</small></span>
        <select name="payment_method" id="pdvPaymentMethod" required>
          <option value="cash">Dinheiro</option>
          <option value="pix">PIX</option>
          <option value="debit_card">Cartão de débito</option>
          <option value="credit_card">Cartão de crédito</option>
          <option value="bank_slip">Boleto</option>
          <option value="bank_transfer">Transferência</option>
          <option value="other">Outro</option>
        </select>
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
</form>
@endsection

@push('scripts')
<script defer src="{{ asset('js/pdv.js') }}"></script>
@endpush
