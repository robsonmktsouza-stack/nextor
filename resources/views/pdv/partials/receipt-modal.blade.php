{{-- Modal de cupom único para conclusão e histórico; impressão em iframe oculto. --}}
@php
    $receiptModalIsHistory=(bool)($receiptModalIsHistory ?? false);
    $receiptModalIsCancelled=$receiptModalSale->status==='cancelled';
    $receiptDialogId=$receiptModalIsHistory ? 'pdvHistoryReceiptModal' : 'pdvReceiptModal';
    $receiptTitleId=$receiptModalIsHistory ? 'pdvHistoryReceiptModalTitle' : 'pdvReceiptModalTitle';
    $receiptCloseId=$receiptModalIsHistory ? 'pdvHistoryReceiptCloseTop' : 'pdvReceiptCloseTop';
    $receiptDoneId=$receiptModalIsHistory ? 'pdvHistoryReceiptDone' : 'pdvReceiptDone';
    $receiptPrintId=$receiptModalIsHistory ? 'pdvHistoryReceiptPrint' : 'pdvReceiptPrint';
    $receiptFrameId=$receiptModalIsHistory ? 'pdvHistoryReceiptFrame' : 'pdvReceiptFrame';
    $receiptModalCompanyName=$receiptModalCompany->trade_name
      ?: ($receiptModalCompany->legal_name ?: config('app.name','Lumeron'));
    $receiptModalHasCash=$receiptModalSale->payments->contains(
      fn($payment)=>($receiptModalPaymentKinds[$payment->payment_method] ?? null)==='cash'
    );
    $receiptModalFiscalAuthorized=$receiptModalNfceDocument?->status==='authorized'
      && preg_match('/^[0-9]{15}$/',(string)$receiptModalNfceDocument?->protocol)===1
      && in_array((string)$receiptModalNfceDocument?->xml_path,[
        'fiscal/nfce/'.$receiptModalNfceDocument->id.'/authorized.xml',
        'fiscal/nfce/'.$receiptModalNfceDocument->id.'/authorized-recovered.xml',
      ],true);
    $receiptModalFiscalLabel=$receiptModalNfceDocument
      ? match($receiptModalNfceDocument->status) {
          'authorized' => $receiptModalFiscalAuthorized ? 'Autorizada' : 'Autorização a conferir',
          'prepared' => 'Preparada para revisão',
          'processing' => 'Em processamento',
          'pending' => 'Aguardando consulta',
          'rejected' => 'Rejeitada — revisar',
          'offline_signed','offline_sending' => 'Contingência — autorização pendente',
          'offline_print_pending' => 'DANFE de contingência pendente',
          'cancelled' => 'Cancelada',
          default => 'Consultar situação fiscal',
        }
      : 'Ainda não preparada';
    $receiptModalCanPrepare=$receiptModalSale->status==='completed'
      && !$receiptModalNfceDocument
      && auth()->user()->canAccess('fiscal')
      && (bool)\App\Models\AppSetting::value('fiscal','enabled',false)
      && (bool)\App\Models\AppSetting::value('nfce','enabled',false);
  @endphp
  <dialog class="pdv-quick-modal pdv-receipt-modal" id="{{ $receiptDialogId }}"
          aria-labelledby="{{ $receiptTitleId }}" aria-modal="true"
          data-receipt-after-checkout="{{ $receiptModalIsHistory ? '0' : '1' }}">
    <div class="pdv-receipt-header">
      <div class="pdv-receipt-title">
        <span class="pdv-receipt-title-icon">@include('partials.icon',['name'=>$receiptModalIsHistory ? 'receipt' : 'check','size'=>19])</span>
        <div>
          <span>{{ $receiptModalIsHistory ? 'PDV · Comprovante' : 'PDV · Venda concluída' }}</span>
          <h2 id="{{ $receiptTitleId }}">Venda nº {{ str_pad((string)$receiptModalSale->id,5,'0',STR_PAD_LEFT) }}</h2>
        </div>
      </div>
      <button type="button" class="pdv-modal-close" id="{{ $receiptCloseId }}" data-pdv-receipt-close aria-label="Fechar comprovante">
        @include('partials.icon',['name'=>'x','size'=>18])
      </button>
    </div>

    <div class="pdv-receipt-toolbar">
      <div class="pdv-receipt-success{{ $receiptModalIsHistory ? ' pdv-receipt-history-state' : '' }}{{ $receiptModalIsCancelled ? ' is-cancelled' : '' }}" role="status">
        @include('partials.icon',['name'=>$receiptModalIsHistory ? 'receipt' : 'check','size'=>18])
        <span>{{ $receiptModalIsHistory ? ($receiptModalIsCancelled ? 'Venda cancelada' : 'Comprovante da venda') : 'Venda efetuada com sucesso' }}</span>
      </div>

      <div class="pdv-receipt-success-actions" aria-label="{{ $receiptModalIsHistory ? 'Ações do comprovante' : 'Ações após a venda' }}">
        <button type="button" class="pdv-receipt-action pdv-receipt-action-back" id="{{ $receiptDoneId }}" data-pdv-receipt-done>
          @include('partials.icon',['name'=>'arrow-left','size'=>17]) {{ $receiptModalIsHistory ? 'Voltar às vendas' : 'Voltar ao PDV' }}
        </button>
        <button type="button" class="pdv-receipt-action pdv-receipt-action-print" id="{{ $receiptPrintId }}" data-pdv-receipt-print disabled>
          @include('partials.icon',['name'=>'print','size'=>17]) Imprimir comprovante
        </button>
        @if($receiptModalNfceDocument && auth()->user()->canAccess('fiscal'))
          <a class="pdv-receipt-action pdv-receipt-action-fiscal"
             href="{{ route('fiscal.show',$receiptModalNfceDocument) }}"
             target="_blank" rel="noopener noreferrer">
            @include('partials.icon',['name'=>'receipt','size'=>17])
            {{ in_array($receiptModalNfceDocument->status,['prepared','rejected'],true) ? 'Revisar NFC-e' : 'Consultar NFC-e' }}
          </a>
        @elseif($receiptModalCanPrepare)
          <form method="post" target="_blank" action="{{ route('pdv.receipt.nfce.prepare',$receiptModalSale) }}">
            @csrf
            <button type="submit" class="pdv-receipt-action pdv-receipt-action-fiscal">
              @include('partials.icon',['name'=>'receipt','size'=>17]) Preparar NFC-e
            </button>
          </form>
        @endif
      </div>
    </div>

    <div class="pdv-receipt-content">
      <section class="pdv-receipt-document" aria-label="Comprovante da venda">
        <div class="pdv-receipt-company">
          @if($receiptModalCompany->logo_path)
            <img src="{{ asset('storage/'.$receiptModalCompany->logo_path) }}"
                 alt="" class="pdv-receipt-company-logo">
          @endif
          <strong>{{ $receiptModalCompanyName }}</strong>
          @if($receiptModalCompany->document)
            <span>{{ $receiptModalCompany->document }}</span>
          @endif
          <div class="pdv-receipt-meta">
            <span><b>Venda:</b> {{ str_pad((string)$receiptModalSale->id,5,'0',STR_PAD_LEFT) }}</span>
            <span><b>Data:</b> {{ ($receiptModalSale->completed_at ?? $receiptModalSale->created_at)?->format(\App\Models\AppSetting::dateFormat().' H:i') }}</span>
            <span><b>Operador:</b> {{ $receiptModalSale->user?->name ?? '—' }}</span>
            <span><b>Cliente:</b> {{ $receiptModalSale->customer?->name ?? ($receiptModalSale->consumer_name ?: 'Consumidor não identificado') }}</span>
          </div>
        </div>

        <div class="pdv-receipt-paper-caption">Comprovante de venda</div>
        <div class="pdv-receipt-paper-warning">Sem valor fiscal@if($receiptModalIsCancelled) · Venda cancelada@endif</div>

        <div class="pdv-receipt-document-heading">Itens da venda</div>
        <div class="pdv-receipt-table-scroll">
          <table class="pdv-receipt-items">
            <thead>
              <tr>
                <th scope="col">Produto / quantidade</th>
                <th scope="col">Valor</th>
              </tr>
            </thead>
            <tbody>
              @foreach($receiptModalSale->items as $item)
                <tr>
                  <td>
                    <span>{{ $item->product_name }}</span>
                    @if($item->product_sku)<small>{{ $item->product_sku }}</small>@endif
                    <small class="pdv-receipt-item-detail">{{ rtrim(rtrim(number_format((float)$item->quantity,3,',','.'),'0'),',') }} × R$ {{ number_format((float)$item->unit_price,2,',','.') }}</small>
                  </td>
                  <td>R$ {{ number_format((float)$item->line_total,2,',','.') }}</td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>

        <div class="pdv-receipt-totals">
          <div><span>Quantidade total de itens</span><strong>{{ rtrim(rtrim(number_format((float)$receiptModalSale->items->sum('quantity'),3,',','.'),'0'),',') }}</strong></div>
          <div><span>Subtotal</span><strong>R$ {{ number_format((float)$receiptModalSale->subtotal,2,',','.') }}</strong></div>
          @if((float)$receiptModalSale->discount_total>0)
            <div><span>Descontos</span><strong>- R$ {{ number_format((float)$receiptModalSale->discount_total,2,',','.') }}</strong></div>
          @endif
          <div class="pdv-receipt-total"><span>Total da venda</span><strong>R$ {{ number_format((float)$receiptModalSale->total,2,',','.') }}</strong></div>
        </div>

        <div class="pdv-receipt-document-heading">Formas de pagamento</div>
        <div class="pdv-receipt-payments">
          @foreach($receiptModalSale->payments as $payment)
            <div>
              <span>{{ $receiptModalPaymentLabels[$payment->payment_method] ?? 'Outra forma de pagamento' }}</span>
              <strong>R$ {{ number_format((float)$payment->amount,2,',','.') }}</strong>
            </div>
          @endforeach
          @if($receiptModalHasCash)
            <div><span>Recebido em dinheiro</span><strong>R$ {{ number_format((float)$receiptModalSale->cash_received,2,',','.') }}</strong></div>
            <div><span>Troco</span><strong>R$ {{ number_format((float)$receiptModalSale->change_amount,2,',','.') }}</strong></div>
          @endif
        </div>
        <div class="pdv-receipt-document-fiscal">
          <span>NFC-e</span>
          <strong>{{ $receiptModalFiscalLabel }}</strong>
        </div>
        @if($receiptModalSale->notes)
          <p class="pdv-receipt-note">{{ $receiptModalSale->notes }}</p>
        @endif
        <p class="pdv-receipt-internal-notice">Comprovante interno de venda · Não substitui documento fiscal.</p>
      </section>
    </div>
    {{-- O modelo térmico original é mantido somente para imprimir ao clicar. --}}
    <iframe id="{{ $receiptFrameId }}" data-pdv-receipt-frame class="pdv-receipt-print-frame"
            title="Documento para impressão da venda #{{ $receiptModalSale->id }}"
            src="{{ route('pdv.receipt',['sale'=>$receiptModalSale->id,'embed'=>1]) }}"
            loading="eager" referrerpolicy="same-origin" tabindex="-1" aria-hidden="true"></iframe>
  </dialog>

