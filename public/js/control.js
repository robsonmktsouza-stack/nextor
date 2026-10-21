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

  // Um único comportamento para todas as grades do Control.
  document.addEventListener('click', event => {
    const toggle = event.target.closest?.('[data-grid-filter-toggle]');
    if (!toggle) return;
    const filterId = toggle.getAttribute('aria-controls');
    const panel = filterId ? document.getElementById(filterId) : null;
    if (!panel) return;
    panel.hidden = !panel.hidden;
    toggle.setAttribute('aria-expanded', String(!panel.hidden));
    if (!panel.hidden) panel.querySelector('input[type="search"]')?.focus();
  });
  document.addEventListener('change', event => {
    if (!event.target.matches?.('[data-page-size]')) return;
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

  // Print/CSV affect only the grid whose toolbar was clicked. Actions,
  // switches and technical buttons never enter the exported columns.
  const gridFor = button => button.closest('[data-admin-list]');
  const csvCell = value => {
    const raw = String(value ?? '').replace(/\s+/g, ' ').trim();
    const escapedFormula = /^[=+\-@\t\r]/.test(raw) ? "'" + raw : raw;
    return '"' + escapedFormula.replace(/"/g, '""') + '"';
  };
  document.addEventListener('click', event => {
    const printButton = event.target.closest?.('[data-grid-print]');
    if (printButton) {
      const grid = gridFor(printButton);
      if (!grid) return;
      document.body.classList.add('print-grid-only');
      grid.classList.add('print-target');
      window.print();
      // Chrome fires afterprint; fallback for browsers without it.
      const cleanPrint = () => {
        document.body.classList.remove('print-grid-only');
        grid.classList.remove('print-target');
        window.removeEventListener('afterprint', cleanPrint);
      };
      window.addEventListener('afterprint', cleanPrint, {once: true});
      setTimeout(cleanPrint, 1500);
      return;
    }
    const exportButton = event.target.closest?.('[data-grid-export]');
    if (!exportButton) return;
    const grid = gridFor(exportButton);
    const table = grid?.querySelector('[data-grid-table]');
    if (!table) return;
    const allHeaders = [...table.querySelectorAll('thead th')];
    const included = allHeaders.map((th, index) => ({
      index,
      keep: !th.matches('.license-actions-heading,[data-grid-export-exclude]')
        && th.textContent.trim().toLowerCase() !== 'ações'
    })).filter(item => item.keep).map(item => item.index);
    const headers = included.map(index => allHeaders[index].textContent.trim());
    const rows = [headers];
    table.querySelectorAll('tbody tr').forEach(tr => {
      const cells = [...tr.querySelectorAll(':scope > td')];
      if (cells.length !== allHeaders.length) return;
      rows.push(included.map(index => {
        const clone = cells[index].cloneNode(true);
        clone.querySelectorAll('form,input,button,svg,.switch-track').forEach(node => node.remove());
        return clone.textContent.trim();
      }));
    });
    const content = '\uFEFF' + rows.map(line => line.map(csvCell).join(';')).join('\r\n');
    const url = URL.createObjectURL(new Blob([content], {type:'text/csv;charset=utf-8'}));
    const a = document.createElement('a');
    const safeName = (grid.dataset.gridFilename || 'listagem').replace(/[^a-z0-9_-]/gi, '-');
    a.href = url;
    a.download = safeName + '-' + new Date().toISOString().slice(0,10) + '.csv';
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
  });

})();
