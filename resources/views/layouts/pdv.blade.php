{{-- O PDV utiliza o mesmo cabeçalho, menu, faixa do módulo e design system do Lumeron. --}}
@extends('layouts.app')

@section('title','PDV')
@section('bodyClass','pdv-body')
@section('pageShellClass','lumeron-pdv-page')

@push('styles')
<link rel="stylesheet" href="{{ asset('css/pdv.css') }}?v={{ filemtime(public_path('css/pdv.css')) }}">
<link rel="stylesheet" href="{{ asset('css/pdv-lumeron.css') }}?v={{ filemtime(public_path('css/pdv-lumeron.css')) }}">
@endpush

@section('content')
<div class="pdv-screen">
  @php
    $cashControlEnabled=isset($pdvSettings) && (bool)($pdvSettings['require_cash_opening'] ?? false);
    $cashIsOpen=!$cashControlEnabled || !empty($cashSession);
  @endphp
  <div class="pdv-workbar" aria-label="Ações do ponto de venda">
    <div class="pdv-workbar-state">
      <span class="pdv-topbar-status {{ $cashIsOpen ? 'is-open' : 'is-closed' }}">
        <span class="pdv-online-dot" aria-hidden="true"></span>
        {{ $cashIsOpen ? 'Caixa aberto' : 'Caixa fechado' }}
      </span>
      <span class="pdv-workbar-operator">{{ auth()->user()->name }}</span>
      @if(!empty($nfceContingencyActive))
        <span class="pdv-topbar-contingency" data-tooltip="Novas NFC-e serão preparadas em contingência offline">NFC-e em contingência</span>
      @endif
    </div>
    <div class="pdv-topbar-actions">
      <button type="button" class="pdv-topbar-button" id="pdvOperations" data-tooltip="Operações do caixa — Alt+O">
        @include('partials.icon',['name'=>'menu','size'=>16])
        <span>Operações</span><kbd>Alt+O</kbd>
      </button>
      <button type="button" class="pdv-topbar-button" id="pdvFullscreen" data-tooltip="Tela cheia — Alt+L">
        @include('partials.icon',['name'=>'expand','size'=>16])
        <span>Tela cheia</span><kbd>Alt+L</kbd>
      </button>
      @if(auth()->user()->canAccess('sales'))
        <a class="pdv-topbar-button" id="pdvSalesLink" href="{{ route('sales.index') }}">
          @include('partials.icon',['name'=>'receipt','size'=>16])
          <span>Vendas</span><kbd>Alt+V</kbd>
        </a>
      @endif
      <a class="pdv-topbar-button pdv-topbar-exit" id="pdvAdminLink" href="{{ route(auth()->user()->canAccess('dashboard') ? 'dashboard' : auth()->user()->homeRouteName()) }}">
        @include('partials.icon',['name'=>'arrow-left','size'=>16])
        <span>Administração</span><kbd>Alt+M</kbd>
      </a>
    </div>
  </div>
  @yield('pdv-content')
</div>
@endsection
