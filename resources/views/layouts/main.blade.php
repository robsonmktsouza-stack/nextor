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
<link rel="stylesheet" href="{{ asset('css/control.css') }}?v={{ filemtime(public_path('css/control.css')) }}">
<link rel="stylesheet" href="{{ asset('css/control-tooltip.css') }}?v={{ filemtime(public_path('css/control-tooltip.css')) }}">
<script src="{{ asset('js/control-tooltip.js') }}?v={{ filemtime(public_path('js/control-tooltip.js')) }}" defer></script>
<script src="{{ asset('js/control.js') }}?v={{ filemtime(public_path('js/control.js')) }}" defer></script>
<title>@yield('title','Administração') — Lumeron Control</title>
</head>
<body>

<div class="shell">
<header class="topbar">
    <button class="icon-button menu-toggle" type="button" aria-label="Mostrar menu" data-menu-toggle>@include('partials.icon',['name'=>'menu','size'=>20])</button>
    <a href="{{ route('dashboard') }}" class="brand"><img src="{{ asset('images/lumeron/logo.png') }}" alt="Lumeron"></a>
    <span class="topbar-name">Painel de Administração</span>
    <div class="topbar-space"></div>
    <span class="topbar-tag">CONTROLE DE ACESSOS</span>
    <div class="account"><span class="avatar">{{ strtoupper(substr(auth()->user()->name,0,1)) }}</span><span><strong>{{ auth()->user()->name }}</strong><small>Administrador</small></span></div>
    <form action="{{ route('logout') }}" method="post">@csrf<button data-tooltip="Sair" class="icon-button">@include('partials.icon',['name'=>'logout','size'=>18])</button></form>
</header>
<aside class="sidebar" id="sidebar">
    <div class="nav-heading">PRINCIPAL</div>
    <a class="nav-link {{ request()->routeIs('dashboard')?'active':'' }}" href="{{ route('dashboard') }}">@include('partials.icon',['name'=>'home','size'=>19])Início</a>
    <div class="nav-heading">GERENCIAMENTO</div>
    <a class="nav-link {{ request()->routeIs('clients.*')?'active':'' }}" href="{{ route('clients.index') }}">@include('partials.icon',['name'=>'clients','size'=>19])Clientes</a>
    <a class="nav-link {{ request()->routeIs('licenses.*')?'active':'' }}" href="{{ route('licenses.index') }}">@include('partials.icon',['name'=>'license','size'=>19])Licenças</a>
    <a class="nav-link {{ request()->routeIs('plans.*')?'active':'' }}" href="{{ route('plans.index') }}">@include('partials.icon',['name'=>'plan','size'=>19])Planos</a>
    <a class="nav-link {{ request()->routeIs('automations.*')?'active':'' }}" href="{{ route('automations.index') }}">@include('partials.icon',['name'=>'automations','size'=>19])Automações</a>
    <a class="nav-link {{ request()->routeIs('history.*')?'active':'' }}" href="{{ route('history.index') }}">@include('partials.icon',['name'=>'history','size'=>19])Histórico</a>
    <div class="nav-fill"></div><div class="sidebar-version">Lumeron Control <span>Administração</span></div>
</aside>
<main class="page">
    <div class="page-header">
        <div><h1>@yield('title','Painel inicial')</h1>@hasSection('subtitle')<p>@yield('subtitle')</p>@endif</div>
        <div class="actions">@yield('actions')</div>
    </div>
    <div class="content">
        @if(session('success'))<div class="notice success" role="status">@include('partials.icon',['name'=>'check','size'=>18]){{ session('success') }}</div>@endif
        @if(session('error'))<div class="notice error" role="alert">{{ session('error') }}</div>@endif
        @if($errors->any())<div class="notice error" role="alert"><strong>Confira os campos:</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        @yield('content')
    </div>
</main>
</div>
<dialog id="licenseActivationDialog" class="license-confirm-dialog" aria-labelledby="licenseConfirmTitle" aria-describedby="licenseConfirmMessage">
    <div class="license-confirm-head"><span class="license-confirm-icon">@include('partials.icon',['name'=>'lock','size'=>18])</span>
        <button type="button" class="icon-button" data-license-cancel aria-label="Fechar">@include('partials.icon',['name'=>'x','size'=>16])</button>
    </div>
    <h2 id="licenseConfirmTitle">Alterar acesso comercial?</h2>
    <p id="licenseConfirmMessage">Confirme a alteração para esta empresa.</p>
    <div class="license-confirm-footer"><button type="button" class="btn subtle" data-license-cancel>Cancelar</button>
        <button type="button" class="btn primary" data-license-confirm>Confirmar</button></div>
</dialog>
<dialog id="companyDialog" class="account-dialog"><div class="dialog-header"><strong>Detalhes da conta</strong><button class="icon-button" type="button" data-dialog-close aria-label="Fechar">@include('partials.icon',['name'=>'x','size'=>16])</button></div><div id="companyDialogBody" class="dialog-body"></div></dialog>
</body>
</html>
