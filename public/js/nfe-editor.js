(()=>{
  const byId=id=>document.getElementById(id);
  const parseJson=id=>{
    const el=byId(id);
    if(!el) return [];
    try{return JSON.parse(el.value||el.textContent||'[]')||[];}catch(_){return [];}
  };
  const money=value=>'R$ '+(Number(value)||0).toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2});
  const number=value=>Number.parseFloat(value||'0')||0;
  const esc=value=>String(value??'').replace(/[&<>"']/g,ch=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch]));

  const products=parseJson('nfe-products-data');
  const customers=parseJson('nfe-customers-data');
  const natures=parseJson('nfe-natures-data');
  const emitterState=(()=>{
    try{return JSON.parse(byId('nfe-emitter-state')?.textContent||'null')||'';}catch(_){return '';}
  })();

  const productById=id=>products.find(item=>String(item.id)===String(id));
  const customerById=id=>customers.find(item=>String(item.id)===String(id));
  const natureById=id=>natures.find(item=>String(item.id)===String(id));

  let items=parseJson('nfeItemsJson');
  let duplicates=parseJson('nfeDuplicatesJson');
  let references=parseJson('nfeReferencesJson');

  const setField=(id,value)=>{
    const field=byId(id);
    if(field) field.value=value??'';
  };
  const setSelect=(id,value)=>{
    const field=byId(id);
    if(!field) return;
    field.value=value??'';
    field.dispatchEvent(new Event('change',{bubbles:true}));
  };
  const getField=id=>byId(id)?.value??'';

  const syncHidden=()=>{
    if(byId('nfeItemsJson')) byId('nfeItemsJson').value=JSON.stringify(items);
    if(byId('nfeDuplicatesJson')) byId('nfeDuplicatesJson').value=JSON.stringify(duplicates);
    if(byId('nfeReferencesJson')) byId('nfeReferencesJson').value=JSON.stringify(references);
  };

  const selectedNature=()=>natureById(getField('nfeNature'));
  const selectedCustomer=()=>customerById(getField('nfeCustomer'));
  const operationType=()=>getField('nfeOperationType')||'outbound';

  const isForeign=customer=>{
    if(!customer) return false;
    const country=String(customer.country_code||'').replace(/\D/g,'');
    return customer.state==='EX' || (country!=='' && country!=='1058');
  };

  const destinationFor=customer=>{
    if(!customer) return 'Automático';
    if(isForeign(customer)) return 'Exterior';
    if(customer.state && emitterState && customer.state!==emitterState) return 'Interestadual';
    return 'Interna';
  };

  const purposeLabel=value=>({
    normal:'1 - NF-e normal',
    complementary:'2 - NF-e complementar',
    adjustment:'3 - NF-e de ajuste',
    return:'4 - Devolução de mercadoria'
  }[value]||'—');

  const normalizeTax=raw=>{
    raw=raw&&typeof raw==='object'?raw:{};
    return {
      cfop_outbound_internal:raw.cfop_outbound_internal??raw.cfop_saida_estadual??'',
      cfop_outbound_interstate:raw.cfop_outbound_interstate??raw.cfop_saida_inter_estadual??'',
      cfop_inbound_internal:raw.cfop_inbound_internal??raw.cfop_entrada_estadual??'',
      cfop_inbound_interstate:raw.cfop_inbound_interstate??raw.cfop_entrada_inter_estadual??'',

      icms_csosn:raw.icms_csosn??raw.icms_csosn_default??raw.CST_CSOSN??'',
      icms_cst:raw.icms_cst??raw.icms_cst_default??'',
      icms_csosn_export:raw.icms_csosn_export??raw.CST_CSOSN_EXP??'',
      icms_rate:raw.icms_rate??raw.icms_rate_default??raw.perc_icms??'',
      base_reduction_rate:raw.base_reduction_rate??raw.perc_reducao??'',
      simple_credit_rate:raw.simple_credit_rate??'',
      mod_bc:raw.mod_bc??raw.modBC??'',
      mod_bc_st:raw.mod_bc_st??raw.modBCST??'',
      icms_st_rate:raw.icms_st_rate??raw.pICMSST??'',
      mva_rate:raw.mva_rate??raw.perc_mva??'',

      pis_cst:raw.pis_cst??raw.pis_cst_default??raw.CST_PIS??'',
      pis_rate:raw.pis_rate??raw.perc_pis??'',
      cofins_cst:raw.cofins_cst??raw.cofins_cst_default??raw.CST_COFINS??'',
      cofins_rate:raw.cofins_rate??raw.perc_cofins??'',
      ipi_cst:raw.ipi_cst??raw.ipi_cst_default??raw.CST_IPI??'',
      ipi_rate:raw.ipi_rate??raw.perc_ipi??'',
      ipi_enq:raw.ipi_enq??raw.cenq_ipi??'999',
      iss_rate:raw.iss_rate??raw.iss_rate_default??raw.perc_iss??'',
      service_list_code:raw.service_list_code??raw.cListServ??'',

      interstate_icms_rate:raw.interstate_icms_rate??raw.perc_icms_interestadual??'',
      internal_icms_rate:raw.internal_icms_rate??raw.perc_icms_interno??'',
      fcp_interstate_rate:raw.fcp_interstate_rate??raw.fcp_rate_default??raw.perc_fcp_interestadual??'',

      tax_quantity_factor:raw.tax_quantity_factor??raw.quantidade_tributavel??'1',
      ibs_cst:raw.ibs_cst??raw.ibs_cst_default??'',
      cbs_cst:raw.cbs_cst??raw.cbs_cst_default??'',
      tax_classification_code:raw.tax_classification_code??''
    };
  };

  const resolveCfop=(taxData={})=>{
    const nature=selectedNature();
    const customer=selectedCustomer();
    const type=operationType();
    const foreign=isForeign(customer);
    const interstate=!!(customer?.state && emitterState && customer.state!==emitterState && !foreign);

    let natureCfop='';
    if(nature){
      if(foreign && nature.cfop_foreign){
        natureCfop=nature.cfop_foreign;
      }else if(type==='inbound'){
        natureCfop=interstate
          ? (nature.cfop_inbound_interstate||nature.cfop_inbound_internal||'')
          : (nature.cfop_inbound_internal||'');
      }else{
        natureCfop=interstate
          ? (nature.cfop_outbound_interstate||nature.cfop_outbound_internal||'')
          : (nature.cfop_outbound_internal||'');
      }
    }

    const productKey=type==='inbound'
      ? (interstate?'cfop_inbound_interstate':'cfop_inbound_internal')
      : (interstate?'cfop_outbound_interstate':'cfop_outbound_internal');
    const productCfop=String(taxData?.[productKey]||'').trim();

    if(nature?.override_product_cfop) return natureCfop||productCfop;
    return productCfop||natureCfop;
  };

  const updateGeneralPreview=()=>{
    const nature=selectedNature();
    const customer=selectedCustomer();

    const purpose=byId('nfePurposePreview');
    if(purpose) purpose.textContent=purposeLabel(nature?.purpose);

    const destination=byId('nfeDestinationPreview');
    if(destination) destination.textContent=destinationFor(customer);

    const finalConsumer=byId('nfeFinalConsumerPreview');
    if(finalConsumer) finalConsumer.textContent=customer ? (customer.final_consumer?'Sim':'Não') : 'Automático pelo cliente';

    const summary=byId('nfeRecipientSummary');
    if(summary){
      if(!customer){
        summary.textContent='Selecione um destinatário.';
      }else{
        const address=[customer.address,customer.address_number,customer.district,customer.city,customer.state].filter(Boolean).join(', ');
        const document=customer.document||customer.foreign_id||'—';
        summary.innerHTML=
          '<strong>'+esc(customer.name)+'</strong><small>'+
          'CPF/CNPJ/ID: '+esc(document)+
          ' · IE: '+esc(customer.state_registration||'—')+
          ' · Município IBGE: '+esc(customer.city_ibge_code||'—')+
          ' · '+esc(address||'Endereço não informado')+
          '</small>';
      }
    }
  };

  const recalcItemCfops=()=>{
    const nature=selectedNature();
    items=items.map(item=>{
      const tax=normalizeTax(item.tax_data||{});
      if(nature?.override_product_cfop || !item.cfop){
        item.cfop=resolveCfop(tax);
      }
      return item;
    });
    renderItems();
  };

  byId('nfeNature')?.addEventListener('change',()=>{
    const nature=selectedNature();
    if(nature){
      setSelect('nfeOperationType',nature.operation_type||'outbound');
      const additional=document.querySelector('[name="additional_info"]');
      if(additional && !additional.value && nature.additional_info) additional.value=nature.additional_info;
    }
    updateGeneralPreview();
    recalcItemCfops();
  });

  byId('nfeCustomer')?.addEventListener('change',()=>{
    updateGeneralPreview();
    recalcItemCfops();
  });

  byId('nfeOperationType')?.addEventListener('change',()=>{
    recalcItemCfops();
  });

  const itemTotal=item=>number(item.quantity)*number(item.unit_price);

  const totals=()=>{
    let productsTotal=0;
    let ipi=0;
    items.forEach(item=>{
      const base=itemTotal(item);
      productsTotal+=base;
      const tax=normalizeTax(item.tax_data||{});
      const ipiRate=Math.max(0,number(tax.ipi_rate));
      if(ipiRate>0) ipi+=base*(ipiRate/100);
    });

    const freight=Math.max(0,number(getField('nfeFreightValue')));
    const discount=Math.max(0,number(getField('nfeDiscount')));
    const surcharge=Math.max(0,number(getField('nfeSurcharge')));
    const total=Math.max(0,productsTotal+freight+surcharge+ipi-discount);
    return {products:productsTotal,freight,discount,surcharge,ipi,total};
  };

  const renderTotals=()=>{
    const values=totals();
    const targets={
      nfeTotalProducts:values.products,
      nfeTotalFreight:values.freight,
      nfeTotalIpi:values.ipi,
      nfeTotalDiscount:values.discount,
      nfeTotalSurcharge:values.surcharge,
      nfeTotalInvoice:values.total,
      nfeFooterTotal:values.total,
    };
    Object.entries(targets).forEach(([id,value])=>{
      const element=byId(id);
      if(element) element.textContent=money(value);
    });
  };

  ['nfeFreightValue','nfeDiscount','nfeSurcharge'].forEach(id=>{
    byId(id)?.addEventListener('input',renderTotals);
  });

  const statusTax=item=>{
    const tax=normalizeTax(item.tax_data||{});
    return tax.icms_csosn
      ? 'CSOSN '+tax.icms_csosn
      : (tax.icms_cst ? 'CST '+tax.icms_cst : '—');
  };

  const renderItems=()=>{
    const body=byId('nfeItemsBody');
    if(!body) return;
    if(!items.length){
      body.innerHTML='<tr><td colspan="8" class="empty-cell">Nenhum produto adicionado.</td></tr>';
    }else{
      body.innerHTML=items.map((item,index)=>`
        <tr>
          <td>
            <strong class="table-title">${esc(item.product_name||'')}</strong>
            <small class="table-subtitle">${esc(item.product_sku||'')}</small>
          </td>
          <td>${item.cfop?'<span class="code-tag">'+esc(item.cfop)+'</span>':'—'}</td>
          <td>${esc(item.ncm||'—')}</td>
          <td>${esc(statusTax(item))}</td>
          <td class="nowrap">${number(item.quantity).toLocaleString('pt-BR',{maximumFractionDigits:4})}</td>
          <td class="nowrap">${money(item.unit_price)}</td>
          <td class="price-strong nowrap">${money(itemTotal(item))}</td>
          <td class="action-cell">
            <div class="row-actions">
              <button type="button" class="btn-icon" data-nfe-edit-item="${index}" data-tooltip="Editar item">✎</button>
              <button type="button" class="btn-icon row-action-danger" data-nfe-remove-item="${index}" data-tooltip="Remover item">×</button>
            </div>
          </td>
        </tr>
      `).join('');
    }
    syncHidden();
    renderTotals();
  };

  const renderReferences=()=>{
    const body=byId('nfeReferenceBody');
    if(!body) return;
    body.innerHTML=references.length
      ? references.map((ref,index)=>`
          <tr>
            <td><span class="code-tag">${esc(ref.key||'')}</span></td>
            <td class="action-cell"><button type="button" class="btn-icon row-action-danger" data-nfe-remove-reference="${index}">×</button></td>
          </tr>
        `).join('')
      : '<tr><td colspan="2" class="empty-cell">Nenhuma NF-e referenciada.</td></tr>';
    syncHidden();
  };

  const renderDuplicates=()=>{
    const body=byId('nfeDuplicateBody');
    if(!body) return;
    body.innerHTML=duplicates.length
      ? duplicates.map((dup,index)=>`
          <tr>
            <td>${esc(dup.number||String(index+1))}</td>
            <td>${esc(dup.payment_type||'—')}</td>
            <td class="nowrap">${esc(dup.due_date||'—')}</td>
            <td class="price-strong nowrap">${money(dup.value)}</td>
            <td class="action-cell"><button type="button" class="btn-icon row-action-danger" data-nfe-remove-duplicate="${index}">×</button></td>
          </tr>
        `).join('')
      : '<tr><td colspan="5" class="empty-cell">Nenhuma duplicata informada.</td></tr>';
    syncHidden();
  };

  const updatePaymentVisibility=()=>{
    const type=getField('nfePaymentType');
    const cardPanel=byId('nfeCardPanel');
    const otherField=byId('nfeOtherPaymentField');
    if(cardPanel) cardPanel.hidden=!['03','04'].includes(type);
    if(otherField) otherField.hidden=type!=='99';
  };
  byId('nfePaymentType')?.addEventListener('change',updatePaymentVisibility);

  const itemDialog=byId('nfeItemDialog');

  const itemFields={
    index:'nfeItemIndex',
    product_id:'nfeItemProduct',
    product_sku:'nfeItemSku',
    quantity:'nfeItemQuantity',
    unit_price:'nfeItemUnitPrice',
    purchase_order:'nfeItemPurchaseOrder',
    purchase_order_item:'nfeItemPurchaseOrderItem',
    notes:'nfeItemNotes',
    ncm:'nfeItemNcm',
    cest:'nfeItemCest',
    fiscal_benefit_code:'nfeItemBenefit',
    ipi_exception:'nfeItemIpiException',
    origin:'nfeItemOrigin',
    ean_gtin:'nfeItemGtin',
    unit:'nfeItemUnit',
    tax_unit:'nfeItemTaxUnit',
    cfop:'nfeItemCfop',
  };

  const taxFields={
    tax_quantity_factor:'nfeTaxQuantityFactor',
    cfop_outbound_internal:'nfeCfopOutboundInternal',
    cfop_outbound_interstate:'nfeCfopOutboundInterstate',
    cfop_inbound_internal:'nfeCfopInboundInternal',
    cfop_inbound_interstate:'nfeCfopInboundInterstate',

    icms_csosn:'nfeTaxCsosn',
    icms_cst:'nfeTaxIcmsCst',
    icms_csosn_export:'nfeTaxCsosnExport',
    icms_rate:'nfeTaxIcmsRate',
    base_reduction_rate:'nfeTaxBaseReduction',
    simple_credit_rate:'nfeTaxSimpleCredit',
    mod_bc:'nfeTaxModBc',
    mod_bc_st:'nfeTaxModBcSt',
    icms_st_rate:'nfeTaxIcmsStRate',
    mva_rate:'nfeTaxMvaRate',

    pis_cst:'nfeTaxPisCst',
    pis_rate:'nfeTaxPisRate',
    cofins_cst:'nfeTaxCofinsCst',
    cofins_rate:'nfeTaxCofinsRate',
    ipi_cst:'nfeTaxIpiCst',
    ipi_rate:'nfeTaxIpiRate',
    ipi_enq:'nfeTaxIpiEnq',
    iss_rate:'nfeTaxIssRate',
    service_list_code:'nfeTaxServiceListCode',

    interstate_icms_rate:'nfeTaxInterstateRate',
    internal_icms_rate:'nfeTaxInternalRate',
    fcp_interstate_rate:'nfeTaxFcpRate',

    ibs_cst:'nfeTaxIbsCst',
    cbs_cst:'nfeTaxCbsCst',
    tax_classification_code:'nfeTaxClassification',
  };

  const specialFields={
    anp_code:'nfeSpecialAnpCode',
    anp_description:'nfeSpecialAnpDescription',
    glp_rate:'nfeSpecialGlp',
    gnn_rate:'nfeSpecialGnn',
    gni_rate:'nfeSpecialGni',
    starting_value:'nfeSpecialStartingValue',
    batch:'nfeSpecialBatch',
    expiry:'nfeSpecialExpiry',
    renavam:'nfeSpecialRenavam',
    chassis:'nfeSpecialChassis',
    plate:'nfeSpecialPlate',
    fuel:'nfeSpecialFuel',
    year_model:'nfeSpecialYearModel',
    vehicle_color:'nfeSpecialVehicleColor',
  };

  const updateItemSubtotal=()=>{
    const subtotal=byId('nfeItemSubtotal');
    if(subtotal) subtotal.textContent=money(number(getField('nfeItemQuantity'))*number(getField('nfeItemUnitPrice')));
  };
  byId('nfeItemQuantity')?.addEventListener('input',updateItemSubtotal);
  byId('nfeItemUnitPrice')?.addEventListener('input',updateItemSubtotal);

  const switchItemTab=target=>{
    document.querySelectorAll('[data-nfe-item-tab]').forEach(button=>{
      button.classList.toggle('active',button.dataset.nfeItemTab===target);
    });
    document.querySelectorAll('[data-nfe-item-panel]').forEach(panel=>{
      const active=panel.dataset.nfeItemPanel===target;
      panel.classList.toggle('active',active);
      panel.hidden=!active;
    });
  };
  document.querySelectorAll('[data-nfe-item-tab]').forEach(button=>{
    button.addEventListener('click',()=>switchItemTab(button.dataset.nfeItemTab));
  });

  const clearItemDialog=()=>{
    Object.values(itemFields).forEach(id=>setField(id,''));
    Object.values(taxFields).forEach(id=>setField(id,''));
    Object.values(specialFields).forEach(id=>setField(id,''));
    setField('nfeItemQuantity','1');
    setField('nfeTaxQuantityFactor','1');
    setField('nfeTaxIpiEnq','999');
    const petroleum=byId('nfeSpecialPetroleum');
    if(petroleum) petroleum.checked=false;
    updateItemSubtotal();
    switchItemTab('item-general');
  };

  const productDefaults=product=>{
    const tax=normalizeTax(product?.tax_defaults||{});
    return {
      product_id:product?.id||'',
      product_name:product?.name||'',
      product_sku:product?.sku||'',
      quantity:1,
      unit_price:number(product?.sale_price),
      cfop:resolveCfop(tax),
      origin:product?.origin??'0',
      ean_gtin:product?.ean_gtin||'',
      unit:product?.unit||'UN',
      tax_unit:product?.tax_unit||product?.unit||'UN',
      ncm:product?.ncm||'',
      cest:product?.cest||'',
      ipi_exception:product?.ipi_exception||'',
      fiscal_benefit_code:product?.fiscal_benefit_code||'',
      purchase_order:'',
      purchase_order_item:'',
      notes:product?.nfe_notes||'',
      tax_data:tax,
      special_data:{
        petroleum_derived:!!(product?.tax_defaults?.petroleum_derived??product?.tax_defaults?.derivado_petroleo),
        anp_code:product?.tax_defaults?.anp_code??product?.tax_defaults?.codigo_anp??'',
        anp_description:product?.tax_defaults?.anp_description??product?.tax_defaults?.descricao_anp??'',
        glp_rate:product?.tax_defaults?.glp_rate??product?.tax_defaults?.perc_glp??'',
        gnn_rate:product?.tax_defaults?.gnn_rate??product?.tax_defaults?.perc_gnn??'',
        gni_rate:product?.tax_defaults?.gni_rate??product?.tax_defaults?.perc_gni??'',
        starting_value:product?.tax_defaults?.starting_value??product?.tax_defaults?.valor_partida??'',
      }
    };
  };

  const populateItemDialog=item=>{
    clearItemDialog();
    Object.entries(itemFields).forEach(([key,id])=>{
      if(key==='index') return;
      if(key==='product_id') setSelect(id,item.product_id);
      else if(key==='origin') setSelect(id,item.origin??'0');
      else setField(id,item[key]);
    });
    const tax=normalizeTax(item.tax_data||{});
    Object.entries(taxFields).forEach(([key,id])=>setField(id,tax[key]));
    const special=item.special_data||{};
    Object.entries(specialFields).forEach(([key,id])=>setField(id,special[key]));
    const petroleum=byId('nfeSpecialPetroleum');
    if(petroleum) petroleum.checked=!!special.petroleum_derived;
    updateItemSubtotal();
  };

  const openNewItem=()=>{
    clearItemDialog();
    setField('nfeItemIndex','');
    itemDialog?.showModal();
  };

  const openEditItem=index=>{
    const item=items[index];
    if(!item) return;
    populateItemDialog(item);
    setField('nfeItemIndex',index);
    itemDialog?.showModal();
  };

  const fillItemFromProduct=product=>{
    if(!product) return;
    const defaults=productDefaults(product);
    populateItemDialog(defaults);
    setField('nfeItemIndex','');
  };

  byId('nfeItemProduct')?.addEventListener('change',()=>{
    const product=productById(getField('nfeItemProduct'));
    if(product) fillItemFromProduct(product);
  });

  byId('nfeAddProduct')?.addEventListener('click',openNewItem);

  byId('nfeQuickProduct')?.addEventListener('change',()=>{
    const product=productById(getField('nfeQuickProduct'));
    setField('nfeQuickUnitPrice',product?.sale_price??'');
  });

  byId('nfeQuickAdd')?.addEventListener('click',()=>{
    const product=productById(getField('nfeQuickProduct'));
    const quantity=number(getField('nfeQuickQuantity'));
    if(!product){byId('nfeQuickProduct')?.focus();return;}
    if(quantity<=0){byId('nfeQuickQuantity')?.focus();return;}

    const item=productDefaults(product);
    item.quantity=quantity;
    item.unit_price=number(getField('nfeQuickUnitPrice')||product.sale_price);
    items.push(item);
    setSelect('nfeQuickProduct','');
    setField('nfeQuickQuantity','1');
    setField('nfeQuickUnitPrice','');
    renderItems();
  });

  byId('nfeSaveItem')?.addEventListener('click',()=>{
    const product=productById(getField('nfeItemProduct'));
    const quantity=number(getField('nfeItemQuantity'));
    if(!product){byId('nfeItemProduct')?.focus();return;}
    if(quantity<=0){byId('nfeItemQuantity')?.focus();return;}

    const tax={};
    Object.entries(taxFields).forEach(([key,id])=>tax[key]=getField(id));

    const special={};
    Object.entries(specialFields).forEach(([key,id])=>special[key]=getField(id));
    special.petroleum_derived=!!byId('nfeSpecialPetroleum')?.checked;

    const item={
      product_id:product.id,
      product_name:product.name,
      product_sku:getField('nfeItemSku')||product.sku,
      quantity,
      unit_price:number(getField('nfeItemUnitPrice')),
      cfop:getField('nfeItemCfop')||resolveCfop(tax),
      origin:getField('nfeItemOrigin'),
      ean_gtin:getField('nfeItemGtin'),
      unit:getField('nfeItemUnit'),
      tax_unit:getField('nfeItemTaxUnit'),
      ncm:getField('nfeItemNcm'),
      cest:getField('nfeItemCest'),
      ipi_exception:getField('nfeItemIpiException'),
      fiscal_benefit_code:getField('nfeItemBenefit'),
      purchase_order:getField('nfeItemPurchaseOrder'),
      purchase_order_item:getField('nfeItemPurchaseOrderItem'),
      notes:getField('nfeItemNotes'),
      tax_data:tax,
      special_data:special,
    };

    const index=getField('nfeItemIndex');
    if(index==='') items.push(item);
    else items[Number(index)]=item;

    itemDialog?.close();
    renderItems();
  });

  byId('nfeSaveReference')?.addEventListener('click',()=>{
    const key=getField('nfeReferenceKey').replace(/\D/g,'');
    if(key.length!==44){
      byId('nfeReferenceKey')?.focus();
      window.NextorNotify?.('A chave da NF-e referenciada deve possuir 44 dígitos.',{type:'warning',title:'NF-e referenciada'});
      return;
    }
    references.push({key});
    setField('nfeReferenceKey','');
    byId('nfeReferenceDialog')?.close();
    renderReferences();
  });

  byId('nfeSaveDuplicate')?.addEventListener('click',()=>{
    const value=number(getField('nfeDuplicateValue'));
    if(value<=0){byId('nfeDuplicateValue')?.focus();return;}
    duplicates.push({
      number:getField('nfeDuplicateNumber'),
      payment_type:getField('nfeDuplicatePaymentType'),
      due_date:getField('nfeDuplicateDue'),
      value,
    });
    setField('nfeDuplicateNumber','');
    setField('nfeDuplicateDue','');
    setField('nfeDuplicateValue','');
    byId('nfeDuplicateDialog')?.close();
    renderDuplicates();
  });

  document.addEventListener('click',event=>{
    const edit=event.target.closest('[data-nfe-edit-item]');
    if(edit){openEditItem(Number(edit.dataset.nfeEditItem));return;}

    const removeItem=event.target.closest('[data-nfe-remove-item]');
    if(removeItem){items.splice(Number(removeItem.dataset.nfeRemoveItem),1);renderItems();return;}

    const removeReference=event.target.closest('[data-nfe-remove-reference]');
    if(removeReference){references.splice(Number(removeReference.dataset.nfeRemoveReference),1);renderReferences();return;}

    const removeDuplicate=event.target.closest('[data-nfe-remove-duplicate]');
    if(removeDuplicate){duplicates.splice(Number(removeDuplicate.dataset.nfeRemoveDuplicate),1);renderDuplicates();}
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
      event.preventDefault();
      syncHidden();
      byId('nfeDraftForm')?.requestSubmit();
      return;
    }

    if(event.key==='F4'){
      event.preventDefault();
      byId('nfeCustomer')?.focus();
      return;
    }

    if(event.key==='F6'){
      event.preventDefault();
      if(!itemDialog?.open) openNewItem();
      return;
    }

    if(event.key==='F8'){
      const form=byId('nfeDraftForm');
      const button=byId('nfeValidateCurrent');
      if(form && button){
        event.preventDefault();
        syncHidden();
        form.requestSubmit(button);
      }
    }
  });

  updateGeneralPreview();
  updatePaymentVisibility();
  renderItems();
  renderReferences();
  renderDuplicates();
  syncHidden();
})();
