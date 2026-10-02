@extends('layouts.app')
@section('title','Transferências')
@section('content')
@include('finance._nav')
<section class="cms-card finance-operational-card">
<div class="grid-actionbar">
 <div class="grid-actions-left"><a class="grid-primary-action" href="{{ route('finance.transfers.create') }}">@include('partials.icon',['name'=>'plus','size'=>17]) Nova transferência</a></div>
 <div class="grid-actions-right">
  <a class="period-current" href="{{ route('finance.transfers.index',['month'=>now()->format('Y-m')]) }}">Mês atual</a>
  <a class="period-arrow" href="{{ route('finance.transfers.index',['month'=>$prevMonth]) }}">@include('partials.icon',['name'=>'chevron-left','size'=>19])</a>
  <span class="period-label">{{ $monthLabel }}</span>
  <a class="period-arrow" href="{{ route('finance.transfers.index',['month'=>$nextMonth]) }}">@include('partials.icon',['name'=>'chevron','size'=>19])</a>
 </div>
</div>
<div class="table-scroll"><table class="cms-table">
<thead><tr><th>Cód</th><th>Data</th><th>Origem</th><th>Destino</th><th>Descrição</th><th>Situação</th><th>Valor</th><th></th></tr></thead>
<tbody>
@forelse($transfers as $transfer)
<tr>
<td>#{{ $transfer->id }}</td><td>{{ $transfer->transfer_date->format('d/m/Y') }}</td><td>{{ $transfer->fromAccount->name }}</td><td>{{ $transfer->toAccount->name }}</td>
<td>{{ $transfer->description ?: 'Transferência entre contas' }}</td>
<td><span class="status {{ $transfer->cancelled_at?'status-muted':'status-ok' }}">{{ $transfer->cancelled_at?'Cancelada':'Concluída' }}</span></td>
<td class="price-strong">R$ {{ number_format((float)$transfer->amount,2,',','.') }}</td>
<td class="action-cell">@if(!$transfer->cancelled_at)<form method="post" action="{{ route('finance.transfers.cancel',$transfer) }}" data-confirm="Cancelar esta transferência?">@csrf<button class="btn-icon row-action-danger">@include('partials.icon',['name'=>'x','size'=>15])</button></form>@endif</td>
</tr>
@empty<tr><td colspan="8" class="empty-cell">Nenhuma transferência encontrada.</td></tr>@endforelse
</tbody></table></div>
@include('partials.table-footer',['paginator'=>$transfers])
</section>
@endsection
