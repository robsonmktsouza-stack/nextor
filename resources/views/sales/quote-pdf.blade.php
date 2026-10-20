<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Orçamento {{ str_pad((string)$quote->id,5,'0',STR_PAD_LEFT) }}</title>
<style>
@page{size:A4;margin:16mm 15mm 16mm}
*{box-sizing:border-box}
body{font:12px/1.5 Arial,Helvetica,sans-serif;color:#25384b;margin:0;background:#fff}
.header{border-bottom:3px solid #315f79;padding-bottom:16px;display:flex;justify-content:space-between;gap:20px;align-items:center}
.brand{display:flex;gap:16px;align-items:center;min-width:0}
.brand img{display:block;max-height:78px;max-width:170px;object-fit:contain}
.company-name{font-size:19px;font-weight:700;color:#234760;line-height:1.18}
.company-data{font-size:10px;color:#566b7a;line-height:1.6;margin-top:4px;white-space:pre-line}
.doc-meta{text-align:right;min-width:150px}
.doc-type{font-size:11px;letter-spacing:1.8px;color:#55718a;font-weight:700}
.doc-number{font-size:30px;font-weight:800;color:#1d4661;line-height:1.2;white-space:nowrap}
.doc-date{font-size:11px;color:#65798b;margin-top:5px}
.title{font-size:17px;font-weight:700;margin:24px 0 4px;color:#244963}
.subtitle{font-size:11px;color:#5f7586;margin-bottom:15px}
.details{border:1px solid #dce5ea;background:#f7fafb;border-radius:5px;padding:12px 14px;display:table;width:100%;margin-top:15px}
.cell{display:table-cell;vertical-align:top;width:50%;padding-right:12px}
.caption{display:block;text-transform:uppercase;font-size:9px;font-weight:700;letter-spacing:.7px;color:#7890a1;margin-bottom:4px}
.value{font-size:12px;font-weight:650;white-space:pre-wrap}
.client-detail{font-size:10px;color:#617587;white-space:pre-line;margin-top:4px}
table.items{width:100%;border-collapse:collapse;margin-top:22px;table-layout:fixed}
.items thead{display:table-header-group}
.items th{background:#e9f0f4;color:#355770;text-transform:uppercase;letter-spacing:.3px;font-size:9px;text-align:left;padding:9px 7px}
.items td{padding:10px 7px;border-bottom:1px solid #e2e8ed;vertical-align:top;word-break:break-word}
.items tr{break-inside:avoid;page-break-inside:avoid}
.items th:nth-child(1){width:7%}
.items th:nth-child(2){width:39%}
.items th:nth-child(3){width:11%}
.items th:nth-child(4){width:15%}
.items th:nth-child(5){width:13%}
.items th:nth-child(6){width:15%}
.right{text-align:right}.center{text-align:center}.item-name{font-weight:700}
.item-code{display:block;font-size:10px;color:#758798;margin-top:2px}
.item-note{font-size:10px;color:#687987;white-space:pre-wrap}
.summary{margin:20px 0 18px auto;width:270px;break-inside:avoid}
.sum-line{display:flex;justify-content:space-between;gap:15px;padding:6px 10px;color:#54697c}
.sum-final{display:flex;justify-content:space-between;gap:10px;background:#294e68;color:#fff;border-radius:4px;padding:13px 11px;font-size:17px;font-weight:700;margin-top:7px}
.notes{margin-top:20px;border-top:1px solid #e0e7ec;padding-top:14px;break-inside:avoid}
.notes p{margin:5px 0 0;white-space:pre-wrap;overflow-wrap:anywhere}
.footer{margin-top:24px;padding-top:13px;border-top:1px solid #dbe4ea;font-size:10px;color:#667b8d;text-align:center}
.status{display:inline-block;padding:3px 9px;border-radius:3px;background:#eaf0f4;color:#315e7b;font-size:10px;font-weight:700;margin-top:8px}
</style>
</head>
<body>
@php
$money=fn($value)=>'R$ '.number_format((float)$value,2,',','.');
$companyName=$company->trade_name ?: ($company->legal_name ?: 'Empresa');
$address=implode(', ',array_filter([$company->address,$company->address_number,$company->address_complement]));
$city=implode(' / ',array_filter([$company->city,$company->state]));
$customer=$quote->customer;
$customerAddress=implode(', ',array_filter([$customer?->address,$customer?->address_number,$customer?->address_complement]));
$expired=$quote->quote_expires_at && $quote->quote_expires_at->startOfDay()->lt(today());
$state=match(true) {
  $quote->status==='cancelled'=>'Cancelado',
  $quote->status==='converted'=>'Convertido em venda',
  $expired=>'Prazo de validade encerrado',
  default=>'Aguardando aprovação'
};
@endphp
<header class="header">
 <div class="brand">
  @if($logo)<img src="{{ $logo }}" alt="Logomarca da empresa">@endif
  <div>
   <div class="company-name">{{ $companyName }}</div>
   <div class="company-data">@if($company->legal_name && $company->trade_name && $company->legal_name!==$company->trade_name){{ $company->legal_name }}
@endif@if($company->document)CNPJ: {{ $company->document }}
@endif@if($address){{ $address }}
@endif@if($company->district){{ $company->district }}@if($city) — @endif@endif{{ $city }}@if($company->zip_code) · CEP {{ $company->zip_code }}@endif
@if($company->phone){{ $company->phone }}@endif@if($company->email) · {{ $company->email }}@endif</div>
  </div>
 </div>
 <div class="doc-meta">
  <div class="doc-type">ORÇAMENTO</div>
  <div class="doc-number">#{{ str_pad((string)$quote->id,5,'0',STR_PAD_LEFT) }}</div>
  <div class="doc-date">Emissão: {{ ($quote->operation_date ?? $quote->created_at)->format('d/m/Y') }}</div>
  <span class="status">{{ $state }}</span>
 </div>
</header>
<h1 class="title">Proposta comercial</h1>
<p class="subtitle">Segue o detalhamento dos produtos e serviços solicitados.</p>
<section class="details">
 <div class="cell">
  <span class="caption">Preparado para</span>
  <div class="value">{{ $customer?->name ?: ($quote->consumer_name ?: 'Cliente não identificado') }}</div>
  <div class="client-detail">@if($customer?->document)Documento: {{ $customer->document }}
@endif@if($customerAddress){{ $customerAddress }}
@endif@if($customer?->district){{ $customer->district }}@if($customer?->city) — @endif@endif@if($customer?->city){{ $customer->city }} / {{ $customer->state }}
@endif@if($customer?->phone){{ $customer->phone }}@endif@if($customer?->email) · {{ $customer->email }}@endif</div>
 </div>
 <div class="cell">
  <span class="caption">Condições</span>
  <div class="value">Validade: {{ $quote->quote_expires_at?->format('d/m/Y') ?? 'A combinar' }}</div>
  <div class="client-detail">Responsável: {{ $quote->user?->name ?? '—' }}
Referência: {{ str_pad((string)$quote->id,5,'0',STR_PAD_LEFT) }}</div>
 </div>
</section>
<table class="items">
 <thead><tr><th>Item</th><th>Descrição</th><th class="right">Qtde.</th><th class="right">Valor unit.</th><th class="right">Desconto</th><th class="right">Total</th></tr></thead>
 <tbody>
 @foreach($quote->items as $item)
 <tr>
  <td>{{ $loop->iteration }}</td>
  <td><span class="item-name">{{ $item->product_name }}</span>
   @if($item->product_sku)<span class="item-code">{{ $item->product_sku }}</span>@endif
   @if($item->notes)<span class="item-note">{{ $item->notes }}</span>@endif
  </td>
  <td class="right">{{ number_format((float)$item->quantity,3,',','.') }}</td>
  <td class="right">{{ $money($item->unit_price) }}</td>
  <td class="right">{{ $money($item->discount) }}</td>
  <td class="right"><strong>{{ $money($item->line_total) }}</strong></td>
 </tr>
 @endforeach
 </tbody>
</table>
<div class="summary">
 <div class="sum-line"><span>Subtotal</span><strong>{{ $money($quote->subtotal) }}</strong></div>
 <div class="sum-line"><span>Descontos</span><strong>{{ $money($quote->discount_total) }}</strong></div>
 <div class="sum-final"><span>Total</span><span>{{ $money($quote->total) }}</span></div>
</div>
@if($quote->notes || $company->print_footer)
<section class="notes">
 <span class="caption">Observações e condições</span>
 @if($quote->notes)<p>{{ $quote->notes }}</p>@endif
 @if($company->print_footer)<p>{{ $company->print_footer }}</p>@endif
</section>
@endif
<footer class="footer">
  Orçamento comercial — não é documento fiscal e não comprova pagamento.
  @if($quote->status==='completed' && !$expired) Sujeito à confirmação de disponibilidade e condições até a validade indicada. @endif
</footer>
</body>
</html>