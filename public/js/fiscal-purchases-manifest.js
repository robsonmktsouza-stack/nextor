/* A escolha na listagem apenas abre confirmação; nunca transmite no evento change. */
document.addEventListener('DOMContentLoaded', () => {
  const dialog = document.getElementById('purchaseManifestDialog');
  const form = document.getElementById('purchaseManifestForm');
  if (!dialog || !form) return;
  const eventType = document.getElementById('purchaseManifestType');
  const eventLabel = document.getElementById('purchaseManifestEvent');
  const keyLabel = document.getElementById('purchaseManifestKey');
  const reasonBox = document.getElementById('purchaseManifestReasonBox');
  const reason = document.getElementById('purchaseManifestReason');
  const warning = document.getElementById('purchaseManifestWarning');
  let currentSelect = null;

  document.addEventListener('change', (event) => {
    const select = event.target.closest?.('[data-manifest-select]');
    if (!select || !select.value) return;
    const code = select.value;
    const label = select.options[select.selectedIndex]?.textContent?.trim() || '';
    currentSelect = select;
    eventType.value = code;
    eventLabel.textContent = label;
    keyLabel.textContent = 'NF-e: ' + select.dataset.invoiceKey;
    form.action = select.dataset.manifestUrl;
    const requiresReason = code === '210240';
    reasonBox.hidden = !requiresReason;
    reason.required = requiresReason;
    reason.value = '';
    const issued = select.dataset.issuedDate;
    const issuedMs = issued ? Date.parse(issued + 'T12:00:00') : NaN;
    const olderThanTenDays = Number.isFinite(issuedMs) &&
      (Date.now() - issuedMs) > 10 * 24 * 60 * 60 * 1000;
    warning.textContent = code === '210210' && olderThanTenDays
      ? 'A nota foi emitida há mais de 10 dias. A Ciência da Operação pode ser rejeitada por prazo vencido (contado da autorização).'
      : code === '210200'
      ? 'Confirme somente após a operação realmente ocorrer. O evento impede o cancelamento da NF-e pelo emitente.'
      : 'O evento será transmitido ao ambiente de produção da SEFAZ.';
    select.value = '';
    if (typeof dialog.showModal === 'function') dialog.showModal();
  });

  dialog.addEventListener('close', () => {
    if (currentSelect) currentSelect.value = '';
    currentSelect = null;
  });
  form.addEventListener('submit', (event) => {
    if (!eventType.value || !form.action || (reason.required && !reason.checkValidity())) {
      event.preventDefault();
      reason.reportValidity();
      return;
    }
    const button = form.querySelector('[type="submit"]');
    if (button) {
      button.disabled = true;
      button.textContent = 'Enviando...';
    }
  });
});
