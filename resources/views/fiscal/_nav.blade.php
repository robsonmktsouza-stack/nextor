<nav class="sales-module-tabs" aria-label="Documentos fiscais">
  @foreach($tabs as $key=>$item)
    <a href="{{ route('fiscal.index',['tab'=>$key,'month'=>request('month',now()->format('Y-m'))]) }}"
       class="{{ $tab===$key?'active':'' }}">
      @include('partials.icon',['name'=>'receipt','size'=>17])
      <span>{{ $item['label'] }}</span>
    </a>
  @endforeach
  <a href="{{ route('fiscal.purchases.index') }}" class="{{ request()->routeIs('fiscal.purchases.*')?'active':'' }}">
    @include('partials.icon',['name'=>'inbox','size'=>17])
    <span>Recebidas</span>
  </a>
</nav>
