(()=>{
  'use strict';
  // Visualização do cupom no modal; impressão fica no PDF nativo autenticado.
  const openReceipt=(dialog,restoreFocus=null,restoreSales=null)=>{
    if(!dialog || dialog.dataset.receiptInitialized==='1')return;
    dialog.dataset.receiptInitialized='1';
    const history=dialog.dataset.receiptAfterCheckout==='0';
    const done=dialog.querySelector('[data-pdv-receipt-done]');
    const dismiss=dialog.querySelector('[data-pdv-receipt-close]');
    const close=()=>{if(dialog.open)dialog.close();};
    done?.addEventListener('click',close);
    dismiss?.addEventListener('click',close);
    dialog.addEventListener('close',()=>{
      if(history){
        dialog.remove();
        restoreSales?.();
        if(restoreFocus?.isConnected)restoreFocus.focus();
        else document.getElementById('pdvSalesSearch')?.focus();
      }else document.getElementById('pdvSearch')?.focus();
    },{once:true});
    dialog.showModal();
    done?.focus();
  };
  window.LumeronPdvReceipt={
    openHistory(dialog,restoreFocus,restoreSales){
      if(dialog?.dataset.receiptAfterCheckout==='0')openReceipt(dialog,restoreFocus,restoreSales);
    }
  };
  document.addEventListener('DOMContentLoaded',()=>{
    const dialog=document.getElementById('pdvReceiptModal');
    if(dialog?.dataset.receiptAfterCheckout==='1')openReceipt(dialog);
  });
})();
