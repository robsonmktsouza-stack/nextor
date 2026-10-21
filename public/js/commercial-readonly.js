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

  banner.addEventListener('click', () => {
    showUnavailable(banner.dataset.licenseExplanation || defaultMessage);
  });

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

  const lockSvg = '<svg class="license-lock-glyph" viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8.5 10V7a3.5 3.5 0 0 1 7 0v3"/></svg>';
  const writeVerb = /(?:\bnov[oa]\b|cadastrar|adicionar|salvar|alterar|editar|excluir|duplicar|inativar|ativar selecionad|concluir|finalizar|abrir caixa|fechar caixa|registrar (?:movimenta|pagamento|saída|entrada)|lançar|liquidar|baixar (?:conta|título)|gerar (?:recorrência|lançamento)|transferir|configurar)/i;
  const fiscalArea = () => window.location.pathname.startsWith('/fiscal/');
  const writeLink = link => {
    if (link.hasAttribute('data-license-write')) return true;
    if (link.hasAttribute('download')) return false;
    const url = urlOf(link.href);
    if (!url || url.origin !== window.location.origin) return false;
    const path = url.pathname.replace(/\/+$/, '');
    if (/\/(?:create|edit|new)$/.test(path)) return true;
    if (path === '/stock' && url.searchParams.get('open') === '1') return true;
    const label = ((link.getAttribute('aria-label') || '') + ' ' + link.textContent).trim();
    return !fiscalArea() && Boolean(writeVerb.test(label)) &&
      (link.matches('.page-actions a, .grid-actionbar a, .row-actions a, a.minor-link') ||
        /\/(?:settings|configura)/i.test(path));
  };
  const writableForm = form => form instanceof HTMLFormElement &&
    !isExemptWrite(methodOf(form), form.action);
  const actionTarget = element => {
    if (element.closest('#commercialReadOnlyDialog') || element === banner) return false;
    if (element.hasAttribute('data-license-write')) return true;
    if (element.matches('a[href]')) return writeLink(element);
    if (!(element instanceof HTMLButtonElement)) return false;
    if (element.closest('form') && writableForm(element.closest('form'))) return true;
    const formId = element.getAttribute('form') || element.dataset.bulkSubmit;
    if (formId && writableForm(document.getElementById(formId))) return true;
    if (element.matches('[data-bulk-apply]')) return true;
    if (element.dataset.dialogOpen) {
      const dlg = document.getElementById(element.dataset.dialogOpen);
      if (dlg && [...dlg.querySelectorAll('form')].some(writableForm)) return true;
    }
    const label = ((element.getAttribute('aria-label') || '') + ' ' + element.textContent).trim();
    if (!writeVerb.test(label) || fiscalArea()) return false;
    return element.matches('.page-actions button, .grid-actionbar button, .row-actions button, .dialog-footer button, .pdv-topbar button, .pdv-quick-modal button, .finance-actionbar button, [data-pdv-action]');
  };
  const lockedSelector = 'a[href],button';
  const markAction = element => {
    if (!actionTarget(element) || element.classList.contains('license-locked-action')) return;
    element.classList.add('license-locked-action');
    element.setAttribute('aria-disabled', 'true');
    element.setAttribute('data-license-action', 'write');
    if (!element.hasAttribute('title')) {
      element.title = 'Indisponível enquanto a assinatura estiver restrita';
    }
    element.insertAdjacentHTML('beforeend', lockSvg);
  };
  const scanActions = root => {
    if (root.matches?.(lockedSelector)) markAction(root);
    root.querySelectorAll?.(lockedSelector).forEach(markAction);
  };
  scanActions(document);
  // Modais e resultados de buscas inseridos dinamicamente também são sinalizados.
  let scanFrame = 0;
  const observer = new MutationObserver(records => {
    if (scanFrame || !records.some(record => record.addedNodes.length)) return;
    scanFrame = requestAnimationFrame(() => {
      scanFrame = 0;
      scanActions(document);
    });
  });
  observer.observe(document.body, {childList: true, subtree: true});

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
    const locked = event.target.closest?.('.license-locked-action');
    if (locked) {
      // Exportação e impressão de seleções continuam disponíveis.
      if (locked.matches('[data-bulk-apply]')) {
        const menu = locked.closest('.bulk-actions')?.querySelector('[data-bulk-menu]');
        if (['local:export', 'local:print'].includes(menu?.value)) return;
      }
      if (locked.hasAttribute('disabled')) return;
      event.preventDefault();
      event.stopImmediatePropagation();
      showUnavailable();
      return;
    }
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
