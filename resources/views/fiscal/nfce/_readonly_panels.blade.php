{{-- Consulta da NFC-e: reaproveita as mesmas abas do editor, sem formularios de alteracao. --}}
@php
  $money=static fn($value)=>'R$ '.number_format((float)$value,2,',','.');
  $quantity=static fn($value)=>rtrim(rtrim(number_format((float)$value,4,',','.'),'0'),',');
@endphp

<section class="editor-tab-panel active" id="nfcePanel-general" role="tabpanel" aria-labelledby="nfceTab-general" data-nfce-panel="general">
  <div class="nfe-general-card">
    <div class="nfe-section-title"><h3>Dados gerais</h3></div>
    <div class="editor-grid cols-12">
      <label class="field col-4"><span>Origem da NFC-e</span>
        <input type="text" value="{{ $readOnlyDocument->sale_id ? 'Venda #'.$readOnlyDocument->sale_id : 'Emissão registrada' }}" readonly>
      </label>
      <label class="field col-4"><span>Natureza da operação</span>
        <input type="text" value="{{ $frozenNature }}" readonly>
      </label>
      <label class="field col-4"><span>Data da emissão</span>
        <input type="text" value="{{ $frozenIssueDate }}" readonly>
      </label>
      <label class="field col-6"><span>Presença do comprador</span>
        <input type="text" value="{{ $frozenPresence }}" readonly>
      </label>
      <label class="field col-3"><span>Tipo de emissão</span>
        <input type="text" value="{{ $readOnlyDocument->emission_mode==='offline' ? 'Contingência offline' : 'Normal' }}" readonly>
      </label>
      <label class="field col-3"><span>Ambiente</span>
        <input type="text" value="{{ $readOnlyDocument->environment==='production' ? 'Produção' : 'Homologação' }}" readonly>
      </label>
    </div>
  </div>
</section>

<section class="editor-tab-panel" id="nfcePanel-consumer" role="tabpanel" aria-labelledby="nfceTab-consumer" data-nfce-panel="consumer" hidden>
  <div class="nfe-general-card">
    <div class="nfe-section-title"><h3>Consumidor</h3></div>
    <div class="editor-grid cols-12">
      <label class="field col-6"><span>Consumidor</span>
        <input type="text" value="{{ $frozenConsumerName ?: 'Consumidor não identificado' }}" readonly>
      </label>
      <label class="field col-6"><span>CPF / CNPJ</span>
        <input type="text" value="{{ $frozenConsumerDocument ?: 'Não informado' }}" readonly>
      </label>
    </div>
  </div>
</section>

<section class="editor-tab-panel" id="nfcePanel-products" role="tabpanel" aria-labelledby="nfceTab-products" data-nfce-panel="products" hidden>
  <div class="nfe-general-card">
    <div class="nfe-section-title"><h3>Produtos</h3></div>
    <div class="nfce-item-table-wrap">
      <table class="nfce-item-table nfce-readonly-items">
        <thead><tr><th>Produto</th><th>Quantidade</th><th>Preço</th><th>Desconto</th><th>Total</th></tr></thead>
        <tbody>
          @forelse($frozenRows as $item)
            <tr>
              <td><strong>{{ $item['name'] }}</strong>@if($item['sku'] !== '')<small>{{ $item['sku'] }}</small>@endif</td>
              <td class="nfce-readonly-number">{{ $quantity($item['quantity']) }}</td>
              <td class="nfce-readonly-number">{{ $money($item['unit_price']) }}</td>
              <td class="nfce-readonly-number">{{ $money($item['discount']) }}</td>
              <td class="nfce-readonly-number"><strong>{{ $money($item['total']) }}</strong></td>
            </tr>
          @empty
            <tr><td colspan="5" class="empty-cell">Itens históricos indisponíveis neste documento.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>
</section>

<section class="editor-tab-panel" id="nfcePanel-payment" role="tabpanel" aria-labelledby="nfceTab-payment" data-nfce-panel="payment" hidden>
  <div class="nfe-general-card">
    <div class="nfe-section-title"><h3>Pagamento</h3></div>
    <div class="nfce-item-table-wrap">
      <table class="nfce-item-table nfce-readonly-payments">
        <thead><tr><th>Forma de pagamento</th><th>Valor</th></tr></thead>
        <tbody>
          @forelse($frozenPaymentRows as $payment)
            <tr><td>{{ $payment['name'] }}</td><td class="nfce-readonly-number">{{ $money($payment['amount']) }}</td></tr>
          @empty
            <tr><td colspan="2" class="empty-cell">Pagamento histórico indisponível.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
    <div class="nfce-payment-subtotal">
      <span>Total informado</span><strong>{{ $money($frozenPaymentTotal) }}</strong>
    </div>
  </div>
</section>

<section class="editor-tab-panel" id="nfcePanel-summary" role="tabpanel" aria-labelledby="nfceTab-summary" data-nfce-panel="summary" hidden>
  <div class="nfe-general-card">
    <div class="nfe-section-title"><h3>Resumo da NFC-e</h3></div>
    <div class="nfce-summary-grid">
      <div><span>Subtotal</span><strong>{{ $money($frozenTotals['subtotal']) }}</strong></div>
      <div><span>Desconto</span><strong>{{ $money($frozenTotals['discount']) }}</strong></div>
      <div><span>Total da NFC-e</span><strong>{{ $money($frozenTotals['total']) }}</strong></div>
    </div>
    <label class="field nfce-note-field"><span>Observações da nota</span>
      <textarea rows="3" readonly>{{ $frozenNotes }}</textarea>
    </label>
  </div>
</section>
