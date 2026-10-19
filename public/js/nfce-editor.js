(() => {
  'use strict';
  const form=document.getElementById('nfceEditorForm');
  if(!form) return;
  const tabsOnly=form.dataset.readonly==='1'||form.dataset.correction==='1';
  if(tabsOnly){
    // Documentos históricos e correções auditadas usam HTML do servidor,
    // sem inicializar o editor de nova venda/estoque/pagamento.
    // A NFC-e autorizada usa valores HTML vindos do XML: não instanciar o
    // editor de produtos/pagamentos, buscas, máscaras ou validação de venda.
    const tabs=[...form.querySelectorAll('[data-nfce-tab]')];
    const panels=[...form.querySelectorAll('[data-nfce-panel]')];
    const activate=target=>{
      const active=tabs.find(tab=>tab.dataset.nfceTab===target);
      if(!active)return;
      tabs.forEach(tab=>{
        const selected=tab===active;
        tab.classList.toggle('active',selected);
        tab.setAttribute('aria-selected',selected?'true':'false');
        tab.tabIndex=selected?0:-1;
      });
      panels.forEach(panel=>{
        const selected=panel.dataset.nfcePanel===target;
        panel.classList.toggle('active',selected);
        panel.hidden=!selected;
      });
    };
    tabs.forEach(tab=>{
      tab.addEventListener('click',()=>activate(tab.dataset.nfceTab));
      tab.addEventListener('keydown',event=>{
        if(!['ArrowLeft','ArrowRight','Home','End'].includes(event.key))return;
        event.preventDefault();
        const index=tabs.indexOf(tab);
        const next=event.key==='Home'?0
          :event.key==='End'?tabs.length-1
          :(index+(event.key==='ArrowRight'?1:-1)+tabs.length)%tabs.length;
        activate(tabs[next].dataset.nfceTab);
        tabs[next].focus();
      });
    });
    // Keep markup, selection and visible panel synchronized on first paint.
    // Invoices open in General and keep the active tab synchronized.
    const initial=form.querySelector('[data-nfce-tabs]')?.dataset.initialTab
      || document.getElementById('nfceEditor')?.dataset.initialTab
      || tabs.find(tab=>tab.getAttribute('aria-selected')==='true')?.dataset.nfceTab
      || 'general';
    activate(initial);

    // A correção apresenta sempre a lista completa de produtos. Selecionar
    // uma linha abre somente os campos do item escolhido, abaixo da tabela.
    const itemEditors=[...form.querySelectorAll('[data-nfce-item-editor]')];
    const itemRows=[...form.querySelectorAll('[data-nfce-item-row]')];
    const itemButtons=[...form.querySelectorAll('[data-nfce-item-select]')];
    const selectItem=index=>{
      const selected=itemEditors.find(panel=>panel.dataset.nfceItemEditor===index);
      itemEditors.forEach(panel=>panel.hidden=panel!==selected);
      itemRows.forEach(row=>row.classList.toggle('is-selected',row.dataset.nfceItemRow===index));
      itemButtons.forEach(button=>button.setAttribute('aria-expanded',
        button.dataset.nfceItemSelect===index?'true':'false'));
      if(selected){
        const body=form.querySelector('.nfe-workspace-body');
        if(body){
          const position=selected.getBoundingClientRect().top-body.getBoundingClientRect().top;
          if(position>body.clientHeight-120)body.scrollTop+=position-70;
        }
      }
    };
    form.addEventListener('click',event=>{
      const button=event.target.closest('[data-nfce-item-select]');
      if(button&&form.contains(button)){
        selectItem(button.dataset.nfceItemSelect);
        return;
      }
      if(event.target.closest('[data-nfce-item-close]')){
        selectItem(null);
      }
    });
    const updateItemSummary=event=>{
      const input=event.target.closest('[data-nfce-item-editor] [name]');
      if(!input)return;
      const editor=input.closest('[data-nfce-item-editor]');
      const field=/\[(ncm|cfop)\]$/.exec(input.name)?.[1];
      if(!field)return;
      const cell=Array.from(form.querySelectorAll('[data-nfce-item-cell]'))
        .find(node=>node.dataset.nfceItemCell===editor.dataset.nfceItemEditor+'-'+field);
      if(cell)cell.textContent=input.value||'—';
    };
    form.addEventListener('input',updateItemSummary);
    form.addEventListener('change',updateItemSummary);
    return;
  }
  const el=id=>document.getElementById(id);
  const json=id=>{try{return JSON.parse(el(id)?.textContent||'[]')}catch{return []}};
  const readOnly=form.dataset.readonly==='1';
  const products=json('nfceProductsJson');
  const methods=json('nfceMethodsJson');
  const oldItems=json('nfceOldItems');
  const oldPayments=json('nfceOldPayments');
  const productMap=new Map(products.map(product=>[String(product.id),product]));
  const rowItems=oldItems.filter(x=>productMap.has(String(x.product_id))).map(x=>({
    product_id:String(x.product_id),quantity:String(x.quantity||1),
    unit_price:String(x.unit_price??0),discount:String(x.discount??0)
  }));
  const paymentFields=['integration_type','card_brand','authorization_code',
    'institution_document','transaction_document','transaction_state',
    'beneficiary_document','terminal_id'];
  const makePayment=source=>({
    payment_method:String(source?.payment_method||methods[0]?.code||''),
    amount:String(source?.amount??''),
    ...Object.fromEntries(paymentFields.map(field=>[field,String(source?.[field]??'')]))
  });
  const payments=oldPayments.length?oldPayments.map(makePayment):[makePayment({})];
  const paymentKind=payment=>methods.find(m=>m.code===payment.payment_method)?.kind||'';
  const isCard=payment=>['credit_card','debit_card'].includes(paymentKind(payment));
  const isElectronic=payment=>isCard(payment)||paymentKind(payment)==='pix';
  const tabs=[...form.querySelectorAll('[data-nfce-tab]')];
  const panels=[...form.querySelectorAll('[data-nfce-panel]')];
  const fmt=cents=>new Intl.NumberFormat('pt-BR',{style:'currency',currency:'BRL'}).format(cents/100);
  const cents=x=>Math.round(Math.max(0,Number(String(x||0).replace(',','.'))||0)*100);
  const mills=x=>Math.round(Math.max(0,Number(String(x||0).replace(',','.'))||0)*1000);
  const text=x=>String(x??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  // Mensagem imediata; o servidor repete os dígitos verificadores antes
  // de criar a venda, inclusive quando o cliente vem do cadastro.
  const validTaxId=value=>{
    const digits=String(value||'').replace(/\D/g,'');
    if(![11,14].includes(digits.length)||/^(\d)\1+$/.test(digits))return false;
    if(digits.length===11){
      for(let i=9;i<11;i++){
        let sum=0;
        for(let j=0;j<i;j++)sum+=Number(digits[j])*(i+1-j);
        let digit=sum*10%11;
        if(digit===10)digit=0;
        if(Number(digits[i])!==digit)return false;
      }
      return true;
    }
    const weights=[[5,4,3,2,9,8,7,6,5,4,3,2],
      [6,5,4,3,2,9,8,7,6,5,4,3,2]];
    return weights.every((weight,i)=>{
      const sum=weight.reduce((n,w,j)=>n+Number(digits[j])*w,0);
      const check=sum%11<2?0:11-sum%11;
      return Number(digits[12+i])===check;
    });
  };
  const newMode=()=>el('nfceMode').value==='new';
  // Mesmo ícone de lixeira utilizado no restante do Lumeron.
  const trashIcon='<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M8 6V4h8v2M5 6l1 15h12l1-15M10 10v7M14 10v7"/></svg>';

  function activateTab(tab,focus=false) {
    const target=tabs.find(button=>button.dataset.nfceTab===tab && !button.disabled);
    if(!target) return;
    tabs.forEach(button=>{
      const active=button===target;
      button.classList.toggle('active',active);
      button.setAttribute('aria-selected',active?'true':'false');
      button.tabIndex=active?0:-1;
    });
    panels.forEach(panel=>{
      const active=panel.dataset.nfcePanel===tab;
      panel.classList.toggle('active',active);
      panel.hidden=!active;
    });
    if(focus) target.focus();
  }
  tabs.forEach(button=>{
    button.addEventListener('click',()=>activateTab(button.dataset.nfceTab));
    button.addEventListener('keydown',event=>{
      if(!['ArrowLeft','ArrowRight','Home','End'].includes(event.key)) return;
      event.preventDefault();
      const enabled=tabs.filter(item=>!item.disabled);
      const current=enabled.indexOf(button);
      if(current<0||!enabled.length) return;
      const next=event.key==='Home'?0
        :event.key==='End'?enabled.length-1
        :(current+(event.key==='ArrowRight'?1:-1)+enabled.length)%enabled.length;
      activateTab(enabled[next].dataset.nfceTab,true);
    });
  });

  function lineTotal(row) {
    const gross=Math.round(cents(row.unit_price)*mills(row.quantity)/1000);
    return Math.max(0,gross-cents(row.discount));
  }
  function sum() {
    let subtotal=0,discount=0;
    rowItems.forEach(row=>{
      subtotal+=Math.round(cents(row.unit_price)*mills(row.quantity)/1000);
      discount+=cents(row.discount);
    });
    return {subtotal,discount,total:Math.max(0,subtotal-discount)};
  }

  function refreshTotals(){
    const amount=sum();
    const linked=Number(el('nfceSale').selectedOptions[0]?.dataset.total||0);
    const total=newMode()?amount.total:Math.round(linked*100);
    el('nfceFixedTotal').textContent=fmt(total);
    el('nfceSubtotal').textContent=fmt(newMode()?amount.subtotal:total);
    el('nfceDiscountTotal').textContent=fmt(newMode()?amount.discount:0);
    el('nfceGrandTotal').textContent=fmt(total);
    el('nfcePaymentTotal').textContent=fmt(payments.reduce((acc,p)=>acc+cents(p.amount),0));
  }

  function renderItems() {
    el('nfceNoItems').hidden=rowItems.length>0;
    el('nfceItems').innerHTML=rowItems.map((row,index)=>{
      const p=productMap.get(row.product_id);
      return '<tr data-idx="'+index+'">'+
        '<td><strong>'+text(p.name)+'</strong><small>'+text(p.sku||'')+'</small>'+
        '<small>NCM: '+text(p.ncm||'Não cadastrado')+'</small>'+
        '<input type="hidden" name="items['+index+'][product_id]" value="'+row.product_id+'"></td>'+
        '<td><input type="number" min="0.001" max="9999999999" step="0.001" name="items['+index+'][quantity]" data-item-field="quantity" value="'+text(row.quantity)+'" required></td>'+
        '<td><input type="number" min="0" max="9999999999.99" step="0.01" name="items['+index+'][unit_price]" data-item-field="unit_price" value="'+text(row.unit_price)+'" required></td>'+
        '<td><input type="number" min="0" max="9999999999.99" step="0.01" name="items['+index+'][discount]" data-item-field="discount" value="'+text(row.discount)+'"></td>'+
        '<td><strong data-item-total>'+fmt(lineTotal(row))+'</strong></td>'+
        '<td><button type="button" data-remove-item="'+index+'" class="nfce-remove" title="Remover produto" aria-label="Remover produto">'+trashIcon+'</button></td>'+
        '</tr>';
    }).join('');
    refreshTotals();
  }
  el('nfceItems').addEventListener('input',event=>{
    const field=event.target.dataset.itemField;
    if(!field) return;
    const row=event.target.closest('[data-idx]');
    const index=Number(row.dataset.idx);
    rowItems[index][field]=event.target.value;
    row.querySelector('[data-item-total]').textContent=fmt(lineTotal(rowItems[index]));
    refreshTotals();
  });
  el('nfceItems').addEventListener('click',event=>{
    const button=event.target.closest('[data-remove-item]');
    if(!button) return;
    rowItems.splice(Number(button.dataset.removeItem),1);
    renderItems();
  });
  function addProduct(id){
    if(!productMap.has(String(id))) return;
    const product=productMap.get(String(id));
    rowItems.push({product_id:String(id),quantity:'1',unit_price:Number(product.sale_price||0).toFixed(2),discount:'0.00'});
    el('nfceProductSearch').value='';
    el('nfceProductResults').hidden=true;
    renderItems();
    el('nfceItems').querySelector('tr:last-child [data-item-field="quantity"]')?.focus();
  }

  const search=el('nfceProductSearch');
  const results=el('nfceProductResults');
  function renderResults(){
    const q=search.value.trim().toLocaleLowerCase('pt-BR');
    const list=products.filter(p=>!q || [p.name,p.sku,p.ean_gtin].some(value=>String(value||'').toLocaleLowerCase('pt-BR').includes(q))).slice(0,14);
    results.innerHTML=list.map(p=>
      '<button type="button" role="option" data-pick-product="'+p.id+'"><span><strong>'+text(p.name)+'</strong><small>'+text(p.sku||'')+'</small></span><b>'+fmt(cents(p.sale_price))+'</b></button>'
    ).join('') || '<p>Nenhum produto encontrado.</p>';
    results.hidden=false;
  }
  search.addEventListener('input',renderResults);
  search.addEventListener('focus',renderResults);
  search.addEventListener('keydown',event=>{
    if(event.key==='Escape'){results.hidden=true;return;}
    if(event.key==='Enter'&&!results.hidden){
      const first=results.querySelector('[data-pick-product]');
      if(first){event.preventDefault();addProduct(first.dataset.pickProduct)}
    }
  });
  results.addEventListener('click',event=>{
    const button=event.target.closest('[data-pick-product]');
    if(button) addProduct(button.dataset.pickProduct);
  });
  document.addEventListener('click',event=>{
    if(!results.contains(event.target)&&event.target!==search) results.hidden=true;
  });
  el('nfceAddProduct').addEventListener('click',()=>{
    activateTab('products');search.focus();renderResults();
  });

  function paymentInput(index,payment,field,label,placeholder='',length=40){
    return '<label class="field"><span>'+text(label)+'</span><input type="text" '+
      'name="payments['+index+']['+field+']" data-payment-field="'+field+'" '+
      'maxlength="'+length+'" placeholder="'+text(placeholder)+'" value="'+text(payment[field])+'"></label>';
  }
  function updatePaymentDetails(){
    el('nfcePayments').querySelectorAll('[data-payment]').forEach(row=>{
      const idx=Number(row.dataset.payment);
      const payment=payments[idx];
      const detail=el('nfcePayments').querySelector('[data-payment-detail="'+idx+'"]');
      if(!detail)return;
      const electronic=isElectronic(payment);
      const card=isCard(payment);
      detail.hidden=!electronic;
      detail.querySelectorAll('input,select').forEach(field=>{
        field.disabled=!electronic ||
          (field.name.endsWith('[card_brand]') && !card);
      });
      const hint=detail.querySelector('[data-payment-hint]');
      if(hint)hint.textContent=card
        ?'Informe TEF/POS. Se for integrado, preencha CNPJ da adquirente e autorização.'
        :'No PIX, informe os dados da transação disponíveis. Não invente autorização.';
    });
  }
  function renderPayments(){
    el('nfcePayments').innerHTML=payments.map((payment,index)=>{
      const menu='<tr data-payment="'+index+'"><td><select name="payments['+index+'][payment_method]" data-payment-field="payment_method" required>'+
        methods.map(method=>'<option value="'+text(method.code)+'" '+(payment.payment_method===method.code?'selected':'')+'>'+text(method.name)+'</option>').join('')+
        '</select></td><td><input type="number" name="payments['+index+'][amount]" data-payment-field="amount" step="0.01" min="0.01" max="9999999999.99" value="'+text(payment.amount)+'" required></td>'+
        '<td><button class="nfce-remove" type="button" data-remove-payment="'+index+'" title="Remover pagamento" aria-label="Remover pagamento">'+trashIcon+'</button></td></tr>';
      const detail='<tr class="nfce-payment-detail" data-payment-detail="'+index+'" hidden><td colspan="3">'+
        '<div class="nfce-payment-detail-head"><strong>Dados do pagamento eletrônico</strong><small data-payment-hint></small></div>'+
        '<div class="nfce-payment-detail-grid">'+
        '<label class="field"><span>Integração com o sistema</span><select name="payments['+index+'][integration_type]" data-payment-field="integration_type">'+
        '<option value="">Selecione quando aplicável</option>'+
        '<option value="1" '+(payment.integration_type==='1'?'selected':'')+'>1 — Integrado (TEF/automação)</option>'+
        '<option value="2" '+(payment.integration_type==='2'?'selected':'')+'>2 — Não integrado (maquininha)</option>'+
        '</select></label>'+
        '<label class="field"><span>Bandeira (cartão)</span><select name="payments['+index+'][card_brand]" data-payment-field="card_brand">'+
        '<option value="">Selecione</option>'+
        [['01','Visa'],['02','Mastercard'],['03','American Express'],['04','Sorocred'],['05','Diners Club'],['06','Elo'],['07','Hipercard'],['08','Aura'],['09','Cabal'],['99','Outros']].map(([code,name])=>
          '<option value="'+code+'" '+(payment.card_brand===code?'selected':'')+'>'+name+'</option>').join('')+
        '</select></label>'+
        paymentInput(index,payment,'institution_document','CNPJ da adquirente','Somente números',18)+
        paymentInput(index,payment,'authorization_code','Autorização da transação','Código fornecido pela adquirente',128)+
        paymentInput(index,payment,'transaction_document','CNPJ do local da transação','Quando aplicável',18)+
        paymentInput(index,payment,'transaction_state','UF da transação','Ex.: BA',2)+
        paymentInput(index,payment,'beneficiary_document','CNPJ de quem recebe','Quando aplicável',18)+
        paymentInput(index,payment,'terminal_id','Identificação do terminal','Quando aplicável',40)+
        '</div></td></tr>';
      return menu+detail;
    }).join('');
    updatePaymentDetails();
    refreshTotals();
  }
  el('nfcePayments').addEventListener('input',event=>{
    const field=event.target.dataset.paymentField;
    if(!field)return;
    const row=event.target.closest('[data-payment],[data-payment-detail]');
    const index=Number(row.dataset.payment??row.dataset.paymentDetail);
    payments[index][field]=event.target.value;
    if(field==='amount')refreshTotals();
  });
  el('nfcePayments').addEventListener('change',event=>{
    const field=event.target.dataset.paymentField;
    if(!field)return;
    const row=event.target.closest('[data-payment],[data-payment-detail]');
    const index=Number(row.dataset.payment??row.dataset.paymentDetail);
    payments[index][field]=event.target.value;
    if(field==='payment_method'){
      // Nunca transportar metadados de cartão/PIX a pagamentos em dinheiro.
      if(!isElectronic(payments[index])){
        paymentFields.forEach(key=>payments[index][key]='');
      }
      updatePaymentDetails();
    }
  });
  el('nfcePayments').addEventListener('click',event=>{
    const btn=event.target.closest('[data-remove-payment]');
    if(!btn)return;
    payments.splice(Number(btn.dataset.removePayment),1);
    renderPayments();
  });
  el('nfceAddPayment').addEventListener('click',()=>{
    payments.push(makePayment({}));
    renderPayments();
    el('nfcePayments').querySelector('[data-payment="'+(payments.length-1)+'"] [data-payment-field="amount"]')?.focus();
  });

  function setMode(){
    if(readOnly){
      el('nfceExistingBlock').hidden=true;
      refreshTotals();
      return;
    }
    const existing=!newMode();
    el('nfceExistingBlock').hidden=!existing;
    for(const panel of ['consumer','products','payment']) {
      const button=form.querySelector('[data-nfce-tab="'+panel+'"]');
      button.disabled=existing;
    }
    form.querySelectorAll('[data-nfce-panel="consumer"] [name],[data-nfce-panel="products"] [name],[data-nfce-panel="payment"] [name]')
      .forEach(control=>control.disabled=existing);
    el('nfceSale').disabled=!existing;
    el('nfceSaleSearch').disabled=!existing;
    if(!existing)updatePaymentDetails();
    if(existing&&['consumer','products','payment'].includes(form.querySelector('.editor-tab.active')?.dataset.nfceTab)){
      activateTab('general');
    }
    refreshTotals();
  }
  el('nfceMode').addEventListener('change',setMode);
  el('nfceSale').addEventListener('change',refreshTotals);
  el('nfceSaleSearch').addEventListener('input',event=>{
    const q=event.target.value.toLocaleLowerCase('pt-BR');
    [...el('nfceSale').options].forEach(option=>{
      if(!option.value) return;
      option.hidden=!option.textContent.toLocaleLowerCase('pt-BR').includes(q);
    });
  });
  el('nfceCustomer').addEventListener('change',event=>{
    const option=event.target.selectedOptions[0];
    el('nfceConsumerDocument').value=option?.dataset.document||'';
    el('nfceConsumerName').value=option?.dataset.name||'';
  });
  let submitting=false;
  function invalid(event,message,tab,focus){
    event.preventDefault();
    const box=el('nfceFormError');
    box.textContent=message;
    box.hidden=false;
    activateTab(tab);
    focus?.focus();
  }

  form.addEventListener('submit',event=>{
    if(readOnly){event.preventDefault();return;}
    if(submitting) {event.preventDefault();return;}
    el('nfceFormError').hidden=true;
    if(!newMode()){
      if(!el('nfceSale').value){
        invalid(event,'Selecione uma venda concluída.','general',el('nfceSale'));
        return;
      }
    } else {
      const documentValue=el('nfceConsumerDocument').value.replace(/\D/g,'');
      if(documentValue && !validTaxId(documentValue)){
        invalid(event,'Informe CPF ou CNPJ válido, ou deixe em branco.','consumer',el('nfceConsumerDocument'));
        return;
      }
      if(!rowItems.length){
        invalid(event,'Adicione pelo menos um produto.','products',search);
        return;
      }
      for(const row of rowItems){
        const qty=Number(String(row.quantity).replace(',','.'));
        const unit=Number(String(row.unit_price).replace(',','.'));
        const disc=Number(String(row.discount).replace(',','.'));
        if(!(qty>0&&qty<=9999999999&&unit>=0&&disc>=0)
          || Math.abs(Math.round(qty*1000)-qty*1000)>0.00001
          || Math.abs(Math.round(unit*100)-unit*100)>0.00001
          || Math.abs(Math.round(disc*100)-disc*100)>0.00001
          || cents(row.discount)>Math.round(cents(row.unit_price)*mills(row.quantity)/1000)){
          invalid(event,'Confira as quantidades, preços e descontos dos produtos.','products',el('nfceItems').querySelector('input[type=number]'));
          return;
        }
      }
      if(!payments.length || payments.some(p=>!p.payment_method||cents(p.amount)<=0)){
        invalid(event,'Informe uma forma de pagamento e um valor válido.','payment',el('nfcePayments').querySelector('input'));
        return;
      }
      const validCnpj=value=>{
        const cleaned=String(value||'').replace(/\D/g,'');
        return cleaned===''||cleaned.length===14&&validTaxId(cleaned);
      };
      const states=['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'];
      for(let index=0;index<payments.length;index++){
        const payment=payments[index];
        if(!isElectronic(payment))continue;
        const number=index+1;
        if(isCard(payment)&&!['1','2'].includes(payment.integration_type)){
          invalid(event,'Pagamento '+number+': informe se o cartão é integrado (TEF) ou não integrado (POS).','payment',
            el('nfcePayments').querySelector('[name="payments['+index+'][integration_type]"]'));
          return;
        }
        if(paymentKind(payment)==='pix' && payment.integration_type && !['1','2'].includes(payment.integration_type)){
          invalid(event,'Pagamento '+number+': tipo de integração PIX inválido.','payment');
          return;
        }
        if(!validCnpj(payment.institution_document)||!validCnpj(payment.beneficiary_document)
          ||!validCnpj(payment.transaction_document)){
          invalid(event,'Pagamento '+number+': confira os dígitos do CNPJ informado.','payment');
          return;
        }
        if(Boolean(payment.transaction_document)!==Boolean(payment.transaction_state)
          ||payment.transaction_state && !states.includes(payment.transaction_state.toUpperCase())){
          invalid(event,'Pagamento '+number+': informe CNPJ e UF válidos do local da transação.','payment');
          return;
        }
        if(isCard(payment)&&payment.integration_type==='1'
          &&(!payment.institution_document||!payment.authorization_code.trim())){
          invalid(event,'Pagamento '+number+': cartão integrado exige CNPJ da adquirente e autorização.','payment');
          return;
        }
      }
      const line=sum();
      if(payments.reduce((n,p)=>n+cents(p.amount),0)!==line.total){
        invalid(event,'O total dos pagamentos precisa ser igual ao total da NFC-e.','payment',el('nfcePayments').querySelector('input'));
        el('nfcePaymentTotal').classList.add('nfce-amount-warning');
        return;
      }
    }

    const submitMode=event.submitter?.value==='save'?'save':'issue';
    // Preserve which action was clicked even after disabling both buttons.
    const hidden=document.createElement('input');
    hidden.type='hidden';hidden.name='submit_mode';hidden.value=submitMode;
    form.appendChild(hidden);
    submitting=true;
    form.querySelectorAll('button[type=submit]').forEach(button=>button.disabled=true);
  });

  renderItems();renderPayments();
  if(!readOnly && el('nfceMode').selectedOptions[0]?.disabled) el('nfceMode').value='existing';
  setMode();
  activateTab('general');
  if(readOnly){
    // Keep tabs interactive, but no field can be edited, deleted or submitted.
    form.querySelectorAll('input,select,textarea').forEach(control=>{
      control.disabled=true;
      control.setAttribute('aria-readonly','true');
    });
    form.querySelectorAll('button:not([data-nfce-tab]):not([data-dialog-open])')
      .forEach(button=>button.disabled=true);
  }
})();
