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
    const paymentPayload=document.getElementById('pdvPaymentsPayload');
    const splitToggle=document.getElementById('pdvSplitToggle');
    const splitPanel=document.getElementById('pdvSplitPanel');
    const splitRows=document.getElementById('pdvSplitRows');
    const splitApply=document.getElementById('pdvSplitApply');
    const splitTotal=document.getElementById('pdvSplitTotal');
    const splitPaid=document.getElementById('pdvSplitPaid');
    const splitRemaining=document.getElementById('pdvSplitRemaining');

    const consumerDocument=document.getElementById('pdvConsumerDocument');
    const consumerName=document.getElementById('pdvConsumerName');
    const consumerModal=document.getElementById('pdvConsumerDocumentModal');
    const consumerDocumentInput=document.getElementById('pdvConsumerDocumentInput');
    const consumerUseCustomer=document.getElementById('pdvConsumerUseCustomer');
    const consumerSkip=document.getElementById('pdvConsumerSkip');
    const consumerApply=document.getElementById('pdvConsumerApply');

    const cashMovementModal=document.getElementById('pdvCashMovementDialog');
    const cashMovementType=document.getElementById('pdvCashMovementType');
    const cashMovementTitle=document.getElementById('pdvCashMovementTitle');
    const cashMovementHelp=document.getElementById('pdvCashMovementHelp');
    const cashMovementAmount=document.getElementById('pdvCashMovementAmount');
    const cashMovementReason=document.getElementById('pdvCashMovementReason');
    const cashMovementSubmit=document.getElementById('pdvCashMovementSubmit');

    const operationsButton=document.getElementById('pdvOperations');
    const operationsModal=document.getElementById('pdvOperationsModal');
    const suspendModal=document.getElementById('pdvSuspendModal');
    const suspendLabel=document.getElementById('pdvSuspendLabel');
    const suspendApply=document.getElementById('pdvSuspendApply');
    const suspendItems=document.getElementById('pdvSuspendItems');
    const suspendTotal=document.getElementById('pdvSuspendTotal');
    const recoverModal=document.getElementById('pdvRecoverModal');
    const suspendedList=document.getElementById('pdvSuspendedList');

    const electronicModal=document.getElementById('pdvElectronicPaymentModal');
    const electronicTargetLabel=document.getElementById('pdvElectronicTarget');
    const electronicIntegration=document.getElementById('pdvElectronicIntegration');
    const electronicBrand=document.getElementById('pdvElectronicBrand');
    const electronicInstitution=document.getElementById('pdvElectronicInstitution');
    const electronicAuthorization=document.getElementById('pdvElectronicAuthorization');
    const electronicBeneficiary=document.getElementById('pdvElectronicBeneficiary');
    const electronicTerminal=document.getElementById('pdvElectronicTerminal');
    const electronicTransactionDocument=document.getElementById('pdvElectronicTransactionDocument');
    const electronicTransactionState=document.getElementById('pdvElectronicTransactionState');
    const electronicApply=document.getElementById('pdvElectronicApply');

    const contingencyModal=document.getElementById('pdvContingencyModal');
    const cancelNfceModal=document.getElementById('pdvCancelNfceModal');
    const cancelNfceForm=document.getElementById('pdvCancelNfceForm');
    const cancelNfceJob=document.getElementById('pdvCancelNfceJob');
    const cancelNfceReason=document.getElementById('pdvCancelNfceReason');

    const cashOpenDialog=document.getElementById('pdvCashOpenDialog');
    const cashCloseDialog=document.getElementById('pdvCashCloseDialog');

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
    const allowNegativeStock=app.dataset.allowNegativeStock==='1';
    const requireCustomer=app.dataset.requireCustomer==='1';
    const allowDiscount=app.dataset.allowDiscount!=='0';
    const showStock=app.dataset.showStock!=='0';
    const cashOpeningRequired=app.dataset.cashRequired==='1';
    const cashSessionOpen=app.dataset.cashOpen==='1';
    const askConsumerDocument=app.dataset.askConsumerDocument==='1';
    const allowSplitPayment=app.dataset.allowSplitPayment==='1';
    const state=new Map();
    let currentKind='all';
    let lastResults=[];
    let resultIndex=-1;
    let searchTimer=null;
    let searchController=null;
    let finishFlowPending=false;
    let bypassFinalizeValidation=false;
    let splitMode=false;
    let splitApplied=false;
    let splitPayments=[];
    let singleElectronicData={};
    let electronicTarget='single';
    let electronicReturnToPayment=false;
    let consumerAnswered=!askConsumerDocument;
    const defaultPaymentMethod=payment.value||'';

    if(actionDiscount && !allowDiscount){
      actionDiscount.disabled=true;
      actionDiscount.dataset.tooltip='Descontos desativados nas configurações do PDV';
    }

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

    const normalizeTaxId=value=>String(value??'').toUpperCase().replace(/[^A-Z0-9]/g,'');
    const csrfToken=()=>document.querySelector('meta[name="csrf-token"]')?.content||'';

    const electronicFieldsFrom=row=>({
      integration_type:row?.integration_type||'',
      transaction_document:row?.transaction_document||'',
      transaction_state:row?.transaction_state||'',
      institution_document:row?.institution_document||'',
      card_brand:row?.card_brand||'',
      authorization_code:row?.authorization_code||'',
      beneficiary_document:row?.beneficiary_document||'',
      terminal_id:row?.terminal_id||'',
    });

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
      if(allowNegativeStock) return true;
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
        if(!allowNegativeStock && item.type==='product' && item.control_stock && item.stock<1){
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
      const before=[...state.keys()];
      const removedIndex=Math.max(0,before.indexOf(key));

      state.delete(key);

      const after=[...state.keys()];
      const nextKey=after.length
        ? after[Math.min(removedIndex,after.length-1)]
        : '';

      app.dataset.activeCartKey=nextKey;
      renderCart();

      if(nextKey){
        requestAnimationFrame(()=>{
          cart.querySelector('.pdv-cart-row[data-key="'+CSS.escape(nextKey)+'"]')
            ?.scrollIntoView({block:'nearest'});
        });
      }else{
        search.focus();
      }
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
      syncPaymentPayload();
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

    const paymentOption=value=>paymentOptions?.querySelector('[data-payment-value="'+CSS.escape(String(value||''))+'"]');
    const paymentName=value=>paymentOption(value)?.dataset.paymentLabel||'Não selecionado';
    const paymentKind=value=>paymentOption(value)?.dataset.paymentKind||'other';
    const paymentInfo=value=>{
      const option=paymentOption(value);
      return {
        method:String(value||''),
        label:option?.dataset.paymentLabel||String(value||''),
        kind:option?.dataset.paymentKind||'other',
      };
    };

    const splitPaymentTotal=()=>splitPayments.reduce((sum,row)=>sum+(Number(row.amount)||0),0);
    const paymentReady=()=>{
      if(splitApplied){
        return splitPayments.length>0 && Math.abs(splitPaymentTotal()-totals().total)<0.01;
      }
      return !!payment.value;
    };
    const cashPaymentAmount=()=>{
      if(splitApplied){
        return splitPayments
          .filter(row=>row.kind==='cash')
          .reduce((sum,row)=>sum+(Number(row.amount)||0),0);
      }
      return paymentKind(payment.value)==='cash' ? totals().total : 0;
    };

    const syncPaymentPayload=()=>{
      if(!paymentPayload) return;
      paymentPayload.innerHTML='';

      let rows=[];
      if(splitApplied){
        rows=splitPayments;
      }else if(payment.value){
        rows=[{
          ...paymentInfo(payment.value),
          amount:totals().total,
          ...singleElectronicData,
        }];
      }

      rows.forEach((row,index)=>{
        const values={
          payment_method:row.method,
          amount:(Number(row.amount)||0).toFixed(2),
          integration_type:row.integration_type||'',
          transaction_document:row.transaction_document||'',
          transaction_state:row.transaction_state||'',
          institution_document:row.institution_document||'',
          card_brand:row.card_brand||'',
          authorization_code:row.authorization_code||'',
          beneficiary_document:row.beneficiary_document||'',
          terminal_id:row.terminal_id||'',
        };

        Object.entries(values).forEach(([field,value])=>{
          const input=document.createElement('input');
          input.type='hidden';
          input.name='payments['+index+']['+field+']';
          input.value=String(value??'');
          paymentPayload.appendChild(input);
        });
      });
    };

    const updatePaymentState=()=>{
      const cashRequired=cashPaymentAmount();
      const received=cashRequired>0?moneyInputValue(cashInput):0;
      const change=cashRequired>0?Math.max(0,received-cashRequired):0;

      if(actionPaymentValue){
        actionPaymentValue.textContent=splitApplied
          ? splitPayments.length+' formas'
          : paymentName(payment.value);
      }
      if(actionCashValue) actionCashValue.textContent=cashRequired>0?money.format(received):'Não se aplica';
      if(actionChangeValue) actionChangeValue.textContent=money.format(change);

      if(actionPayment){
        const label=splitApplied
          ? 'Pagamento dividido em '+splitPayments.length+' formas'
          : (payment.value ? 'Forma de pagamento: '+paymentName(payment.value) : 'Forma de pagamento pendente');
        actionPayment.dataset.tooltip=label;
        actionPayment.setAttribute('aria-label',label);
      }

      if(actionCash){
        const label=cashRequired>0
          ? 'Dinheiro '+money.format(cashRequired)+' · Recebido '+money.format(received)+' · Troco '+money.format(change)
          : 'Valor recebido e troco';
        actionCash.dataset.tooltip=label;
        actionCash.setAttribute('aria-label',label);
      }

      syncPaymentPayload();
      updateFinishState();
    };

    const updateFinishState=()=>{
      const hasItems=state.size>0;
      const customerReady=!requireCustomer || !!customer.value;
      const cashSessionReady=!cashOpeningRequired || cashSessionOpen;
      finish.disabled=!hasItems || !customerReady || !cashSessionReady;
      if(actionFinish) actionFinish.disabled=!hasItems || !customerReady || !cashSessionReady;

      actionPayment?.classList.toggle('is-set',paymentReady());

      const requiredCash=cashPaymentAmount();
      const cashReady=requiredCash>0 && moneyInputValue(cashInput)+0.0001>=requiredCash;
      actionCash?.classList.toggle('is-set',cashReady);
    };

    const cartKeys=()=>[...state.keys()];

    const selectCartItem=(key,{render=true,scroll=true}={})=>{
      if(!key || !state.has(key)) return false;
      app.dataset.activeCartKey=key;

      if(render){
        renderCart();
      }else{
        cart.querySelectorAll('.pdv-cart-row').forEach(row=>{
          row.classList.toggle('keyboard-active',row.dataset.key===key);
        });
      }

      if(scroll){
        requestAnimationFrame(()=>{
          cart.querySelector('.pdv-cart-row[data-key="'+CSS.escape(key)+'"]')
            ?.scrollIntoView({block:'nearest'});
        });
      }

      return true;
    };

    const moveCartSelection=direction=>{
      const keys=cartKeys();
      if(!keys.length){
        notify('O carrinho está vazio.','warning');
        return;
      }

      const current=activeCartKey();
      let index=keys.indexOf(current);
      if(index<0) index=0;
      else index=Math.max(0,Math.min(keys.length-1,index+direction));

      selectCartItem(keys[index],{render:true,scroll:true});
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

      const selectedBadge=document.createElement('span');
      selectedBadge.className='pdv-cart-selected-badge';
      selectedBadge.textContent='Selecionado';

      title.append(marker,details,selectedBadge);
      title.tabIndex=0;

      row.addEventListener('pointerdown',event=>{
        if(event.button!==0) return;
        if(app.dataset.activeCartKey===key) return;
        app.dataset.activeCartKey=key;
        row.classList.add('keyboard-active');
        cart.querySelectorAll('.pdv-cart-row').forEach(other=>{
          if(other!==row) other.classList.remove('keyboard-active');
        });
      });

      title.addEventListener('keydown',event=>{
        if(event.key==='Enter' || event.key===' '){
          event.preventDefault();
          selectCartItem(key);
        }
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
        selectCartItem(key);
      });

      plus.addEventListener('click',()=>{
        const next=item.quantity+1;
        if(!canIncrease(item,next)) return;
        item.quantity=next;
        selectCartItem(key);
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
        selectCartItem(key,{render:false,scroll:false});
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
      if(showStock && item.type==='product' && item.control_stock) parts.push('Estoque '+number.format(item.stock)+' '+(item.unit||'UN'));
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
    customer.addEventListener('change',updateFinishState);
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

    const updateSplitSummary=()=>{
      const saleTotal=totals().total;
      const informed=splitPaymentTotal();
      const remaining=Math.max(0,saleTotal-informed);

      if(splitTotal) splitTotal.textContent=money.format(saleTotal);
      if(splitPaid) splitPaid.textContent=money.format(informed);
      if(splitRemaining) splitRemaining.textContent=money.format(remaining);
    };

    const renderSplitPayments=()=>{
      if(!splitRows) return;

      splitRows.innerHTML='';
      updateSplitSummary();

      splitPayments.forEach((row,index)=>{
        const line=document.createElement('div');
        line.className='pdv-split-row';

        const label=document.createElement('strong');
        label.textContent=row.label;

        const input=document.createElement('input');
        input.type='text';
        input.inputMode='decimal';
        input.value=(Number(row.amount)||0).toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2});
        input.setAttribute('aria-label','Valor em '+row.label);

        const remove=document.createElement('button');
        remove.type='button';
        remove.textContent='×';
        remove.setAttribute('aria-label','Remover '+row.label);

        input.addEventListener('input',()=>{
          row.amount=parseDecimal(input.value);
          splitApplied=false;
          updateSplitSummary();
        });
        input.addEventListener('focus',()=>input.select());
        remove.addEventListener('click',()=>{
          splitPayments.splice(index,1);
          splitApplied=false;
          renderSplitPayments();
        });

        line.append(label,input,remove);
        splitRows.appendChild(line);
      });
    };

    const addSplitPayment=option=>{
      if(!option) return;
      const info=paymentInfo(option.dataset.paymentValue||'');
      const remaining=Math.max(0,totals().total-splitPaymentTotal());

      if(remaining<0.005){
        notify('O total da venda já foi distribuído. Ajuste ou remova uma forma.','info');
        return;
      }

      const existing=splitPayments.find(row=>row.method===info.method);
      if(existing){
        existing.amount=(Number(existing.amount)||0)+remaining;
      }else{
        splitPayments.push({...info,amount:remaining,...electronicFieldsFrom(null)});
      }

      splitApplied=false;
      renderSplitPayments();
      requestAnimationFrame(()=>{
        splitRows?.querySelector('.pdv-split-row:last-child input')?.focus();
      });
    };

    const selectPaymentOption=option=>{
      if(!option) return;

      const info=paymentInfo(option.dataset.paymentValue||'');

      if(splitMode && allowSplitPayment){
        addSplitPayment(option);
        const index=splitPayments.findIndex(row=>row.method===info.method);
        if(info.kind==='card' && index>=0){
          paymentModal.close();
          setTimeout(()=>openElectronicPaymentModal(index,true),0);
        }
        return;
      }

      splitApplied=false;
      splitPayments=[];
      singleElectronicData={};
      payment.value=option.dataset.paymentValue||'';
      if(actionPaymentValue) actionPaymentValue.textContent=option.dataset.paymentLabel||option.textContent.trim();
      payment.dispatchEvent(new Event('change',{bubbles:true}));
      paymentModal.close();

      if(info.kind==='card'){
        setTimeout(()=>openElectronicPaymentModal('single',false),0);
        return;
      }

      if(finishFlowPending){
        if(cashPaymentAmount()>0){
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
      if(consumerModal?.open) consumerModal.close();
      if(!paymentModal.open) paymentModal.showModal();

      if(splitApplied && allowSplitPayment){
        splitMode=true;
        if(splitPanel) splitPanel.hidden=false;
        if(splitToggle) splitToggle.textContent='Usar pagamento único';
        renderSplitPayments();
      }

      const options=paymentButtons();
      const selectedIndex=options.findIndex(option=>option.dataset.paymentValue===payment.value);
      paymentIndex=selectedIndex>=0?selectedIndex:0;
      updatePaymentHighlight();
      requestAnimationFrame(()=>options[paymentIndex]?.focus());
    };

    splitToggle?.addEventListener('click',()=>{
      splitMode=!splitMode;
      if(splitPanel) splitPanel.hidden=!splitMode;
      splitToggle.textContent=splitMode?'Usar pagamento único':'Dividir em mais de uma forma';

      if(splitMode && !splitPayments.length && payment.value){
        const info=paymentInfo(payment.value);
        splitPayments=[{...info,amount:totals().total}];
        splitApplied=false;
      }

      if(!splitMode && !splitApplied){
        splitPayments=[];
      }

      renderSplitPayments();
    });

    splitApply?.addEventListener('click',()=>{
      if(splitPayments.length<2){
        notify('Adicione pelo menos duas formas de pagamento para dividir a venda.','warning');
        return;
      }

      if(Math.abs(splitPaymentTotal()-totals().total)>=0.01){
        notify('A soma dos pagamentos precisa ser igual ao total da venda.','warning');
        return;
      }

      splitApplied=true;
      splitMode=false;
      payment.value='';
      if(splitPanel) splitPanel.hidden=true;
      if(splitToggle) splitToggle.textContent='Dividir em mais de uma forma';
      updatePaymentState();
      paymentModal.close();

      if(finishFlowPending){
        if(cashPaymentAmount()>0){
          setTimeout(()=>openCashModal(),0);
        }else{
          setTimeout(()=>requestFinalize(),0);
        }
      }
    });

    const updateDiscountPreview=()=>{
      const item=state.get(discountModalKey);
      if(!item) return;
      const gross=item.price*item.quantity;
      const discount=Math.min(gross,parseDecimal(discountModalInput?.value));
      if(discountGross) discountGross.textContent=money.format(gross);
      if(discountNet) discountNet.textContent=money.format(Math.max(0,gross-discount));
    };

    const openDiscountModal=(key=activeCartKey())=>{
      if(!allowDiscount){
        notify('Descontos estão desativados nas configurações do PDV.','info');
        return;
      }
      const item=state.get(key);
      if(!item){
        notify('Adicione um item ao carrinho primeiro.','warning');
        search.focus();
        return;
      }

      [customerModal,paymentModal,discountModal,cashModal,notesModal,consumerModal].forEach(modal=>{
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
      const required=cashPaymentAmount();
      const received=parseDecimal(cashModalInput?.value);
      if(cashModalTotal) cashModalTotal.textContent=money.format(required);
      if(cashModalChange) cashModalChange.textContent=money.format(Math.max(0,received-required));
    };

    const openCashModal=()=>{
      if(cashPaymentAmount()<=0){
        notify('Não há parcela em dinheiro nesta venda.','info');
        finishFlowPending=true;
        openPaymentModal();
        return;
      }
      [customerModal,paymentModal,discountModal,notesModal].forEach(modal=>{if(modal?.open) modal.close();});
      const current=moneyInputValue(cashInput);
      const suggested=current>0?current:cashPaymentAmount();
      cashModalInput.value=suggested.toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2});
      updateCashModalPreview();
      if(!cashModal.open) cashModal.showModal();
      requestAnimationFrame(()=>{
        cashModalInput.focus();
        cashModalInput.select();
      });
    };

    const applyCashModal=()=>{
      const total=cashPaymentAmount();
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
      const total=cashPaymentAmount();
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

    const selectedCustomerOption=()=>{
      const id=String(customer.value||'');
      return customerList?.querySelector('.pdv-client-option[data-customer-id="'+CSS.escape(id)+'"]')||null;
    };

    const openConsumerDocumentModal=()=>{
      [customerModal,paymentModal,discountModal,cashModal,notesModal,consumerModal,electronicModal,operationsModal,suspendModal,recoverModal,contingencyModal,cancelNfceModal].forEach(modal=>{
        if(modal?.open) modal.close();
      });

      const option=selectedCustomerOption();
      const customerDoc=option?.dataset.customerDocument||'';
      const current=consumerDocument?.value||'';
      consumerDocumentInput.value=current||customerDoc||'';

      if(consumerUseCustomer){
        consumerUseCustomer.hidden=!customerDoc;
        consumerUseCustomer.textContent=customerDoc
          ? 'Usar documento de '+(option?.dataset.customerName||'cliente selecionado')
          : 'Usar documento do cliente selecionado';
      }

      if(!consumerModal.open) consumerModal.showModal();
      requestAnimationFrame(()=>{
        consumerDocumentInput?.focus();
        consumerDocumentInput?.select?.();
      });
    };

    const finishConsumerStep=()=>{
      consumerAnswered=true;
      consumerModal?.close();
      if(finishFlowPending) setTimeout(()=>requestFinalize(),0);
    };

    const applyConsumerDocument=()=>{
      const document=normalizeTaxId(consumerDocumentInput?.value||'');
      if(document && ![11,14].includes(document.length)){
        notify('Digite um CPF com 11 caracteres ou CNPJ com 14 caracteres.','warning');
        consumerDocumentInput?.focus();
        return;
      }

      if(document.length===11 && !/^\d{11}$/.test(document)){
        notify('CPF deve conter somente números.','warning');
        consumerDocumentInput?.focus();
        return;
      }

      if(document.length===14 && !/^[A-Z0-9]{12}\d{2}$/.test(document)){
        notify('Formato de CNPJ inválido.','warning');
        consumerDocumentInput?.focus();
        return;
      }

      if(consumerDocument) consumerDocument.value=document;
      const option=selectedCustomerOption();
      const customerDoc=option?.dataset.customerDocument||'';
      if(consumerName){
        consumerName.value=document && document===normalizeTaxId(customerDoc)
          ? (option?.dataset.customerName||'')
          : '';
      }

      finishConsumerStep();
    };

    consumerUseCustomer?.addEventListener('click',()=>{
      const option=selectedCustomerOption();
      const doc=option?.dataset.customerDocument||'';
      if(!doc) return;
      consumerDocumentInput.value=doc;
      consumerDocumentInput.focus();
      consumerDocumentInput.select();
    });

    consumerSkip?.addEventListener('click',()=>{
      if(consumerDocument) consumerDocument.value='';
      if(consumerName) consumerName.value='';
      finishConsumerStep();
    });
    consumerApply?.addEventListener('click',applyConsumerDocument);
    consumerDocumentInput?.addEventListener('keydown',event=>{
      if(event.key==='Enter'){
        event.preventDefault();
        applyConsumerDocument();
      }else if(event.key==='Escape'){
        event.preventDefault();
        consumerSkip?.click();
      }
    });

    const electronicSource=()=>{
      if(electronicTarget==='single'){
        if(paymentKind(payment.value)!=='card') return null;
        return {row:singleElectronicData,label:paymentName(payment.value)};
      }

      const index=Number(electronicTarget);
      const row=splitPayments[index];
      if(!row || row.kind!=='card') return null;
      return {row,label:row.label};
    };

    const openElectronicPaymentModal=(target=null,returnToPayment=false)=>{
      if(target===null){
        if(splitApplied || splitMode){
          const index=splitPayments.findIndex(row=>row.kind==='card');
          target=index>=0 ? index : 'single';
        }else{
          target='single';
        }
      }

      electronicTarget=target;
      electronicReturnToPayment=returnToPayment;
      const source=electronicSource();

      if(!source){
        notify('Selecione uma forma de pagamento com cartão primeiro.','info');
        return;
      }

      const row=electronicFieldsFrom(source.row);
      if(electronicTargetLabel) electronicTargetLabel.textContent=source.label;
      electronicIntegration.value=row.integration_type;
      electronicBrand.value=row.card_brand;
      electronicInstitution.value=row.institution_document;
      electronicAuthorization.value=row.authorization_code;
      electronicBeneficiary.value=row.beneficiary_document;
      electronicTerminal.value=row.terminal_id;
      electronicTransactionDocument.value=row.transaction_document;
      electronicTransactionState.value=row.transaction_state;

      [operationsModal,paymentModal,consumerModal].forEach(modal=>{if(modal?.open) modal.close();});
      if(!electronicModal.open) electronicModal.showModal();
      requestAnimationFrame(()=>electronicIntegration?.focus());
    };

    const applyElectronicPayment=()=>{
      const data={
        integration_type:electronicIntegration.value||'',
        card_brand:electronicBrand.value||'',
        institution_document:normalizeTaxId(electronicInstitution.value),
        authorization_code:electronicAuthorization.value.trim(),
        beneficiary_document:normalizeTaxId(electronicBeneficiary.value),
        terminal_id:electronicTerminal.value.trim(),
        transaction_document:normalizeTaxId(electronicTransactionDocument.value),
        transaction_state:electronicTransactionState.value.trim().toUpperCase(),
      };

      for(const key of ['institution_document','beneficiary_document','transaction_document']){
        if(data[key] && data[key].length!==14){
          notify('Os CNPJs do pagamento devem ter 14 dígitos.','warning');
          return;
        }
      }

      if(data.transaction_state && data.transaction_state.length!==2){
        notify('Informe a UF do pagamento com 2 letras.','warning');
        return;
      }

      if(electronicTarget==='single'){
        singleElectronicData=data;
      }else{
        const index=Number(electronicTarget);
        if(splitPayments[index]) Object.assign(splitPayments[index],data);
      }

      syncPaymentPayload();
      electronicModal.close();

      if(electronicReturnToPayment && splitMode){
        setTimeout(()=>openPaymentModal(),0);
      }else if(finishFlowPending){
        setTimeout(()=>requestFinalize(),0);
      }
    };

    electronicApply?.addEventListener('click',applyElectronicPayment);
    electronicModal?.addEventListener('keydown',event=>{
      if(event.ctrlKey && event.key==='Enter'){
        event.preventDefault();
        applyElectronicPayment();
      }
    });

    const resetCurrentSale=()=>{
      state.clear();
      app.dataset.activeCartKey='';
      customer.value='';
      consumerDocument.value='';
      consumerName.value='';
      payment.value=defaultPaymentMethod;
      cashInput.value='0.00';
      notesInput.value='';
      splitMode=false;
      splitApplied=false;
      splitPayments=[];
      singleElectronicData={};
      consumerAnswered=!askConsumerDocument;

      if(actionCustomerValue) actionCustomerValue.textContent='Consumidor não identificado';
      if(actionNotesValue) actionNotesValue.textContent='Sem observação';

      renderCart();
      updatePaymentState();
      search.value='';
      caption.textContent='Venda limpa. Busque um produto ou serviço.';
      search.focus();
    };

    const serializePayments=()=>{
      if(splitApplied) return splitPayments.map(row=>({...row}));
      if(!payment.value) return [];
      return [{
        ...paymentInfo(payment.value),
        amount:totals().total,
        ...singleElectronicData,
      }];
    };

    const openSuspendModal=()=>{
      if(!state.size){
        notify('Adicione pelo menos um item antes de suspender a venda.','warning');
        return;
      }

      if(suspendItems) suspendItems.textContent=String(state.size);
      if(suspendTotal) suspendTotal.textContent=money.format(totals().total);
      if(suspendLabel) suspendLabel.value='';
      [operationsModal,recoverModal].forEach(modal=>{if(modal?.open) modal.close();});
      if(!suspendModal.open) suspendModal.showModal();
      requestAnimationFrame(()=>suspendLabel?.focus());
    };

    const suspendCurrentSale=async()=>{
      if(!state.size) return;

      const body={
        label:suspendLabel?.value.trim()||null,
        customer_id:customer.value||null,
        consumer_document:consumerDocument.value||null,
        consumer_name:consumerName.value||null,
        payment_method:splitApplied?null:(payment.value||null),
        payments:serializePayments(),
        cash_received:moneyInputValue(cashInput),
        notes:notesInput.value||null,
        items:[...state.values()].map(item=>({
          type:item.type,
          id:item.id,
          quantity:item.quantity,
          discount:item.discount,
        })),
      };

      try{
        const response=await fetch(app.dataset.suspendUrl,{
          method:'POST',
          headers:{
            'Content-Type':'application/json',
            'Accept':'application/json',
            'X-CSRF-TOKEN':csrfToken(),
          },
          body:JSON.stringify(body),
        });

        const result=await response.json().catch(()=>({}));
        if(!response.ok) throw new Error(result.message||Object.values(result.errors||{}).flat()[0]||'Não foi possível suspender a venda.');

        suspendModal.close();
        notify('Venda suspensa.','success');
        resetCurrentSale();
        window.setTimeout(()=>window.location.reload(),350);
      }catch(error){
        notify(error.message||'Não foi possível suspender a venda.','error');
      }
    };

    suspendApply?.addEventListener('click',suspendCurrentSale);
    suspendLabel?.addEventListener('keydown',event=>{
      if(event.key==='Enter'){
        event.preventDefault();
        suspendCurrentSale();
      }
    });

    const openRecoverModal=()=>{
      [operationsModal,suspendModal].forEach(modal=>{if(modal?.open) modal.close();});
      if(!recoverModal.open) recoverModal.showModal();
      requestAnimationFrame(()=>suspendedList?.querySelector('.pdv-suspended-resume')?.focus());
    };

    const restoreSnapshot=snapshot=>{
      resetCurrentSale();

      (snapshot.items||[]).forEach(item=>{
        const normalized={
          ...item,
          price:Number(item.price)||0,
          stock:Number(item.stock)||0,
          quantity:Number(item.quantity)||1,
          discount:Number(item.discount)||0,
        };
        state.set(itemKey(normalized),normalized);
      });

      customer.value=snapshot.customer_id||'';
      const option=selectedCustomerOption();
      if(actionCustomerValue) actionCustomerValue.textContent=option?.querySelector('strong')?.textContent||'Consumidor não identificado';

      consumerDocument.value=snapshot.consumer_document||'';
      consumerName.value=snapshot.consumer_name||'';
      cashInput.value=(Number(snapshot.cash_received)||0).toFixed(2);
      notesInput.value=snapshot.notes||'';
      if(actionNotesValue) actionNotesValue.textContent=notesInput.value||'Sem observação';

      const restoredPayments=Array.isArray(snapshot.payments)?snapshot.payments:[];
      if(restoredPayments.length>1){
        splitPayments=restoredPayments.map(row=>({
          ...row,
          method:row.method||row.payment_method||'',
          label:row.label||paymentName(row.method||row.payment_method),
          kind:row.kind||paymentKind(row.method||row.payment_method),
          amount:Number(row.amount)||0,
        }));
        splitApplied=true;
        splitMode=false;
        payment.value='';
      }else if(restoredPayments.length===1){
        const row=restoredPayments[0];
        payment.value=row.method||row.payment_method||snapshot.payment_method||'';
        singleElectronicData=electronicFieldsFrom(row);
      }else{
        payment.value=snapshot.payment_method||defaultPaymentMethod;
      }

      consumerAnswered=!!snapshot.consumer_document || !askConsumerDocument;
      renderCart();
      updatePaymentState();
      caption.textContent='Venda suspensa recuperada.';
      recoverModal?.close();
      search.focus();
    };

    const resumeSuspendedSale=async id=>{
      const url=(app.dataset.resumeUrlTemplate||'').replace('__ID__',String(id));
      try{
        const response=await fetch(url,{
          method:'POST',
          headers:{'Accept':'application/json','X-CSRF-TOKEN':csrfToken()},
        });
        const result=await response.json().catch(()=>({}));
        if(!response.ok) throw new Error(result.message||'Não foi possível recuperar a venda.');
        restoreSnapshot(result.snapshot||{});
        notify('Venda recuperada.','success');
      }catch(error){
        notify(error.message||'Não foi possível recuperar a venda.','error');
      }
    };

    const discardSuspendedSale=async id=>{
      const url=(app.dataset.discardUrlTemplate||'').replace('__ID__',String(id));
      try{
        const response=await fetch(url,{
          method:'DELETE',
          headers:{'Accept':'application/json','X-CSRF-TOKEN':csrfToken()},
        });
        const result=await response.json().catch(()=>({}));
        if(!response.ok) throw new Error(result.message||'Não foi possível descartar a venda.');
        suspendedList?.querySelector('[data-suspended-row="'+CSS.escape(String(id))+'"]')?.remove();
        notify('Venda suspensa descartada.','success');
      }catch(error){
        notify(error.message||'Não foi possível descartar a venda.','error');
      }
    };

    suspendedList?.addEventListener('click',event=>{
      const resume=event.target.closest('[data-suspended-id]');
      if(resume){
        resumeSuspendedSale(resume.dataset.suspendedId);
        return;
      }

      const discard=event.target.closest('[data-suspended-discard]');
      if(discard){
        discardSuspendedSale(discard.dataset.suspendedDiscard);
      }
    });

    recoverModal?.addEventListener('keydown',event=>{
      const buttons=[...suspendedList.querySelectorAll('.pdv-suspended-resume')];
      if(!buttons.length) return;
      const current=Math.max(0,buttons.indexOf(document.activeElement));

      if(event.key==='ArrowDown'){
        event.preventDefault();
        buttons[Math.min(buttons.length-1,current+1)]?.focus();
      }else if(event.key==='ArrowUp'){
        event.preventDefault();
        buttons[Math.max(0,current-1)]?.focus();
      }else if(event.key==='Delete'){
        event.preventDefault();
        const row=document.activeElement?.closest('[data-suspended-row]');
        const discard=row?.querySelector('[data-suspended-discard]');
        discard?.click();
      }
    });

    const openOperationsModal=()=>{
      if(!operationsModal) return;
      if(!operationsModal.open) operationsModal.showModal();
      requestAnimationFrame(()=>operationsModal.querySelector('[data-pdv-operation]')?.focus());
    };

    const openContingencyModal=()=>{
      operationsModal?.close();
      if(!contingencyModal.open) contingencyModal.showModal();
      requestAnimationFrame(()=>contingencyModal.querySelector('textarea,button[type="submit"]')?.focus());
    };

    const openCancelNfceModal=()=>{
      operationsModal?.close();
      if(!cancelNfceModal.open) cancelNfceModal.showModal();
      requestAnimationFrame(()=>cancelNfceJob?.focus()||cancelNfceReason?.focus());
    };

    const runAdvancedOperation=operation=>{
      switch(operation){
        case 'suspend': openSuspendModal(); break;
        case 'resume': openRecoverModal(); break;
        case 'electronic': operationsModal?.close(); openElectronicPaymentModal(); break;
        case 'contingency': openContingencyModal(); break;
        case 'cancel-nfce': openCancelNfceModal(); break;
        case 'open-cash': operationsModal?.close(); cashOpenDialog?.showModal(); break;
        case 'close-cash': operationsModal?.close(); cashCloseDialog?.showModal(); break;
        case 'supply':
          operationsModal?.close();
          document.querySelector('[data-pdv-cash-movement="supply"]')?.click();
          break;
        case 'withdrawal':
          operationsModal?.close();
          document.querySelector('[data-pdv-cash-movement="withdrawal"]')?.click();
          break;
      }
    };

    operationsButton?.addEventListener('click',openOperationsModal);
    operationsModal?.addEventListener('click',event=>{
      const button=event.target.closest('[data-pdv-operation]');
      if(button) runAdvancedOperation(button.dataset.pdvOperation);
    });
    operationsModal?.addEventListener('keydown',event=>{
      const buttons=[...operationsModal.querySelectorAll('[data-pdv-operation]')];
      if(!buttons.length) return;
      const current=Math.max(0,buttons.indexOf(document.activeElement));

      if(event.key==='ArrowDown'){
        event.preventDefault();
        buttons[Math.min(buttons.length-1,current+1)]?.focus();
      }else if(event.key==='ArrowUp'){
        event.preventDefault();
        buttons[Math.max(0,current-1)]?.focus();
      }
    });

    cancelNfceJob?.addEventListener('change',()=>{
      const option=cancelNfceJob.selectedOptions[0];
      if(cancelNfceForm && option?.dataset.cancelUrl) cancelNfceForm.action=option.dataset.cancelUrl;
    });
    if(cancelNfceJob?.selectedOptions[0]?.dataset.cancelUrl && cancelNfceForm){
      cancelNfceForm.action=cancelNfceJob.selectedOptions[0].dataset.cancelUrl;
    }

    [contingencyModal,cancelNfceModal].forEach(modal=>{
      modal?.addEventListener('keydown',event=>{
        if(event.ctrlKey && event.key==='Enter'){
          event.preventDefault();
          modal.querySelector('form')?.requestSubmit();
        }
      });
    });

    document.querySelectorAll('[data-pdv-cash-movement]').forEach(button=>{
      button.addEventListener('click',()=>{
        if(!cashMovementModal) return;
        const type=button.dataset.pdvCashMovement==='withdrawal'?'withdrawal':'supply';
        if(cashMovementType) cashMovementType.value=type;
        if(cashMovementTitle) cashMovementTitle.textContent=type==='withdrawal'?'Registrar sangria':'Registrar suprimento';
        if(cashMovementHelp) cashMovementHelp.textContent=type==='withdrawal'
          ? 'Retirada de dinheiro do caixa durante o turno.'
          : 'Entrada manual de dinheiro para reforço de troco.';
        if(cashMovementSubmit) cashMovementSubmit.textContent=type==='withdrawal'?'Registrar sangria':'Registrar suprimento';
        if(cashMovementAmount) cashMovementAmount.value='';
        if(cashMovementReason) cashMovementReason.value='';
        cashMovementModal.showModal();
        requestAnimationFrame(()=>cashMovementAmount?.focus());
      });
    });

    const requestFinalize=()=>{
      const t=totals();

      if(!state.size){
        finishFlowPending=false;
        notify('Adicione pelo menos um item ao carrinho.','warning');
        search.focus();
        return;
      }

      if(!paymentReady()){
        finishFlowPending=true;
        notify('Selecione a forma de pagamento para continuar.','info');
        openPaymentModal();
        return;
      }

      const requiredCash=cashPaymentAmount();
      if(requiredCash>0){
        const received=moneyInputValue(cashInput);
        if(received+0.0001<requiredCash){
          finishFlowPending=true;
          openCashModal();
          return;
        }
      }

      if(askConsumerDocument && !consumerAnswered){
        finishFlowPending=true;
        openConsumerDocumentModal();
        return;
      }

      finishFlowPending=false;
      syncPayload();
      syncPaymentPayload();
      bypassFinalizeValidation=true;
      form.requestSubmit();
    };

    const runCommand=command=>{
      switch(command){
        case 'search':
          search.focus();
          search.select();
          break;
        case 'quantity':
          focusCartField('.pdv-qty input');
          break;
        case 'customer':
          openCustomerModal();
          break;
        case 'discount':
          openDiscountModal();
          break;
        case 'payment':
          openPaymentModal();
          break;
        case 'cash':
          openCashModal();
          break;
        case 'remove':
          removeActiveCartItem();
          break;
        case 'finalize':
          requestFinalize();
          break;
        case 'notes':
          openNotesModal();
          break;
      }

      const button={
        search:actionSearch,
        quantity:actionQuantity,
        customer:actionCustomer,
        discount:actionDiscount,
        payment:actionPayment,
        cash:actionCash,
        remove:actionRemove,
        finalize:actionFinish,
        notes:actionNotes,
      }[command];

      if(button){
        button.classList.remove('shortcut-fired');
        void button.offsetWidth;
        button.classList.add('shortcut-fired');
        setTimeout(()=>button.classList.remove('shortcut-fired'),180);
      }
    };

    [
      [actionSearch,'search'],
      [actionQuantity,'quantity'],
      [actionCustomer,'customer'],
      [actionDiscount,'discount'],
      [actionPayment,'payment'],
      [actionCash,'cash'],
      [actionRemove,'remove'],
      [actionFinish,'finalize'],
      [actionNotes,'notes'],
    ].forEach(([button,command])=>{
      button?.addEventListener('click',()=>runCommand(command));
    });

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

      if((event.key==='d'||event.key==='D') && allowSplitPayment && splitToggle){
        event.preventDefault();
        splitToggle.click();
        return;
      }

      if(event.ctrlKey && event.key==='Enter' && splitMode && splitApply){
        event.preventDefault();
        splitApply.click();
        return;
      }
      if(/^[1-9]$/.test(event.key)){
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

    [customerModal,paymentModal,discountModal,cashModal,notesModal].forEach(modal=>{
      modal?.addEventListener('close',()=>{
        setTimeout(()=>search.focus(),0);
      });
    });

    const activeCartKey=()=>{
      const explicit=app.dataset.activeCartKey;
      if(explicit && state.has(explicit)) return explicit;

      const fallback=[...state.keys()].at(-1)||'';
      if(fallback) app.dataset.activeCartKey=fallback;
      return fallback;
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
      const item=state.get(key);
      removeItem(key);
      notify((item?.name||'Item')+' removido do carrinho.','info');
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

    const functionShortcutMap={
      F2:'search',
      F3:'quantity',
      F4:'customer',
      F5:'discount',
      F6:'payment',
      F7:'cash',
      F8:'remove',
      F9:'finalize',
      F10:'notes',
    };

    const altShortcutMap={
      Digit1:'search',
      Digit2:'quantity',
      Digit3:'customer',
      Digit4:'discount',
      Digit5:'payment',
      Digit6:'cash',
      Digit7:'remove',
      Digit8:'finalize',
      Digit9:'notes',
      Numpad1:'search',
      Numpad2:'quantity',
      Numpad3:'customer',
      Numpad4:'discount',
      Numpad5:'payment',
      Numpad6:'cash',
      Numpad7:'remove',
      Numpad8:'finalize',
      Numpad9:'notes',
    };

    const shortcutHandler=event=>{
      const modalOpen=
        customerModal?.open ||
        paymentModal?.open ||
        discountModal?.open ||
        cashModal?.open ||
        notesModal?.open ||
        consumerModal?.open ||
        cashMovementModal?.open ||
        operationsModal?.open ||
        suspendModal?.open ||
        recoverModal?.open ||
        electronicModal?.open ||
        contingencyModal?.open ||
        cancelNfceModal?.open;

      if(modalOpen) return;

      if(event.key==='Escape' || event.code==='Escape'){
        event.preventDefault();
        event.stopPropagation();
        search.focus();
        search.select();
        return;
      }

      if(event.altKey && (event.key==='ArrowUp' || event.code==='ArrowUp')){
        event.preventDefault();
        event.stopPropagation();
        moveCartSelection(-1);
        return;
      }

      if(event.altKey && (event.key==='ArrowDown' || event.code==='ArrowDown')){
        event.preventDefault();
        event.stopPropagation();
        moveCartSelection(1);
        return;
      }

      if((event.key==='Delete' || event.code==='Delete') &&
         !['INPUT','TEXTAREA'].includes(document.activeElement?.tagName)){
        event.preventDefault();
        event.stopPropagation();
        removeActiveCartItem();
        return;
      }

      if(event.altKey && !event.ctrlKey && !event.metaKey){
        const advanced={
          KeyO:'operations',
          Digit0:'suspend',
          Numpad0:'suspend',
          KeyR:'resume',
          KeyT:'electronic',
          KeyC:'contingency',
          KeyX:'cancel-nfce',
          KeyA:'open-cash',
          KeyK:'close-cash',
          KeyP:'supply',
          KeyS:'withdrawal',
        }[event.code];

        if(advanced){
          event.preventDefault();
          event.stopPropagation();
          event.stopImmediatePropagation?.();
          if(advanced==='operations') openOperationsModal();
          else runAdvancedOperation(advanced);
          return;
        }
      }

      if(event.ctrlKey || event.metaKey) return;

      let command=
        functionShortcutMap[event.code] ||
        functionShortcutMap[event.key] ||
        null;

      if(!command && event.altKey){
        command=altShortcutMap[event.code]||null;
      }

      if(!command) return;
      if(event.repeat) return;

      event.preventDefault();
      event.stopPropagation();
      event.stopImmediatePropagation?.();
      runCommand(command);
    };

    window.addEventListener('keydown',shortcutHandler,true);

    form.addEventListener('submit',event=>{
      if(bypassFinalizeValidation){
        bypassFinalizeValidation=false;
        syncPayload();
        syncPaymentPayload();
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