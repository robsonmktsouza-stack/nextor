@extends('layouts.main')
@section('title','Automações')
@section('actions')
<form method="post" action="{{ route('automations.run') }}">@csrf
<button class="btn primary" type="submit" data-confirm="Executar agora as regras habilitadas? Serão gerados somente avisos internos."><svg><use href="#i-check"/></svg>Executar verificação</button>
</form>
@endsection
@section('content')
<section class="panel">
<div class="panel-heading"><h2>Regras de monitoramento</h2><span class="panel-description">Ative apenas o que desejar. Nenhuma rotina envia e-mails, cobra ou suspende clientes automaticamente.</span></div>
<div class="automation-list">
@foreach($rules as $rule)
<form class="automation-rule" method="post" action="{{ route('automations.update',$rule) }}">
@csrf @method('PUT')
<div class="automation-rule-details">
<strong>{{ $rule->name }}</strong>
<p>{{ $rule->description }}</p>
<small>Última execução: {{ $rule->last_run_at?->format('d/m/Y H:i') ?? 'Nunca' }}</small>
</div>
<div class="automation-rule-controls">
@if($rule->code !== 'expired')
<label>Prazo (dias)
<input type="number" name="days" min="{{ $rule->code === 'offline' ? 1 : 0 }}" max="365" value="{{ $rule->days }}" required>
</label>
@else
<input type="hidden" name="days" value="0">
<span class="automation-rule-no-days">No dia seguinte ao vencimento</span>
@endif
<label class="check automation-switch"><input type="hidden" name="enabled" value="0"><input type="checkbox" name="enabled" value="1" @checked($rule->enabled)>Ativa</label>
<button class="btn primary small" type="submit">Salvar</button>
</div>
</form>
@endforeach
</div>
</section>
<section class="panel automation-history">
<div class="panel-heading"><h2>Últimos avisos automáticos</h2><a href="{{ route('history.index') }}">Ver histórico completo</a></div>
<div class="table-scroll"><table class="data-table"><thead><tr><th>Registro</th><th>Cliente</th><th>Regra</th><th>Detalhes</th></tr></thead><tbody>
@forelse($deliveries as $delivery)
<tr><td>{{ $delivery->created_at?->format('d/m/Y H:i') }}</td>
<td><a href="{{ route('clients.show',$delivery->company_id) }}">{{ $delivery->company?->legal_name ?? 'Conta indisponível' }}</a></td>
<td>{{ $delivery->rule?->name ?? '—' }}</td><td>Ocorrência registrada no histórico do cliente.</td></tr>
@empty<tr><td colspan="4" class="empty">Nenhum aviso automático registrado.</td></tr>@endforelse
</tbody></table></div>
</section>
<div class="info-line more-space">Execução agendada: diariamente às 08h, desde que o agendador Laravel esteja em funcionamento no servidor. Também é possível executar manualmente acima. Repetir uma verificação não duplica o mesmo aviso.</div>
@endsection
