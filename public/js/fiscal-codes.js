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
    const entries=catalog==='cfop_pattern'
      ? {...(data.cfop||{}),...(data.cfop_pattern||{})}
      : data[catalog];
    const add=createOptions(select,entries);

    if(oldValue && !Object.prototype.hasOwnProperty.call(entries,oldValue)){
      add(oldValue,oldValue+' — Código já cadastrado (conferir)');
    }

    const allowCustom=catalog==='cfop' || catalog==='cfop_pattern';
    let other=null;
    if(allowCustom){
      add('__manual__','Outro CFOP — digitar código');
      other=document.createElement('input');
      other.type='text';
      other.inputMode='numeric';
      other.maxLength=4;
      other.pattern=catalog==='cfop_pattern'?'(?:[1-7][0-9]{3}|x[0-9]{3})':'[1-7][0-9]{3}';
      other.placeholder='Informe o CFOP';
      other.className='fiscal-cfop-manual';
      other.hidden=true;
      other.dataset.fiscalCatalogReady='1';
      other.setAttribute('aria-label','Outro CFOP');
      other.addEventListener('input',()=>{
        // Bloquear caracteres não permitidos para manter só 4 posições.
        other.value=other.value.replace(/[^0-9x]/gi,'').slice(0,4);
      });
    }

    select.value=oldValue || '';
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
        other.focus();
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
