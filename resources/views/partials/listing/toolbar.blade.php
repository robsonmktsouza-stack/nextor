@php
    $toolbarUrl = $toolbarUrl ?? request()->url();
    $filterId = $filterId ?? 'grid-filters';
    $filtersActive = $filtersActive ?? false;
    $createUrl = $createUrl ?? null;
    $createLabel = $createLabel ?? 'Novo';
    $showFilters = $showFilters ?? true;
@endphp
<div class="license-grid-toolbar" data-grid-toolbar>
    <div class="license-grid-tools">
        @if($createUrl)
            <a href="{{ $createUrl }}" class="license-grid-new" @if($createModal ?? false) data-automation-open="create" @endif data-tooltip="{{ $createLabel }}">
                @include('partials.icon',['name'=>'plus','size'=>17])<span>{{ $createLabel }}</span>
            </a>
        @endif
        <div class="license-tool-group">
            <button class="license-tool" type="button" data-grid-print data-tooltip="Imprimir esta listagem" aria-label="Imprimir esta listagem">@include('partials.icon',['name'=>'print','size'=>18])</button>
            <button class="license-tool" type="button" data-grid-export data-tooltip="Exportar página atual em CSV" aria-label="Exportar página atual">@include('partials.icon',['name'=>'download','size'=>18])</button>
            <a class="license-tool" href="{{ request()->fullUrl() }}" data-tooltip="Atualizar listagem" aria-label="Atualizar listagem">@include('partials.icon',['name'=>'refresh','size'=>18])</a>
        </div>
    </div>
    @if($showFilters)
    <button class="license-tool license-filter-toggle" type="button"
        data-grid-filter-toggle data-license-filter-toggle
        aria-controls="{{ $filterId }}" aria-expanded="{{ $filtersActive ? 'true' : 'false' }}"
        data-tooltip="Filtros da listagem" aria-label="Filtros da listagem">@include('partials.icon',['name'=>'search','size'=>18])</button>
    @endif
</div>
