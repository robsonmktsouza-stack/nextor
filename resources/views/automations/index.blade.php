@extends('layouts.main')
@section('title','Automações')
@section('actions')
<form method="post" action="{{ route('automations.run') }}">@csrf
    <button class="btn primary" type="submit" data-confirm="Executar verificação agora?">
        @include('partials.icon',['name'=>'check','size'=>18])Executar verificação
    </button>
</form>
@endsection
@section('content')
@php $hasFilters = request()->filled('q') || request()->filled('status'); @endphp
<section class="panel license-grid" data-admin-list data-grid-filename="automacoes-regras">
    @include('partials.listing.toolbar', [
        'toolbarUrl' => route('automations.index'),
        'filterId' => 'automation-rule-filters',
        'filtersActive' => $hasFilters,
    ])
    <div class="license-filters" id="automation-rule-filters" @unless($hasFilters) hidden @endunless>
        <form class="license-filter-form" method="get" action="{{ route('automations.index') }}">
            <label class="license-search"><span class="sr-only">Buscar regra</span>
                <input type="search" name="q" value="{{ request('q') }}" placeholder="Buscar regra de automação...">
                @include('partials.icon',['name'=>'search','size'=>17])
            </label>
            <label><span class="sr-only">Situação</span><select name="status">
                <option value="">Todas</option>
                <option value="enabled" @selected(request('status')==='enabled')>Ativas</option>
                <option value="disabled" @selected(request('status')==='disabled')>Desativadas</option>
            </select></label>
            <input type="hidden" name="per_page" value="{{ $rules->perPage() }}">
            <button class="btn primary small" type="submit">Filtrar</button>
            @if($hasFilters)<a class="btn subtle small" href="{{ route('automations.index') }}">Limpar</a>@endif
        </form>
    </div>
    <div class="table-scroll">
        <table class="license-data-table" data-grid-table>
            <thead><tr><th>Regra</th><th>Descrição</th><th>Prazo</th><th>Última verificação</th><th>Situação</th><th class="license-actions-heading">Ações</th></tr></thead>
            <tbody>
                @forelse($rules as $rule)
                    <tr>
                        <td class="license-name-cell">
                            <strong class="license-name">{{ $rule->name }}</strong>
                            <form method="post" action="{{ route('automations.update',$rule) }}" id="automation-rule-{{ $rule->id }}">
                                @csrf @method('PUT')
                                <input type="hidden" name="enabled" value="0">
                            </form>
                        </td>
                        <td>{{ $rule->description }}</td>
                        <td class="license-nowrap">
                            @if($rule->code !== 'expired')
                                <label class="automation-inline-days"><span class="sr-only">Prazo em dias para {{ $rule->name }}</span>
                                    <input type="number" form="automation-rule-{{ $rule->id }}" name="days" min="{{ $rule->code === 'offline' ? 1 : 0 }}" max="365" value="{{ $rule->days }}" required>
                                    <span>dias</span>
                                </label>
                            @else
                                <input type="hidden" form="automation-rule-{{ $rule->id }}" name="days" value="0">
                                <span>Após vencimento</span>
                            @endif
                        </td>
                        <td class="license-nowrap">{{ $rule->last_run_at?->format('d/m/Y H:i') ?? 'Nunca' }}</td>
                        <td>
                            <label class="switch-row automation-table-switch">
                                <input form="automation-rule-{{ $rule->id }}" type="checkbox" name="enabled" role="switch"
                                    value="1" aria-label="Ativar a regra {{ $rule->name }}" @checked($rule->enabled)>
                                <span class="switch-track" aria-hidden="true"></span>
                                <span class="status {{ $rule->enabled ? 'active' : 'expired' }}">{{ $rule->enabled ? 'Ativa' : 'Desativada' }}</span>
                            </label>
                        </td>
                        <td>
                            <div class="license-row-actions">
                                <button class="license-icon-action" type="submit" form="automation-rule-{{ $rule->id }}"
                                    data-tooltip="Salvar regra" aria-label="Salvar regra {{ $rule->name }}">@include('partials.icon',['name'=>'check','size'=>16])</button>
                            </div>
                        </td>
                    </tr>
                @empty<tr><td colspan="6" class="empty">Nenhuma regra encontrada.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @include('partials.listing.footer', [
        'paginator' => $rules,
        'pageName' => 'rules_page',
        'singular' => 'regra',
        'plural' => 'regras',
    ])
</section>

<section class="panel license-grid automation-history" data-admin-list data-grid-filename="automacoes-avisos">
    <div class="panel-heading admin-grid-heading"><h2>Últimos avisos automáticos</h2><a href="{{ route('history.index') }}">Ver histórico completo</a></div>
    @include('partials.listing.toolbar', ['showFilters' => false])
    <div class="table-scroll">
        <table class="license-data-table" data-grid-table>
            <thead><tr><th>Data</th><th>Cliente</th><th>Regra</th><th class="license-actions-heading">Ações</th></tr></thead>
            <tbody>
                @forelse($deliveries as $delivery)
                    <tr>
                        <td class="license-nowrap">{{ $delivery->created_at?->format('d/m/Y H:i') }}</td>
                        <td class="license-name-cell">
                            @if($delivery->company)
                                <a class="license-name" href="{{ route('clients.show',$delivery->company_id) }}">{{ $delivery->company->legal_name }}</a>
                            @else
                                Conta indisponível
                            @endif
                        </td>
                        <td>{{ $delivery->rule?->name ?? '—' }}</td>
                        <td>
                            <div class="license-row-actions">
                                @if($delivery->company)
                                    <a class="license-icon-action" href="{{ route('clients.show',$delivery->company_id) }}" data-tooltip="Ver cliente" aria-label="Ver cliente">@include('partials.icon',['name'=>'clients','size'=>16])</a>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty<tr><td colspan="4" class="empty">Nenhum aviso registrado.</td></tr>@endforelse
            </tbody>
        </table>
    </div>
    @include('partials.listing.footer', [
        'paginator' => $deliveries,
        'pageName' => 'alerts_page',
        'singular' => 'aviso',
        'plural' => 'avisos',
    ])
</section>
@endsection
