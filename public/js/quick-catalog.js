document.addEventListener('DOMContentLoaded',()=>{
  'use strict';
  const dialog=document.getElementById('erpCatalogDialog');
  if(!dialog)return;
  const el=id=>document.getElementById(id);
  const picker=el('erpCatalogPicker'),form=el('erpCatalogCreate'),list=el('erpCatalogResults');
  const search=el('erpCatalogSearch'),title=el('erpCatalogTitle'),subtitle=el('erpCatalogSubtitle');
  const newButton=el('erpCatalogNew'),newLabel=el('erpCatalogNewLabel');
  const fields=el('erpCatalogFields'),error=el('erpCatalogError');
  const save=el('erpCatalogSave');
  const singular={products:'produto',customers:'cliente',suppliers:'fornecedor'};
  const labels={products:'Produtos',customers:'Clientes',suppliers:'Fornecedores'};
  let kind='products',onSelect=null,returnFocus=null,abortController=null,sequence=0;
  let searchTimer=null,localItems=[],selectedQuery='',isCreating=false,createDefaults={};
  const canCreate=type=>dialog.dataset[type==='products'?'catalogProducts':'catalogCustomers']==='1';
  const norm=text=>String(text||'').normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLocaleLowerCase('pt-BR');
  const labelItem=item=>item.sku ? item.sku+' — '+item.name : item.name;
  const description=item=>[item.document,item.unit ? 'Unidade '+item.unit:null,
    item.sale_price!=null ? 'Venda R$ '+Number(item.sale_price).toLocaleString('pt-BR',{minimumFractionDigits:2}):null]
    .filter(Boolean).join(' · ');

  const input=(name,label,type='text',opts={})=>{
    const wrapper=document.createElement('label');
    if(opts.wide)wrapper.className='catalog-wide';
    const caption=document.createElement('span');caption.textContent=label;wrapper.appendChild(caption);
    const field=document.createElement(type==='select'?'select':'input');
    if(type!=='select')field.type=type;
    field.name=name;
    if(opts.required)field.required=true;
    if(opts.maxLength)field.maxLength=opts.maxLength;
    if(opts.placeholder)field.placeholder=opts.placeholder;
    if(opts.value!==undefined)field.value=opts.value;
    if(type==='number'){field.min='0';field.step='0.01';}
    for(const option of opts.options||[])field.add(new Option(option[1],option[0]));
    wrapper.appendChild(field);
    return wrapper;
  };
  const checkbox=(name,label,checked)=>{
    const wrapper=document.createElement('label');wrapper.className='catalog-check catalog-wide';
    const field=document.createElement('input');field.type='checkbox';field.name=name;field.checked=checked;
    const text=document.createElement('span');text.textContent=label;wrapper.append(field,text);return wrapper;
  };
  const prepareFields=()=>{
    fields.replaceChildren();
    if(kind==='products'){
      fields.append(
        input('name','Nome / descrição *','text',{wide:true,required:true,maxLength:190,value:selectedQuery}),
        input('sku','Código próprio (opcional)','text',{maxLength:80}),
        input('unit','Unidade *','select',{required:true,options:[['UN','Unidade'],['KG','Quilograma'],['G','Grama'],['LT','Litro'],['MT','Metro'],['CX','Caixa'],['PC','Peça'],['PCT','Pacote']]}),
        input('cost_price','Preço de custo *','number',{required:true,value:'0.00'}),
        input('sale_price','Preço de venda *','number',{required:true,value:'0.00'}),
        input('ncm','NCM (8 dígitos, se houver NF-e)','text',{maxLength:8}),
        input('origin','Origem *','select',{options:[['0','0 - Nacional'],['1','1 - Importação direta'],['2','2 - Estrangeira no Brasil'],['3','3 - Nacional >40% importado'],['4','4 - Nacional PPB'],['5','5 - Nacional <=40%'],['6','6 - Importado sem similar'],['7','7 - Adquirido sem similar'],['8','8 - Nacional >70%']]}),
        input('usage_type','Finalidade *','select',{options:[['resale','Revenda'],['consumption','Consumo'],['raw_material','Matéria-prima'],['fixed_asset','Ativo imobilizado'],['packaging','Embalagem'],['other','Outros']]}),
        checkbox('control_stock','Controlar estoque',true)
      );
    }else{
      fields.append(
        input('name','Nome / razão social *','text',{required:true,wide:true,maxLength:190,value:selectedQuery}),
        input('document','CPF / CNPJ','text',{maxLength:20}),
        input('phone','Telefone','text',{maxLength:25}),
        input('email','E-mail','email',{wide:true,maxLength:255}),
        input('ie_indicator','Indicador IE','select',{options:[['non_contributor','Não contribuinte'],['contributor','Contribuinte'],['exempt','Isento']]}),
        input('state_registration','Inscrição estadual','text',{maxLength:40}),
        input('state','UF','text',{maxLength:2}),
        input('city','Município','text',{maxLength:120}),
        input('city_ibge_code','Código IBGE','text',{maxLength:10}),
        input('address','Endereço','text',{maxLength:190}),
        input('address_number','Número','text',{maxLength:30})
      );
    }
  };
  const close=()=>{abortController?.abort();clearTimeout(searchTimer);if(dialog.open)dialog.close();returnFocus?.focus?.();onSelect=null;};
  const choose=item=>{
    const callback=onSelect;
    close();
    document.dispatchEvent(new CustomEvent('lumeron:catalog-created',{detail:{kind,item}}));
    callback?.(item);
  };
  const render=(items)=>{
    list.replaceChildren();
    if(!items.length){
      const empty=document.createElement('div');empty.className='erp-catalog-empty';
      empty.textContent='Nenhum '+singular[kind]+' encontrado. Você pode cadastrá-lo abaixo.';
      list.appendChild(empty);return;
    }
    for(const item of items){
      const button=document.createElement('button');button.type='button';button.className='erp-catalog-result';
      button.setAttribute('role','option');
      const strong=document.createElement('strong');strong.textContent=labelItem(item);
      const small=document.createElement('small');small.textContent=description(item);
      button.append(strong,small);
      button.addEventListener('click',()=>choose(item));
      list.appendChild(button);
    }
  };
  const remoteSearch=async(q)=>{
    const seq=++sequence;
    abortController?.abort();
    abortController=new AbortController();
    try{
      const url=dialog.dataset.searchUrl+'/'+kind+'?q='+encodeURIComponent(q);
      const result=await fetch(url,{headers:{Accept:'application/json'},signal:abortController.signal});
      if(!result.ok)throw Error('Não foi possível consultar o cadastro.');
      const data=await result.json();
      if(seq===sequence && !isCreating)render(data.items||[]);
    }catch(e){
      if(e.name!=='AbortError'&&seq===sequence){
        const matches=localItems.filter(item=>norm(labelItem(item)).includes(norm(q))).slice(0,25);
        render(matches);
      }
    }
  };
  const updateQuery=()=>{
    selectedQuery=search.value.trim().slice(0,190);
    newLabel.textContent=selectedQuery?'Cadastrar "'+selectedQuery+'"':'Cadastrar novo '+singular[kind];
    const matches=localItems.filter(item=>norm(labelItem(item)).includes(norm(selectedQuery))).slice(0,25);
    render(matches);
    clearTimeout(searchTimer);
    searchTimer=setTimeout(()=>remoteSearch(selectedQuery),240);
  };
  const showCreate=(name)=>{
    if(!canCreate(kind))return;
    isCreating=true;selectedQuery=String(name??search.value).trim().slice(0,190);
    picker.hidden=true;form.hidden=false;error.hidden=true;title.textContent='Cadastrar '+singular[kind];
    prepareFields();
    for(const [key,value] of Object.entries(createDefaults)){
      const control=[...fields.querySelectorAll('[name]')].find(node=>node.name===key);
      if(control && value!==null && value!==undefined){
        if(control.type==='checkbox')control.checked=Boolean(value);
        else control.value=String(value);
      }
    }
    fields.querySelector('[name="name"]')?.focus();
  };
  const showPicker=()=>{
    isCreating=false;form.hidden=true;picker.hidden=false;
    title.textContent='Selecionar '+singular[kind];
    subtitle.textContent='Pesquise um cadastro existente ou adicione um novo.';
    search.focus();updateQuery();
  };
  const pick=(type,options={})=>{
    if(!singular[type])return;
    if(dialog.open)close();
    kind=type;onSelect=typeof options.onSelect==='function'?options.onSelect:null;
    returnFocus=document.activeElement;
    localItems=Array.isArray(options.items)?options.items:[];
    createDefaults=options.defaults && typeof options.defaults==='object'?options.defaults:{};
    selectedQuery=String(options.initial||'');
    search.value=selectedQuery;
    newButton.disabled=!canCreate(kind);
    newButton.title=canCreate(kind)?'Cadastrar sem sair desta tela':'Cadastro indisponível para seu perfil';
    subtitle.textContent='Pesquise um cadastro existente ou adicione um novo.';
    if(typeof dialog.showModal==='function')dialog.showModal();
    else dialog.setAttribute('open','');
    showPicker();
  };
  const create=(type,options={})=>{
    pick(type,options);showCreate(options.initial??'');
  };
  const submit=async event=>{
    event.preventDefault();
    if(save.disabled)return;
    error.hidden=true;
    const raw=Object.fromEntries(new FormData(form).entries());
    if(kind==='products')raw.control_stock=form.querySelector('[name="control_stock"]').checked?1:0;
    save.disabled=true;save.textContent='Salvando...';
    try{
      const response=await fetch(dialog.dataset.storeUrl+'/'+kind,{
        method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json',
          'X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]')?.content||''},
        body:JSON.stringify(raw)
      });
      const data=await response.json();
      if(!response.ok){
        const msg=Object.values(data.errors||{}).flat().join(' ')||data.message||'Confira os dados.';
        throw new Error(msg);
      }
      choose(data.item);
    }catch(e){
      error.textContent=e.message||'Não foi possível salvar o cadastro.';
      error.hidden=false;
    }finally{
      save.disabled=false;save.textContent='Salvar cadastro';
    }
  };
  search.addEventListener('input',updateQuery);
  search.addEventListener('keydown',e=>{if(e.key==='Enter'){
    e.preventDefault();const result=list.querySelector('.erp-catalog-result');
    if(result)result.click();else if(canCreate(kind))showCreate(search.value);
  }});
  newButton.addEventListener('click',()=>showCreate(search.value));
  el('erpCatalogBack').addEventListener('click',showPicker);
  form.addEventListener('submit',submit);
  dialog.querySelector('[data-catalog-close]').addEventListener('click',close);
  dialog.addEventListener('cancel',event=>{event.preventDefault();close();});
  window.LumeronCatalog={pick,create};
});
