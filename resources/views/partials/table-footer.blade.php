@php
  $currentPage=$paginator->currentPage();
  $lastPage=max(1,$paginator->lastPage());
  $perPage=(int)request('per_page',$paginator->perPage());
@endphp

<div class="table-footerbar table-footer-controls">
  <form method="get" class="table-page-size-form">
    @foreach(request()->except('page','per_page') as $key=>$value)
      @if(is_scalar($value))
        <input type="hidden" name="{{ $key }}" value="{{ $value }}">
      @endif
    @endforeach

    <select name="per_page" class="table-page-size-select" onchange="this.form.submit()" aria-label="Linhas por página">
      @foreach([10,25,50,100] as $size)
        <option value="{{ $size }}" @selected($perPage===$size)>{{ $size }} linhas por página</option>
      @endforeach
    </select>
  </form>

  <nav class="table-pager-compact" aria-label="Paginação">
    @if($currentPage>1)
      <a class="table-pager-button" href="{{ $paginator->url(1) }}" aria-label="Primeira página" data-tooltip="Primeira página">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 5v14M18 6l-6 6 6 6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
      </a>
      <a class="table-pager-button" href="{{ $paginator->previousPageUrl() }}" aria-label="Página anterior" data-tooltip="Página anterior">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m15 18-6-6 6-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
      </a>
    @else
      <span class="table-pager-button disabled" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M6 5v14M18 6l-6 6 6 6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
      <span class="table-pager-button disabled" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m15 18-6-6 6-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
    @endif

    <span class="table-pager-current">{{ $currentPage }} / {{ $lastPage }}</span>

    @if($currentPage<$lastPage)
      <a class="table-pager-button" href="{{ $paginator->nextPageUrl() }}" aria-label="Próxima página" data-tooltip="Próxima página">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 18 6-6-6-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
      </a>
      <a class="table-pager-button" href="{{ $paginator->url($lastPage) }}" aria-label="Última página" data-tooltip="Última página">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 5v14M6 6l6 6-6 6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
      </a>
    @else
      <span class="table-pager-button disabled" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m9 18 6-6-6-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
      <span class="table-pager-button disabled" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M18 5v14M6 6l6 6-6 6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
    @endif
  </nav>
</div>
