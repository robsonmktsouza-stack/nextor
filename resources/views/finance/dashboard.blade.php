@extends('layouts.app')
@section('title','Financeiro')
@section('actions')
<a class="btn btn-success" href="{{ route('finance.entries.create',['type'=>'receivable']) }}">@include('partials.icon',['name'=>'plus','size'=>16]) Conta a receber</a>
<a class="btn btn-secondary" href="{{ route('finance.entries.create',['type'=>'payable']) }}">@include('partials.icon',['name'=>'plus','size'=>16]) Conta a pagar</a>
@push('styles')
<style>
.finance-chart-card{margin:0 0 16px;border:1px solid #d5dfe8;border-radius:4px;background:#fff;overflow:hidden}
.finance-chart-card .card-header{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:13px 16px;border-bottom:1px solid #e3eaf0}
.finance-chart-card .card-header h2{font-size:14px;margin:0}
.finance-chart-card .card-header p{font-size:11px;color:#708398;margin:3px 0 0}
.finance-chart-legend{display:flex;gap:16px;font-size:11px;color:#597085;white-space:nowrap}
.finance-chart-legend span:before{content:"";display:inline-block;width:9px;height:9px;margin-right:5px;border-radius:2px;background:#2b9c72}
.finance-chart-legend .expense:before{background:#d58b56}
.finance-chart-content{padding:14px 18px;max-height:280px;overflow-y:auto}
.finance-chart-row{display:grid;grid-template-columns:46px minmax(0,1fr) 116px;gap:12px;align-items:center;min-height:31px}
.finance-chart-date{font-size:11px;color:#526d82}
.finance-chart-bars{display:flex;flex-direction:column;justify-content:center;gap:3px;min-height:24px}
.finance-chart-bar{height:8px;border-radius:2px;min-width:0}
.finance-chart-bar.income{background:#2b9c72}
.finance-chart-bar.expense{background:#d58b56}
.finance-chart-value{text-align:right;font-size:11px;color:#3a5268;font-weight:600}
@media(max-width:600px){.finance-chart-card .card-header{align-items:flex-start;flex-direction:column}.finance-chart-row{grid-template-columns:37px minmax(0,1fr) 90px;gap:7px}.finance-chart-content{padding:12px}}
</style>
@endpush
@endsection

@section('content')
@include('finance._nav')

<div class="finance-periodbar">
  <a class="period-current" href="{{ route('finance.dashboard',['month'=>now()->format('Y-m')]) }}">Mês atual</a>
  <a class="period-arrow" href="{{ route('finance.dashboard',['month'=>$prevMonth]) }}" aria-label="Mês anterior">@include('partials.icon',['name'=>'chevron-left','size'=>19])</a>
  <strong>{{ $monthLabel }}</strong>
  <a class="period-arrow" href="{{ route('finance.dashboard',['month'=>$nextMonth]) }}" aria-label="Próximo mês">@include('partials.icon',['name'=>'chevron','size'=>19])</a>
</div>

<div class="stats-grid finance-stats">
  <a class="stat-card" href="{{ route('finance.entries',['type'=>'receivable','status'=>'paid','month'=>$month]) }}">
    <span class="stat-icon green">@include('partials.icon',['name'=>'money','size'=>21])</span>
    <span class="stat-label">Recebido no mês</span>
    <strong>R$ {{ number_format((float)$received,2,',','.') }}</strong>
    <small>Baixas efetivamente recebidas</small>
  </a>

  <a class="stat-card" href="{{ route('finance.entries',['type'=>'payable','status'=>'paid','month'=>$month]) }}">
    <span class="stat-icon orange">@include('partials.icon',['name'=>'money','size'=>21])</span>
    <span class="stat-label">Pago no mês</span>
    <strong>R$ {{ number_format((float)$paid,2,',','.') }}</strong>
    <small>Saídas efetivamente pagas</small>
  </a>

  <a class="stat-card" href="{{ route('finance.entries',['type'=>'receivable','month'=>$month]) }}">
    <span class="stat-icon blue">@include('partials.icon',['name'=>'clock','size'=>21])</span>
    <span class="stat-label">A receber no mês</span>
    <strong>R$ {{ number_format((float)$receivable,2,',','.') }}</strong>
    <small>@if((float)$overdueReceivable>0) Vencido: R$ {{ number_format((float)$overdueReceivable,2,',','.') }} @else Sem recebíveis vencidos @endif</small>
  </a>

  <a class="stat-card" href="{{ route('finance.entries',['type'=>'payable','month'=>$month]) }}">
    <span class="stat-icon violet">@include('partials.icon',['name'=>'receipt','size'=>21])</span>
    <span class="stat-label">A pagar no mês</span>
    <strong>R$ {{ number_format((float)$payable,2,',','.') }}</strong>
    <small>@if((float)$overduePayable>0) Vencido: R$ {{ number_format((float)$overduePayable,2,',','.') }} @else Sem contas vencidas @endif</small>
  </a>
</div>

@php
  $flowMax = max(1, (float) $cashFlow->max(fn ($row) => max((float)$row->income,(float)$row->expense)));
@endphp
<section class="cms-card finance-chart-card" aria-label="Gráfico do fluxo de caixa realizado">
  <div class="card-header"><div><h2>Entradas e saídas realizadas</h2><p>Comparação diária das baixas no período selecionado</p></div><div class="finance-chart-legend"><span class="income">Entradas</span><span class="expense">Saídas</span></div></div>
  <div class="finance-chart-content">
    @forelse($cashFlow as $row)
      <div class="finance-chart-row">
        <span class="finance-chart-date">{{ date('d/m',strtotime((string)$row->day)) }}</span>
        <div class="finance-chart-bars">
          <div class="finance-chart-bar income" style="width:{{ round(((float)$row->income/$flowMax)*100,2) }}%" title="Entradas: R$ {{ number_format((float)$row->income,2,',','.') }}"></div>
          <div class="finance-chart-bar expense" style="width:{{ round(((float)$row->expense/$flowMax)*100,2) }}%" title="Saídas: R$ {{ number_format((float)$row->expense,2,',','.') }}"></div>
        </div>
        <span class="finance-chart-value">R$ {{ number_format((float)$row->income-(float)$row->expense,2,',','.') }}</span>
      </div>
    @empty
      <div class="empty-cell">Nenhuma entrada ou saída realizada no período.</div>
    @endforelse
  </div>
</section>
<div class="finance-dashboard-grid">
  <section class="cms-card">
    <div class="card-header">
      <div><h2>Fluxo realizado</h2><p>Entradas e saídas por dia no mês selecionado</p></div>
    </div>
    <div class="table-scroll">
      <table class="cms-table">
        <thead><tr><th>Data</th><th>Entradas</th><th>Saídas</th><th>Saldo do dia</th></tr></thead>
        <tbody>
        @forelse($cashFlow as $row)
          @php($net=(float)$row->income-(float)$row->expense)
          <tr>
            <td>{{ date('d/m/Y', strtotime((string)$row->day)) }}</td>
            <td class="finance-positive">R$ {{ number_format((float)$row->income,2,',','.') }}</td>
            <td class="finance-negative">R$ {{ number_format((float)$row->expense,2,',','.') }}</td>
            <td class="{{ $net<0?'finance-negative':'price-strong' }}">R$ {{ number_format($net,2,',','.') }}</td>
          </tr>
        @empty
          <tr><td colspan="4" class="empty-cell">Nenhuma baixa registrada neste mês.</td></tr>
        @endforelse
        </tbody>
      </table>
    </div>
  </section>

  <section class="cms-card">
    <div class="card-header">
      <div><h2>Contas financeiras</h2><p>Saldo realizado por caixa/banco</p></div>
      <a class="minor-link" href="{{ route('finance.settings') }}">Configurar →</a>
    </div>
    <div class="finance-account-list">
      @forelse($accounts as $account)
        <div class="finance-account-row">
          <span>
            <strong>{{ $account->name }}</strong>
            <small>{{ match($account->type){'bank'=>'Banco','digital'=>'Conta digital','other'=>'Outra',default=>'Caixa'} }}</small>
          </span>
          <strong class="{{ $account->current_balance<0?'finance-negative':'finance-positive' }}">
            R$ {{ number_format((float)$account->current_balance,2,',','.') }}
          </strong>
        </div>
      @empty
        <div class="empty-cell">Nenhuma conta financeira ativa.</div>
      @endforelse
    </div>
  </section>
</div>

<div class="finance-dashboard-grid">
  <section class="cms-card">
    <div class="card-header">
      <div><h2>Próximos 7 dias</h2><p>Compromissos e recebimentos a vencer</p></div>
      <a class="minor-link" href="{{ route('finance.entries') }}">Ver lançamentos →</a>
    </div>
    <div class="table-scroll">
      <table class="cms-table">
        <thead><tr><th>Vencimento</th><th>Tipo</th><th>Descrição</th><th>Saldo</th></tr></thead>
        <tbody>
        @forelse($upcoming as $entry)
          <tr>
            <td class="nowrap">{{ $entry->due_date->format(\App\Models\AppSetting::dateFormat()) }}</td>
            <td><span class="status {{ $entry->type==='receivable'?'status-ok':'status-blue' }}">{{ $entry->type_label }}</span></td>
            <td><a class="table-link" href="{{ route('finance.entries.show',$entry) }}">{{ $entry->description }}</a><small class="table-subtitle">{{ $entry->customer?->name ?? $entry->category?->name ?? '—' }}</small></td>
            <td class="price-strong">R$ {{ number_format($entry->balance,2,',','.') }}</td>
          </tr>
        @empty
          <tr><td colspan="4" class="empty-cell">Nenhum vencimento nos próximos 7 dias.</td></tr>
        @endforelse
        </tbody>
      </table>
    </div>
  </section>

  <section class="cms-card">
    <div class="card-header">
      <div><h2>Últimos lançamentos</h2><p>Movimentações cadastradas recentemente</p></div>
    </div>
    <div class="table-scroll">
      <table class="cms-table">
        <thead><tr><th>Descrição</th><th>Vencimento</th><th>Valor</th></tr></thead>
        <tbody>
        @forelse($recentEntries as $entry)
          <tr>
            <td><a class="table-link" href="{{ route('finance.entries.show',$entry) }}">{{ $entry->description }}</a><small class="table-subtitle">{{ $entry->type_label }} · {{ $entry->status_label }}</small></td>
            <td>{{ $entry->due_date->format(\App\Models\AppSetting::dateFormat()) }}</td>
            <td class="price-strong">R$ {{ number_format((float)$entry->amount,2,',','.') }}</td>
          </tr>
        @empty
          <tr><td colspan="3" class="empty-cell">Nenhum lançamento financeiro.</td></tr>
        @endforelse
        </tbody>
      </table>
    </div>
  </section>
</div>
@endsection
