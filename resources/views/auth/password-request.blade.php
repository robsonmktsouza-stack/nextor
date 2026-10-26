<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Recuperar senha · Lumeron</title>
  <link rel="icon" type="image/png" href="{{ asset('images/lumeron/elephant.png') }}">
  <link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="{{ asset('css/ui-tokens.css') }}?v={{ filemtime(public_path('css/ui-tokens.css')) }}">
<link rel="stylesheet" href="{{ asset('css/login.css') }}?v={{ filemtime(public_path('css/login.css')) }}">
<script defer src="{{ asset('js/user-messages.js') }}?v={{ filemtime(public_path('js/user-messages.js')) }}"></script>
</head>
<body class="lumeron-login">
  <main class="login-shell">
    <div class="login-brand"><img class="lumeron-login-logo" src="{{ asset('images/lumeron/logo.png') }}" alt="Lumeron" width="236" height="61">
    </div>
    <section class="login-panel">
      <header class="login-panel-header"><h1>Recuperar senha</h1>
        <p>Enviaremos as instruções para o e-mail cadastrado.</p>
      </header>
      @if(session('status'))<div class="login-feedback is-success" role="status">{{ session('status') }}</div>@endif
      @if($errors->any())<div class="login-feedback is-error" role="alert">{{ $errors->first() }}</div>@endif
      <form method="post" action="{{ route('password.email') }}">
        @csrf
        <label class="login-label" for="resetEmail">E-mail cadastrado</label>
        <input class="login-input" type="email" id="resetEmail" name="email"
          value="{{ old('email') }}" required autocomplete="email" autofocus placeholder="email@empresa.com.br">
        <button type="submit" class="login-submit">Enviar instruções</button>
      </form>
      <a href="{{ route('login') }}" class="login-back-link">Voltar ao acesso</a>
    </section>
  </main>
</body>
</html>
