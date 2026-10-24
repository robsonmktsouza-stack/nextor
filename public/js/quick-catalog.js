document.addEventListener('DOMContentLoaded',()=>{
  'use strict';
  const dialog=document.getElementById('erpCatalogDialog');
  if(!dialog)return;
  const el=id=>document.getElementById(id);
  const picker=el('erpCatalogPicker'),form=el('erpCatalogCreate'),list=el('erpCatalogResults');
  const search=el('erpCatalogSearch'),title=el('erpCatalogTitle'),subtitle=el('erpCatalogSubtitle');
  const newButton=el('erpCatalogNew'),newLabel=el('erpCatalogNewLabel');
  const fields=el('erpCatalogFields'),error=el('erpCatalogError');
  const save=el('erpCatalogSave'),back=el('erpCatalogBack');
  const singular={products:'produto',customers:'cliente',suppliers:'fornecedor',carriers:'transportadora'};
  const labels={products:'Produtos',customers:'Clientes',suppliers:'Fornecedores',carriers:'Transportadoras'};
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
    wrapper.className='field catalog-col-'+(opts.cols||4);
    const caption=document.createElement('span');caption.textContent=label;wrapper.appendChild(caption);
    const field=document.createElement(type==='select'?'select':'input');
    if(type!=='select')field.type=type;
    field.name=name;
    if(opts.required)field.required=true;
    if(opts.maxLength)field.maxLength=opts.maxLength;
    if(opts.placeholder)field.placeholder=opts.placeholder;
    if(opts.value!==undefined)field.value=opts.value;
    if(type==='number'){
      field.min='0';
      field.step='0.01';
    }
    for(const option of opts.options||[])field.add(new Option(option[1],option[0]));
    wrapper.appendChild(field);
    return wrapper;
  };
  const checkbox=(name,label,checked)=>{
    const wrapper=document.createElement('label');
    wrapper.className='erp-catalog-toggle catalog-col-4';
    const field=document.createElement('input');
    field.type='checkbox';field.name=name;field.checked=checked;
    const text=document.createElement('span');text.textContent=label;
    wrapper.append(field,text);
    return wrapper;
  };
  const section=(heading,rows)=>{
    const group=document.createElement('section');
    group.className='erp-catalog-section';
    const title=document.createElement('h3');title.textContent=heading;
    const grid=document.createElement('div');grid.className='erp-catalog-fields-grid';
    grid.append(...rows);group.append(title,grid);
    return group;
  };
  const prepareFields=()=>{
    fields.replaceChildren();
    if(kind==='products'){
      fields.append(
        section('Dados do produto',[
          input('name','Descrição do produto *','text',{cols:8,required:true,maxLength:190,value:selectedQuery}),
          input('sku','Código próprio','text',{cols:4,maxLength:80,placeholder:'Gerado automaticamente se ficar vazio'}),
          input('unit','Unidade *','select',{cols:4,required:true,options:[['UN','Unidade'],['KG','Quilograma'],['G','Grama'],['LT','Litro'],['MT','Metro'],['CX','Caixa'],['PC','Peça'],['PCT','Pacote']]}),
          input('usage_type','Utilização *','select',{cols:4,options:[['resale','Revenda'],['consumption','Uso e consumo'],['raw_material','Matéria-prima'],['fixed_asset','Ativo imobilizado'],['packaging','Embalagem'],['other','Outros']]}),
          input('ean_gtin','GTIN / código de barras','text',{cols:4,maxLength:14,placeholder:'Opcional'}),
        ]),
        section('Valores e estoque',[
          input('cost_price','Preço de custo *','number',{cols:4,required:true,value:'0.00'}),
          input('sale_price','Preço de venda *','number',{cols:4,required:true,value:'0.00'}),
          checkbox('control_stock','Controlar estoque',true),
        ]),
        section('Dados fiscais',[
          input('ncm','NCM','text',{cols:4,maxLength:10,placeholder:'8 números'}),
          input('cest','CEST','text',{cols:4,maxLength:9,placeholder:'Se aplicável'}),
          input('origin','Origem *','select',{cols:4,required:true,options:[['0','0 - Nacional'],['1','1 - Importação direta'],['2','2 - Estrangeira no Brasil'],['3','3 - Nacional >40% importado'],['4','4 - Nacional PPB'],['5','5 - Nacional <=40%'],['6','6 - Importado sem similar'],['7','7 - Adquirido sem similar'],['8','8 - Nacional >70%']]}),
        ])
      );
    }else{
      fields.append(
        section('Identificação',[
          input('name','Nome / razão social *','text',{cols:8,required:true,maxLength:190,value:selectedQuery}),
          input('trade_name','Nome fantasia','text',{cols:4,maxLength:190}),
          input('document','CPF / CNPJ','text',{cols:4,maxLength:20}),
          input('ie_indicator','Indicador de inscrição estadual','select',{cols:4,options:[['non_contributor','Não contribuinte'],['contributor','Contribuinte'],['exempt','Isento']]}),
          input('state_registration','Inscrição estadual','text',{cols:4,maxLength:40}),
          input('phone','Telefone','text',{cols:4,maxLength:25}),
          input('email','E-mail','email',{cols:8,maxLength:255}),
        ]),
        section('Endereço',[
          input('zip_code','CEP','text',{cols:3,maxLength:10}),
          input('state','UF','text',{cols:2,maxLength:2}),
          input('city','Município','text',{cols:4,maxLength:120}),
          input('city_ibge_code','Código IBGE','text',{cols:3,maxLength:7}),
          input('address','Logradouro','text',{cols:8,maxLength:190}),
          input('address_number','Número','text',{cols:4,maxLength:30}),
          input('district','Bairro','text',{cols:6,maxLength:120}),
          input('address_complement','Complemento','text',{cols:6,maxLength:120}),
        ])
      );
    }
  };
  const close=()=>{
    abortController?.abort();clearTimeout(searchTimer);
    sequence++;
    if(dialog.open)dialog.close();
    returnFocus?.focus?.();
    onSelect=null;
  };
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
    subtitle.textContent='Preencha os dados e salve sem sair desta operação.';
    back.hidden=false;save.hidden=false;
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
    back.hidden=true;save.hidden=true;error.hidden=true;
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
    if(!form.reportValidity())return;
    const raw=Object.fromEntries(new FormData(form).entries());
    const digits=value=>String(value||'').replace(/\D/g,'');
    for(const name of ['ncm','cest','ean_gtin','document','zip_code','city_ibge_code']){
      if(raw[name])raw[name]=digits(raw[name]);
    }
    if(kind==='products')raw.control_stock=form.querySelector('[name="control_stock"]').checked?1:0;
    if(raw.state)raw.state=String(raw.state).trim().toUpperCase();
    save.disabled=true;save.textContent='Salvando...';
    try{
      const response=await fetch(dialog.dataset.storeUrl+'/'+kind,{
        method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json',
          'X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]')?.content||''},
        body:JSON.stringify(raw)
      });
      const data=await response.json();
      if(!response.ok){
        const invalid=data.errors||{};
        for(const name of Object.keys(invalid)){
          const field=[...fields.querySelectorAll('[name]')].find(node=>node.name===name);
          if(field)field.classList.add('catalog-invalid');
        }
        const msg=Object.values(invalid).flat().join(' ')||'Confira os dados informados.';
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
  back.addEventListener('click',showPicker);
  fields.addEventListener('input',event=>event.target.classList?.remove('catalog-invalid'));
  fields.addEventListener('change',event=>event.target.classList?.remove('catalog-invalid'));
  form.addEventListener('submit',submit);
  dialog.querySelectorAll('[data-catalog-close]').forEach(button=>button.addEventListener('click',close));
  dialog.addEventListener('cancel',event=>{event.preventDefault();close();});
  window.LumeronCatalog={pick,create};
});
