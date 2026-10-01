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
    const customerModal=document.getElementById('pdvCustomerModal');
    const customerSearch=document.getElementById('pdvCustomerSearch');
    const customerList=document.getElementById('pdvCustomerList');

    const payment=document.getElementById('pdvPaymentMethod');
    const paymentModal=document.getElementById('pdvPaymentModal');
    const paymentOptions=document.getElementById('pdvPaymentOptions');

    const discountModal=document.getElementById('pdvDiscountModal');
    const discountModalInput=document.getElementById('pdvDiscountModalInput');
    const discountItemName=document.getElementById('pdvDiscountItemName');
    const discountGross=document.getElementById('pdvDiscountGross');
    const discountNet=document.getElementById('pdvDiscountNet');
    const discountApply=document.getElementById('pdvDiscountApply');
    let discountModalKey='';

    const cashInput=document.getElementById('pdvCashReceived');
    const cashModal=document.getElementById('pdvCashModal');
    const cashModalInput=document.getElementById('pdvCashModalInput');
    const cashModalTotal=document.getElementById('pdvCashModalTotal');
    const cashModalChange=document.getElementById('pdvCashModalChange');
    const cashExact=document.getElementById('pdvCashExact');
    const cashApply=document.getElementById('pdvCashApply');

    const notesInput=document.getElementById('pdvNotes');
    const notesModal=document.getElementById('pdvNotesModal');
    const notesModalInput=document.getElementById('pdvNotesModalInput');
    const notesApply=document.getElementById('pdvNotesApply');

    const actionSearch=document.getElementById('pdvActionSearch');
    const actionQuantity=document.getElementById('pdvActionQuantity');
    const actionCustomer=document.getElementById('pdvActionCustomer');
    const actionCustomerValue=document.getElementById('pdvActionCustomerValue');
    const actionDiscount=document.getElementById('pdvActionDiscount');
    const actionPayment=document.getElementById('pdvActionPayment');
    const actionPaymentValue=document.getElementById('pdvActionPaymentValue');
    const actionCash=document.getElementById('pdvActionCash');
    const actionCashValue=document.getElementById('pdvActionCashValue');
    const actionChangeValue=document.getElementById('pdvActionChangeValue');
    const actionRemove=document.getElementById('pdvActionRemove');
    const actionFinish=document.getElementById('pdvActionFinish');
    const actionNotes=document.getElementById('pdvActionNotes');
    const actionNotesValue=document.getElementById('pdvActionNotesValue');
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
    let resultIndex=-1;
    let searchTimer=null;
    let searchController=null;
    let finishFlowPending=false;
    let bypassFinalizeValidation=false;

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

      app.dataset.activeCartKey=key;
      renderCart();
      search.value='';
      caption.textContent='Item adicionado. Busque o próximo produto ou serviço.';
      search.focus();
    };

    const removeItem=key=>{
      state.delete(key);
      const keys=[...state.keys()];
      app.dataset.activeCartKey=keys.at(-1)||'';
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

    const paymentName=value=>({
      cash:'Dinheiro',
      pix:'PIX',
      debit_card:'Cartão de débito',
      credit_card:'Cartão de crédito',
      bank_slip:'Boleto',
      bank_transfer:'Transferência',
      other:'Outro',
    }[value]||'Não selecionado');

    const updatePaymentState=()=>{
      const t=totals();
      const cash=payment.value==='cash';
      const received=cash?moneyInputValue(cashInput):0;
      const change=cash?Math.max(0,received-t.total):0;

      if(actionPaymentValue) actionPaymentValue.textContent=paymentName(payment.value);
      if(actionCashValue) actionCashValue.textContent=cash?money.format(received):'Não se aplica';
      if(actionChangeValue) actionChangeValue.textContent=money.format(change);

      if(actionPayment){
        const label=payment.value
          ? 'Forma de pagamento: '+paymentName(payment.value)
          : 'Forma de pagamento pendente';
        actionPayment.dataset.tooltip=label;
        actionPayment.setAttribute('aria-label',label);
      }

      if(actionCash){
        const label=cash
          ? 'Recebido '+money.format(received)+' · Troco '+money.format(change)
          : 'Valor recebido e troco';
        actionCash.dataset.tooltip=label;
        actionCash.setAttribute('aria-label',label);
      }

      updateFinishState();
    };

    const updateFinishState=()=>{
      const hasItems=state.size>0;
      finish.disabled=!hasItems;
      if(actionFinish) actionFinish.disabled=!hasItems;

      const hasPayment=!!payment.value;
      actionPayment?.classList.toggle('is-set',hasPayment);

      const cashReady=payment.value==='cash' &&
        moneyInputValue(cashInput)+0.0001>=totals().total;
      actionCash?.classList.toggle('is-set',cashReady);
    };

    const createCartRow=item=>{
      const key=itemKey(item);
      const row=document.createElement('div');
      row.className='pdv-cart-row';
      row.dataset.key=key;
      if(app.dataset.activeCartKey===key) row.classList.add('keyboard-active');

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
      title.tabIndex=0;
      title.addEventListener('click',()=>{
        app.dataset.activeCartKey=key;
        renderCart();
      });

      const qtyWrap=document.createElement('div');
      qtyWrap.className='pdv-cart-qty-cell';

      const qtyBox=document.createElement('div');
      qtyBox.className='pdv-qty';
      const minus=document.createElement('button');
      minus.type='button';
      minus.textContent='−';
      minus.setAttribute('aria-label','Diminuir quantidade');
      minus.setAttribute('data-tooltip','Diminuir quantidade');

      const qty=document.createElement('input');
      qty.type='text';
      qty.inputMode='decimal';
      qty.value=number.format(item.quantity);
      qty.setAttribute('aria-label','Quantidade');
      qty.setAttribute('data-tooltip','Quantidade');

      const plus=document.createElement('button');
      plus.type='button';
      plus.textContent='+';
      plus.setAttribute('aria-label','Aumentar quantidade');
      plus.setAttribute('data-tooltip','Aumentar quantidade');

      qtyBox.append(minus,qty,plus);
      qtyWrap.appendChild(qtyBox);

      minus.addEventListener('click',()=>{
        const next=item.quantity-1;
        if(next<=0){removeItem(key);return;}
        item.quantity=next;
        if(item.discount>item.price*item.quantity) item.discount=item.price*item.quantity;
        app.dataset.activeCartKey=key;
        renderCart();
      });

      plus.addEventListener('click',()=>{
        const next=item.quantity+1;
        if(!canIncrease(item,next)) return;
        item.quantity=next;
        app.dataset.activeCartKey=key;
        renderCart();
      });

      qty.addEventListener('focus',()=>{
        app.dataset.activeCartKey=key;
        row.classList.add('keyboard-active');
      });
      qty.addEventListener('change',()=>setQuantity(key,qty.value));
      qty.addEventListener('keydown',event=>{
        if(event.key==='Enter'){
          event.preventDefault();
          setQuantity(key,qty.value);
          search.focus();
        }
      });

      const discountWrap=document.createElement('button');
      discountWrap.type='button';
      discountWrap.className='pdv-cart-discount-button';
      if(item.discount>0) discountWrap.classList.add('has-discount');
      discountWrap.setAttribute('aria-label','Alterar desconto');
      discountWrap.setAttribute('data-tooltip','Alterar desconto (F5)');
      discountWrap.innerHTML=
        '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 13 13 20 4 11V4h7l9 9Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><circle cx="8.5" cy="8.5" r="1.2" fill="none" stroke="currentColor" stroke-width="1.8"/></svg>'+
        '<span>'+money.format(item.discount)+'</span>';

      discountWrap.addEventListener('click',()=>{
        app.dataset.activeCartKey=key;
        openDiscountModal(key);
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

      row.append(title,qtyWrap,discountWrap,line,remove);
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

    const updateResultSelection=()=>{
      const cards=[...results.querySelectorAll('.pdv-result-card')];
      cards.forEach((card,index)=>card.classList.toggle('keyboard-selected',index===resultIndex));
      const current=cards[resultIndex];
      current?.scrollIntoView({block:'nearest'});
    };

    const moveResultSelection=direction=>{
      if(!lastResults.length) return;
      if(resultIndex<0) resultIndex=0;
      else resultIndex=Math.max(0,Math.min(lastResults.length-1,resultIndex+direction));
      updateResultSelection();
    };

    const renderResults=items=>{
      results.innerHTML='';
      lastResults=items;
      resultIndex=items.length?0:-1;

      if(!items.length){
        const empty=document.createElement('div');
        empty.className='pdv-empty-state';
        empty.innerHTML='<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="m20 20-4-4" fill="none" stroke="currentColor" stroke-width="1.8"/></svg><strong>Nenhum item encontrado</strong><span>Confira o código ou tente outro nome.</span>';
        results.appendChild(empty);
        caption.textContent='Nenhum resultado para esta busca.';
        return;
      }

      items.forEach((item,index)=>{
        const card=resultCard(item);
        card.addEventListener('mouseenter',()=>{
          resultIndex=index;
          updateResultSelection();
        });
        results.appendChild(card);
      });
      updateResultSelection();
      caption.textContent=items.length+' '+(items.length===1?'resultado':'resultados')+'. Use ↑/↓ e Enter para adicionar.';
    };

    const performSearch=async(addOnExact=false)=>{
      const term=search.value.trim();
      if(!term){
        lastResults=[];
        resultIndex=-1;
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
      lastResults=[];
      resultIndex=-1;
      clearTimeout(searchTimer);
      searchTimer=setTimeout(()=>performSearch(false),180);
    });

    search.addEventListener('keydown',event=>{
      if(event.key==='ArrowDown'){
        event.preventDefault();
        moveResultSelection(1);
        return;
      }
      if(event.key==='ArrowUp'){
        event.preventDefault();
        moveResultSelection(-1);
        return;
      }
      if(event.key==='Enter'){
        event.preventDefault();
        clearTimeout(searchTimer);
        if(lastResults.length && resultIndex>=0){
          addItem(lastResults[resultIndex]);
        }else{
          performSearch(true);
        }
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
    const normalizeText=value=>String(value||'')
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g,'')
      .toLocaleLowerCase('pt-BR');

    let customerIndex=0;
    let paymentIndex=0;

    const visibleCustomerOptions=()=>[...customerList.querySelectorAll('.pdv-client-option:not([hidden])')];

    const updateCustomerHighlight=()=>{
      const options=visibleCustomerOptions();
      if(!options.length) return;
      customerIndex=Math.max(0,Math.min(customerIndex,options.length-1));
      options.forEach((option,index)=>option.classList.toggle('keyboard-selected',index===customerIndex));
      options[customerIndex]?.scrollIntoView({block:'nearest'});
    };

    const filterCustomers=()=>{
      const term=normalizeText(customerSearch.value);
      [...customerList.querySelectorAll('.pdv-client-option')].forEach(option=>{
        option.hidden=term && !normalizeText(option.dataset.customerSearch).includes(term);
      });
      const visible=visibleCustomerOptions();
      const selectedIndex=visible.findIndex(option=>String(option.dataset.customerId||'')===String(customer.value||''));
      customerIndex=selectedIndex>=0?selectedIndex:0;
      updateCustomerHighlight();
    };

    const selectCustomerOption=option=>{
      if(!option) return;
      customer.value=option.dataset.customerId||'';
      if(actionCustomerValue) actionCustomerValue.textContent=option.querySelector('strong')?.textContent||'Consumidor não identificado';
      customer.dispatchEvent(new Event('change',{bubbles:true}));
      customerModal.close();
    };

    const openCustomerModal=()=>{
      if(paymentModal?.open) paymentModal.close();
      if(cashModal?.open) cashModal.close();
      if(notesModal?.open) notesModal.close();
      if(!customerModal.open) customerModal.showModal();
      customerSearch.value='';
      filterCustomers();
      requestAnimationFrame(()=>{
        customerSearch.focus();
        customerSearch.select();
      });
    };

    const paymentButtons=()=>[...paymentOptions.querySelectorAll('[data-payment-value]')];

    const updatePaymentHighlight=()=>{
      const options=paymentButtons();
      if(!options.length) return;
      paymentIndex=Math.max(0,Math.min(paymentIndex,options.length-1));
      options.forEach((option,index)=>option.classList.toggle('keyboard-selected',index===paymentIndex));
      options[paymentIndex]?.scrollIntoView({block:'nearest'});
    };

    const selectPaymentOption=option=>{
      if(!option) return;
      payment.value=option.dataset.paymentValue||'';
      if(actionPaymentValue) actionPaymentValue.textContent=option.dataset.paymentLabel||option.textContent.trim();
      payment.dispatchEvent(new Event('change',{bubbles:true}));
      paymentModal.close();

      if(finishFlowPending){
        if(payment.value==='cash'){
          setTimeout(()=>openCashModal(),0);
        }else{
          setTimeout(()=>requestFinalize(),0);
        }
      }
    };

    const openPaymentModal=()=>{
      if(customerModal?.open) customerModal.close();
      if(cashModal?.open) cashModal.close();
      if(notesModal?.open) notesModal.close();
      if(!paymentModal.open) paymentModal.showModal();
      const options=paymentButtons();
      const selectedIndex=options.findIndex(option=>option.dataset.paymentValue===payment.value);
      paymentIndex=selectedIndex>=0?selectedIndex:0;
      updatePaymentHighlight();
      requestAnimationFrame(()=>options[paymentIndex]?.focus());
    };

    const updateDiscountPreview=()=>{
      const item=state.get(discountModalKey);
      if(!item) return;
      const gross=item.price*item.quantity;
      const discount=Math.min(gross,parseDecimal(discountModalInput?.value));
      if(discountGross) discountGross.textContent=money.format(gross);
      if(discountNet) discountNet.textContent=money.format(Math.max(0,gross-discount));
    };

    const openDiscountModal=(key=activeCartKey())=>{
      const item=state.get(key);
      if(!item){
        notify('Adicione um item ao carrinho primeiro.','warning');
        search.focus();
        return;
      }

      [customerModal,paymentModal,discountModal,cashModal,notesModal].forEach(modal=>{
        if(modal?.open) modal.close();
      });

      discountModalKey=key;
      app.dataset.activeCartKey=key;
      if(discountItemName) discountItemName.textContent=item.name;
      if(discountModalInput){
        discountModalInput.value=item.discount.toLocaleString('pt-BR',{
          minimumFractionDigits:2,
          maximumFractionDigits:2
        });
      }
      updateDiscountPreview();

      if(!discountModal.open) discountModal.showModal();
      requestAnimationFrame(()=>{
        discountModalInput?.focus();
        discountModalInput?.select?.();
      });
    };

    const applyDiscountModal=()=>{
      if(!discountModalKey) return;
      setDiscount(discountModalKey,discountModalInput?.value||0);
      discountModal.close();
    };

    discountModalInput?.addEventListener('input',updateDiscountPreview);
    discountModalInput?.addEventListener('keydown',event=>{
      if(event.key==='Enter'){
        event.preventDefault();
        applyDiscountModal();
      }
    });
    discountApply?.addEventListener('click',applyDiscountModal);

    const setCashReceived=value=>{
      const amount=parseDecimal(value);
      cashInput.value=amount.toFixed(2);
      updatePaymentState();
      return amount;
    };

    const updateCashModalPreview=()=>{
      const total=totals().total;
      const received=parseDecimal(cashModalInput?.value);
      if(cashModalTotal) cashModalTotal.textContent=money.format(total);
      if(cashModalChange) cashModalChange.textContent=money.format(Math.max(0,received-total));
    };

    const openCashModal=()=>{
      if(payment.value!=='cash'){
        notify('Selecione Dinheiro como forma de pagamento para informar valor recebido.','info');
        finishFlowPending=true;
        openPaymentModal();
        return;
      }
      [customerModal,paymentModal,discountModal,notesModal].forEach(modal=>{if(modal?.open) modal.close();});
      const current=moneyInputValue(cashInput);
      const suggested=current>0?current:totals().total;
      cashModalInput.value=suggested.toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2});
      updateCashModalPreview();
      if(!cashModal.open) cashModal.showModal();
      requestAnimationFrame(()=>{
        cashModalInput.focus();
        cashModalInput.select();
      });
    };

    const applyCashModal=()=>{
      const total=totals().total;
      const received=setCashReceived(cashModalInput.value);

      if(received+0.0001<total){
        notify('O valor recebido é menor que o total da venda.','warning');
        updateCashModalPreview();
        requestAnimationFrame(()=>{
          cashModalInput?.focus();
          cashModalInput?.select?.();
        });
        return;
      }

      cashModal.close();

      if(finishFlowPending){
        setTimeout(()=>requestFinalize(),0);
      }
    };

    cashExact?.addEventListener('click',()=>{
      const total=totals().total;
      cashModalInput.value=total.toLocaleString('pt-BR',{
        minimumFractionDigits:2,
        maximumFractionDigits:2
      });
      updateCashModalPreview();
      requestAnimationFrame(()=>{
        cashModalInput?.focus();
        cashModalInput?.select?.();
      });
    });

    cashModalInput?.addEventListener('input',updateCashModalPreview);
    cashModalInput?.addEventListener('keydown',event=>{
      if(event.key==='Enter'){
        event.preventDefault();
        applyCashModal();
      }
    });
    cashApply?.addEventListener('click',applyCashModal);

    const openNotesModal=()=>{
      [customerModal,paymentModal,discountModal,cashModal].forEach(modal=>{if(modal?.open) modal.close();});
      notesModalInput.value=notesInput.value||'';
      if(!notesModal.open) notesModal.showModal();
      requestAnimationFrame(()=>{
        notesModalInput.focus();
        notesModalInput.setSelectionRange(notesModalInput.value.length,notesModalInput.value.length);
      });
    };

    const applyNotesModal=()=>{
      notesInput.value=notesModalInput.value.trim();
      if(actionNotesValue){
        actionNotesValue.textContent=notesInput.value
          ? (notesInput.value.length>38?notesInput.value.slice(0,38)+'…':notesInput.value)
          : 'Sem observação';
      }
      notesModal.close();
    };

    notesApply?.addEventListener('click',applyNotesModal);
    notesModalInput?.addEventListener('keydown',event=>{
      if(event.ctrlKey && event.key==='Enter'){
        event.preventDefault();
        applyNotesModal();
      }
    });

    const requestFinalize=()=>{
      const t=totals();

      if(!state.size){
        finishFlowPending=false;
        notify('Adicione pelo menos um item ao carrinho.','warning');
        search.focus();
        return;
      }

      if(!payment.value){
        finishFlowPending=true;
        notify('Selecione a forma de pagamento para continuar.','info');
        openPaymentModal();
        return;
      }

      if(payment.value==='cash'){
        const received=moneyInputValue(cashInput);
        if(received+0.0001<t.total){
          finishFlowPending=true;
          openCashModal();
          return;
        }
      }

      finishFlowPending=false;
      syncPayload();
      bypassFinalizeValidation=true;
      form.requestSubmit();
    };

    actionSearch?.addEventListener('click',()=>{search.focus();search.select();});
    actionQuantity?.addEventListener('click',()=>focusCartField('.pdv-qty input'));
    actionCustomer?.addEventListener('click',openCustomerModal);
    actionDiscount?.addEventListener('click',()=>openDiscountModal());
    actionPayment?.addEventListener('click',openPaymentModal);
    actionCash?.addEventListener('click',openCashModal);
    actionRemove?.addEventListener('click',removeActiveCartItem);
    actionFinish?.addEventListener('click',requestFinalize);
    actionNotes?.addEventListener('click',openNotesModal);

    customerSearch?.addEventListener('input',filterCustomers);
    customerSearch?.addEventListener('keydown',event=>{
      const options=visibleCustomerOptions();
      if(event.key==='ArrowDown'){
        event.preventDefault();
        customerIndex=Math.min(options.length-1,customerIndex+1);
        updateCustomerHighlight();
      }else if(event.key==='ArrowUp'){
        event.preventDefault();
        customerIndex=Math.max(0,customerIndex-1);
        updateCustomerHighlight();
      }else if(event.key==='Enter'){
        event.preventDefault();
        selectCustomerOption(options[customerIndex]);
      }
    });

    customerList?.addEventListener('click',event=>{
      const option=event.target.closest('.pdv-client-option');
      if(option) selectCustomerOption(option);
    });

    customerList?.addEventListener('mousemove',event=>{
      const option=event.target.closest('.pdv-client-option:not([hidden])');
      if(!option) return;
      const options=visibleCustomerOptions();
      const index=options.indexOf(option);
      if(index>=0){
        customerIndex=index;
        updateCustomerHighlight();
      }
    });

    paymentOptions?.addEventListener('click',event=>{
      const option=event.target.closest('[data-payment-value]');
      if(option) selectPaymentOption(option);
    });

    paymentModal?.addEventListener('keydown',event=>{
      const options=paymentButtons();
      if(/^[1-7]$/.test(event.key)){
        event.preventDefault();
        const option=options.find(button=>button.dataset.paymentKey===event.key);
        selectPaymentOption(option);
        return;
      }
      if(event.key==='ArrowDown'||event.key==='ArrowRight'){
        event.preventDefault();
        paymentIndex=Math.min(options.length-1,paymentIndex+1);
        updatePaymentHighlight();
      }else if(event.key==='ArrowUp'||event.key==='ArrowLeft'){
        event.preventDefault();
        paymentIndex=Math.max(0,paymentIndex-1);
        updatePaymentHighlight();
      }else if(event.key==='Enter'){
        event.preventDefault();
        selectPaymentOption(options[paymentIndex]);
      }
    });

    document.querySelectorAll('[data-pdv-modal-close]').forEach(button=>{
      button.addEventListener('click',()=>button.closest('dialog')?.close());
    });

    [customerModal,paymentModal,cashModal,notesModal].forEach(modal=>{
      modal?.addEventListener('close',()=>{
        setTimeout(()=>search.focus(),0);
      });
    });

    const activeCartKey=()=>{
      const explicit=app.dataset.activeCartKey;
      if(explicit && state.has(explicit)) return explicit;
      return [...state.keys()].at(-1)||'';
    };

    const focusCartField=selector=>{
      const key=activeCartKey();
      if(!key){
        notify('Adicione um item ao carrinho primeiro.','warning');
        search.focus();
        return;
      }
      app.dataset.activeCartKey=key;
      renderCart();
      requestAnimationFrame(()=>{
        const row=cart.querySelector('.pdv-cart-row[data-key="'+CSS.escape(key)+'"]');
        const input=row?.querySelector(selector);
        input?.focus();
        input?.select?.();
      });
    };

    const removeActiveCartItem=()=>{
      const key=activeCartKey();
      if(!key){
        notify('Não há item para remover.','warning');
        return;
      }
      removeItem(key);
      notify('Último item removido.','info');
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

      if(customerModal?.open || paymentModal?.open || discountModal?.open || cashModal?.open || notesModal?.open) return;

      if(event.key==='Escape'){
        event.preventDefault();
        search.focus();
        search.select();
        return;
      }

      if(event.key==='F2'){
        event.preventDefault();
        search.focus();
        search.select();
      }else if(event.key==='F3'){
        event.preventDefault();
        focusCartField('.pdv-qty input');
      }else if(event.key==='F4'){
        event.preventDefault();
        openCustomerModal();
      }else if(event.key==='F5'){
        event.preventDefault();
        openDiscountModal();
      }else if(event.key==='F6'){
        event.preventDefault();
        openPaymentModal();
      }else if(event.key==='F7'){
        event.preventDefault();
        openCashModal();
      }else if(event.key==='F8'){
        event.preventDefault();
        removeActiveCartItem();
      }else if(event.key==='F9'){
        event.preventDefault();
        requestFinalize();
      }else if(event.key==='F10'){
        event.preventDefault();
        openNotesModal();
      }
    });

    form.addEventListener('submit',event=>{
      if(bypassFinalizeValidation){
        bypassFinalizeValidation=false;
        syncPayload();
        return;
      }

      event.preventDefault();
      requestFinalize();
    });

    renderCart();
    updatePaymentState();
    setTimeout(()=>search.focus(),80);
  });
})();