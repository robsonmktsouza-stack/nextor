<!doctype html>
@php
  $companyName=$company->trade_name ?: $company->legal_name ?: config('app.name');
  $issuerName=$receipt->issuer_mode==='company' ? $companyName : ($receipt->creator?->name ?? $companyName);
@endphp
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Recibo #{{ $receipt->id }}</title>
<style>
body{font-family:Arial,sans-serif;background:#f2f4f6;margin:0;color:#202b34}
.actions{max-width:800px;margin:20px auto;display:flex;gap:8px}
.actions a,.actions button{padding:10px 16px;border:0;border-radius:4px;text-decoration:none;background:#2877c7;color:#fff;cursor:pointer}
.sheet{max-width:800px;margin:0 auto 24px;background:#fff;padding:55px;box-shadow:0 3px 20px #0001}
.receipt-brand{display:flex;align-items:center;gap:16px;margin-bottom:28px;padding-bottom:18px;border-bottom:1px solid #e4e7eb}
.receipt-brand img{width:110px;height:70px;object-fit:contain}
.receipt-brand div{display:flex;flex-direction:column;gap:4px}
.receipt-brand strong{font-size:17px}
.receipt-brand span{font-size:12px;color:#647182}
.sheet h1{text-align:center;font-size:26px;margin:0 0 40px}
.amount{float:right;border:1px solid #444;padding:9px 16px;font-weight:bold}
.text{font-size:16px;line-height:1.9;text-align:justify}
.custom-print{font-size:12px;line-height:1.55;color:#566474;margin:12px 0}
.signature{margin-top:80px;text-align:center}
.line{width:320px;border-top:1px solid #444;margin:0 auto 8px}
.copy{page-break-after:always;margin-bottom:40px}
.receipt-footer{margin-top:35px;padding-top:12px;border-top:1px solid #e2e6ea;text-align:center;font-size:11px;color:#718092;line-height:1.5}
@media print{body{background:#fff}.actions{display:none}.sheet{box-shadow:none;margin:0;max-width:none;padding:35px}.copy:last-child{page-break-after:auto}}
</style>
</head>
<body>
<div class="actions"><a href="{{ route('finance.receipts.index') }}">Voltar</a><button onclick="window.print()">Imprimir</button></div>
@for($i=0;$i<$receipt->copies;$i++)
<section class="sheet copy">
  @if($receipt->issuer_mode==='company')
    <div class="receipt-brand">
      @if($company->logo_path)<img src="{{ asset('storage/'.$company->logo_path) }}" alt="">@endif
      <div>
        <strong>{{ $companyName }}</strong>
        @if($company->document)<span>{{ $company->document }}</span>@endif
        @if($company->phone || $company->email)<span>{{ collect([$company->phone,$company->email])->filter()->implode(' · ') }}</span>@endif
      </div>
    </div>
    @if($company->print_header)<div class="custom-print">{!! nl2br(e($company->print_header)) !!}</div>@endif
  @endif

  <div class="amount">R$ {{ number_format((float)$receipt->amount,2,',','.') }}</div>
  <h1>RECIBO</h1>

  <p class="text">Recebi de <strong>{{ $receipt->recipient_name }}</strong>@if($receipt->recipient_document), inscrito(a) sob o documento <strong>{{ $receipt->recipient_document }}</strong>@endif, a importância de <strong>R$ {{ number_format((float)$receipt->amount,2,',','.') }}</strong>, referente a <strong>{{ $receipt->reference }}</strong>.</p>

  <p class="text">Data: {{ $receipt->receipt_date->format('d/m/Y') }}.</p>

  <div class="signature">
    <div class="line"></div>
    <strong>{{ $issuerName }}</strong>
  </div>

  @if($receipt->issuer_mode==='company' && $company->print_footer)
    <div class="receipt-footer">{!! nl2br(e($company->print_footer)) !!}</div>
  @endif
</section>
@endfor
</body>
</html>
