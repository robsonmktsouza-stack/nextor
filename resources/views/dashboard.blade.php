@extends('layouts.main')
@section('title','Painel inicial')
@section('actions')
<a href="{{ route('clients.create') }}" class="btn primary"><svg><use href="#i-plus"/></svg>Nova conta</a>
@endsection
@section('content')
<div class="stats">
  <a href="{{ route('clients.index') }}" class="stat"><span>Clientes cadastrados</span><strong>{{ $total }}</strong><small>Total de instalações registradas</small></a>
  <a href="{{ route('licenses.index',['status'=>'active']) }}" class="stat"><span>Licenças ativas</span><strong>{{ $active }}</strong><small>Acesso comercial liberado</small></a>
  <a href="{{ route('licenses.index',['status'=>'suspended']) }}" class="stat"><span>Suspensas</span><strong>{{ $suspended }}</strong><small>Aguardando liberação</small></a>
  <a href="{{ route('licenses.index',['status'=>'expired']) }}" class="stat"><span>Vencidas</span><strong>{{ $expired }}</strong><small>Prazo de licença encerrado</small></a>
</div>
<div class="two-cols">
<section class="panel"><div class="panel-heading"><h2>Próximos vencimentos</h2><a href="{{ route('licenses.index') }}">Ver licenças <svg><use href="#i-arrow"/></svg></a></div>
<div class="table-scroll"><table><thead><tr><th>Cliente</th><th>Validade</th><th></th></tr></thead><tbody>
@forelse($renewals as $company)
<tr><td><a href="{{ route('clients.show',$company) }}">{{ $company->legal_name }}</a><small class="cell-secondary">{{ $company->subdomain }}</small></td><td>{{ $company->expires_at?->format('d/m/Y') }}</td><td><a class="link" href="{{ route('clients.show',$company) }}">Gerenciar</a></td></tr>
@empty<tr><td colspan="3" class="empty">Nenhuma licença vencendo nos próximos 15 dias.</td></tr>@endforelse
</tbody></table></div></section>
<section class="panel"><div class="panel-heading"><h2>Atividades recentes</h2><a href="{{ route('history.index') }}">Histórico <svg><use href="#i-arrow"/></svg></a></div>
<div class="activity-list">
@forelse($events as $event)
<div class="activity"><span class="activity-dot"></span><div><strong>{{ $event->company?->legal_name ?? 'Conta indisponível' }}</strong><p>{{ $event->description }}</p><small>{{ $event->created_at?->format('d/m/Y H:i') }}</small></div></div>
@empty<div class="empty">Nenhuma movimentação registrada.</div>@endforelse
</div></section>
</div>
@endsection
