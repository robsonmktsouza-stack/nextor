@extends('layouts.main')
@section('title','Histórico')
@section('content')
@php $hasFilters = request()->filled('q'); @endphp
<section class="panel license-grid" data-admin-list data-grid-filename="historico">
    @include('partials.listing.toolbar', [
        'toolbarUrl' => route('history.index'),
        'filterId' => 'history-list-filters',
        'filtersActive' => $hasFilters,
    ])
    <div class="license-filters" id="history-list-filters" @unless($hasFilters) hidden @endunless>
        <form class="license-filter-form" method="get" action="{{ route('history.index') }}">
            <label class="license-search"><span class="sr-only">Buscar histórico</span>
                <input type="search" name="q" value="{{ request('q') }}" placeholder="Empresa ou subdomínio...">
                <svg><use href="#i-search"/></svg></label>
            <input type="hidden" name="per_page" value="{{ $events->perPage() }}">
            <button class="btn primary small" type="submit">Filtrar</button>
            @if($hasFilters)<a href="{{ route('history.index') }}" class="btn subtle small">Limpar</a>@endif
        </form>
    </div>
    <div class="table-scroll">
        <table class="license-data-table" data-grid-table>
            <thead><tr><th>Data</th><th>Cliente</th><th>Operador</th><th>Evento</th><th>Descrição</th><th class="license-actions-heading">Ações</th></tr></thead>
            <tbody>
                @forelse($events as $event)
                    <tr><td class="license-nowrap">{{ $event->created_at?->format('d/m/Y H:i') }}</td>
                        <td class="license-name-cell">
                            @if($event->company)
                                <a class="license-name" href="{{ route('clients.show',$event->company_id) }}">{{ $event->company->legal_name }}</a>
                                <small>{{ $event->company->subdomain }}</small>
                            @else
                                <span>Conta indisponível</span>
                            @endif
                        </td>
                        <td>{{ $event->user?->name ?? 'Sistema' }}</td>
                        <td>{{ ucfirst(str_replace('_',' ',$event->event_type)) }}</td>
                        <td>{{ $event->description }}</td>
                        <td>
                            <div class="license-row-actions">
                                @if($event->company)
                                    <a class="license-icon-action" href="{{ route('clients.show',$event->company_id) }}" title="Ver cliente" aria-label="Ver cliente relacionado ao evento"><svg><use href="#i-clients"/></svg></a>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty<tr><td colspan="6" class="empty">Nenhuma atividade encontrada.</td></tr>@endforelse
            </tbody>
        </table>
    </div>
    @include('partials.listing.footer', ['paginator' => $events, 'singular' => 'evento', 'plural' => 'eventos'])
</section>
@endsection
