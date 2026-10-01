<!DOCTYPE html>
@php
  $formatMoney=fn($value)=>number_format((float)$value,2,',','.');
  $formatQty=function($value){
      $formatted=number_format((float)$value,3,',','.');
      return rtrim(rtrim($formatted,'0'),',');
  };

  $paymentIsCash=$sale->payments->contains(fn($payment)=>$payment->payment_method==='cash');
  $discountRows=$sale->items->filter(fn($item)=>(float)$item->discount>0)->count();

  $issuerName=trim((string)config('fiscal.issuer_name',config('app.name','NEXTOR')));
  $issuerCnpj=trim((string)config('fiscal.issuer_cnpj',''));
  $issuerAddress=trim((string)config('fiscal.issuer_address',''));

  $consumerDocument=preg_replace('/\D+/','',(string)($sale->customer?->document ?? ''));
  $consumerDocumentLabel=strlen($consumerDocument)===11 ? 'CPF' : (strlen($consumerDocument)===14 ? 'CNPJ' : 'CPF/CNPJ');

  /*
   * Altura física da bobina para o Chromium. O DANFE NFC-e não possui
   * altura fixa; apenas a largura/margens são relevantes. Como o Chrome
   * não respeita "auto" no @page, reservamos altura suficiente por conteúdo.
   */
  $receiptHeightMm=
      185
      + ($sale->items->count()*9)
      + ($discountRows*5)
      + ($sale->payments->count()*6)
      + ($sale->customer ? 8 : 4)
      + ($sale->notes ? 16 : 0);

  $receiptHeightMm=max(205,min(650,$receiptHeightMm));
@endphp
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>DANFE NFC-e — modelo sem valor fiscal — venda #{{ $sale->id }}</title>
<style>
  *{box-sizing:border-box}
  html,body{margin:0;padding:0;background:#e7e9ec;color:#000;font-family:Arial,Helvetica,sans-serif}
  body{font-size:9px}

  .screen-actions{
    width:80mm;
    max-width:calc(100vw - 24px);
    margin:16px auto 10px;
    display:flex;
    gap:8px;
  }
  .screen-actions a,.screen-actions button{
    flex:1;
    min-height:38px;
    display:flex;
    align-items:center;
    justify-content:center;
    border:1px solid #cbd4dc;
    border-radius:5px;
    background:#fff;
    color:#29455e;
    font:600 11px Arial,sans-serif;
    text-decoration:none;
    cursor:pointer;
  }
  .screen-actions button{background:#2877c7;border-color:#2877c7;color:#fff}

  .danfe{
    width:80mm;
    max-width:100%;
    margin:0 auto 24px;
    padding:3mm 3mm 5mm;
    background:#fff;
  }

  .center{text-align:center}
  .dash-top{border-top:1px dashed #000}
  .section{padding:2.1mm 0}
  .section + .section{border-top:1px dashed #000}

  /* Divisão I — Cabeçalho */
  .issuer{
    text-align:center;
    line-height:1.35;
    padding-bottom:2mm;
  }
  .issuer-name{
    display:block;
    font-size:11px;
    font-weight:700;
    text-transform:uppercase;
  }
  .issuer-cnpj{
    display:block;
    margin-top:1px;
    font-size:8.5px;
  }
  .issuer-address{
    display:block;
    margin-top:1px;
    font-size:8px;
  }
  .official-title{
    margin-top:2mm;
    padding-top:2mm;
    border-top:1px dashed #000;
    font-size:9px;
    font-weight:700;
    line-height:1.25;
    text-transform:uppercase;
  }
  .non-fiscal-top{
    margin-top:1.5mm;
    padding:1.2mm 1mm;
    border:1px solid #000;
    font-size:10px;
    font-weight:800;
    line-height:1.25;
    text-align:center;
  }

  /* Divisão II — Detalhe da venda */
  .items{
    width:100%;
    border-collapse:collapse;
    table-layout:fixed;
  }
  .items th{
    padding:1.1mm .4mm .9mm;
    border-bottom:1px solid #000;
    font-size:6.5px;
    line-height:1.1;
    font-weight:700;
    text-align:right;
    vertical-align:bottom;
  }
  .items th:nth-child(1),.items th:nth-child(2){text-align:left}
  .items td{
    padding:1mm .4mm;
    border-bottom:1px dotted #888;
    font-size:7.2px;
    line-height:1.2;
    text-align:right;
    vertical-align:top;
    overflow-wrap:anywhere;
  }
  .items td:nth-child(1),.items td:nth-child(2){text-align:left}
  .col-code{width:14%}
  .col-description{width:32%}
  .col-qty{width:10%}
  .col-unit{width:8%}
  .col-unit-price{width:17%}
  .col-total{width:19%}
  .item-adjustment td{
    padding-top:.6mm;
    padding-bottom:.6mm;
    border-bottom:0;
    font-size:6.8px;
  }

  /* Divisão III — Totais */
  .totals{
    display:grid;
    gap:.6mm;
    font-size:8.3px;
  }
  .total-line,.payment-line{
    display:grid;
    grid-template-columns:minmax(0,1fr) auto;
    gap:5mm;
    align-items:baseline;
  }
  .total-line strong,.payment-line strong{
    text-align:right;
    white-space:nowrap;
  }
  .payable{
    margin-top:.8mm;
    font-size:10px;
    font-weight:700;
  }
  .payment-header{
    display:grid;
    grid-template-columns:minmax(0,1fr) auto;
    margin-top:2mm;
    padding-top:1.4mm;
    border-top:1px solid #000;
    font-size:7.2px;
    font-weight:700;
  }

  /* Divisão IV — Consulta por chave */
  .access-key{
    text-align:center;
    font-size:7.4px;
    line-height:1.4;
  }
  .access-key strong{
    display:block;
    margin-top:1mm;
    font-size:8px;
    letter-spacing:.02em;
  }
  .fiscal-placeholder{
    display:block;
    margin-top:1mm;
    font-weight:700;
    text-transform:uppercase;
  }

  /* Divisão VI — Consumidor */
  .consumer{
    text-align:center;
    font-size:7.6px;
    line-height:1.4;
  }
  .consumer-title{
    display:block;
    margin-bottom:.7mm;
    font-weight:700;
    text-transform:uppercase;
  }

  /* Divisão VII — Identificação */
  .identification{
    text-align:center;
    font-size:7.4px;
    line-height:1.45;
  }
  .nfce-line{
    font-weight:700;
  }
  .via{
    display:block;
    margin-top:.7mm;
    font-size:7px;
  }

  /* Divisão V + VII — QR e protocolo */
  .qr-protocol{
    display:grid;
    grid-template-columns:27mm minmax(0,1fr);
    align-items:center;
    gap:2.5mm;
  }
  .qr-placeholder{
    width:25mm;
    height:25mm;
    display:grid;
    place-items:center;
    margin:0 auto;
    border:1px solid #000;
    position:relative;
    text-align:center;
    font-size:6.5px;
    font-weight:700;
    line-height:1.25;
  }
  .qr-placeholder:before,.qr-placeholder:after{
    content:"";
    position:absolute;
    left:2mm;
    right:2mm;
    top:50%;
    height:1px;
    background:#000;
    transform-origin:center;
  }
  .qr-placeholder:before{transform:rotate(45deg)}
  .qr-placeholder:after{transform:rotate(-45deg)}
  .qr-placeholder span{
    position:relative;
    z-index:1;
    padding:1mm;
    background:#fff;
  }
  .protocol{
    font-size:7px;
    line-height:1.45;
  }
  .protocol strong{
    display:block;
    margin-bottom:.8mm;
    font-size:7.2px;
  }

  /* Divisão VIII e IX */
  .fiscal-message{
    text-align:center;
    font-size:7.3px;
    font-weight:700;
    line-height:1.4;
  }
  .contributor-message{
    font-size:7px;
    line-height:1.4;
    text-align:center;
  }
  .legal-tax{
    font-size:6.8px;
    line-height:1.35;
    text-align:center;
  }
  .final-warning{
    margin-top:1.8mm;
    padding:1.5mm 1mm;
    border:1px solid #000;
    font-size:8.5px;
    font-weight:800;
    line-height:1.35;
    text-align:center;
  }

  @page{
    size:80mm {{ $receiptHeightMm }}mm;
    margin:0;
  }

  @media print{
    html,body{
      width:80mm!important;
      min-width:80mm!important;
      max-width:80mm!important;
      min-height:{{ $receiptHeightMm }}mm!important;
      margin:0!important;
      padding:0!important;
      background:#fff!important;
    }
    body{overflow:visible!important}
    .screen-actions{display:none!important}
    .danfe{
      width:80mm!important;
      min-width:80mm!important;
      max-width:80mm!important;
      margin:0!important;
      padding:3mm 3mm 4mm!important;
      box-shadow:none!important;
      break-inside:avoid-page!important;
      page-break-inside:avoid!important;
    }
  }
</style>
</head>
<body>

<div class="screen-actions">
  <a href="{{ route('pdv.index') }}">Voltar ao PDV</a>
  <button type="button">Imprimir novamente</button>
</div>

<main class="danfe">
  {{-- DIVISÃO I — INFORMAÇÕES DO CABEÇALHO --}}
  <section class="issuer">
    <strong class="issuer-name">{{ $issuerName ?: 'EMITENTE NÃO CONFIGURADO' }}</strong>
    <span class="issuer-cnpj">
      CNPJ: {{ $issuerCnpj !== '' ? $issuerCnpj : 'NÃO CONFIGURADO' }}
    </span>
    <span class="issuer-address">
      {{ $issuerAddress !== '' ? $issuerAddress : 'ENDEREÇO DO EMITENTE NÃO CONFIGURADO' }}
    </span>

    <div class="official-title">
      Documento Auxiliar da Nota Fiscal de Consumidor Eletrônica
    </div>

    <div class="non-fiscal-top">
      SEM VALOR FISCAL<br>
      NFC-e NÃO EMITIDA
    </div>
  </section>

  {{-- DIVISÃO II — DETALHE DE PRODUTOS/SERVIÇOS --}}
  <section class="section">
    <table class="items" aria-label="Detalhe da venda">
      <thead>
        <tr>
          <th class="col-code">Código</th>
          <th class="col-description">Descrição</th>
          <th class="col-qty">Qtde</th>
          <th class="col-unit">UN</th>
          <th class="col-unit-price">Vl Unit</th>
          <th class="col-total">Vl Total</th>
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
            <td>{{ $formatMoney($item->line_total + $item->discount) }}</td>
          </tr>
          @if((float)$item->discount>0)
            <tr class="item-adjustment">
              <td></td>
              <td colspan="4">Desconto</td>
              <td>-{{ $formatMoney($item->discount) }}</td>
            </tr>
          @endif
        @endforeach
      </tbody>
    </table>
  </section>

  {{-- DIVISÃO III — TOTAIS --}}
  <section class="section totals">
    <div class="total-line">
      <span>Qtde. total de itens</span>
      <strong>{{ $sale->items->count() }}</strong>
    </div>
    <div class="total-line">
      <span>Valor total R$</span>
      <strong>{{ $formatMoney($sale->subtotal) }}</strong>
    </div>
    @if((float)$sale->discount_total>0)
      <div class="total-line">
        <span>Desconto R$</span>
        <strong>{{ $formatMoney($sale->discount_total) }}</strong>
      </div>
    @endif
    @if((float)$sale->discount_total>0)
      <div class="total-line payable">
        <span>Valor a Pagar R$</span>
        <strong>{{ $formatMoney($sale->total) }}</strong>
      </div>
    @endif

    <div class="payment-header">
      <span>FORMA PAGAMENTO</span>
      <span>VALOR PAGO R$</span>
    </div>

    @foreach($sale->payments as $payment)
      <div class="payment-line">
        <span>{{ $paymentLabels[$payment->payment_method] ?? 'Outro' }}</span>
        <strong>{{ $formatMoney($payment->payment_method==='cash' ? $cashReceived : $payment->amount) }}</strong>
      </div>
    @endforeach

    @if($paymentIsCash)
      <div class="payment-line">
        <span>Troco R$</span>
        <strong>{{ $formatMoney($change) }}</strong>
      </div>
    @endif
  </section>

  {{-- DIVISÃO IV — CONSULTA VIA CHAVE DE ACESSO --}}
  <section class="section access-key">
    Consulte pela Chave de Acesso em
    <strong>ENDEREÇO DE CONSULTA INDISPONÍVEL</strong>
    <span class="fiscal-placeholder">CHAVE DE ACESSO NÃO GERADA — NFC-e NÃO EMITIDA</span>
  </section>

  {{-- DIVISÃO VI — CONSUMIDOR --}}
  <section class="section consumer">
    <span class="consumer-title">CONSUMIDOR</span>
    @if($sale->customer)
      @if($sale->customer->document)
        {{ $consumerDocumentLabel }}: {{ $sale->customer->document }}
      @endif
      @if($sale->customer->name)
        {{ $sale->customer->document ? ' - ' : '' }}{{ $sale->customer->name }}
      @endif
    @else
      CONSUMIDOR NÃO IDENTIFICADO
    @endif
  </section>

  {{-- DIVISÃO VII — IDENTIFICAÇÃO DA NFC-e E PROTOCOLO --}}
  <section class="section identification">
    <div class="nfce-line">
      NFC-e nº — &nbsp;&nbsp; Série — &nbsp;&nbsp; Emissão —
    </div>
    <span class="via">Via Consumidor</span>
  </section>

  {{-- DIVISÃO V — QR CODE / DIVISÃO VII — PROTOCOLO --}}
  <section class="section qr-protocol">
    <div class="qr-placeholder" aria-label="QR Code não gerado">
      <span>QR CODE<br>NÃO GERADO</span>
    </div>
    <div class="protocol">
      <strong>Consulta via leitor de QR Code</strong>
      Protocolo de Autorização:<br>
      <b>NÃO GERADO</b><br><br>
      Data de autorização:<br>
      <b>NÃO AUTORIZADA</b>
    </div>
  </section>

  {{-- DIVISÃO VIII — MENSAGEM FISCAL --}}
  <section class="section fiscal-message">
    SEM VALOR FISCAL — NFC-e NÃO EMITIDA<br>
    NENHUMA AUTORIZAÇÃO FOI SOLICITADA À SEFAZ
  </section>

  {{-- DIVISÃO IX — MENSAGEM DE INTERESSE DO CONTRIBUINTE --}}
  <section class="section contributor-message">
    Venda interna nº {{ str_pad((string)$sale->id,9,'0',STR_PAD_LEFT) }}
    — {{ optional($sale->completed_at)->format('d/m/Y H:i:s') ?? now()->format('d/m/Y H:i:s') }}
    @if($sale->user)
      <br>Operador: {{ $sale->user->name }}
    @endif

    @if($sale->notes)
      <br>{{ $sale->notes }}
    @endif
  </section>

  <section class="section legal-tax">
    Tributos Totais Incidentes (Lei Federal 12.741/2012):
    não calculados neste comprovante sem valor fiscal.
  </section>

  <div class="final-warning">
    COMPROVANTE NÃO FISCAL — SEM VALOR FISCAL<br>
    NÃO SUBSTITUI NFC-e, NF-e OU NFS-e
  </div>
</main>

<script>
(()=>{
  let automatic=true;
  const printButton=document.querySelector('.screen-actions button');

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

  printButton?.addEventListener('click',()=>{
    automatic=false;
    window.print();
  });
})();
</script>
</body>
</html>
