<!DOCTYPE html>
<html lang="pt-BR"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>@yield('title','Painel') — {{ config('app.name') }}</title>
<link rel="stylesheet" href="{{ asset('css/erp.css') }}">
<script defer src="{{ asset('js/erp.js') }}"></script>
</head><body
  class="{{ \App\Models\AppSetting::value('system','compact_mode',true) ? 'system-compact' : 'system-comfortable' }}"
  data-live-search-delay="{{ \App\Models\AppSetting::value('system','search_delay',240) }}"
  data-confirm-destructive="{{ \App\Models\AppSetting::value('system','confirm_destructive_actions',true) ? '1' : '0' }}"
  data-show-tutorials="{{ \App\Models\AppSetting::value('system','show_tutorials',true) ? '1' : '0' }}">
<div class="app-root" id="appRoot">
  <header class="cms-topbar">
    <div class="cms-topbar-brand">
      <button class="cms-topbar-icon" type="button" data-sidebar-toggle aria-label="Alternar menu">@include('partials.icon',['name'=>'menu','size'=>20])</button>
      <a class="brand-home-link" href="{{ route(auth()->user()->canAccess('dashboard') ? 'dashboard' : auth()->user()->homeRouteName()) }}" data-tooltip="Abrir dashboard" aria-label="Abrir dashboard">
        <span class="cms-logo-mark">@include('partials.icon',['name'=>'shield','size'=>17])</span>
        <strong class="brand-name">ERP</strong>
      </a>
    </div>
    <div class="topbar-content">
      <div class="command-box">
        @include('partials.icon',['name'=>'search','size'=>18])
        <input id="commandSearch" autocomplete="off" placeholder="Buscar no sistema..." aria-label="Buscar páginas">
        <kbd data-tutorial>Ctrl K</kbd>
        <div class="command-results" id="commandResults" hidden>
          @if(auth()->user()->canAccess('dashboard'))<a href="{{ route('dashboard') }}">Painel geral</a>@endif
          @if(auth()->user()->canAccess('products'))<a href="{{ route('products.index') }}">Produtos</a>@endif
          @if(auth()->user()->canAccess('services'))<a href="{{ route('services.index') }}">Serviços</a>@endif
          @if(auth()->user()->canAccess('stock'))<a href="{{ route('stock.index') }}">Movimentações de estoque</a>@endif
          @if(auth()->user()->canAccess('customers'))<a href="{{ route('customers.index') }}">Clientes</a>@endif
          @if(auth()->user()->canAccess('pdv'))<a href="{{ route('pdv.index') }}">PDV</a>@endif
          @if(auth()->user()->canAccess('sales'))
            <a href="{{ route('sales.index') }}">Vendas</a>
            <a href="{{ route('sales.create') }}">Nova venda</a>
          @endif
          @if(auth()->user()->canAccess('fiscal'))
            <a href="{{ route('fiscal.index') }}">Fiscal</a>
            <a href="{{ route('fiscal.index',['tab'=>'nfe']) }}">NF-e</a>
            <a href="{{ route('fiscal.index',['tab'=>'nfce']) }}">NFC-e</a>
            <a href="{{ route('fiscal.index',['tab'=>'nfse']) }}">NFS-e</a>
            <a href="{{ route('fiscal.index',['tab'=>'cte']) }}">CT-e</a>
          @endif
          @if(auth()->user()->canAccess('returns'))
            <a href="{{ route('sales.returns.index') }}">Devoluções</a>
            <a href="{{ route('sales.returns.create') }}">Nova devolução</a>
          @endif
          @if(auth()->user()->canAccess('finance'))
            <a href="{{ route('finance.dashboard') }}">Financeiro</a>
            <a href="{{ route('finance.entries',['type'=>'receivable']) }}">Contas a receber</a>
            <a href="{{ route('finance.entries',['type'=>'payable']) }}">Contas a pagar</a>
            <a href="{{ route('finance.transfers.index') }}">Transferências financeiras</a>
            <a href="{{ route('finance.recurrences.index') }}">Recorrências financeiras</a>
            <a href="{{ route('finance.receipts.index') }}">Recibos</a>
            <a href="{{ route('finance.reconciliation.index') }}">Conciliação bancária</a>
          @endif
          @if(auth()->user()->canAccess('settings'))<a href="{{ route('settings.index') }}">Configurações</a>@endif
        </div>
      </div>
      @if(auth()->user()->canAccess('pdv'))
      <a class="topbar-pdv-shortcut {{ request()->routeIs('pdv.*') ? 'active' : '' }}" href="{{ route('pdv.index') }}" data-tooltip="Abrir PDV">
        @include('partials.icon',['name'=>'pdv','size'=>18])
        <span>PDV</span>
      </a>
      @endif
      <div class="topbar-spacer"></div>
      <span class="topbar-environment">ADMINISTRAÇÃO</span>
      <details class="user-menu"><summary>
        <span class="user-avatar">{{ collect(explode(' ', auth()->user()->name))->filter()->take(2)->map(fn($w) => strtoupper(substr($w,0,1)))->implode('') }}</span>
        <span class="user-label"><strong>{{ auth()->user()->name }}</strong><small>{{ match(auth()->user()->role ?? 'admin'){ 'manager'=>'Gerente','finance'=>'Financeiro','sales'=>'Vendas','operator'=>'Operador',default=>'Administrador' } }}</small></span>
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
    @if(auth()->user()->canAccess('finance'))
    <a href="{{ route('finance.dashboard') }}" class="sidebar-link {{ request()->routeIs('finance.*') ? 'active' : '' }}">
      @include('partials.icon',['name'=>'money'])<span>Financeiro</span></a>
    @endif

    <div class="sidebar-section-label">CADASTROS</div>
    @if(auth()->user()->canAccess('products'))
    <a href="{{ route('products.index') }}" class="sidebar-link {{ request()->routeIs('products.*') ? 'active' : '' }}">
      @include('partials.icon',['name'=>'products'])<span>Produtos</span></a>
    @endif
    @if(auth()->user()->canAccess('services'))
    <a href="{{ route('services.index') }}" class="sidebar-link {{ request()->routeIs('services.*') ? 'active' : '' }}">
      @include('partials.icon',['name'=>'services'])<span>Serviços</span></a>
    @endif
    @if(auth()->user()->canAccess('customers'))
    <a href="{{ route('customers.index') }}" class="sidebar-link {{ request()->routeIs('customers.*') ? 'active' : '' }}">
      @include('partials.icon',['name'=>'customers'])<span>Clientes</span></a>
    @endif

    <div class="sidebar-section-label">OPERAÇÕES</div>
    @if(auth()->user()->canAccess('sales'))
    <a href="{{ route('sales.index') }}" class="sidebar-link {{ request()->routeIs('sales.*') ? 'active' : '' }}">
      @include('partials.icon',['name'=>'sales'])<span>Vendas</span></a>
    @endif
    @if(auth()->user()->canAccess('pdv'))
    <a href="{{ route('pdv.index') }}" class="sidebar-link {{ request()->routeIs('pdv.*') ? 'active' : '' }}">
      @include('partials.icon',['name'=>'pdv'])<span>PDV</span></a>
    @endif
    @if(auth()->user()->canAccess('stock'))
    <a href="{{ route('stock.index') }}" class="sidebar-link {{ request()->routeIs('stock.*') ? 'active' : '' }}">
      @include('partials.icon',['name'=>'stock'])<span>Estoque</span></a>
    @endif

    @if(auth()->user()->canAccess('fiscal'))
    <div class="sidebar-section-label">FISCAL</div>
    <a href="{{ route('fiscal.index') }}" class="sidebar-link {{ request()->routeIs('fiscal.*') ? 'active' : '' }}">
      @include('partials.icon',['name'=>'shield'])<span>Fiscal</span></a>
    @endif

    <div class="sidebar-spacer"></div>
    @if(auth()->user()->canAccess('settings'))
    <a href="{{ route('settings.index') }}" class="sidebar-link sidebar-settings-link {{ request()->routeIs('settings.*') ? 'active' : '' }}">
      @include('partials.icon',['name'=>'settings'])<span>Configurações</span></a>
    @endif
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
