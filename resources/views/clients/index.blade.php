@extends('layouts.main')
@section('title', $licenses?'Licenças':'Clientes')
@section('actions')
<a href="{{ route('clients.create') }}" class="btn primary"><svg><use href="#i-plus"/></svg>Nova conta</a>
@endsection
@section('content')
<section class="panel">
<form class="filters" method="get" action="{{ $licenses?route('licenses.index'):route('clients.index') }}">
 <label class="field-search"><span>Buscar cliente</span><span class="search-wrap"><svg><use href="#i-search"/></svg><input type="search" name="q" value="{{ request('q') }}" placeholder="Nome, CNPJ ou subdomínio"></span></label>
 <label><span>Situação</span><select name="status"><option value="">Todas</option><option value="active" @selected(request('status')==='active')>Ativas</option><option value="suspended" @selected(request('status')==='suspended')>Suspensas</option><option value="expired" @selected(request('status')==='expired')>Vencidas</option></select></label>
 <label><span>Plano</span><select name="plan"><option value="">Todos</option>@foreach(config('license.plans') as $value=>$label)<option value="{{ $value }}" @selected(request('plan')===$value)>{{ $label }}</option>@endforeach</select></label>
 <button class="btn primary" type="submit">Buscar</button><a class="btn subtle" href="{{ $licenses?route('licenses.index'):route('clients.index') }}">Limpar</a>
</form>
<div class="list-actions"><strong>{{ $companies->total() }} {{ $companies->total()===1?'registro':'registros' }}</strong><span>Selecione uma conta para consultar ou alterar a licença.</span></div>
<div class="table-scroll"><table class="data-table">
<thead><tr><th>Código</th><th>Empresa / CNPJ</th><th>Subdomínio</th><th>Plano</th><th>Validade</th><th>Situação</th><th>Último contato</th><th class="right">Ações</th></tr></thead>
<tbody>
@forelse($companies as $company)
<tr><td class="mono muted">#{{ str_pad($company->id,4,'0',STR_PAD_LEFT) }}</td>
<td><a class="table-primary" href="{{ route('clients.show',$company) }}">{{ $company->legal_name }}</a><small class="cell-secondary">{{ substr($company->cnpj,0,2) }}.{{ substr($company->cnpj,2,3) }}.{{ substr($company->cnpj,5,3) }}/{{ substr($company->cnpj,8,4) }}-{{ substr($company->cnpj,12,2) }}</small></td>
<td><span class="mono">{{ $company->subdomain }}</span></td>
<td>{{ config('license.plans')[$company->plan]??$company->plan }}</td>
<td>{{ $company->expires_at?->format('d/m/Y')??'Sem prazo' }}</td>
<td><span class="status {{ $company->currentStatus() }}">{{ ['active'=>'Ativa','expired'=>'Vencida','suspended'=>'Suspensa'][$company->currentStatus()] }}</span></td>
<td>{{ $company->last_seen_at?->format('d/m/Y H:i')??'Não conectada' }}</td>
<td class="right"><button type="button" class="link-button" data-account-details="{{ route('clients.details',$company) }}">Detalhes</button><a class="link-button" href="{{ route('clients.show',$company) }}">Gerenciar</a></td>
</tr>
@empty<tr><td colspan="8" class="empty">Nenhuma empresa encontrada.</td></tr>
@endforelse
</tbody></table></div>
<div class="pagination"><span>Página {{ $companies->currentPage() }} de {{ $companies->lastPage() }}</span><div>@if($companies->previousPageUrl())<a class="btn subtle small" href="{{ $companies->previousPageUrl() }}">Anterior</a>@endif @if($companies->nextPageUrl())<a class="btn subtle small" href="{{ $companies->nextPageUrl() }}">Próxima</a>@endif</div></div>
</section>
@endsection
