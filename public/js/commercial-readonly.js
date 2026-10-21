(() => {
  'use strict';

  // A interface apenas informa; RequireCentralLicense aplica a regra no servidor.
  const banner = document.querySelector('[data-commercial-readonly]');
  if (!banner) return;

  const dialog = document.getElementById('commercialReadOnlyDialog');
  const defaultMessage = banner.dataset.deniedMessage ||
    'Esta operação está indisponível enquanto a assinatura não estiver ativa.';
  let lastNoticeAt = 0;

  const showUnavailable = (message = defaultMessage) => {
    if (dialog) {
      const description = dialog.querySelector('#commercialReadOnlyDescription');
      if (description) description.textContent = message;
      if (!dialog.open) dialog.showModal();
      dialog.querySelector('[data-license-dialog-close]')?.focus({preventScroll: true});
    } else {
      window.NextorNotify?.(message, {
        type: 'warning',
        title: 'Modo de consulta',
        duration: 7000
      });
    }
    lastNoticeAt = Date.now();
  };

  window.LumeronCommercialReadOnly = {active: true, show: showUnavailable};

  dialog?.querySelectorAll('[data-license-dialog-close]').forEach(button => {
    button.addEventListener('click', () => dialog.close());
  });
  dialog?.addEventListener('click', event => {
    if (event.target === dialog) dialog.close();
  });

  const urlOf = value => {
    try { return new URL(value, window.location.href); }
    catch { return null; }
  };

  const isExemptWrite = (method, urlValue) => {
    const url = urlOf(urlValue);
    if (!url || url.origin !== window.location.origin) return true;

    const path = url.pathname.replace(/\/+$/, '') || '/';
    if (method === 'GET' || method === 'HEAD' || method === 'OPTIONS') return true;
    if (method === 'POST' && path === '/logout') return true;

    // Somente operações fiscais legais e downloads autorizados por regra
    // explícita. A validação final é sempre feita no backend.
    if (method !== 'POST') return false;
    return path === '/fiscal/utilities/xml'
      || path === '/fiscal/nfce/inutilizations'
      || /^\/pdv\/nfce\/[^/]+\/cancel$/.test(path)
      || /^\/fiscal\/[^/]+\/(?:consult-nfce|cancel-nfce|transmit-offline|recover-nfce-xml)$/.test(path);
  };

  const methodOf = form => {
    const override = form.querySelector('input[name="_method"]')?.value;
    return String(override || form.getAttribute('method') || 'GET').toUpperCase();
  };

  // Formulários convencionais e modais, inclusive formulários dinâmicos.
  document.addEventListener('submit', event => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) return;
    if (isExemptWrite(methodOf(form), form.action)) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    showUnavailable();
  }, true);

  // Alguns módulos fazem submissão direta sem disparar o evento submit.
  const nativeSubmit = HTMLFormElement.prototype.submit;
  HTMLFormElement.prototype.submit = function () {
    if (isExemptWrite(methodOf(this), this.action)) {
      return nativeSubmit.call(this);
    }
    showUnavailable();
  };

  const nativeRequestSubmit = HTMLFormElement.prototype.requestSubmit;
  if (nativeRequestSubmit) {
    HTMLFormElement.prototype.requestSubmit = function (submitter) {
      if (isExemptWrite(methodOf(this), this.action)) {
        return nativeRequestSubmit.call(this, submitter);
      }
      showUnavailable();
    };
  }

  document.addEventListener('click', event => {
    if (event.defaultPrevented) return;
    const link = event.target.closest?.('a[href]');
    if (link) {
      const url = urlOf(link.href);
      // Entrar em "Novo/Editar" não cria dados, mas não faz sentido oferecer
      // um formulário com Salvar bloqueado. Consultas continuam livres.
      if (url?.origin === location.origin
          && /\/(?:create|edit)(?:\/|$)/.test(url.pathname)) {
        event.preventDefault();
        event.stopImmediatePropagation();
        showUnavailable();
      }
      return;
    }

    // No PDV, fluxos acionados pelo teclado/botões de trabalho não precisam
    // abrir telas que não podem ser concluídas. A consulta de vendas segue.
    if (!document.body.classList.contains('pdv-body')) return;
    const button = event.target.closest?.('button[type="button"], button:not([type])');
    if (!button || button.closest('#commercialReadOnlyDialog')) return;
    const label = (button.getAttribute('aria-label') || button.textContent || '').trim();
    if (/\b(?:finalizar|concluir venda|receber pagamento|suspender venda|abrir caixa|fechar caixa|salvar pagamento|registrar movimenta)/i.test(label)) {
      event.preventDefault();
      event.stopImmediatePropagation();
      showUnavailable();
    }
  }, true);

  // Requisições assíncronas ou acionadas pelo teclado não burlam o servidor.
  // Apenas apresenta a mesma explicação quando o backend responde 423.
  const nativeFetch = window.fetch.bind(window);
  window.fetch = async (...args) => {
    const response = await nativeFetch(...args);
    if (response.status === 423 && Date.now() - lastNoticeAt > 800) {
      const payload = await response.clone().json().catch(() => ({}));
      if (payload.code === 'LUMERON_LICENSE_READ_ONLY') {
        showUnavailable(payload.message || defaultMessage);
      }
    }
    return response;
  };

  const nativeSend = XMLHttpRequest.prototype.send;
  XMLHttpRequest.prototype.send = function (...args) {
    this.addEventListener('loadend', () => {
      if (this.status !== 423 || Date.now() - lastNoticeAt <= 800) return;
      try {
        const payload = JSON.parse(this.responseText);
        if (payload.code === 'LUMERON_LICENSE_READ_ONLY') {
          showUnavailable(payload.message || defaultMessage);
        }
      } catch { /* Resposta não JSON: sem notificação duplicada. */ }
    }, {once: true});
    return nativeSend.apply(this, args);
  };
})();
