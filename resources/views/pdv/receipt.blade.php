<!DOCTYPE html>
@php
  $formatMoney = fn($value) => number_format((float) $value, 2, ',', '.');
  $currencyPrefix = $company->show_currency_prefix ? 'R$ ' : '';
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
  $companyName = $company->trade_name ?: $company->legal_name ?: config('app.name','Lumeron');
  // A finalização da venda pertence ao caixa: nunca mudar automaticamente
  // para o módulo Fiscal após imprimir ou cancelar a impressão.
  // A situação da NFC-e é exibida no próprio PDV, sem supor autorização.
  $receiptAfterPrintUrl = route('pdv.index');
  if ($receiptOrigin === 'sales') {
      $receiptAfterPrintUrl = route('sales.show', $sale);
  }
  $canPrintAuthorizedDanfe = $nfceDocument?->status==='authorized'
      && (str_ends_with((string)$nfceDocument->xml_path,'authorized.xml')
          || str_ends_with((string)$nfceDocument->xml_path,'authorized-recovered.xml'));
  $canPrintOfflineDanfe = $nfceDocument?->emission_mode==='offline'
      && in_array($nfceDocument?->status,['offline_signed','offline_sending','pending'],true)
      && (string)$nfceDocument?->xml_path==='fiscal/nfce/'.$nfceDocument->id.'/signed.xml';
  $canPrintDanfe = $canPrintAuthorizedDanfe || $canPrintOfflineDanfe;
  $canPrepareNfce = auth()->user()->canAccess('fiscal')
      && $sale->status === 'completed'
      && $sale->operation_type === 'sale'
      && (bool) \App\Models\AppSetting::value('fiscal', 'enabled', false)
      && (bool) \App\Models\AppSetting::value('nfce', 'enabled', false);
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
<title>Comprovante de venda #{{ $sale->id }} — Lumeron</title>
<link rel="icon" type="image/png" href="{{ asset('images/lumeron/elephant.png') }}">
<link rel="stylesheet" href="{{ asset('css/ui-tokens.css') }}?v={{ filemtime(public_path('css/ui-tokens.css')) }}" media="screen">
<style>
*{box-sizing:border-box}
html,body{margin:0;padding:0;background:#eef1f4;color:#111;font-family:Arial,Helvetica,sans-serif}
body{font-size:9px}
.screen-actions{width:{{ $receiptWidth }}mm;max-width:calc(100vw - 24px);margin:16px auto 10px;display:flex;gap:8px}
.post-sale-status{max-width:460px;margin:0 auto 14px;padding:12px 14px;background:#fff;border:1px solid #d2dae4;border-radius:5px;font-size:12px;line-height:1.5}
.post-sale-status strong{display:block;margin-bottom:3px;font-size:13px}
.post-sale-status p{margin:3px 0 10px}
.post-sale-status form{margin:0}
.post-sale-status .receipt-alert{margin-bottom:8px;color:#8f260f;font-weight:700}

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
/* No módulo Vendas, o Chrome abre primeiro uma prévia legível.
   A impressão continua usando exatamente a largura térmica do PDV. */
@media screen {
  .sales-receipt-preview .screen-actions,
  .sales-receipt-preview .receipt,
  .pdv-receipt-embedded .receipt { width:min(460px,calc(100vw - 24px)); }
  .sales-receipt-preview .receipt,
  .pdv-receipt-embedded .receipt { max-width:calc(100vw - 24px);padding:20px;box-shadow:0 2px 14px rgba(24,36,46,.12); }
  .sales-receipt-preview .company-logo,.pdv-receipt-embedded .company-logo { max-height:86px; }
  .sales-receipt-preview .brand,.pdv-receipt-embedded .brand { font-size:17px; }
  .sales-receipt-preview .subtitle,.pdv-receipt-embedded .subtitle { font-size:13px; }
  .sales-receipt-preview .warning,.pdv-receipt-embedded .warning { font-size:11px; }
  .sales-receipt-preview .meta,
  .sales-receipt-preview .custom-header,
  .pdv-receipt-embedded .meta,.pdv-receipt-embedded .custom-header { font-size:11px; }
  .sales-receipt-preview .items th,.pdv-receipt-embedded .items th { font-size:11px; }
  .sales-receipt-preview .items td,.pdv-receipt-embedded .items td { font-size:11px; }
  .sales-receipt-preview .row,.pdv-receipt-embedded .row { font-size:12px; }
  .sales-receipt-preview .total,.pdv-receipt-embedded .total { font-size:15px; }
  .sales-receipt-preview .footer,.pdv-receipt-embedded .footer { font-size:10px; }
  .sales-receipt-preview .copy-label,.pdv-receipt-embedded .copy-label { font-size:10px; }
}
@media print{
  html,body{width:{{ $receiptWidth }}mm!important;min-width:{{ $receiptWidth }}mm!important;max-width:{{ $receiptWidth }}mm!important;background:#fff!important}
  .screen-actions,.post-sale-status{display:none!important}
  .receipt{width:{{ $receiptWidth }}mm!important;min-width:{{ $receiptWidth }}mm!important;max-width:{{ $receiptWidth }}mm!important;margin:0!important;padding:{{ $receiptWidth==='58'?'3':'4' }}mm!important;page-break-after:always}
  .receipt:last-of-type{page-break-after:auto}
}
@if($receiptPdfMode ?? false)
/* O Chrome mede a via no mesmo tamanho em tela e impressão.
   A altura da página PDF será ajustada ao conteúdo real, incluindo a logo. */
html,body{width:{{ $receiptWidth }}mm!important;min-width:{{ $receiptWidth }}mm!important;max-width:{{ $receiptWidth }}mm!important;background:#fff!important}
body.receipt-pdf-source .receipt,
body.receipt-pdf-source.sales-receipt-preview .receipt{
  width:{{ $receiptWidth }}mm!important;
  min-width:{{ $receiptWidth }}mm!important;
  max-width:{{ $receiptWidth }}mm!important;
  margin:0!important;
  padding:{{ $receiptWidth==='58'?'3':'4' }}mm!important;
  box-shadow:none!important;
}
body.receipt-pdf-source .receipt-copy+.receipt-copy{margin-top:0!important}
@endif
</style>
<link rel="stylesheet" href="{{ asset('css/ui-refinement.css') }}?v={{ filemtime(public_path('css/ui-refinement.css')) }}" media="screen">
<link rel="stylesheet" href="{{ asset('css/pdv-receipt-screen.css') }}?v={{ filemtime(public_path('css/pdv-receipt-screen.css')) }}" media="screen">
</head>
<body class="pdv-receipt-document {{ $receiptOrigin==='sales' ? 'sales-receipt-preview' : '' }}{{ request()->boolean('embed') ? ' pdv-receipt-embedded' : '' }}{{ ($receiptPdfMode ?? false) ? ' receipt-pdf-source' : '' }}">
@if(!($receiptPdfMode ?? false) && !request()->boolean('embed'))
<div class="screen-actions">
  @if($receiptOrigin==='sales')
    <a class="btn btn-secondary" href="{{ route('sales.show',$sale) }}">Voltar à venda</a>
  @else
  <a class="btn btn-secondary" href="{{ route('pdv.index') }}">Voltar ao PDV</a>
  @if($nfceDocument && auth()->user()->canAccess('fiscal'))
    <a class="btn btn-secondary" href="{{ route('fiscal.show', $nfceDocument) }}">NFC-e {{ $nfceDocument->status==='authorized' ? 'autorizada' : 'da venda' }}</a>
  @endif
  @if($canPrintDanfe)
    <a class="btn btn-secondary" href="{{ route('pdv.nfce.danfe',$nfceDocument) }}" target="_blank" rel="noopener noreferrer">
      {{ $canPrintOfflineDanfe ? 'DANFE contingência (2 vias)' : 'Imprimir DANFE NFC-e' }}
    </a>
  @endif
  @endif
  <button class="btn btn-primary" type="button" data-reprint-receipt>Imprimir comprovante interno</button>
</div>
@if($receiptOrigin!=='sales')
<section class="post-sale-status" aria-live="polite">
  @if(session('error'))
    <div class="receipt-alert">{{ session('error') }}</div>
  @endif
  @if($nfceDocument)
    <strong>NFC-e da venda #{{ $sale->id }}: {{ match($nfceDocument->status){
      'prepared'=>'preparada para revisão','processing'=>'em processamento',
      'pending'=>'aguardando confirmação','authorized'=>'autorizada',
      'rejected'=>'rejeitada','cancelled'=>'cancelada',
      'offline_signed'=>'em contingência','offline_print_pending'=>'DANFE de contingência pendente',
      'offline_sending'=>'contingência aguardando autorização',
      'failed','error'=>'requer atenção',default=>'situação a verificar',
    } }}</strong>
    <p>A venda foi concluída e o documento fiscal está vinculado a ela. Cancelar a impressão não altera a NFC-e.</p>
    @if($canPrintDanfe)
      <p><a class="action btn btn-primary" href="{{ route('pdv.nfce.danfe',$nfceDocument) }}" target="_blank" rel="noopener noreferrer">
        {{ $canPrintOfflineDanfe ? 'Imprimir DANFE em contingência (2 vias; pendente de autorização)' : 'Imprimir DANFE NFC-e autorizado' }}
      </a></p>
    @endif
    @if(auth()->user()->canAccess('fiscal'))
      <a class="action btn btn-primary" href="{{ route('fiscal.show', $nfceDocument) }}">Abrir NFC-e da venda</a>
    @else
      <p>Solicite a um usuário com permissão fiscal que revise o documento.</p>
    @endif
  @else
    <strong>Venda #{{ $sale->id }} concluída — NFC-e pendente</strong>
    <p>O estoque e o financeiro desta venda já foram registrados. Não registre outra venda para tentar gerar a nota.</p>
    @if($canPrepareNfce)
      <form method="post" action="{{ route('pdv.receipt.nfce.prepare', $sale) }}">
        @csrf
        <button class="btn btn-primary" type="submit">Preparar NFC-e desta venda</button>
      </form>
    @else
      <p>Confira se os módulos Fiscal e NFC-e estão habilitados. Para preparar o documento, também é necessária permissão fiscal.</p>
      @if(auth()->user()->canAccess('settings'))
        <a class="action btn btn-primary" href="{{ route('settings.index', ['tab'=>'fiscal']) }}">Conferir configurações fiscais</a>
      @endif
    @endif
  @endif
</section>
@endif
@endif

@for($copy=1;$copy<=$receiptCopies;$copy++)
<main class="receipt receipt-copy">
  <header class="center">
    @if($company->logo_path)
      <img class="company-logo" src="{{ $receiptPdfLogo ?? asset('storage/'.$company->logo_path) }}" alt="">
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
    <div class="row"><span>Subtotal</span><strong>{{ $currencyPrefix }}{{ $formatMoney($sale->subtotal) }}</strong></div>
    @if((float) $sale->discount_total > 0)
      <div class="row"><span>Desconto</span><strong>- {{ $currencyPrefix }}{{ $formatMoney($sale->discount_total) }}</strong></div>
    @endif
    <div class="row total"><span>Total</span><strong>{{ $currencyPrefix }}{{ $formatMoney($sale->total) }}</strong></div>
  </section>

  <section class="section">
    @foreach($sale->payments as $payment)
      @php($isCash=($paymentKinds[$payment->payment_method] ?? null)==='cash')
      <div class="row">
        <span>{{ $paymentLabels[$payment->payment_method] ?? 'Outro' }}</span>
        <strong>{{ $currencyPrefix }}{{ $formatMoney($payment->amount) }}</strong>
      </div>
    @endforeach
    @if($paymentIsCash)
      <div class="row"><span>Recebido em dinheiro</span><strong>{{ $currencyPrefix }}{{ $formatMoney($cashReceived) }}</strong></div>
      <div class="row"><span>Troco</span><strong>{{ $currencyPrefix }}{{ $formatMoney($change) }}</strong></div>
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

@if($receiptPdfMode ?? false)
<script id="receipt-paper-fit">
(() => {
  // O cálculo por número de itens não considera a altura real da logo,
  // quebras de linha e observações. Medir o recibo evita folhas extras.
  const adjustPaper = () => {
    const copies=[...document.querySelectorAll('main.receipt-copy')];
    if (!copies.length) return;
    const pxPerMillimeter=96/25.4;
    const heightPx=Math.max(...copies.map(copy => copy.getBoundingClientRect().height));
    if (!Number.isFinite(heightPx) || heightPx<=0) return;
    const heightMm=Math.max(50,Math.ceil(heightPx/pxPerMillimeter+4));
    let paper=document.getElementById('receipt-exact-paper');
    if (!paper) {
      paper=document.createElement('style');
      paper.id='receipt-exact-paper';
      document.head.appendChild(paper);
    }
    paper.textContent='@page{size:{{ $receiptWidth }}mm '+heightMm+'mm;margin:0}';
  };
  // O segundo cálculo inclui imagens incorporadas que terminam de carregar.
  adjustPaper();
  window.addEventListener('load',adjustPaper,{once:true});
})();
</script>
@endif

@if(!($receiptPdfMode ?? false) && !request()->boolean('embed'))
<script>
(()=>{
  // Venda comum: sempre visualizar primeiro no Chrome. Apenas o clique
  // explícito em Imprimir abre o diálogo. O PDV conserva seu fluxo automático.
  let automatic=@json($receiptOrigin==='pdv' && request()->query('print') !== '0');
  const afterPrintUrl=@json($receiptAfterPrintUrl);
  const salesReceipt=@json($receiptOrigin==='sales');
  const printButton=document.querySelector('[data-reprint-receipt]');
  window.addEventListener('load',()=>window.setTimeout(()=>{if(automatic) window.print();},250));
  window.addEventListener('afterprint',()=>{
    if(!automatic) return;
    automatic=false;
    if(salesReceipt) return; // Na venda comum, manter o comprovante aberto.
    // Retornar sempre à frente de caixa: abrir a NFC-e é escolha explícita.
    window.setTimeout(()=>window.location.assign(afterPrintUrl),150);
  });
  printButton?.addEventListener('click',()=>{automatic=false;window.print();});
})();
</script>
@endif
</body>
</html>
