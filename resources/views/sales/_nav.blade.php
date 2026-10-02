<nav class="sales-module-tabs" aria-label="Vendas">
  <a href="{{ route('sales.index') }}" class="{{ request()->routeIs('sales.index','sales.create','sales.show') ? 'active' : '' }}">
    @include('partials.icon',['name'=>'sales','size'=>17])
    <span>Vendas</span>
  </a>
  <a href="{{ route('sales.returns.index') }}" class="{{ request()->routeIs('sales.returns.*') ? 'active' : '' }}">
    @include('partials.icon',['name'=>'return','size'=>17])
    <span>Devoluções</span>
  </a>
</nav>
