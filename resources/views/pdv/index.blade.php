@extends('layouts.pdv')

@section('content')
@if($pdvSettings['require_cash_opening'])
<section class="pdv-cash-session-bar {{ $cashSession ? 'open' : 'closed' }}">
  <div>
    @include('partials.icon',['name'=>'money','size'=>17])
    @if($cashSession)
      <span>Caixa aberto às <strong>{{ $cashSession->opened_at->format('H:i') }}</strong> · Abertura <strong>R$ {{ number_format((float)$cashSession->opening_amount,2,',','.') }}</strong></span>
      @if($cashExpected!==null)<small>Saldo esperado em dinheiro: R$ {{ number_format((float)$cashExpected,2,',','.') }}</small>@endif
    @else
      <span><strong>Caixa fechado.</strong> Abra o caixa para poder finalizar vendas.</span>
    @endif
  </div>
  <div class="pdv-cash-session-actions">
    @if($cashSession && $pdvSettings['allow_cash_movements'])
      <button type="button" class="pdv-cash-session-action" data-pdv-cash-movement="supply">Suprimento <kbd>Alt+P</kbd></button>
      <button type="button" class="pdv-cash-session-action danger" data-pdv-cash-movement="withdrawal">Sangria <kbd>Alt+S</kbd></button>
    @endif
    @if($cashSession)
      <button type="button" class="pdv-cash-session-action" id="pdvCashCloseButton" onclick="document.getElementById('pdvCashCloseDialog').showModal()">Fechar caixa <kbd>Alt+K</kbd></button>
    @else
      <button type="button" class="pdv-cash-session-action primary" id="pdvCashOpenButton" onclick="document.getElementById('pdvCashOpenDialog').showModal()">Abrir caixa <kbd>Alt+A</kbd></button>
    @endif
  </div>
</section>

@if(!$cashSession)
<dialog class="pdv-quick-modal" id="pdvCashOpenDialog">
  <form method="post" action="{{ route('pdv.cash.open') }}">
    @csrf
    <div class="pdv-modal-head">
      <div><h2>Abrir caixa</h2><p>Informe o fundo inicial disponível em dinheiro.</p></div>
      <button type="button" class="pdv-modal-close" onclick="this.closest('dialog').close()" aria-label="Fechar">@include('partials.icon',['name'=>'x','size'=>18])</button>
    </div>
    <div class="pdv-cash-modal-body">
      <label><span>Valor de abertura</span><input type="number" step="0.01" min="0" name="opening_amount" value="{{ old('opening_amount','0.00') }}" required autofocus></label>
      <label><span>Observação</span><textarea name="opening_notes" rows="3" maxlength="1000" placeholder="Opcional"></textarea></label>
      <button class="pdv-modal-primary" type="submit">@include('partials.icon',['name'=>'check','size'=>18]) Abrir caixa</button>
    </div>
  </form>
</dialog>
@else
<dialog class="pdv-quick-modal" id="pdvCashCloseDialog">
  <form method="post" action="{{ route('pdv.cash.close') }}">
    @csrf
    <div class="pdv-modal-head">
      <div><h2>Fechar caixa</h2><p>Conferência do saldo físico em dinheiro.</p></div>
      <button type="button" class="pdv-modal-close" onclick="this.closest('dialog').close()" aria-label="Fechar">@include('partials.icon',['name'=>'x','size'=>18])</button>
    </div>
    <div class="pdv-cash-modal-body">
      @if($cashExpected!==null)
        <div class="pdv-cash-total"><span>Saldo esperado</span><strong>R$ {{ number_format((float)$cashExpected,2,',','.') }}</strong></div>
      @endif
      <label><span>Saldo contado</span><input type="number" step="0.01" min="0" name="closing_amount" value="{{ old('closing_amount',$cashExpected ?? 0) }}" required autofocus></label>
      <label><span>Observação</span><textarea name="closing_notes" rows="3" maxlength="1000" placeholder="Opcional"></textarea></label>
      <button class="pdv-modal-primary" type="submit">@include('partials.icon',['name'=>'check','size'=>18]) Fechar caixa</button>
    </div>
  </form>
</dialog>
@endif

@if($cashSession && $pdvSettings['allow_cash_movements'])
<dialog class="pdv-quick-modal pdv-cash-movement-modal" id="pdvCashMovementDialog">
  <form method="post" action="{{ route('pdv.cash.movement') }}">
    @csrf
    <input type="hidden" name="type" id="pdvCashMovementType" value="supply">
    <div class="pdv-modal-head">
      <div>
        <h2 id="pdvCashMovementTitle">Movimentar caixa</h2>
        <p id="pdvCashMovementHelp" data-tutorial>Registre uma entrada ou retirada manual.</p>
      </div>
      <button type="button" class="pdv-modal-close" onclick="this.closest('dialog').close()" aria-label="Fechar">@include('partials.icon',['name'=>'x','size'=>18])</button>
    </div>
    <div class="pdv-cash-modal-body">
      <div class="pdv-cash-total">
        <span>Saldo esperado agora</span>
        <strong>R$ {{ number_format((float)($cashExpected ?? 0),2,',','.') }}</strong>
      </div>
      <label><span>Valor</span><input type="number" step="0.01" min="0.01" name="amount" id="pdvCashMovementAmount" required></label>
      <label><span>Motivo</span><input type="text" name="reason" maxlength="255" id="pdvCashMovementReason" placeholder="Ex.: troco adicional, depósito no cofre..." required></label>
      @if($cashMovements->isNotEmpty())
        <div class="pdv-cash-history">
          <strong>Últimas movimentações</strong>
          @foreach($cashMovements as $movement)
            <span>
              {{ $movement->type==='supply'?'Suprimento':'Sangria' }}
              · R$ {{ number_format((float)$movement->amount,2,',','.') }}
              · {{ $movement->reason }}
            </span>
          @endforeach
        </div>
      @endif
      <button class="pdv-modal-primary" type="submit" id="pdvCashMovementSubmit">Registrar <kbd>Ctrl+Enter</kbd></button>
    </div>
  </form>
</dialog>
@endif
@endif

<form id="pdvForm" method="post" action="{{ route('pdv.store') }}">
@csrf

@if($lastCompletedPdvSale)
<div class="pdv-last-sale">
  <div>
    @include('partials.icon',['name'=>'check','size'=>18])
    <span>Última venda: <strong>#{{ str_pad((string)$lastCompletedPdvSale->id,5,'0',STR_PAD_LEFT) }}</strong></span>
    <span>R$ {{ number_format((float)$lastCompletedPdvSale->total,2,',','.') }}</span>
    <span>{{ ($lastCompletedPdvSale->completed_at ?? $lastCompletedPdvSale->created_at)?->format('d/m H:i') }}</span>
    @if($lastPdvNfceDocument)
      <span class="pdv-last-sale-fiscal-status">NFC-e: {{ match($lastPdvNfceDocument->status) {
        'prepared'=>'preparada', 'processing'=>'processando', 'pending'=>'pendente',
        'authorized'=>'autorizada', 'rejected'=>'rejeitada', 'cancelled'=>'cancelada',
        default=>'verificar'
      } }}</span>
    @else
      <span class="pdv-last-sale-fiscal-status">NFC-e ainda não preparada</span>
    @endif
  </div>
  <div class="pdv-last-sale-actions">
    @if(auth()->user()->canAccess('fiscal'))
      <a href="{{ $lastPdvNfceDocument
        ? route('fiscal.show', $lastPdvNfceDocument)
        : route('pdv.receipt', ['sale' => $lastCompletedPdvSale->id, 'print' => 0]) }}">Conferir NFC-e</a>
    @endif
    @if(auth()->user()->canAccess('sales'))
      <a href="{{ route('sales.show',$lastCompletedPdvSale) }}">Abrir venda</a>
    @endif
  </div>
</div>
@endif

<div class="pdv-workspace" id="pdvApp"
     data-search-url="{{ route('pdv.search') }}"
     data-allow-negative-stock="{{ $allowNegativeStock ? '1' : '0' }}"
     data-require-customer="{{ $pdvSettings['require_customer'] ? '1' : '0' }}"
     data-allow-discount="{{ $pdvSettings['allow_discount'] ? '1' : '0' }}"
     data-show-stock="{{ $pdvSettings['show_stock'] ? '1' : '0' }}"
     data-cash-required="{{ $pdvSettings['require_cash_opening'] ? '1' : '0' }}"
     data-cash-open="{{ $cashSession ? '1' : '0' }}"
     data-ask-consumer-document="{{ $pdvSettings['ask_consumer_document'] ? '1' : '0' }}"
     data-allow-split-payment="{{ $pdvSettings['allow_split_payment'] ? '1' : '0' }}"
     data-suspend-url="{{ route('pdv.suspended.store') }}"
     data-resume-url-template="{{ route('pdv.suspended.resume',['suspendedSale'=>'__ID__']) }}"
     data-discard-url-template="{{ route('pdv.suspended.destroy',['suspendedSale'=>'__ID__']) }}"
     data-contingency-active="{{ $nfceContingencyActive ? '1' : '0' }}">
  <section class="pdv-catalog">
    <div class="pdv-search-panel">
      <div class="pdv-search-box">
        <span class="pdv-search-icon">@include('partials.icon',['name'=>'barcode','size'=>22])</span>
        <input id="pdvSearch" type="search" autocomplete="off"
               placeholder="Código, código de barras ou produto..." aria-label="Buscar produto no PDV">
        <kbd>F2</kbd>
      </div>

      <div class="pdv-search-tools pdv-search-tools-products-only">
        <div class="pdv-product-search-label">
          @include('partials.icon',['name'=>'products','size'=>15])
          <span>Produtos</span>
        </div>
        <div class="pdv-search-keyboard">
          <span><kbd>↑</kbd><kbd>↓</kbd> Navegar</span>
          <span><kbd>Enter</kbd> Adicionar</span>
        </div>
      </div>
    </div>

    <div class="pdv-results-head">
      <div>
        <h2>Produtos</h2>
        <p id="pdvResultsCaption">Digite para localizar um produto.</p>
      </div>
      <span class="pdv-scan-status">@include('partials.icon',['name'=>'barcode','size'=>15]) Busca / leitor prontos</span>
    </div>

    <div class="pdv-results" id="pdvResults">
      <div class="pdv-empty-state" id="pdvSearchEmpty">
        @include('partials.icon',['name'=>'search','size'=>28])
        <strong>Localize um item para começar</strong>
        <span>Use nome, SKU ou EAN/GTIN. O leitor de código de barras também pode digitar aqui.</span>
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
        <span>Adicione um produto.</span>
      </div>
    </div>

    <div class="pdv-summary">
      <div><span>Subtotal</span><strong id="pdvSubtotal">R$ 0,00</strong></div>
      <div><span>Descontos</span><strong id="pdvDiscountTotal">R$ 0,00</strong></div>
      <div class="pdv-grand-total"><span>Total</span><strong id="pdvTotal">R$ 0,00</strong></div>
    </div>

    <input type="hidden" name="customer_id" id="pdvCustomer" value="">
    <input type="hidden" name="consumer_document" id="pdvConsumerDocument" value="">
    <input type="hidden" name="consumer_name" id="pdvConsumerName" value="">
    <input type="hidden" name="payment_method" id="pdvPaymentMethod" value="{{ $pdvSettings['default_payment_method'] }}">
    <input type="hidden" name="cash_received" id="pdvCashReceived" value="0.00">
    <input type="hidden" name="notes" id="pdvNotes" value="">

    <div id="pdvItemsPayload"></div>
    <div id="pdvPaymentsPayload"></div>

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
    <button type="button" class="pdv-client-option" data-customer-id="" data-customer-name="" data-customer-document="" data-customer-search="consumidor não identificado">
      <span class="pdv-client-avatar">CF</span>
      <span><strong>Consumidor não identificado</strong><small>Venda sem cliente vinculado</small></span>
    </button>
    @foreach($customers as $customer)
      <button type="button" class="pdv-client-option"
              data-customer-id="{{ $customer->id }}"
              data-customer-name="{{ $customer->name }}"
              data-customer-document="{{ strtoupper(preg_replace('/[^A-Z0-9]/i','',$customer->document ?? '')) }}"
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
  @if($pdvSettings['allow_split_payment'])
    <div class="pdv-split-payment">
      <button type="button" class="pdv-split-toggle" id="pdvSplitToggle">Dividir em mais de uma forma <kbd>D</kbd></button>
      <div class="pdv-split-panel" id="pdvSplitPanel" hidden>
        <div class="pdv-split-summary">
          <span>Total <strong id="pdvSplitTotal">R$ 0,00</strong></span>
          <span>Informado <strong id="pdvSplitPaid">R$ 0,00</strong></span>
          <span>Falta <strong id="pdvSplitRemaining">R$ 0,00</strong></span>
        </div>
        <div class="pdv-split-rows" id="pdvSplitRows"></div>
        <p class="pdv-split-help">Com a divisão ativa, clique nas formas de pagamento acima para adicioná-las.</p>
        <button type="button" class="pdv-modal-primary" id="pdvSplitApply">Aplicar pagamento dividido <kbd>Ctrl+Enter</kbd></button>
      </div>
    </div>
  @endif
  <div class="pdv-modal-help">
    <span><kbd>1–9</kbd> Selecionar</span>
    @if($pdvSettings['allow_split_payment'])<span><kbd>D</kbd> Dividir</span>@endif
    <span><kbd>↑</kbd><kbd>↓</kbd> Navegar</span>
    <span><kbd>Enter</kbd> Confirmar</span>
    <span><kbd>Esc</kbd> Fechar</span>
  </div>
</dialog>

<dialog class="pdv-quick-modal pdv-consumer-modal" id="pdvConsumerDocumentModal">
  <div class="pdv-modal-head">
    <div>
      <h2>CPF/CNPJ na nota?</h2>
      <p>Identifique o consumidor somente quando ele solicitar ou quando a operação exigir.</p>
    </div>
    <button type="button" class="pdv-modal-close" data-pdv-modal-close aria-label="Fechar">@include('partials.icon',['name'=>'x','size'=>18])</button>
  </div>
  <div class="pdv-consumer-modal-body">
    <div class="pdv-consumer-question">
      @include('partials.icon',['name'=>'customers','size'=>28])
      <div><strong>O consumidor quer identificação na nota?</strong><span>Informe CPF ou CNPJ, ou continue sem identificação.</span></div>
    </div>
    <label>
      <span>CPF / CNPJ</span>
      <input type="text" maxlength="18" id="pdvConsumerDocumentInput" autocomplete="off" placeholder="Digite somente se solicitado">
    </label>
    <button type="button" class="pdv-consumer-customer" id="pdvConsumerUseCustomer" hidden>Usar documento do cliente selecionado <kbd>C</kbd></button>
    <div class="pdv-consumer-actions">
      <button type="button" class="pdv-consumer-skip" id="pdvConsumerSkip">Sem CPF/CNPJ <kbd>Esc</kbd></button>
      <button type="button" class="pdv-modal-primary" id="pdvConsumerApply">@include('partials.icon',['name'=>'check','size'=>18]) Confirmar identificação <kbd>Enter</kbd></button>
    </div>
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
      <span>Parcela em dinheiro</span>
      <strong id="pdvCashModalTotal">R$ 0,00</strong>
    </div>

    <label>
      <span>Valor recebido</span>
      <input type="text" inputmode="decimal" id="pdvCashModalInput" autocomplete="off" value="0,00">
    </label>

    <button type="button" class="pdv-cash-exact" id="pdvCashExact">
      Usar valor exato da venda <kbd>E</kbd>
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

<dialog class="pdv-quick-modal pdv-operations-modal" id="pdvOperationsModal">
  <div class="pdv-modal-head">
    <div>
      <span>Alt+O</span>
      <h2>Operações do caixa</h2>
      <p data-tutorial>Todas as rotinas operacionais também possuem atalho direto.</p>
    </div>
    <button type="button" class="pdv-modal-close" data-pdv-modal-close aria-label="Fechar">@include('partials.icon',['name'=>'x','size'=>18])</button>
  </div>
  <div class="pdv-operations-list">
    <button type="button" data-pdv-operation="suspend"><span><strong>Suspender venda</strong><small>Guarda o carrinho para atender depois.</small></span><kbd>Alt+0</kbd></button>
    <button type="button" data-pdv-operation="resume"><span><strong>Recuperar venda</strong><small>Retoma uma venda suspensa sem movimentar estoque antes da conclusão.</small></span><kbd>Alt+R</kbd></button>
    <button type="button" data-pdv-operation="electronic"><span><strong>Cartão / TEF / POS</strong><small>Dados da integração, adquirente, bandeira, autorização e terminal.</small></span><kbd>Alt+T</kbd></button>
    <button type="button" data-pdv-operation="contingency"><span><strong>Contingência NFC-e</strong><small>Ativa ou encerra a preparação offline de novas NFC-e.</small></span><kbd>Alt+C</kbd></button>
    <button type="button" data-pdv-operation="cancel-nfce"><span><strong>Cancelamento NFC-e</strong><small>Coloca uma NFC-e autorizada na fila de cancelamento fiscal.</small></span><kbd>Alt+X</kbd></button>
    @if($pdvSettings['require_cash_opening'])
      @if($cashSession)
        <button type="button" data-pdv-operation="close-cash"><span><strong>Fechar caixa</strong><small>Encerra o turno atual.</small></span><kbd>Alt+K</kbd></button>
        @if($pdvSettings['allow_cash_movements'])
          <button type="button" data-pdv-operation="supply"><span><strong>Suprimento</strong><small>Entrada manual de dinheiro para reforço de troco.</small></span><kbd>Alt+P</kbd></button>
          <button type="button" data-pdv-operation="withdrawal"><span><strong>Sangria</strong><small>Retirada manual de dinheiro do caixa.</small></span><kbd>Alt+S</kbd></button>
        @endif
      @else
        <button type="button" data-pdv-operation="open-cash"><span><strong>Abrir caixa</strong><small>Inicia o turno do operador.</small></span><kbd>Alt+A</kbd></button>
      @endif
    @endif
  </div>
  <div class="pdv-modal-help"><span><kbd>↑</kbd><kbd>↓</kbd> Navegar</span><span><kbd>Enter</kbd> Abrir</span><span><kbd>Esc</kbd> Fechar</span></div>
</dialog>

<dialog class="pdv-quick-modal pdv-suspend-modal" id="pdvSuspendModal">
  <div class="pdv-modal-head">
    <div><span>Alt+0</span><h2>Suspender venda</h2><p>O estoque só será movimentado quando a venda for finalizada.</p></div>
    <button type="button" class="pdv-modal-close" data-pdv-modal-close aria-label="Fechar">@include('partials.icon',['name'=>'x','size'=>18])</button>
  </div>
  <div class="pdv-operation-body">
    <div class="pdv-operation-summary"><span>Itens</span><strong id="pdvSuspendItems">0</strong><span>Total</span><strong id="pdvSuspendTotal">R$ 0,00</strong></div>
    <label><span>Identificação opcional</span><input type="text" id="pdvSuspendLabel" maxlength="190" placeholder="Ex.: Mesa 3, João, orçamento balcão..."></label>
    <button type="button" class="pdv-modal-primary" id="pdvSuspendApply">Suspender venda <kbd>Enter</kbd></button>
  </div>
</dialog>

<dialog class="pdv-quick-modal pdv-recover-modal" id="pdvRecoverModal">
  <div class="pdv-modal-head">
    <div><span>Alt+R</span><h2>Recuperar venda suspensa</h2><p>Selecione a venda que deseja devolver ao carrinho.</p></div>
    <button type="button" class="pdv-modal-close" data-pdv-modal-close aria-label="Fechar">@include('partials.icon',['name'=>'x','size'=>18])</button>
  </div>
  <div class="pdv-suspended-list" id="pdvSuspendedList">
    @forelse($suspendedSales as $suspended)
      <div class="pdv-suspended-row" data-suspended-row="{{ $suspended->id }}">
        <button type="button" class="pdv-suspended-resume"
                data-suspended-id="{{ $suspended->id }}">
          <span>
            <strong>{{ $suspended->label ?: 'Venda suspensa #'.$suspended->id }}</strong>
            <small>{{ $suspended->item_count }} item(ns) · R$ {{ number_format((float)$suspended->total,2,',','.') }} · {{ $suspended->user?->name }}</small>
          </span>
          <time>{{ $suspended->suspended_at?->format(\App\Models\AppSetting::dateFormat().' H:i') }}</time>
        </button>
        <button type="button" class="pdv-suspended-discard" data-suspended-discard="{{ $suspended->id }}" aria-label="Descartar venda suspensa">@include('partials.icon',['name'=>'trash','size'=>15])</button>
      </div>
    @empty
      <div class="pdv-operation-empty">Nenhuma venda suspensa.</div>
    @endforelse
  </div>
  <div class="pdv-modal-help"><span><kbd>↑</kbd><kbd>↓</kbd> Navegar</span><span><kbd>Enter</kbd> Recuperar</span><span><kbd>Delete</kbd> Descartar</span></div>
</dialog>

<dialog class="pdv-quick-modal pdv-electronic-modal" id="pdvElectronicPaymentModal">
  <div class="pdv-modal-head">
    <div><span>Alt+T</span><h2>Cartão / TEF / POS</h2><p>Dados que acompanham o grupo de pagamento eletrônico da NFC-e.</p></div>
    <button type="button" class="pdv-modal-close" data-pdv-modal-close aria-label="Fechar">@include('partials.icon',['name'=>'x','size'=>18])</button>
  </div>
  <div class="pdv-electronic-body">
    <div class="pdv-electronic-target" id="pdvElectronicTarget">Selecione uma forma de pagamento com cartão.</div>
    <div class="pdv-electronic-grid">
      <label><span>Integração</span><select id="pdvElectronicIntegration"><option value="">Não informado</option><option value="1">1 — Integrado / TEF / POS integrado</option><option value="2">2 — POS não integrado</option></select></label>
      <label><span>Bandeira</span><select id="pdvElectronicBrand"><option value="">Não informada</option><option value="01">01 — Visa</option><option value="02">02 — Mastercard</option><option value="03">03 — American Express</option><option value="04">04 — Sorocred</option><option value="05">05 — Diners Club</option><option value="06">06 — Elo</option><option value="07">07 — Hipercard</option><option value="08">08 — Aura</option><option value="09">09 — Cabal</option><option value="99">99 — Outros</option></select></label>
      <label><span>CNPJ instituição / adquirente</span><input id="pdvElectronicInstitution" maxlength="18" placeholder="Somente números ou formatado"></label>
      <label><span>Autorização da transação</span><input id="pdvElectronicAuthorization" maxlength="128" placeholder="cAut"></label>
      <label><span>CNPJ beneficiário</span><input id="pdvElectronicBeneficiary" maxlength="18" placeholder="CNPJReceb"></label>
      <label><span>Terminal</span><input id="pdvElectronicTerminal" maxlength="40" placeholder="idTermPag"></label>
      <label><span>CNPJ transacional</span><input id="pdvElectronicTransactionDocument" maxlength="18" placeholder="CNPJPag — opcional"></label>
      <label><span>UF do pagamento</span><input id="pdvElectronicTransactionState" maxlength="2" placeholder="UFPag"></label>
    </div>
    <div class="pdv-operation-note">Esses campos são guardados na venda e seguem para o snapshot fiscal. A exigência final depende das regras da UF e do meio de pagamento.</div>
    <button type="button" class="pdv-modal-primary" id="pdvElectronicApply">Salvar dados do pagamento <kbd>Ctrl+Enter</kbd></button>
  </div>
</dialog>

<dialog class="pdv-quick-modal pdv-contingency-modal" id="pdvContingencyModal">
  <form method="post" action="{{ route('pdv.nfce.contingency') }}">
    @csrf
    <input type="hidden" name="action" value="{{ $nfceContingencyActive ? 'stop' : 'start' }}">
    <div class="pdv-modal-head">
      <div><span>Alt+C</span><h2>Contingência NFC-e</h2><p>Controle operacional da emissão offline.</p></div>
      <button type="button" class="pdv-modal-close" data-pdv-modal-close aria-label="Fechar">@include('partials.icon',['name'=>'x','size'=>18])</button>
    </div>
    <div class="pdv-operation-body">
      @if($nfceContingencyActive)
        <div class="pdv-contingency-active">
          <strong>Contingência offline ativa</strong>
          <span>{{ $nfceContingencyReason ?: 'Sem motivo registrado' }}</span>
          @if($nfceContingencyStartedAt)<small>Iniciada em {{ \Carbon\Carbon::parse($nfceContingencyStartedAt)->format(\App\Models\AppSetting::dateFormat().' H:i') }}</small>@endif
        </div>
        <div class="pdv-operation-note">Encerrar a contingência afeta apenas novas vendas. As NFC-e já preparadas offline permanecem identificadas para transmissão posterior.</div>
        <button class="pdv-modal-primary" type="submit">Encerrar contingência <kbd>Ctrl+Enter</kbd></button>
      @else
        <label><span>Motivo técnico *</span><textarea name="reason" id="pdvContingencyReason" rows="4" minlength="15" maxlength="255" required placeholder="Ex.: indisponibilidade de comunicação com o autorizador..."></textarea></label>
        <div class="pdv-operation-note">Use contingência somente quando houver problema técnico que impeça a autorização normal.</div>
        <button class="pdv-modal-primary" type="submit">Ativar contingência offline <kbd>Ctrl+Enter</kbd></button>
      @endif
    </div>
  </form>
</dialog>

<dialog class="pdv-quick-modal pdv-cancel-nfce-modal" id="pdvCancelNfceModal">
  <form method="post" id="pdvCancelNfceForm" action="">
    @csrf
    <div class="pdv-modal-head">
      <div><span>Alt+X</span><h2>Cancelar NFC-e</h2><p>Somente documentos já autorizados podem entrar na fila de cancelamento.</p></div>
      <button type="button" class="pdv-modal-close" data-pdv-modal-close aria-label="Fechar">@include('partials.icon',['name'=>'x','size'=>18])</button>
    </div>
    <div class="pdv-operation-body">
      @if($cancelableNfceJobs->isNotEmpty())
        <label><span>NFC-e autorizada</span>
          <select id="pdvCancelNfceJob" required>
            @foreach($cancelableNfceJobs as $job)
              <option value="{{ $job->id }}" data-cancel-url="{{ route('pdv.nfce.cancel',$job) }}">
                NFC-e {{ $job->series }}/{{ $job->document_number }} · venda #{{ $job->sale_id }} · R$ {{ number_format((float)($job->sale?->total ?? 0),2,',','.') }}
              </option>
            @endforeach
          </select>
        </label>
        <label><span>Justificativa *</span><textarea name="reason" id="pdvCancelNfceReason" rows="4" minlength="15" maxlength="255" required></textarea></label>
        <div class="pdv-operation-note">O Nextor registra a solicitação na fila fiscal. A efetivação depende do motor fiscal transmitir o evento à SEFAZ.</div>
        <button class="pdv-modal-primary" type="submit">Colocar cancelamento na fila <kbd>Ctrl+Enter</kbd></button>
      @else
        <div class="pdv-operation-empty">Nenhuma NFC-e autorizada disponível para cancelamento.</div>
      @endif
    </div>
  </form>
</dialog>

@endsection

@push('scripts')
<script defer src="{{ asset('js/pdv.js') }}"></script>
@endpush
