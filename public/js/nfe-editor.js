(()=>{
  const parseJson=id=>{
    const el=document.getElementById(id);
    if(!el) return [];
    try{return JSON.parse(el.value||el.textContent||'[]')||[];}catch(_){return [];}
  };
  const byId=id=>document.getElementById(id);
  const money=value=>'R$ '+(Number(value)||0).toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2});
  const number=value=>Number.parseFloat(value||'0')||0;
  const esc=value=>String(value??'').replace(/[&<>"']/g,ch=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch]));

  const products=parseJson('nfe-products-data');
  const customers=parseJson('nfe-customers-data');
  const natures=parseJson('nfe-natures-data');
  const emitterState=(()=>{
    const el=byId('nfe-emitter-state');
    try{return JSON.parse(el?.textContent||'null')||'';}catch(_){return '';}
  })();

  const productById=id=>products.find(x=>String(x.id)===String(id));
  const customerById=id=>customers.find(x=>String(x.id)===String(id));
  const natureById=id=>natures.find(x=>String(x.id)===String(id));

  let items=parseJson('nfeItemsJson');
  let duplicates=parseJson('nfeDuplicatesJson');
  let payments=parseJson('nfePaymentsJson');
  let references=parseJson('nfeReferencesJson');
  let customFields=parseJson('nfeCustomFieldsJson');
  if(Array.isArray(customFields)) customFields={};

  const hidden={
    items:byId('nfeItemsJson'),
    duplicates:byId('nfeDuplicatesJson'),
    payments:byId('nfePaymentsJson'),
    references:byId('nfeReferencesJson'),
    custom:byId('nfeCustomFieldsJson'),
  };

  const syncHidden=()=>{
    if(hidden.items) hidden.items.value=JSON.stringify(items);
    if(hidden.duplicates) hidden.duplicates.value=JSON.stringify(duplicates);
    if(hidden.payments) hidden.payments.value=JSON.stringify(payments);
    if(hidden.references) hidden.references.value=JSON.stringify(references);
    document.querySelectorAll('[data-nfe-custom]').forEach(field=>{
      customFields[field.dataset.nfeCustom]=field.value;
    });
    if(hidden.custom) hidden.custom.value=JSON.stringify(customFields);
  };

  const totals=()=>{
    const total=items.reduce((acc,item)=>{
      const qty=number(item.quantity);
      const unit=number(item.unit_price);
      acc.products+=qty*unit;
      acc.freight+=number(item.freight);
      acc.insurance+=number(item.insurance);
      acc.other+=number(item.other_expenses);
      acc.discount+=number(item.discount);
      return acc;
    },{products:0,freight:0,insurance:0,other:0,discount:0});
    total.total=total.products+total.freight+total.insurance+total.other-total.discount;
    return total;
  };

  const renderTotals=()=>{
    const t=totals();
    const map={
      nfeTotalProducts:t.products,nfeTotalFreight:t.freight,nfeTotalInsurance:t.insurance,
      nfeTotalOther:t.other,nfeTotalDiscount:t.discount,nfeTotalInvoice:t.total,nfeFooterTotal:t.total
    };
    Object.entries(map).forEach(([id,value])=>{const el=byId(id);if(el)el.textContent=money(value);});
  };

  const itemLineTotal=item=>{
    return Math.max(0,number(item.quantity)*number(item.unit_price)+number(item.freight)+number(item.insurance)+number(item.other_expenses)-number(item.discount));
  };

  const renderItems=()=>{
    const body=byId('nfeItemsBody');
    if(!body) return;
    if(!items.length){
      body.innerHTML='<tr><td colspan="8" class="empty-cell">Nenhum produto adicionado.</td></tr>';
      renderTotals(); syncHidden(); return;
    }
    body.innerHTML=items.map((item,index)=>`
      <tr>
        <td><strong class="table-title">${esc(item.product_name)}</strong><small class="table-subtitle">${esc(item.product_sku||'')}</small></td>
        <td>${item.cfop?'<span class="code-tag">'+esc(item.cfop)+'</span>':'—'}</td>
        <td>${esc(item.ncm||'—')}</td>
        <td class="nowrap">${number(item.quantity).toLocaleString('pt-BR',{maximumFractionDigits:4})}</td>
        <td class="nowrap">${money(item.unit_price)}</td>
        <td class="nowrap">${money(item.discount)}</td>
        <td class="price-strong nowrap">${money(itemLineTotal(item))}</td>
        <td class="action-cell"><div class="row-actions">
          <button type="button" class="btn-icon" data-nfe-edit-item="${index}" data-tooltip="Editar item">✎</button>
          <button type="button" class="btn-icon row-action-danger" data-nfe-remove-item="${index}" data-tooltip="Remover item">×</button>
        </div></td>
      </tr>`).join('');
    renderTotals(); syncHidden();
  };

  const renderReferences=()=>{
    const box=byId('nfeReferenceList'); if(!box) return;
    box.innerHTML=references.length?references.map((ref,index)=>`
      <div class="nfe-mini-row"><span><strong>${esc((ref.type||'').toUpperCase())}</strong> ${esc(ref.key||'')}</span>
      <button type="button" class="btn-icon row-action-danger" data-nfe-remove-reference="${index}">×</button></div>`).join('')
      :'<div class="nfe-mini-empty">Nenhum documento referenciado.</div>';
    syncHidden();
  };

  const renderDuplicates=()=>{
    const box=byId('nfeDuplicateList'); if(!box) return;
    box.innerHTML=duplicates.length?duplicates.map((row,index)=>`
      <div class="nfe-mini-row"><span><strong>${esc(row.number||String(index+1))}</strong> · ${esc(row.due_date||'Sem vencimento')} · ${money(row.value)}</span>
      <button type="button" class="btn-icon row-action-danger" data-nfe-remove-duplicate="${index}">×</button></div>`).join('')
      :'<div class="nfe-mini-empty">Nenhuma duplicata informada.</div>';
    syncHidden();
  };

  const renderPayments=()=>{
    const box=byId('nfePaymentList'); if(!box) return;
    box.innerHTML=payments.length?payments.map((row,index)=>`
      <div class="nfe-mini-row"><span><strong>${esc(row.payment_method||'')}</strong> · ${money(row.amount)}${row.due_date?' · '+esc(row.due_date):''}</span>
      <button type="button" class="btn-icon row-action-danger" data-nfe-remove-payment="${index}">×</button></div>`).join('')
      :'<div class="nfe-mini-empty">Nenhum pagamento informado.</div>';
    const count=byId('nfePaymentCount');
    if(count) count.textContent=payments.length+' pagamento'+(payments.length===1?'':'s');
    syncHidden();
  };

  const selectedNature=()=>natureById(byId('nfeNature')?.value);
  const selectedCustomer=()=>customerById(byId('nfeCustomer')?.value);

  const resolveCfop=()=>{
    const nature=selectedNature();
    const customer=selectedCustomer();
    if(!nature) return '';
    const destination=byId('nfeDestination')?.value||'auto';
    if(destination==='foreign') return nature.cfop_foreign||nature.cfop_interstate||nature.cfop_internal||'';
    if(destination==='interstate') return nature.cfop_interstate||nature.cfop_internal||'';
    if(destination==='internal') return nature.cfop_internal||'';
    if(customer?.state && emitterState && customer.state!==emitterState) return nature.cfop_interstate||nature.cfop_internal||'';
    return nature.cfop_internal||'';
  };

  const updateRecipient=()=>{
    const customer=selectedCustomer();
    const summary=byId('nfeRecipientSummary');
    const final=byId('nfeCustomerFinal');
    const delivery=byId('nfeDeliveryAddress');
    if(!customer){
      if(summary) summary.innerHTML='<span>Selecione um destinatário para visualizar CPF/CNPJ, IE e endereço.</span>';
      if(final) final.textContent='—';
      if(delivery) delivery.innerHTML='<option value="">Selecione</option>';
      return;
    }

    if(summary){
      const address=[customer.address,customer.address_number,customer.district,customer.city,customer.state].filter(Boolean).join(', ');
      summary.innerHTML=`<strong>${esc(customer.name)}</strong><span>CPF/CNPJ: ${esc(customer.document||'—')} · IE: ${esc(customer.state_registration||'—')} · ${esc(address||'Endereço não informado')}</span>`;
    }
    if(final) final.textContent=customer.final_consumer?'Sim':'Não';

    if(delivery){
      const current=delivery.value;
      delivery.innerHTML='<option value="">Selecione</option>'+((customer.delivery_addresses||[]).map(a=>{
        const label=[a.name,a.address,a.address_number,a.city,a.state].filter(Boolean).join(' · ');
        return '<option value="'+esc(a.id)+'">'+esc(label||('Endereço #'+a.id))+'</option>';
      }).join(''));
      if([...delivery.options].some(o=>o.value===current)) delivery.value=current;
    }
  };

  const updateDeliveryVisibility=()=>{
    const toggle=byId('nfeDifferentDelivery');
    const field=byId('nfeDeliveryField');
    if(field) field.hidden=!toggle?.checked;
  };

  byId('nfeNature')?.addEventListener('change',()=>{
    const nature=selectedNature(); if(!nature) return;
    if(byId('nfeOperationType')) byId('nfeOperationType').value=nature.operation_type||'outbound';
    if(byId('nfePurpose')) byId('nfePurpose').value=nature.purpose||'normal';
    if(byId('nfePresence')) byId('nfePresence').value=nature.presence_default||'not_applicable';
    const final=byId('nfeFinalConsumer'); if(final) final.checked=!!nature.final_consumer_default;
    const additional=document.querySelector('[name="additional_info"]');
    const fiscal=document.querySelector('[name="tax_authority_info"]');
    if(additional && !additional.value && nature.additional_info) additional.value=nature.additional_info;
    if(fiscal && !fiscal.value && nature.tax_authority_info) fiscal.value=nature.tax_authority_info;
  });
  byId('nfeCustomer')?.addEventListener('change',()=>{
    updateRecipient();
    const customer=selectedCustomer();
    const final=byId('nfeFinalConsumer');
    if(final && customer) final.checked=!!customer.final_consumer;
  });
  byId('nfeDifferentDelivery')?.addEventListener('change',updateDeliveryVisibility);

  const itemDialog=byId('nfeItemDialog');
  const itemFields={
    index:'nfeItemIndex',product_id:'nfeItemProduct',product_sku:'nfeItemSku',quantity:'nfeItemQuantity',
    unit_price:'nfeItemUnitPrice',cfop:'nfeItemCfop',freight:'nfeItemFreight',insurance:'nfeItemInsurance',
    other_expenses:'nfeItemOther',discount:'nfeItemDiscount',origin:'nfeItemOrigin',ean_gtin:'nfeItemGtin',
    unit:'nfeItemUnit',tax_unit:'nfeItemTaxUnit',ncm:'nfeItemNcm',cest:'nfeItemCest',
    ipi_exception:'nfeItemIpiException',fiscal_benefit_code:'nfeItemBenefit',purchase_order:'nfeItemPurchaseOrder',
    purchase_order_item:'nfeItemPurchaseOrderItem',notes:'nfeItemNotes'
  };

  const setField=(id,value)=>{const el=byId(id);if(el)el.value=value??'';};
  const getField=id=>byId(id)?.value??'';

  const taxFields={
    icms_csosn:'nfeTaxCsosn',icms_cst:'nfeTaxIcmsCst',icms_rate:'nfeTaxIcmsRate',
    simple_credit_rate:'nfeTaxIcmsCreditRate',fcp_rate:'nfeTaxFcpRate',
    ipi_cst:'nfeTaxIpiCst',ipi_rate:'nfeTaxIpiRate',pis_cst:'nfeTaxPisCst',pis_rate:'nfeTaxPisRate',
    cofins_cst:'nfeTaxCofinsCst',cofins_rate:'nfeTaxCofinsRate',ibs_cst:'nfeTaxIbsCst',
    cbs_cst:'nfeTaxCbsCst',tax_classification_code:'nfeTaxClassification'
  };

  const specialFields={
    st:'nfeSpecialSt',import:'nfeSpecialImport',export:'nfeSpecialExport',
    product_specific:'nfeSpecialProduct',traceability:'nfeSpecialTrace',other:'nfeSpecialOther'
  };

  const normalizeProductTax=raw=>({
    icms_csosn:raw.icms_csosn??raw.icms_csosn_default??'',
    icms_cst:raw.icms_cst??raw.icms_cst_default??'',
    icms_rate:raw.icms_rate??raw.icms_rate_default??'',
    simple_credit_rate:raw.simple_credit_rate??'',
    fcp_rate:raw.fcp_rate??raw.fcp_rate_default??'',
    ipi_cst:raw.ipi_cst??raw.ipi_cst_default??'',
    ipi_rate:raw.ipi_rate??'',
    pis_cst:raw.pis_cst??raw.pis_cst_default??'',
    pis_rate:raw.pis_rate??'',
    cofins_cst:raw.cofins_cst??raw.cofins_cst_default??'',
    cofins_rate:raw.cofins_rate??'',
    ibs_cst:raw.ibs_cst??raw.ibs_cst_default??'',
    cbs_cst:raw.cbs_cst??raw.cbs_cst_default??'',
    tax_classification_code:raw.tax_classification_code??''
  });

  const updateTaxSummary=()=>{
    const csosn=getField('nfeTaxCsosn'),cst=getField('nfeTaxIcmsCst');
    const set=(id,text)=>{const el=byId(id);if(el)el.textContent=text||'—';};
    set('nfeTaxIcmsSummary',csosn?('CSOSN '+csosn):(cst?('CST '+cst):'—'));
    set('nfeTaxIpiSummary',getField('nfeTaxIpiCst')?('CST '+getField('nfeTaxIpiCst')):'Sem IPI definido');
    set('nfeTaxPisSummary',getField('nfeTaxPisCst')?('CST '+getField('nfeTaxPisCst')):'—');
    set('nfeTaxCofinsSummary',getField('nfeTaxCofinsCst')?('CST '+getField('nfeTaxCofinsCst')):'—');
    const ibs=getField('nfeTaxIbsCst'),cbs=getField('nfeTaxCbsCst');
    set('nfeTaxIbsCbsSummary',[ibs&&('IBS '+ibs),cbs&&('CBS '+cbs)].filter(Boolean).join(' / ')||'Sem IBS/CBS definido');
  };

  const resetItemDialog=()=>{
    Object.values(itemFields).forEach(id=>setField(id,''));
    setField('nfeItemQuantity','1');
    ['nfeItemFreight','nfeItemInsurance','nfeItemOther','nfeItemDiscount'].forEach(id=>setField(id,'0'));
    Object.values(taxFields).forEach(id=>setField(id,''));
    Object.values(specialFields).forEach(id=>setField(id,''));
    updateTaxSummary();
  };

  const fillFromProduct=product=>{
    if(!product) return;
    setField('nfeItemSku',product.sku);
    setField('nfeItemUnitPrice',product.sale_price);
    setField('nfeItemCfop',resolveCfop());
    setField('nfeItemOrigin',product.origin);
    setField('nfeItemGtin',product.ean_gtin);
    setField('nfeItemUnit',product.unit);
    setField('nfeItemTaxUnit',product.tax_unit||product.unit);
    setField('nfeItemNcm',product.ncm);
    setField('nfeItemCest',product.cest);
    setField('nfeItemIpiException',product.ipi_exception);
    setField('nfeItemBenefit',product.fiscal_benefit_code);
    setField('nfeItemNotes',product.nfe_notes);
    const tax=normalizeProductTax(product.tax_defaults||{});
    Object.entries(taxFields).forEach(([key,id])=>setField(id,tax[key]));
    updateTaxSummary();
  };

  byId('nfeItemProduct')?.addEventListener('change',()=>fillFromProduct(productById(getField('nfeItemProduct'))));
  Object.values(taxFields).forEach(id=>byId(id)?.addEventListener('input',updateTaxSummary));

  const openNewItem=()=>{
    resetItemDialog();
    setField('nfeItemIndex','');
    itemDialog?.showModal();
  };
  byId('nfeAddProduct')?.addEventListener('click',openNewItem);

  const openEditItem=index=>{
    const item=items[index]; if(!item) return;
    resetItemDialog();
    Object.entries(itemFields).forEach(([key,id])=>setField(id,key==='index'?index:item[key]));
    const tax=normalizeProductTax(item.tax_data||{});
    Object.entries(taxFields).forEach(([key,id])=>setField(id,tax[key]));
    Object.entries(specialFields).forEach(([key,id])=>setField(id,item.special_data?.[key]||''));
    updateTaxSummary();
    itemDialog?.showModal();
  };

  byId('nfeSaveItem')?.addEventListener('click',()=>{
    const product=productById(getField('nfeItemProduct'));
    if(!product){byId('nfeItemProduct')?.focus();return;}
    const qty=number(getField('nfeItemQuantity'));
    if(qty<=0){byId('nfeItemQuantity')?.focus();return;}

    const tax={};
    Object.entries(taxFields).forEach(([key,id])=>tax[key]=getField(id));
    const special={};
    Object.entries(specialFields).forEach(([key,id])=>special[key]=getField(id));

    const item={
      product_id:product.id,product_name:product.name,product_sku:getField('nfeItemSku')||product.sku,
      quantity:qty,unit_price:number(getField('nfeItemUnitPrice')),cfop:getField('nfeItemCfop'),
      freight:number(getField('nfeItemFreight')),insurance:number(getField('nfeItemInsurance')),
      other_expenses:number(getField('nfeItemOther')),discount:number(getField('nfeItemDiscount')),
      origin:getField('nfeItemOrigin'),ean_gtin:getField('nfeItemGtin'),unit:getField('nfeItemUnit'),
      tax_unit:getField('nfeItemTaxUnit'),ncm:getField('nfeItemNcm'),cest:getField('nfeItemCest'),
      ipi_exception:getField('nfeItemIpiException'),fiscal_benefit_code:getField('nfeItemBenefit'),
      purchase_order:getField('nfeItemPurchaseOrder'),purchase_order_item:getField('nfeItemPurchaseOrderItem'),
      notes:getField('nfeItemNotes'),tax_data:tax,special_data:special
    };
    item.line_total=itemLineTotal(item);
    const index=getField('nfeItemIndex');
    if(index==='') items.push(item); else items[Number(index)]=item;
    itemDialog?.close();
    renderItems();
  });

  document.addEventListener('click',event=>{
    const edit=event.target.closest('[data-nfe-edit-item]');
    if(edit){openEditItem(Number(edit.dataset.nfeEditItem));return;}
    const remove=event.target.closest('[data-nfe-remove-item]');
    if(remove){items.splice(Number(remove.dataset.nfeRemoveItem),1);renderItems();return;}
    const ref=event.target.closest('[data-nfe-remove-reference]');
    if(ref){references.splice(Number(ref.dataset.nfeRemoveReference),1);renderReferences();return;}
    const dup=event.target.closest('[data-nfe-remove-duplicate]');
    if(dup){duplicates.splice(Number(dup.dataset.nfeRemoveDuplicate),1);renderDuplicates();return;}
    const pay=event.target.closest('[data-nfe-remove-payment]');
    if(pay){payments.splice(Number(pay.dataset.nfeRemovePayment),1);renderPayments();return;}
  });

  byId('nfeSaveReference')?.addEventListener('click',()=>{
    const key=getField('nfeReferenceKey').trim(); if(!key){byId('nfeReferenceKey')?.focus();return;}
    references.push({type:getField('nfeReferenceType'),key});
    setField('nfeReferenceKey',''); byId('nfeReferenceDialog')?.close(); renderReferences();
  });

  byId('nfeSaveDuplicate')?.addEventListener('click',()=>{
    const value=number(getField('nfeDuplicateValue')); if(value<=0){byId('nfeDuplicateValue')?.focus();return;}
    duplicates.push({number:getField('nfeDuplicateNumber'),due_date:getField('nfeDuplicateDue'),value});
    setField('nfeDuplicateNumber','');setField('nfeDuplicateDue','');setField('nfeDuplicateValue','');
    byId('nfeDuplicateDialog')?.close(); renderDuplicates();
  });

  byId('nfeSavePayment')?.addEventListener('click',()=>{
    const amount=number(getField('nfePaymentValue')); if(amount<=0){byId('nfePaymentValue')?.focus();return;}
    payments.push({
      payment_method:getField('nfePaymentMethod'),due_date:getField('nfePaymentDue'),amount,
      integration_type:getField('nfePaymentIntegration'),card_brand:getField('nfePaymentBrand'),
      authorization_code:getField('nfePaymentAuthorization')
    });
    ['nfePaymentDue','nfePaymentValue','nfePaymentIntegration','nfePaymentBrand','nfePaymentAuthorization'].forEach(id=>setField(id,''));
    byId('nfePaymentDialog')?.close(); renderPayments();
  });

  byId('nfeDraftForm')?.addEventListener('submit',syncHidden);

  const mainDialog=byId('nfeEditorDialog');
  mainDialog?.addEventListener('cancel',event=>{
    event.preventDefault();
    window.location.href=mainDialog.querySelector('.close-dialog')?.href||'/fiscal?tab=nfe';
  });

  document.addEventListener('keydown',event=>{
    if(!mainDialog?.open) return;
    if((event.ctrlKey||event.metaKey) && event.key.toLowerCase()==='s'){
      event.preventDefault();syncHidden();byId('nfeDraftForm')?.requestSubmit();return;
    }
    if(event.key==='F4'){
      event.preventDefault();byId('nfeCustomer')?.focus();return;
    }
    if(event.key==='F6'){
      event.preventDefault();if(!itemDialog?.open)openNewItem();return;
    }
    if(event.key==='F8'){
      const validation=byId('nfeValidateForm');
      if(validation){event.preventDefault();syncHidden();validation.requestSubmit();}
    }
  });

  updateRecipient();updateDeliveryVisibility();
  renderItems();renderReferences();renderDuplicates();renderPayments();syncHidden();
})();
