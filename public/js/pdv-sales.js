(()=>{
  'use strict';
  document.addEventListener('DOMContentLoaded',()=>{
    const modal=document.getElementById('pdvSalesModal');
    const trigger=document.getElementById('pdvSalesLink');
    if(!modal || !trigger)return;

    const form=document.getElementById('pdvSalesFilters');
    const search=document.getElementById('pdvSalesSearch');
    const period=document.getElementById('pdvSalesPeriod');
    const from=document.getElementById('pdvSalesFrom');
    const to=document.getElementById('pdvSalesTo');
    const status=document.getElementById('pdvSalesStatus');
    const operator=document.getElementById('pdvSalesOperator');
    const rows=document.getElementById('pdvSalesRows');
    const count=document.getElementById('pdvSalesCount');
    const pageLabel=document.getElementById('pdvSalesPage');
    const previous=document.getElementById('pdvSalesPrevious');
    const next=document.getElementById('pdvSalesNext');
    const todayButton=document.getElementById('pdvSalesToday');
    const cancelSheet=document.getElementById('pdvSalesCancelSheet');
    const cancelForm=document.getElementById('pdvSalesCancelForm');
    const cancelReason=document.getElementById('pdvSalesCancelReason');
    const cancelAuthorizer=document.getElementById('pdvSalesCancelAuthorizer');
    const cancelCredential=document.getElementById('pdvSalesCancelCredential');
    const cancelNumber=document.getElementById('pdvSalesCancelNumber');
    const cancelSummary=document.getElementById('pdvSalesCancelSummary');
    const cancelError=document.getElementById('pdvSalesCancelError');
    const cancelConfirm=document.getElementById('pdvSalesCancelConfirm');
    const actionStatus=document.getElementById('pdvSalesActionStatus');
    const csrf=document.querySelector('meta[name="csrf-token"]')?.content || '';
    let cancelTarget=null;
    let cancelBusy=false;
    const money=new Intl.NumberFormat('pt-BR',{style:'currency',currency:'BRL'});
    const today=from.value;
    let currentPage=1;
    let pageCount=1;
    let controller=null;
    let sequence=0;
    let searchTimer=null;

    const asDate=(iso)=>new Date(iso+'T12:00:00');
    const isoDate=date=>[
      date.getFullYear(),String(date.getMonth()+1).padStart(2,'0'),
      String(date.getDate()).padStart(2,'0')
    ].join('-');
    const dateOffset=days=>{
      const date=asDate(today);
      date.setDate(date.getDate()+days);
      return isoDate(date);
    };
    const showMessage=(text,kind='normal')=>{
      rows.replaceChildren();
      const tr=document.createElement('tr');
      const td=document.createElement('td');
      td.colSpan=8;
      td.className='pdv-sales-message'+(kind==='error'?' is-error':'');
      td.textContent=text;
      tr.appendChild(td);
      rows.appendChild(tr);
    };
    const setPager=(pagination)=>{
      const found=pagination||{page:1,pages:1,total:0,from:null,to:null};
      currentPage=Number(found.page)||1;
      pageCount=Math.max(1,Number(found.pages)||1);
      count.textContent=Number(found.total)
        ? String(found.from)+'–'+String(found.to)+' de '+String(found.total)+' vendas'
        : 'Nenhuma venda no período';
      pageLabel.textContent=currentPage+' / '+pageCount;
      previous.disabled=currentPage<=1;
      next.disabled=currentPage>=pageCount;
    };
    const createCell=(row,value,className)=>{
      const td=document.createElement('td');
      if(className)td.className=className;
      td.textContent=String(value??'');
      row.appendChild(td);
      return td;
    };
    let previewController=null;
    let previewSequence=0;
    let returningFromReceipt=false;
    let suppressSalesClose=false;
    const showPreviewError=(container,message)=>{
      container.querySelector('.pdv-sales-receipt-error')?.remove();
      const messageElement=document.createElement('span');
      messageElement.className='pdv-sales-receipt-error';
      messageElement.setAttribute('role','alert');
      messageElement.textContent=message;
      container.appendChild(messageElement);
    };
    const openReceipt=async(button,url)=>{
      if(!url || button.disabled)return;
      if(document.getElementById('pdvHistoryReceiptModal')?.open)return;
      previewController?.abort();
      previewController=new AbortController();
      const request=++previewSequence;
      const oldText=button.textContent;
      button.disabled=true;
      button.textContent='Abrindo...';
      button.parentElement?.querySelector('.pdv-sales-receipt-error')?.remove();
      try{
        const response=await fetch(url,{
          credentials:'same-origin',cache:'no-store',
          headers:{Accept:'text/html','X-Requested-With':'XMLHttpRequest'},
          signal:previewController.signal
        });
        if(!response.ok)throw new Error([401,403].includes(response.status)
          ? 'Você não tem permissão para consultar este comprovante.'
          : 'Não foi possível abrir o comprovante. Tente novamente.');
        const html=await response.text();
        if(request!==previewSequence || !modal.open || !button.isConnected)return;
        const template=document.createElement('template');
        template.innerHTML=html.trim();
        const dialog=template.content.querySelector('dialog#pdvHistoryReceiptModal');
        if(!dialog || !window.LumeronPdvReceipt?.openHistory)
          throw new Error('Não foi possível exibir o comprovante.');
        document.getElementById('pdvHistoryReceiptModal')?.remove();
        // Evita dois <dialog> modais e dois backdrops grandes na GPU.
        // A listagem e os filtros permanecem no DOM e serão restaurados ao fechar.
        returningFromReceipt=true;
        suppressSalesClose=true;
        modal.close();
        document.body.appendChild(dialog);
        const restoreSales=()=>{
          returningFromReceipt=false;
          if(!modal.open)modal.showModal();
        };
        try{
          window.LumeronPdvReceipt.openHistory(dialog,button,restoreSales);
        }catch(error){
          dialog.remove();
          restoreSales();
          throw error;
        }
      }catch(error){
        if(error.name!=='AbortError' && request===previewSequence && button.isConnected)
          showPreviewError(button.parentElement,window.LumeronUserMessage?.fromError(error,'Não foi possível abrir o comprovante. Tente novamente.')||'Não foi possível abrir o comprovante. Tente novamente.');
      }finally{
        if(request===previewSequence && button.isConnected){
          button.disabled=false;
          button.textContent=oldText;
        }
      }
    };
    const addPreviewAction=(container,label,url)=>{
      if(!url)return;
      const button=document.createElement('button');
      button.type='button';
      button.textContent=label;
      button.setAttribute('aria-label','Ver comprovante desta venda');
      button.addEventListener('click',()=>openReceipt(button,url));
      container.appendChild(button);
    };
    const openCancelSheet=(button,sale)=>{
      if(cancelBusy || !sale.cancel_url)return;
      cancelTarget={url:sale.cancel_url,button};
      cancelNumber.textContent='#'+sale.number;
      cancelSummary.textContent='Venda #'+sale.number+' · '+money.format(Number(sale.total)||0);
      cancelReason.value='';
      if(cancelCredential)cancelCredential.value='';
      cancelError.hidden=true;
      cancelError.textContent='';
      cancelSheet.hidden=false;
      requestAnimationFrame(()=>cancelReason.focus());
    };
    const closeCancelSheet=()=>{
      if(cancelBusy)return;
      cancelSheet.hidden=true;
      if(cancelCredential)cancelCredential.value='';
      cancelError.hidden=true;
      if(cancelTarget?.button?.isConnected)cancelTarget.button.focus();
      cancelTarget=null;
    };
    const addCancelAction=(container,sale)=>{
      if(!sale.can_cancel || !sale.cancel_url)return;
      const button=document.createElement('button');
      button.type='button';
      button.className='pdv-sales-cancel-action';
      button.textContent='Cancelar';
      button.setAttribute('aria-label','Cancelar venda #'+sale.number);
      button.addEventListener('click',()=>openCancelSheet(button,sale));
      container.appendChild(button);
    };
    const addLink=(cell,label,url)=>{
      if(!url)return;
      const anchor=document.createElement('a');
      anchor.href=url;
      anchor.target='_blank';
      anchor.rel='noopener noreferrer';
      anchor.setAttribute('data-no-loading','');
      anchor.textContent=label;
      anchor.title=label+' (abre em outra aba)';
      cell.appendChild(anchor);
    };
    const render=items=>{
      rows.replaceChildren();
      if(!items.length){showMessage('Nenhuma venda encontrada para os filtros escolhidos.');return;}
      for(const sale of items){
        const tr=document.createElement('tr');
        createCell(tr,'#'+sale.number,'pdv-sales-id');
        createCell(tr,sale.date,'pdv-sales-date');
        createCell(tr,sale.customer,'pdv-sales-customer');
        createCell(tr,sale.operator,'pdv-sales-operator');
        createCell(tr,money.format(Number(sale.total)||0),'pdv-sales-amount');
        const statusCell=createCell(tr,'');
        const statusTag=document.createElement('span');
        statusTag.className='pdv-sales-status '+(sale.status==='completed'?'is-completed':sale.status==='cancelled'?'is-cancelled':'is-pending');
        statusTag.textContent=sale.status_label;
        statusCell.appendChild(statusTag);
        const fiscal=createCell(tr,sale.fiscal_status,'pdv-sales-fiscal');
        fiscal.dataset.status=sale.fiscal_code||'none';
        const actions=createCell(tr,'','pdv-sales-actions-col');
        const links=document.createElement('div');
        links.className='pdv-sales-row-actions';
        addPreviewAction(links,'Comprovante',sale.receipt_preview_url);
        addCancelAction(links,sale);
        addLink(links,'DANFE',sale.danfe_url);
        actions.appendChild(links);
        rows.appendChild(tr);
      }
    };

    const load=async(page=1)=>{
      if(!modal.open)return;
      if(!from.value || !to.value){
        showMessage('Informe as duas datas para consultar as vendas.','error');
        return;
      }
      if(from.value>to.value){
        showMessage('A data inicial não pode ser posterior à final.','error');
        return;
      }
      controller?.abort();
      controller=new AbortController();
      const request=++sequence;
      modal.setAttribute('aria-busy','true');
      showMessage('Consultando vendas...');
      const query=new URLSearchParams({
        from:from.value,to:to.value,status:status.value,
        operator:operator.value,q:search.value.trim(),page:String(page)
      });
      try{
        const response=await fetch(modal.dataset.salesUrl+'?'+query.toString(),{
          method:'GET',credentials:'same-origin',cache:'no-store',
          headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'},
          signal:controller.signal
        });
        if(!response.ok){
          let message='Não foi possível consultar as vendas. Tente novamente.';
          if(response.status===422){
            const error=await response.json();
            message=Object.values(error.errors||{}).flat()[0]||message;
          }else if(response.status===401||response.status===403){
            message='Sua sessão expirou ou você não tem permissão para consultar as vendas.';
          }
          throw new Error(message);
        }
        const data=await response.json();
        if(request!==sequence||!modal.open)return;
        render(Array.isArray(data.items)?data.items:[]);
        setPager(data.pagination);
      }catch(error){
        if(error.name==='AbortError'||request!==sequence)return;
        showMessage(window.LumeronUserMessage?.fromError(error,'Não foi possível consultar as vendas. Tente novamente.')||'Não foi possível consultar as vendas. Tente novamente.','error');
        setPager(null);
      }finally{
        if(request===sequence)modal.removeAttribute('aria-busy');
      }
    };

    const applyPreset=(value)=>{
      to.value=today;
      if(value==='today')from.value=today;
      else if(value==='yesterday')from.value=to.value=dateOffset(-1);
      else if(value==='seven')from.value=dateOffset(-6);
      else if(value==='month')from.value=today.slice(0,7)+'-01';
    };
    const reset=()=>{
      actionStatus.hidden=true;
      actionStatus.textContent='';
      search.value='';
      period.value='today';
      status.value='all';
      operator.value='all';
      applyPreset('today');
      setPager(null);
    };
    const open=()=>{
      if(modal.open)return;
      reset();
      modal.showModal();
      load(1);
      requestAnimationFrame(()=>search.focus());
    };

    document.getElementById('pdvSalesCancelClose')?.addEventListener('click',closeCancelSheet);
    document.getElementById('pdvSalesCancelBack')?.addEventListener('click',closeCancelSheet);
    modal.addEventListener('cancel',event=>{
      if(cancelSheet && !cancelSheet.hidden){
        event.preventDefault();
        closeCancelSheet();
      }
    });
    cancelForm?.addEventListener('submit',async event=>{
      event.preventDefault();
      if(cancelBusy || !cancelTarget)return;
      const reason=cancelReason.value.trim();
      if(reason.length<5){
        cancelError.textContent='Informe um motivo com pelo menos 5 caracteres.';
        cancelError.hidden=false;
        cancelReason.focus();
        return;
      }
      if(!cancelAuthorizer?.value || !cancelCredential?.value.trim()){
        cancelError.textContent='Selecione um supervisor e informe a senha ou PIN.';
        cancelError.hidden=false;
        (cancelAuthorizer?.value?cancelCredential:cancelAuthorizer)?.focus();
        return;
      }
      cancelBusy=true;
      cancelConfirm.disabled=true;
      cancelConfirm.textContent='Cancelando...';
      cancelError.hidden=true;
      try{
        const response=await fetch(cancelTarget.url,{
          method:'POST',credentials:'same-origin',cache:'no-store',
          headers:{
            Accept:'application/json','Content-Type':'application/json',
            'X-Requested-With':'XMLHttpRequest','X-CSRF-TOKEN':csrf
          },
          body:JSON.stringify({reason,authorizer_id:Number(cancelAuthorizer.value),credential:cancelCredential.value})
        });
        const result=await response.json().catch(()=>({}));
        if(!response.ok){
          const first=Object.values(result.errors||{}).flat()[0];
          throw new Error(first||result.message||'Não foi possível cancelar a venda.');
        }
        cancelSheet.hidden=true;
        cancelTarget=null;
        cancelCredential.value='';
        actionStatus.textContent=result.message||'Venda cancelada.';
        actionStatus.hidden=false;
        load(currentPage);
      }catch(error){
        cancelError.textContent=window.LumeronUserMessage?.fromError(error,'Não foi possível cancelar a venda. Tente novamente.')||'Não foi possível cancelar a venda. Tente novamente.';
        cancelError.hidden=false;
      }finally{
        cancelBusy=false;
        cancelConfirm.disabled=false;
        cancelConfirm.textContent='Confirmar cancelamento';
      }
    });
    trigger.addEventListener('click',event=>{event.preventDefault();open();});
    form.addEventListener('submit',event=>{event.preventDefault();load(1);});
    period.addEventListener('change',()=>{
      if(period.value!=='custom')applyPreset(period.value);
      load(1);
    });
    for(const input of [from,to]){
      input.addEventListener('change',()=>{
        period.value='custom';
        load(1);
      });
    }
    for(const select of [status,operator]){
      select.addEventListener('change',()=>load(1));
    }
    search.addEventListener('input',()=>{
      clearTimeout(searchTimer);
      searchTimer=setTimeout(()=>load(1),300);
    });
    todayButton.addEventListener('click',()=>{reset();load(1);});
    previous.addEventListener('click',()=>{if(currentPage>1)load(currentPage-1);});
    next.addEventListener('click',()=>{if(currentPage<pageCount)load(currentPage+1);});
    modal.addEventListener('close',()=>{
      if(!cancelBusy){
        cancelSheet.hidden=true;
        cancelTarget=null;
      }
      if(suppressSalesClose){
        suppressSalesClose=false;
        return;
      }
      if(returningFromReceipt)return;
      previewSequence++;
      previewController?.abort();
      sequence++;
      controller?.abort();
      clearTimeout(searchTimer);
      modal.removeAttribute('aria-busy');
      document.getElementById('pdvSearch')?.focus();
    });
  });
})();
