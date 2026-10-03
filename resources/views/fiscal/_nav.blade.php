<nav class="fiscal-tabs" aria-label="Documentos fiscais">
  @foreach($tabs as $key=>$item)
    <a href="{{ route('fiscal.index',['tab'=>$key]) }}" class="{{ $tab===$key?'active':'' }}">
      <span>{{ $item['label'] }}</span>
      @if(($tabCounts[$key] ?? 0)>0)<small>{{ $tabCounts[$key] }}</small>@endif
    </a>
  @endforeach
</nav>
