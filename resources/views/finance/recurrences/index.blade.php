@extends('layouts.app')
@section('title','Recorrência')
@section('content')
@include('finance._nav')

<section class="cms-card finance-operational-card">
  <div class="grid-actionbar">
    <div class="grid-actions-left">
      <a class="grid-primary-action" href="{{ route('finance.recurrences.create') }}">@include('partials.icon',['name'=>'plus','size'=>17]) <span>Nova recorrência</span></a>
      <div class="grid-tool-group">
        <form method="post" action="{{ route('finance.recurrences.generate') }}">@csrf<button class="grid-tool" type="submit" data-tooltip="Gerar vencimentos pendentes">@include('partials.icon',['name'=>'refresh','size'=>17])</button></form>
        <button class="grid-tool" type="button" data-print-page data-tooltip="Imprimir">@include('partials.icon',['name'=>'print','size'=>18])</button>
        <button class="grid-tool" type="button" data-export-table="recorrencias.csv" data-tooltip="Exportar CSV">@include('partials.icon',['name'=>'download','size'=>18])</button>
      </div>
      <div class="finance-toolbar-summary" data-live-sync="recurrence-summary">
        <span class="neutral">Ativas <strong>{{ $activeCount }}</strong></span>
        <span class="positive">Base mensal <strong>R$ {{ number_format((float)$monthlyBase,2,',','.') }}</strong></span>
      </div>
    </div>
    <div class="grid-actions-right">
      <button class="grid-filter-button" type="button" data-filter-toggle="recurrence-filters" data-tooltip="Pesquisar / filtrar">@include('partials.icon',['name'=>'search','size'=>18])</button>
    </div>
  </div>

  <div class="grid-filter-panel" id="recurrence-filters" @if(!$term && !$type && !$status) hidden @endif>
    <form method="get" action="{{ route('finance.recurrences.index') }}" class="toolbar-filters" data-live-search data-live-target="recurrences-live-results">
      <input type="search" autocomplete="off" class="input-filter" name="search" value="{{ $term }}" placeholder="Descrição, contato ou palavra-chave">
      <select class="input-filter" name="type">
        <option value="">Todos os tipos</option>
        <option value="receivable" @selected($type==='receivable')>Recebimentos</option>
        <option value="payable" @selected($type==='payable')>Pagamentos</option>
      </select>
      <select class="input-filter" name="status">
        <option value="">Todas as situações</option>
        <option value="active" @selected($status==='active')>Ativas</option>
        <option value="paused" @selected($status==='paused')>Pausadas</option>
      </select>
      <button class="btn btn-secondary">Filtrar</button>
      @if($term || $type || $status)<a class="btn btn-light" href="{{ route('finance.recurrences.index') }}">Limpar</a>@endif
    </form>
  </div>

  <div id="recurrences-live-results" data-live-search-results>
  <div class="table-scroll">
    <table class="cms-table">
      <thead><tr><th>Cód</th><th>Contato</th><th>Descrição</th><th>A cada</th><th>Início</th><th>Último gerado</th><th>Próximo</th><th>Situação</th><th>Valor</th><th></th></tr></thead>
      <tbody>
      @forelse($recurrences as $r)
        <tr>
          <td>#{{ $r->id }}</td>
          <td>{{ $r->customer?->name ?? '—' }}</td>
          <td>{{ $r->description }}@if($r->keywords)<small class="table-subtitle">{{ $r->keywords }}</small>@endif</td>
          <td>{{ $r->frequency_label }}</td>
          <td class="nowrap">{{ $r->start_date->format(\App\Models\AppSetting::dateFormat()) }}</td>
          <td class="nowrap">{{ $r->last_generated_at?->format(\App\Models\AppSetting::dateFormat()) ?? '—' }}</td>
          <td class="nowrap">{{ $r->next_date?->format(\App\Models\AppSetting::dateFormat()) ?? '—' }}</td>
          <td><span class="status {{ $r->is_active?'status-ok':'status-muted' }}">{{ $r->is_active?'Ativa':'Pausada' }}</span></td>
          <td class="price-strong nowrap">R$ {{ number_format((float)$r->amount,2,',','.') }}</td>
          <td class="action-cell">
            <form method="post" action="{{ route('finance.recurrences.toggle',$r) }}">@csrf<button class="btn-icon" data-tooltip="{{ $r->is_active?'Pausar':'Ativar' }}">@include('partials.icon',['name'=>$r->is_active?'x':'check','size'=>15])</button></form>
          </td>
        </tr>
      @empty
        <tr><td colspan="10" class="empty-cell">Nenhuma recorrência encontrada.</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
  @include('partials.table-footer',['paginator'=>$recurrences])
  </div>
</section>
@endsection
