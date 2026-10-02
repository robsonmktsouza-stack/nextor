@extends('layouts.app')
@section('title',$type==='payable'?'Pagamentos':($type==='receivable'?'Recebimentos':'Lançamentos financeiros'))
@section('titleMeta'){{ $entries->total() }} registro(s)@endsection
@section('content')
@include('finance._nav')

<section class="cms-card finance-operational-card">
  <div class="grid-actionbar">
    <div class="grid-actions-left">
      <a class="grid-primary-action" href="{{ route('finance.entries.create',['type'=>$type ?: 'receivable']) }}">
        @include('partials.icon',['name'=>'plus','size'=>17]) <span>Novo</span>
      </a>
      <div class="grid-tool-group">
        <button class="grid-tool" type="button" data-print-page data-tooltip="Imprimir">@include('partials.icon',['name'=>'print','size'=>18])</button>
        <button class="grid-tool" type="button" data-export-table="financeiro.csv" data-tooltip="Exportar CSV">@include('partials.icon',['name'=>'download','size'=>18])</button>
        <button class="grid-tool" type="button" data-refresh-page data-tooltip="Atualizar">@include('partials.icon',['name'=>'refresh','size'=>18])</button>
      </div>

      <div class="bulk-actions">
        <select class="bulk-action-select" data-bulk-menu disabled aria-label="Ações em massa">
          <option value="">Ações em massa</option>
          <option value="finance-bulk-settle" data-bulk-dialog="financeBulkSettle">Baixar selecionados</option>
          <option value="finance-bulk-cancel" data-confirm="Cancelar os lançamentos selecionados? Lançamentos de vendas e itens com baixa ativa serão ignorados.">Cancelar selecionados</option>
          <option value="finance-bulk-reopen">Reabrir cancelados</option>
          <option value="local:export">Exportar selecionados</option>
          <option value="local:print">Imprimir selecionados</option>
        </select>
        <button class="bulk-apply" type="button" data-bulk-apply disabled>Aplicar</button>
      </div>
      <span class="selection-count" data-selection-count hidden></span>

      <form id="finance-bulk-cancel" method="post" action="{{ route('finance.entries.bulk-action') }}" hidden>
        @csrf
        <input type="hidden" name="action" value="cancel">
      </form>
      <form id="finance-bulk-reopen" method="post" action="{{ route('finance.entries.bulk-action') }}" hidden>
        @csrf
        <input type="hidden" name="action" value="reopen">
      </form>

      <div class="finance-list-totals">
        <span>Total listado <strong>R$ {{ number_format((float)$totalAmount,2,',','.') }}</strong></span>
        <span>Em aberto <strong>R$ {{ number_format((float)$openAmount,2,',','.') }}</strong></span>
      </div>
    </div>

    <div class="grid-actions-right">
      <a class="period-current" href="{{ route('finance.entries',array_merge(request()->except('page','month'),['month'=>now()->format('Y-m')])) }}">Mês atual</a>
      <a class="period-arrow" href="{{ route('finance.entries',array_merge(request()->except('page','month'),['month'=>$prevMonth])) }}">@include('partials.icon',['name'=>'chevron-left','size'=>19])</a>
      <span class="period-label">{{ $monthLabel }}</span>
      <a class="period-arrow" href="{{ route('finance.entries',array_merge(request()->except('page','month'),['month'=>$nextMonth])) }}">@include('partials.icon',['name'=>'chevron','size'=>19])</a>
      <button class="grid-filter-button" type="button" data-filter-toggle="finance-filters" data-tooltip="Pesquisar / filtrar">@include('partials.icon',['name'=>'search','size'=>18])</button>
    </div>
  </div>

  <div class="grid-filter-panel" id="finance-filters" @if(!$term && !$status && !$categoryId) hidden @endif>
    <form method="get" class="toolbar-filters">
      <input type="hidden" name="month" value="{{ $month }}">
      @if($type)<input type="hidden" name="type" value="{{ $type }}">@endif
      <input class="input-filter" name="search" value="{{ $term }}" placeholder="Descrição, contato, documento ou palavra-chave">
      <select class="input-filter" name="status">
        <option value="">Todas as situações</option>
        <option value="open" @selected($status==='open')>Em aberto</option>
        <option value="partial" @selected($status==='partial')>Parcial</option>
        <option value="paid" @selected($status==='paid')>Baixado</option>
        <option value="overdue" @selected($status==='overdue')>Vencido</option>
        <option value="cancelled" @selected($status==='cancelled')>Cancelado</option>
      </select>
      <select class="input-filter" name="category_id">
        <option value="">Todas as categorias</option>
        @foreach($categories as $category)<option value="{{ $category->id }}" @selected($categoryId===$category->id)>{{ $category->name }}</option>@endforeach
      </select>
      <button class="btn btn-secondary">Filtrar</button>
      @if($term || $status || $categoryId)<a class="btn btn-light" href="{{ route('finance.entries',['type'=>$type,'month'=>$month]) }}">Limpar</a>@endif
    </form>
  </div>

  <div class="table-scroll">
    <table class="cms-table finance-main-table">
      <thead>
        <tr>
          <th class="select-cell"><input type="checkbox" data-check-all aria-label="Selecionar todos"></th><th>Cód</th><th>Descrição</th><th>Contato</th><th>Conta</th><th>Data</th><th>Situação</th><th>Valor</th><th class="action-cell"></th>
        </tr>
      </thead>
      <tbody>
      @forelse($entries as $entry)
        @php
          $display=$entry->display_status;
          $statusClass=match($display){'paid'=>'status-ok','partial'=>'finance-status-partial','overdue'=>'finance-status-overdue','cancelled'=>'status-muted',default=>'status-blue'};
        @endphp
        <tr class="{{ $display==='overdue'?'finance-overdue-row':'' }}">
          <td class="select-cell"><input type="checkbox" data-row-select value="{{ $entry->id }}" aria-label="Selecionar lançamento {{ $entry->id }}"></td>
          <td><a class="table-link" href="{{ route('finance.entries.show',$entry) }}">#{{ $entry->id }}</a></td>
          <td>
            <a class="table-link" href="{{ route('finance.entries.show',$entry) }}">{{ $entry->description }}</a>
            @if($entry->keywords)<small class="table-subtitle">{{ $entry->keywords }}</small>@endif
          </td>
          <td>{{ $entry->customer?->name ?? '—' }}</td>
          <td>{{ $entry->account?->name ?? '—' }}</td>
          <td class="nowrap">{{ $entry->due_date->format('d/m/Y') }}</td>
          <td><span class="status {{ $statusClass }}">{{ $entry->status_label }}</span></td>
          <td class="price-strong nowrap">R$ {{ number_format((float)$entry->amount,2,',','.') }}</td>
          <td class="action-cell"><a class="btn-icon" href="{{ route('finance.entries.show',$entry) }}" data-tooltip="Abrir">@include('partials.icon',['name'=>'chevron','size'=>16])</a></td>
        </tr>
      @empty
        <tr><td colspan="9" class="empty-cell">Nenhum {{ $type==='payable'?'pagamento':'recebimento' }} encontrado neste mês.</td></tr>
      @endforelse
      </tbody>
      <tfoot><tr><td colspan="7"><strong>TOTAL LISTADO ({{ $entries->total() }} itens)</strong></td><td class="price-strong">R$ {{ number_format((float)$totalAmount,2,',','.') }}</td><td></td></tr></tfoot>
    </table>
  </div>
  @include('partials.table-footer',['paginator'=>$entries])
</section>

<dialog class="erp-dialog small-dialog" id="financeBulkSettle">
  <form method="post" action="{{ route('finance.entries.bulk-action') }}" id="finance-bulk-settle">
    @csrf
    <input type="hidden" name="action" value="settle">

    <div class="dialog-header">
      <div>
        <h2>Baixar lançamentos selecionados</h2>
        <p data-bulk-dialog-count>Lançamentos selecionados</p>
      </div>
      <button type="button" class="close-dialog" data-dialog-close aria-label="Fechar">×</button>
    </div>

    <div class="dialog-body">
      <div class="form-grid">
        <label class="field wide">
          <span>Conta financeira *</span>
          <select name="financial_account_id" required>
            <option value="">Selecione a conta</option>
            @foreach($accounts as $account)
              <option value="{{ $account->id }}">{{ $account->name }}</option>
            @endforeach
          </select>
        </label>

        <label class="field">
          <span>Data da baixa *</span>
          <input type="date" name="settled_at" value="{{ now()->toDateString() }}" required>
        </label>

        <label class="field">
          <span>Forma de pagamento *</span>
          <select name="payment_method" required>
            <option value="">Selecione</option>
            @foreach($paymentMethods as $key=>$label)
              <option value="{{ $key }}">{{ $label }}</option>
            @endforeach
          </select>
        </label>
      </div>

      <p class="inline-note">
        Cada lançamento selecionado será baixado pelo saldo integral ainda em aberto. Itens já pagos ou cancelados serão ignorados.
      </p>
    </div>

    <div class="dialog-footer">
      <button type="button" class="btn btn-secondary" data-dialog-close>Cancelar</button>
      <button type="submit" class="btn btn-primary">Confirmar baixas</button>
    </div>
  </form>
</dialog>
@endsection
