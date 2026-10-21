@extends('layouts.main')
@section('title','Planos')
@section('content')
@php $hasFilters = request()->filled('q') || request()->filled('status'); @endphp
<section class="panel license-grid" data-admin-list data-grid-filename="planos">
    @include('partials.listing.toolbar', [
        'toolbarUrl' => route('plans.index'),
        'createUrl' => route('plans.create'),
        'createLabel' => 'Novo plano',
        'filterId' => 'plan-list-filters',
        'filtersActive' => $hasFilters,
    ])
    <div class="license-filters" id="plan-list-filters" @unless($hasFilters) hidden @endunless>
        <form class="license-filter-form" method="get" action="{{ route('plans.index') }}">
            <label class="license-search"><span class="sr-only">Buscar plano</span>
                <input type="search" name="q" value="{{ request('q') }}" placeholder="Nome, código ou descrição...">
                @include('partials.icon',['name'=>'search','size'=>17])
            </label>
            <label><span class="sr-only">Situação</span><select name="status">
                <option value="">Todos</option>
                <option value="active" @selected(request('status')==='active')>Disponíveis</option>
                <option value="archived" @selected(request('status')==='archived')>Arquivados</option>
            </select></label>
            <input type="hidden" name="per_page" value="{{ $plans->perPage() }}">
            <button class="btn primary small" type="submit">Filtrar</button>
            @if($hasFilters)<a class="btn subtle small" href="{{ route('plans.index') }}">Limpar</a>@endif
        </form>
    </div>
    <div class="table-scroll">
        <table class="license-data-table" data-grid-table>
            <thead><tr><th>Código</th><th>Plano</th><th>Preço</th><th>Período</th>
                <th>Módulos padrão</th><th>Clientes</th><th>Situação</th><th class="license-actions-heading">Ações</th>
            </tr></thead>
            <tbody>
            @forelse($plans as $plan)
                <tr>
                    <td><span class="license-code">{{ $plan->code }}</span></td>
                    <td class="license-name-cell"><a class="license-name" href="{{ route('plans.edit',$plan) }}">{{ $plan->name }}</a>
                        @if($plan->description)<small>{{ $plan->description }}</small>@endif</td>
                    <td class="license-nowrap">{{ $plan->monthly_price === null ? 'Não definido' : 'R$ '.number_format((float)$plan->monthly_price,2,',','.') }}</td>
                    <td>{{ $plan->period_months }} {{ $plan->period_months === 1 ? 'mês' : 'meses' }}</td>
                    <td>{{ collect($plan->modules)->map(fn($code) => config('license.modules')[$code] ?? $code)->join(' · ') }}</td>
                    <td>{{ $usage[$plan->code] ?? 0 }}</td>
                    <td><span class="status {{ $plan->active ? 'active' : 'expired' }}">{{ $plan->active ? 'Disponível' : 'Arquivado' }}</span></td>
                    <td><div class="license-row-actions">
                        <a class="license-icon-action" href="{{ route('plans.edit',$plan) }}" data-tooltip="Editar plano" aria-label="Editar plano {{ $plan->name }}">@include('partials.icon',['name'=>'edit','size'=>16])</a>
                    </div></td>
                </tr>
            @empty
                <tr><td colspan="8" class="empty">Nenhum plano encontrado.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @include('partials.listing.footer', ['paginator' => $plans, 'singular' => 'plano', 'plural' => 'planos'])
</section>
@endsection
