@extends('layouts.app')
@section('title','Inutilização NFC-e')
@section('actions')
<a class="btn btn-secondary" href="{{ route('fiscal.index',['tab'=>'nfce']) }}">Voltar às NFC-e</a>
@endsection
@section('content')
@include('fiscal._nav',['tab'=>'nfce'])
<section class="cms-card">
  <div class="card-header"><div><h2>Inutilização de numeração</h2>
  <p>Somente para lacunas de números não utilizados. Uma NFC-e já emitida ou cancelada nunca deve ser inutilizada.</p></div>
  <span class="status {{ $environment==='homologation'?'status-blue':'status-danger' }}">{{ $environment==='homologation'?'Homologação — teste':'Produção — operação fiscal real' }}</span></div>
  @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
  <form method="post" action="{{ route('fiscal.nfce.inutilizations.store') }}" class="dialog-body"
    data-confirm-submit="Confirma que a faixa informada nunca foi utilizada? A inutilização será transmitida à SEFAZ e não poderá ser revertida automaticamente."
    data-confirm-title="Confirmar inutilização" data-confirm-kind="action" data-confirm-label="Confirmar inutilização">
    @csrf
    <div class="form-grid">
      <label class="field"><span>Ano</span><input type="number" name="year" min="2020" max="{{ now()->year }}" value="{{ old('year',now()->year) }}" required></label>
      <label class="field"><span>Série</span><input type="number" name="series" value="{{ old('series',$series) }}" min="0" max="999" required></label>
      <label class="field"><span>Número inicial</span><input type="number" name="first_number" value="{{ old('first_number') }}" min="1" max="{{ max(1,$nextNumber-1) }}" required></label>
      <label class="field"><span>Número final</span><input type="number" name="last_number" value="{{ old('last_number') }}" min="1" max="{{ max(1,$nextNumber-1) }}" required></label>
      <label class="field wide"><span>Justificativa</span><input type="text" name="reason" minlength="15" maxlength="255" required value="{{ old('reason') }}" placeholder="Explique a quebra de sequência da numeração"></label>
    </div>
    <p class="nfce-muted">Próximo número disponível: {{ $nextNumber }}. A faixa informada deve ser anterior a esse número e comprovadamente não utilizada.</p>
    <button class="btn btn-primary" type="submit">Solicitar inutilização à SEFAZ</button>
  </form>
</section>
<section class="cms-card" style="margin-top:16px">
  <div class="card-header"><h2>Solicitações</h2></div>
  <div class="table-scroll">
    <table class="cms-table">
      <thead><tr><th>Ambiente</th><th>Ano / Série</th><th>Faixa</th><th>Situação</th><th>Protocolo</th><th>Solicitada em</th></tr></thead>
      <tbody>
      @forelse($rows as $row)
      <tr>
        <td>{{ $row->environment==='production'?'Produção':'Homologação' }}</td>
        <td>{{ $row->year }} / {{ $row->series }}</td>
        <td>{{ $row->first_number }}–{{ $row->last_number }}</td>
        <td><span class="status {{ $row->status==='authorized'?'status-ok':($row->status==='uncertain'?'status-danger':'status-blue') }}">{{ match($row->status){'authorized'=>'Inutilizado pela SEFAZ','rejected'=>'Rejeitado','uncertain'=>'Verificar na SEFAZ',default=>'Em processamento'} }}</span></td>
        <td>{{ $row->protocol ?: '—' }}</td>
        <td>{{ $row->created_at->format('d/m/Y H:i') }}</td>
      </tr>
      @empty
      <tr><td colspan="6" class="empty-cell">Nenhuma inutilização solicitada.</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
  {{ $rows->links() }}
</section>
@endsection
