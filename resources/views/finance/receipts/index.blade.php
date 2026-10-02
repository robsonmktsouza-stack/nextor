@extends('layouts.app')
@section('title','Recibos')
@section('titleMeta'){{ $receipts->total() }} registro(s)@endsection
@section('content')
@include('finance._nav')

<section class="cms-card finance-operational-card">
  <div class="grid-actionbar">
    <div class="grid-actions-left">
      <a class="grid-primary-action" href="{{ route('finance.receipts.create') }}">@include('partials.icon',['name'=>'plus','size'=>17]) <span>Novo recibo</span></a>
      <div class="grid-tool-group">
        <button class="grid-tool" type="button" data-print-page data-tooltip="Imprimir">@include('partials.icon',['name'=>'print','size'=>18])</button>
        <button class="grid-tool" type="button" data-export-table="recibos.csv" data-tooltip="Exportar CSV">@include('partials.icon',['name'=>'download','size'=>18])</button>
        <button class="grid-tool" type="button" data-refresh-page data-tooltip="Atualizar">@include('partials.icon',['name'=>'refresh','size'=>18])</button>
      </div>
      <div class="finance-toolbar-summary">
        <span class="positive">Total emitido <strong>R$ {{ number_format((float)$totalAmount,2,',','.') }}</strong></span>
      </div>
    </div>

    <div class="grid-actions-right">
      <a class="period-current" href="{{ route('finance.receipts.index',array_merge(request()->except('page','month'),['month'=>now()->format('Y-m')])) }}">Mês atual</a>
      <a class="period-arrow" href="{{ route('finance.receipts.index',array_merge(request()->except('page','month'),['month'=>$prevMonth])) }}">@include('partials.icon',['name'=>'chevron-left','size'=>19])</a>
      <span class="period-label">{{ $monthLabel }}</span>
      <a class="period-arrow" href="{{ route('finance.receipts.index',array_merge(request()->except('page','month'),['month'=>$nextMonth])) }}">@include('partials.icon',['name'=>'chevron','size'=>19])</a>
      <button class="grid-filter-button" type="button" data-filter-toggle="receipt-filters" data-tooltip="Pesquisar / filtrar">@include('partials.icon',['name'=>'search','size'=>18])</button>
    </div>
  </div>

  <div class="grid-filter-panel" id="receipt-filters" @if(!$term) hidden @endif>
    <form method="get" class="toolbar-filters">
      <input type="hidden" name="month" value="{{ $month }}">
      <input class="input-filter" name="search" value="{{ $term }}" placeholder="Contato, documento ou referência">
      <button class="btn btn-secondary">Filtrar</button>
      @if($term)<a class="btn btn-light" href="{{ route('finance.receipts.index',['month'=>$month]) }}">Limpar</a>@endif
    </form>
  </div>

  <div class="table-scroll">
    <table class="cms-table">
      <thead><tr><th>Cód</th><th>Contato</th><th>Referência</th><th>Data</th><th>Valor</th><th></th></tr></thead>
      <tbody>
      @forelse($receipts as $r)
        <tr>
          <td>#{{ $r->id }}</td>
          <td>{{ $r->recipient_name }}@if($r->recipient_document)<small class="table-subtitle">{{ $r->recipient_document }}</small>@endif</td>
          <td>{{ $r->reference }}</td>
          <td class="nowrap">{{ $r->receipt_date->format('d/m/Y') }}</td>
          <td class="price-strong nowrap">R$ {{ number_format((float)$r->amount,2,',','.') }}</td>
          <td class="action-cell"><a class="btn-icon" href="{{ route('finance.receipts.print',$r) }}" data-tooltip="Abrir / imprimir">@include('partials.icon',['name'=>'print','size'=>16])</a></td>
        </tr>
      @empty
        <tr><td colspan="6" class="empty-cell">Nenhum recibo encontrado neste mês.</td></tr>
      @endforelse
      </tbody>
      <tfoot><tr><td colspan="4"><strong>TOTAL EMITIDO ({{ $receipts->total() }} recibos)</strong></td><td class="price-strong">R$ {{ number_format((float)$totalAmount,2,',','.') }}</td><td></td></tr></tfoot>
    </table>
  </div>
  @include('partials.table-footer',['paginator'=>$receipts])
</section>
@endsection
