{{-- Navigation remains subject to each user's permissions and licensed modules. --}}
<nav class="lumeron-mobile-nav" aria-label="Navegação rápida">
  @if(auth()->user()->canAccess('dashboard'))
    <a href="{{ route('dashboard') }}" class="{{ request()->routeIs('dashboard') ? 'is-active' : '' }}" @if(request()->routeIs('dashboard')) aria-current="page" @endif>
      @include('partials.icon',['name'=>'panel','size'=>21])<span>Início</span>
    </a>
  @endif
  @if(auth()->user()->canAccess('sales') && $hasModule('pdv'))
    <a href="{{ route('sales.index') }}" class="{{ request()->routeIs('sales.*') ? 'is-active' : '' }}" @if(request()->routeIs('sales.*')) aria-current="page" @endif>
      @include('partials.icon',['name'=>'sales','size'=>21])<span>Vendas</span>
    </a>
  @endif
  @if(auth()->user()->canAccess('products') && $hasModule('products'))
    <a href="{{ route('products.index') }}" class="{{ request()->routeIs('products.*') ? 'is-active' : '' }}" @if(request()->routeIs('products.*')) aria-current="page" @endif>
      @include('partials.icon',['name'=>'products','size'=>21])<span>Produtos</span>
    </a>
  @endif
  @if(auth()->user()->canAccess('customers'))
    <a href="{{ route('customers.index') }}" class="{{ request()->routeIs('customers.*') ? 'is-active' : '' }}" @if(request()->routeIs('customers.*')) aria-current="page" @endif>
      @include('partials.icon',['name'=>'customers','size'=>21])<span>Clientes</span>
    </a>
  @endif
  <button type="button" data-mobile-more aria-label="Abrir menu completo" aria-controls="sidebar" aria-expanded="false">
    @include('partials.icon',['name'=>'menu','size'=>21])<span>Menu</span>
  </button>
</nav>
