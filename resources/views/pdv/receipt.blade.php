<!DOCTYPE html>
@php
  $formatMoney = fn($value) => number_format((float) $value, 2, ',', '.');
  $formatQty = function ($value) {
      $formatted = number_format((float) $value, 3, ',', '.');
      return rtrim(rtrim($formatted, '0'), ',');
  };
  $paymentIsCash = $sale->payments->contains(fn($payment) => $payment->payment_method === 'cash');
  $receiptHeightMm = max(120, min(500, 105 + ($sale->items->count() * 9) + ($sale->payments->count() * 6) + ($sale->notes ? 14 : 0)));
@endphp
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Comprovante de venda #{{ $sale->id }}</title>
<style>
*{box-sizing:border-box}
html,body{margin:0;padding:0;background:#eef1f4;color:#111;font-family:Arial,Helvetica,sans-serif}
body{font-size:9px}
.screen-actions{width:80mm;max-width:calc(100vw - 24px);margin:16px auto 10px;display:flex;gap:8px}
.screen-actions a,.screen-actions button{flex:1;min-height:38px;display:flex;align-items:center;justify-content:center;border:1px solid #cbd4dc;border-radius:5px;background:#fff;color:#29455e;font:600 11px Arial,sans-serif;text-decoration:none;cursor:pointer}
.screen-actions button{background:#2877c7;border-color:#2877c7;color:#fff}
.receipt{width:80mm;max-width:100%;margin:0 auto 24px;padding:4mm;background:#fff}
.center{text-align:center}
.section{padding:2.2mm 0;border-top:1px dashed #222}
.brand{font-size:13px;font-weight:800;text-transform:uppercase}
.subtitle{margin-top:1mm;font-size:10px;font-weight:700}
.warning{margin-top:1.5mm;padding:1.2mm;border:1px solid #222;font-size:8px;font-weight:700}
.meta{margin-top:2mm;line-height:1.45;font-size:8px}
.items{width:100%;border-collapse:collapse;table-layout:fixed}
.items th{padding:1mm .4mm;border-bottom:1px solid #222;font-size:6.8px;text-align:right}
.items th:nth-child(1),.items th:nth-child(2){text-align:left}
.items td{padding:1mm .4mm;border-bottom:1px dotted #aaa;font-size:7.2px;text-align:right;vertical-align:top;overflow-wrap:anywhere}
.items td:nth-child(1),.items td:nth-child(2){text-align:left}
.col-code{width:14%}.col-desc{width:36%}.col-qty{width:12%}.col-unit{width:18%}.col-total{width:20%}
.row{display:grid;grid-template-columns:1fr auto;gap:5mm;margin:.8mm 0;align-items:baseline}
.row strong{text-align:right;white-space:nowrap}
.total{font-size:11px;font-weight:800;margin-top:1.5mm}
.footer{font-size:7.2px;line-height:1.45;text-align:center}
@page{size:80mm {{ $receiptHeightMm }}mm;margin:0}
@media print{
  html,body{width:80mm!important;min-width:80mm!important;max-width:80mm!important;min-height:{{ $receiptHeightMm }}mm!important;background:#fff!important}
  .screen-actions{display:none!important}
  .receipt{width:80mm!important;min-width:80mm!important;max-width:80mm!important;margin:0!important;padding:4mm!important}
}
</style>
</head>
<body>
<div class="screen-actions">
  <a href="{{ route('pdv.index') }}">Voltar ao PDV</a>
  <button type="button">Imprimir novamente</button>
</div>

<main class="receipt">
  <header class="center">
    <div class="brand">{{ config('app.name', 'NEXTOR') }}</div>
    <div class="subtitle">COMPROVANTE DE VENDA</div>
    <div class="warning">SEM VALOR FISCAL</div>
    <div class="meta">
      Venda nº {{ str_pad((string) $sale->id, 9, '0', STR_PAD_LEFT) }}<br>
      {{ optional($sale->completed_at)->format('d/m/Y H:i:s') ?? now()->format('d/m/Y H:i:s') }}
      @if($sale->user)<br>Operador: {{ $sale->user->name }}@endif
    </div>
  </header>

  <section class="section">
    <table class="items">
      <thead>
        <tr>
          <th class="col-code">Cód.</th>
          <th class="col-desc">Descrição</th>
          <th class="col-qty">Qtde</th>
          <th class="col-unit">Vl.Unit</th>
          <th class="col-total">Total</th>
        </tr>
      </thead>
      <tbody>
      @foreach($sale->items as $item)
        <tr>
          <td>{{ $item->product_sku ?: '-' }}</td>
          <td>{{ $item->product_name }}</td>
          <td>{{ $formatQty($item->quantity) }}</td>
          <td>{{ $formatMoney($item->unit_price) }}</td>
          <td>{{ $formatMoney($item->line_total + $item->discount) }}</td>
        </tr>
        @if((float) $item->discount > 0)
          <tr>
            <td></td><td colspan="3">Desconto</td><td>-{{ $formatMoney($item->discount) }}</td>
          </tr>
        @endif
      @endforeach
      </tbody>
    </table>
  </section>

  <section class="section">
    <div class="row"><span>Subtotal</span><strong>R$ {{ $formatMoney($sale->subtotal) }}</strong></div>
    @if((float) $sale->discount_total > 0)
      <div class="row"><span>Desconto</span><strong>- R$ {{ $formatMoney($sale->discount_total) }}</strong></div>
    @endif
    <div class="row total"><span>Total</span><strong>R$ {{ $formatMoney($sale->total) }}</strong></div>
  </section>

  <section class="section">
    @foreach($sale->payments as $payment)
      <div class="row">
        <span>{{ $paymentLabels[$payment->payment_method] ?? 'Outro' }}</span>
        <strong>R$ {{ $formatMoney($payment->payment_method === 'cash' ? $cashReceived : $payment->amount) }}</strong>
      </div>
    @endforeach
    @if($paymentIsCash)
      <div class="row"><span>Troco</span><strong>R$ {{ $formatMoney($change) }}</strong></div>
    @endif
  </section>

  <section class="section">
    <div class="row"><span>Cliente</span><strong>{{ $sale->customer?->name ?? 'Consumidor não identificado' }}</strong></div>
    @if($sale->customer?->document)
      <div class="row"><span>Documento</span><strong>{{ $sale->customer->document }}</strong></div>
    @endif
  </section>

  @if($sale->notes)
    <section class="section footer">{{ $sale->notes }}</section>
  @endif

  <footer class="section footer">
    Comprovante interno de venda. Não substitui documento fiscal.
  </footer>
</main>

<script>
(()=>{
  let automatic=true;
  const printButton=document.querySelector('.screen-actions button');
  window.addEventListener('load',()=>window.setTimeout(()=>{if(automatic) window.print();},250));
  window.addEventListener('afterprint',()=>{
    if(!automatic) return;
    automatic=false;
    window.setTimeout(()=>window.location.replace(@json(route('pdv.index'))),120);
  });
  printButton?.addEventListener('click',()=>{automatic=false;window.print();});
})();
</script>
</body>
</html>
