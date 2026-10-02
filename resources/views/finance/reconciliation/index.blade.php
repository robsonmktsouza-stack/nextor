@extends('layouts.app')
@section('title','Conciliação bancária')
@section('content')
@include('finance._nav')

<section class="cms-card finance-operational-card">
  <div class="grid-actionbar">
    <div class="grid-actions-left">
      <a class="grid-primary-action" href="{{ route('finance.reconciliation.import') }}">@include('partials.icon',['name'=>'plus','size'=>17]) <span>Importar extrato</span></a>
      <div class="grid-tool-group">
        <button class="grid-tool" type="button" data-print-page data-tooltip="Imprimir">@include('partials.icon',['name'=>'print','size'=>18])</button>
        <button class="grid-tool" type="button" data-export-table="conciliacao.csv" data-tooltip="Exportar CSV">@include('partials.icon',['name'=>'download','size'=>18])</button>
        <button class="grid-tool" type="button" data-refresh-page data-tooltip="Atualizar">@include('partials.icon',['name'=>'refresh','size'=>18])</button>
      </div>
    </div>
  </div>

  @if($selected)
    <div class="finance-reconcile-summary">
      <span>Movimentos <strong>{{ $summary['count'] }}</strong></span>
      <span class="neutral">Pendentes <strong>{{ $summary['pending'] }}</strong></span>
      <span class="positive">Conciliados <strong>{{ $summary['reconciled'] }}</strong></span>
      <span class="positive">Créditos <strong>R$ {{ number_format((float)$summary['credits'],2,',','.') }}</strong></span>
      <span class="negative">Débitos <strong>R$ {{ number_format((float)$summary['debits'],2,',','.') }}</strong></span>
    </div>
  @endif

  <div class="finance-reconcile-layout">
    <aside class="finance-import-list">
      <h3>Arquivos importados</h3>
      @forelse($imports as $imp)
        <a class="{{ $selected?->id===$imp->id?'active':'' }}" href="{{ route('finance.reconciliation.index',['import'=>$imp->id]) }}">
          <strong>{{ $imp->original_name }}</strong>
          <span>{{ $imp->account->name }} · {{ $imp->transactions_count }} lançamentos</span>
        </a>
      @empty
        <p>Nenhum extrato importado.</p>
      @endforelse
    </aside>

    <div class="table-scroll finance-reconcile-table">
      @if($selected)
        <div class="finance-import-heading">
          <strong>{{ $selected->original_name }}</strong>
          <span>{{ $selected->account->name }} · {{ $selected->period_start?->format('d/m/Y') }} a {{ $selected->period_end?->format('d/m/Y') }}</span>
        </div>
        <table class="cms-table">
          <thead><tr><th>Data</th><th>Histórico</th><th>Valor</th><th>Situação</th><th>Conciliação</th></tr></thead>
          <tbody>
          @foreach($transactions as $tx)
            <tr>
              <td class="nowrap">{{ $tx->transaction_date->format('d/m/Y') }}</td>
              <td>{{ $tx->description }}@if($tx->external_id)<small class="table-subtitle">{{ $tx->external_id }}</small>@endif</td>
              <td class="{{ (float)$tx->amount>=0?'finance-positive':'finance-negative' }} nowrap">R$ {{ number_format((float)$tx->amount,2,',','.') }}</td>
              <td>@if($tx->reconciled_at)<span class="status status-ok">Conciliado</span>@else<span class="status status-blue">Pendente</span>@endif</td>
              <td>
                @if($tx->reconciled_at)
                  <div class="finance-match">
                    <span>{{ $tx->entry?->description ?? 'Lançamento removido' }}</span>
                    <form method="post" action="{{ route('finance.reconciliation.unmatch',$tx) }}">@csrf<button class="btn btn-light">Desfazer</button></form>
                  </div>
                @else
                  @php($candidates=$candidateMap[$tx->id] ?? collect())
                  @if($candidates->isNotEmpty())
                    <form class="finance-match" method="post" action="{{ route('finance.reconciliation.match',$tx) }}">
                      @csrf
                      <select name="entry_id">
                        @foreach($candidates as $candidate)
                          <option value="{{ $candidate->id }}">{{ $candidate->description }} — R$ {{ number_format($candidate->balance,2,',','.') }}</option>
                        @endforeach
                      </select>
                      <button class="btn btn-primary">Conciliar</button>
                    </form>
                  @else
                    <span class="table-subtitle">Nenhum lançamento com mesmo valor em ±15 dias.</span>
                  @endif
                @endif
              </td>
            </tr>
          @endforeach
          </tbody>
        </table>
      @else
        <div class="empty-cell">Importe um extrato OFX, CSV ou XLSX para iniciar.</div>
      @endif
    </div>
  </div>
</section>
@endsection
