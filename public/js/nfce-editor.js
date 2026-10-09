(() => {
  'use strict';
  const form=document.getElementById('nfceEditorForm');
  if(!form) return;
  const el=id=>document.getElementById(id);
  const json=id=>{try{return JSON.parse(el(id)?.textContent||'[]')}catch{return []}};
  const products=json('nfceProductsJson');
  const methods=json('nfceMethodsJson');
  const oldItems=json('nfceOldItems');
  const oldPayments=json('nfceOldPayments');
  const productMap=new Map(products.map(product=>[String(product.id),product]));
  const rowItems=oldItems.filter(x=>productMap.has(String(x.product_id))).map(x=>({
    product_id:String(x.product_id),quantity:String(x.quantity||1),
    unit_price:String(x.unit_price??0),discount:String(x.discount??0)
  }));
  const payments=oldPayments.length?oldPayments.map(x=>({
    payment_method:String(x.payment_method||methods[0]?.code||''),
    amount:String(x.amount??'')
  })):[{payment_method:methods[0]?.code||'',amount:''}];
  const tabs=[...form.querySelectorAll('[data-nfce-tab]')];
  const panels=[...form.querySelectorAll('[data-nfce-panel]')];
  const fmt=cents=>new Intl.NumberFormat('pt-BR',{style:'currency',currency:'BRL'}).format(cents/100);
  const cents=x=>Math.round(Math.max(0,Number(String(x||0).replace(',','.'))||0)*100);
  const mills=x=>Math.round(Math.max(0,Number(String(x||0).replace(',','.'))||0)*1000);
  const text=x=>String(x??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const newMode=()=>el('nfceMode').value==='new';
  // Mesmo ícone de lixeira utilizado no restante do NEXTOR.
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
        '<td><strong>'+text(p.name)+'</strong><small>'+text(p.sku||'')+'</small><input type="hidden" name="items['+index+'][product_id]" value="'+row.product_id+'"></td>'+
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

  function renderPayments(){
    el('nfcePayments').innerHTML=payments.map((payment,index)=>
      '<tr data-payment="'+index+'"><td><select name="payments['+index+'][payment_method]" data-payment-field="payment_method" required>'+
      methods.map(method=>'<option value="'+text(method.code)+'" '+(payment.payment_method===method.code?'selected':'')+'>'+text(method.name)+'</option>').join('')+
      '</select></td><td><input type="number" name="payments['+index+'][amount]" data-payment-field="amount" step="0.01" min="0.01" max="9999999999.99" value="'+text(payment.amount)+'" required></td>'+
      '<td><button class="nfce-remove" type="button" data-remove-payment="'+index+'" title="Remover pagamento" aria-label="Remover pagamento">'+trashIcon+'</button></td></tr>'
    ).join('');
    refreshTotals();
  }
  el('nfcePayments').addEventListener('input',event=>{
    const field=event.target.dataset.paymentField;
    if(!field)return;
    payments[Number(event.target.closest('[data-payment]').dataset.payment)][field]=event.target.value;
    refreshTotals();
  });
  el('nfcePayments').addEventListener('change',event=>{
    if(event.target.dataset.paymentField==='payment_method') {
      payments[Number(event.target.closest('[data-payment]').dataset.payment)].payment_method=event.target.value;
    }
  });
  el('nfcePayments').addEventListener('click',event=>{
    const btn=event.target.closest('[data-remove-payment]');
    if(!btn) return;
    payments.splice(Number(btn.dataset.removePayment),1);
    renderPayments();
  });
  el('nfceAddPayment').addEventListener('click',()=>{
    payments.push({payment_method:methods[0]?.code||'',amount:''});
    renderPayments();
    el('nfcePayments').querySelector('tr:last-child input')?.focus();
  });

  function setMode(){
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
    if(submitting) {event.preventDefault();return;}
    el('nfceFormError').hidden=true;
    if(!newMode()){
      if(!el('nfceSale').value){
        invalid(event,'Selecione uma venda concluída.','general',el('nfceSale'));
        return;
      }
    } else {
      const documentValue=el('nfceConsumerDocument').value.replace(/\D/g,'');
      if(documentValue && ![11,14].includes(documentValue.length)){
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
  if(el('nfceMode').selectedOptions[0]?.disabled) el('nfceMode').value='existing';
  setMode();
  activateTab('general');
})();
