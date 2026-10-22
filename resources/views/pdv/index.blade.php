@extends('layouts.pdv')

@section('content')
<dialog class="pdv-quick-modal" id="pdvCashPinDialog">
  <form method="post" action="{{ route('pdv.cash.pin') }}">@csrf
    <div class="pdv-modal-head"><div><h2>Meu PIN de caixa</h2><p>PIN individual com seis dígitos.</p></div>
    <button type="button" class="pdv-modal-close" onclick="this.closest('dialog').close()" aria-label="Fechar">@include('partials.icon',['name'=>'x','size'=>18])</button></div>
    <div class="pdv-cash-modal-body">
      <label><span>Senha de acesso *</span><input type="password" name="current_password" autocomplete="current-password" required></label>
      <label><span>Novo PIN *</span><input type="password" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" name="pin" required autocomplete="new-password"></label>
      <label><span>Confirmar PIN *</span><input type="password" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" name="pin_confirmation" required autocomplete="new-password"></label>
      <button class="pdv-modal-primary" type="submit">Salvar PIN</button>
    </div>
  </form>
</dialog>
@if($pdvSettings['require_cash_opening'])
@if(!$cashSession)
<dialog class="pdv-quick-modal" id="pdvCashOpenDialog">
  <form method="post" action="{{ route('pdv.cash.open') }}">
    @csrf
    <div class="pdv-modal-head">
      <div><h2>Abrir caixa</h2></div>
      <button type="button" class="pdv-modal-close" onclick="this.closest('dialog').close()" aria-label="Fechar">@include('partials.icon',['name'=>'x','size'=>18])</button>
    </div>
    <div class="pdv-cash-modal-body">
      <label><span>Caixa físico *</span><select name="register_id" required>@foreach($cashRegisters as $register)<option value="{{ $register->id }}">{{ $register->name }} ({{ $register->code }})</option>@endforeach</select></label>
      <label><span>Operador responsável</span><input value="{{ auth()->user()->name }}" readonly></label>
      <label><span>Valor de abertura</span><input type="number" step="0.01" min="0" name="opening_amount" value="{{ old('opening_amount','0.00') }}" required autofocus></label>
      <label><span>Senha ou PIN *</span><input type="password" name="credential" autocomplete="off" required></label>
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
      <div><h2>Fechar caixa</h2></div>
      <button type="button" class="pdv-modal-close" onclick="this.closest('dialog').close()" aria-label="Fechar">@include('partials.icon',['name'=>'x','size'=>18])</button>
    </div>
    <div class="pdv-cash-modal-body">
      <p class="pdv-cash-blind-notice">Fechamento cego: conte primeiro o dinheiro físico. O saldo esperado e as diferenças serão revelados no relatório após confirmar.</p>
      <label><span>Saldo contado</span><input type="number" step="0.01" min="0" name="closing_amount" value="{{ old('closing_amount','') }}" required autofocus></label>
      <p style="margin:4px 0;font-size:12px">Conte o dinheiro físico antes de informar o valor. Divergências exigem justificativa e ficam registradas para conferência.</p>
      <label><span>Observação</span><textarea name="closing_notes" rows="3" maxlength="1000" placeholder="Obrigatória se houver diferença"></textarea></label>
      <label><span>Senha ou PIN *</span><input name="credential" type="password" autocomplete="off" required></label>
      <button class="pdv-modal-primary" type="submit">@include('partials.icon',['name'=>'check','size'=>18]) Fechar caixa</button>
    </div>
  </form>
</dialog>
<dialog class="pdv-quick-modal pdv-cash-handover-modal" id="pdvCashHandoverDialog">
  <form method="post" action="{{ route('pdv.cash.handover') }}">@csrf
    <div class="pdv-modal-head">
      <div><h2>Trocar turno</h2><p>{{ $cashSession->register?->name ?? 'Caixa' }} · {{ auth()->user()->name }}</p></div>
      <button type="button" class="pdv-modal-close" onclick="this.closest('dialog').close()" aria-label="Fechar">@include('partials.icon',['name'=>'x','size'=>18])</button>
    </div>
    <div class="pdv-cash-modal-body">
      <p class="pdv-cash-blind-notice">Conte o dinheiro antes de transferir. O turno anterior é fechado e o novo aberto na mesma operação.</p>
      <label><span>Dinheiro contado *</span><input type="number" name="counted_amount" step="0.01" min="0" required></label>
      <label><span>Operador que assume *</span><select name="incoming_user_id" required><option value="">Selecione</option>
        @foreach($shiftOperators as $operator)<option value="{{ $operator->id }}">{{ $operator->name }}</option>@endforeach
      </select></label>
      <label><span>Senha ou PIN do operador atual *</span><input type="password" name="outgoing_credential" autocomplete="off" required></label>
      <label><span>Senha ou PIN do próximo operador *</span><input type="password" name="incoming_credential" autocomplete="off" required></label>
      <label><span>Observação</span><textarea name="notes" rows="2" maxlength="1000" placeholder="Obrigatória se houver diferença"></textarea></label>
      <button class="pdv-modal-primary" type="submit">Confirmar passagem de turno</button>
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
      <div class="pdv-cash-total"><span>Conferência</span><strong>Valor apurado no fechamento</strong></div>
      <label><span>Valor</span><input type="number" step="0.01" min="0.01" name="amount" id="pdvCashMovementAmount" required></label>
      <label><span>Motivo</span><input type="text" name="reason" maxlength="255" id="pdvCashMovementReason" placeholder="Ex.: troco adicional, depósito no cofre..." required></label>
      <div id="pdvCashSupervisorFields" class="pdv-cash-supervisor" hidden data-approval-threshold="{{ $pdvSettings['supervisor_movement_min'] ?? 200 }}">
        <p>Operação sensível: autorização de um supervisor.</p>
        <label><span>Supervisor</span><select name="supervisor_user_id" id="pdvCashSupervisorUser"><option value="">Selecione</option>
          @foreach($cashSupervisors as $supervisor)<option value="{{ $supervisor->id }}">{{ $supervisor->name }}</option>@endforeach
        </select></label>
        <label><span>Senha ou PIN do supervisor</span><input type="password" name="supervisor_credential" id="pdvCashSupervisorCredential" autocomplete="off"></label>
      </div>
      <div id="pdvCashRefundFields" hidden>
        <label><span>Devolução concluída (se aplicável)</span>
          <input type="number" min="1" step="1" name="sale_return_id" id="pdvCashRefundReturn" list="pdvCashRefundReturnOptions" placeholder="Número da devolução">
          <datalist id="pdvCashRefundReturnOptions">
            @foreach($refundReturns as $return)
              <option value="{{ $return->id }}" label="Venda #{{ $return->sale_id }} · R$ {{ number_format((float)$return->total,2,',','.') }}"></option>
            @endforeach
          </datalist>
        </label>
        <label><span>Ou venda cancelada</span>
          <input type="number" min="1" step="1" name="sale_id" id="pdvCashRefundSale" list="pdvCashRefundSaleOptions" placeholder="Número da venda cancelada">
          <datalist id="pdvCashRefundSaleOptions">
            @foreach($refundSales as $saleRefund)
              <option value="{{ $saleRefund->id }}" label="Valor R$ {{ number_format((float)$saleRefund->total,2,',','.') }}"></option>
            @endforeach
          </datalist>
        </label>
        <small>Registre somente dinheiro realmente devolvido. Selecione uma devolução ou uma venda cancelada, nunca ambas.</small>
      </div>
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

{{-- Esta confirmação aparece somente ao voltar da impressão da venda atual.
     Nunca equivale a protocolo de autorização da SEFAZ. --}}
@if($lastCompletedPdvSale && (int)session('pdv_last_sale')===(int)$lastCompletedPdvSale->id)
  @php
    $lastFiscalStatus = $lastPdvNfceDocument?->status;
    $fiscalAuthorized = $lastFiscalStatus==='authorized'
      && preg_match('/^[0-9]{15}$/', (string)$lastPdvNfceDocument?->protocol)===1
      && in_array((string)$lastPdvNfceDocument?->xml_path,[
        'fiscal/nfce/'.$lastPdvNfceDocument->id.'/authorized.xml',
        'fiscal/nfce/'.$lastPdvNfceDocument->id.'/authorized-recovered.xml',
      ],true);
    $fiscalMessage = match($lastFiscalStatus) {
      'authorized' => $fiscalAuthorized
        ? 'NFC-e autorizada pela SEFAZ. O DANFE pode ser impresso em Operações > Última venda.'
        : 'A NFC-e precisa de conferência: autorização sem protocolo disponível.',
      'processing' => 'NFC-e em processamento. A autorização ainda não foi confirmada.',
      'pending' => 'Retorno da SEFAZ pendente de consulta. Não repita a venda nem reenvie a nota.',
      'prepared' => 'NFC-e preparada, mas ainda não autorizada. Revise e emita a partir da última venda.',
      'rejected' => 'NFC-e rejeitada. Corrija o documento da venda; não registre outra venda.',
      'offline_signed', 'offline_sending' => 'NFC-e em contingência: autorização da SEFAZ ainda pendente.',
      'offline_print_pending' => 'XML da contingência assinado, mas o DANFE ainda não está pronto. Não refaça a venda.',
      'cancelled' => 'NFC-e cancelada. Consulte o histórico fiscal da venda.',
      default => 'NFC-e ainda não preparada. Consulte a última venda antes de emitir.',
    };
  @endphp
  <div data-system-notification
       data-type="{{ $fiscalAuthorized ? 'success' : 'warning' }}"
       data-notification-title="Venda #{{ $lastCompletedPdvSale->id }} concluída"
       hidden>
    {{ $fiscalMessage }} Você pode continuar atendendo no PDV.
  </div>
@endif

<form id="pdvForm" method="post" action="{{ route('pdv.store') }}">
@csrf


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

{{-- Pós-venda: o comprovante é consultado no próprio caixa.
     Imprimir abre somente o PDF térmico existente, sem imprimir o modal. --}}
@if($receiptModalSale)
  @include('pdv.partials.receipt-modal', ['receiptModalIsHistory'=>false])
@endif

<dialog class="pdv-quick-modal pdv-sales-modal" id="pdvSalesModal" aria-labelledby="pdvSalesTitle" data-sales-url="{{ route('pdv.sales.history') }}">
  <div class="pdv-modal-head">
    <div>
      <span>Alt+V</span>
      <h2 id="pdvSalesTitle">Vendas do PDV</h2>
      
    </div>
    <button type="button" class="pdv-modal-close" data-pdv-modal-close aria-label="Fechar vendas">
      @include('partials.icon',['name'=>'x','size'=>18])
    </button>
  </div>

  <form class="pdv-sales-filters" id="pdvSalesFilters" autocomplete="off">
    <label class="pdv-sales-filter-wide">
      <span>Pesquisar venda</span>
      <input type="search" name="q" id="pdvSalesSearch" placeholder="Número, cliente, CPF/CNPJ ou operador" maxlength="100">
    </label>
    <label>
      <span>Período</span>
      <select id="pdvSalesPeriod" aria-label="Período das vendas">
        <option value="today" selected>Hoje</option>
        <option value="yesterday">Ontem</option>
        <option value="seven">Últimos 7 dias</option>
        <option value="month">Este mês</option>
        <option value="custom">Personalizado</option>
      </select>
    </label>
    <label>
      <span>De</span>
      <input type="date" id="pdvSalesFrom" name="from" value="{{ now()->toDateString() }}" required>
    </label>
    <label>
      <span>Até</span>
      <input type="date" id="pdvSalesTo" name="to" value="{{ now()->toDateString() }}" required>
    </label>
    <label>
      <span>Situação</span>
      <select id="pdvSalesStatus" name="status">
        <option value="all">Todas</option>
        <option value="completed">Concluídas</option>
        <option value="cancelled">Canceladas</option>
      </select>
    </label>
    <label>
      <span>Operador</span>
      <select id="pdvSalesOperator" name="operator">
        <option value="all">Todos</option>
        <option value="mine">Minhas vendas</option>
      </select>
    </label>
    <div class="pdv-sales-filter-actions">
      <button type="submit" id="pdvSalesApply">Filtrar</button>
      <button type="button" id="pdvSalesToday">Hoje</button>
    </div>
  </form>

  <div class="pdv-sales-cancel-sheet" id="pdvSalesCancelSheet" hidden>
    <form class="pdv-sales-cancel-panel" id="pdvSalesCancelForm" novalidate>
      <div class="pdv-sales-cancel-head">
        <h3>Cancelar venda <span id="pdvSalesCancelNumber"></span></h3>
        <button type="button" class="pdv-modal-close" id="pdvSalesCancelClose" aria-label="Voltar sem cancelar">
          @include('partials.icon',['name'=>'x','size'=>17])
        </button>
      </div>
      <div class="pdv-sales-cancel-body">
        <p id="pdvSalesCancelSummary"></p>
        <label for="pdvSalesCancelReason">Motivo do cancelamento *</label>
        <textarea id="pdvSalesCancelReason" rows="3" minlength="5" maxlength="255" required
                  placeholder="Descreva por que esta venda será cancelada"></textarea>
        <p class="pdv-sales-cancel-hint">Os produtos voltarão ao estoque e os lançamentos financeiros serão estornados. A devolução do pagamento ao cliente deve ser realizada separadamente.</p>
        <p class="pdv-sales-cancel-error" id="pdvSalesCancelError" role="alert" hidden></p>
      </div>
      <div class="pdv-sales-cancel-footer">
        <button type="button" id="pdvSalesCancelBack">Voltar</button>
        <button type="submit" class="pdv-sales-cancel-submit" id="pdvSalesCancelConfirm">Confirmar cancelamento</button>
      </div>
    </form>
  </div>
  <div class="pdv-sales-list-scroll">
    <table class="pdv-sales-table" aria-label="Vendas do período">
      <thead>
        <tr>
          <th>Nº</th>
          <th>Data</th>
          <th>Cliente</th>
          <th>Operador</th>
          <th class="pdv-sales-amount">Total</th>
          <th>Situação</th>
          <th>NFC-e</th>
          <th class="pdv-sales-actions-col">Ações</th>
        </tr>
      </thead>
      <tbody id="pdvSalesRows">
        <tr><td colspan="8" class="pdv-sales-message">Abra esta janela para consultar as vendas de hoje.</td></tr>
      </tbody>
    </table>
  </div>

  <div class="pdv-sales-footer">
    <span id="pdvSalesActionStatus" class="pdv-sales-action-status" role="status" aria-live="polite" hidden></span>
    <span id="pdvSalesCount" aria-live="polite">Vendas de hoje</span>
    <div class="pdv-sales-pages">
      <button type="button" id="pdvSalesPrevious" disabled aria-label="Página anterior">Anterior</button>
      <span id="pdvSalesPage">1 / 1</span>
      <button type="button" id="pdvSalesNext" disabled aria-label="Próxima página">Próxima</button>
    </div>
  </div>
</dialog>

<dialog class="pdv-quick-modal pdv-operations-modal" id="pdvOperationsModal">
  <div class="pdv-modal-head">
    <div>
      <span>Alt+O</span>
      <h2>Operações do caixa</h2>
      
    </div>
    <button type="button" class="pdv-modal-close" data-pdv-modal-close aria-label="Fechar">@include('partials.icon',['name'=>'x','size'=>18])</button>
  </div>
  @if($pdvSettings['require_cash_opening'] || $lastCompletedPdvSale)
    <div class="pdv-operations-overview" aria-label="Informações do caixa e da última venda">
      @if($pdvSettings['require_cash_opening'])
        <section class="pdv-operations-overview-card">
          <div class="pdv-operations-overview-title">@include('partials.icon',['name'=>'money','size'=>16]) <strong>Caixa atual</strong></div>
          @if($cashSession)
            <div class="pdv-operations-overview-values">
              <span>Aberto às <strong>{{ $cashSession->opened_at->format('H:i') }}</strong></span>
              <span>Abertura <strong>R$ {{ number_format((float)$cashSession->opening_amount,2,',','.') }}</strong></span>
              
            </div>
          @else
            <p class="pdv-operations-overview-note">Caixa fechado. Abra o caixa para finalizar vendas.</p>
          @endif
        </section>
      @endif

      @if($lastCompletedPdvSale)
        @php
          $lastDanfeAuthorized = $lastPdvNfceDocument?->status==='authorized'
              && (str_ends_with((string)$lastPdvNfceDocument->xml_path,'authorized.xml')
                  || str_ends_with((string)$lastPdvNfceDocument->xml_path,'authorized-recovered.xml'));
          $lastDanfeOffline = $lastPdvNfceDocument?->emission_mode==='offline'
              && in_array($lastPdvNfceDocument?->status,['offline_signed','offline_sending','pending'],true)
              && (string)$lastPdvNfceDocument?->xml_path==='fiscal/nfce/'.$lastPdvNfceDocument->id.'/signed.xml';
        @endphp
        <section class="pdv-operations-overview-card">
          <div class="pdv-operations-overview-title">@include('partials.icon',['name'=>'receipt','size'=>16]) <strong>Última venda</strong></div>
          <div class="pdv-operations-overview-values">
            <span><strong>#{{ str_pad((string)$lastCompletedPdvSale->id,5,'0',STR_PAD_LEFT) }}</strong></span>
            <span><strong>R$ {{ number_format((float)$lastCompletedPdvSale->total,2,',','.') }}</strong></span>
            <span>{{ ($lastCompletedPdvSale->completed_at ?? $lastCompletedPdvSale->created_at)?->format('d/m H:i') }}</span>
            <span>NFC-e: {{ $lastPdvNfceDocument ? $lastPdvNfceStatusLabel : 'ainda não preparada' }}</span>
          </div>
          <div class="pdv-operations-overview-links">
            @if($lastDanfeAuthorized || $lastDanfeOffline)
              <a href="{{ route('pdv.nfce.danfe',$lastPdvNfceDocument) }}" target="_blank" rel="noopener noreferrer">
                {{ $lastDanfeOffline ? 'DANFE contingência (2 vias)' : 'Imprimir DANFE NFC-e' }}
              </a>
            @endif
            @if(auth()->user()->canAccess('fiscal'))
              <a href="{{ $lastPdvNfceDocument
                ? route('fiscal.show',$lastPdvNfceDocument)
                : route('pdv.receipt',['sale'=>$lastCompletedPdvSale->id,'print'=>0]) }}">Abrir NFC-e</a>
            @endif
            @if(auth()->user()->canAccess('sales'))
              <a href="{{ route('sales.show',$lastCompletedPdvSale) }}">Abrir venda</a>
            @endif
          </div>
        </section>
      @endif
    </div>
  @endif
  <div class="pdv-operations-list">
    <button type="button" data-pdv-operation="suspend"><span><strong>Suspender venda</strong><small>Guarda o carrinho para atender depois.</small></span><kbd>Alt+0</kbd></button>
    <button type="button" data-pdv-operation="resume"><span><strong>Recuperar venda</strong><small>Retoma uma venda suspensa sem movimentar estoque antes da conclusão.</small></span><kbd>Alt+R</kbd></button>
    <button type="button" data-pdv-operation="electronic"><span><strong>Dados de cartão e PIX</strong><small>Integração, autorização e dados da instituição de pagamento.</small></span><kbd>Alt+T</kbd></button>
    <button type="button" data-pdv-operation="contingency"><span><strong>Contingência NFC-e</strong><small>Ativa ou encerra a preparação offline de novas NFC-e.</small></span><kbd>Alt+C</kbd></button>
    <button type="button" data-pdv-operation="cancel-nfce"><span><strong>Cancelamento NFC-e</strong><small>Coloca uma NFC-e autorizada na fila de cancelamento fiscal.</small></span><kbd>Alt+X</kbd></button>
    @if($pdvSettings['require_cash_opening'])
      @if($cashSession)
        <button type="button" data-pdv-operation="handover"><span><strong>Trocar turno</strong><small>Passar o caixa ao próximo operador após conferência e identificação de ambos.</small></span><kbd>—</kbd></button>
        <button type="button" data-pdv-operation="close-cash"><span><strong>Fechar caixa</strong><small>Encerra o turno atual.</small></span><kbd>Alt+K</kbd></button>
        <a href="{{ route('pdv.cash.report',$cashSession) }}" target="_blank" rel="noopener noreferrer" style="display:block;padding:10px 14px;color:inherit">Resumo do turno atual</a>
        @if($pdvSettings['allow_cash_movements'])
          <button type="button" data-pdv-operation="supply"><span><strong>Suprimento</strong><small>Entrada manual de dinheiro para reforço de troco.</small></span><kbd>Alt+P</kbd></button>
          <button type="button" data-pdv-operation="withdrawal"><span><strong>Sangria</strong><small>Retirada manual de dinheiro do caixa.</small></span><kbd>Alt+S</kbd></button>
          <button type="button" data-pdv-operation="refund"><span><strong>Reembolso</strong><small>Registra dinheiro devolvido ao cliente, vinculado à operação.</small></span><kbd>—</kbd></button>
        @endif
      @else
        <button type="button" data-pdv-operation="open-cash"><span><strong>Abrir caixa</strong><small>Inicia o turno do operador.</small></span><kbd>Alt+A</kbd></button>
      @endif
    @endif
  </div>
  <p class="pdv-cash-operation-links">
    <a href="{{ route('pdv.cash.history') }}" target="_blank" rel="noopener noreferrer">Histórico e relatórios de caixa</a>
    @if(auth()->user()->role==='admin')<a href="{{ route('pdv.cash.registers') }}">Administrar caixas físicos</a>@endif
    <button type="button" data-pdv-operation="set-pin" class="pdv-cash-text-action">Configurar meu PIN</button>
  </p>
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
    <div><span>Alt+R</span><h2>Recuperar venda suspensa</h2></div>
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
    <div><span>Alt+T</span><h2>Dados de cartão e PIX</h2></div>
    <button type="button" class="pdv-modal-close" data-pdv-modal-close aria-label="Fechar">@include('partials.icon',['name'=>'x','size'=>18])</button>
  </div>
  <div class="pdv-electronic-body">
    <div class="pdv-electronic-target" id="pdvElectronicTarget">Selecione uma forma de pagamento com cartão ou PIX.</div>
    <div class="pdv-electronic-grid">
      <label><span>Integração</span><select id="pdvElectronicIntegration"><option value="">Não informado</option><option value="1">1 — Integrado ao sistema (TEF / pagamento integrado)</option><option value="2">2 — Não integrado (quando permitido)</option></select></label>
      <label><span>Bandeira (apenas cartão)</span><select id="pdvElectronicBrand"><option value="">Não informada</option>@foreach(\App\Support\NFCeCardBrands::LABELS as $code=>$label)<option value="{{ $code }}">{{ $code }} — {{ $label }}</option>@endforeach</select></label>
      <label><span>CNPJ da instituição de pagamento / adquirente</span><input id="pdvElectronicInstitution" maxlength="18" placeholder="Somente números ou formatado"></label>
      <label><span>Código da transação</span><input id="pdvElectronicAuthorization" maxlength="128" placeholder="Autorização do cartão ou identificação do PIX"></label>
      <label><span>CNPJ de quem recebe</span><input id="pdvElectronicBeneficiary" maxlength="18" placeholder="CNPJ beneficiário"></label>
      <label><span>Terminal do pagamento</span><input id="pdvElectronicTerminal" maxlength="40" placeholder="Identificador do terminal, se houver"></label>
      <label><span>CNPJ da transação (quando informado, exige UF)</span><input id="pdvElectronicTransactionDocument" maxlength="18" placeholder="CNPJ do pagamento, quando aplicável"></label>
      <label><span>UF da transação</span><input id="pdvElectronicTransactionState" maxlength="2" placeholder="Ex.: BA"></label>
    </div>
    <div class="pdv-operation-note">Os dados informados acompanham a nota fiscal. Para cartão integrado, informe também CNPJ da adquirente e autorização. Para PIX, preencha os dados da integração quando aplicável; a exigência depende das regras da SEFAZ.</div>
    <button type="button" class="pdv-modal-primary" id="pdvElectronicApply">Salvar dados do pagamento <kbd>Ctrl+Enter</kbd></button>
  </div>
</dialog>

<dialog class="pdv-quick-modal pdv-contingency-modal" id="pdvContingencyModal">
  <form method="post" action="{{ route('pdv.nfce.contingency') }}">
    @csrf
    <input type="hidden" name="action" value="{{ $nfceContingencyActive ? 'stop' : 'start' }}">
    <div class="pdv-modal-head">
      <div><span>Alt+C</span><h2>Contingência NFC-e</h2></div>
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
        <label class="pdv-operation-note"><input type="checkbox" name="no_circulation" value="1" required> Confirmo que a mercadoria não circulou e a operação não foi concluída fisicamente.</label>
        <div class="pdv-operation-note">Cancelamento normal na BA: até 30 minutos da autorização. A situação só mudará após resposta oficial da SEFAZ.</div>
        <button class="pdv-modal-primary" type="submit">Colocar cancelamento na fila <kbd>Ctrl+Enter</kbd></button>
      @else
        <div class="pdv-operation-empty">Nenhuma NFC-e autorizada disponível para cancelamento.</div>
      @endif
    </div>
  </form>
</dialog>

@endsection

@push('scripts')
<script defer src="{{ asset('js/pdv.js') }}?v={{ filemtime(public_path('js/pdv.js')) }}"></script>
<script defer src="{{ asset('js/pdv-sales.js') }}?v={{ filemtime(public_path('js/pdv-sales.js')) }}"></script>
<script defer src="{{ asset('js/pdv-receipt-modal.js') }}?v={{ filemtime(public_path('js/pdv-receipt-modal.js')) }}"></script>
@endpush
