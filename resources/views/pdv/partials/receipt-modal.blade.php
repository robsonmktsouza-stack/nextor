{{-- Mesmo cupom visual para pós-venda e histórico; impressão abre o PDF térmico nativo. --}}
@php
    $receiptModalIsHistory=(bool)($receiptModalIsHistory ?? false);
    $receiptModalIsCancelled=$receiptModalSale->status==='cancelled';
    $receiptDialogId=$receiptModalIsHistory ? 'pdvHistoryReceiptModal' : 'pdvReceiptModal';
    $receiptTitleId=$receiptModalIsHistory ? 'pdvHistoryReceiptModalTitle' : 'pdvReceiptModalTitle';
    $receiptCloseId=$receiptModalIsHistory ? 'pdvHistoryReceiptCloseTop' : 'pdvReceiptCloseTop';
    $receiptDoneId=$receiptModalIsHistory ? 'pdvHistoryReceiptDone' : 'pdvReceiptDone';
    $receiptPrintId=$receiptModalIsHistory ? 'pdvHistoryReceiptPrint' : 'pdvReceiptPrint';
    $receiptCompanyAddress=collect([
      $receiptModalCompany->address,
      $receiptModalCompany->address_number,
      $receiptModalCompany->district,
      $receiptModalCompany->city
        ? $receiptModalCompany->city.($receiptModalCompany->state ? '/'.$receiptModalCompany->state : '')
        : null,
    ])->filter()->implode(' · ');
    $receiptCompanyContact=collect([
      $receiptModalCompany->phone,
      $receiptModalCompany->email,
    ])->filter()->implode(' · ');
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
        @include('partials.icon',['name'=>'x','size'=>16])
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
        <a class="pdv-receipt-action pdv-receipt-action-print" id="{{ $receiptPrintId }}"
           href="{{ route('pdv.receipt',['sale'=>$receiptModalSale->id,'print'=>0]) }}"
           target="_blank" rel="noopener noreferrer" data-pdv-receipt-print
           title="Abrir PDF térmico para impressão">
          @include('partials.icon',['name'=>'print','size'=>17]) Imprimir comprovante
        </a>
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
      <div class="pdv-receipt-fiscal-inline">
        <span>NFC-e</span>
        <strong>{{ $receiptModalFiscalLabel }}</strong>
      </div>
    </div>

    <div class="pdv-receipt-content">
      <section class="pdv-receipt-document" aria-label="Comprovante da venda">
        <div class="pdv-receipt-company">
          @if($receiptModalCompany->logo_path)
            <img src="{{ route('pdv.company.logo') }}"
                 alt="" loading="lazy" decoding="async" class="pdv-receipt-company-logo">
          @endif
          <strong>{{ $receiptModalCompanyName }}</strong>
          @if($receiptModalCompany->document)
            <span>{{ $receiptModalCompany->document }}</span>
          @endif
          @if($receiptCompanyAddress)
            <span>{{ $receiptCompanyAddress }}</span>
          @endif
          @if($receiptCompanyContact)
            <span>{{ $receiptCompanyContact }}</span>
          @endif
          @if($receiptModalCompany->print_header)
            <div class="pdv-receipt-custom-header">{{ $receiptModalCompany->print_header }}</div>
          @endif
        </div>

        <div class="pdv-receipt-paper-caption">COMPROVANTE DE VENDA</div>
        <div class="pdv-receipt-paper-warning">SEM VALOR FISCAL</div>
        @if($receiptModalIsCancelled)
          <div class="pdv-receipt-cancelled-note">VENDA CANCELADA</div>
        @endif
        <div class="pdv-receipt-meta">
          <span>Venda nº {{ str_pad((string)$receiptModalSale->id,9,'0',STR_PAD_LEFT) }}</span>
          <span>{{ ($receiptModalSale->completed_at ?? $receiptModalSale->created_at)?->format(\App\Models\AppSetting::dateFormat().' H:i:s') }}</span>
          <span>Operador: {{ $receiptModalSale->user?->name ?? '—' }}</span>
        </div>

        <div class="pdv-receipt-document-heading">Itens da venda</div>
        <div class="pdv-receipt-table-scroll">
          <table class="pdv-receipt-items">
            <tbody>
              @foreach($receiptModalSale->items as $item)
                <tr>
                  <td>
                    <span>{{ $item->product_name }}</span>
                    @if($item->product_sku)
                      <small>Cod.: {{ $item->product_sku }}</small>
                    @endif
                    <small class="pdv-receipt-item-detail">{{ rtrim(rtrim(number_format((float)$item->quantity,3,',','.'),'0'),',') }} × R$ {{ number_format((float)$item->unit_price,2,',','.') }}</small>
                    @if((float)$item->discount>0)
                      <small>Desconto do item: - R$ {{ number_format((float)$item->discount,2,',','.') }}</small>
                    @endif
                  </td>
                  <td>R$ {{ number_format((float)$item->line_total + (float)$item->discount,2,',','.') }}</td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>

        <div class="pdv-receipt-totals">
          <div><span>Subtotal</span><strong>R$ {{ number_format((float)$receiptModalSale->subtotal,2,',','.') }}</strong></div>
          @if((float)$receiptModalSale->discount_total>0)
            <div><span>Desconto</span><strong>- R$ {{ number_format((float)$receiptModalSale->discount_total,2,',','.') }}</strong></div>
          @endif
          <div class="pdv-receipt-total"><span>Total</span><strong>R$ {{ number_format((float)$receiptModalSale->total,2,',','.') }}</strong></div>
        </div>

        <div class="pdv-receipt-payments">
          @foreach($receiptModalSale->payments as $payment)
            <div>
              <span>{{ $receiptModalPaymentLabels[$payment->payment_method] ?? 'Outro' }}</span>
              <strong>R$ {{ number_format((float)$payment->amount,2,',','.') }}</strong>
            </div>
          @endforeach
          @if($receiptModalHasCash)
            <div><span>Recebido em dinheiro</span><strong>R$ {{ number_format((float)$receiptModalSale->cash_received,2,',','.') }}</strong></div>
            <div><span>Troco</span><strong>R$ {{ number_format((float)$receiptModalSale->change_amount,2,',','.') }}</strong></div>
          @endif
        </div>

        <div class="pdv-receipt-customer-info">
          <div><span>Cliente</span><strong>{{ $receiptModalSale->customer?->name ?? 'Consumidor não identificado' }}</strong></div>
          <div><span>CPF/CNPJ na nota</span><strong>{{ $receiptModalSale->consumer_document ?: 'Não informado' }}</strong></div>
          @if($receiptModalSale->consumer_name)
            <div><span>Consumidor</span><strong>{{ $receiptModalSale->consumer_name }}</strong></div>
          @endif
        </div>
        @if($receiptModalSale->notes)
          <p class="pdv-receipt-note">{{ $receiptModalSale->notes }}</p>
        @endif
        @if($receiptModalCompany->print_footer)
          <p class="pdv-receipt-note pdv-receipt-custom-footer">{{ $receiptModalCompany->print_footer }}</p>
        @endif
        <p class="pdv-receipt-internal-notice">Comprovante interno de venda. Não substitui documento fiscal.</p>
      </section>
    </div>
    {{-- O PDF de 58/80 mm é aberto no leitor nativo do navegador, não em um iframe oculto. --}}
  </dialog>

