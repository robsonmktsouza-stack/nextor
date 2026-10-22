(()=>{
  'use strict';
  document.addEventListener('DOMContentLoaded',()=>{
    const dialog=document.getElementById('pdvCashReportModal');
    if(!dialog) return;
    const frame=dialog.querySelector('[data-cash-report-frame]');
    const loading=dialog.querySelector('[data-cash-report-loading]');
    const printButton=dialog.querySelector('[data-cash-report-print]');
    const closeButtons=dialog.querySelectorAll('[data-cash-report-close],[data-cash-report-back]');
    let lastTrigger=null;
    let loaded=false;

    const frameUrl=url=>{
      try{
        const target=new URL(url,window.location.href);
        if(target.origin!==window.location.origin || !/^\\/pdv\\/cash\\/\\d+\\/report$/.test(target.pathname)) return null;
        target.searchParams.set('embed','1');
        return target.href;
      }catch{return null;}
    };

    const close=()=>{if(dialog.open)dialog.close();};
    closeButtons.forEach(button=>button.addEventListener('click',close));
    dialog.addEventListener('close',()=>{
      // Release the frame after the dialog finishes to avoid repeated
      // rendering/observers retained across many cashier shifts.
      frame.removeAttribute('src');
      printButton.disabled=true;
      loaded=false;
      if(lastTrigger?.isConnected)lastTrigger.focus();
      lastTrigger=null;
    });
    dialog.addEventListener('click',event=>{
      if(event.target===dialog)close();
    });
    frame.addEventListener('load',()=>{
      if(!dialog.open)return;
      loading.hidden=true;
      loaded=true;
      printButton.disabled=false;
    });
    printButton.addEventListener('click',()=>{
      if(!loaded)return;
      try {
        frame.contentWindow.focus();
        frame.contentWindow.print();
      }catch{
        const url=frame.getAttribute('src');
        if(url)window.open(url,'_blank','noopener');
      }
    });
    const open=(url,trigger=null)=>{
      const safeUrl=frameUrl(url);
      if(!safeUrl)return;
      lastTrigger=trigger;
      loaded=false;
      loading.hidden=false;
      printButton.disabled=true;
      if(!dialog.open)dialog.showModal();
      frame.src=safeUrl;
      dialog.querySelector('[data-cash-report-back]')?.focus();
    };
    document.addEventListener('click',event=>{
      const trigger=event.target.closest('[data-cash-report-open]');
      if(!trigger)return;
      const target=frameUrl(trigger.dataset.cashReportUrl||trigger.href);
      if(!target)return;
      event.preventDefault();
      open(target,trigger);
    });
    const automatic=frameUrl(dialog.dataset.autoReportUrl);
    if(automatic)requestAnimationFrame(()=>open(automatic));
  });
})();
