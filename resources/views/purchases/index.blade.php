@extends('layouts.app')
@section('title','Compras')
@section('content')
<section class="cms-card sales-module-card">
  <div class="grid-actionbar">
    <div class="grid-actions-left">
      <a href="{{ route('purchases.create') }}" class="grid-primary-action" data-company-required>
        @include('partials.icon',['name'=>'plus','size'=>17]) <span>Nova</span>
      </a>
      <div class="grid-tool-group">
        <button type="button" class="grid-tool" data-print-page data-tooltip="Imprimir" aria-label="Imprimir">@include('partials.icon',['name'=>'print','size'=>18])</button>
        <button type="button" class="grid-tool" data-export-table="compras.csv" data-tooltip="Exportar CSV" aria-label="Exportar CSV">@include('partials.icon',['name'=>'download','size'=>18])</button>
        <button type="button" class="grid-tool" data-refresh-page data-tooltip="Atualizar" aria-label="Atualizar">@include('partials.icon',['name'=>'refresh','size'=>18])</button>
      </div>
      <span class="purchases-summary">{{ $count }} {{ $count===1?'compra':'compras' }} · Total R$ {{ number_format((float)$total,2,',','.') }}</span>
    </div>
    <div class="grid-actions-right">
      <a class="period-current" href="{{ route('purchases.index',array_merge(request()->except('page','month'),['month'=>now()->format('Y-m')])) }}">Mês atual</a>
      <a class="period-arrow" href="{{ route('purchases.index',array_merge(request()->except('page','month'),['month'=>$prevMonth])) }}" aria-label="Mês anterior">@include('partials.icon',['name'=>'chevron-left','size'=>19])</a>
      <span class="period-label">{{ $monthLabel }}</span>
      <a class="period-arrow" href="{{ route('purchases.index',array_merge(request()->except('page','month'),['month'=>$nextMonth])) }}" aria-label="Próximo mês">@include('partials.icon',['name'=>'chevron','size'=>19])</a>
      <button type="button" class="grid-filter-button" data-filter-toggle="purchase-filters" aria-controls="purchase-filters" aria-label="Filtrar compras" data-tooltip="Filtrar compras">@include('partials.icon',['name'=>'search','size'=>18])</button>
    </div>
  </div>
  <div class="grid-filter-panel" id="purchase-filters" @if(!$term&&!$status&&!$supplierId) hidden @endif>
    <form method="get" action="{{ route('purchases.index') }}" class="toolbar-filters">
      <input type="hidden" name="month" value="{{ $month }}">
      <div class="table-search-group">
        <input type="search" name="search" value="{{ $term }}" placeholder="Número, documento, fornecedor ou CNPJ" aria-label="Pesquisar compras">
        <button type="submit" class="table-search-submit" aria-label="Pesquisar">@include('partials.icon',['name'=>'search','size'=>17])</button>
      </div>
      <select class="input-filter" name="status" aria-label="Situação">
        <option value="">Todas as situações</option>
        <option value="draft" @selected($status==='draft')>Rascunhos</option>
        <option value="received" @selected($status==='received')>Recebidas</option>
        <option value="cancelled" @selected($status==='cancelled')>Canceladas</option>
      </select>
      <select class="input-filter" name="supplier_id" aria-label="Fornecedor">
        <option value="">Todos os fornecedores</option>
        @foreach($suppliers as $supplier)<option value="{{ $supplier->id }}" @selected($supplierId===$supplier->id)>{{ $supplier->name }}</option>@endforeach
      </select>
      <button type="submit" class="btn btn-secondary">Filtrar</button>
      @if($term||$status||$supplierId)<a class="btn btn-light" href="{{ route('purchases.index',['month'=>$month]) }}">Limpar</a>@endif
    </form>
  </div>
  <div class="table-scroll">
    <table class="cms-table">
      <thead><tr><th>Número</th><th>Data</th><th>Fornecedor</th><th>Documento</th><th>Valor total</th><th>Situação</th><th>Financeiro</th><th class="action-cell">Ações</th></tr></thead>
      <tbody>
      @forelse($purchases as $purchase)
      <tr>
        <td><a class="table-link" href="{{ route('purchases.show',$purchase) }}">#{{ str_pad($purchase->id,5,'0',STR_PAD_LEFT) }}</a></td>
        <td class="nowrap">{{ $purchase->operation_date?->format(\App\Models\AppSetting::dateFormat()) }}</td>
        <td><strong class="table-title">{{ $purchase->supplier?->name ?? 'Fornecedor indisponível' }}</strong><small class="table-subtitle">{{ $purchase->supplier?->document ?: '—' }}</small></td>
        <td>{{ $purchase->document_number ?: '—' }}</td>
        <td class="price-strong nowrap">R$ {{ number_format((float)$purchase->total,2,',','.') }}</td>
        <td><span class="status {{ $purchase->status==='received'?'status-ok':($purchase->status==='draft'?'status-blue':'status-muted') }}">{{ match($purchase->status){'received'=>'Recebida','cancelled'=>'Cancelada',default=>'Rascunho'} }}</span></td>
        <td>{{ $purchase->generate_payable?'Conta a pagar':'Sem lançamento' }}</td>
        <td class="action-cell">
          <div class="purchase-row-actions">
            <a href="{{ route('purchases.show',$purchase) }}" class="btn-icon" data-tooltip="Detalhes" aria-label="Ver compra">@include('partials.icon',['name'=>'eye','size'=>17])</a>
            @if($purchase->status==='draft')<a href="{{ route('purchases.edit',$purchase) }}" class="btn-icon" data-tooltip="Editar rascunho" aria-label="Editar compra">@include('partials.icon',['name'=>'edit','size'=>17])</a>@endif
          </div>
        </td>
      </tr>
      @empty
      <tr><td colspan="8" class="empty-cell">Nenhuma compra encontrada neste período.</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
  @include('partials.table-footer',['paginator'=>$purchases])
</section>
@push('styles')
<link rel="stylesheet" href="{{ asset('css/purchases.css') }}?v={{ filemtime(public_path('css/purchases.css')) }}">
@endpush
@endsection
