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

})();
