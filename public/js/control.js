'use strict';

(() => {
  const menu = document.querySelector('[data-menu-toggle]');
  menu?.addEventListener('click', () => document.querySelector('#sidebar')?.classList.toggle('open'));

  document.querySelectorAll('[data-confirm]').forEach(button => {
    button.addEventListener('click', event => {
      if (!window.confirm(button.dataset.confirm)) event.preventDefault();
    });
  });

  const dialog = document.querySelector('#companyDialog');
  const dialogBody = document.querySelector('#companyDialogBody');
  document.querySelectorAll('[data-account-details]').forEach(button => {
    button.addEventListener('click', async () => {
      if (!dialog || !dialogBody) return;
      dialogBody.textContent = 'Carregando conta...';
      dialog.showModal();
      try {
        const response = await fetch(button.dataset.accountDetails, {
          headers: { Accept: 'text/html' }, credentials: 'same-origin'
        });
        if (!response.ok) throw new Error('Não foi possível carregar esta conta.');
        dialogBody.innerHTML = await response.text();
        dialogBody.querySelectorAll('[data-confirm]').forEach(action => {
          action.addEventListener('click', event => {
            if (!window.confirm(action.dataset.confirm)) event.preventDefault();
          });
        });
      } catch (error) {
        dialogBody.textContent = error.message;
      }
    });
  });
  document.querySelector('[data-dialog-close]')?.addEventListener('click', () => dialog?.close());
  dialog?.addEventListener('click', event => {
    if (event.target === dialog) dialog.close();
  });

  const form = document.querySelector('#newAccountForm');
  if (form) {
    const steps = [...form.querySelectorAll('[data-wizard-step]')];
    const firstErrorField = form.dataset.errorField ?? '';
    const firstErrorStep = firstErrorField
      ? steps.findIndex(step => [...step.querySelectorAll('[name]')].some(input =>
          input.name === firstErrorField
          || (input.name.endsWith('[]') && firstErrorField.startsWith(input.name.slice(0, -2)))))
      : -1;
    let current = firstErrorStep >= 0 ? firstErrorStep : 0;
    const next = form.querySelector('[data-wizard-next]');
    const back = form.querySelector('[data-wizard-back]');
    const submit = form.querySelector('[data-wizard-submit]');
    const dots = [...form.querySelectorAll('[data-step-dot]')];
    const paint = () => {
      steps.forEach((step, index) => { step.hidden = index !== current; });
      dots.forEach((dot, index) => {
        dot.classList.toggle('current', index === current);
        dot.classList.toggle('done', index < current);
      });
      back.hidden = current === 0;
      next.hidden = current === steps.length - 1;
      submit.hidden = current !== steps.length - 1;
    };
    next?.addEventListener('click', () => {
      const invalid = [...steps[current].querySelectorAll('input,select,textarea')]
        .find(input => !input.checkValidity());
      if (invalid) { invalid.reportValidity(); invalid.focus(); return; }
      current = Math.min(current + 1, steps.length - 1);
      paint();
    });
    back?.addEventListener('click', () => { current = Math.max(0, current - 1); paint(); });
    form.addEventListener('submit', event => {
      const invalidStep = steps.findIndex(step =>
        [...step.querySelectorAll('input,select,textarea')].some(input => !input.checkValidity()));
      if (invalidStep >= 0) {
        event.preventDefault();
        current = invalidStep;
        paint();
        const invalid = [...steps[current].querySelectorAll('input,select,textarea')]
          .find(input => !input.checkValidity());
        invalid?.reportValidity();
      }
    });
    paint();
    if (firstErrorStep >= 0) {
      const invalidField = [...steps[firstErrorStep].querySelectorAll('[name]')]
        .find(input => input.name === firstErrorField
          || (input.name.endsWith('[]') && firstErrorField.startsWith(input.name.slice(0, -2))));
      invalidField?.setAttribute('aria-invalid', 'true');
      invalidField?.focus({ preventScroll: true });
    }
  }

  // Campos da página e de detalhes carregados depois por AJAX.
  // Ao trocar o plano, aplica os módulos padrão; ainda é possível ajustá-los.
  document.addEventListener('change', event => {
    const select = event.target.closest?.('[data-plan-selector]');
    if (!select) return;
    const form = select.closest('form');
    if (!form) return;
    const defaults = (select.selectedOptions[0]?.dataset.modules || '')
      .split(',').filter(Boolean);
    form.querySelectorAll('input[name="modules[]"]').forEach(box => {
      box.checked = defaults.includes(box.value);
    });
  });

  const filterToggle = document.querySelector('[data-license-filter-toggle]');
  const filterPanel = document.querySelector('#license-list-filters');
  filterToggle?.addEventListener('click', () => {
    if (!filterPanel) return;
    filterPanel.hidden = !filterPanel.hidden;
    filterToggle.setAttribute('aria-expanded', String(!filterPanel.hidden));
    if (!filterPanel.hidden) filterPanel.querySelector('input[type="search"]')?.focus();
  });
  document.querySelector('[data-page-size]')?.addEventListener('change', event => {
    event.target.form?.requestSubmit();
  });

  const licenseConfirmation = document.querySelector('#licenseActivationDialog');
  let pendingToggle = null;
  const cancelLicenseChange = () => {
    if (pendingToggle) {
      pendingToggle.checked = !pendingToggle.checked;
      pendingToggle = null;
    }
    if (licenseConfirmation?.open) licenseConfirmation.close();
  };
  document.querySelectorAll('[data-license-cancel]').forEach(button => {
    button.addEventListener('click', cancelLicenseChange);
  });
  licenseConfirmation?.addEventListener('cancel', event => {
    event.preventDefault();
    cancelLicenseChange();
  });
  licenseConfirmation?.addEventListener('click', event => {
    if (event.target === licenseConfirmation) cancelLicenseChange();
  });
  licenseConfirmation?.querySelector('[data-license-confirm]')?.addEventListener('click', () => {
    if (!pendingToggle) return;
    const input = pendingToggle;
    pendingToggle = null;
    licenseConfirmation.close();
    const action = input.checked ? 'activate' : 'suspend';
    const switchForm = input.closest('form[data-license-switch-form]');
    if (!switchForm) return;
    switchForm.querySelector('input[name="action"]').value = action;
    input.disabled = true;
    switchForm.submit();
  });

  // O status visual acompanha a confirmação do operador. A mudança real é
  // feita pelo endpoint de licença autenticado, com CSRF e histórico.
  document.addEventListener('change', event => {
    const input = event.target;
    if (!input?.matches?.('[data-license-switch]')) return;
    if (pendingToggle) cancelLicenseChange();
    pendingToggle = input;
    const action = input.checked ? 'ativar' : 'suspender';
    const companyName = input.closest('tr')?.querySelector('.license-name')?.textContent?.trim()
      || input.closest('.detail-grid')?.parentElement?.querySelector('.client-overview h2')?.textContent?.trim()
      || 'esta empresa';
    if (!licenseConfirmation?.showModal) {
      const ok = window.confirm('Deseja ' + action + ' o acesso comercial de ' + companyName + '?');
      if (!ok) { cancelLicenseChange(); return; }
      const switchForm = input.closest('form[data-license-switch-form]');
      switchForm.querySelector('input[name="action"]').value = input.checked ? 'activate' : 'suspend';
      pendingToggle = null;
      switchForm.submit();
      return;
    }
    const headline = licenseConfirmation.querySelector('#licenseConfirmTitle');
    const message = licenseConfirmation.querySelector('#licenseConfirmMessage');
    if (headline) headline.textContent = input.checked ? 'Reativar acesso comercial?' : 'Suspender acesso comercial?';
    if (message) message.textContent = input.checked
      ? 'A licença de ' + companyName + ' voltará a permitir acesso comercial, desde que não esteja vencida.'
      : 'A licença de ' + companyName + ' será suspensa. O ERP continuará permitindo consultas e regularizações fiscais autorizadas.';
    licenseConfirmation.showModal();
  });

  document.querySelector('[data-grid-print]')?.addEventListener('click', () => window.print());
  document.querySelector('[data-grid-export]')?.addEventListener('click', () => {
    const table = document.querySelector('[data-license-export-table]');
    if (!table) return;
    const clean = value => String(value ?? '').replace(/\s+/g, ' ').trim();
    const quoted = value => {
      const v = clean(value);
      // Evita execução de fórmulas ao abrir um CSV exportado no Excel.
      const safe = /^[=+\-@\t\r]/.test(v) ? "'" + v : v;
      return '"' + safe.replace(/"/g, '""') + '"';
    };
    const columns = [...table.querySelectorAll('thead th')].slice(0, 7).map(c => clean(c.textContent));
    const lines = [columns];
    table.querySelectorAll('tbody tr').forEach(tr => {
      const cells = [...tr.querySelectorAll(':scope > td')];
      if (cells.length !== 8) return;
      lines.push(cells.slice(0, 7).map(c => clean(c.textContent)));
    });
    const content = '\uFEFF' + lines.map(line => line.map(quoted).join(';')).join('\r\n');
    const blob = new Blob([content], {type: 'text/csv;charset=utf-8'});
    const href = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = href;
    a.download = 'licencas-' + new Date().toISOString().slice(0, 10) + '.csv';
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(href), 1000);
  });

})();
