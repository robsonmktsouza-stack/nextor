<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Recuperar senha · Lumeron</title>
  <link rel="stylesheet" href="{{ asset('css/login.css') }}">
</head>
<body class="lumeron-login">
  <main class="login-shell">
    <div class="login-brand"><span class="login-brand-symbol" aria-hidden="true">L</span>
      <div><strong>Lumeron</strong><small>Gestão empresarial</small></div>
    </div>
    <section class="login-panel">
      <header class="login-panel-header"><h1>Recuperar senha</h1>
        <p>Enviaremos as instruções para o e-mail cadastrado.</p>
      </header>
      @if(session('status'))<div class="login-feedback" role="status">{{ session('status') }}</div>@endif
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
    <footer class="login-copyright">A recuperação é individual para esta empresa.</footer>
  </main>
</body>
</html>
