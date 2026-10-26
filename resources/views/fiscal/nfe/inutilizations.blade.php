@extends('layouts.app')
@section('title','Inutilização NF-e')
@section('actions')
<a class="btn btn-secondary" href="{{ route('fiscal.index',['tab'=>'nfe']) }}">Voltar às NF-e</a>
@endsection
@section('content')
@include('fiscal._nav',['tab'=>'nfe'])
@if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
<section class="cms-card">
  <form method="post" action="{{ route('fiscal.nfe.inutilizations.store') }}" class="dialog-body">
    @csrf
    <div class="form-grid">
      <label class="field"><span>Ambiente</span><input readonly value="{{ $environment==='homologation'?'Homologação — teste':'Produção — indisponível' }}"></label>
      <label class="field"><span>Ano</span><input type="number" name="year" min="2020" max="{{ now()->year }}" value="{{ old('year',now()->year) }}" required></label>
      <label class="field"><span>Série</span><input type="number" name="series" min="0" max="999" value="{{ old('series',$series) }}" required></label>
      <label class="field"><span>Número inicial</span><input type="number" name="first_number" min="1" max="{{ max(1,$nextNumber-1) }}" value="{{ old('first_number') }}" required></label>
      <label class="field"><span>Número final</span><input type="number" name="last_number" min="1" max="{{ max(1,$nextNumber-1) }}" value="{{ old('last_number') }}" required></label>
      <label class="field wide"><span>Justificativa</span><input name="reason" minlength="15" maxlength="255" value="{{ old('reason') }}" required></label>
      <label class="field"><span>Senha do responsável</span><input type="password" name="current_password" autocomplete="current-password" required></label>
      <label class="field wide"><span><input type="checkbox" name="confirmed_unused" value="1" required @checked(old('confirmed_unused'))> Confirmo que a faixa nunca foi utilizada, inclusive fora deste ERP.</span></label>
    </div>
    <p>Próxima NF-e: {{ $nextNumber }}. A inutilização confirmada pela SEFAZ é irreversível; não use esta operação para cancelar uma nota.</p>
    <button class="btn btn-primary" type="submit" @disabled($environment!=='homologation')>Solicitar inutilização</button>
  </form>
</section>
<section class="cms-card" style="margin-top:12px">
  <div class="table-scroll">
    <table class="cms-table">
      <thead><tr><th>Ambiente</th><th>Ano / Série</th><th>Faixa</th><th>Situação</th><th>Protocolo</th><th>Solicitada em</th><th>Documento</th></tr></thead>
      <tbody>
      @forelse($rows as $row)
        <tr>
          <td>{{ $row->environment==='homologation'?'Homologação':'Produção' }}</td>
          <td>{{ $row->year }} / {{ $row->series }}</td>
          <td>{{ $row->first_number }}–{{ $row->last_number }}</td>
          <td>{{ match($row->status){'authorized'=>'Inutilizado pela SEFAZ','prepared'=>'Não transmitido — conferir serviço','uncertain'=>'Resultado incerto — conferir SEFAZ','rejected'=>'Rejeitado',default=>'Em processamento'} }}
            @php($inutilizationGuidance=app(\App\Services\Fiscal\SefazRejectionGuidance::class)->forInutilization($row))
            @if($inutilizationGuidance)
              <details class="sefaz-inutilization-guidance"><summary>Ver orientação</summary>
                @include('fiscal.partials.sefaz-guidance',['guidance'=>$inutilizationGuidance])
              </details>
            @endif
          </td>
          <td>{{ $row->protocol ?: '—' }}</td>
          <td>{{ $row->created_at->format('d/m/Y H:i') }}</td>
          <td>
            @if($row->status==='authorized' && $row->xml_path)
              <a href="{{ route('fiscal.nfe.inutilizations.xml',$row) }}">XML</a>
            @elseif($row->attempted_at===null && in_array($row->status,['prepared','pending','uncertain'],true) && auth()->user()?->canAccess('settings'))
              <details><summary>Reagendar pedido</summary>
                <form method="post" action="{{ route('fiscal.nfe.inutilizations.retry',$row) }}">
                  @csrf
                  <label class="field"><span>Senha do responsável</span><input type="password" name="current_password" autocomplete="current-password" required></label>
                  <label><input type="checkbox" name="confirmed_unused" value="1" required> Confirmo que a faixa continua sem utilização.</label>
                  <button class="btn btn-secondary" type="submit">Reagendar pedido</button>
                </form>
              </details>
            @else — @endif
          </td>
        </tr>
      @empty
        <tr><td colspan="7" class="empty-cell">Nenhuma inutilização solicitada.</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
  @include('partials.table-footer',['paginator'=>$rows])
</section>
@endsection
