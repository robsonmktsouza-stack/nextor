@extends('layouts.app')
@section('title','Transferências')
@section('content')
@include('finance._nav')

<section class="cms-card finance-operational-card">
  <div class="grid-actionbar">
    <div class="grid-actions-left">
      <a class="grid-primary-action" href="{{ route('finance.transfers.create') }}">
        @include('partials.icon',['name'=>'plus','size'=>17]) <span>Nova transferência</span>
      </a>
      <div class="grid-tool-group">
        <button class="grid-tool" type="button" data-print-page data-tooltip="Imprimir">@include('partials.icon',['name'=>'print','size'=>18])</button>
        <button class="grid-tool" type="button" data-export-table="transferencias.csv" data-tooltip="Exportar CSV">@include('partials.icon',['name'=>'download','size'=>18])</button>
        <button class="grid-tool" type="button" data-refresh-page data-tooltip="Atualizar">@include('partials.icon',['name'=>'refresh','size'=>18])</button>
      </div>
      <div class="finance-toolbar-summary" data-live-sync="transfer-summary">
        <span class="neutral">Movimentado <strong>R$ {{ number_format((float)$activeTotal,2,',','.') }}</strong></span>
        @if($cancelledCount)<span>Canceladas <strong>{{ $cancelledCount }}</strong></span>@endif
      </div>
    </div>

    <div class="grid-actions-right">
      <a class="period-current" href="{{ route('finance.transfers.index',array_merge(request()->except('page','month'),['month'=>now()->format('Y-m')])) }}">Mês atual</a>
      <a class="period-arrow" href="{{ route('finance.transfers.index',array_merge(request()->except('page','month'),['month'=>$prevMonth])) }}">@include('partials.icon',['name'=>'chevron-left','size'=>19])</a>
      <span class="period-label">{{ $monthLabel }}</span>
      <a class="period-arrow" href="{{ route('finance.transfers.index',array_merge(request()->except('page','month'),['month'=>$nextMonth])) }}">@include('partials.icon',['name'=>'chevron','size'=>19])</a>
      <button class="grid-filter-button" type="button" data-filter-toggle="transfer-filters" data-tooltip="Pesquisar / filtrar">@include('partials.icon',['name'=>'search','size'=>18])</button>
    </div>
  </div>

  <div class="grid-filter-panel" id="transfer-filters" @if(!$term) hidden @endif>
    <form method="get" action="{{ route('finance.transfers.index') }}" class="toolbar-filters" data-live-search data-live-target="transfers-live-results">
      <input type="hidden" name="month" value="{{ $month }}">
      <input type="search" autocomplete="off" class="input-filter" name="search" value="{{ $term }}" placeholder="Descrição, conta de origem ou destino">
      <button class="btn btn-secondary">Filtrar</button>
      @if($term)<a class="btn btn-light" href="{{ route('finance.transfers.index',['month'=>$month]) }}">Limpar</a>@endif
    </form>
  </div>

  <div id="transfers-live-results" data-live-search-results>
  <div class="table-scroll">
    <table class="cms-table">
      <thead><tr><th>Cód</th><th>Data</th><th>Origem</th><th>Destino</th><th>Descrição</th><th>Situação</th><th>Valor</th><th></th></tr></thead>
      <tbody>
      @forelse($transfers as $transfer)
        <tr>
          <td>#{{ $transfer->id }}</td>
          <td class="nowrap">{{ $transfer->transfer_date->format(\App\Models\AppSetting::dateFormat()) }}</td>
          <td>{{ $transfer->fromAccount->name }}</td>
          <td>{{ $transfer->toAccount->name }}</td>
          <td>{{ $transfer->description ?: 'Transferência entre contas' }}</td>
          <td><span class="status {{ $transfer->cancelled_at?'status-muted':'status-ok' }}">{{ $transfer->cancelled_at?'Cancelada':'Concluída' }}</span></td>
          <td class="price-strong nowrap">R$ {{ number_format((float)$transfer->amount,2,',','.') }}</td>
          <td class="action-cell">
            @if(!$transfer->cancelled_at)
              <form method="post" action="{{ route('finance.transfers.cancel',$transfer) }}" data-confirm="Cancelar esta transferência?">
                @csrf
                <button class="btn-icon row-action-danger" data-tooltip="Cancelar">@include('partials.icon',['name'=>'x','size'=>15])</button>
              </form>
            @endif
          </td>
        </tr>
      @empty
        <tr><td colspan="8" class="empty-cell">Nenhuma transferência encontrada neste mês.</td></tr>
      @endforelse
      </tbody>
      <tfoot><tr><td colspan="6"><strong>TOTAL MOVIMENTADO</strong></td><td class="price-strong">R$ {{ number_format((float)$activeTotal,2,',','.') }}</td><td></td></tr></tfoot>
    </table>
  </div>
  @include('partials.table-footer',['paginator'=>$transfers])
  </div>
</section>
@endsection
