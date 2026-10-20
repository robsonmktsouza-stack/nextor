<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Orçamento {{ str_pad((string)$quote->id,5,'0',STR_PAD_LEFT) }}</title>
<style>
/* Orçamento impresso de ERP: legível, tabular e sem elementos promocionais.
   Escopo exclusivo deste PDF. Nenhum estilo do painel é carregado aqui. */
@page { size:A4; margin:13mm 13mm 20mm; }
* { box-sizing:border-box; }
html,body { margin:0; padding:0; }
body { font:10.5px/1.42 Arial,Helvetica,sans-serif; color:#272c2f; background:#fff;
  -webkit-font-smoothing:antialiased; }
table { border-collapse:collapse; width:100%; }
strong { font-weight:700; }
.header { margin-bottom:14px; padding-bottom:12px; border-bottom:2px solid #667780; }
.header td { vertical-align:top; }
.company-logo { width:115px; padding:2px 14px 0 0; }
.company-logo img { display:block; max-width:104px; max-height:56px; object-fit:contain; }
.company { padding-right:15px; }
.company-name { font-size:16px; line-height:1.22; font-weight:700; color:#263238; margin:0 0 5px; }
.company-legal { font-size:9.5px; color:#4b565b; margin-bottom:4px; }
.company-detail { font-size:9.5px; line-height:1.5; color:#48545a; overflow-wrap:anywhere; }
.document { width:181px; border-left:1px solid #bdc8ca; padding:2px 0 0 14px; }
.document-name { font-size:17px; line-height:1.1; font-weight:700; letter-spacing:.35px; margin-bottom:2px; color:#3d6768; }
.document-number { font-size:23px; line-height:1.1; font-weight:700; margin-bottom:9px;
  font-variant-numeric:tabular-nums; }
.document-line { display:table; width:100%; font-size:9.5px; padding:3px 0;
  border-bottom:1px solid #e7eaec; }
.document-line:last-child { border-bottom:0; }
.document-line span { display:table-cell; width:60px; color:#556168; }
.document-line strong { display:table-cell; color:#242e33; }
.section-title { font-size:11px; line-height:1.3; font-weight:700;
  letter-spacing:.18px; text-transform:uppercase; color:#35494b;
  background:#f1f3f3; border-left:3px solid #558983;
  border-bottom:1px solid #c8d1d2; margin:15px 0 0; padding:7px 9px; }
.info { margin:0 0 13px; border:1px solid #c6cfd0; border-top:0; table-layout:fixed; }
.info td { width:50%; padding:7px 9px 8px; vertical-align:top;
  border-right:1px solid #d7dde0; border-bottom:1px solid #e2e6e8; overflow-wrap:anywhere; }
.info tr:last-child td { border-bottom:0; }
.info td:last-child { border-right:0; }
.label { display:block; margin-bottom:3px; font-size:9px; line-height:1.2; color:#667078; }
.value { font-size:10.5px; font-weight:700; line-height:1.4; white-space:pre-wrap; }
.value.plain { font-weight:400; color:#343d43; }
.items { table-layout:fixed; border:1px solid #b3bec4; margin-top:0; }
.items thead { display:table-header-group; }
.items tbody tr { page-break-inside:avoid; break-inside:avoid; }
.items tbody tr:nth-child(even) { background:#fafbfb; }
.items th { background:#4f7d7c; color:#fff; border-bottom:1px solid #456f70;
  border-right:1px solid rgba(255,255,255,.22); padding:8px 6px; font-size:9px; font-weight:700;
  line-height:1.3; text-align:left; text-transform:uppercase; letter-spacing:.1px; }
.items td { padding:8px 6px; border-right:1px solid #e0e5e7;
  border-bottom:1px solid #dfe4e7; vertical-align:top; overflow-wrap:anywhere; }
.items tbody tr:last-child td { border-bottom:0; }
.items th:last-child,.items td:last-child { border-right:0; }
.items th:nth-child(1) { width:5%; }
.items th:nth-child(2) { width:12%; }
.items th:nth-child(3) { width:31%; }
.items th:nth-child(4) { width:6%; }
.items th:nth-child(5) { width:9%; }
.items th:nth-child(6) { width:13%; }
.items th:nth-child(7) { width:11%; }
.items th:nth-child(8) { width:13%; }
.right { text-align:right!important; white-space:nowrap; font-variant-numeric:tabular-nums; }
.center { text-align:center!important; }
.item-name { font-weight:600; color:#293138; line-height:1.35; }
.item-kind { display:inline; font-size:9px; color:#647578; font-weight:400; }
.item-kind::after { content:'  ·  '; }
.item-note { display:block; color:#667076; margin-top:4px; font-size:9.5px;
  line-height:1.35; white-space:pre-wrap; }
.totals { width:303px; margin:13px 0 0 auto; border:1px solid #c1cacf;
  break-inside:avoid; page-break-inside:avoid; font-variant-numeric:tabular-nums; }
.totals td { padding:5px 9px; border-bottom:1px solid #e1e5e7; }
.totals td:first-child { color:#4a555a; }
.totals td:last-child { font-weight:600; width:130px; text-align:right; white-space:nowrap; }
.totals .total td { padding:10px 9px; border-top:2px solid #558983; border-bottom:0;
  background:#e9edef; font-size:12.5px; font-weight:700; color:#263037; }
.notes { margin-top:18px; break-inside:avoid; page-break-inside:avoid; }
.notes .section-title { margin-bottom:7px; }
.notes p { margin:5px 0; font-size:10px; line-height:1.48; white-space:pre-wrap;
  overflow-wrap:anywhere; }
.footer { position:fixed; bottom:-11mm; left:0; right:0; border-top:1px solid #b1b9be;
  padding-top:6px; color:#6b7479; font-size:9px; line-height:1.35; }
@media print {
  body { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
}
</style>
</head>
<body>
@php
$money=fn($value)=>'R$ '.number_format((float)$value,2,',','.');
$companyName=$company->trade_name ?: ($company->legal_name ?: 'Empresa');
$address=implode(', ',array_filter([$company->address,$company->address_number,$company->address_complement]));
$location=implode(' - ',array_filter([$company->district,implode(' / ',array_filter([$company->city,$company->state]))]));
$customer=$quote->customer;
// Valores de exibição, calculados a partir das linhas já gravadas.
// Preços/descontos do orçamento não são recalculados nem persistidos.
$productSubtotal=$quote->items->filter(fn($item)=>$item->item_type!=='service')
    ->sum(fn($item)=>(float)$item->line_total+(float)$item->discount);
$serviceSubtotal=$quote->items->filter(fn($item)=>$item->item_type==='service')
    ->sum(fn($item)=>(float)$item->line_total+(float)$item->discount);
$hasProducts=$quote->items->contains(fn($item)=>$item->item_type!=='service');
$hasServices=$quote->items->contains(fn($item)=>$item->item_type==='service');
$customerAddress=implode(', ',array_filter([$customer?->address,$customer?->address_number,$customer?->address_complement]));
$customerLocation=implode(' - ',array_filter([$customer?->district,implode(' / ',array_filter([$customer?->city,$customer?->state]))]));
$expired=$quote->quote_expires_at && $quote->quote_expires_at->copy()->startOfDay()->lt(today());
$state=match(true) {
    $quote->status==='cancelled'=>'Cancelado',
    $quote->status==='converted'=>'Convertido em venda',
    $expired=>'Vencido',
    default=>'Em aberto',
};
@endphp
<header class="header">
<table><tr>
@if($logo)
<td class="company-logo"><img src="{{ $logo }}" alt="Logomarca da empresa"></td>
@endif
<td class="company">
  <div class="company-name">{{ $companyName }}</div>
  @if($company->legal_name && $company->legal_name!==$companyName)
    <div class="company-legal">{{ $company->legal_name }}</div>
  @endif
  <div class="company-detail">
    @if($company->document)CNPJ: {{ $company->document }}@endif
    @if($address)<div>{{ $address }}</div>@endif
    @if($location)<div>{{ $location }}@if($company->zip_code) | CEP {{ $company->zip_code }}@endif</div>@endif
    @if($company->phone || $company->email)
      <div>
        @if($company->phone){{ $company->phone }}@endif
        @if($company->phone && $company->email) | @endif
        @if($company->email){{ $company->email }}@endif
      </div>
    @endif
  </div>
</td>
<td class="document">
  <div class="document-name">ORÇAMENTO</div>
  <div class="document-number">Nº {{ str_pad((string)$quote->id,5,'0',STR_PAD_LEFT) }}</div>
  <div class="document-line"><span>Emissão</span><strong>{{ ($quote->operation_date ?? $quote->created_at)->format('d/m/Y') }}</strong></div>
  <div class="document-line"><span>Validade</span><strong>{{ $quote->quote_expires_at?->format('d/m/Y') ?? 'A combinar' }}</strong></div>
  <div class="document-line"><span>Situação</span><strong>{{ $state }}</strong></div>
</td>
</tr></table>
</header>
<section>
  <h2 class="section-title">Dados do cliente</h2>
  <table class="info">
  <tr>
    <td><span class="label">Nome / Razão social</span><span class="value">{{ $customer?->name ?: ($quote->consumer_name ?: 'Cliente não identificado') }}</span></td>
    <td><span class="label">CPF / CNPJ</span><span class="value plain">{{ $customer?->document ?: ($quote->consumer_document ?: '—') }}</span></td>
  </tr>
  <tr>
    <td><span class="label">Endereço</span><span class="value plain">{{ $customerAddress ?: '—' }}</span></td>
    <td><span class="label">Bairro / Município / UF</span><span class="value plain">{{ $customerLocation ?: '—' }}</span></td>
  </tr>
  <tr>
    <td><span class="label">Telefone / E-mail</span><span class="value plain">
      {{ implode(' | ',array_filter([$customer?->phone,$customer?->email])) ?: '—' }}
    </span></td>
    <td><span class="label">Vendedor / Responsável</span><span class="value plain">{{ $quote->user?->name ?? '—' }}</span></td>
  </tr>
  </table>
</section>
<section>
  <h2 class="section-title">Itens do orçamento</h2>
  <table class="items">
    <thead>
      <tr>
        <th>Item</th><th>Código</th><th>Descrição</th><th class="center">Un.</th>
        <th class="right">Qtde.</th><th class="right">Vlr. unit.</th>
        <th class="right">Desconto</th><th class="right">Valor total</th>
      </tr>
    </thead>
    <tbody>
    @foreach($quote->items as $item)
      <tr>
        <td class="center">{{ $loop->iteration }}</td>
        <td>{{ $item->product_sku ?: '—' }}</td>
        <td>
          <span class="item-kind">{{ $item->item_type==='service' ? 'Serviço' : 'Produto' }}</span><span class="item-name">{{ $item->product_name }}</span>
          @if($item->notes)<span class="item-note">{{ $item->notes }}</span>@endif
        </td>
        <td class="center">{{ $item->item_type==='service' ? 'SV' : ($item->product?->unit ?: 'UN') }}</td>
        <td class="right">{{ number_format((float)$item->quantity,3,',','.') }}</td>
        <td class="right">{{ $money($item->unit_price) }}</td>
        <td class="right">{{ $money($item->discount) }}</td>
        <td class="right"><strong>{{ $money($item->line_total) }}</strong></td>
      </tr>
    @endforeach
    </tbody>
  </table>
  <table class="totals">
    @if($hasProducts && $hasServices)
      <tr><td>Produtos</td><td>{{ $money($productSubtotal) }}</td></tr>
      <tr><td>Serviços</td><td>{{ $money($serviceSubtotal) }}</td></tr>
    @endif
    <tr><td>Subtotal</td><td>{{ $money($quote->subtotal) }}</td></tr>
    <tr><td>Descontos</td><td>{{ $money($quote->discount_total) }}</td></tr>
    <tr class="total"><td>TOTAL GERAL</td><td>{{ $money($quote->total) }}</td></tr>
  </table>
</section>
@if($quote->notes || $company->print_footer)
<section class="notes">
  <h2 class="section-title">Observações / condições comerciais</h2>
  @if($quote->notes)<p>{{ $quote->notes }}</p>@endif
  @if($company->print_footer)<p>{{ $company->print_footer }}</p>@endif
</section>
@endif
<footer class="footer">
  Orçamento comercial — não é documento fiscal e não comprova pagamento.
</footer>
</body>
</html>
