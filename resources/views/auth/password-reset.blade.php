<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Nova senha · Lumeron</title>
  <link rel="stylesheet" href="{{ asset('css/login.css') }}">
</head>
<body class="lumeron-login">
  <main class="login-shell">
    <div class="login-brand"><span class="login-brand-symbol" aria-hidden="true">L</span>
      <div><strong>Lumeron</strong><small>Gestão empresarial</small></div>
    </div>
    <section class="login-panel">
      <header class="login-panel-header"><h1>Definir nova senha</h1>
        <p>Cadastre uma senha com pelo menos 8 caracteres.</p>
      </header>
      @if($errors->any())<div class="login-feedback is-error" role="alert">{{ $errors->first() }}</div>@endif
      <form method="post" action="{{ route('password.update') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <input type="hidden" name="email" value="{{ $email }}">
        <label class="login-label" for="resetPassword">Nova senha</label>
        <input class="login-input" type="password" id="resetPassword" name="password"
          required minlength="8" autocomplete="new-password">
        <label class="login-label" for="resetConfirmation">Confirmar senha</label>
        <input class="login-input" type="password" id="resetConfirmation" name="password_confirmation"
          required minlength="8" autocomplete="new-password">
        <button type="submit" class="login-submit">Salvar nova senha</button>
      </form>
      <a href="{{ route('login') }}" class="login-back-link">Voltar ao acesso</a>
    </section>
  </main>
</body>
</html>
