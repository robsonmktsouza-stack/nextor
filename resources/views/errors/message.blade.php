<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Não foi possível concluir — Lumeron</title>
  <link rel="icon" href="{{ asset('images/lumeron/elephant.png') }}">
  <link rel="stylesheet" href="{{ asset('css/ui-tokens.css') }}?v={{ filemtime(public_path('css/ui-tokens.css')) }}">
  <link rel="stylesheet" href="{{ asset('css/user-message-page.css') }}?v={{ filemtime(public_path('css/user-message-page.css')) }}">
</head>
<body>
  <main class="user-message-page">
    <img src="{{ asset('images/lumeron/logo.png') }}" alt="Lumeron" width="150">
    <h1>{{ $status===404 ? 'Página não encontrada' : ($status===419 ? 'Sessão expirada' : 'Não foi possível concluir') }}</h1>
    <p>{{ $message }}</p>
    <a href="{{ $status===401 || $status===419 ? url('/login') : url('/') }}">{{ $status===401 || $status===419 ? 'Entrar novamente' : 'Voltar ao sistema' }}</a>
  </main>
</body>
</html>
