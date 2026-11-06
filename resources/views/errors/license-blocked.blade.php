<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Licença do Lumeron</title>
<link rel="icon" href="{{ asset('images/lumeron/elephant.png') }}">
<link rel="stylesheet" href="{{ asset('css/ui-tokens.css') }}?v={{ filemtime(public_path('css/ui-tokens.css')) }}">
<link rel="stylesheet" href="{{ asset('css/user-message-page.css') }}?v={{ filemtime(public_path('css/user-message-page.css')) }}"></head>
<body><main class="user-message-page">
<img src="{{ asset('images/lumeron/logo.png') }}" alt="Lumeron" width="150"><h1>Operação indisponível</h1><p>Não foi possível liberar esta operação. Entre em contato com o responsável pela assinatura para regularizar o acesso.</p><p>As consultas e as regularizações fiscais permitidas continuam disponíveis conforme suas permissões.</p>
<nav class="user-message-actions" aria-label="Ações de acesso">
@if(auth()->user()?->canAccess('fiscal'))<a href="{{ route('fiscal.index') }}">Acessar Fiscal</a>@endif
<a class="outline" href="{{ route('logout') }}" onclick="event.preventDefault();document.getElementById('logoutForm').submit()">Sair</a></nav><form id="logoutForm" method="post" action="{{ route('logout') }}" hidden>@csrf</form>
</main></body></html>
