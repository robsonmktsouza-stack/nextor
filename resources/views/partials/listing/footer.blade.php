@if(isset($paginator))
<div class="license-grid-footer">
    <form method="get" action="{{ $footerUrl ?? request()->url() }}" class="license-page-size">
        <label><span class="sr-only">Quantidade de linhas por página</span>
            <select name="{{ $sizeName ?? 'per_page' }}" data-page-size>
                @foreach([20, 50, 100] as $limit)
                    <option value="{{ $limit }}" @selected($paginator->perPage()===$limit)>{{ $limit }} linhas por página</option>
                @endforeach
            </select>
        </label>
        @foreach(request()->except(['page', $pageName ?? 'page', $sizeName ?? 'per_page']) as $key => $value)
            @if(is_scalar($value))
                <input type="hidden" name="{{ $key }}" value="{{ $value }}">
            @endif
        @endforeach
    </form>
    <div class="license-page-controls">
        <span class="license-result-count">{{ $paginator->total() }} {{ $paginator->total() === 1 ? ($singular ?? 'registro') : ($plural ?? 'registros') }}</span>
        <a class="license-page-arrow {{ $paginator->onFirstPage() ? 'disabled' : '' }}"
           href="{{ $paginator->onFirstPage() ? '#' : $paginator->url(1) }}"
           aria-label="Primeira página" @if($paginator->onFirstPage()) aria-disabled="true" @endif>«</a>
        <a class="license-page-arrow {{ $paginator->onFirstPage() ? 'disabled' : '' }}"
           href="{{ $paginator->previousPageUrl() ?? '#' }}"
           aria-label="Página anterior" @if($paginator->onFirstPage()) aria-disabled="true" @endif>‹</a>
        <span class="license-page-current">{{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}</span>
        <a class="license-page-arrow {{ $paginator->hasMorePages() ? '' : 'disabled' }}"
           href="{{ $paginator->nextPageUrl() ?? '#' }}"
           aria-label="Próxima página" @unless($paginator->hasMorePages()) aria-disabled="true" @endunless>›</a>
        <a class="license-page-arrow {{ $paginator->hasMorePages() ? '' : 'disabled' }}"
           href="{{ $paginator->hasMorePages() ? $paginator->url($paginator->lastPage()) : '#' }}"
           aria-label="Última página" @unless($paginator->hasMorePages()) aria-disabled="true" @endunless>»</a>
    </div>
</div>
@endif
