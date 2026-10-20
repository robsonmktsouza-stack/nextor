<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Orçamento {{ str_pad((string)$quote->id,5,'0',STR_PAD_LEFT) }}</title>
<style>
/* Reprodução do modelo A4 aprovado no Excel do proprietário (20/10/2026).
   Não importar CSS do painel: este documento possui identidade de impressão. */
@page { size:A4; margin:20mm 3mm 14mm; }
* { box-sizing:border-box; }
html,body { margin:0; padding:0; }
body { font:10px/1.4 Calibri,Arial,Helvetica,sans-serif; color:#2e373b; background:#fff; }
table { width:100%; border-collapse:collapse; }
strong { font-weight:700; }
.right { text-align:right!important; font-variant-numeric:tabular-nums; }
.center { text-align:center!important; }
.no-break { page-break-inside:avoid; break-inside:avoid; }
.header { table-layout:fixed; }
.header td { vertical-align:middle; }
.logo-cell { width:110px; padding:0 7px 0 0; }
.logo-box { width:102px; height:73px; display:flex; align-items:center; justify-content:center;
    border:1px solid #d8dfdf; background:#f3f6f6; }
.logo-box img { display:block; width:auto; height:auto; max-width:97px; max-height:67px; object-fit:contain; }
.company { padding:0 12px 0 0; vertical-align:middle; }
.company-name { font-size:14px; font-weight:700; line-height:1.2; margin:0 0 13px; }
.company-details { font-size:9px; color:#617078; line-height:1.55; overflow-wrap:anywhere; }
.document { width:202px; text-align:right; white-space:nowrap; vertical-align:top!important; padding-top:3px; }
.document-kind { font-size:10px; font-weight:700; color:#477b78; margin:0 0 6px; }
.document-number { font-size:23px; font-weight:700; color:#2e5555;
    line-height:1.05; font-variant-numeric:tabular-nums; }
.document-dates { margin-top:9px; font-size:9px; color:#617078; white-space:normal; }
.accent-band { height:11px; background:#477b78; margin:11px 0 5px; }
.proposal-line { padding:1px 0 6px; font-size:8.5px; color:#617078; text-transform:uppercase; }
.section-title { background:#e9eff0; color:#2e5555; font-size:10px;
    line-height:1.25; font-weight:700; text-transform:uppercase; margin:0;
    padding:5px 3px; }
.customer-section { margin-top:0; }
.info { table-layout:fixed; }
.info td { vertical-align:top; padding:5px 4px 8px 0; overflow-wrap:anywhere; }
.info .label { display:block; font-size:8.5px; color:#617078; font-weight:700;
    text-transform:uppercase; margin-bottom:5px; line-height:1.15; }
.info .value { font-size:10px; color:#2e373b; line-height:1.45; white-space:pre-wrap; }
.info .name { font-weight:500; }
.info .muted { color:#617078; }
.info .spacer { padding-left:12px; }
.info td:nth-child(3) { padding-left:16px; }
.info .wide { width:47%; }
.info .medium { width:27%; }
.info .narrow { width:26%; }
.items-section { margin-top:10px; }
.items { table-layout:fixed; }
.items thead { display:table-header-group; }
.items th { background:#477b78; color:#fff; font-size:9px; font-weight:700;
    padding:7px 3px; line-height:1.2; text-align:left; white-space:nowrap; }
.items th:nth-child(1) { width:4%; }
.items th:nth-child(2) { width:7%; }
.items th:nth-child(3) { width:10%; }
.items th:nth-child(4) { width:36%; }
.items th:nth-child(5) { width:5%; }
.items th:nth-child(6) { width:7%; }
.items th:nth-child(7) { width:10%; }
.items th:nth-child(8) { width:9%; }
.items th:nth-child(9) { width:12%; }
.items tbody tr { height:21pt; page-break-inside:avoid; break-inside:avoid; }
.items tbody tr:nth-child(even) { background:#fafbfb; }
.items td { padding:6px 4px; border-bottom:1px solid #d8dddd;
    font-size:9.3px; line-height:1.35; vertical-align:top; overflow-wrap:anywhere; }
.items td:first-child,.items td:nth-child(2) { color:#617078; }
.item-name { font-weight:400; }
.item-note { display:block; color:#617078; margin-top:3px; font-size:8.7px; white-space:pre-wrap; }
.number-cell { text-align:right; white-space:nowrap; font-variant-numeric:tabular-nums; }
.tail { display:table; width:100%; table-layout:fixed; margin-top:12px; }
.tail-left,.tail-right { display:table-cell; vertical-align:top; }
.tail-left { width:58%; padding-right:10px; }
.tail-right { width:42%; padding-left:2px; }
.tail .section-title { margin-bottom:3px; }
.conditions { font-size:9.4px; line-height:1.5; }
.conditions p { margin:4px 0; overflow-wrap:anywhere; }
.totals { table-layout:fixed; }
.totals td { padding:5px 5px; font-size:9.5px; border:1px solid #e4e8e8; }
.totals td:first-child { text-align:right; width:60%; color:#617078; }
.totals td:last-child { text-align:right; font-weight:700; font-variant-numeric:tabular-nums; }
.totals .total td { background:#e9eff0; border-color:#e9eff0; padding:12px 5px;
    font-size:13px; font-weight:700; color:#2e5555; }
.notes { margin-top:12px; }
.notes .section-title { margin-bottom:4px; }
.notes p { margin:5px 1px; font-size:9.5px; line-height:1.5;
    white-space:pre-wrap; overflow-wrap:anywhere; }
.signature-row { display:table; width:100%; table-layout:fixed; margin-top:13mm;
    page-break-inside:avoid; break-inside:avoid; }
.signature { display:table-cell; width:50%; text-align:center; padding:0 15px; }
.signature-line { width:77%; margin:0 auto 5px; height:1px; border-top:1px solid #8e999a; }
.signature-label { font-size:9px; color:#617078; }
.footer { position:static; margin-top:10mm; padding-top:5px; border-top:1px solid #d9dfdf;
    text-align:center; color:#8a9a9b; font-size:8px; page-break-inside:avoid; break-inside:avoid; }
@media print { body { -webkit-print-color-adjust:exact; print-color-adjust:exact; } }
</style>
</head>
<body>
@php
$number=str_pad((string)$quote->id,6,'0',STR_PAD_LEFT);
$money=fn($value)=>'R$ '.number_format((float)$value,2,',','.');
$numberOnly=fn($value)=>number_format((float)$value,2,',','.');
$qty=fn($value)=>rtrim(rtrim(number_format((float)$value,3,',','.'),'0'),',');
$companyName=$company->trade_name ?: ($company->legal_name ?: 'Empresa');
$companyAddress=implode(', ',array_filter([$company->address,$company->address_number,$company->address_complement]));
$companyCity=implode(' · ',array_filter([$company->district,implode('/',array_filter([$company->city,$company->state]))]));
$customer=$quote->customer;
$customerAddress=implode(', ',array_filter([$customer?->address,$customer?->address_number,$customer?->address_complement]));
$customerCity=implode(' · ',array_filter([$customer?->district,implode('/',array_filter([$customer?->city,$customer?->state]))]));
$customerContact=implode(' · ',array_filter([$customer?->contact_name,$customer?->phone]));
$customerAddressFull=implode(' · ',array_filter([$customerAddress,$customerCity]));
$validDate=$quote->quote_expires_at?->format('d/m/Y');
$validDays=($quote->operation_date && $quote->quote_expires_at)
    ? (int)$quote->operation_date->copy()->startOfDay()
        ->diffInDays($quote->quote_expires_at->copy()->startOfDay(),false)
    : null;
$paymentCodes=$quote->payments->pluck('payment_method')->filter()->unique()->values();
$paymentCatalog=$paymentCodes->isNotEmpty()
    ? \App\Models\PaymentMethod::query()->whereIn('code',$paymentCodes->all())->pluck('name','code')
    : collect();
$paymentTerms=$paymentCodes->map(fn($code)=>$paymentCatalog->get($code) ?? $code)->implode(', ') ?: 'A combinar';
$createdDate=($quote->operation_date ?? $quote->created_at)->format('d/m/Y');
$expired=$quote->quote_expires_at && $quote->quote_expires_at->copy()->startOfDay()->lt(today());
$state=match(true) {
    $quote->status==='cancelled'=>'Cancelado',
    $quote->status==='converted'=>'Convertido em venda',
    $expired=>'Vencido',
    default=>'Aguardando aprovação',
};
$productSubtotal=$quote->items->where('item_type','!=','service')
    ->sum(fn($item)=>(float)$item->line_total+(float)$item->discount);
$serviceSubtotal=$quote->items->where('item_type','service')
    ->sum(fn($item)=>(float)$item->line_total+(float)$item->discount);
@endphp

<header class="no-break">
<table class="header">
<tr>
  @if($logo)
    <td class="logo-cell">
      <div class="logo-box"><img src="{{ $logo }}" alt="Logomarca da empresa"></div>
    </td>
  @endif
  <td class="company">
    <div class="company-name">{{ $companyName }}</div>
    @if($company->legal_name && $company->legal_name!==$companyName)
      <div class="company-details">{{ $company->legal_name }}</div>
    @endif
    <div class="company-details">
      @if($company->document)CNPJ {{ $company->document }}@endif
      @if($company->state_registration) · IE {{ $company->state_registration }}@endif
      @if($companyAddress || $companyCity)
        <div>{{ implode(' · ',array_filter([$companyAddress,$companyCity])) }}</div>
      @endif
      @if($company->phone || $company->email)
        <div>{{ implode(' · ',array_filter([$company->phone,$company->email])) }}</div>
      @endif
    </div>
  </td>
  <td class="document">
    <div class="document-kind">ORÇAMENTO COMERCIAL</div>
    <div class="document-number">Nº {{ $number }}</div>
    <div class="document-dates">Emissão {{ $createdDate }} · Válido até {{ $validDate ?: 'a combinar' }}</div>
  </td>
</tr>
</table>
</header>

<div class="accent-band"></div>
<div class="proposal-line">PROPOSTA DE FORNECIMENTO | PRODUTOS E SERVIÇOS</div>

<section class="customer-section no-break">
  <h2 class="section-title">Dados do cliente</h2>
  <table class="info">
    <colgroup><col class="wide"><col class="medium"><col class="narrow"></colgroup>
    <tr>
      <td><span class="label">Cliente / Razão social</span>
          <span class="value name">{{ $customer?->name ?: ($quote->consumer_name ?: 'Cliente não identificado') }}</span></td>
      <td class="spacer"><span class="label">CPF / CNPJ</span>
          <span class="value">{{ $customer?->document ?: ($quote->consumer_document ?: '—') }}</span></td>
      <td><span class="label">Contato</span><span class="value">{{ $customerContact ?: '—' }}</span></td>
    </tr>
    <tr>
      <td colspan="2"><span class="label">Endereço de entrega / atendimento</span>
          <span class="value">{{ $customerAddressFull ?: '—' }}</span></td>
      <td><span class="label">Validade da proposta</span>
          <span class="value">{{ $validDate ?: 'A combinar' }}@if($validDate && $validDays!==null && $validDays>=0) ({{ $validDays }} {{ $validDays===1 ? 'dia' : 'dias' }})@endif</span></td>
    </tr>
    <tr>
      <td><span class="label">Responsável comercial</span><span class="value">{{ $quote->user?->name ?? '—' }}</span></td>
      <td class="spacer"><span class="label">Forma de pagamento</span><span class="value">{{ $paymentTerms }}</span></td>
      <td><span class="label">Situação</span><span class="value">{{ $state }}</span></td>
    </tr>
  </table>
</section>

<section class="items-section">
  <h2 class="section-title">Discriminação dos itens</h2>
  <table class="items">
    <thead>
      <tr>
        <th>Item</th><th>Tipo</th><th>Código</th><th>Descrição do produto / serviço</th>
        <th class="center">Un.</th><th class="right">Qtd.</th>
        <th class="right">Vlr. unit.</th><th class="right">Desconto</th>
        <th class="right">Valor total</th>
      </tr>
    </thead>
    <tbody>
      @foreach($quote->items as $item)
      <tr>
        <td class="center">{{ str_pad((string)$loop->iteration,2,'0',STR_PAD_LEFT) }}</td>
        <td>{{ $item->item_type==='service' ? 'SERV.' : 'PROD.' }}</td>
        <td>{{ $item->product_sku ?: '—' }}</td>
        <td><span class="item-name">{{ $item->product_name }}</span>
          @if($item->notes)<span class="item-note">{{ $item->notes }}</span>@endif
        </td>
        <td class="center">{{ $item->item_type==='service' ? 'SV' : ($item->product?->unit ?: 'UN') }}</td>
        <td class="number-cell">{{ $qty($item->quantity) }}</td>
        <td class="number-cell">{{ $numberOnly($item->unit_price) }}</td>
        <td class="number-cell">{{ $numberOnly($item->discount) }}</td>
        <td class="number-cell">{{ $numberOnly($item->line_total) }}</td>
      </tr>
      @endforeach
    </tbody>
  </table>
</section>

<section class="tail no-break">
  <div class="tail-left">
    <h2 class="section-title">Condições comerciais</h2>
    <div class="conditions">
      <p>Pagamento: {{ $paymentTerms }}.</p>
      <p>Prazo de entrega / execução: a combinar.</p>
      <p>Disponibilidade sujeita à confirmação.</p>
      @if($validDate)<p>Preços e condições válidos até {{ $validDate }}.</p>@endif
    </div>
  </div>
  <div class="tail-right">
    <h2 class="section-title">Resumo financeiro</h2>
    <table class="totals">
      <tr><td>Produtos</td><td>{{ $money($productSubtotal) }}</td></tr>
      <tr><td>Serviços</td><td>{{ $money($serviceSubtotal) }}</td></tr>
      <tr><td>Descontos</td><td>– {{ $money($quote->discount_total) }}</td></tr>
      <tr class="total"><td>TOTAL GERAL</td><td>{{ $money($quote->total) }}</td></tr>
    </table>
  </div>
</section>

<section class="notes no-break">
  <h2 class="section-title">Observações / informações adicionais</h2>
  @if($quote->notes)<p>{{ $quote->notes }}</p>@endif
  @if($company->print_footer)<p>{{ $company->print_footer }}</p>@endif
  @if(!$quote->notes && !$company->print_footer)<p>—</p>@endif
</section>

<section class="signature-row">
  <div class="signature"><div class="signature-line"></div>
    <div class="signature-label">Responsável pela proposta</div></div>
  <div class="signature"><div class="signature-line"></div>
    <div class="signature-label">Aceite do cliente</div></div>
</section>

<footer class="footer">Orçamento comercial — não é documento fiscal e não comprova pagamento.</footer>
</body>
</html>