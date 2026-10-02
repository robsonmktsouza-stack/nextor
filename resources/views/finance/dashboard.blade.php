@extends('layouts.app')
@section('title','Financeiro')
@section('actions')
<a class="btn btn-success" href="{{ route('finance.entries.create',['type'=>'receivable']) }}">@include('partials.icon',['name'=>'plus','size'=>16]) Conta a receber</a>
<a class="btn btn-secondary" href="{{ route('finance.entries.create',['type'=>'payable']) }}">@include('partials.icon',['name'=>'plus','size'=>16]) Conta a pagar</a>
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
