<!doctype html>
<html lang="pt-BR"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Acesso administrativo — Lumeron</title>
<link rel="icon" href="{{ asset('images/lumeron/elephant.png') }}">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="{{ asset('css/control.css') }}">
</head><body class="login-page">
<div class="login-panel">
<img class="login-logo" src="{{ asset('images/lumeron/logo.png') }}" alt="Lumeron">
<h1>Acesso administrativo</h1>
@if($errors->any())<div class="notice error" role="alert">{{ $errors->first() }}</div>@endif
<form method="post" action="{{ route('login.post') }}">@csrf
<label>E-mail<input name="email" type="email" autocomplete="username" required autofocus value="{{ old('email') }}" placeholder="seu@email.com"></label>
<label>Senha<input name="password" type="password" autocomplete="current-password" required placeholder="Sua senha"></label>
<button type="submit" class="btn primary full">Entrar no painel</button>
</form>
</div>
</body></html>
