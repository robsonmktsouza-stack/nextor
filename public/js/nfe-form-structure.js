/* Organização semântica do formulário NF-e.
 * Mantém todos os controles e IDs originais no DOM para preservar o motor fiscal. */
(()=>{
'use strict';
const start=()=>{
  const editor=document.getElementById('nfeEditorDialog');
  const item=document.getElementById('nfeItemDialog');
  if(!editor||!item)return;
  const findField=(node,identifier)=>identifier.startsWith('@')
    ?!!node.querySelector('[name="'+identifier.slice(1)+'"]')
    :!!node.querySelector('[id="'+identifier+'"]');
  const makeGroup=(title,description,fields,options={})=>{
    const wrapper=document.createElement(options.collapsible?'details':'section');
    wrapper.className='nfe-field-group'+(options.compact?' nfe-group-compact':'');
    wrapper.dataset.nfeGroup=options.key||title;
    const head=document.createElement(options.collapsible?'summary':'div');
    head.className='nfe-field-group-heading';
    const heading=document.createElement('strong');
    heading.textContent=title;
    head.append(heading);
    if(description){
      const help=document.createElement('small');
      help.textContent=description;
      head.append(help);
    }
    wrapper.append(head);
    const grid=document.createElement('div');
    grid.className='editor-grid cols-12 nfe-field-group-fields';
    fields.forEach(field=>grid.append(field));
    wrapper.append(grid);
    if(options.collapsible){
      const populated=fields.some(field=>[...field.querySelectorAll('input,select,textarea')].some(input=>input.type!=='hidden'&&input.value!==''&&input.value!=='0'));
      wrapper.open=populated||!!options.open;
    }
    return wrapper;
  };
  const groupGrid=(selector,sections,options={})=>{
    const grid=document.querySelector(selector);
    if(!grid||grid.dataset.nfeStructured==='1')return null;
    const direct=[...grid.children].filter(child=>child.classList.contains('field'));
    if(!direct.length)return null;
    const assigned=new Set();
    const fragments=[];
    for(const section of sections){
      const matched=[];
      for(const id of section.ids){
        const field=direct.find(node=>!assigned.has(node)&&findField(node,id));
        if(field){assigned.add(field);matched.push(field);}
      }
      if(matched.length)fragments.push(makeGroup(section.title,section.description,matched,{...options,...section}));
    }
    const remaining=direct.filter(field=>!assigned.has(field));
    if(remaining.length)fragments.push(makeGroup(options.otherTitle||'Outras informações','',remaining,options));
    // Reutiliza os mesmos nós. Eventos, valores e atributos de validação não são clonados.
    grid.classList.add('nfe-structured-grid');
    grid.dataset.nfeStructured='1';
    fragments.forEach(fragment=>grid.append(fragment));
    return grid;
  };
  const group=(selector,blocks,options={})=>groupGrid(selector,blocks.map(([title,ids,description,extra])=>({title,ids,description,...extra})),options);
  const main='#nfe-section-general .nfe-general-fields > .editor-grid';
  group(main,[
    ['Natureza e operação',['nfeNatureSearch','nfeOperationType','nfeDestination','nfeFiscalOperation','nfePurpose'],'Identificação e enquadramento da operação'],
    ['Consumidor e atendimento',['nfePresence','nfeFinalConsumer'],'Presença do comprador e destino do consumo'],
    ['Documentos e situações especiais',['@substitute_state_registration','nfeHasReferencedDocument','@government_purchase','@advance_payment'],'Informações complementares da operação'],
    ['Controle de datas',['nfeInformIssueDatetime','nfeInformExitDatetime','nfeInformExpectedDeliveryDate'],'Defina quais datas serão informadas']
  ]);
  const dates=editor.querySelector('.nfe-general-dates');
  if(dates&&!dates.querySelector('.nfe-standalone-title')){
    const title=document.createElement('div');title.className='nfe-standalone-title';title.textContent='Datas e horários';dates.prepend(title);
  }
  const delivery=editor.querySelector('.nfe-delivery-panel');
  if(delivery&&!delivery.querySelector('.nfe-standalone-title')){
    const title=document.createElement('div');title.className='nfe-standalone-title';title.textContent='Local de entrega';delivery.prepend(title);
  }
  group('#nfeDeliveryFields .editor-grid',[
    ['Identificação do recebedor',['nfeDeliveryAddressSelect','nfeDeliveryName','nfeDeliveryDocument']],
    ['Endereço para entrega',['nfeDeliveryZip','nfeDeliveryAddress','nfeDeliveryNumber','nfeDeliveryComplement','nfeDeliveryDistrict']],
    ['Município e localização',['nfeDeliveryCity','nfeDeliveryState','nfeDeliveryIbge']]
  ]);
  const items=editor.querySelector('#nfe-section-items > .cms-card');
  if(items&&!items.querySelector('.nfe-standalone-title')){
    const title=document.createElement('h3');title.className='nfe-standalone-title';title.textContent='Produtos da NF-e';items.prepend(title);
  }
  group('#nfe-section-items .nfe-totals-grid',[
    ['Valores da operação',['nfeTotalProducts','nfeTotalFreight','nfeTotalInsurance','nfeTotalOtherExpenses']],
    ['Tributos da nota',['nfeTotalIcms','nfeTotalIcmsSt','nfeTotalFcpSt','nfeTotalFcp','nfeTotalIpi','nfeTotalPis','nfeTotalCofins']],
    ['IBS e CBS',['nfeTotalIbsUf','nfeTotalIbsMunicipal','nfeTotalCbs']],
    ['Ajustes e valor final',['nfeTotalDiscount','nfeTotalSurcharge','nfeDiscount','nfeSurcharge','nfeTotalInvoice']]
  ],{compact:true,otherTitle:'Demais totalizadores'});
  const primary=editor.querySelector('#nfe-section-payment > .editor-panel');
  if(primary&&!primary.querySelector('h3')){
    const h=document.createElement('h3');h.className='nfe-standalone-title';h.textContent='Pagamento principal';primary.prepend(h);
  }
  group('#nfe-section-transport > .editor-panel > .editor-grid',[
    ['Frete e transportadora',['nfeFreightMode','@carrier_id','nfeFreightValue']],
    ['Identificação do veículo',['@vehicle_plate','@vehicle_state']],
    ['Volumes transportados',['@volume_quantity','@volume_species','@volume_numbering']],
    ['Pesos',['@net_weight','@gross_weight']]
  ]);
  const notes=editor.querySelector('#nfe-section-other .notes-panel');
  if(notes&&!notes.dataset.nfeStructured){
    const specs=[['Informações da operação','additional_info'],['Informações para o Fisco','tax_authority_info']];
    for(const [title,name] of specs){
      const field=notes.querySelector('[name="'+name+'"]')?.closest('.field');
      if(!field)continue;
      const section=makeGroup(title,'',[field],{compact:false});
      notes.append(section);
    }
    notes.dataset.nfeStructured='1';
  }
  group('#nfeItemDialog [data-nfe-item-panel="item-general"] .nfe-item-section-content > .editor-panel > .editor-grid',[
    ['Produto',['nfeItemProductSearch','nfeQuickCreateProduct','nfeItemName','nfeItemSku']],
    ['Quantidade e preço',['nfeItemQuantity','nfeItemDimensionQuantity','nfeItemUnitPrice','nfeItemSubtotal']],
    ['Composição do valor',['nfeItemFreight','nfeItemInsurance','nfeItemOtherExpenses','nfeItemDiscount']],
    ['Pedido e observações',['nfeItemPurchaseOrder','nfeItemPurchaseOrderItem','nfeItemNotes']]
  ]);
  group('#nfeItemDialog [data-nfe-item-panel="item-fiscal"] .nfe-item-section-content > .editor-panel > .editor-grid',[
    ['Classificação fiscal',['nfeItemNcm','nfeItemCest','nfeItemBenefit','nfeItemIpiException','nfeItemOrigin']],
    ['Identificação e unidades',['nfeItemGtin','nfeItemUnit','nfeItemTaxUnit','nfeTaxQuantityFactor']],
    ['CFOP da operação',['nfeItemCfop']]
  ]);
  group('#nfeItemDialog [data-nfe-item-panel="item-fiscal"] .nfe-item-section-content > .editor-panel:nth-of-type(2) > .editor-grid',[
    ['Saídas',['nfeCfopOutboundInternal','nfeCfopOutboundInterstate','nfeCfopOutboundForeign']],
    ['Entradas',['nfeCfopInboundInternal','nfeCfopInboundInterstate','nfeCfopInboundForeign']]
  ],{compact:true});
  group('#nfeItemDialog [data-nfe-item-panel="item-icms"] .nfe-item-section-content > .editor-panel > .editor-grid',[
    ['Situação tributária',['nfeTaxCsosn','nfeTaxIcmsCst','nfeTaxCsosnExport','nfeTaxCsosnInbound','nfeTaxIcmsCstInbound']],
    ['ICMS próprio e crédito',['nfeTaxIcmsRate','nfeTaxIcmsBase','nfeTaxModBc','nfeTaxBaseReduction','nfeTaxIcmsDeferment','nfeTaxSimpleCredit','nfeTaxOwnFcp']],
    ['Substituição tributária — ICMS-ST',['nfeTaxModBcSt','nfeTaxIcmsStRate','nfeTaxMvaRate','nfeTaxStBase','nfeTaxStDeduction','nfeTaxStReduction','nfeTaxStFcp'],'Preencher quando a operação exigir ST',{collapsible:true,key:'icms-st'}],
    ['Desoneração',['nfeTaxDesonerationAmount','nfeTaxDesonerationReason','nfeTaxDesonerationDeduct'],'Preencher somente quando aplicável',{collapsible:true,key:'icms-exempt'}]
  ]);
  group('#nfeItemDialog [data-nfe-item-panel="item-federal"] .nfe-item-section-content > .editor-panel > .editor-grid',[
    ['PIS — situação e alíquota',['nfeTaxPisCst','nfeTaxPisCstInbound','nfeTaxPisRate']],
    ['PIS — base e cálculo',['nfeTaxPisCalculationType','nfeTaxPisBase','nfeTaxPisQuantity','nfeTaxPisUnitRate']],
    ['COFINS — situação e alíquota',['nfeTaxCofinsCst','nfeTaxCofinsCstInbound','nfeTaxCofinsRate']],
    ['COFINS — base e cálculo',['nfeTaxCofinsCalculationType','nfeTaxCofinsBase','nfeTaxCofinsQuantity','nfeTaxCofinsUnitRate']],
    ['IPI — situação e alíquota',['nfeTaxIpiCst','nfeTaxIpiCstInbound','nfeTaxIpiRate','nfeTaxIpiEnq']],
    ['IPI — cálculo por unidade',['nfeTaxIpiQuantity','nfeTaxIpiUnitRate']],
    ['Serviços, quando aplicável',['nfeTaxIssRate','nfeTaxServiceListCode'],'Informações adicionais',{collapsible:true,key:'federal-services'}]
  ]);
  group('#nfeItemDialog [data-nfe-item-panel="item-ibscbs"] .nfe-item-section-content > .editor-panel > .editor-grid',[
    ['Classificação tributária',['nfeTaxIbsCst','nfeTaxCbsCst','nfeTaxClassification']],
    ['Base e alíquotas',['nfeTaxIbsCbsBase','nfeIbsUfRate','nfeIbsMunicipalRate','nfeCbsRate']],
    ['Reduções',['nfeIbsUfReduction','nfeIbsMunicipalReduction','nfeCbsReduction']]
  ]);
  group('#nfeItemDialog [data-nfe-item-panel="item-difal"] .nfe-item-section-content > .editor-panel > .editor-grid',[
    ['Alíquotas de destino',['nfeTaxInterstateRate','nfeTaxInternalRate','nfeTaxFcpRate']],
    ['Bases e partilha',['nfeTaxDifalBase','nfeTaxDifalFcpBase','nfeTaxDifalShare']]
  ]);
  group('#nfeItemDialog [data-nfe-item-panel="item-specific"] .nfe-item-section-content > .editor-panel:first-child > .editor-grid',[
    ['Combustíveis e ANP',['nfeSpecialPetroleum','nfeSpecialAnpCode','nfeSpecialAnpDescription']],
    ['Composição e valor',['nfeSpecialGlp','nfeSpecialGnn','nfeSpecialGni','nfeSpecialStartingValue']]
  ]);
  group('#nfeItemDialog [data-nfe-item-panel="item-specific"] .nfe-item-section-content > .editor-panel:nth-of-type(2) > .editor-grid',[
    ['Lote e validade',['nfeSpecialBatch','nfeSpecialExpiry']],
    ['Veículo',['nfeSpecialRenavam','nfeSpecialChassis','nfeSpecialPlate','nfeSpecialFuel','nfeSpecialYearModel','nfeSpecialVehicleColor']]
  ],{compact:true});
  const taxHeading=item.querySelector('.nfe-tax-review-heading span');
  if(taxHeading)taxHeading.textContent='Tributação do item';
  const refresh=()=>{
    item.querySelectorAll('[data-nfe-item-panel="item-federal"] .nfe-field-group').forEach(group=>{
      const fields=[...group.querySelectorAll('.field[data-nfe-tax-group]')];
      if(fields.length)group.hidden=fields.every(field=>field.hidden||field.style.display==='none');
    });
  };
  window.nfeStructureRefresh=refresh;
  item.querySelectorAll('[data-nfe-tax-tab]').forEach(button=>button.addEventListener('click',refresh));
  refresh();
  // No Simples Nacional, CST e CSOSN não precisam ser exibidos simultaneamente.
  const crtNode=document.getElementById('nfe-emitter-crt');
  let crt='';
  try{crt=String(JSON.parse(crtNode?.textContent||'null')||'');}catch(_){}
  const simple=['1','4'].includes(crt);
  const byField=id=>document.getElementById(id)?.closest('.field');
  for(const id of ['nfeTaxCsosn','nfeTaxCsosnExport','nfeTaxCsosnInbound']){
    const node=byField(id);if(node)node.hidden=!simple;
  }
  for(const id of ['nfeTaxIcmsCst','nfeTaxIcmsCstInbound']){
    const node=byField(id);if(node)node.hidden=simple;
  }
  const st=item.querySelector('[data-nfe-group="icms-st"]');
  const stCode=()=>String(document.getElementById(simple?'nfeTaxCsosn':'nfeTaxIcmsCst')?.value||'').trim();
  const expandSt=()=>{
    if(st&&(/^(201|202|203|500|900)$/.test(stCode())&&simple||/^(10|30|70|90|60)$/.test(stCode())&&!simple))st.open=true;
  };
  byField('nfeTaxCsosn')?.addEventListener('change',expandSt);
  byField('nfeTaxIcmsCst')?.addEventListener('change',expandSt);
  expandSt();
};
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start,{once:true});
else start();
})();
