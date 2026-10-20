<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="theme-color" content="#31515c">
<link rel="icon" href="{{ asset('images/lumeron/elephant.png') }}">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="{{ asset('css/control.css') }}">
<script src="{{ asset('js/control.js') }}" defer></script>
<title>@yield('title','Administração') — Lumeron Control</title>
</head>
<body>
<svg xmlns="http://www.w3.org/2000/svg" class="icon-defs" aria-hidden="true">
<symbol id="i-menu" viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h16"/></symbol>
<symbol id="i-home" viewBox="0 0 24 24"><path d="m3 10 9-7 9 7v10H3zM9 20v-7h6v7"/></symbol>
<symbol id="i-clients" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="1"/><path d="M8 7h8M8 11h8M8 15h5"/></symbol>
<symbol id="i-license" viewBox="0 0 24 24"><circle cx="8" cy="15" r="4"/><path d="M12 15h9m-3 0v-3m-3 3v-2"/></symbol>
<symbol id="i-history" viewBox="0 0 24 24"><path d="M3 12a9 9 0 1 1 2.6 6.4M3 17v-5h5M12 7v5l3 2"/></symbol>
<symbol id="i-plus" viewBox="0 0 24 24"><path d="M12 4v16M4 12h16"/></symbol>
<symbol id="i-search" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m16 16 5 5"/></symbol>
<symbol id="i-check" viewBox="0 0 24 24"><path d="m4 12 5 5L20 6"/></symbol>
<symbol id="i-arrow" viewBox="0 0 24 24"><path d="m9 18 6-6-6-6"/></symbol>
<symbol id="i-lock" viewBox="0 0 24 24"><rect x="5" y="10" width="14" height="11" rx="1"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></symbol>
<symbol id="i-logout" viewBox="0 0 24 24"><path d="M10 3H4v18h6M14 7l5 5-5 5m5-5H9"/></symbol>
<symbol id="i-x" viewBox="0 0 24 24"><path d="M5 5l14 14M19 5 5 19"/></symbol>
<symbol id="i-edit" viewBox="0 0 24 24"><path d="m4 16 12-12 4 4-12 12-5 1zM14 6l4 4"/></symbol>
<symbol id="i-calendar" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="1"/><path d="M7 3v5M17 3v5M3 10h18"/></symbol>
</svg>
<div class="shell">
<header class="topbar">
    <button class="icon-button menu-toggle" type="button" aria-label="Mostrar menu" data-menu-toggle><svg><use href="#i-menu"/></svg></button>
    <a href="{{ route('dashboard') }}" class="brand"><img src="{{ asset('images/lumeron/logo.png') }}" alt="Lumeron"></a>
    <span class="topbar-name">Painel de Administração</span>
    <div class="topbar-space"></div>
    <span class="topbar-tag">CONTROLE DE ACESSOS</span>
    <div class="account"><span class="avatar">{{ strtoupper(substr(auth()->user()->name,0,1)) }}</span><span><strong>{{ auth()->user()->name }}</strong><small>Administrador</small></span></div>
    <form action="{{ route('logout') }}" method="post">@csrf<button title="Sair" class="icon-button"><svg><use href="#i-logout"/></svg></button></form>
</header>
<aside class="sidebar" id="sidebar">
    <div class="nav-heading">PRINCIPAL</div>
    <a class="nav-link {{ request()->routeIs('dashboard')?'active':'' }}" href="{{ route('dashboard') }}"><svg><use href="#i-home"/></svg>Início</a>
    <div class="nav-heading">GERENCIAMENTO</div>
    <a class="nav-link {{ request()->routeIs('clients.*')?'active':'' }}" href="{{ route('clients.index') }}"><svg><use href="#i-clients"/></svg>Clientes</a>
    <a class="nav-link {{ request()->routeIs('licenses.*')?'active':'' }}" href="{{ route('licenses.index') }}"><svg><use href="#i-license"/></svg>Licenças</a>
    <a class="nav-link {{ request()->routeIs('history.*')?'active':'' }}" href="{{ route('history.index') }}"><svg><use href="#i-history"/></svg>Histórico</a>
    <div class="nav-fill"></div><div class="sidebar-version">Lumeron Control <span>Administração</span></div>
</aside>
<main class="page">
    <div class="page-header">
        <div><h1>@yield('title','Painel inicial')</h1>@hasSection('subtitle')<p>@yield('subtitle')</p>@endif</div>
        <div class="actions">@yield('actions')</div>
    </div>
    <div class="content">
        @if(session('success'))<div class="notice success" role="status"><svg><use href="#i-check"/></svg>{{ session('success') }}</div>@endif
        @if(session('error'))<div class="notice error" role="alert">{{ session('error') }}</div>@endif
        @if($errors->any())<div class="notice error" role="alert"><strong>Confira os campos:</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        @yield('content')
    </div>
</main>
</div>
<dialog id="companyDialog" class="account-dialog"><div class="dialog-header"><strong>Detalhes da conta</strong><button class="icon-button" type="button" data-dialog-close aria-label="Fechar"><svg><use href="#i-x"/></svg></button></div><div id="companyDialogBody" class="dialog-body"></div></dialog>
</body>
</html>
