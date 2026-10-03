<nav class="sales-module-tabs" aria-label="Documentos fiscais">
  @foreach($tabs as $key=>$item)
    <a href="{{ route('fiscal.index',['tab'=>$key,'month'=>request('month',now()->format('Y-m'))]) }}"
       class="{{ $tab===$key?'active':'' }}">
      @include('partials.icon',['name'=>'receipt','size'=>17])
      <span>{{ $item['label'] }}</span>
    </a>
  @endforeach
</nav>
