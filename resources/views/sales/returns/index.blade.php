@extends('layouts.app')
@section('title','Vendas')
@section('titleMeta'){{ $returns->total() }} {{ $returns->total()===1?'devolução':'devoluções' }}@endsection
@section('content')
@include('sales._nav')

<section class="cms-card sales-module-card">
  <div class="grid-actionbar">
    <div class="grid-actions-left">
      <a class="grid-primary-action" href="{{ route('sales.returns.create') }}">
        @include('partials.icon',['name'=>'plus','size'=>17]) <span>Nova devolução</span>
      </a>
      <div class="grid-tool-group">
        <button class="grid-tool" type="button" data-print-page data-tooltip="Imprimir">@include('partials.icon',['name'=>'print','size'=>18])</button>
        <button class="grid-tool" type="button" data-export-table="devolucoes.csv" data-tooltip="Exportar CSV">@include('partials.icon',['name'=>'download','size'=>18])</button>
        <button class="grid-tool" type="button" data-refresh-page data-tooltip="Atualizar">@include('partials.icon',['name'=>'refresh','size'=>18])</button>
      </div>
    </div>

    <div class="grid-actions-right">
      <a class="period-current" href="{{ route('sales.returns.index',array_merge(request()->except('page','month'),['month'=>now()->format('Y-m')])) }}">Mês atual</a>
      <a class="period-arrow" href="{{ route('sales.returns.index',array_merge(request()->except('page','month'),['month'=>$prevMonth])) }}" aria-label="Mês anterior">@include('partials.icon',['name'=>'chevron-left','size'=>19])</a>
      <span class="period-label">{{ $monthLabel }}</span>
      <a class="period-arrow" href="{{ route('sales.returns.index',array_merge(request()->except('page','month'),['month'=>$nextMonth])) }}" aria-label="Próximo mês">@include('partials.icon',['name'=>'chevron','size'=>19])</a>
      <button class="grid-filter-button" type="button" data-filter-toggle="return-filters" data-tooltip="Pesquisar / filtrar">@include('partials.icon',['name'=>'search','size'=>18])</button>
    </div>
  </div>

  <div class="grid-filter-panel" id="return-filters" @if(!$term && !$status) hidden @endif>
    <form method="get" action="{{ route('sales.returns.index') }}" class="toolbar-filters" data-live-search data-live-target="returns-live-results">
      <input type="hidden" name="month" value="{{ $month }}">
      <div class="table-search-group">
        <input type="search" autocomplete="off" name="search" value="{{ $term }}" placeholder="Código da devolução, venda ou cliente...">
        <button type="submit" class="table-search-submit" data-tooltip="Pesquisar">@include('partials.icon',['name'=>'search','size'=>17])</button>
      </div>
      <select class="input-filter" name="status">
        <option value="">Todas as situações</option>
        <option value="completed" @selected($status==='completed')>Concluídas</option>
        <option value="cancelled" @selected($status==='cancelled')>Canceladas</option>
      </select>
      <button class="btn btn-secondary">Filtrar</button>
      @if($term || $status)<a class="btn btn-light" href="{{ route('sales.returns.index',['month'=>$month]) }}">Limpar</a>@endif
    </form>
  </div>

  <div id="returns-live-results" data-live-search-results>
  <div class="table-scroll">
    <table class="cms-table">
      <thead>
        <tr>
          <th>Cód.</th>
          <th>Venda</th>
          <th>Cliente</th>
          <th>Data</th>
          <th>Itens</th>
          <th>Valor devolvido</th>
          <th>Responsável</th>
          <th>Situação</th>
          <th class="action-cell">Detalhes</th>
        </tr>
      </thead>
      <tbody>
      @forelse($returns as $return)
        <tr>
          <td><a class="table-link" href="{{ route('sales.returns.show',$return) }}">#{{ str_pad((string)$return->id,5,'0',STR_PAD_LEFT) }}</a></td>
          <td><a class="table-link" href="{{ route('sales.show',$return->sale_id) }}">#{{ str_pad((string)$return->sale_id,5,'0',STR_PAD_LEFT) }}</a></td>
          <td>{{ $return->sale?->customer?->name ?? 'Consumidor não identificado' }}</td>
          <td class="nowrap">{{ $return->return_date->format('d/m/Y') }}</td>
          <td>{{ $return->items_count }}</td>
          <td class="price-strong nowrap">R$ {{ number_format((float)$return->total,2,',','.') }}</td>
          <td>{{ $return->user?->name ?? '—' }}</td>
          <td><span class="status {{ $return->status==='completed'?'status-ok':'status-muted' }}">{{ $return->status==='completed'?'Concluída':'Cancelada' }}</span></td>
          <td class="action-cell"><a class="btn-icon" href="{{ route('sales.returns.show',$return) }}" data-tooltip="Abrir devolução">@include('partials.icon',['name'=>'chevron','size'=>17])</a></td>
        </tr>
      @empty
        <tr><td class="empty-cell" colspan="9">Nenhuma devolução encontrada neste mês.</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>

  @include('partials.table-footer',['paginator'=>$returns])
  </div>
</section>
@endsection
