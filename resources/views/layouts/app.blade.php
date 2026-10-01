<!DOCTYPE html>
<html lang="pt-BR"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>@yield('title','Painel') — {{ config('app.name') }}</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="{{ asset('css/erp.css') }}">
<script defer src="{{ asset('js/erp.js') }}"></script>
</head><body>
<div class="app-root" id="appRoot">
  <header class="cms-topbar">
    <div class="cms-topbar-brand">
      <button class="cms-topbar-icon" type="button" data-sidebar-toggle aria-label="Alternar menu">@include('partials.icon',['name'=>'menu','size'=>20])</button>
      <div class="cms-logo-mark">@include('partials.icon',['name'=>'shield','size'=>17])</div>
      <strong class="brand-name">ERP</strong>
    </div>
    <div class="topbar-content">
      <div class="command-box">
        @include('partials.icon',['name'=>'search','size'=>18])
        <input id="commandSearch" autocomplete="off" placeholder="Buscar no sistema..." aria-label="Buscar páginas">
        <kbd>Ctrl K</kbd>
        <div class="command-results" id="commandResults" hidden>
          <a href="{{ route('dashboard') }}">Painel geral</a>
          <a href="{{ route('products.index') }}">Produtos</a>
          <a href="{{ route('services.index') }}">Serviços</a>
          <a href="{{ route('stock.index') }}">Movimentações de estoque</a>
          <a href="{{ route('customers.index') }}">Clientes</a>
          <a href="{{ route('pdv.index') }}">PDV</a>
          <a href="{{ route('sales.index') }}">Vendas</a>
          <a href="{{ route('sales.create') }}">Nova venda</a>
        </div>
      </div>
      <div class="topbar-spacer"></div>
      <span class="topbar-environment">ADMINISTRAÇÃO</span>
      <details class="user-menu"><summary>
        <span class="user-avatar">{{ collect(explode(' ', auth()->user()->name))->filter()->take(2)->map(fn($w) => strtoupper(substr($w,0,1)))->implode('') }}</span>
        <span class="user-label"><strong>{{ auth()->user()->name }}</strong><small>Administrador</small></span>
        @include('partials.icon',['name'=>'down','size'=>15])
      </summary><div class="user-dropdown">
         <span class="user-dropdown-name">{{ auth()->user()->email }}</span>
         <form method="post" action="{{ route('logout') }}">@csrf
           <button type="submit">@include('partials.icon',['name'=>'logout','size'=>16]) Sair do ERP</button>
         </form>
      </div></details>
    </div>
  </header>
  <div class="mobile-overlay" id="mobileOverlay" hidden></div>
  <aside class="cms-sidebar" id="sidebar">
    <div class="sidebar-section-label">PRINCIPAL</div>
    <a href="{{ route('dashboard') }}" class="sidebar-link {{ request()->routeIs('dashboard') ? 'active' : '' }}">
      @include('partials.icon',['name'=>'panel'])<span>Painel</span></a>
    <div class="sidebar-section-label">CADASTROS</div>
    <a href="{{ route('products.index') }}" class="sidebar-link {{ request()->routeIs('products.*') ? 'active' : '' }}">
      @include('partials.icon',['name'=>'products'])<span>Produtos</span></a>
    <a href="{{ route('services.index') }}" class="sidebar-link {{ request()->routeIs('services.*') ? 'active' : '' }}">
      @include('partials.icon',['name'=>'services'])<span>Serviços</span></a>
    <a href="{{ route('customers.index') }}" class="sidebar-link {{ request()->routeIs('customers.*') ? 'active' : '' }}">
      @include('partials.icon',['name'=>'customers'])<span>Clientes</span></a>
    <div class="sidebar-section-label">OPERAÇÕES</div>
    <a href="{{ route('stock.index') }}" class="sidebar-link {{ request()->routeIs('stock.*') ? 'active' : '' }}">
      @include('partials.icon',['name'=>'stock'])<span>Estoque</span></a>
    <a href="{{ route('pdv.index') }}" class="sidebar-link {{ request()->routeIs('pdv.*') ? 'active' : '' }}">
      @include('partials.icon',['name'=>'pdv'])<span>PDV</span></a>
    <a href="{{ route('sales.index') }}" class="sidebar-link {{ request()->routeIs('sales.*') ? 'active' : '' }}">
      @include('partials.icon',['name'=>'sales'])<span>Vendas</span></a>
      </aside>
  <main class="cms-page-shell" id="pageShell">
    <div class="main-content">
      <div class="page-heading">
        <div class="page-heading-main">
          <div class="page-title-wrap">
            <h1>@yield('title','Painel')</h1>
            @hasSection('titleMeta')<span class="page-title-meta">@yield('titleMeta')</span>@endif
          </div>
          <div class="page-actions">@yield('actions')</div>
        </div>
      </div>
      @if(session('success'))
        <div data-system-notification data-type="success" data-notification-title="Concluído" hidden>{{ session('success') }}</div>
      @endif
      @if(session('error'))
        <div data-system-notification data-type="error" data-notification-title="Não foi possível concluir" hidden>{{ session('error') }}</div>
      @endif
      @if(session('warning'))
        <div data-system-notification data-type="warning" data-notification-title="Atenção" hidden>{{ session('warning') }}</div>
      @endif
      @if(session('info'))
        <div data-system-notification data-type="info" data-notification-title="Informação" hidden>{{ session('info') }}</div>
      @endif
      @if(session('status'))
        <div data-system-notification data-type="success" data-notification-title="Concluído" hidden>{{ session('status') }}</div>
      @endif
      @if($errors->any())
        <div data-system-notification data-type="error" data-notification-title="Verifique os campos" hidden>
          @foreach($errors->all() as $error)<span data-notification-item>{{ $error }}</span>@endforeach
        </div>
      @endif
      @yield('content')
    </div>
  </main>
</div>
@stack('scripts')
</body></html>
