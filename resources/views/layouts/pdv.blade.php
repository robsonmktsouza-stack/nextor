<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>PDV — {{ config('app.name') }}</title>
<link rel="stylesheet" href="{{ asset('css/erp.css') }}">
<link rel="stylesheet" href="{{ asset('css/pdv.css') }}">
<script defer src="{{ asset('js/erp.js') }}"></script>
</head>
<body class="pdv-body" data-confirm-destructive="{{ \App\Models\AppSetting::value('system','confirm_destructive_actions',true) ? '1' : '0' }}">
<div class="pdv-root" id="appRoot">
  <header class="pdv-topbar">
    <div class="pdv-topbar-brand">
      <span class="pdv-topbar-mark">@include('partials.icon',['name'=>'pdv','size'=>21])</span>
      <div>
        <strong>NEXTOR PDV</strong>
        <small>Frente de caixa</small>
      </div>
    </div>

    @php
      $cashControlEnabled=isset($pdvSettings) && (bool)($pdvSettings['require_cash_opening'] ?? false);
      $cashIsOpen=!$cashControlEnabled || !empty($cashSession);
    @endphp
    <div class="pdv-topbar-status {{ $cashIsOpen ? 'is-open' : 'is-closed' }}">
      <span class="pdv-online-dot"></span>
      <span>{{ $cashIsOpen ? 'Caixa aberto' : 'Caixa fechado' }}</span>
      <span class="pdv-topbar-separator"></span>
      <span>{{ auth()->user()->name }}</span>
    </div>

    <div class="pdv-topbar-actions">
      <button type="button" class="pdv-topbar-button" id="pdvFullscreen" data-tooltip="Tela cheia">
        @include('partials.icon',['name'=>'expand','size'=>17])
        <span>Tela cheia</span>
      </button>
      @if(auth()->user()->canAccess('sales'))
      <a class="pdv-topbar-button" href="{{ route('sales.index') }}">
        @include('partials.icon',['name'=>'receipt','size'=>17])
        <span>Vendas</span>
      </a>
      @endif
      <a class="pdv-topbar-button pdv-topbar-exit" href="{{ route(auth()->user()->canAccess('dashboard') ? 'dashboard' : auth()->user()->homeRouteName()) }}">
        @include('partials.icon',['name'=>'arrow-left','size'=>17])
        <span>Administração</span>
      </a>
    </div>
  </header>

  <main class="pdv-screen">
    @if(session('success'))
      <div data-system-notification data-type="success" data-notification-title="Concluído" hidden>{{ session('success') }}</div>
    @endif
    @if(session('error'))
      <div data-system-notification data-type="error" data-notification-title="Não foi possível concluir" hidden>{{ session('error') }}</div>
    @endif
    @if(session('warning'))
      <div data-system-notification data-type="warning" data-notification-title="Atenção" hidden>{{ session('warning') }}</div>
    @endif
    @if($errors->any())
      <div data-system-notification data-type="error" data-notification-title="Verifique os campos" hidden>
        @foreach($errors->all() as $error)<span data-notification-item>{{ $error }}</span>@endforeach
      </div>
    @endif

    @yield('content')
  </main>
</div>
@stack('scripts')
</body>
</html>
