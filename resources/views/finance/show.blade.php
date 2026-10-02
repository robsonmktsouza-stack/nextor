@extends('layouts.app')
@section('title',$entry->type_label.' #'.str_pad($entry->id,5,'0',STR_PAD_LEFT))
@section('actions')
<a class="btn btn-secondary" href="{{ route('finance.entries',['type'=>$entry->type]) }}">@include('partials.icon',['name'=>'arrow-left','size'=>16]) Voltar</a>
@if($entry->sale_id===null && $entry->status!=='cancelled')
<a class="btn btn-secondary" href="{{ route('finance.entries.edit',$entry) }}">@include('partials.icon',['name'=>'edit','size'=>16]) Editar</a>
@endif
@if(in_array($entry->status,['open','partial'],true))
<button type="button" class="btn btn-success" data-dialog-open="finance-settle">@include('partials.icon',['name'=>'check','size'=>16]) Registrar baixa</button>
@endif
@endsection

@section('content')
@php
  $display=$entry->display_status;
  $statusClass=match($display){
    'paid'=>'status-ok','partial'=>'finance-status-partial','overdue'=>'finance-status-overdue',
    'cancelled'=>'status-muted',default=>'status-blue'
  };
@endphp

@include('finance._nav')

<div class="detail-grid finance-detail-grid">
  <section class="cms-card">
    <div class="card-header">
      <div><h2>{{ $entry->description }}</h2><p>{{ $entry->document_number ?: 'Sem número de documento' }}</p></div>
      <span class="status {{ $statusClass }}">{{ $entry->status_label }}</span>
    </div>

    <div class="finance-summary-strip">
      <div><span>Valor</span><strong>R$ {{ number_format((float)$entry->amount,2,',','.') }}</strong></div>
      <div><span>Baixado</span><strong>R$ {{ number_format((float)$entry->paid_amount,2,',','.') }}</strong></div>
      <div><span>Saldo</span><strong>R$ {{ number_format($entry->balance,2,',','.') }}</strong></div>
      <div><span>Vencimento</span><strong>{{ $entry->due_date->format('d/m/Y') }}</strong></div>
    </div>

    @if($billingSummary)
      <div class="finance-billing-box">
        <div class="finance-billing-head">
          <div>
            <h3>Cobrança</h3>
            <p>Valor atualizado conforme as configurações de cobrança.</p>
          </div>
          <strong>R$ {{ number_format((float)$billingSummary['charge'],2,',','.') }}</strong>
        </div>

        <div class="finance-billing-summary">
          <span>Saldo: R$ {{ number_format((float)$billingSummary['balance'],2,',','.') }}</span>
          @if((float)$billingSummary['fine']>0)<span>Multa: R$ {{ number_format((float)$billingSummary['fine'],2,',','.') }}</span>@endif
          @if((float)$billingSummary['interest']>0)<span>Juros: R$ {{ number_format((float)$billingSummary['interest'],2,',','.') }}</span>@endif
          @if((int)$billingSummary['days_late']>0)<span>{{ $billingSummary['days_late'] }} dia(s) em atraso</span>@endif
        </div>

        @if($billingSummary['pix_payload'])
          <label class="field finance-pix-field">
            <span>PIX Copia e Cola</span>
            <textarea readonly rows="3" data-copy-source="finance-pix-{{ $entry->id }}">{{ $billingSummary['pix_payload'] }}</textarea>
          </label>
          <div class="finance-billing-actions">
            <button type="button" class="btn btn-secondary" data-copy-target="finance-pix-{{ $entry->id }}">
              @include('partials.icon',['name'=>'copy','size'=>15]) Copiar PIX
            </button>
          </div>
        @elseif($billingSummary['pix_key'])
          <div class="inline-note">A chave PIX foi configurada, mas não foi possível gerar a cobrança.</div>
        @endif

        @if($billingSummary['instructions'])
          <div class="inline-note">{{ $billingSummary['instructions'] }}</div>
        @endif
      </div>
    @endif

    <div class="card-header finance-history-header">
      <div><h2>Baixas e estornos</h2><p>Histórico financeiro do lançamento</p></div>
    </div>

    <div class="table-scroll">
      <table class="cms-table">
        <thead><tr><th>Data</th><th>Conta</th><th>Forma</th><th>Valor</th><th>Situação</th><th class="action-cell">Ação</th></tr></thead>
        <tbody>
        @forelse($entry->settlements as $settlement)
          <tr>
            <td>{{ $settlement->settled_at->format('d/m/Y') }}</td>
            <td>{{ $settlement->account?->name ?? '—' }}</td>
            <td>{{ $paymentMethods[$settlement->payment_method] ?? 'Não informado' }}</td>
            <td class="price-strong">R$ {{ number_format((float)$settlement->amount,2,',','.') }}</td>
            <td>
              @if($settlement->reversed_at)
                <span class="status status-muted">Estornado</span>
                @if($settlement->reversal_reason)<small class="table-subtitle">{{ $settlement->reversal_reason }}</small>@endif
              @else
                <span class="status status-ok">Efetivado</span>
              @endif
            </td>
            <td class="action-cell">
              @if(!$settlement->reversed_at && $entry->status!=='cancelled')
                <form action="{{ route('finance.settlements.reverse',$settlement) }}" method="post" data-confirm="Estornar esta baixa? O saldo do lançamento será reaberto.">
                  @csrf
                  <button class="btn-icon" type="submit" aria-label="Estornar baixa" data-tooltip="Estornar">@include('partials.icon',['name'=>'refresh','size'=>16])</button>
                </form>
              @endif
            </td>
          </tr>
        @empty
          <tr><td colspan="6" class="empty-cell">Nenhuma baixa registrada.</td></tr>
        @endforelse
        </tbody>
      </table>
    </div>
  </section>

  <aside class="cms-card">
    <div class="card-header"><div><h2>Dados do lançamento</h2><p>Origem e classificação</p></div></div>
    <div class="detail-meta">
      <div><span>Tipo</span><strong>{{ $entry->type_label }}</strong></div>
      <div><span>Categoria</span><strong>{{ $entry->category?->name ?? '—' }}</strong></div>
      <div><span>Pessoa</span><strong>{{ $entry->customer?->name ?? '—' }}</strong></div>
      <div><span>Emissão</span><strong>{{ $entry->issue_date->format('d/m/Y') }}</strong></div>
      <div><span>Competência</span><strong>{{ $entry->competence_date?->format('d/m/Y') ?? '—' }}</strong></div>
      <div><span>Conta</span><strong>{{ $entry->account?->name ?? '—' }}</strong></div>
      @if($entry->credit_date)<div><span>Previsão de crédito</span><strong>{{ $entry->credit_date->format('d/m/Y') }}</strong></div>@endif
      @if($entry->keywords)<div><span>Palavras-chave</span><strong>{{ $entry->keywords }}</strong></div>@endif
      @if($entry->attachment_path)<div><span>Anexo</span><strong><a class="table-link" href="{{ route('finance.entries.attachment',$entry) }}">{{ $entry->attachment_name }}</a></strong></div>@endif
      <div><span>Forma prevista</span><strong>{{ $paymentMethods[$entry->payment_method] ?? 'Não informada' }}</strong></div>
      @if($entry->sale)
        <div><span>Origem</span><strong><a class="table-link" href="{{ route('sales.show',$entry->sale) }}">Venda #{{ str_pad($entry->sale_id,5,'0',STR_PAD_LEFT) }}</a></strong></div>
      @else
        <div><span>Origem</span><strong>Lançamento manual</strong></div>
      @endif
      <div><span>Cadastrado por</span><strong>{{ $entry->creator?->name ?? 'Sistema' }}</strong></div>
      @if($entry->notes)<div><span>Observações</span><strong>{{ $entry->notes }}</strong></div>@endif
    </div>

    @if($entry->sale_id===null && $entry->status!=='cancelled')
      <div class="finance-side-actions">
        <form action="{{ route('finance.entries.cancel',$entry) }}" method="post" data-confirm="Cancelar este lançamento financeiro?">
          @csrf
          <button class="btn btn-danger-outline" type="submit">@include('partials.icon',['name'=>'x','size'=>16]) Cancelar lançamento</button>
        </form>
      </div>
    @endif
  </aside>
</div>

@if(in_array($entry->status,['open','partial'],true))
<dialog class="erp-dialog small-dialog" id="finance-settle">
  <form action="{{ route('finance.entries.settle',$entry) }}" method="post">
    @csrf
    <div class="dialog-header">
      <div><h2>Registrar baixa</h2><p>Saldo atual: R$ {{ number_format($entry->balance,2,',','.') }}</p></div>
      <button type="button" data-dialog-close class="close-dialog">@include('partials.icon',['name'=>'x'])</button>
    </div>
    <div class="dialog-body">
      <div class="editor-grid cols-12">
        <label class="field col-6">
          <span>Valor</span>
          <input type="number" data-number-kind="money" name="amount" step="0.01" min="0.01" max="{{ number_format($entry->balance,2,'.','') }}" value="{{ number_format($entry->balance,2,'.','') }}" required>
        </label>
        <label class="field col-6">
          <span>Data</span>
          <input type="date" name="settled_at" value="{{ today()->format('Y-m-d') }}" required>
        </label>
        <label class="field col-6">
          <span>Conta</span>
          <select name="financial_account_id" required>
            @foreach($accounts as $account)<option value="{{ $account->id }}">{{ $account->name }}</option>@endforeach
          </select>
        </label>
        <label class="field col-6">
          <span>Forma</span>
          <select name="payment_method" required>
            @foreach($paymentMethods as $value=>$label)
              <option value="{{ $value }}" @selected(($entry->payment_method ?: 'other')===$value)>{{ $label }}</option>
            @endforeach
          </select>
        </label>
        <label class="field col-12"><span>Observação</span><textarea name="notes" rows="3"></textarea></label>
      </div>
    </div>
    <div class="dialog-footer">
      <button type="button" data-dialog-close class="btn btn-secondary">Voltar</button>
      <button class="btn btn-success">@include('partials.icon',['name'=>'check','size'=>16]) Confirmar baixa</button>
    </div>
  </form>
</dialog>
@endif
@endsection
