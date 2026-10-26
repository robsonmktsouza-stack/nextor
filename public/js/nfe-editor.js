(()=>{
  const byId=id=>document.getElementById(id);

  const readOnly=()=>document.getElementById('nfeEditorDialog')?.dataset.nfeReadonly==='1';
  const modalIsOpen=modal=>!!modal && !modal.hidden && modal.classList.contains('is-open');

  const openModal=modal=>{
    if(!modal) return;
    modal.hidden=false;
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden','false');
    document.body.classList.add('nfe-modal-open');

    const focusable=modal.querySelector(
      '[autofocus], .dialog-body input:not([type="hidden"]):not(:disabled), .dialog-body select:not(:disabled), .dialog-body textarea:not(:disabled), input:not([type="hidden"]):not(:disabled), select:not(:disabled), textarea:not(:disabled), button:not(:disabled), a[href]'
    );
    window.setTimeout(()=>focusable?.focus(),0);
  };

  const closeModal=modal=>{
    if(!modal) return;
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden','true');
    modal.hidden=true;

    if(!document.querySelector('[data-nfe-modal].is-open')){
      document.body.classList.remove('nfe-modal-open');
    }
  };

  document.addEventListener('click',event=>{
    const opener=event.target.closest('[data-nfe-modal-open]');
    if(opener){
      event.preventDefault();
      if(readOnly()&&!opener.hasAttribute('data-nfe-allow-readonly'))return;
      openModal(byId(opener.getAttribute('data-nfe-modal-open')));
      return;
    }

    const closer=event.target.closest('[data-nfe-modal-close]');
    if(closer){
      event.preventDefault();
      closeModal(closer.closest('[data-nfe-modal]'));
    }
  });

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
  const taxDefaults=parseJson('nfe-tax-defaults-data');
  const emitterCrt=String(parseJson('nfe-emitter-crt')||'');
  const emitterState=(()=>{
    try{return JSON.parse(byId('nfe-emitter-state')?.textContent||'null')||'';}catch(_){return '';}
  })();

  document.addEventListener('lumeron:catalog-created',event=>{
    const {kind,item}=event.detail||{};
    if(!item)return;
    if(kind==='products'&&!products.some(p=>String(p.id)===String(item.id)))products.push(item);
    if(kind==='customers'&&!customers.some(c=>String(c.id)===String(item.id)))customers.push(item);
  });
  document.addEventListener('lumeron:catalog-updated',event=>{
    const {kind,item}=event.detail||{};
    if(kind!=='customers'||!item?.id)return;
    const index=customers.findIndex(customer=>String(customer.id)===String(item.id));
    if(index<0)return;
    // Dados de entrega alternativos não são alterados pelo cadastro rápido.
    customers[index]={...customers[index],...item};
    updateRecipientPreview();
  });
  const productById=id=>products.find(item=>String(item.id)===String(id));
  const customerById=id=>customers.find(item=>String(item.id)===String(id));
  const natureById=id=>natures.find(item=>String(item.id)===String(id));

  let items=parseJson('nfeItemsJson');
  let duplicates=parseJson('nfeDuplicatesJson');
  let extraPayments=parseJson('nfePaymentsJson');
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
    if(byId('nfePaymentsJson')) byId('nfePaymentsJson').value=JSON.stringify(extraPayments);
    if(byId('nfeReferencesJson')) byId('nfeReferencesJson').value=JSON.stringify(references);
  };

  const selectedNature=()=>natureById(getField('nfeNature'));
  const selectedCustomer=()=>customerById(getField('nfeCustomer'));
  const operationType=()=>getField('nfeOperationType')||'outbound';
  const destinationType=()=>getField('nfeDestination')||'internal';

  const normalizeTax=raw=>{
    raw=raw&&typeof raw==='object'?raw:{};
    return {
      ...raw,
      cfop_outbound_internal:raw.cfop_outbound_internal??raw.cfop_saida_estadual??'',
      cfop_outbound_interstate:raw.cfop_outbound_interstate??raw.cfop_saida_inter_estadual??'',
      cfop_inbound_internal:raw.cfop_inbound_internal??raw.cfop_entrada_estadual??'',
      cfop_inbound_interstate:raw.cfop_inbound_interstate??raw.cfop_entrada_inter_estadual??'',

      icms_csosn:raw.icms_csosn??raw.icms_csosn_default??raw.CST_CSOSN??'',
      icms_cst:raw.icms_cst??raw.icms_cst_default??'',
      icms_csosn_export:raw.icms_csosn_export??raw.CST_CSOSN_EXP??'',
      icms_csosn_inbound:raw.icms_csosn_inbound??raw.CST_CSOSN_entrada??'',
      icms_cst_inbound:raw.icms_cst_inbound??'',
      icms_rate:raw.icms_rate??raw.icms_rate_default??raw.perc_icms??'',
      base_reduction_rate:raw.icms_base_reduction??raw.base_reduction_rate??raw.perc_reducao??'',
      simple_credit_rate:raw.icms_credit_rate??raw.simple_credit_rate??'',
      mod_bc:raw.icms_base_mode??raw.mod_bc??raw.modBC??'',
      mod_bc_st:raw.icms_st_base_mode??raw.mod_bc_st??raw.modBCST??'',
      icms_st_rate:raw.icms_st_rate??raw.pICMSST??'',
      mva_rate:raw.icms_st_mva??raw.mva_rate??raw.perc_mva??'',

      pis_cst:raw.pis_cst??raw.pis_cst_default??raw.CST_PIS??'',
      pis_rate:raw.pis_rate??raw.perc_pis??'',
      pis_cst_inbound:raw.pis_cst_inbound??raw.CST_PIS_entrada??'',
      cofins_cst:raw.cofins_cst??raw.cofins_cst_default??raw.CST_COFINS??'',
      cofins_rate:raw.cofins_rate??raw.perc_cofins??'',
      cofins_cst_inbound:raw.cofins_cst_inbound??raw.CST_COFINS_entrada??'',
      ipi_cst:raw.ipi_cst??raw.ipi_cst_default??raw.CST_IPI??'',
      ipi_rate:raw.ipi_rate??raw.perc_ipi??'',
      ipi_cst_inbound:raw.ipi_cst_inbound??raw.CST_IPI_entrada??'',
      ipi_enq:raw.ipi_enq??raw.cenq_ipi??'',
      iss_rate:raw.iss_rate??raw.iss_rate_default??raw.perc_iss??'',
      service_list_code:raw.service_list_code??raw.cListServ??'',

      interstate_icms_rate:raw.difal_interstate_rate??raw.interstate_icms_rate??raw.perc_icms_interestadual??'',
      internal_icms_rate:raw.difal_internal_rate??raw.internal_icms_rate??raw.perc_icms_interno??'',
      fcp_interstate_rate:raw.difal_fcp_rate??raw.fcp_interstate_rate??raw.fcp_rate_default??raw.perc_fcp_interestadual??'',

      tax_quantity_factor:raw.tax_quantity_factor??raw.quantidade_tributavel??'1',
      ibs_cst:raw.ibs_cst??raw.ibs_cst_default??'',
      cbs_cst:raw.cbs_cst??raw.cbs_cst_default??'',
      tax_classification_code:raw.tax_classification_code??raw.ibs_cbs_class??'',
      ibs_uf_rate:raw.ibs_uf_rate??'',
      ibs_municipal_rate:raw.ibs_municipal_rate??'',
      cbs_rate:raw.cbs_rate??'',
      ibs_uf_reduction_rate:raw.ibs_uf_reduction_rate??'',
      ibs_municipal_reduction_rate:raw.ibs_municipal_reduction_rate??'',
      cbs_reduction_rate:raw.cbs_reduction_rate??''
    };
  };

  const resolveCfop=(taxData={})=>{
    const nature=selectedNature();
    const type=operationType();
    const destination=destinationType();
    const foreign=destination==='foreign';
    const interstate=destination==='interstate';

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

    const productKey=foreign?(type==='inbound'?'cfop_inbound_foreign':'cfop_outbound_foreign'):
      type==='inbound'?(interstate?'cfop_inbound_interstate':'cfop_inbound_internal'):
        (interstate?'cfop_outbound_interstate':'cfop_outbound_internal');
    const productCfop=String(taxData?.[productKey]||(foreign?taxData?.cfop_foreign:'')||taxData?.cfop||'').trim();

    if(nature?.override_product_cfop) return natureCfop||productCfop;
    return productCfop||natureCfop;
  };

  const updateRecipientPreview=()=>{
    const customer=selectedCustomer();
    const summary=byId('nfeRecipientSummary');
    const editButton=byId('nfeEditCustomer');
    if(editButton)editButton.hidden=!customer||readOnly();
    if(!summary) return;
    if(!customer){
      summary.textContent='Selecione um destinatário.';
      return;
    }

    const address=[customer.address,customer.address_number,customer.district,customer.city,customer.state].filter(Boolean).join(', ');
    const document=customer.document||customer.foreign_id||'—';
    // Informações separadas para leitura rápida, sem texto fiscal
    // comprido numa única linha. Conteúdo dinâmico sempre escapado.
    summary.innerHTML=
      '<strong>'+esc(customer.name)+'</strong>'+
      '<span class="nfe-recipient-meta">'+
        '<span>CPF/CNPJ: '+esc(document)+'</span>'+
        '<span>IE: '+esc(customer.state_registration||'—')+'</span>'+
        '<span>Município IBGE: '+esc(customer.city_ibge_code||'—')+'</span>'+
      '</span>'+
      '<span class="nfe-recipient-address">'+esc(address||'Endereço não informado')+'</span>';
  };

  byId('nfeEditCustomer')?.addEventListener('click',()=>{
    if(readOnly())return;
    const customer=selectedCustomer();
    if(!customer)return;
    window.LumeronCatalog?.edit('customers',{
      item:customer,
      onSelect:updated=>{
        // Mantém a NF-e aberta, com produtos, descontos e observações
        // intactos; atualiza apenas o nome exibido e o resumo cadastral.
        const option=[...byId('nfeCustomer').options]
          .find(option=>option.value===String(updated.id));
        if(option){
          option.textContent=updated.name+(updated.document?' — '+updated.document:'');
          option.dataset.name=updated.name;
        }
        updateRecipientPreview();
        // A atualização do cadastro invalida a conferência fiscal anterior.
        // O operador mantém o rascunho e precisa validá-lo novamente.
        const emitButton=byId('nfeSubmitEmission');
        if(emitButton)emitButton.disabled=true;
        window.NextorNotify?.('Dados do cliente atualizados. Confira e valide a NF-e antes de emitir.',{
          type:'success',title:'Destinatário'
        });
      },
    });
  });

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


  // Pesquisa de naturezas: mantém o select original como fonte de verdade
  // para validação, envio do formulário e regras fiscais existentes.
  (() => {
    const native=byId('nfeNature');
    const search=byId('nfeNatureSearch');
    const list=byId('nfeNatureOptions');
    const root=search?.closest('[data-nfe-nature-combobox]');
    if(!native||!search||!list||!root)return;
    const options=Array.from(native.options).filter(option=>option.value);
    const normalize=value=>String(value||'').normalize('NFD').replace(/[\\u0300-\\u036f]/g,'').toLowerCase().trim();
    const selectedText=()=>native.selectedOptions[0]?.value?native.selectedOptions[0].textContent.trim():'';
    const close=()=>{list.hidden=true;search.setAttribute('aria-expanded','false');};
    const render=()=>{
      const term=normalize(search.value);
      const matching=options.filter(option=>normalize(option.textContent).includes(term)).slice(0,80);
      list.replaceChildren();
      if(!matching.length){
        const empty=document.createElement('div');
        empty.className='nfe-nature-empty';
        empty.textContent='Nenhuma natureza encontrada.';
        list.appendChild(empty);
      }
      matching.forEach(option=>{
        const button=document.createElement('button');
        button.type='button';
        button.className='nfe-nature-option';
        button.setAttribute('role','option');
        button.setAttribute('aria-selected',String(native.value===option.value));
        button.textContent=option.textContent.trim();
        button.addEventListener('mousedown',event=>event.preventDefault());
        button.addEventListener('click',()=>{
          native.value=option.value;
          search.value=option.textContent.trim();
          native.dispatchEvent(new Event('change',{bubbles:true}));
          close();
        });
        list.appendChild(button);
      });
      list.hidden=false;
      search.setAttribute('aria-expanded','true');
    };
    search.value=selectedText();
    search.addEventListener('focus',()=>{search.select();render();});
    search.addEventListener('input',()=>{
      if(native.value){
        native.value='';
        native.dispatchEvent(new Event('change',{bubbles:true}));
      }
      render();
    });
    search.addEventListener('keydown',event=>{
      if(event.key==='Escape'){close();search.value=selectedText();}
      if(event.key==='ArrowDown'){
        if(list.hidden)render();
        const first=list.querySelector('button');
        if(first){event.preventDefault();first.focus();}
      }
      if(event.key==='Enter'&&!list.hidden){
        const first=list.querySelector('button');
        if(first){event.preventDefault();first.click();}
      }
    });
    list.addEventListener('keydown',event=>{
      const buttons=Array.from(list.querySelectorAll('button'));
      const index=buttons.indexOf(document.activeElement);
      if(event.key==='ArrowDown'){event.preventDefault();buttons[Math.min(index+1,buttons.length-1)]?.focus();}
      if(event.key==='ArrowUp'){event.preventDefault();index<=0?search.focus():buttons[index-1]?.focus();}
      if(event.key==='Escape'){close();search.focus();}
    });
    native.addEventListener('change',()=>{search.value=selectedText();});
    document.addEventListener('pointerdown',event=>{if(!root.contains(event.target))close();});
    const clear=byId('nfeClearNature');
    clear?.addEventListener('click',()=>{search.value='';close();});
  })();

  byId('nfeNature')?.addEventListener('change',()=>{
    const nature=selectedNature();
    if(nature){
      setSelect('nfeOperationType',nature.operation_type||'outbound');
      if(nature.purpose) setSelect('nfePurpose',nature.purpose);
      if(nature.presence_default) setSelect('nfePresence',nature.presence_default);

      const additional=document.querySelector('[name="additional_info"]');
      if(additional && !additional.value && nature.additional_info) additional.value=nature.additional_info;
    }
    recalcItemCfops();
  });

  byId('nfeClearNature')?.addEventListener('click',()=>{
    setSelect('nfeNature','');
  });

  const deliveryFields=byId('nfeDeliveryFields');
  const deliveryToggle=byId('nfeDifferentDelivery');
  const deliverySelect=byId('nfeDeliveryAddressSelect');
  const deliveryMapping={
    name:'nfeDeliveryName',document:'nfeDeliveryDocument',zip_code:'nfeDeliveryZip',
    address:'nfeDeliveryAddress',address_number:'nfeDeliveryNumber',
    address_complement:'nfeDeliveryComplement',district:'nfeDeliveryDistrict',
    city:'nfeDeliveryCity',state:'nfeDeliveryState',city_ibge_code:'nfeDeliveryIbge',
  };
  const toggleDelivery=()=>{
    if(!deliveryFields)return;
    deliveryFields.hidden=!deliveryToggle?.checked;
    deliveryFields.querySelectorAll('input').forEach(el=>{el.disabled=!deliveryToggle.checked;});
    if(deliverySelect)deliverySelect.disabled=!deliveryToggle.checked;
  };
  const populateDeliveryAddresses=()=>{
    if(!deliverySelect)return;
    const previous=deliverySelect.value;
    deliverySelect.replaceChildren(new Option('Preencher manualmente',''));
    for(const address of selectedCustomer()?.delivery_addresses||[]){
      const label=[address.name,address.address,address.address_number,address.city].filter(Boolean).join(' — ');
      deliverySelect.add(new Option(label,String(address.id)));
    }
    if([...deliverySelect.options].some(o=>o.value===previous))deliverySelect.value=previous;
  };
  byId('nfeCustomer')?.addEventListener('change',()=>{
    updateRecipientPreview();
    populateDeliveryAddresses();
    if(deliverySelect)deliverySelect.value='';
  });
  deliveryToggle?.addEventListener('change',toggleDelivery);
  deliverySelect?.addEventListener('change',()=>{
    const address=(selectedCustomer()?.delivery_addresses||[])
      .find(row=>String(row.id)===deliverySelect.value);
    if(!address)return;
    for(const [key,id] of Object.entries(deliveryMapping))setField(id,address[key]||'');
  });
  byId('nfeOperationType')?.addEventListener('change',recalcItemCfops);
  byId('nfeDestination')?.addEventListener('change',recalcItemCfops);

  const generalToggle=byId('nfeGeneralFields');
  document.querySelector('[data-nfe-general-toggle]')?.addEventListener('click',event=>{
    if(!generalToggle) return;
    const wasHidden=generalToggle.hidden;
    generalToggle.hidden=!wasHidden;
    event.currentTarget.textContent=wasHidden?'Ocultar detalhes':'Mostrar detalhes';
    event.currentTarget.setAttribute('aria-expanded',String(wasHidden));
  });

  const syncDateControl=(checkboxId,inputIds)=>{
    const checkbox=byId(checkboxId);
    if(!checkbox) return;

    const apply=()=>{
      inputIds.forEach(id=>{
        const input=byId(id);
        if(!input)return;
        input.disabled=!checkbox.checked;
        // Datas opcionais não ocupam uma coluna vazia quando desativadas.
        // O valor permanece no formulário e reaparece se a opção for ligada.
        const group=input.closest('[data-nfe-date-group]');
        if(group)group.hidden=!checkbox.checked;
      });
    };

    checkbox.addEventListener('change',apply);
    apply();
  };

  syncDateControl('nfeInformIssueDatetime',['nfeIssueDate','nfeIssueTime']);
  syncDateControl('nfeInformExitDatetime',['nfeExitDate','nfeExitTime']);
  syncDateControl('nfeInformExpectedDeliveryDate',['nfeExpectedDeliveryDate']);

  const itemGross=item=>number(item.quantity)*Math.max(number(item.dimension_quantity)||1,0)*number(item.unit_price);
  const itemTotal=item=>Math.max(0,itemGross(item)+number(item.freight)+number(item.insurance)
    +number(item.other_expenses)-number(item.discount));

  let latestTaxPreview=null;
  const fiscalInputSignature=()=>JSON.stringify([items,...[
    'nfeCustomer','nfeNature','nfeOperationType','nfeDestination','nfePurpose','nfeFiscalOperation',
    'nfeFinalConsumer','nfeIssueDate','nfeFreightValue','nfeDiscount','nfeSurcharge',
    'nfeExportExitUf','nfeExportExitLocation','nfeExportDispatchLocation',
  ].map(getField),references,extraPayments,duplicates]);
  const serverTotalsShape=values=>({...values,insurance:number(values.insurance),
    otherExpenses:number(values.other_expenses),itemFreight:0,itemDiscount:0});
  const totals=()=>{
    if(readOnly())return serverTotalsShape(parseJson('nfe-stored-totals-data'));
    if(latestTaxPreview?.signature===fiscalInputSignature())return serverTotalsShape(latestTaxPreview.values);
    let productsTotal=0;
    let ipi=0,insurance=0,itemFreight=0,otherExpenses=0,itemDiscount=0;
    items.forEach(item=>{
      const base=itemGross(item);
      productsTotal+=base;
      insurance+=number(item.insurance);
      itemFreight+=number(item.freight);
      otherExpenses+=number(item.other_expenses);
      itemDiscount+=number(item.discount);
    });

    const freight=Math.max(0,number(getField('nfeFreightValue')));
    const discount=Math.max(0,number(getField('nfeDiscount')));
    const surcharge=Math.max(0,number(getField('nfeSurcharge')));
    const total=Math.max(0,productsTotal+freight+itemFreight+insurance+otherExpenses+surcharge+ipi-discount-itemDiscount);
    return {products:productsTotal,freight,discount,surcharge,ipi,total,insurance,otherExpenses,itemFreight,itemDiscount};
  };

  let taxPreviewTimer,taxPreviewRequest,taxPreviewVersion=0;
  const scheduleTaxPreview=()=>{
    const form=byId('nfeDraftForm');
    if(!form?.dataset.taxPreviewUrl||readOnly())return;
    const signature=fiscalInputSignature();
    if(latestTaxPreview?.signature===signature)return;
    latestTaxPreview=null;
    window.clearTimeout(taxPreviewTimer);
    const version=++taxPreviewVersion;
    taxPreviewRequest?.abort();
    const status=byId('nfeTaxPreviewStatus');
    if(status)status.textContent=items.length?'Calculando tributos…':'';
    taxPreviewTimer=window.setTimeout(async()=>{
      if(!items.length)return;
      taxPreviewRequest=new AbortController();
      const body=new FormData(form);
      body.delete('_method');
      body.set('items_json',JSON.stringify(items));
      try {
        const response=await fetch(form.dataset.taxPreviewUrl,{
          method:'POST',body,signal:taxPreviewRequest.signal,
          headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'},
        });
        const payload=await response.json();
        if(version!==taxPreviewVersion)return;
        if(!response.ok)throw new Error(Object.values(payload.errors||{}).flat()[0]||payload.message||'Confira os dados da nota.');
        const values=payload.totals;
        latestTaxPreview={signature,values};
        const fields={products:'nfeTotalProducts',freight:'nfeTotalFreight',insurance:'nfeTotalInsurance',
          other_expenses:'nfeTotalOtherExpenses',discount:'nfeTotalDiscount',surcharge:'nfeTotalSurcharge',
          ipi:'nfeTotalIpi',icms:'nfeTotalIcms',icms_st:'nfeTotalIcmsSt',fcp_st:'nfeTotalFcpSt',fcp:'nfeTotalFcp',
          pis:'nfeTotalPis',cofins:'nfeTotalCofins',ibs_uf:'nfeTotalIbsUf',ibs_municipal:'nfeTotalIbsMunicipal',
          cbs:'nfeTotalCbs',total:'nfeTotalInvoice',pis_st:'nfeTotalPisSt',cofins_st:'nfeTotalCofinsSt',ipi_returned:'nfeTotalIpiReturned',selective_tax:'nfeTotalSelective',ii:'nfeTotalIi',icms_desonerated:'nfeTotalDesonerated',
          desoneration_deduction:'nfeTotalDesonerationDeduction',icms_credit:'nfeTotalIcmsCredit',
          difal_destination:'nfeTotalDifalDestination',fcp_destination:'nfeTotalFcpDestination',fcp_st_retained:'nfeTotalFcpRetained'};
        Object.entries(fields).forEach(([key,id])=>{
          if(byId(id))byId(id).textContent=values[key]===null?'Pendente':money(values[key]);
        });
        if(byId('nfeFooterTotal'))byId('nfeFooterTotal').textContent=money(values.total);
        const extras=extraPayments.reduce((sum,item)=>sum+number(item.amount),0);
        if(byId('nfePrimaryPaymentTotal'))byId('nfePrimaryPaymentTotal').textContent=money(Math.max(0,values.total-extras));
        if(status){
          const errors=[...new Set([...(values.tax_errors||[]),...(payload.document_errors||[])])];
          status.textContent=(values.tax_calculation_complete?'Tributos calculados. ':'Total provisório. ')+
            (errors.length?errors.slice(0,2).join(' '):'Valide a nota antes da emissão.')+
            (errors.length>2?' Mais '+(errors.length-2)+' pendências abaixo.':'');
          if(byId('nfeTaxPreviewErrors'))byId('nfeTaxPreviewErrors').hidden=errors.length<3;
          if(byId('nfeTaxPreviewErrorsBody'))byId('nfeTaxPreviewErrorsBody').textContent=errors.join('\n');
        }
        const rules=payload.applied_rules||[];
        if(byId('nfeAppliedRules'))byId('nfeAppliedRules').hidden=rules.length===0;
        if(byId('nfeAppliedRulesBody'))byId('nfeAppliedRulesBody').textContent=rules.map(rule=>
          'Item '+rule.item+': '+rule.name+'. '+(rule.legal_reference||'')+
          (rule.cfop?' CFOP '+rule.cfop+'.':'')+
          (rule.csosn?' CSOSN '+rule.csosn+'.':rule.icms_cst?' CST ICMS '+rule.icms_cst+'.':'')+
          (rule.pis_cst?' PIS '+rule.pis_cst+'.':'')+(rule.cofins_cst?' COFINS '+rule.cofins_cst+'.':'')+
          (rule.overrides.length?' Existem valores informados manualmente. Confira os tributos deste item.':'')
        ).join('\n');
      }catch(error){
        if(error.name!=='AbortError'&&version===taxPreviewVersion&&status){
          status.textContent='Cálculo pendente: '+window.LumeronUserMessage.fromError(error,'Não foi possível atualizar os tributos. Confira a conexão e tente novamente.');
        }
      }
    },350);
  };

  const renderTotals=()=>{
    const values=totals();
    const targets={
      nfeTotalProducts:values.products,
      nfeTotalFreight:values.freight+values.itemFreight,
      nfeTotalInsurance:values.insurance,
      nfeTotalOtherExpenses:values.otherExpenses,
      nfeTotalIpi:values.ipi,
      nfeTotalDiscount:values.discount+values.itemDiscount,
      nfeTotalSurcharge:values.surcharge,
      nfeTotalInvoice:values.total,
      nfeFooterTotal:values.total,
    };
    Object.entries(targets).forEach(([id,value])=>{
      const element=byId(id);
      if(element) element.textContent=money(value);
    });
    const authoritative=readOnly()?parseJson('nfe-stored-totals-data'):
      latestTaxPreview?.signature===fiscalInputSignature()?latestTaxPreview.values:null;
    Object.entries({ipi:'nfeTotalIpi',icms:'nfeTotalIcms',icms_st:'nfeTotalIcmsSt',
      fcp_st:'nfeTotalFcpSt',fcp:'nfeTotalFcp',pis:'nfeTotalPis',cofins:'nfeTotalCofins',
      ibs_uf:'nfeTotalIbsUf',ibs_municipal:'nfeTotalIbsMunicipal',cbs:'nfeTotalCbs',
      pis_st:'nfeTotalPisSt',cofins_st:'nfeTotalCofinsSt',ipi_returned:'nfeTotalIpiReturned',selective_tax:'nfeTotalSelective',ii:'nfeTotalIi',icms_desonerated:'nfeTotalDesonerated',desoneration_deduction:'nfeTotalDesonerationDeduction',
      icms_credit:'nfeTotalIcmsCredit',difal_destination:'nfeTotalDifalDestination',
      fcp_destination:'nfeTotalFcpDestination',fcp_st_retained:'nfeTotalFcpRetained'}).forEach(([key,id])=>{
      if(byId(id))byId(id).textContent=authoritative?.[key]==null?'Pendente':money(authoritative[key]);
    });
    const extra=extraPayments.reduce((sum,item)=>sum+number(item.amount),0);
    const primary=byId('nfePrimaryPaymentTotal');
    if(primary){
      primary.textContent=money(Math.max(0,values.total-extra));
      primary.classList.toggle('field-invalid',extra>values.total+0.01);
    }
    scheduleTaxPreview();
  };

  ['nfeFreightValue','nfeDiscount','nfeSurcharge'].forEach(id=>{
    byId(id)?.addEventListener('input',renderTotals);
  });
  ['nfeCustomer','nfeNature','nfeOperationType','nfeDestination','nfePurpose',
    'nfeFiscalOperation','nfeFinalConsumer','nfeIssueDate','nfeExportExitUf','nfeExportExitLocation','nfeExportDispatchLocation'].forEach(id=>{
    byId(id)?.addEventListener('change',renderTotals);
  });

  const statusTax=item=>{
    const tax=normalizeTax(item.tax_data||{});
    const simple=['1','4'].includes(emitterCrt);
    const field=simple?'icms_csosn':'icms_cst';
    const code=(operationType()==='inbound'?tax[field+'_inbound']:'')||tax[field];
    return code?(simple?'CSOSN ':'CST ')+code:'—';
  };

  const removeTrashIcon='<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M8 6V4h8v2M5 6l1 15h12l1-15M10 10v7M14 10v7"/></svg>';
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
              <button type="button" class="btn-icon row-action-danger" data-nfe-remove-item="${index}" data-tooltip="Remover item" aria-label="Remover item">${removeTrashIcon}</button>
            </div>
          </td>
        </tr>
      `).join('');
    }
    syncHidden();
    renderTotals();
  };

  const syncReferencePanel=()=>{
    const toggle=byId('nfeHasReferencedDocument');
    const panel=byId('nfeReferencedDocumentsPanel');
    if(panel && toggle) panel.hidden=!toggle.checked;
  };
  byId('nfeHasReferencedDocument')?.addEventListener('change',syncReferencePanel);
  syncReferencePanel();

  const renderReferences=()=>{
    const body=byId('nfeReferenceBody');
    if(!body) return;
    body.innerHTML=references.length
      ? references.map((ref,index)=>`
          <tr>
            <td>${esc(String(ref.type||'NFE').toUpperCase()==='CTE'?'CT-e':'NF-e')}</td>
            <td><span class="code-tag">${esc(ref.key||'')}</span></td>
            <td class="action-cell"><button type="button" class="btn-icon row-action-danger" data-nfe-remove-reference="${index}" data-tooltip="Remover referência" aria-label="Remover referência">${removeTrashIcon}</button></td>
          </tr>
        `).join('')
      : '<tr><td colspan="3" class="empty-cell">Nenhum documento referenciado.</td></tr>';
    syncHidden();
    scheduleTaxPreview();
  };

  const renderDuplicates=()=>{
    const body=byId('nfeDuplicateBody');
    if(!body) return;
    if(duplicates.length && byId('nfePaymentInstallments'))byId('nfePaymentInstallments').open=true;
    body.innerHTML=duplicates.length
      ? duplicates.map((dup,index)=>`
          <tr>
            <td>${esc(dup.number||String(index+1))}</td>
            <td>${esc(dup.payment_type||'—')}</td>
            <td class="nowrap">${esc(dup.due_date||'—')}</td>
            <td class="price-strong nowrap">${money(dup.value)}</td>
            <td class="action-cell"><button type="button" class="btn-icon row-action-danger" data-nfe-remove-duplicate="${index}" data-tooltip="Remover duplicata" aria-label="Remover duplicata">${removeTrashIcon}</button></td>
          </tr>
        `).join('')
      : '<tr><td colspan="5" class="empty-cell">Nenhuma duplicata informada.</td></tr>';
    syncHidden();
  };

  const renderExtraPayments=()=>{
    const body=byId('nfeExtraPaymentsBody');
    if(extraPayments.length && byId('nfeSplitPayments'))byId('nfeSplitPayments').open=true;
    if(body){
      body.innerHTML=extraPayments.length ? extraPayments.map((p,i)=>`
        <tr><td>${esc([...byId('nfeExtraPaymentType').options].find(option=>option.value===p.type)?.textContent||'Forma não informada')}</td><td>${esc(({a_vista:'À vista','30_dias':'30 dias',personalizado:'Personalizado'})[p.condition]||'À vista')}</td>
        <td class="nowrap">${money(p.amount)}</td>
        <td class="action-cell"><button type="button" class="btn-icon row-action-danger"
          data-nfe-remove-payment="${i}" aria-label="Remover pagamento"
          data-tooltip="Remover pagamento">${removeTrashIcon}</button></td></tr>
      `).join(''):'<tr><td colspan="4" class="empty-cell">Nenhum pagamento adicional.</td></tr>';
    }
    syncHidden();
    renderTotals();
  };
  const updateExtraPaymentFields=()=>{
    const type=getField('nfeExtraPaymentType');
    const card=['03','04'].includes(type);
    for(const id of ['nfeExtraPaymentIntegrationField','nfeExtraPaymentBrandField','nfeExtraPaymentAcquirerField','nfeExtraPaymentAuthorizationField']){
      const field=byId(id);if(field)field.hidden=!card;
    }
    const other=byId('nfeExtraPaymentOtherField');if(other)other.hidden=type!=='99';
  };
  byId('nfeExtraPaymentType')?.addEventListener('change',updateExtraPaymentFields);
  byId('nfeAddExtraPayment')?.addEventListener('click',()=>{
    const amount=Math.round(number(getField('nfeExtraPaymentAmount'))*100)/100;
    const total=totals().total;
    const already=extraPayments.reduce((sum,p)=>sum+number(p.amount),0);
    if(amount<=0||already+amount>total+0.009||getField('nfePaymentType')==='90'){
      byId('nfeExtraPaymentAmount')?.focus();
      return;
    }
    const type=getField('nfeExtraPaymentType');
    if(['03','04'].includes(type)&&!['1','2'].includes(getField('nfeExtraPaymentIntegration'))){
      byId('nfeExtraPaymentIntegration')?.focus();
      return;
    }
    const p={type,condition:getField('nfeExtraPaymentCondition')||'a_vista',amount};
    if(type==='99')p.other_description=getField('nfeExtraPaymentOther');
    if(['03','04'].includes(type)){
      p.integration_type=getField('nfeExtraPaymentIntegration');
      p.card_brand=getField('nfeExtraPaymentBrand');
      p.acquirer_document=getField('nfeExtraPaymentAcquirer');
      p.authorization_code=getField('nfeExtraPaymentAuthorization');
    }
    extraPayments.push(p);
    setField('nfeExtraPaymentAmount','');
    renderExtraPayments();
  });
  const updatePaymentVisibility=()=>{
    const type=getField('nfePaymentType');
    const cardPanel=byId('nfeCardPanel');
    const otherField=byId('nfeOtherPaymentField');
    if(cardPanel) cardPanel.hidden=!['03','04'].includes(type);
    if(otherField) otherField.hidden=type!=='99';
  };
  byId('nfePaymentType')?.addEventListener('change',updatePaymentVisibility);
  // Parcelas são opcionais; só abrir o grupo quando há informação
  // existente ou quando o operador escolhe pagamento a prazo.
  byId('nfePaymentCondition')?.addEventListener('change',()=>{
    const group=byId('nfePaymentInstallments');
    if(group && getField('nfePaymentCondition')!=='a_vista')group.open=true;
  });

  const itemDialog=byId('nfeItemDialog');

  const itemFields={
    index:'nfeItemIndex',
    product_id:'nfeItemProduct',
    product_name:'nfeItemName',
    product_sku:'nfeItemSku',
    quantity:'nfeItemQuantity',
    dimension_quantity:'nfeItemDimensionQuantity',
    unit_price:'nfeItemUnitPrice',
    freight:'nfeItemFreight',insurance:'nfeItemInsurance',
    other_expenses:'nfeItemOtherExpenses',discount:'nfeItemDiscount',
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
    ibs_uf_deferral_rate:'nfeExtraIbsUfDeferralRate',
    ibs_municipal_deferral_rate:'nfeExtraIbsMunicipalDeferralRate',
    cbs_deferral_rate:'nfeExtraCbsDeferralRate',
    rtc_regular_cst:'nfeExtraRtcRegularCst',
    rtc_regular_class:'nfeExtraRtcRegularClass',
    rtc_regular_base:'nfeExtraRtcRegularBase',
    rtc_regular_ibs_uf_rate:'nfeExtraRtcRegularIbsUfRate',
    rtc_regular_ibs_municipal_rate:'nfeExtraRtcRegularIbsMunicipalRate',
    rtc_regular_cbs_rate:'nfeExtraRtcRegularCbsRate',
    rtc_regular_legal_source:'nfeExtraRtcRegularLegalSource',
    rtc_regular_legal_reference:'nfeExtraRtcRegularLegalReference',
    is_cst:'nfeExtraIsCst',
    is_class:'nfeExtraIsClass',
    is_calculation_type:'nfeExtraIsCalculationType',
    is_base:'nfeExtraIsBase',
    is_rate:'nfeExtraIsRate',
    is_quantity:'nfeExtraIsQuantity',
    is_unit_rate:'nfeExtraIsUnitRate',
    is_unit:'nfeExtraIsUnit',
    is_valid_from:'nfeExtraIsValidFrom',
    is_valid_until:'nfeExtraIsValidUntil',
    is_legal_source:'nfeExtraIsLegalSource',
    is_legal_reference:'nfeExtraIsLegalReference',

    pis_st_calculation_type:'nfeTaxPisStMode',
    pis_st_base:'nfeTaxPisStBase',
    pis_st_rate:'nfeTaxPisStRate',
    pis_st_quantity:'nfeTaxPisStQuantity',
    pis_st_unit_rate:'nfeTaxPisStUnitRate',
    pis_st_add_to_total:'nfeTaxPisStAdd',
    cofins_st_calculation_type:'nfeTaxCofinsStMode',
    cofins_st_base:'nfeTaxCofinsStBase',
    cofins_st_rate:'nfeTaxCofinsStRate',
    cofins_st_quantity:'nfeTaxCofinsStQuantity',
    cofins_st_unit_rate:'nfeTaxCofinsStUnitRate',
    cofins_st_add_to_total:'nfeTaxCofinsStAdd',
    ipi_returned_percentage:'nfeTaxIpiReturnedPercentage',
    ipi_returned_original_amount:'nfeTaxIpiReturnedOriginal',

    tax_quantity_factor:'nfeTaxQuantityFactor',
    cfop_outbound_internal:'nfeCfopOutboundInternal',
    cfop_outbound_interstate:'nfeCfopOutboundInterstate',
    cfop_inbound_internal:'nfeCfopInboundInternal',
    cfop_inbound_interstate:'nfeCfopInboundInterstate',
    cfop_outbound_foreign:'nfeCfopOutboundForeign',
    cfop_inbound_foreign:'nfeCfopInboundForeign',

    icms_csosn:'nfeTaxCsosn',
    icms_cst:'nfeTaxIcmsCst',
    icms_csosn_export:'nfeTaxCsosnExport',
    icms_csosn_inbound:'nfeTaxCsosnInbound',
    icms_cst_inbound:'nfeTaxIcmsCstInbound',
    icms_rate:'nfeTaxIcmsRate',
    base_reduction_rate:'nfeTaxBaseReduction',
    simple_credit_rate:'nfeTaxSimpleCredit',
    mod_bc:'nfeTaxModBc',
    mod_bc_st:'nfeTaxModBcSt',
    icms_st_rate:'nfeTaxIcmsStRate',
    mva_rate:'nfeTaxMvaRate',
    icms_base:'nfeTaxIcmsBase',
    icms_deferment_rate:'nfeTaxIcmsDeferment',
    fcp_rate:'nfeTaxOwnFcp',
    icms_st_base:'nfeTaxStBase',
    icms_st_deduction:'nfeTaxStDeduction',
    icms_st_base_reduction:'nfeTaxStReduction',
    fcp_st_rate:'nfeTaxStFcp',
    st_retained_amount_scope:'nfeTaxRetainedScope',
    icms_st_retained_base:'nfeTaxRetainedBase',
    st_retained_rate:'nfeTaxRetainedRate',
    icms_substitutor_value:'nfeTaxSubstitutorValue',
    icms_st_retained_value:'nfeTaxRetainedValue',
    fcp_st_retained_base:'nfeTaxRetainedFcpBase',
    fcp_st_retained_rate:'nfeTaxRetainedFcpRate',
    fcp_st_retained_value:'nfeTaxRetainedFcpValue',
    icms_effective_base:'nfeTaxEffectiveBase',
    icms_effective_rate:'nfeTaxEffectiveRate',
    icms_effective_value:'nfeTaxEffectiveValue',
    icms_effective_reduction:'nfeTaxEffectiveReduction',
    icms_desoneration_amount:'nfeTaxDesonerationAmount',
    icms_desoneration_reason:'nfeTaxDesonerationReason',
    icms_desoneration_deduct:'nfeTaxDesonerationDeduct',

    pis_cst:'nfeTaxPisCst',
    pis_rate:'nfeTaxPisRate',
    pis_cst_inbound:'nfeTaxPisCstInbound',
    pis_base:'nfeTaxPisBase',
    pis_quantity:'nfeTaxPisQuantity',
    pis_unit_rate:'nfeTaxPisUnitRate',
    pis_calculation_type:'nfeTaxPisCalculationType',
    cofins_cst:'nfeTaxCofinsCst',
    cofins_rate:'nfeTaxCofinsRate',
    cofins_cst_inbound:'nfeTaxCofinsCstInbound',
    cofins_base:'nfeTaxCofinsBase',
    cofins_quantity:'nfeTaxCofinsQuantity',
    cofins_unit_rate:'nfeTaxCofinsUnitRate',
    cofins_calculation_type:'nfeTaxCofinsCalculationType',
    ipi_cst:'nfeTaxIpiCst',
    ipi_rate:'nfeTaxIpiRate',
    ipi_cst_inbound:'nfeTaxIpiCstInbound',
    ipi_enq:'nfeTaxIpiEnq',
    ipi_quantity:'nfeTaxIpiQuantity',
    ipi_unit_rate:'nfeTaxIpiUnitRate',
    iss_rate:'nfeTaxIssRate',
    service_list_code:'nfeTaxServiceListCode',

    interstate_icms_rate:'nfeTaxInterstateRate',
    internal_icms_rate:'nfeTaxInternalRate',
    fcp_interstate_rate:'nfeTaxFcpRate',
    difal_base:'nfeTaxDifalBase',
    difal_fcp_base:'nfeTaxDifalFcpBase',
    difal_share_rate:'nfeTaxDifalShare',

    ibs_cst:'nfeTaxIbsCst',
    cbs_cst:'nfeTaxCbsCst',
    tax_classification_code:'nfeTaxClassification',
    ibs_cbs_base:'nfeTaxIbsCbsBase',
    ibs_uf_rate:'nfeIbsUfRate',
    ibs_municipal_rate:'nfeIbsMunicipalRate',
    cbs_rate:'nfeCbsRate',
    ibs_uf_reduction_rate:'nfeIbsUfReduction',
    ibs_municipal_reduction_rate:'nfeIbsMunicipalReduction',
    cbs_reduction_rate:'nfeCbsReduction',
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

  const productSearchMeta=product=>{
    const stock=Number(product.stock_quantity||0).toLocaleString('pt-BR',{maximumFractionDigits:3});
    return [
      product.sku ? 'Cód. '+product.sku : null,
      product.ean_gtin ? 'GTIN '+product.ean_gtin : null,
      product.ncm ? 'NCM '+product.ncm : null,
      money(product.sale_price),
      product.control_stock ? 'Estoque '+stock+' '+(product.unit||'UN') : null,
    ].filter(Boolean).join(' · ');
  };

  const closeProductSuggestions=(except=null)=>{
    document.querySelectorAll('.nfe-product-search .sale-item-suggestions').forEach(menu=>{
      if(menu!==except) menu.hidden=true;
    });
  };

  const updateItemSubtotal=()=>{
    const subtotal=byId('nfeItemSubtotal');
    const quantity=number(getField('nfeItemQuantity'));
    const dimension=Math.max(number(getField('nfeItemDimensionQuantity'))||1,0);
    const total=money(Math.max(0,quantity*dimension*number(getField('nfeItemUnitPrice'))
        +number(getField('nfeItemFreight'))+number(getField('nfeItemInsurance'))
        +number(getField('nfeItemOtherExpenses'))-number(getField('nfeItemDiscount'))));
    if(subtotal) subtotal.textContent=total;
    const headerTotal=byId('nfeItemHeaderTotal');
    if(headerTotal)headerTotal.textContent=total;
  };
  byId('nfeItemQuantity')?.addEventListener('input',updateItemSubtotal);
  byId('nfeItemDimensionQuantity')?.addEventListener('input',updateItemSubtotal);
  byId('nfeItemUnitPrice')?.addEventListener('input',updateItemSubtotal);
  ['nfeItemFreight','nfeItemInsurance','nfeItemOtherExpenses','nfeItemDiscount']
    .forEach(id=>byId(id)?.addEventListener('input',updateItemSubtotal));

  // Cards fiscais funcionam como abas no mesmo espaço, sem rolagem automática.
  // Os campos continuam no DOM e mantêm seus valores ao alternar.
  const itemSections=[...itemDialog?.querySelectorAll('[data-nfe-item-panel]')||[]];
  const taxCards=[...itemDialog?.querySelectorAll('[data-nfe-open-section]')||[]];
  const taxReview=itemDialog?.querySelector('.nfe-tax-review');
  const taxHost=document.createElement('div');
  taxHost.className='nfe-tax-tab-host';
  taxHost.setAttribute('role','region');
  taxHost.setAttribute('aria-label','Tributação do item');
  taxReview?.after(taxHost);
  const taxSections={
    icms:itemDialog?.querySelector('[data-nfe-item-panel="item-icms"]'),
    federal:itemDialog?.querySelector('[data-nfe-item-panel="item-federal"]'),
    ibscbs:itemDialog?.querySelector('[data-nfe-item-panel="item-ibscbs"]')
  };
  Object.values(taxSections).forEach(section=>{
    if(!section)return;
    taxHost.append(section);
    section.open=true;
    section.classList.add('nfe-tax-tab-panel');
    section.querySelector(':scope > summary')?.setAttribute('aria-hidden','true');
  });
  const federalGrid=taxSections.federal?.querySelector('.nfe-item-section-content .editor-grid');
  federalGrid?.querySelectorAll(':scope > .field').forEach(field=>{
    const id=field.querySelector('[id]')?.id||'';
    let group=/Pis/i.test(id)?'pis':/Cofins/i.test(id)?'cofins':/Ipi/i.test(id)?'ipi':'federal-common';
    field.dataset.nfeTaxGroup=group;
  });
  const additionalFederal=itemDialog?.querySelector('.nfe-item-additional-federal-grid');
  if(additionalFederal)taxSections.federal?.querySelector('.nfe-item-section-content')?.append(additionalFederal);
  additionalFederal?.querySelectorAll(':scope > .field').forEach(field=>{
    const id=field.querySelector('[id]')?.id||'';
    field.dataset.nfeTaxGroup=/Cofins/i.test(id)?'cofins':/Pis/i.test(id)?'pis':'federal-common';
  });
  const rtcPanels=[...itemDialog?.querySelectorAll('.nfe-rtc-extra-panel')||[]];
  rtcPanels.forEach(panel=>taxSections.ibscbs?.querySelector('.nfe-item-section-content')?.append(panel));
  const taxKeys=['icms','pis','cofins','ipi','ibscbs'];
  taxCards.forEach((button,index)=>{
    button.dataset.nfeTaxTab=taxKeys[index];
    button.setAttribute('aria-pressed','false');
  });
  const selectTaxTab=(key)=>{
    const selected=taxKeys.includes(key)?key:'icms';
    taxCards.forEach(button=>{
      const active=button.dataset.nfeTaxTab===selected;
      button.classList.toggle('nfe-tax-tab-active',active);
      button.setAttribute('aria-pressed',String(active));
    });
    Object.entries(taxSections).forEach(([name,section])=>{
      if(!section)return;
      const visible=name===selected||(name==='federal'&&['pis','cofins','ipi'].includes(selected));
      section.hidden=!visible;
      section.style.display=visible?'':'none';
      if(visible)section.open=true;
    });
    federalGrid?.querySelectorAll('[data-nfe-tax-group]').forEach(field=>{
      field.hidden=field.dataset.nfeTaxGroup!==selected&&field.dataset.nfeTaxGroup!=='federal-common';
      field.style.display=field.hidden?'none':'';
    });
    additionalFederal?.querySelectorAll('[data-nfe-tax-group]').forEach(field=>{
      field.hidden=field.dataset.nfeTaxGroup!==selected&&field.dataset.nfeTaxGroup!=='federal-common';
      field.style.display=field.hidden?'none':'';
    });
  };
  taxCards.forEach(button=>button.addEventListener('click',()=>selectTaxTab(button.dataset.nfeTaxTab)));
  selectTaxTab('icms');
  const openItemSection=(target,scroll=false)=>{
    const key=target==='item-icms'?'icms':target==='item-federal'?'pis':target==='item-ibscbs'?'ibscbs':null;
    if(key){selectTaxTab(key);return;}
    const section=itemDialog?.querySelector('[data-nfe-item-panel="'+target+'"]');
    if(!section)return;
    section.open=true;
    if(scroll)section.scrollIntoView({behavior:'smooth',block:'nearest'});
  };
  const updateItemTaxSummary=()=>{
    const summary=(id,value,optional=false)=>{
      const element=byId(id);
      if(!element)return;
      element.textContent=value||(optional?'Sem preenchimento':'Não informado');
      element.closest('button')?.classList.toggle('nfe-tax-needs-review',!value&&!optional);
    };
    const inbound=operationType()==='inbound';
    const simple=['1','4'].includes(emitterCrt);
    const icmsField=simple?'nfeTaxCsosn':'nfeTaxIcmsCst';
    const icms=(inbound?getField(icmsField+'Inbound'):'')||getField(icmsField);
    summary('nfeTaxSummaryIcms',icms?(simple?'CSOSN ':'CST ')+icms:'');
    const icmsRate=getField('nfeTaxIcmsRate')||getField('nfeTaxSimpleCreditRate');
    const icmsSummary=byId('nfeTaxSummaryIcms');
    if(icmsSummary&&icms&&icmsRate)icmsSummary.textContent+=' · '+icmsRate+'%';
    [['Pis','nfeTaxPisCst'],['Cofins','nfeTaxCofinsCst'],['Ipi','nfeTaxIpiCst']].forEach(([name,field])=>{
      const code=(inbound?getField(field+'Inbound'):'')||getField(field);
      summary('nfeTaxSummary'+name,code?'CST '+code:'');
      const rate=getField('nfeTax'+name+'Rate');
      const element=byId('nfeTaxSummary'+name);
      if(element&&code&&rate)element.textContent+=' · '+rate+'%';
    });
    const ibs=getField('nfeTaxIbsCst'),cbs=getField('nfeTaxCbsCst');
    summary('nfeTaxSummaryIbs',ibs||cbs
      ?[ibs?'IBS '+ibs:null,cbs?'CBS '+cbs:null].filter(Boolean).join(' · ')
      :'',true);
  };
  itemDialog?.addEventListener('input',updateItemTaxSummary);
  itemDialog?.addEventListener('change',updateItemTaxSummary);
  // As abas representam painéis exclusivos, não atalhos de rolagem.
  // Manter os painéis montados preserva todos os campos e dados preenchidos.
  const flowButtons=[...document.querySelectorAll('[data-nfe-jump]')];
  const flowBody=document.querySelector('.nfe-workspace-body');
  const flowPanels=flowButtons.map(button=>byId(button.dataset.nfeJump)).filter(Boolean);
  const activateFlow=(button,resetScroll=true)=>{
    const target=byId(button?.dataset.nfeJump);
    if(!target)return;
    flowPanels.forEach(panel=>{
      panel.hidden=panel!==target;
      panel.setAttribute('aria-hidden',String(panel!==target));
    });
    flowButtons.forEach(tab=>{
      const active=tab===button;
      tab.classList.toggle('active',active);
      tab.setAttribute('aria-selected',String(active));
      tab.setAttribute('tabindex',active?'0':'-1');
    });
    if(resetScroll&&flowBody)flowBody.scrollTop=0;
  };
  flowButtons.forEach(button=>button.addEventListener('click',()=>activateFlow(button)));
  if(flowButtons.length)activateFlow(flowButtons[0],false);

  const clearItemDialog=()=>{
    Object.values(itemFields).forEach(id=>setField(id,''));
    Object.values(taxFields).forEach(id=>setField(id,''));
    Object.values(specialFields).forEach(id=>setField(id,''));
    setField('nfeItemQuantity','1');
    setField('nfeItemDimensionQuantity','1');
    setField('nfeTaxQuantityFactor','1');
    setField('nfeTaxIpiEnq','');
    const petroleum=byId('nfeSpecialPetroleum');
    if(petroleum) petroleum.checked=false;
    updateItemSubtotal();
    updateItemTaxSummary();
    selectTaxTab('icms');
    itemSections.forEach(section=>{
      if(section.classList.contains('nfe-tax-tab-panel'))return;
      section.open=['item-general','item-fiscal'].includes(section.dataset.nfeItemPanel);
    });
  };

  const productDefaults=product=>{
    const tax=normalizeTax(product?.tax_defaults||{});
    return {
      product_id:product?.id||'',
      product_name:product?.name||'',
      product_sku:product?.sku||'',
      quantity:1,
      dimension_quantity:1,
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

  const newItemDefaults=()=>{
    const tax=normalizeTax(taxDefaults||{});
    return {
      product_id:'',
      product_name:'',
      product_sku:'',
      quantity:1,
      dimension_quantity:1,
      unit_price:0,
      freight:0,insurance:0,other_expenses:0,discount:0,
      cfop:resolveCfop(tax),
      origin:taxDefaults?.icms_origin_default||'0',
      ean_gtin:'',
      unit:'UN',
      tax_unit:'UN',
      ncm:'',
      cest:'',
      ipi_exception:'',
      fiscal_benefit_code:'',
      purchase_order:'',
      purchase_order_item:'',
      notes:'',
      tax_data:tax,
      special_data:{},
    };
  };

  let itemSearchControl=null;
  let dialogTaxData={},dialogSpecialData={};
  let dialogImportDeclarations=[];
  const importTaxFields={base:'nfeImportTaxBase',rate:'nfeImportTaxRate',
    ii_amount:'nfeImportTaxAmount',customs_expenses:'nfeImportCustomsExpenses',iof_amount:'nfeImportIof'};
  const importField=(key,label,value='',type='text',max='')=>
    '<label class="field col-3"><span>'+esc(label)+'</span><input data-import-key="'+key+'" type="'+type+'" '+
    (type==='number'?'min="0" step="0.01" ':'')+(max?'maxlength="'+max+'" ':'')+'value="'+esc(value)+'"></label>';
  const collectImportDeclarations=()=>{
    const container=byId('nfeImportDeclarations');
    if(!container)return;
    dialogImportDeclarations=Array.from(container.querySelectorAll('[data-import-declaration]')).map((node,index)=>{
      const declaration={...(dialogImportDeclarations[index]||{})};
      node.querySelectorAll(':scope > .editor-grid [data-import-key]').forEach(input=>declaration[input.dataset.importKey]=input.value);
      declaration.additions=Array.from(node.querySelectorAll('[data-import-addition]')).map((addition,additionIndex)=>{
        const value={...(declaration.additions?.[additionIndex]||{})};
        addition.querySelectorAll('[data-import-key]').forEach(input=>value[input.dataset.importKey]=input.value);
        return value;
      });
      return declaration;
    });
  };
  const renderImportDeclarations=()=>{
    const container=byId('nfeImportDeclarations');
    if(!container)return;
    container.innerHTML=dialogImportDeclarations.map((di,index)=>
      '<div class="editor-panel" data-import-declaration="'+index+'"><div class="delivery-card-title"><strong>Declaração '+(index+1)+
      '</strong><button type="button" class="btn btn-secondary" data-remove-import="'+index+'">Remover declaração</button></div><div class="editor-grid cols-12">'+
      importField('number','Número da DI',di.number,'text','12')+importField('date','Data do registro',di.date,'date')+
      importField('clearance_location','Local do desembaraço',di.clearance_location,'text','60')+
      importField('clearance_uf','UF do desembaraço',di.clearance_uf,'text','2')+
      importField('clearance_date','Data do desembaraço',di.clearance_date,'date')+
      '<label class="field col-3"><span>Via de transporte</span><select data-import-key="transport_type"><option value="">Selecione</option>'+[['1','Marítima'],['4','Aérea'],['7','Rodoviária']].map(([value,label])=>'<option value="'+value+'" '+(di.transport_type===value?'selected':'')+'>'+value+' - '+label+'</option>').join('')+'</select></label>'+
      importField('afrmm_value','AFRMM documentado',di.afrmm_value,'number')+
      '<label class="field col-3"><span>Intermediação</span><select data-import-key="intermediation_type"><option value="">Selecione</option><option value="1" '+(di.intermediation_type==='1'?'selected':'')+'>1 - Conta própria</option></select></label>'+
      importField('exporter_code','Código do exportador',di.exporter_code,'text','60')+'</div>'+
      (Array.isArray(di.additions)?di.additions:[]).map((addition,additionIndex)=>
        '<div class="editor-grid cols-12" data-import-addition="'+additionIndex+'">'+
        importField('number','Número da adição',addition.number,'text','3')+
        importField('sequence','Sequência da adição',addition.sequence,'text','3')+
        importField('manufacturer_code','Código do fabricante',addition.manufacturer_code,'text','60')+
        importField('discount','Desconto documentado',addition.discount,'number')+
        importField('drawback','Ato concessório drawback',addition.drawback,'text','11')+
        '<div class="field col-3"><span>&nbsp;</span><button type="button" class="btn btn-secondary" data-remove-addition="'+index+':'+additionIndex+'">Remover adição</button></div></div>'
      ).join('')+'<button type="button" class="btn btn-secondary" data-add-import-addition="'+index+'">Adicionar adição</button></div>'
    ).join('');
  };
  byId('nfeAddImportDeclaration')?.addEventListener('click',()=>{
    if(readOnly())return;
    collectImportDeclarations();
    if(dialogImportDeclarations.length>=100)return;
    dialogImportDeclarations.push({additions:[]});renderImportDeclarations();
  });
  byId('nfeImportDeclarations')?.addEventListener('click',event=>{
    if(readOnly())return;
    const remove=event.target.closest('[data-remove-import]');
    const add=event.target.closest('[data-add-import-addition]');
    const removeAddition=event.target.closest('[data-remove-addition]');
    if(!remove&&!add&&!removeAddition)return;
    collectImportDeclarations();
    if(remove)dialogImportDeclarations.splice(Number(remove.dataset.removeImport),1);
    if(add){const di=dialogImportDeclarations[Number(add.dataset.addImportAddition)];if(di.additions.length<100)di.additions.push({});}
    if(removeAddition){const [di,addition]=removeAddition.dataset.removeAddition.split(':').map(Number);dialogImportDeclarations[di].additions.splice(addition,1);}
    renderImportDeclarations();
  });

  const toggleForeignTrade=()=>{
    const foreign=destinationType()==='foreign';
    const hasExportFields=['nfeExportExitUf','nfeExportExitLocation','nfeExportDispatchLocation'].some(id=>getField(id)!=='');
    const hasImportFields=dialogImportDeclarations.length>0||Object.values(importTaxFields).some(id=>getField(id)!=='');
    if(byId('nfeForeignTradeHeader'))byId('nfeForeignTradeHeader').hidden=!hasExportFields&&(!foreign||getField('nfeFiscalOperation')!=='export');
    if(byId('nfeItemForeignTrade'))byId('nfeItemForeignTrade').hidden=!hasImportFields&&(!foreign||getField('nfeFiscalOperation')!=='import');
  };
  ['nfeDestination','nfeFiscalOperation'].forEach(id=>byId(id)?.addEventListener('change',toggleForeignTrade));

  const populateItemDialog=(item,indexValue='')=>{
    clearItemDialog();
    dialogTaxData={...(item.tax_data||{})};
    dialogSpecialData={...(item.special_data||{})};
    dialogImportDeclarations=JSON.parse(JSON.stringify(Array.isArray(dialogSpecialData.import_declarations)?dialogSpecialData.import_declarations:[]));
    Object.entries(importTaxFields).forEach(([key,id])=>setField(id,dialogSpecialData.import_taxes?.[key]??''));
    renderImportDeclarations();
    toggleForeignTrade();

    Object.entries(itemFields).forEach(([key,id])=>{
      if(key==='index') return;
      if(key==='origin') setSelect(id,item.origin??'0');
      else setField(id,item[key]);
    });

    const tax=normalizeTax(item.tax_data||{});
    Object.entries(taxFields).forEach(([key,id])=>setField(id,tax[key]));

    const special=item.special_data||{};
    Object.entries(specialFields).forEach(([key,id])=>setField(id,special[key]));

    const petroleum=byId('nfeSpecialPetroleum');
    if(petroleum) petroleum.checked=!!special.petroleum_derived;

    const product=productById(item.product_id);
    if(product) itemSearchControl?.setProduct(product);
    else itemSearchControl?.setText(item.product_name||'');

    setField('nfeItemIndex',indexValue);
    updateItemSubtotal();
    updateItemTaxSummary();
  };

  const openNewItem=()=>{
    populateItemDialog(newItemDefaults(),'');
    openModal(itemDialog);
    setTimeout(()=>byId('nfeItemProductSearch')?.focus(),0);
  };

  const openEditItem=index=>{
    const item=items[index];
    if(!item) return;
    populateItemDialog(item,index);
    openModal(itemDialog);
  };

  const applyProductToItemDialog=product=>{
    if(!product) return;
    const currentIndex=getField('nfeItemIndex');
    // Mudar o produto não pode apagar frete, desconto, pedido ou observações
    // que o operador já digitou neste item.
    const manualFields={
      quantity:getField('nfeItemQuantity'),
      dimension_quantity:getField('nfeItemDimensionQuantity'),
      freight:getField('nfeItemFreight'),
      insurance:getField('nfeItemInsurance'),
      other_expenses:getField('nfeItemOtherExpenses'),
      discount:getField('nfeItemDiscount'),
      purchase_order:getField('nfeItemPurchaseOrder'),
      purchase_order_item:getField('nfeItemPurchaseOrderItem'),
      notes:getField('nfeItemNotes'),
    };
    const defaults=productDefaults(product);
    const sameSavedProduct=currentIndex!==''
      &&String(items[Number(currentIndex)]?.product_id)===String(product.id);
    // Reabrir/selecionar o mesmo produto não deve apagar tributos ou
    // informações específicas que já foram conferidos manualmente.
    if(sameSavedProduct){
      const tax=normalizeTax(defaults.tax_data||{});
      for(const [key,id] of Object.entries(taxFields)){
        const value=getField(id);
        if(value!=='')tax[key]=value;
      }
      defaults.tax_data=tax;
      const special={...(defaults.special_data||{})};
      for(const [key,id] of Object.entries(specialFields)){
        const value=getField(id);
        if(value!=='')special[key]=value;
      }
      special.petroleum_derived=!!byId('nfeSpecialPetroleum')?.checked;
      defaults.special_data=special;
    }
    for(const [key,value] of Object.entries(manualFields)){
      if(value!=='' && value!==null)defaults[key]=value;
    }
    // Preservar preço unitário apenas quando o mesmo produto for reaberto.
    if(currentIndex!=='' && String(items[Number(currentIndex)]?.product_id)===String(product.id)
       &&getField('nfeItemUnitPrice')!=='') defaults.unit_price=getField('nfeItemUnitPrice');
    populateItemDialog(defaults,currentIndex);
  };

  const bindItemProductSearch=()=>{
    const input=byId('nfeItemProductSearch');
    const hidden=byId('nfeItemProduct');
    const menu=byId('nfeItemProductSuggestions');
    if(!input||!hidden||!menu) return null;

    const selectProduct=product=>{
      hidden.value=String(product.id);
      input.value=product.name;
      input.dataset.selectedLabel=product.name;
      menu.hidden=true;
      applyProductToItemDialog(product);
    };

    const render=()=>{
      const raw=input.value.trim();
      const term=raw.toLocaleLowerCase('pt-BR');
      const matches=products.filter(product=>{
        const hay=[product.name,product.sku,product.ean_gtin,product.ncm]
          .filter(Boolean).join(' ').toLocaleLowerCase('pt-BR');
        return !term || hay.includes(term);
      }).slice(0,10);

      menu.innerHTML='';

      matches.forEach(product=>{
        const option=document.createElement('button');
        option.type='button';
        option.className='sale-suggestion';
        option.innerHTML='<strong></strong><small></small>';
        option.querySelector('strong').textContent=product.name;
        option.querySelector('small').textContent=productSearchMeta(product);
        option.addEventListener('mousedown',event=>{
          event.preventDefault();
          selectProduct(product);
        });
        menu.appendChild(option);
      });

      if(!matches.length){
        const empty=document.createElement('div');
        empty.className='sale-suggestion-empty';
        empty.textContent=raw
          ? 'Produto não encontrado. Informe a descrição e os dados do item abaixo.'
          : 'Nenhum produto encontrado.';
        menu.appendChild(empty);
      }

      const create=document.createElement('button');
      create.type='button';
      create.className='sale-suggestion nfe-catalog-create';
      create.textContent=raw ? 'Cadastrar "'+raw.slice(0,90)+'" como produto' : 'Cadastrar novo produto';
      create.addEventListener('mousedown',event=>{
        event.preventDefault();
        menu.hidden=true;
        window.LumeronCatalog?.create('products',{
          initial:raw,
          onSelect:product=>{
            if(!products.some(p=>String(p.id)===String(product.id)))products.push(product);
            selectProduct(product);
          }
        });
      });
      menu.appendChild(create);
      closeProductSuggestions(menu);
      menu.hidden=false;
    };

    input.addEventListener('focus',render);
    input.addEventListener('input',()=>{
      const raw=input.value.trim();

      if(input.dataset.selectedLabel && input.value!==input.dataset.selectedLabel){
        hidden.value='';
        delete input.dataset.selectedLabel;
      }

      if(!hidden.value && raw){
        setField('nfeItemName',raw);
      }

      render();
    });

    return {
      setProduct(product){
        hidden.value=String(product.id);
        input.value=product.name;
        input.dataset.selectedLabel=product.name;
        menu.hidden=true;
      },
      setText(value){
        hidden.value='';
        input.value=value||'';
        delete input.dataset.selectedLabel;
        menu.hidden=true;
      },
    };
  };

  itemSearchControl=bindItemProductSearch();

  document.addEventListener('click',event=>{
    if(!event.target.closest('.nfe-product-search')) closeProductSuggestions();
  });

  byId('nfeAddProduct')?.addEventListener('click',openNewItem);
  byId('nfeQuickCreateProduct')?.addEventListener('click',()=>{
    window.LumeronCatalog?.create('products',{
      initial:byId('nfeItemProductSearch')?.value||'',
      onSelect:product=>{
        if(!products.some(p=>String(p.id)===String(product.id)))products.push(product);
        itemSearchControl?.setProduct(product);
        applyProductToItemDialog(product);
      }
    });
  });

  byId('nfeSaveItem')?.addEventListener('click',()=>{
    if(readOnly())return;
    const product=productById(getField('nfeItemProduct'));
    const productName=getField('nfeItemName').trim();
    const quantity=number(getField('nfeItemQuantity'));

    if(!productName){
      byId('nfeItemName')?.focus();
      return;
    }
    if(quantity<=0){
      byId('nfeItemQuantity')?.focus();
      return;
    }

    const tax={...dialogTaxData};
    Object.entries(taxFields).forEach(([key,id])=>tax[key]=getField(id));
    Object.assign(tax,{icms_base_mode:tax.mod_bc,icms_base_reduction:tax.base_reduction_rate,
      icms_credit_rate:tax.simple_credit_rate,icms_st_base_mode:tax.mod_bc_st,icms_st_mva:tax.mva_rate,
      difal_interstate_rate:tax.interstate_icms_rate,difal_internal_rate:tax.internal_icms_rate,difal_fcp_rate:tax.fcp_interstate_rate});

    const special={...dialogSpecialData};
    Object.entries(specialFields).forEach(([key,id])=>special[key]=getField(id));
    special.petroleum_derived=!!byId('nfeSpecialPetroleum')?.checked;
    collectImportDeclarations();
    if(dialogImportDeclarations.length)special.import_declarations=dialogImportDeclarations;
    else delete special.import_declarations;
    const importTax={...(dialogSpecialData.import_taxes||{})};
    Object.entries(importTaxFields).forEach(([key,id])=>importTax[key]=getField(id));
    if(Object.values(importTax).some(value=>value!==''&&value!==null))special.import_taxes=importTax;
    else delete special.import_taxes;

    const item={
      product_id:product?.id||null,
      product_name:productName,
      product_sku:getField('nfeItemSku')||product?.sku||'',
      quantity,
      dimension_quantity:Math.max(number(getField('nfeItemDimensionQuantity'))||1,0),
      unit_price:number(getField('nfeItemUnitPrice')),
      freight:number(getField('nfeItemFreight')),
      insurance:number(getField('nfeItemInsurance')),
      other_expenses:number(getField('nfeItemOtherExpenses')),
      discount:number(getField('nfeItemDiscount')),
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

    closeModal(itemDialog);
    renderItems();
  });

  byId('nfeSaveReference')?.addEventListener('click',()=>{
    const key=getField('nfeReferenceKey').replace(/\D/g,'');
    const type=getField('nfeReferenceType')||'NFE';
    if(key.length!==44||!['NFE','CTE'].includes(type)){
      byId('nfeReferenceKey')?.focus();
      window.NextorNotify?.('Informe uma chave fiscal de 44 dígitos.',{type:'warning',title:'Documento referenciado'});
      return;
    }
    if(references.some(ref=>(ref.type||'NFE')===type&&ref.key===key)){
      byId('nfeReferenceKey')?.focus();
      return;
    }
    references.push({key,type});
    const referenceToggle=byId('nfeHasReferencedDocument');
    if(referenceToggle){
      referenceToggle.checked=true;
      referenceToggle.dispatchEvent(new Event('change',{bubbles:true}));
    }
    setField('nfeReferenceKey','');
    closeModal(byId('nfeReferenceDialog'));
    renderReferences();
  });

  byId('nfeSaveDuplicate')?.addEventListener('click',()=>{
    const value=number(getField('nfeDuplicateValue'));
    const due=getField('nfeDuplicateDue');
    if(value<=0||duplicates.length>=120){byId('nfeDuplicateValue')?.focus();return;}
    if(!/^\d{4}-\d{2}-\d{2}$/.test(due)){byId('nfeDuplicateDue')?.focus();return;}
    duplicates.push({
      number:getField('nfeDuplicateNumber'),
      payment_type:getField('nfeDuplicatePaymentType'),
      due_date:due,
      value,
    });
    setField('nfeDuplicateNumber','');
    setField('nfeDuplicateDue','');
    setField('nfeDuplicateValue','');
    closeModal(byId('nfeDuplicateDialog'));
    renderDuplicates();
  });

  document.addEventListener('click',event=>{
    if(readOnly())return;
    const edit=event.target.closest('[data-nfe-edit-item]');
    if(edit){openEditItem(Number(edit.dataset.nfeEditItem));return;}

    const removeItem=event.target.closest('[data-nfe-remove-item]');
    if(removeItem){items.splice(Number(removeItem.dataset.nfeRemoveItem),1);renderItems();return;}

    const removeReference=event.target.closest('[data-nfe-remove-reference]');
    if(removeReference){references.splice(Number(removeReference.dataset.nfeRemoveReference),1);renderReferences();return;}

    const removePayment=event.target.closest('[data-nfe-remove-payment]');
    if(removePayment){extraPayments.splice(Number(removePayment.dataset.nfeRemovePayment),1);renderExtraPayments();return;}

    const removeDuplicate=event.target.closest('[data-nfe-remove-duplicate]');
    if(removeDuplicate){duplicates.splice(Number(removeDuplicate.dataset.nfeRemoveDuplicate),1);renderDuplicates();}
  });

  byId('nfeDraftForm')?.addEventListener('submit',event=>{
    syncHidden();
    // Evita submissão dupla de um mesmo rascunho, inclusive em conexões lentas.
    const button=event.submitter;
    if(button && button.type==='submit'){
      if(button.dataset.nfeSubmitting==='1'){
        event.preventDefault();return;
      }
      button.dataset.nfeSubmitting='1';
      // Não desabilitar o submitter antes da serialização: seu name/value
      // after_save=validate precisa acompanhar o POST.
    }
  });

  const mainDialog=byId('nfeEditorDialog');
  document.body.classList.add('nfe-modal-open');

  document.addEventListener('keydown',event=>{
    // Um cadastro contextual é um <dialog> independente. Seus atalhos,
    // inclusive Esc, não podem fechar a NF-e que continua sendo editada.
    if(byId('erpCatalogDialog')?.open)return;
    if(readOnly() && ['F4','F6','F8'].includes(event.key)){
      event.preventDefault();return;
    }
    const openLayers=[...document.querySelectorAll('[data-nfe-modal].is-open:not([hidden])')];

    if(event.key==='Escape'){
      const top=openLayers.at(-1);
      if(top && top!==mainDialog){
        event.preventDefault();
        closeModal(top);
        return;
      }

      if(top===mainDialog){
        event.preventDefault();
        window.location.href=mainDialog.querySelector('.close-dialog')?.href||'/fiscal?tab=nfe';
        return;
      }
    }

    if(!modalIsOpen(mainDialog)) return;

    const topModal=openLayers.at(-1);
    if(topModal && topModal!==mainDialog) return;

    if((event.ctrlKey||event.metaKey) && event.key.toLowerCase()==='s'){
      event.preventDefault();
      syncHidden();
      const form=byId('nfeDraftForm');
      const saveButton=byId('nfeSaveDraft');
      if(form && saveButton)form.requestSubmit(saveButton);
      return;
    }

    if(event.key==='F4'){
      event.preventDefault();
      byId('nfeCustomer')?.focus();
      return;
    }

    if(event.key==='F6'){
      event.preventDefault();
      if(!modalIsOpen(itemDialog)) openNewItem();
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

  updateRecipientPreview();
  populateDeliveryAddresses();
  toggleDelivery();
  updatePaymentVisibility();
  updateExtraPaymentFields();
  if(getField('nfePaymentCondition')!=='a_vista' && byId('nfePaymentInstallments')){
    byId('nfePaymentInstallments').open=true;
  }
  renderExtraPayments();
  renderItems();
  renderReferences();
  renderDuplicates();
  toggleForeignTrade();
  syncHidden();
})();
