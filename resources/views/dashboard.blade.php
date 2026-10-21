@extends('layouts.main')
@section('title','Painel inicial')
@section('actions')
<a href="{{ route('clients.create') }}" class="btn primary">@include('partials.icon',['name'=>'plus','size'=>18])Nova conta</a>
@endsection
@section('content')
<div class="stats">
  <a href="{{ route('clients.index') }}" class="stat"><span>Clientes cadastrados</span><strong>{{ $total }}</strong></a>
  <a href="{{ route('licenses.index',['status'=>'active']) }}" class="stat"><span>Licenças ativas</span><strong>{{ $active }}</strong></a>
  <a href="{{ route('licenses.index',['status'=>'suspended']) }}" class="stat"><span>Suspensas</span><strong>{{ $suspended }}</strong></a>
  <a href="{{ route('licenses.index',['status'=>'expired']) }}" class="stat"><span>Vencidas</span><strong>{{ $expired }}</strong></a>
</div>
<div class="two-cols">
<section class="panel license-grid" data-admin-list data-grid-filename="proximos-vencimentos">
<div class="panel-heading admin-grid-heading"><h2>Próximos vencimentos</h2><a href="{{ route('licenses.index') }}">Ver licenças @include('partials.icon',['name'=>'arrow','size'=>16])</a></div>
@include('partials.listing.toolbar', ['showFilters' => false])
<div class="table-scroll"><table class="license-data-table" data-grid-table>
<thead><tr><th>Cliente</th><th>Validade</th><th class="license-actions-heading">Ações</th></tr></thead>
<tbody>
@forelse($renewals as $company)
<tr>
<td class="license-name-cell"><a class="license-name" href="{{ route('clients.show',$company) }}">{{ $company->legal_name }}</a><small>{{ $company->subdomain }}</small></td>
<td class="license-nowrap">{{ $company->expires_at?->format('d/m/Y') }}</td>
<td><div class="license-row-actions"><a class="license-icon-action" href="{{ route('clients.show',$company) }}" data-tooltip="Gerenciar licença" aria-label="Gerenciar licença de {{ $company->legal_name }}">@include('partials.icon',['name'=>'edit','size'=>16])</a></div></td>
</tr>
@empty<tr><td colspan="3" class="empty">Nenhuma licença vencendo nos próximos 15 dias.</td></tr>@endforelse
</tbody></table></div>
<div class="admin-grid-mini-footer"><a href="{{ route('licenses.index') }}">Consultar todas as licenças @include('partials.icon',['name'=>'arrow','size'=>16])</a></div>
</section>
<section class="panel"><div class="panel-heading"><h2>Atividades recentes</h2><a href="{{ route('history.index') }}">Histórico @include('partials.icon',['name'=>'arrow','size'=>16])</a></div>
<div class="activity-list">
@forelse($events as $event)
<div class="activity"><span class="activity-dot"></span><div><strong>{{ $event->company?->legal_name ?? 'Conta indisponível' }}</strong><p>{{ \App\Support\OperatorMessage::event($event->description) }}</p><small>{{ $event->created_at?->format('d/m/Y H:i') }}</small></div></div>
@empty<div class="empty">Nenhuma movimentação registrada.</div>@endforelse
</div></section>
</div>
@endsection
