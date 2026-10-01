(()=>{
  'use strict';

  document.addEventListener('DOMContentLoaded',()=>{
    const app=document.getElementById('pdvApp');
    if(!app) return;

    const form=document.getElementById('pdvForm');
    const search=document.getElementById('pdvSearch');
    const results=document.getElementById('pdvResults');
    const caption=document.getElementById('pdvResultsCaption');
    const cart=document.getElementById('pdvCart');
    const payload=document.getElementById('pdvItemsPayload');
    const customer=document.getElementById('pdvCustomer');
    const payment=document.getElementById('pdvPaymentMethod');
    const cashField=document.getElementById('pdvCashField');
    const cashInput=document.getElementById('pdvCashReceived');
    const changeBox=document.getElementById('pdvChangeBox');
    const changeOutput=document.getElementById('pdvChange');
    const finish=document.getElementById('pdvFinish');
    const fullscreenButton=document.getElementById('pdvFullscreen');
    const itemCount=document.getElementById('pdvItemCount');
    const subtotalOutput=document.getElementById('pdvSubtotal');
    const discountOutput=document.getElementById('pdvDiscountTotal');
    const totalOutput=document.getElementById('pdvTotal');

    const money=new Intl.NumberFormat('pt-BR',{style:'currency',currency:'BRL'});
    const number=new Intl.NumberFormat('pt-BR',{maximumFractionDigits:3});
    const state=new Map();
    let currentKind='all';
    let lastResults=[];
    let searchTimer=null;
    let searchController=null;

    const notify=(message,type='info')=>{
      if(window.NextorNotify) window.NextorNotify(message,{type,title:type==='error'?'PDV':'PDV'});
      else window.alert(message);
    };

    const parseDecimal=value=>{
      let text=String(value??'').trim().replace(/[^0-9,.-]/g,'').replace(/-/g,'');
      if(!text) return 0;
      if(text.includes(',')) text=text.replace(/\./g,'').replace(',','.');
      const parsed=Number(text);
      return Number.isFinite(parsed)?Math.max(0,parsed):0;
    };

    const moneyInputValue=input=>{
      if(!input) return 0;
      if(input.dataset.moneyValue!==undefined) return Number(input.dataset.moneyValue)||0;
      const hidden=input.nextElementSibling?.matches?.('[data-money-hidden="1"]') ? input.nextElementSibling : null;
      if(hidden) return Number(hidden.value)||0;
      return parseDecimal(input.value);
    };

    const itemKey=item=>item.type+':'+item.id;

    const lineTotal=item=>Math.max(0,(item.price*item.quantity)-item.discount);

    const totals=()=>{
      let subtotal=0,discount=0,total=0,units=0;
      state.forEach(item=>{
        subtotal+=item.price*item.quantity;
        discount+=item.discount;
        total+=lineTotal(item);
        units+=item.quantity;
      });
      return {subtotal,discount,total,units};
    };

    const canIncrease=(item,nextQuantity)=>{
      if(item.type!=='product' || !item.control_stock) return true;
      if(nextQuantity<=item.stock+0.000001) return true;
      notify('Estoque disponível: '+number.format(item.stock)+' '+(item.unit||'UN')+'.','warning');
      return false;
    };

    const addItem=item=>{
      const key=itemKey(item);
      const existing=state.get(key);

      if(existing){
        const next=existing.quantity+1;
        if(!canIncrease(existing,next)) return;
        existing.quantity=next;
      }else{
        if(item.type==='product' && item.control_stock && item.stock<1){
          notify('Este produto está sem estoque disponível.','warning');
          return;
        }
        state.set(key,{
          ...item,
          price:Number(item.price)||0,
          stock:Number(item.stock)||0,
          quantity:1,
          discount:0,
        });
      }

      renderCart();
      search.value='';
      caption.textContent='Item adicionado. Busque o próximo produto ou serviço.';
      search.focus();
    };

    const removeItem=key=>{
      state.delete(key);
      renderCart();
      search.focus();
    };

    const setQuantity=(key,value)=>{
      const item=state.get(key);
      if(!item) return;
      const qty=Math.max(0.001,parseDecimal(value)||0.001);
      if(!canIncrease(item,qty)){
        renderCart();
        return;
      }
      item.quantity=qty;
      const gross=item.price*qty;
      if(item.discount>gross) item.discount=gross;
      renderCart();
    };

    const setDiscount=(key,value)=>{
      const item=state.get(key);
      if(!item) return;
      const gross=item.price*item.quantity;
      item.discount=Math.min(gross,parseDecimal(value));
      renderCart();
    };

    const syncPayload=()=>{
      payload.innerHTML='';
      [...state.values()].forEach((item,index)=>{
        const values={
          item_type:item.type,
          product_id:item.type==='product'?item.id:'',
          service_id:item.type==='service'?item.id:'',
          quantity:item.quantity.toFixed(3),
          discount:item.discount.toFixed(2),
        };

        Object.entries(values).forEach(([name,value])=>{
          const input=document.createElement('input');
          input.type='hidden';
          input.name='items['+index+']['+name+']';
          input.value=String(value);
          payload.appendChild(input);
        });
      });
    };

    const renderSummary=()=>{
      const t=totals();
      subtotalOutput.textContent=money.format(t.subtotal);
      discountOutput.textContent=money.format(t.discount);
      totalOutput.textContent=money.format(t.total);

      const count=state.size;
      itemCount.textContent=count+' '+(count===1?'item':'itens');

      updatePaymentState();
    };

    const updatePaymentState=()=>{
      const t=totals();
      const cash=payment.value==='cash';
      cashField.hidden=!cash;
      changeBox.hidden=!cash;

      const received=cash?moneyInputValue(cashInput):t.total;
      const change=Math.max(0,received-t.total);
      changeOutput.textContent=money.format(change);

      updateFinishState();
    };

    const updateFinishState=()=>{
      const t=totals();
      const hasItems=state.size>0 && t.total>=0;
      const cashOk=payment.value!=='cash' || moneyInputValue(cashInput)+0.0001>=t.total;
      finish.disabled=!hasItems || !cashOk;
    };

    const createCartRow=item=>{
      const key=itemKey(item);
      const row=document.createElement('div');
      row.className='pdv-cart-row';
      row.dataset.key=key;

      const title=document.createElement('div');
      title.className='pdv-cart-item';

      const marker=document.createElement('span');
      marker.className='pdv-cart-marker '+(item.type==='service'?'service':'product');
      marker.textContent=item.type==='service'?'S':'P';

      const details=document.createElement('div');
      const strong=document.createElement('strong');
      strong.textContent=item.name;
      const small=document.createElement('small');
      small.textContent=(item.code||'Sem código')+' · '+money.format(item.price)+' / '+(item.unit||'UN');
      details.append(strong,small);
      title.append(marker,details);

      const qtyBox=document.createElement('div');
      qtyBox.className='pdv-qty';
      const minus=document.createElement('button');
      minus.type='button';
      minus.textContent='−';
      minus.setAttribute('aria-label','Diminuir quantidade');
      const qty=document.createElement('input');
      qty.type='text';
      qty.inputMode='decimal';
      qty.value=number.format(item.quantity);
      qty.setAttribute('aria-label','Quantidade');
      const plus=document.createElement('button');
      plus.type='button';
      plus.textContent='+';
      plus.setAttribute('aria-label','Aumentar quantidade');
      qtyBox.append(minus,qty,plus);

      minus.addEventListener('click',()=>{
        const next=item.quantity-1;
        if(next<=0){removeItem(key);return;}
        item.quantity=next;
        if(item.discount>item.price*item.quantity) item.discount=item.price*item.quantity;
        renderCart();
      });
      plus.addEventListener('click',()=>{
        const next=item.quantity+1;
        if(!canIncrease(item,next)) return;
        item.quantity=next;
        renderCart();
      });
      qty.addEventListener('change',()=>setQuantity(key,qty.value));
      qty.addEventListener('keydown',event=>{
        if(event.key==='Enter'){event.preventDefault();setQuantity(key,qty.value);search.focus();}
      });

      const discountWrap=document.createElement('label');
      discountWrap.className='pdv-cart-discount';
      const discountLabel=document.createElement('span');
      discountLabel.textContent='Desconto';
      const discount=document.createElement('input');
      discount.type='text';
      discount.inputMode='decimal';
      discount.value=item.discount.toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2});
      discount.setAttribute('aria-label','Desconto do item');
      discountWrap.append(discountLabel,discount);
      discount.addEventListener('change',()=>setDiscount(key,discount.value));
      discount.addEventListener('keydown',event=>{
        if(event.key==='Enter'){event.preventDefault();setDiscount(key,discount.value);search.focus();}
      });

      const line=document.createElement('div');
      line.className='pdv-cart-line-total';
      line.innerHTML='<span>Total</span><strong></strong>';
      line.querySelector('strong').textContent=money.format(lineTotal(item));

      const remove=document.createElement('button');
      remove.type='button';
      remove.className='pdv-cart-remove';
      remove.setAttribute('aria-label','Remover item');
      remove.setAttribute('data-tooltip','Remover item');
      remove.innerHTML='<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18M8 6V4h8v2M5 6l1 15h12l1-15M10 10v7M14 10v7" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
      remove.addEventListener('click',()=>removeItem(key));

      row.append(title,qtyBox,discountWrap,line,remove);
      return row;
    };

    const renderCart=()=>{
      cart.innerHTML='';

      if(!state.size){
        const empty=document.createElement('div');
        empty.className='pdv-cart-empty';
        empty.innerHTML='<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="20" r="1" fill="none" stroke="currentColor"/><circle cx="19" cy="20" r="1" fill="none" stroke="currentColor"/><path d="M2 3h2l2.4 12.1a2 2 0 0 0 2 1.6h9.9a2 2 0 0 0 2-1.6L22 7H5" fill="none" stroke="currentColor" stroke-width="1.8"/></svg><strong>Carrinho vazio</strong><span>Adicione um produto ou serviço.</span>';
        cart.appendChild(empty);
      }else{
        state.forEach(item=>cart.appendChild(createCartRow(item)));
      }

      renderSummary();
      syncPayload();
    };

    const resultCard=item=>{
      const button=document.createElement('button');
      button.type='button';
      button.className='pdv-result-card';
      button.dataset.key=itemKey(item);

      const thumb=document.createElement('span');
      thumb.className='pdv-result-thumb';
      if(item.image){
        const img=document.createElement('img');
        img.src=item.image;
        img.alt='';
        thumb.appendChild(img);
      }else{
        thumb.classList.add(item.type==='service'?'service':'product');
        thumb.textContent=item.type==='service'?'S':'P';
      }

      const info=document.createElement('span');
      info.className='pdv-result-info';
      const name=document.createElement('strong');
      name.textContent=item.name;
      const code=document.createElement('small');
      const parts=[item.code];
      if(item.ean) parts.push(item.ean);
      if(item.type==='product' && item.control_stock) parts.push('Estoque '+number.format(item.stock)+' '+(item.unit||'UN'));
      if(item.type==='service') parts.push('Serviço');
      code.textContent=parts.filter(Boolean).join(' · ');
      info.append(name,code);

      const price=document.createElement('strong');
      price.className='pdv-result-price';
      price.textContent=money.format(Number(item.price)||0);

      const add=document.createElement('span');
      add.className='pdv-result-add';
      add.textContent='+';

      button.append(thumb,info,price,add);
      button.addEventListener('click',()=>addItem(item));
      return button;
    };

    const renderResults=items=>{
      results.innerHTML='';
      lastResults=items;

      if(!items.length){
        const empty=document.createElement('div');
        empty.className='pdv-empty-state';
        empty.innerHTML='<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="m20 20-4-4" fill="none" stroke="currentColor" stroke-width="1.8"/></svg><strong>Nenhum item encontrado</strong><span>Confira o código ou tente outro nome.</span>';
        results.appendChild(empty);
        caption.textContent='Nenhum resultado para esta busca.';
        return;
      }

      items.forEach(item=>results.appendChild(resultCard(item)));
      caption.textContent=items.length+' '+(items.length===1?'resultado':'resultados')+'. Clique para adicionar ao carrinho.';
    };

    const performSearch=async(addOnExact=false)=>{
      const term=search.value.trim();
      if(!term){
        lastResults=[];
        results.innerHTML='<div class="pdv-empty-state"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="m20 20-4-4" fill="none" stroke="currentColor" stroke-width="1.8"/></svg><strong>Localize um item para começar</strong><span>Use nome, SKU, EAN/GTIN ou item de serviço.</span></div>';
        caption.textContent='Digite para localizar um produto ou serviço.';
        return;
      }

      searchController?.abort();
      searchController=new AbortController();

      try{
        caption.textContent='Buscando...';
        const url=new URL(app.dataset.searchUrl,window.location.origin);
        url.searchParams.set('q',term);
        url.searchParams.set('kind',currentKind);

        const fetcher=window.NextorFetch||window.fetch.bind(window);
        const response=await fetcher(url.toString(),{
          headers:{'Accept':'application/json'},
          signal:searchController.signal,
        });

        if(!response.ok) throw new Error('Falha ao buscar itens.');

        const data=await response.json();
        const items=Array.isArray(data.items)?data.items:[];
        renderResults(items);

        if(addOnExact){
          const normalized=term.toLocaleLowerCase('pt-BR');
          const exact=items.find(item=>
            String(item.code||'').toLocaleLowerCase('pt-BR')===normalized ||
            String(item.ean||'').toLocaleLowerCase('pt-BR')===normalized
          );
          if(exact) addItem(exact);
          else if(items.length===1) addItem(items[0]);
        }
      }catch(error){
        if(error.name==='AbortError') return;
        caption.textContent='Não foi possível buscar agora.';
        notify('Não foi possível pesquisar os itens do PDV.','error');
      }
    };

    search.addEventListener('input',()=>{
      clearTimeout(searchTimer);
      searchTimer=setTimeout(()=>performSearch(false),180);
    });

    search.addEventListener('keydown',event=>{
      if(event.key==='Enter'){
        event.preventDefault();
        clearTimeout(searchTimer);
        performSearch(true);
      }
    });

    document.querySelectorAll('[data-pdv-kind]').forEach(button=>{
      button.addEventListener('click',()=>{
        document.querySelectorAll('[data-pdv-kind]').forEach(x=>x.classList.remove('active'));
        button.classList.add('active');
        currentKind=button.dataset.pdvKind||'all';
        search.focus();
        if(search.value.trim()) performSearch(false);
      });
    });

    payment.addEventListener('change',updatePaymentState);
    cashInput?.addEventListener('input',()=>setTimeout(updatePaymentState,0));
    cashInput?.addEventListener('blur',updatePaymentState);

    const openCustomSelect=select=>{
      const wrapper=select?.nextElementSibling;
      wrapper?.querySelector('.ui-select-trigger')?.click();
    };

    fullscreenButton?.addEventListener('click',async()=>{
      try{
        if(!document.fullscreenElement){
          await document.documentElement.requestFullscreen?.();
        }else{
          await document.exitFullscreen?.();
        }
      }catch(_){
        notify('O navegador não permitiu entrar em tela cheia.','warning');
      }
    });

    document.addEventListener('fullscreenchange',()=>{
      if(!fullscreenButton) return;
      const label=fullscreenButton.querySelector('span');
      if(label) label.textContent=document.fullscreenElement?'Sair da tela cheia':'Tela cheia';
    });

    document.addEventListener('keydown',event=>{
      if(event.altKey||event.ctrlKey||event.metaKey) return;

      if(event.key==='F2'){
        event.preventDefault();
        search.focus();
        search.select();
      }else if(event.key==='F4'){
        event.preventDefault();
        openCustomSelect(customer);
      }else if(event.key==='F6'){
        event.preventDefault();
        openCustomSelect(payment);
      }else if(event.key==='F9'){
        event.preventDefault();
        if(!finish.disabled) form.requestSubmit();
      }
    });

    form.addEventListener('submit',event=>{
      const t=totals();
      if(!state.size){
        event.preventDefault();
        notify('Adicione pelo menos um item ao carrinho.','warning');
        search.focus();
        return;
      }

      if(payment.value==='cash' && moneyInputValue(cashInput)+0.0001<t.total){
        event.preventDefault();
        notify('O valor recebido é menor que o total da venda.','warning');
        cashInput.focus();
        return;
      }

      syncPayload();
    });

    renderCart();
    updatePaymentState();
    setTimeout(()=>search.focus(),80);
  });
})();