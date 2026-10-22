(() => {
  'use strict';

  document.addEventListener('DOMContentLoaded', () => {
    const dialog = document.getElementById('pdvReceiptModal');
    if (!dialog || dialog.dataset.receiptAfterCheckout !== '1') return;

    const frame = document.getElementById('pdvReceiptFrame');
    const printButton = document.getElementById('pdvReceiptPrint');
    const doneButton = document.getElementById('pdvReceiptDone');
    const closeButton = document.getElementById('pdvReceiptCloseTop');
    let ready = false;

    const updatePrintReady = () => {
      try {
        ready = Boolean(frame?.contentDocument?.querySelector('main.receipt'));
      } catch (_) {
        ready = false;
      }
      if (printButton) printButton.disabled = !ready;
    };
    frame?.addEventListener('load', updatePrintReady);
    // The print document might load before the deferred script starts.
    if (frame?.contentDocument?.readyState === 'complete') updatePrintReady();

    const close = () => {
      if (dialog.open) dialog.close();
    };
    doneButton?.addEventListener('click', close);
    closeButton?.addEventListener('click', close);

    printButton?.addEventListener('click', () => {
      if (!ready || !frame?.contentWindow) return;
      try {
        // Only user action opens printing. The sale is already saved and
        // closing/cancelling the print dialog never repeats checkout or NFC-e.
        frame.contentWindow.focus();
        frame.contentWindow.print();
      } catch (error) {
        printButton.disabled = true;
        const note = document.createElement('span');
        note.className = 'pdv-receipt-print-error';
        note.setAttribute('role', 'alert');
        note.textContent = 'Não foi possível abrir a impressão. Consulte a venda pelo histórico.';
        printButton.parentElement?.prepend(note);
      }
    });

    dialog.addEventListener('close', () => {
      document.getElementById('pdvSearch')?.focus();
    });

    // showModal keeps focus and Escape handling native. It overlays the
    // existing cashier screen; there is no redirect to a receipt page.
    dialog.showModal();
    doneButton?.focus();
  });
})();
