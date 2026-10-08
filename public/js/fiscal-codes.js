/* Catálogos fiscais compartilhados em todos os formulários do NEXTOR.
   Mantém os nomes/valores das entradas para não alterar a persistência. */
(() => {
  'use strict';
  const endpoint=document.body.dataset.fiscalCatalogUrl;
  if(!endpoint) return;

  const fieldType=name=>{
    const match=String(name||'').match(/\[([^\]]+)\]$/);
    const raw=(match ? match[1] : String(name||'')).replace(/_default$/,'');
    if(raw==='origin' || raw==='icms_origin') return 'origin';
    if(raw==='crt') return 'crt';
    if(['ibs_cbs_class','tax_classification_code','cclasstrib'].includes(raw)) return 'ibs_cbs_class';
    if(raw.includes('csosn')) return 'csosn';
    if(/^icms_cst(?:_|$)/.test(raw)) return 'icms_cst';
    if(/^(?:pis_cst|cofins_cst)(?:_|$)/.test(raw)) return 'pis_cofins_cst';
    if(/^ipi_cst(?:_|$)/.test(raw)) return 'ipi_cst';
    if(/^(?:ibs_cbs_cst|ibs_cst|cbs_cst)(?:_|$)/.test(raw)) return 'ibs_cbs_cst';
    if(raw==='mod_bc') return 'icms_mod_bc';
    if(raw==='mod_bc_st') return 'icms_mod_bc_st';
    if(raw==='iss_exigibility') return 'iss_exigibility';
    if(raw==='cfop_pattern') return 'cfop_pattern';
    if(raw==='cfop' || raw==='default_cfop' || raw.startsWith('cfop_') || raw==='nfce_cfop') return 'cfop';
    return null;
  };

  const codeInputs=root=>[...root.querySelectorAll('input[name]')].filter(input=>
    input.type!=='hidden' && input.type!=='checkbox' && input.type!=='radio'
    && input.type!=='number' && input.type!=='date' && !input.dataset.fiscalCatalogReady
    && !input.hasAttribute('data-no-fiscal-catalog') && fieldType(input.name)
  );

  if(!codeInputs(document).length) return;
  let catalogs=null;
  let request=null;

  const loadCatalogs=async()=>{
    if(catalogs) return catalogs;
    if(!request){
      request=fetch(endpoint,{credentials:'same-origin',headers:{Accept:'application/json'}})
        .then(response=>{
          if(!response.ok)throw new Error('Falha ao carregar catálogo fiscal');
          return response.json();
        })
        .then(data=>{
          catalogs=data.catalogs||{};
          return catalogs;
        })
        .catch(()=>{
          // Se não houver catálogo disponível, manter os inputs originais;
          // nunca bloquear o cadastro inteiro por falha de rede.
          request=null;
          return null;
        });
    }
    return request;
  };

  const createOptions=(select,entries)=>{
    const opt=(value,label)=>{
      const el=document.createElement('option');
      el.value=value;
      el.textContent=label;
      select.add(el);
      return el;
    };
    opt('','Selecione pelo código ou descrição');
    Object.entries(entries).forEach(([code,label])=>opt(code,code+' — '+label));
    return opt;
  };

  const upgrade=(input,data)=>{
    const catalog=fieldType(input.name);
    if(!catalog || !data[catalog]) return;
    if(input.dataset.fiscalCatalogReady)return;
    input.dataset.fiscalCatalogReady='1';
    const name=input.name;
    const form=input.closest('form');
    const oldValue=input.value;
    const select=document.createElement('select');
    select.name=name;
    select.id=input.id||'';
    select.className=input.className;
    select.dataset.searchable='1';
    select.dataset.fiscalCatalog=catalog;
    select.required=input.required;
    select.disabled=input.disabled;
    if(input.getAttribute('aria-label'))select.setAttribute('aria-label',input.getAttribute('aria-label'));
    const cfopPrefix=(()=>{
      if(/outbound_internal/.test(name))return '5';
      if(/outbound_interstate|state_variations/.test(name))return '6';
      if(/inbound_internal/.test(name))return '1';
      if(/inbound_interstate/.test(name))return '2';
      if(name==='cfop' && /\/fiscal\/rules(?:\/|$)/.test(form?.action||''))return '5';
      return '';
    })();
    const entries=catalog==='cfop_pattern'
      ? {...(data.cfop||{}),...(data.cfop_pattern||{})}
      : catalog==='cfop' && cfopPrefix
        ? Object.fromEntries(Object.entries(data.cfop||{}).filter(([code])=>code.startsWith(cfopPrefix)))
        : data[catalog];
    const add=createOptions(select,entries);

    if(oldValue && !Object.prototype.hasOwnProperty.call(entries,oldValue)){
      add(oldValue,oldValue+' — Código já cadastrado (conferir)');
    }

    const allowCustom=catalog==='cfop' || catalog==='cfop_pattern' || catalog==='ibs_cbs_class';
    let other=null;
    if(allowCustom){
      add('__manual__',catalog==='ibs_cbs_class' ? 'Outra classificação — informar código (consultar tabela oficial)' : 'Outro CFOP — digitar código');
      other=document.createElement('input');
      other.type='text';
      other.inputMode='numeric';
      other.maxLength=catalog==='ibs_cbs_class'?6:4;
      other.pattern=catalog==='ibs_cbs_class'?'[0-9]{6}':(catalog==='cfop_pattern'?'(?:[1-7][0-9]{3}|x[0-9]{3})':'[1-7][0-9]{3}');
      other.placeholder=catalog==='ibs_cbs_class'?'Informe os 6 dígitos':'Informe o CFOP';
      other.className='fiscal-cfop-manual';
      other.hidden=true;
      other.dataset.fiscalCatalogReady='1';
      other.setAttribute('aria-label',catalog==='ibs_cbs_class'?'Outra classificação tributária':'Outro CFOP');
      other.addEventListener('input',()=>{
        // Apenas dígitos ou o prefixo x, conforme o código.
        other.value=other.value.replace(catalog==='cfop_pattern'?/[^0-9x]/gi:/\D/g,'').slice(0,other.maxLength);
      });
    }

    select.value=oldValue || '';
    if(catalog==='ibs_cbs_class'){
      const prefix=form?.querySelector(
        '[name="tax_config[ibs_cbs_cst]"],[name="tax_defaults[ibs_cst]"],[name="ibs_cst_default"]'
      );
      const filterByCst=()=>{
        const active=String(prefix?.value||'').trim();
        [...select.options].forEach(option=>{
          if(/^\d{6}$/.test(option.value)){
            option.disabled=!!active && /^\d{3}$/.test(active) && !option.value.startsWith(active)
              && option.value!==select.value;
          }
        });
      };
      prefix?.addEventListener('change',filterByCst);
      filterByCst();
    }
    const syncManual=()=>{
      if(!other)return;
      const manual=select.value==='__manual__';
      other.hidden=!manual;
      if(manual){
        select.removeAttribute('name');
        select.required=false;
        other.name=name;
        other.required=input.required;
        other.disabled=input.disabled;
        queueMicrotask(()=>other.focus({preventScroll:true}));
      }else{
        other.removeAttribute('name');
        other.required=false;
        other.value='';
        select.name=name;
        select.required=input.required;
      }
    };
    select.addEventListener('change',syncManual);

    input.replaceWith(select);
    if(other)select.insertAdjacentElement('afterend',other);
    window.NextorEnhanceSelect?.(select);
  };

  const upgradeInputs=async()=>{
    const inputs=codeInputs(document);
    if(!inputs.length)return;
    const data=await loadCatalogs();
    if(!data)return;
    inputs.forEach(input=>upgrade(input,data));
  };

  // Os formulários fiscais também existem em modais e editores dinâmicos.
  let scheduled=false;
  new MutationObserver(()=>{
    if(scheduled)return;
    scheduled=true;
    queueMicrotask(()=>{
      scheduled=false;
      if(codeInputs(document).length)upgradeInputs();
    });
  }).observe(document.body,{childList:true,subtree:true});
  upgradeInputs();
})();
