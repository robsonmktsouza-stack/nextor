<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="light">
  <meta name="robots" content="noindex,nofollow">
  <title>Entrar · Lumeron</title>
  <link rel="stylesheet" href="{{ asset('css/login.css') }}">
  <script src="{{ asset('js/login.js') }}" defer></script>
</head>
<body class="lumeron-login">
  <main class="login-shell">
    <div class="login-brand" aria-label="Lumeron">
      <span class="login-brand-symbol" aria-hidden="true">L</span>
      <div><strong>Lumeron</strong><small>Gestão empresarial</small></div>
    </div>

    <section class="login-panel" aria-labelledby="loginTitle">
      <header class="login-panel-header">
        <h1 id="loginTitle">Acesse seu sistema</h1>
        <p>Entre no ambiente da sua empresa.</p>
      </header>
      @if($errors->any())
        <div class="login-feedback is-error" role="alert">{{ $errors->first() }}</div>
      @endif
      <div class="login-feedback" id="loginFeedback" role="alert" hidden></div>
      <form
        method="post"
        action="{{ route('login') }}"
        id="lumeronLoginForm"
        data-portal="{{ $loginPortal ? '1' : '0' }}"
        data-default-slug="{{ $loginSubdomain }}"
        data-base-domain="{{ $loginBaseDomain }}"
        autocomplete="on"
      >
        @csrf
        <label class="login-label" for="loginSubdomain">Subdomínio (link de acesso)</label>
        <div class="login-subdomain-field">
          <input id="loginSubdomain" name="subdomain" type="text" value="{{ old('subdomain', $loginPortal ? '' : $loginSubdomain) }}"
            placeholder="suaempresa" inputmode="url" autocapitalize="none" spellcheck="false"
            pattern="[a-z][a-z0-9-]{1,38}" maxlength="39" autocomplete="off" required autofocus>
          <span class="login-subdomain-suffix">.{{ $loginBaseDomain }}</span>
        </div>
        <label class="login-label" for="loginUsername">Nome de usuário</label>
        <input class="login-input" id="loginUsername" name="username" type="text" value="{{ old('username') }}"
          placeholder="Seu usuário" autocapitalize="none" spellcheck="false" maxlength="255"
          autocomplete="username" required>
        <label class="login-label" for="loginPassword">Senha de acesso</label>
        <div class="login-password-field">
          <input id="loginPassword" name="password" type="password" placeholder="Sua senha"
            autocomplete="current-password" required>
          <button type="button" id="loginTogglePassword" aria-label="Mostrar senha" aria-pressed="false" title="Mostrar senha">
            <svg aria-hidden="true" width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
              <path d="M2 12s3.6-6.7 10-6.7S22 12 22 12s-3.6 6.7-10 6.7S2 12 2 12Z"/>
              <circle cx="12" cy="12" r="3"/>
            </svg>
          </button>
        </div>
        <label class="login-remember">
          <input type="checkbox" name="no_save" id="loginNoSave" value="1">
          <span>Não gravar dados de acesso</span>
        </label>
        <button type="submit" class="login-submit" id="loginSubmit">
          <span id="loginSubmitText">Entrar</span>
          <span class="login-submit-spinner" aria-hidden="true" hidden></span>
        </button>
      </form>
      <a class="login-forgot" href="{{ route('password.request') }}" id="loginForgot">Esqueci minha senha</a>
    </section>
    <footer class="login-copyright">Acesso exclusivo aos usuários autorizados.</footer>
  </main>
</body>
</html>
