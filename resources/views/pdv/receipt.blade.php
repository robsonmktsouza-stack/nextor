<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Comprovante da venda #{{ str_pad((string)$sale->id,5,'0',STR_PAD_LEFT) }}</title>
<style>
  *{box-sizing:border-box}
  html,body{margin:0;padding:0;background:#eef1f4;color:#000;font-family:Arial,Helvetica,sans-serif}
  body{font-size:10px}
  .screen-actions{
    width:80mm;
    max-width:calc(100vw - 24px);
    margin:18px auto 10px;
    display:flex;
    gap:8px;
  }
  .screen-actions button,.screen-actions a{
    flex:1;
    min-height:38px;
    display:flex;
    align-items:center;
    justify-content:center;
    border:1px solid #cbd4dc;
    border-radius:5px;
    background:#fff;
    color:#28465f;
    font:600 11px Arial,sans-serif;
    text-decoration:none;
    cursor:pointer;
  }
  .screen-actions button{background:#2877c7;color:#fff;border-color:#2877c7}
  .receipt{
    width:80mm;
    max-width:100%;
    margin:0 auto 24px;
    padding:4mm 4mm 6mm;
    background:#fff;
  }
  .center{text-align:center}
  .issuer{padding-bottom:7px}
  .issuer-name{display:block;font-size:14px;font-weight:700;line-height:1.2}
  .issuer-sub{display:block;margin-top:3px;font-size:9px}
  .doc-title{
    margin:5px 0 0;
    padding:6px 4px;
    border-top:1px dashed #000;
    border-bottom:1px dashed #000;
    text-align:center;
    font-size:10px;
    font-weight:700;
    line-height:1.35;
  }
  .non-fiscal{
    display:block;
    margin-top:2px;
    font-size:12px;
    font-weight:800;
  }
  .items{width:100%;border-collapse:collapse;margin-top:7px;table-layout:fixed}
  .items th{
    padding:3px 1px;
    border-bottom:1px solid #000;
    font-size:7px;
    font-weight:700;
    text-align:right;
    vertical-align:bottom;
  }
  .items th:nth-child(1),.items th:nth-child(2){text-align:left}
  .items td{
    padding:4px 1px;
    border-bottom:1px dotted #aaa;
    font-size:8px;
    text-align:right;
    vertical-align:top;
    overflow-wrap:anywhere;
  }
  .items td:nth-child(1),.items td:nth-child(2){text-align:left}
  .items .code{width:14%}
  .items .description{width:31%}
  .items .qty{width:11%}
  .items .unit{width:8%}
  .items .unit-price{width:17%}
  .items .line-total{width:19%}
  .summary{margin-top:7px}
  .summary-row{
    min-height:16px;
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:10px;
    padding:1px 0;
  }
  .summary-row span:first-child{flex:1}
  .summary-row strong,.summary-row span:last-child{white-space:nowrap;text-align:right}
  .summary-row.total{
    margin-top:3px;
    padding-top:4px;
    border-top:1px dashed #000;
    font-size:12px;
    font-weight:700;
  }
  .section{
    margin-top:7px;
    padding-top:6px;
    border-top:1px dashed #000;
  }
  .section-title{
    margin-bottom:4px;
    text-align:center;
    font-size:9px;
    font-weight:700;
  }
  .payment-line{
    display:flex;
    justify-content:space-between;
    gap:8px;
    padding:1px 0;
  }
  .consumer{
    font-size:9px;
    line-height:1.45;
    text-align:center;
  }
  .operation{
    font-size:9px;
    line-height:1.5;
    text-align:center;
  }
  .warning{
    margin-top:7px;
    padding:7px 4px;
    border:1px solid #000;
    text-align:center;
    font-size:10px;
    font-weight:800;
    line-height:1.4;
  }
  .footer{
    margin-top:7px;
    text-align:center;
    font-size:8px;
    line-height:1.4;
  }
  @media print{
    @page{margin:0}
    html,body{width:80mm;background:#fff}
    .screen-actions{display:none!important}
    .receipt{width:80mm;margin:0;padding:3mm 4mm 5mm}
  }
</style>
</head>
<body>
@php
  $formatMoney=fn($value)=>number_format((float)$value,2,',','.');
  $formatQty=function($value){
      $formatted=number_format((float)$value,3,',','.');
      return rtrim(rtrim($formatted,'0'),',');
  };
  $paymentIsCash=$sale->payments->contains(fn($payment)=>$payment->payment_method==='cash');
@endphp

<div class="screen-actions">
  <a href="{{ route('pdv.index') }}">Voltar ao PDV</a>
  <button type="button" onclick="window.print()">Imprimir novamente</button>
</div>

<main class="receipt">
  <header class="issuer center">
    <strong class="issuer-name">{{ strtoupper(config('app.name','NEXTOR')) }}</strong>
    <span class="issuer-sub">COMPROVANTE DE VENDA</span>
  </header>

  <div class="doc-title">
    MODELO VISUAL BASEADO NO DANFE NFC-e
    <span class="non-fiscal">SEM VALOR FISCAL</span>
    NÃO É DOCUMENTO FISCAL
  </div>

  <table class="items" aria-label="Itens da venda">
    <thead>
      <tr>
        <th class="code">CÓD.</th>
        <th class="description">DESCRIÇÃO</th>
        <th class="qty">QTD.</th>
        <th class="unit">UN</th>
        <th class="unit-price">VL UNIT.</th>
        <th class="line-total">VL TOTAL</th>
      </tr>
    </thead>
    <tbody>
      @foreach($sale->items as $item)
        <tr>
          <td>{{ $item->product_sku ?: '-' }}</td>
          <td>{{ $item->product_name }}</td>
          <td>{{ $formatQty($item->quantity) }}</td>
          <td>{{ $item->product?->unit ?? 'UN' }}</td>
          <td>{{ $formatMoney($item->unit_price) }}</td>
          <td>{{ $formatMoney($item->line_total) }}</td>
        </tr>
        @if((float)$item->discount>0)
          <tr>
            <td></td>
            <td colspan="4">Desconto do item</td>
            <td>-{{ $formatMoney($item->discount) }}</td>
          </tr>
        @endif
      @endforeach
    </tbody>
  </table>

  <section class="summary">
    <div class="summary-row">
      <span>Qtde. total de itens</span>
      <strong>{{ $sale->items->count() }}</strong>
    </div>
    <div class="summary-row">
      <span>Valor total R$</span>
      <strong>{{ $formatMoney($sale->subtotal) }}</strong>
    </div>
    @if((float)$sale->discount_total>0)
      <div class="summary-row">
        <span>Desconto R$</span>
        <strong>-{{ $formatMoney($sale->discount_total) }}</strong>
      </div>
    @endif
    <div class="summary-row total">
      <span>VALOR A PAGAR R$</span>
      <strong>{{ $formatMoney($sale->total) }}</strong>
    </div>
  </section>

  <section class="section">
    <div class="section-title">FORMA DE PAGAMENTO</div>
    @foreach($sale->payments as $payment)
      <div class="payment-line">
        <span>{{ $paymentLabels[$payment->payment_method] ?? 'Outro' }}</span>
        <strong>
          {{ $formatMoney($payment->payment_method==='cash' ? $cashReceived : $payment->amount) }}
        </strong>
      </div>
    @endforeach

    @if($paymentIsCash)
      <div class="payment-line">
        <span>Troco R$</span>
        <strong>{{ $formatMoney($change) }}</strong>
      </div>
    @endif
  </section>

  <section class="section consumer">
    <div class="section-title">CONSUMIDOR</div>
    @if($sale->customer)
      <strong>{{ $sale->customer->name }}</strong><br>
      @if($sale->customer->document)
        CPF/CNPJ: {{ $sale->customer->document }}
      @endif
    @else
      CONSUMIDOR NÃO IDENTIFICADO
    @endif
  </section>

  <section class="section operation">
    <strong>VENDA Nº {{ str_pad((string)$sale->id,9,'0',STR_PAD_LEFT) }}</strong><br>
    {{ optional($sale->completed_at)->format('d/m/Y H:i:s') ?? now()->format('d/m/Y H:i:s') }}<br>
    @if($sale->user)
      Operador: {{ $sale->user->name }}
    @endif
  </section>

  @if($sale->notes)
    <section class="section">
      <div class="section-title">OBSERVAÇÃO</div>
      <div class="center">{{ $sale->notes }}</div>
    </section>
  @endif

  <div class="warning">
    COMPROVANTE NÃO FISCAL — SEM VALOR FISCAL<br>
    NÃO SUBSTITUI NFC-e, NF-e OU NFS-e
  </div>

  <footer class="footer">
    Documento interno gerado pelo {{ config('app.name','Nextor') }}.<br>
    Nenhuma autorização fiscal foi solicitada à SEFAZ.
  </footer>
</main>

<script>
(()=>{
  let automatic=true;

  window.addEventListener('load',()=>{
    window.setTimeout(()=>{
      if(automatic) window.print();
    },250);
  });

  window.addEventListener('afterprint',()=>{
    if(!automatic) return;
    automatic=false;
    window.setTimeout(()=>{
      window.location.replace(@json(route('pdv.index')));
    },120);
  });

  document.querySelector('.screen-actions button')?.addEventListener('click',()=>{
    automatic=false;
  });
})();
</script>
</body>
</html>
