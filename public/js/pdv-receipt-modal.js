(()=>{
  'use strict';

  const initializeReceipt=(dialog,restoreFocus=null)=>{
    if(!dialog || dialog.dataset.receiptInitialized==='1')return;
    dialog.dataset.receiptInitialized='1';
    const history=dialog.dataset.receiptAfterCheckout==='0';
    const frame=dialog.querySelector('[data-pdv-receipt-frame]');
    const printButton=dialog.querySelector('[data-pdv-receipt-print]');
    const doneButton=dialog.querySelector('[data-pdv-receipt-done]');
    const closeButton=dialog.querySelector('[data-pdv-receipt-close]');
    let ready=false;

    const updatePrintReady=()=>{
      try{
        ready=Boolean(frame?.contentDocument?.querySelector('main.receipt'));
      }catch(_){
        ready=false;
      }
      if(printButton)printButton.disabled=!ready;
    };
    frame?.addEventListener('load',updatePrintReady);
    if(frame?.contentDocument?.readyState==='complete')updatePrintReady();

    const close=()=>{if(dialog.open)dialog.close();};
    doneButton?.addEventListener('click',close);
    closeButton?.addEventListener('click',close);
    printButton?.addEventListener('click',()=>{
      if(!ready || !frame?.contentWindow)return;
      try{
        // A venda já foi gravada; a impressão nunca executa checkout novamente.
        frame.contentWindow.focus();
        frame.contentWindow.print();
      }catch(_){
        printButton.disabled=true;
        if(!dialog.querySelector('.pdv-receipt-print-error')){
          const note=document.createElement('span');
          note.className='pdv-receipt-print-error';
          note.setAttribute('role','alert');
          note.textContent='Não foi possível abrir a impressão. Consulte a venda pelo histórico.';
          printButton.parentElement?.prepend(note);
        }
      }
    });

    dialog.addEventListener('close',()=>{
      if(history){
        dialog.remove();
        if(restoreFocus?.isConnected)restoreFocus.focus();
        else document.getElementById('pdvSalesSearch')?.focus();
      }else{
        document.getElementById('pdvSearch')?.focus();
      }
    },{once:true});

    dialog.showModal();
    doneButton?.focus();
  };

  window.LumeronPdvReceipt={
    openHistory(dialog,restoreFocus){
      if(dialog?.dataset.receiptAfterCheckout==='0')
        initializeReceipt(dialog,restoreFocus);
    }
  };
  document.addEventListener('DOMContentLoaded',()=>{
    const dialog=document.getElementById('pdvReceiptModal');
    if(dialog?.dataset.receiptAfterCheckout==='1')
      initializeReceipt(dialog);
  });
})();
