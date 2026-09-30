@extends('layouts.app')
@section('title','Vendas')
@section('description','Histórico de pedidos e operações comerciais com baixa automática de estoque.')
@section('actions')<a class="btn btn-primary" href="{{ route('sales.create') }}">@include('partials.icon',['name'=>'plus','size'=>16]) Nova venda</a>@endsection
@section('content')
<section class="cms-card"><div class="table-toolbar"><div><h2>Vendas realizadas</h2><p>Consulta de documentos comerciais internos</p></div>
<form method="get" class="toolbar-filters"><select name="status" class="input-filter"><option value="">Todos os status</option><option value="completed" @selected($status==='completed')>Concluídas</option><option value="cancelled" @selected($status==='cancelled')>Canceladas</option></select><button class="btn btn-secondary">Filtrar</button></form></div>
<div class="table-scroll"><table class="cms-table"><thead><tr><th>Nº venda</th><th>Data</th><th>Cliente</th><th>Responsável</th><th>Valor total</th><th>Status</th><th class="action-cell">Detalhes</th></tr></thead><tbody>
@forelse($sales as $sale)<tr><td><a class="table-link" href="{{ route('sales.show',$sale) }}">#{{ str_pad($sale->id,5,'0',STR_PAD_LEFT) }}</a></td><td class="nowrap">{{ $sale->created_at->format('d/m/Y H:i') }}</td><td>{{ $sale->customer?->name ?? 'Consumidor não identificado' }}</td><td>{{ $sale->user?->name ?? '—' }}</td><td class="price-strong nowrap">R$ {{ number_format((float)$sale->total,2,',','.') }}</td><td><span class="status {{ $sale->status==='completed'?'status-ok':'status-muted' }}">{{ $sale->status==='completed'?'Concluída':'Cancelada' }}</span></td><td class="action-cell"><a class="btn-icon" href="{{ route('sales.show',$sale) }}" aria-label="Abrir venda">@include('partials.icon',['name'=>'chevron','size'=>17])</a></td></tr>
@empty<tr><td class="empty-cell" colspan="7">Nenhuma venda cadastrada. Crie a primeira venda.</td></tr>@endforelse
</tbody></table></div><div class="card-pagination">{{ $sales->links('partials.pagination') }}</div></section>
@endsection
