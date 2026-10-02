@extends('layouts.app')
@section('title','Recibos')
@section('content')
@include('finance._nav')
<section class="cms-card finance-operational-card">
<div class="grid-actionbar">
<div class="grid-actions-left"><a class="grid-primary-action" href="{{ route('finance.receipts.create') }}">@include('partials.icon',['name'=>'plus','size'=>17]) Novo</a></div>
<div class="grid-actions-right">
<a class="period-current" href="{{ route('finance.receipts.index',['month'=>now()->format('Y-m')]) }}">Mês atual</a>
<a class="period-arrow" href="{{ route('finance.receipts.index',['month'=>$prevMonth]) }}">@include('partials.icon',['name'=>'chevron-left','size'=>19])</a><span class="period-label">{{ $monthLabel }}</span><a class="period-arrow" href="{{ route('finance.receipts.index',['month'=>$nextMonth]) }}">@include('partials.icon',['name'=>'chevron','size'=>19])</a>
</div></div>
<div class="table-scroll"><table class="cms-table"><thead><tr><th>Cód</th><th>Contato</th><th>Referência</th><th>Data</th><th>Valor</th><th></th></tr></thead><tbody>
@forelse($receipts as $r)<tr><td>#{{ $r->id }}</td><td>{{ $r->recipient_name }}</td><td>{{ $r->reference }}</td><td>{{ $r->receipt_date->format('d/m/Y') }}</td><td class="price-strong">R$ {{ number_format((float)$r->amount,2,',','.') }}</td><td class="action-cell"><a class="btn-icon" href="{{ route('finance.receipts.print',$r) }}" data-tooltip="Imprimir">@include('partials.icon',['name'=>'print','size'=>16])</a></td></tr>
@empty<tr><td colspan="6" class="empty-cell">Nenhum recibo encontrado.</td></tr>@endforelse
</tbody></table></div>
@include('partials.table-footer',['paginator'=>$receipts])
</section>
@endsection
