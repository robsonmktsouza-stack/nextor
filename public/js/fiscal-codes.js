/* Busca assistida de códigos fiscais, reutilizada pelos formulários do NEXTOR.
   O campo enviado ao servidor continua contendo exclusivamente o código. */
(() => {
  'use strict';

  const endpoint=document.body.dataset.fiscalCatalogUrl;
  if(!endpoint)return;

  const fieldType=name=>{
    const match=String(name||'').match(/\[([^\]]+)\]$/);
    const raw=(match?match[1]:String(name||'')).replace(/_default$/,'');
    if(raw==='origin'||raw==='icms_origin')return 'origin';
    if(raw==='crt')return 'crt';
    if(['ibs_cbs_class','tax_classification_code','cclasstrib'].includes(raw))return 'ibs_cbs_class';
    if(raw.includes('csosn'))return 'csosn';
    if(/^icms_cst(?:_|$)/.test(raw))return 'icms_cst';
    if(/^(?:pis_cst|cofins_cst)(?:_|$)/.test(raw))return 'pis_cofins_cst';
    if(/^ipi_cst(?:_|$)/.test(raw))return 'ipi_cst';
    if(/^(?:ibs_cbs_cst|ibs_cst|cbs_cst)(?:_|$)/.test(raw))return 'ibs_cbs_cst';
    if(raw==='mod_bc')return 'icms_mod_bc';
    if(raw==='mod_bc_st')return 'icms_mod_bc_st';
    if(raw==='iss_exigibility')return 'iss_exigibility';
    if(raw==='cfop_pattern')return 'cfop_pattern';
    if(raw==='cfop'||raw==='default_cfop'||raw.startsWith('cfop_')||raw==='nfce_cfop')return 'cfop';
    return null;
  };

  const codeInputs=root=>[...root.querySelectorAll('input[name]')].filter(input=>
    !['hidden','checkbox','radio','number','date','password'].includes(input.type)
    && !input.dataset.fiscalCatalogReady
    && !input.hasAttribute('data-no-fiscal-catalog')
    && fieldType(input.name)
  );
  const normalize=text=>String(text??'').normalize('NFD')
    .replace(/[\u0300-\u036f]/g,'').toLocaleLowerCase('pt-BR').trim();

  let catalogs=null;
  let request=null;
  let nextId=0;
  const loadCatalogs=()=>{
    if(catalogs)return Promise.resolve(catalogs);
    if(!request)request=fetch(endpoint,{
      credentials:'same-origin',headers:{Accept:'application/json'},
    }).then(response=>{
      if(!response.ok)throw Error('Catálogo fiscal indisponível');
      return response.json();
    }).then(data=>{
      catalogs=data.catalogs||{};
      return catalogs;
    }).catch(()=>{
      // Se a API falhar, o campo original continua funcionando.
      request=null;
      return null;
    });
    return request;
  };

  const setup=(source,data)=>{
    const type=fieldType(source.name);
    if(!type||!data[type]||source.dataset.fiscalCatalogReady)return;
    const name=source.name;
    const form=source.closest('form');
    const oldValue=source.value;
    const mandatory=source.required;
    const originalClasses=source.className;
    let committedValue=oldValue;
    const cfopPrefix=(()=>{
      if(/outbound_internal/.test(name))return '5';
      if(/outbound_interstate|state_variations/.test(name))return '6';
      if(/inbound_internal/.test(name))return '1';
      if(/inbound_interstate/.test(name))return '2';
      if(name==='cfop'&&/\/fiscal\/rules(?:\/|$)/.test(form?.action||''))return '5';
      return '';
    })();

    const rawEntries=type==='cfop_pattern'
      ? {...(data.cfop||{}),...(data.cfop_pattern||{})}
      : type==='cfop'&&cfopPrefix
        ? Object.fromEntries(Object.entries(data.cfop||{}).filter(([code])=>code.startsWith(cfopPrefix)))
        : data[type];
    const entries={...rawEntries};
    if(oldValue!==''&&!Object.prototype.hasOwnProperty.call(entries,oldValue)){
      entries[oldValue]='Código já cadastrado (conferir)';
    }
    const catalogItems=Object.entries(entries).map(([code,description])=>({
      code,description,search:normalize(code+' '+description),
    }));

    const wrapper=document.createElement('div');
    wrapper.className='fiscal-code-field';
    const search=document.createElement('input');
    search.type='search';
    search.className=(originalClasses+' fiscal-code-search').trim();
    search.autocomplete='off';
    search.spellcheck=false;
    search.placeholder='Pesquisar código ou descrição...';
    search.required=mandatory;
    search.disabled=source.disabled;
    search.readOnly=source.readOnly;
    search.setAttribute('role','combobox');
    search.setAttribute('aria-autocomplete','list');
    search.setAttribute('aria-expanded','false');
    if(source.getAttribute('aria-label'))search.setAttribute('aria-label',source.getAttribute('aria-label'));
    if(source.id){
      search.id=source.id;
      source.removeAttribute('id');
    }

    const list=document.createElement('div');
    list.className='fiscal-code-results';
    list.hidden=true;
    list.setAttribute('role','listbox');
    list.id='fiscal-code-results-'+(++nextId);
    search.setAttribute('aria-controls',list.id);
    search.setAttribute('aria-haspopup','listbox');

    // Guardar apenas o código no formulário, sem a descrição apresentada.
    source.dataset.fiscalCatalogReady='1';
    source.required=false;
    source.type='hidden';
    source.replaceWith(wrapper);
    wrapper.append(source,search,list);

    const allowManual=['cfop','cfop_pattern','ibs_cbs_class'].includes(type);
    const manualPattern=type==='ibs_cbs_class'?/^\d{6}$/
      : type==='cfop_pattern'?/^(?:[1-7]\d{3}|x\d{3})$/
      :/^[1-7]\d{3}$/;

    const getCst=()=>{
      if(type!=='ibs_cbs_class')return '';
      const cst=form?.querySelector(
        '[name="tax_config[ibs_cbs_cst]"],[name="tax_defaults[ibs_cst]"],[name="ibs_cst_default"]'
      );
      return /^\d{3}$/.test(cst?.value||'')?cst.value:'';
    };
    const isAllowed=code=>{
      const cst=getCst();
      return !cst||type!=='ibs_cbs_class'||code.startsWith(cst);
    };
    const display=code=>{
      if(!code)return '';
      const description=entries[code]||'Código informado (conferir)';
      const brief=description.length>38 ? description.slice(0,36).trimEnd()+'…' : description;
      search.title=code+' — '+description;
      return code+' — '+brief;
    };
    const validate=()=>{
      const code=source.value;
      if(!code){
        search.setCustomValidity(search.value.trim()
          ? 'Escolha uma opção da lista.' : '');
      }else if(!isAllowed(code)){
        search.setCustomValidity('A classificação não corresponde ao CST selecionado.');
      }else{
        search.setCustomValidity('');
      }
    };
    const sync=()=>{
      // Permite que outras rotinas alterem o valor original por "change".
      const code=source.value;
      committedValue=code;
      if(code&&!Object.prototype.hasOwnProperty.call(entries,code)){
        entries[code]='Código já cadastrado (conferir)';
        catalogItems.push({code,description:entries[code],search:normalize(code+' '+entries[code])});
      }
      search.value=display(code);
      validate();
    };
    let highlighted=0;
    const available=()=>[...list.querySelectorAll('button.fiscal-code-result')];
    const setActive=index=>{
      const items=available();
      if(!items.length)return;
      highlighted=Math.max(0,Math.min(index,items.length-1));
      items.forEach((node,i)=>{
        node.classList.toggle('active',i===highlighted);
        node.setAttribute('aria-selected',i===highlighted?'true':'false');
      });
      const active=items[highlighted];
      search.setAttribute('aria-activedescendant',active.id);
      active.scrollIntoView({block:'nearest'});
    };
    const close=()=>{
      wrapper.classList.remove('open');
      list.hidden=true;
      search.setAttribute('aria-expanded','false');
      search.removeAttribute('aria-activedescendant');
    };
    const open=()=>{
      if(search.disabled)return;
      wrapper.classList.add('open');
      list.hidden=false;
      search.setAttribute('aria-expanded','true');
      // Lista no fluxo do formulário: abre abaixo do campo e move a
      // próxima linha, sem cobrir os outros impostos nem cards adjacentes.
    };

    const choose=code=>{
      source.value=code;
      sync();
      close();
      source.dispatchEvent(new Event('change',{bubbles:true}));
      search.focus({preventScroll:true});
    };
    const option=(code,label,extraClass='')=>{
      const row=document.createElement('button');
      row.type='button';
      row.className='fiscal-code-result'+(extraClass?' '+extraClass:'');
      row.id=list.id+'-option-'+list.childElementCount;
      row.setAttribute('role','option');
      const codeLabel=document.createElement('strong');
      codeLabel.textContent=code;
      const description=document.createElement('span');
      description.textContent=label;
      row.append(codeLabel,description);
      row.addEventListener('pointerdown',event=>event.preventDefault());
      row.addEventListener('click',()=>choose(code));
      list.appendChild(row);
    };
    const render=query=>{
      const q=normalize(query);
      list.replaceChildren();
      const matches=catalogItems.filter(item=>isAllowed(item.code)&&item.search.includes(q));
      matches.slice(0,7).forEach(item=>option(item.code,item.description));
      if(allowManual&&manualPattern.test(String(query).trim())
        && !matches.some(item=>item.code===String(query).trim())
        && isAllowed(String(query).trim())){
        option(String(query).trim(),'Usar este código (conferir)','manual');
      }
      if(!available().length){
        const empty=document.createElement('p');
        empty.className='fiscal-code-empty';
        empty.textContent='Nenhum código encontrado';
        list.appendChild(empty);
      }else if(matches.length>7){
        const help=document.createElement('p');
        help.className='fiscal-code-empty';
        help.textContent='Continue digitando para ver mais resultados';
        list.appendChild(help);
      }
      highlighted=0;
      const items=available();
      items.forEach((row,index)=>{
        row.classList.toggle('active',index===0);
        row.setAttribute('aria-selected',index===0?'true':'false');
      });
      if(items[0])search.setAttribute('aria-activedescendant',items[0].id);
      else search.removeAttribute('aria-activedescendant');
      open();
    };

    search.addEventListener('focus',()=>{
      if(search.readOnly)return;
      render('');
      if(source.value)search.select();
    });
    search.addEventListener('input',()=>{
      const typed=search.value;
      source.value='';
      source.dispatchEvent(new Event('change',{bubbles:true}));
      validate();
      render(typed);
    });
    search.addEventListener('keydown',event=>{
      if(event.key==='ArrowDown'||event.key==='ArrowUp'){
        event.preventDefault();
        if(!wrapper.classList.contains('open'))render('');
        else setActive(highlighted+(event.key==='ArrowDown'?1:-1));
        return;
      }
      if(event.key==='Enter'&&wrapper.classList.contains('open')){
        const current=available()[highlighted];
        if(current){
          event.preventDefault();
          current.click();
        }else{
          event.preventDefault();
        }
      }
      if(event.key==='Escape'&&wrapper.classList.contains('open')){
        event.preventDefault();
        source.value=committedValue;
        close();
        sync();
        source.dispatchEvent(new Event('change',{bubbles:true}));
      }
      if(event.key==='Tab')close();
    });
    wrapper.addEventListener('fiscal-code-close',close);
    source.addEventListener('change',()=>{
      // Não reescrever o texto enquanto o usuário digita uma pesquisa.
      if(!wrapper.classList.contains('open'))sync();
    });
    if(type==='ibs_cbs_class'){
      const cst=form?.querySelector(
        '[name="tax_config[ibs_cbs_cst]"],[name="tax_defaults[ibs_cst]"],[name="ibs_cst_default"]'
      );
      cst?.addEventListener('change',()=>{
        validate();
        if(wrapper.classList.contains('open'))render(search.value);
      });
    }
    form?.addEventListener('submit',event=>{
      validate();
      if(!search.checkValidity()){
        event.preventDefault();
        event.stopImmediatePropagation();
        close();
        search.reportValidity();
        search.focus();
      }
    });
    sync();
  };

  let upgradePending=false;
  const upgrade=async()=>{
    const inputs=codeInputs(document);
    if(!inputs.length)return;
    const data=await loadCatalogs();
    if(!data)return;
    inputs.forEach(input=>setup(input,data));
  };
  const queueUpgrade=()=>{
    if(upgradePending)return;
    upgradePending=true;
    queueMicrotask(()=>{
      upgradePending=false;
      if(codeInputs(document).length)upgrade();
    });
  };
  new MutationObserver(queueUpgrade).observe(document.body,{childList:true,subtree:true});
  document.addEventListener('pointerdown',event=>{
    document.querySelectorAll('.fiscal-code-field.open').forEach(field=>{
      if(!field.contains(event.target))field.dispatchEvent(new Event('fiscal-code-close'));
    });
  });
  upgrade();
})();