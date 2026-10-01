@extends('layouts.app')
@section('titleMeta'){{ $sales->total() }} {{ $sales->total() === 1 ? 'venda' : 'vendas' }}@endsection
@section('title','Vendas')
@section('description','Histórico de pedidos e operações comerciais com baixa automática de estoque.')
@section('content')
<section class="cms-card">
<div class="grid-actionbar">
  <div class="grid-actions-left">
    <a class="grid-primary-action" href="{{ route('sales.create') }}">@include('partials.icon',['name'=>'plus','size'=>17]) <span>Nova</span></a>
    <div class="grid-tool-group">
      <button class="grid-tool" type="button" data-print-page data-tooltip="Imprimir">@include('partials.icon',['name'=>'print','size'=>18])</button>
      <button class="grid-tool" type="button" data-export-table="vendas.csv" data-tooltip="Exportar CSV">@include('partials.icon',['name'=>'download','size'=>18])</button>
      <button class="grid-tool" type="button" data-refresh-page data-tooltip="Atualizar">@include('partials.icon',['name'=>'refresh','size'=>18])</button>
    </div>
    <div class="bulk-actions">
      <select class="bulk-action-select" data-bulk-menu disabled aria-label="Ações em massa">
        <option value="">Ações em massa</option>
        <option value="local:export">Exportar selecionadas</option>
        <option value="local:print">Imprimir selecionadas</option>
      </select>
      <button class="bulk-apply" type="button" data-bulk-apply disabled>Aplicar</button>
    </div>
    <span class="selection-count" data-selection-count hidden></span>
  </div>
  <div class="grid-actions-right">
    <a class="period-current" href="{{ route('sales.index', array_merge(request()->except('page','month'), ['month'=>now()->format('Y-m')])) }}">Mês atual</a>
    <a class="period-arrow" href="{{ route('sales.index', array_merge(request()->except('page','month'), ['month'=>$prevMonth])) }}" aria-label="Mês anterior">@include('partials.icon',['name'=>'chevron-left','size'=>19])</a>
    <span class="period-label">{{ $monthLabel }}</span>
    <a class="period-arrow" href="{{ route('sales.index', array_merge(request()->except('page','month'), ['month'=>$nextMonth])) }}" aria-label="Próximo mês">@include('partials.icon',['name'=>'chevron','size'=>19])</a>
    <button class="grid-filter-button" type="button" data-filter-toggle="sales-filters" data-tooltip="Filtro avançado" aria-label="Filtro avançado">@include('partials.icon',['name'=>'search','size'=>18])</button>
  </div>
</div>
<div class="grid-filter-panel" id="sales-filters" @if(!$status) hidden @endif>
<form method="get" class="toolbar-filters"><input type="hidden" name="month" value="{{ $month }}"><select name="status" class="input-filter"><option value="">Todos os status</option><option value="completed" @selected($status==='completed')>Concluídas</option><option value="cancelled" @selected($status==='cancelled')>Canceladas</option></select><button class="btn btn-secondary">Filtrar</button>@if($status)<a class="btn btn-light" href="{{ route('sales.index',['month'=>$month]) }}">Limpar</a>@endif</form>
</div>
<div class="table-scroll"><table class="cms-table"><thead><tr><th class="select-cell"><input type="checkbox" data-check-all aria-label="Selecionar todos"></th><th>Nº</th><th>Tipo</th><th>Data</th><th>Cliente</th><th>Responsável</th><th>Valor total</th><th>Status</th><th class="action-cell">Detalhes</th></tr></thead><tbody>
@forelse($sales as $sale)<tr><td class="select-cell"><input type="checkbox" data-row-select value="{{ $sale->id }}" aria-label="Selecionar venda {{ $sale->id }}"></td><td><a class="table-link" href="{{ route('sales.show',$sale) }}">#{{ str_pad($sale->id,5,'0',STR_PAD_LEFT) }}</a></td><td><span class="status status-blue">{{ $sale->operation_type==='quote'?'Orçamento':'Venda' }}</span></td><td class="nowrap">{{ ($sale->operation_date ?? $sale->created_at)->format('d/m/Y') }}</td><td>{{ $sale->customer?->name ?? 'Consumidor não identificado' }}</td><td>{{ $sale->user?->name ?? '—' }}</td><td class="price-strong nowrap">R$ {{ number_format((float)$sale->total,2,',','.') }}</td><td><span class="status {{ $sale->status==='completed'?'status-ok':'status-muted' }}">{{ $sale->status==='completed'?'Concluída':'Cancelada' }}</span></td><td class="action-cell"><a class="btn-icon" href="{{ route('sales.show',$sale) }}" aria-label="Abrir venda">@include('partials.icon',['name'=>'chevron','size'=>17])</a></td></tr>
@empty<tr><td class="empty-cell" colspan="9">Nenhuma venda ou orçamento cadastrado.</td></tr>@endforelse
</tbody></table></div>@include('partials.table-footer',['paginator'=>$sales])
</section>
@endsection
