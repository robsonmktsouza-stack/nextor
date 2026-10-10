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
        addLink(links,'Comprovante',sale.receipt_url);
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
        showMessage(error.message||'Falha na consulta.','error');
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
      sequence++;
      controller?.abort();
      clearTimeout(searchTimer);
      modal.removeAttribute('aria-busy');
      document.getElementById('pdvSearch')?.focus();
    });
  });
})();
