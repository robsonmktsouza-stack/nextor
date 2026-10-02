@extends('layouts.app')
@section('title','Recorrência')
@section('content')
@include('finance._nav')
<section class="cms-card finance-operational-card">
<div class="grid-actionbar">
<div class="grid-actions-left">
<a class="grid-primary-action" href="{{ route('finance.recurrences.create') }}">@include('partials.icon',['name'=>'plus','size'=>17]) Nova</a>
<form method="post" action="{{ route('finance.recurrences.generate') }}">@csrf<button class="grid-tool" type="submit" data-tooltip="Gerar vencimentos pendentes">@include('partials.icon',['name'=>'refresh','size'=>17])</button></form>
</div></div>
<div class="table-scroll"><table class="cms-table">
<thead><tr><th>Cód</th><th>Contato</th><th>Descrição</th><th>A cada</th><th>Início</th><th>Último gerado</th><th>Próximo</th><th>Situação</th><th>Valor</th><th></th></tr></thead>
<tbody>
@forelse($recurrences as $r)
<tr>
<td>#{{ $r->id }}</td><td>{{ $r->customer?->name ?? '—' }}</td><td>{{ $r->description }}</td>
<td>{{ $r->frequency_label }}</td><td>{{ $r->start_date->format('d/m/Y') }}</td><td>{{ $r->last_generated_at?->format('d/m/Y') ?? '—' }}</td><td>{{ $r->next_date?->format('d/m/Y') ?? '—' }}</td>
<td><span class="status {{ $r->is_active?'status-ok':'status-muted' }}">{{ $r->is_active?'Ativa':'Pausada' }}</span></td>
<td class="price-strong">R$ {{ number_format((float)$r->amount,2,',','.') }}</td>
<td class="action-cell"><form method="post" action="{{ route('finance.recurrences.toggle',$r) }}">@csrf<button class="btn-icon" data-tooltip="{{ $r->is_active?'Pausar':'Ativar' }}">@include('partials.icon',['name'=>$r->is_active?'x':'check','size'=>15])</button></form></td>
</tr>
@empty<tr><td colspan="10" class="empty-cell">Nenhuma recorrência encontrada.</td></tr>@endforelse
</tbody></table></div>
@include('partials.table-footer',['paginator'=>$recurrences])
</section>
@endsection
