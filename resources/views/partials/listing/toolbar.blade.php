@php
    $toolbarUrl = $toolbarUrl ?? request()->url();
    $filterId = $filterId ?? 'grid-filters';
    $filtersActive = $filtersActive ?? false;
    $createUrl = $createUrl ?? null;
    $createLabel = $createLabel ?? 'Novo';
@endphp
<div class="license-grid-toolbar" data-grid-toolbar>
    <div class="license-grid-tools">
        @if($createUrl)
            <a href="{{ $createUrl }}" class="license-grid-new" title="{{ $createLabel }}">
                <svg><use href="#i-plus"/></svg><span>{{ $createLabel }}</span>
            </a>
        @endif
        <div class="license-tool-group">
            <button class="license-tool" type="button" data-grid-print title="Imprimir esta listagem" aria-label="Imprimir esta listagem"><svg><use href="#i-print"/></svg></button>
            <button class="license-tool" type="button" data-grid-export title="Exportar página atual em CSV" aria-label="Exportar página atual"><svg><use href="#i-download"/></svg></button>
            <a class="license-tool" href="{{ request()->fullUrl() }}" title="Atualizar listagem" aria-label="Atualizar listagem"><svg><use href="#i-refresh"/></svg></a>
        </div>
    </div>
    <button class="license-tool license-filter-toggle" type="button"
        data-grid-filter-toggle data-license-filter-toggle
        aria-controls="{{ $filterId }}" aria-expanded="{{ $filtersActive ? 'true' : 'false' }}"
        title="Filtros da listagem" aria-label="Filtros da listagem"><svg><use href="#i-search"/></svg></button>
</div>
