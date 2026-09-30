@if ($paginator->hasPages())
<nav class="pager" aria-label="Paginação">
 <span>Exibindo {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} de {{ $paginator->total() }}</span>
 <div>
 @if($paginator->onFirstPage())<span class="pager-button disabled">Anterior</span>@else<a class="pager-button" href="{{ $paginator->previousPageUrl() }}">Anterior</a>@endif
 @foreach($elements as $element)
  @if(is_array($element)) @foreach($element as $page=>$url)
    @if($page==$paginator->currentPage())<span class="pager-button active">{{ $page }}</span>@else<a class="pager-button" href="{{ $url }}">{{ $page }}</a>@endif
  @endforeach @endif
 @endforeach
 @if($paginator->hasMorePages())<a class="pager-button" href="{{ $paginator->nextPageUrl() }}">Próximo</a>@else<span class="pager-button disabled">Próximo</span>@endif
 </div>
</nav>@endif
