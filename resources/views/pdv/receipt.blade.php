<!DOCTYPE html>
@php
  $formatMoney = fn($value) => number_format((float) $value, 2, ',', '.');
  $formatQty = function ($value) {
      $formatted = number_format((float) $value, 3, ',', '.');
      return rtrim(rtrim($formatted, '0'), ',');
  };

  $receiptWidth = in_array((string)($pdvSettings['receipt_width'] ?? '80'), ['58','80'], true)
      ? (string)$pdvSettings['receipt_width']
      : '80';
  $receiptCopies = max(1, min(5, (int)($pdvSettings['receipt_copies'] ?? 1)));
  $paymentIsCash = $sale->payments->contains(
      fn($payment) => ($paymentKinds[$payment->payment_method] ?? null) === 'cash'
  );
  $baseHeight = $receiptWidth === '58' ? 132 : 112;
  $itemHeight = $receiptWidth === '58' ? 12 : 9;
  $receiptHeightMm = max(
      $receiptWidth === '58' ? 145 : 120,
      min(700, $baseHeight + ($sale->items->count() * $itemHeight) + ($sale->payments->count() * 7) + ($sale->notes ? 16 : 0))
  );
  $companyName = $company->trade_name ?: $company->legal_name ?: config('app.name','NEXTOR');
  $companyDocument = $company->document;
  $companyAddress = collect([
      $company->address,
      $company->address_number,
      $company->district,
      $company->city ? $company->city.($company->state ? '/'.$company->state : '') : null,
  ])->filter()->implode(' · ');
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
.screen-actions{width:{{ $receiptWidth }}mm;max-width:calc(100vw - 24px);margin:16px auto 10px;display:flex;gap:8px}
.screen-actions a,.screen-actions button{flex:1;min-height:38px;display:flex;align-items:center;justify-content:center;border:1px solid #cbd4dc;border-radius:5px;background:#fff;color:#29455e;font:600 11px Arial,sans-serif;text-decoration:none;cursor:pointer}
.screen-actions button{background:#2877c7;border-color:#2877c7;color:#fff}
.receipt{width:{{ $receiptWidth }}mm;max-width:100%;margin:0 auto 24px;padding:{{ $receiptWidth==='58'?'3':'4' }}mm;background:#fff}
.receipt-copy+.receipt-copy{margin-top:12px}
.center{text-align:center}
.company-logo{display:block;max-width:70%;max-height:19mm;object-fit:contain;margin:0 auto 2mm}
.section{padding:2.2mm 0;border-top:1px dashed #222}
.brand{font-size:{{ $receiptWidth==='58'?'11':'13' }}px;font-weight:800;text-transform:uppercase}
.subtitle{margin-top:1mm;font-size:{{ $receiptWidth==='58'?'9':'10' }}px;font-weight:700}
.warning{margin-top:1.5mm;padding:1.2mm;border:1px solid #222;font-size:8px;font-weight:700}
.meta{margin-top:2mm;line-height:1.45;font-size:7.5px;overflow-wrap:anywhere}
.custom-header{margin-top:1.5mm;font-size:7.2px;line-height:1.45}
.items{width:100%;border-collapse:collapse;table-layout:fixed}
.items th{padding:1mm .3mm;border-bottom:1px solid #222;font-size:{{ $receiptWidth==='58'?'6':'6.8' }}px;text-align:right}
.items th:nth-child(1),.items th:nth-child(2){text-align:left}
.items td{padding:1mm .3mm;border-bottom:1px dotted #aaa;font-size:{{ $receiptWidth==='58'?'6.4':'7.2' }}px;text-align:right;vertical-align:top;overflow-wrap:anywhere}
.items td:nth-child(1),.items td:nth-child(2){text-align:left}
.col-code{width:14%}.col-desc{width:{{ $receiptWidth==='58'?'34':'36' }}%}.col-qty{width:12%}.col-unit{width:19%}.col-total{width:21%}
.row{display:grid;grid-template-columns:1fr auto;gap:3mm;margin:.8mm 0;align-items:baseline}
.row strong{text-align:right;white-space:nowrap}
.total{font-size:11px;font-weight:800;margin-top:1.5mm}
.footer{font-size:7.2px;line-height:1.45;text-align:center;overflow-wrap:anywhere}
.copy-label{font-size:6.5px;color:#555;margin-top:1mm}
@page{size:{{ $receiptWidth }}mm {{ $receiptHeightMm }}mm;margin:0}
@media print{
  html,body{width:{{ $receiptWidth }}mm!important;min-width:{{ $receiptWidth }}mm!important;max-width:{{ $receiptWidth }}mm!important;background:#fff!important}
  .screen-actions{display:none!important}
  .receipt{width:{{ $receiptWidth }}mm!important;min-width:{{ $receiptWidth }}mm!important;max-width:{{ $receiptWidth }}mm!important;margin:0!important;padding:{{ $receiptWidth==='58'?'3':'4' }}mm!important;page-break-after:always}
  .receipt:last-of-type{page-break-after:auto}
}
</style>
</head>
<body>
<div class="screen-actions">
  <a href="{{ route('pdv.index') }}">Voltar ao PDV</a>
  <button type="button">Imprimir novamente</button>
</div>

@for($copy=1;$copy<=$receiptCopies;$copy++)
<main class="receipt receipt-copy">
  <header class="center">
    @if($company->logo_path)
      <img class="company-logo" src="{{ asset('storage/'.$company->logo_path) }}" alt="">
    @endif
    <div class="brand">{{ $companyName }}</div>
    @if($companyDocument)<div class="meta">{{ $companyDocument }}</div>@endif
    @if($companyAddress)<div class="meta">{{ $companyAddress }}</div>@endif
    @if($company->phone || $company->email)
      <div class="meta">{{ collect([$company->phone,$company->email])->filter()->implode(' · ') }}</div>
    @endif
    @if($company->print_header)
      <div class="custom-header">{!! nl2br(e($company->print_header)) !!}</div>
    @endif
    <div class="subtitle">COMPROVANTE DE VENDA</div>
    <div class="warning">SEM VALOR FISCAL</div>
    <div class="meta">
      Venda nº {{ str_pad((string) $sale->id, 9, '0', STR_PAD_LEFT) }}<br>
      {{ optional($sale->completed_at)->format(\App\Models\AppSetting::dateFormat().' H:i:s') ?? now()->format(\App\Models\AppSetting::dateFormat().' H:i:s') }}
      @if($sale->user)<br>Operador: {{ $sale->user->name }}@endif
    </div>
    @if($receiptCopies>1)<div class="copy-label">Via {{ $copy }}/{{ $receiptCopies }}</div>@endif
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
      @php($isCash=($paymentKinds[$payment->payment_method] ?? null)==='cash')
      <div class="row">
        <span>{{ $paymentLabels[$payment->payment_method] ?? 'Outro' }}</span>
        <strong>R$ {{ $formatMoney($payment->amount) }}</strong>
      </div>
    @endforeach
    @if($paymentIsCash)
      <div class="row"><span>Recebido em dinheiro</span><strong>R$ {{ $formatMoney($cashReceived) }}</strong></div>
      <div class="row"><span>Troco</span><strong>R$ {{ $formatMoney($change) }}</strong></div>
    @endif
  </section>

  <section class="section">
    <div class="row"><span>Cliente</span><strong>{{ $sale->customer?->name ?? 'Consumidor não identificado' }}</strong></div>
    @if($sale->consumer_document)
      <div class="row"><span>CPF/CNPJ na nota</span><strong>{{ $sale->consumer_document }}</strong></div>
      @if($sale->consumer_name)
        <div class="row"><span>Consumidor identificado</span><strong>{{ $sale->consumer_name }}</strong></div>
      @endif
    @else
      <div class="row"><span>CPF/CNPJ na nota</span><strong>Não informado</strong></div>
    @endif
  </section>

  @if($sale->notes)
    <section class="section footer">{{ $sale->notes }}</section>
  @endif

  <footer class="section footer">
    @if($company->print_footer)
      {!! nl2br(e($company->print_footer)) !!}<br>
    @endif
    Comprovante interno de venda. Não substitui documento fiscal.
  </footer>
</main>
@endfor

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
