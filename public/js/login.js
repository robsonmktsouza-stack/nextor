(() => {
  'use strict';
  const form = document.getElementById('lumeronLoginForm');
  if (!form) return;
  const slugInput = document.getElementById('loginSubdomain');
  const userInput = document.getElementById('loginUsername');
  const passwordInput = document.getElementById('loginPassword');
  const noSave = document.getElementById('loginNoSave');
  const button = document.getElementById('loginSubmit');
  const buttonText = document.getElementById('loginSubmitText');
  const spinner = button.querySelector('.login-submit-spinner');
  const feedback = document.getElementById('loginFeedback');
  const toggle = document.getElementById('loginTogglePassword');
  const forgot = document.getElementById('loginForgot');
  const portal = form.dataset.portal === '1';
  const baseDomain = form.dataset.baseDomain || 'lumeron.com.br';
  const slugPattern = /^[a-z][a-z0-9-]{1,38}$/;
  const storageKey = 'lumeron:last-login-identity';
  let busy = false;

  const normalizeSlug = value => String(value || '').trim().toLowerCase();
  const notify = message => {
    feedback.textContent = message;
    feedback.hidden = !message;
    feedback.classList.toggle('is-error', Boolean(message));
  };
  const setBusy = active => {
    busy = active;
    button.disabled = active;
    buttonText.textContent = active ? 'Entrando...' : 'Entrar';
    spinner.hidden = !active;
    form.setAttribute('aria-busy', active ? 'true' : 'false');
  };
  const tenantOrigin = slug => {
    const clean = normalizeSlug(slug);
    if (!slugPattern.test(clean) || clean.includes('--') || ['admin', 'api', 'login', 'www'].includes(clean)) {
      return null;
    }
    // Only a subdomain of the configured base domain. Never arbitrary URLs.
    if (!/^[a-z0-9]+(?:[.-][a-z0-9]+)*\.[a-z]{2,}$/.test(baseDomain)) return null;
    return 'https://' + clean + '.' + baseDomain;
  };
  const saveIdentity = () => {
    try {
      if (noSave.checked) window.localStorage.removeItem(storageKey);
      else window.localStorage.setItem(storageKey, JSON.stringify({
        subdomain: normalizeSlug(slugInput.value),
        username: userInput.value.trim()
      }));
      // Passwords are never written to localStorage or sessionStorage.
    } catch (_) { /* Browser may block storage. Login remains available. */ }
  };
  try {
    const stored = JSON.parse(window.localStorage.getItem(storageKey) || 'null');
    if (stored && typeof stored === 'object') {
      if (portal && !slugInput.value && tenantOrigin(stored.subdomain)) slugInput.value = stored.subdomain;
      if (!userInput.value && typeof stored.username === 'string') userInput.value = stored.username.slice(0, 255);
    }
  } catch (_) {}

  toggle.addEventListener('click', () => {
    const visible = passwordInput.type === 'text';
    passwordInput.type = visible ? 'password' : 'text';
    toggle.setAttribute('aria-pressed', visible ? 'false' : 'true');
    toggle.setAttribute('aria-label', visible ? 'Mostrar senha' : 'Ocultar senha');
    toggle.title = visible ? 'Mostrar senha' : 'Ocultar senha';
  });
  noSave.addEventListener('change', () => {
    if (noSave.checked) {
      try { window.localStorage.removeItem(storageKey); } catch (_) {}
      form.autocomplete = 'off';
    } else form.autocomplete = 'on';
  });
  slugInput.addEventListener('input', () => {
    const clean = normalizeSlug(slugInput.value).replace(/[^a-z0-9-]/g, '');
    if (slugInput.value !== clean) slugInput.value = clean;
    notify('');
  });
  userInput.addEventListener('input', () => notify(''));

  forgot.addEventListener('click', event => {
    if (!portal) return;
    event.preventDefault();
    const origin = tenantOrigin(slugInput.value);
    if (!origin) {
      notify('Informe primeiro o subdomínio da empresa para recuperar a senha.');
      slugInput.focus();
      return;
    }
    window.location.assign(origin + '/forgot-password');
  });

  if (!portal) {
    form.addEventListener('submit', event => {
      const chosen = normalizeSlug(slugInput.value);
      const expected = normalizeSlug(form.dataset.defaultSlug);
      if (!slugPattern.test(chosen) || chosen.includes('--') || chosen !== expected) {
        event.preventDefault();
        notify('Confira o subdomínio. Este endereço pertence a outra instalação.');
        slugInput.focus();
        return;
      }
      saveIdentity();
    });
    return;
  }

  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (busy) return;
    notify('');
    const origin = tenantOrigin(slugInput.value);
    if (!origin) {
      notify('Informe um subdomínio válido.');
      slugInput.focus();
      return;
    }
    if (!userInput.value.trim() || !passwordInput.value) {
      notify('Informe seu usuário e sua senha.');
      return;
    }
    setBusy(true);
    try {
      const bootstrap = await fetch(origin + '/login/bootstrap', {
        method: 'GET', mode: 'cors', credentials: 'include',
        headers: { 'Accept': 'application/json' },
        cache: 'no-store'
      });
      if (!bootstrap.ok) throw new Error('Não foi possível localizar o ambiente desta empresa.');
      const boot = await bootstrap.json();
      if (!boot.csrf_token || typeof boot.csrf_token !== 'string') {
        throw new Error('Não foi possível iniciar uma sessão segura com a empresa.');
      }
      const reply = await fetch(origin + '/login', {
        method: 'POST', mode: 'cors', credentials: 'include',
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': boot.csrf_token,
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({
          subdomain: normalizeSlug(slugInput.value),
          username: userInput.value.trim(),
          password: passwordInput.value,
          remember: false
        })
      });
      if (!reply.ok) {
        let reason = 'Não foi possível entrar. Confira subdomínio, usuário e senha.';
        if (reply.status === 429) reason = 'Muitas tentativas. Aguarde alguns minutos.';
        else if (reply.status === 419) reason = 'A sessão expirou. Tente entrar novamente.';
        else {
          try {
            const err = await reply.json();
            reason = err.message && reply.status !== 500 ? err.message : reason;
            if (err.errors) reason = Object.values(err.errors).flat()[0] || reason;
          } catch (_) {}
        }
        throw new Error(reason);
      }
      const result = await reply.json();
      const redirect = new URL(result.redirect || '/', origin);
      if (redirect.origin !== origin) throw new Error('O destino de acesso foi recusado.');
      saveIdentity();
      passwordInput.value = '';
      window.location.assign(redirect.href);
    } catch (error) {
      notify(error.message === 'Failed to fetch'
        ? 'Não foi possível conectar à instalação. Confira o subdomínio ou a conexão.'
        : error.message || 'Não foi possível entrar. Tente novamente.');
      setBusy(false);
    }
  });
})();
