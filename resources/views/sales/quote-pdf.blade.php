<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Orçamento {{ str_pad((string)$quote->id,5,'0',STR_PAD_LEFT) }}</title>
<style>
/* Impressão comercial tradicional: grade, campos compactos e tinta econômica.
   Sem cartões, bordas arredondadas, grandes faixas de cor ou marca do ERP. */
@page { size:A4; margin:13mm 13mm 19mm; }
* { box-sizing:border-box; }
html,body { margin:0; padding:0; }
body { font:10px/1.38 Arial,Helvetica,sans-serif; color:#232323; background:#fff; }
table { border-collapse:collapse; width:100%; }
strong { font-weight:700; }
.header { border-bottom:2px solid #3b3b3b; margin-bottom:10px; padding-bottom:9px; }
.header td { vertical-align:top; }
.company-logo { width:112px; padding:0 10px 0 0; }
.company-logo img { display:block; max-width:105px; max-height:52px; object-fit:contain; }
.company { padding-right:14px; }
.company-name { font-size:15px; line-height:1.22; font-weight:700; margin:0 0 3px; }
.company-legal { color:#484848; font-size:10px; margin-bottom:3px; }
.company-detail { font-size:9.5px; line-height:1.45; }
.document { width:166px; border-left:1px solid #b6b6b6; padding-left:13px; }
.document-name { font-size:15px; font-weight:700; letter-spacing:.2px; margin-bottom:4px; }
.document-number { font-size:12px; font-weight:700; margin-bottom:5px; }
.document-line { display:table; width:100%; font-size:9.5px; padding:2px 0; }
.document-line span { display:table-cell; width:62px; color:#505050; }
.document-line strong { display:table-cell; }
.section-title { font-size:10.5px; font-weight:700; text-transform:uppercase;
  border-bottom:1px solid #999; margin:12px 0 0; padding:0 0 4px; }
.info { margin:0 0 8px; border-left:1px solid #c7c7c7; border-right:1px solid #c7c7c7;
  border-bottom:1px solid #c7c7c7; table-layout:fixed; }
.info td { border-right:1px solid #d5d5d5; border-bottom:1px solid #dedede;
  vertical-align:top; width:50%; padding:6px 8px; overflow-wrap:anywhere; }
.info tr:last-child td { border-bottom:none; }
.info td:last-child { border-right:none; }
.label { display:block; color:#525252; font-size:9px; margin-bottom:2px; }
.value { font-size:10px; font-weight:700; white-space:pre-wrap; }
.plain { font-size:10px; font-weight:400; }
.items { table-layout:fixed; border:1px solid #a7a7a7; margin-top:0; }
.items thead { display:table-header-group; }
.items tr { page-break-inside:avoid; break-inside:avoid; }
.items th { background:#ededed; border-bottom:1px solid #a7a7a7; border-right:1px solid #c5c5c5;
  padding:6px 5px; font-size:9px; font-weight:700; text-align:left; }
.items td { border-right:1px solid #d4d4d4; border-bottom:1px solid #dedede;
  padding:6px 5px; vertical-align:top; overflow-wrap:anywhere; }
.items th:last-child,.items td:last-child { border-right:none; }
.items th:nth-child(1) { width:5%; }
.items th:nth-child(2) { width:12%; }
.items th:nth-child(3) { width:31%; }
.items th:nth-child(4) { width:6%; }
.items th:nth-child(5) { width:9%; }
.items th:nth-child(6) { width:13%; }
.items th:nth-child(7) { width:11%; }
.items th:nth-child(8) { width:13%; }
.right { text-align:right!important; white-space:nowrap; }
.center { text-align:center!important; }
.item-name { font-weight:500; }
.item-note { display:block; color:#555; margin-top:3px; font-size:9px; white-space:pre-wrap; }
.totals { width:265px; margin:9px 0 0 auto; break-inside:avoid; page-break-inside:avoid; }
.totals td { padding:4px 7px; text-align:right; border-bottom:1px solid #d9d9d9; }
.totals td:first-child { font-weight:400; color:#444; }
.totals td:last-child { font-weight:700; width:120px; }
.totals .total td { border-top:1px solid #666; border-bottom:2px solid #555;
  background:#ededed; font-size:12px; font-weight:700; padding:7px; color:#222; }
.notes { margin-top:14px; break-inside:avoid; page-break-inside:avoid; }
.notes .section-title { margin-bottom:6px; }
.notes p { margin:4px 0; font-size:10px; white-space:pre-wrap; overflow-wrap:anywhere; }
.footer { position:fixed; bottom:-11mm; left:0; right:0; border-top:1px solid #999;
  padding-top:5px; color:#555; font-size:9px; }
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
    <td><span class="label">CPF / CNPJ</span><span class="value plain">{{ $customer?->document ?: ($quote->consumer_document ?: 'Não informado') }}</span></td>
  </tr>
  <tr>
    <td><span class="label">Endereço</span><span class="value plain">{{ $customerAddress ?: 'Não informado' }}</span></td>
    <td><span class="label">Bairro / Município / UF</span><span class="value plain">{{ $customerLocation ?: 'Não informado' }}</span></td>
  </tr>
  <tr>
    <td><span class="label">Telefone / E-mail</span><span class="value plain">
      {{ implode(' | ',array_filter([$customer?->phone,$customer?->email])) ?: 'Não informado' }}
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
          <span class="item-name">{{ $item->product_name }}</span>
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
