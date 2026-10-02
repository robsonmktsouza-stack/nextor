(() => {
  'use strict';

  // Tooltip próprio do Nextor
  const nextorTooltip=document.createElement('div');
  nextorTooltip.className='nextor-tooltip';
  nextorTooltip.hidden=true;
  nextorTooltip.setAttribute('role','tooltip');
  document.body.appendChild(nextorTooltip);
  let tooltipTarget=null;
  const hideNextorTooltip=()=>{
    tooltipTarget=null;
    nextorTooltip.hidden=true;
    nextorTooltip.classList.remove('below');
  };
  const showNextorTooltip=target=>{
    const text=(target?.getAttribute('data-tooltip')||'').trim();
    if(!text) return;
    tooltipTarget=target;
    nextorTooltip.textContent=text;
    nextorTooltip.hidden=false;
    nextorTooltip.classList.remove('below');
    nextorTooltip.style.left='0px';
    nextorTooltip.style.top='0px';
    requestAnimationFrame(()=>{
      if(tooltipTarget!==target) return;
      const rect=target.getBoundingClientRect();
      const tip=nextorTooltip.getBoundingClientRect();
      const gap=8;
      let top=rect.top-tip.height-gap;
      let below=false;
      if(top<8){
        top=rect.bottom+gap;
        below=true;
      }
      let left=rect.left+(rect.width-tip.width)/2;
      left=Math.max(8,Math.min(left,window.innerWidth-tip.width-8));
      top=Math.max(8,Math.min(top,window.innerHeight-tip.height-8));
      nextorTooltip.style.left=Math.round(left)+'px';
      nextorTooltip.style.top=Math.round(top)+'px';
      nextorTooltip.classList.toggle('below',below);
    });
  };
  document.addEventListener('pointerover',e=>{
    const target=e.target.closest?.('[data-tooltip]');
    if(target && target!==tooltipTarget) showNextorTooltip(target);
  });
  document.addEventListener('pointerout',e=>{
    if(!tooltipTarget) return;
    const from=e.target.closest?.('[data-tooltip]');
    const to=e.relatedTarget?.closest?.('[data-tooltip]');
    if(from===tooltipTarget && to!==tooltipTarget) hideNextorTooltip();
  });
  document.addEventListener('focusin',e=>{
    const target=e.target.closest?.('[data-tooltip]');
    if(target) showNextorTooltip(target);
  });
  document.addEventListener('focusout',e=>{
    if(e.target.closest?.('[data-tooltip]')===tooltipTarget) hideNextorTooltip();
  });
  document.addEventListener('scroll',hideNextorTooltip,true);
  window.addEventListener('resize',hideNextorTooltip);

  // Notificações próprias do Nextor (substitui alerts nativos do navegador)
  let notifyTimer=null;
  const nextorNotify=(message,{type='info',title='',duration=4200,actionLabel='',onAction=null}={})=>{
    let box=document.getElementById('nextorNotification');
    if(!box){
      box=document.createElement('div');
      box.id='nextorNotification';
      box.className='nextor-notification';
      box.hidden=true;
      box.innerHTML='<div class="nextor-notification-accent"></div><div class="nextor-notification-content"><strong class="nextor-notification-title"></strong><div class="nextor-notification-message"></div><div class="nextor-notification-actions"></div></div><button type="button" class="nextor-notification-close" aria-label="Fechar notificação">×</button>';
      document.body.appendChild(box);
      box.querySelector('.nextor-notification-close').addEventListener('click',()=>{box.hidden=true;});
    }
    if(notifyTimer) clearTimeout(notifyTimer);
    box.className='nextor-notification type-'+type;
    const defaultTitles={success:'Concluído',error:'Não foi possível concluir',warning:'Atenção',info:'Informação'};
    box.querySelector('.nextor-notification-title').textContent=title || defaultTitles[type] || 'Aviso';
    box.querySelector('.nextor-notification-message').textContent=message;
    const actions=box.querySelector('.nextor-notification-actions');
    actions.innerHTML='';
    if(actionLabel){
      const button=document.createElement('button');
      button.type='button';
      button.className='nextor-notification-action';
      button.textContent=actionLabel;
      button.addEventListener('click',()=>{box.hidden=true;onAction?.();},{once:true});
      actions.appendChild(button);
    }
    box.hidden=false;
    requestAnimationFrame(()=>box.classList.add('show'));
    if(duration>0) notifyTimer=setTimeout(()=>{box.classList.remove('show');setTimeout(()=>{box.hidden=true;},160);},duration);
  };
  window.NextorNotify=nextorNotify;
  window.alert=(message)=>nextorNotify(String(message),{type:'info',title:'Aviso'});

  // Confirmações próprias do Nextor
  let confirmResolver=null;
  const nextorConfirm=(message,{title='Confirmar ação',confirmLabel='Confirmar',cancelLabel='Cancelar',type='danger'}={})=>{
    if(confirmResolver){
      confirmResolver(false);
      confirmResolver=null;
    }

    let modal=document.getElementById('nextorConfirm');
    if(!modal){
      modal=document.createElement('div');
      modal.id='nextorConfirm';
      modal.className='nextor-confirm';
      modal.hidden=true;
      modal.innerHTML=
        '<div class="nextor-confirm-backdrop" data-confirm-cancel></div>'+
        '<div class="nextor-confirm-dialog" role="dialog" aria-modal="true" aria-labelledby="nextorConfirmTitle">'+
          '<div class="nextor-confirm-head">'+
            '<strong id="nextorConfirmTitle" class="nextor-confirm-title"></strong>'+
            '<button type="button" class="nextor-confirm-x" data-confirm-cancel aria-label="Fechar">×</button>'+
          '</div>'+
          '<div class="nextor-confirm-body"><p class="nextor-confirm-message"></p></div>'+
          '<div class="nextor-confirm-footer">'+
            '<button type="button" class="btn btn-secondary nextor-confirm-cancel" data-confirm-cancel></button>'+
            '<button type="button" class="btn nextor-confirm-ok" data-confirm-ok></button>'+
          '</div>'+
        '</div>';
      document.body.appendChild(modal);
    }

    modal.className='nextor-confirm type-'+type;
    modal.querySelector('.nextor-confirm-title').textContent=title;
    modal.querySelector('.nextor-confirm-message').textContent=String(message||'');
    modal.querySelector('.nextor-confirm-cancel').textContent=cancelLabel;
    modal.querySelector('.nextor-confirm-ok').textContent=confirmLabel;
    modal.hidden=false;
    document.body.classList.add('has-confirm-open');

    return new Promise(resolve=>{
      confirmResolver=resolve;
      const finish=result=>{
        if(!confirmResolver) return;
        const resolver=confirmResolver;
        confirmResolver=null;
        modal.classList.remove('show');
        document.body.classList.remove('has-confirm-open');
        setTimeout(()=>{modal.hidden=true;resolver(result);},120);
      };

      const onKey=e=>{
        if(modal.hidden) return;
        if(e.key==='Escape'){e.preventDefault();cleanup();finish(false);}
        if(e.key==='Enter' && !e.target.matches('textarea,input,select')){e.preventDefault();cleanup();finish(true);}
      };
      const cleanup=()=>document.removeEventListener('keydown',onKey);
      const wrapped=result=>{cleanup();finish(result);};

      modal.querySelectorAll('[data-confirm-cancel]').forEach(button=>button.onclick=()=>wrapped(false));
      modal.querySelector('[data-confirm-ok]').onclick=()=>wrapped(true);
      document.addEventListener('keydown',onKey);

      requestAnimationFrame(()=>{
        modal.classList.add('show');
        modal.querySelector('[data-confirm-ok]')?.focus();
      });
    });
  };
  window.NextorConfirm=nextorConfirm;

  document.querySelectorAll('[data-system-notification]').forEach(node=>{
    const items=[...node.querySelectorAll('[data-notification-item]')].map(item=>item.textContent.trim()).filter(Boolean);
    const message=items.length ? items.join(' • ') : node.textContent.trim();
    if(!message) return;
    nextorNotify(message,{
      type:node.dataset.type || 'info',
      title:node.dataset.notificationTitle || '',
      duration:(node.dataset.type==='error' ? 6500 : 4200)
    });
  });

  // Loading global do Nextor: bloqueia a interface enquanto qualquer operação está em andamento.
  let loadingCount=0;
  let loadingTimer=null;
  let navigationLoadingTimer=null;
  const loadingOverlay=document.createElement('div');
  loadingOverlay.id='nextorLoading';
  loadingOverlay.className='nextor-loading';
  loadingOverlay.hidden=true;
  loadingOverlay.setAttribute('role','status');
  loadingOverlay.setAttribute('aria-live','polite');
  loadingOverlay.setAttribute('aria-label','Carregando');
  loadingOverlay.innerHTML='<div class="nextor-loading-center"><span class="nextor-spinner" aria-hidden="true"></span><span class="nextor-loading-text">Carregando...</span></div>';
  document.body.appendChild(loadingOverlay);

  const setLoadingState=active=>{
    const appRoot=document.getElementById('appRoot');
    document.body.classList.toggle('is-loading',active);
    document.body.setAttribute('aria-busy',active?'true':'false');
    if(appRoot) appRoot.inert=active;
  };

  const beginLoading=(message='Carregando...')=>{
    loadingCount++;
    const text=loadingOverlay.querySelector('.nextor-loading-text');
    if(text) text.textContent=message;

    // Requisições rápidas não precisam piscar o overlay. A interface só é
    // bloqueada quando o carregamento realmente ultrapassa este pequeno limiar.
    if(!loadingTimer && loadingOverlay.hidden){
      loadingTimer=setTimeout(()=>{
        loadingTimer=null;
        if(loadingCount>0){
          setLoadingState(true);
          loadingOverlay.hidden=false;
          requestAnimationFrame(()=>loadingOverlay.classList.add('show'));
        }
      },140);
    }
  };

  const endLoading=()=>{
    loadingCount=Math.max(0,loadingCount-1);
    if(loadingCount>0) return;
    if(loadingTimer){clearTimeout(loadingTimer);loadingTimer=null;}

    loadingOverlay.classList.remove('show');
    setLoadingState(false);

    // Esconde praticamente junto com o fim da resposta, sem segurar a tela.
    setTimeout(()=>{
      if(loadingCount===0) loadingOverlay.hidden=true;
    },40);
  };

  const resetLoading=()=>{
    loadingCount=0;
    if(loadingTimer){clearTimeout(loadingTimer);loadingTimer=null;}
    if(navigationLoadingTimer){clearTimeout(navigationLoadingTimer);navigationLoadingTimer=null;}
    loadingOverlay.classList.remove('show','navigation-only');
    loadingOverlay.hidden=true;
    setLoadingState(false);
  };

  const beginNavigationLoading=()=>{
    if(navigationLoadingTimer) clearTimeout(navigationLoadingTimer);
    navigationLoadingTimer=setTimeout(()=>{
      navigationLoadingTimer=null;
      if(loadingCount>0) return;
      const text=loadingOverlay.querySelector('.nextor-loading-text');
      if(text) text.textContent='Carregando...';
      loadingOverlay.classList.add('navigation-only');
      loadingOverlay.hidden=false;
      requestAnimationFrame(()=>loadingOverlay.classList.add('show'));
    },90);
  };

  window.NextorLoading={show:beginLoading,hide:endLoading,reset:resetLoading};

  // Toda chamada fetch passa automaticamente pelo loading global.
  const nativeFetch=window.fetch.bind(window);
  window.NextorFetch=nativeFetch;
  window.fetch=async(...args)=>{
    beginLoading('Carregando...');
    try{return await nativeFetch(...args);}
    finally{endLoading();}
  };

  // Requisições XMLHttpRequest futuras também seguem o mesmo padrão.
  const nativeXhrSend=XMLHttpRequest.prototype.send;
  XMLHttpRequest.prototype.send=function(...args){
    beginLoading('Carregando...');
    this.addEventListener('loadend',endLoading,{once:true});
    try{return nativeXhrSend.apply(this,args);}
    catch(error){endLoading();throw error;}
  };

  // Submissões comuns de formulários.
  document.addEventListener('submit',event=>{
    const form=event.target;
    if(!(form instanceof HTMLFormElement) || form.hasAttribute('data-no-loading')) return;
    beginLoading('Salvando...');
    setTimeout(()=>{if(event.defaultPrevented) endLoading();},0);
  });

  // form.submit() não dispara o evento submit; cobre ações em massa e submits programáticos.
  const nativeFormSubmit=HTMLFormElement.prototype.submit;
  HTMLFormElement.prototype.submit=function(){
    if(!this.hasAttribute('data-no-loading')) beginLoading('Processando...');
    return nativeFormSubmit.call(this);
  };

  // Navegação GET comum mantém apenas a bolinha visual, sem bloquear a interface.
  document.addEventListener('click',event=>{
    const link=event.target.closest?.('a[href]');
    if(!link || link.hasAttribute('data-no-loading') || link.hasAttribute('download')) return;
    if(link.target && link.target!=='_self') return;
    if(event.ctrlKey||event.metaKey||event.shiftKey||event.altKey||event.button!==0) return;
    const raw=link.getAttribute('href')||'';
    if(!raw || raw.startsWith('#') || raw.startsWith('javascript:') || raw.startsWith('mailto:') || raw.startsWith('tel:')) return;
    let url;
    try{url=new URL(link.href,window.location.href);}catch(_){return;}
    if(url.origin!==window.location.origin) return;
    beginNavigationLoading();
  });

  // Ao voltar pelo histórico/bfcache, nunca mantém a tela bloqueada.
  window.addEventListener('pageshow',event=>{if(event.persisted) resetLoading();});

  const uiSelectIconSvg={
    product:'<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m3 7 9-4 9 4v10l-9 4-9-4V7ZM3 7l9 4 9-4M12 11v10" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    service:'<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6V4h6v2M4 7h16a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V9a2 2 0 0 1 2-2ZM2 12h20M9 12v2h6v-2" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    freight:'<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h11v11H3V6Zm11 4h4l3 3v4h-7v-7ZM7 20a2 2 0 1 0 0-4 2 2 0 0 0 0 4Zm11 0a2 2 0 1 0 0-4 2 2 0 0 0 0 4Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    expense:'<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="2" y="5" width="20" height="14" rx="2" fill="none" stroke="currentColor" stroke-width="1.8"/><circle cx="12" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M6 12h.01M18 12h.01" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>'
  };
  const setUiSelectOptionContent=(target,option)=>{
    target.textContent='';
    const iconName=option?.dataset?.icon||'';
    const icon=uiSelectIconSvg[iconName];
    if(icon){
      target.classList.add('has-icon');
      const iconWrap=document.createElement('span');
      iconWrap.className='ui-select-option-icon';
      iconWrap.innerHTML=icon;
      const label=document.createElement('span');
      label.textContent=option?.textContent||'';
      target.append(iconWrap,label);
    }else{
      target.classList.remove('has-icon');
      target.textContent=option?.textContent||'';
    }
  };

  let uiSelectSeq=0;
  const closeUiSelects=(except=null)=>{
    document.querySelectorAll('.ui-select.open').forEach(wrapper=>{
      if(wrapper===except) return;
      wrapper.classList.remove('open');
      const trigger=wrapper.querySelector('.ui-select-trigger');
      const menu=wrapper.querySelector('.ui-select-menu');
      if(trigger) trigger.setAttribute('aria-expanded','false');
      if(menu) menu.hidden=true;
    });
  };
  const enhanceSelect=select=>{
    if(!select || select.dataset.uiSelectReady==='1' || select.multiple) return;
    select.dataset.uiSelectReady='1';
    select.classList.add('ui-select-native');

    const wrapper=document.createElement('div');
    wrapper.className='ui-select';
    const trigger=document.createElement('button');
    trigger.type='button';
    trigger.className='ui-select-trigger';
    trigger.setAttribute('aria-haspopup','listbox');
    trigger.setAttribute('aria-expanded','false');

    const value=document.createElement('span');
    value.className='ui-select-value';
    trigger.appendChild(value);

    const menu=document.createElement('div');
    menu.className='ui-select-menu';
    menu.hidden=true;
    menu.setAttribute('role','listbox');
    menu.id='ui-select-menu-'+(++uiSelectSeq);
    trigger.setAttribute('aria-controls',menu.id);

    select.insertAdjacentElement('afterend',wrapper);
    wrapper.appendChild(trigger);
    wrapper.appendChild(menu);

    let optionsBuilt=false;
    const buildOptions=()=>{
      menu.innerHTML='';
      [...select.options].forEach(option=>{
        const item=document.createElement('button');
        item.type='button';
        item.className='ui-select-option';
        item.setAttribute('role','option');
        item.dataset.value=option.value;
        setUiSelectOptionContent(item,option);
        item.disabled=option.disabled;
        if(option.selected){
          item.classList.add('selected');
          item.setAttribute('aria-selected','true');
        } else item.setAttribute('aria-selected','false');
        item.addEventListener('click',()=>{
          if(item.disabled) return;
          select.value=option.value;
          select.dispatchEvent(new Event('change',{bubbles:true}));
          sync();
          closeUiSelects();
          trigger.focus();
        });
        menu.appendChild(item);
      });
      optionsBuilt=true;
    };
    const ensureOptions=()=>{if(!optionsBuilt) buildOptions();};
    const sync=()=>{
      const option=select.options[select.selectedIndex];
      if(option) setUiSelectOptionContent(value,option);
      else value.textContent=select.getAttribute('placeholder') || 'Selecione';
      trigger.disabled=select.disabled;
      wrapper.classList.toggle('disabled',select.disabled);
      [...menu.querySelectorAll('.ui-select-option')].forEach(item=>{
        const active=item.dataset.value===select.value;
        item.classList.toggle('selected',active);
        item.setAttribute('aria-selected',active?'true':'false');
      });
    };
    const open=()=>{
      if(select.disabled) return;
      const willOpen=!wrapper.classList.contains('open');
      closeUiSelects(wrapper);
      wrapper.classList.toggle('open',willOpen);
      trigger.setAttribute('aria-expanded',willOpen?'true':'false');
      menu.hidden=!willOpen;
      if(willOpen){
        ensureOptions();
        sync();
        wrapper.classList.remove('drop-up');
        requestAnimationFrame(()=>{
          const rect=menu.getBoundingClientRect();
          if(rect.bottom>window.innerHeight-12 && trigger.getBoundingClientRect().top>rect.height+12) wrapper.classList.add('drop-up');
        });
        const selected=menu.querySelector('.ui-select-option.selected:not(:disabled)');
        selected?.scrollIntoView({block:'nearest'});
      }
    };

    trigger.addEventListener('click',open);
    trigger.addEventListener('keydown',e=>{
      if(['Enter',' ','ArrowDown','ArrowUp'].includes(e.key)) ensureOptions();
      const items=[...menu.querySelectorAll('.ui-select-option:not(:disabled)')];
      if(!items.length) return;
      if(e.key==='Escape'){e.preventDefault();closeUiSelects();return;}
      if(e.key==='Enter'||e.key===' '){
        e.preventDefault();
        const focused=menu.querySelector('.ui-select-option.focused');
        if(wrapper.classList.contains('open') && focused){focused.click();return;}
        open();return;
      }
      if(e.key==='ArrowDown'||e.key==='ArrowUp'){
        e.preventDefault();
        if(!wrapper.classList.contains('open')) open();
        const current=menu.querySelector('.ui-select-option.focused') || menu.querySelector('.ui-select-option.selected');
        let index=Math.max(0,items.indexOf(current));
        index=e.key==='ArrowDown'?Math.min(items.length-1,index+1):Math.max(0,index-1);
        menu.querySelectorAll('.ui-select-option').forEach(x=>x.classList.remove('focused'));
        items[index].classList.add('focused');
        items[index].scrollIntoView({block:'nearest'});
      }
    });
    select.addEventListener('change',sync);
    select.addEventListener('invalid',()=>{
      ensureOptions();
      sync();
      trigger.focus();
      wrapper.classList.add('open');
      menu.hidden=false;
      trigger.setAttribute('aria-expanded','true');
    });
    new MutationObserver(mutations=>{
      if(optionsBuilt && mutations.some(m=>m.type==='childList'||m.target.tagName==='OPTION')) buildOptions();
      sync();
    }).observe(select,{attributes:true,childList:true,subtree:true,attributeFilter:['disabled','selected','label']});

    // Renderiza apenas o valor selecionado no carregamento.
    // A lista completa de opções só é construída quando o usuário abre o campo.
    sync();
  };

  document.addEventListener('click',e=>{
    if(!e.target.closest('.ui-select')) closeUiSelects();
  });
  document.querySelectorAll('select').forEach(enhanceSelect);

  // Campos numéricos e monetários padronizados do Nextor
  const numberIconSvg='<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 6h10M10 12h10M10 18h10M4 6h1M4 12h1M4 18h1" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>';
  const moneyFormatter=new Intl.NumberFormat('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2});

  const numberKind=input=>{
    const explicit=(input.dataset.numberKind||'').trim();
    if(explicit) return explicit;
    const key=((input.name||input.dataset.moneyName||'')+' '+(input.id||'')+' '+(input.getAttribute('aria-label')||'')).toLowerCase();
    if(/percent|percentage|margin|margem|rate|tax_rate|aliquota|alíquota/.test(key)) return 'percent';
    if(/price|cost|amount|total|value|valor|preco|preço/.test(key)) return 'money';
    return 'number';
  };

  const initialMoneyValue=value=>{
    let text=String(value??'').trim();
    if(!text) return 0;

    if(text.includes(',')){
      text=text.replace(/\./g,'').replace(',','.');
    }
    const parsed=Number(text);
    return Number.isFinite(parsed) ? Math.max(0,parsed) : 0;
  };

  const parseMoneyText=value=>{
    let text=String(value??'').trim().replace(/[^0-9,.-]/g,'').replace(/-/g,'');
    if(!text) return 0;

    if(text.includes(',')){
      const comma=text.lastIndexOf(',');
      const whole=text.slice(0,comma).replace(/[.,]/g,'')||'0';
      const decimals=text.slice(comma+1).replace(/\D/g,'').slice(0,2);
      const parsed=Number(whole+'.'+(decimals||'0'));
      return Number.isFinite(parsed) ? Math.max(0,parsed) : 0;
    }

    const dots=(text.match(/\./g)||[]).length;
    if(dots===1){
      const [whole,decimals='']=text.split('.');
      if(decimals.length>0 && decimals.length<=2){
        const parsed=Number((whole||'0')+'.'+decimals);
        return Number.isFinite(parsed) ? Math.max(0,parsed) : 0;
      }
    }

    const parsed=Number(text.replace(/\./g,''));
    return Number.isFinite(parsed) ? Math.max(0,parsed) : 0;
  };

  const enhanceMoneyInput=input=>{
    if(!input || input.dataset.moneyReady==='1') return;
    const originalName=input.name;
    if(!originalName) return;

    input.dataset.moneyReady='1';
    input.dataset.moneyName=originalName;

    const hidden=document.createElement('input');
    hidden.type='hidden';
    hidden.name=originalName;
    hidden.dataset.moneyHidden='1';

    const initial=initialMoneyValue(input.value);
    hidden.value=initial.toFixed(2);

    input.removeAttribute('name');
    input.type='text';
    input.inputMode='decimal';
    input.autocomplete='off';
    input.value=moneyFormatter.format(initial);
    input.dataset.moneyValue=hidden.value;

    input.insertAdjacentElement('afterend',hidden);

    const sync=()=>{
      const value=parseMoneyText(input.value);
      hidden.value=value.toFixed(2);
      input.dataset.moneyValue=hidden.value;
      return value;
    };

    const format=()=>{
      const value=sync();
      input.value=moneyFormatter.format(value);
    };

    input.addEventListener('input',sync);
    input.addEventListener('blur',format);
    input.addEventListener('focus',()=>{
      requestAnimationFrame(()=>input.select());
    });
    input.addEventListener('click',()=>{
      if(document.activeElement===input && input.selectionStart===input.selectionEnd){
        input.select();
      }
    });
    input.addEventListener('keydown',event=>{
      if(event.key==='ArrowUp'||event.key==='ArrowDown') event.preventDefault();
      if(event.key==='Enter') format();
    });
  };

  const enhanceNumberInput=input=>{
    if(!input || input.dataset.numberReady==='1') return;
    if(input.type!=='number' && input.dataset.numberKind!=='money') return;

    const kind=numberKind(input);
    input.dataset.numberReady='1';

    if(!input.closest('.input-prefix,.numeric-input')){
      const wrapper=document.createElement('div');
      wrapper.className='numeric-input numeric-'+kind;
      const prefix=document.createElement('span');
      prefix.className='numeric-input-prefix';
      prefix.setAttribute('aria-hidden','true');
      prefix.innerHTML=kind==='money'?'R$':kind==='percent'?'%':numberIconSvg;

      input.insertAdjacentElement('beforebegin',wrapper);
      wrapper.appendChild(prefix);
      wrapper.appendChild(input);
    }

    if(kind==='money') enhanceMoneyInput(input);
  };

  document.querySelectorAll('input[type="number"],input[data-number-kind="money"]').forEach(enhanceNumberInput);

  const numberObserver=new MutationObserver(mutations=>{
    mutations.forEach(mutation=>{
      mutation.addedNodes.forEach(node=>{
        if(!(node instanceof Element)) return;
        if(node.matches?.('input[type="number"],input[data-number-kind="money"]')) enhanceNumberInput(node);
        node.querySelectorAll?.('input[type="number"],input[data-number-kind="money"]').forEach(enhanceNumberInput);
      });
    });
  });
  numberObserver.observe(document.body,{childList:true,subtree:true});

  // Máscaras de texto padronizadas do Nextor
  const onlyDigits=(value,max=Infinity)=>String(value||'').replace(/\D/g,'').slice(0,max);
  const onlyAlphaNum=(value,max=Infinity)=>String(value||'').toUpperCase().replace(/[^0-9A-Z]/g,'').slice(0,max);

  const maskCpfCnpj=value=>{
    const raw=onlyAlphaNum(value,14);
    if(!raw) return '';

    const numeric=/^\d+$/.test(raw);
    if(numeric && raw.length<=11){
      const d=raw.slice(0,11);
      if(d.length<=3) return d;
      if(d.length<=6) return d.slice(0,3)+'.'+d.slice(3);
      if(d.length<=9) return d.slice(0,3)+'.'+d.slice(3,6)+'.'+d.slice(6);
      return d.slice(0,3)+'.'+d.slice(3,6)+'.'+d.slice(6,9)+'-'+d.slice(9);
    }

    const d=raw.slice(0,14);
    let out=d.slice(0,2);
    if(d.length>2) out+='.'+d.slice(2,5);
    if(d.length>5) out+='.'+d.slice(5,8);
    if(d.length>8) out+='/'+d.slice(8,12);
    if(d.length>12) out+='-'+d.slice(12,14);
    return out;
  };

  const maskCep=value=>{
    const d=onlyDigits(value,8);
    return d.length>5 ? d.slice(0,5)+'-'+d.slice(5) : d;
  };

  const maskPhone=value=>{
    const d=onlyDigits(value,11);
    if(!d) return '';
    if(d.length<=2) return '('+d;
    const ddd='('+d.slice(0,2)+') ';
    if(d.length<=6) return ddd+d.slice(2);
    if(d.length<=10) return ddd+d.slice(2,6)+'-'+d.slice(6);
    return ddd+d.slice(2,7)+'-'+d.slice(7);
  };

  const maskCnae=value=>{
    const d=onlyDigits(value,7);
    if(d.length<=4) return d;
    if(d.length<=5) return d.slice(0,4)+'-'+d.slice(4);
    return d.slice(0,4)+'-'+d.slice(4,5)+'/'+d.slice(5);
  };

  const maskNcm=value=>{
    const d=onlyDigits(value,8);
    if(d.length<=4) return d;
    if(d.length<=6) return d.slice(0,4)+'.'+d.slice(4);
    return d.slice(0,4)+'.'+d.slice(4,6)+'.'+d.slice(6);
  };

  const maskCest=value=>{
    const d=onlyDigits(value,7);
    if(d.length<=2) return d;
    if(d.length<=5) return d.slice(0,2)+'.'+d.slice(2);
    return d.slice(0,2)+'.'+d.slice(2,5)+'.'+d.slice(5);
  };

  const maskNbs=value=>{
    const d=onlyDigits(value,9);
    if(d.length<=1) return d;
    if(d.length<=5) return d.slice(0,1)+'.'+d.slice(1);
    if(d.length<=7) return d.slice(0,1)+'.'+d.slice(1,5)+'.'+d.slice(5);
    return d.slice(0,1)+'.'+d.slice(1,5)+'.'+d.slice(5,7)+'.'+d.slice(7);
  };

  const maskServiceItem=value=>{
    const d=onlyDigits(value,4);
    return d.length>2 ? d.slice(0,2)+'.'+d.slice(2) : d;
  };

  const textMasks={
    'cpf-cnpj':maskCpfCnpj,
    'cep':maskCep,
    'phone':maskPhone,
    'cnae':maskCnae,
    'ncm':maskNcm,
    'cest':maskCest,
    'nbs':maskNbs,
    'service-item':maskServiceItem,
    'gtin':value=>onlyDigits(value,14),
    'digits8':value=>onlyDigits(value,8),
    'digits9':value=>onlyDigits(value,9),
    'digits11':value=>onlyDigits(value,11),
  };

  const inferTextMask=input=>{
    if(input.dataset.mask) return input.dataset.mask;
    const name=(input.name||'').toLowerCase();
    if(name.endsWith('[document]') || name==='document') return 'cpf-cnpj';
    if(name.endsWith('[zip_code]') || name==='zip_code') return 'cep';
    if(name.endsWith('[phone]') || name==='phone') return 'phone';
    if(name==='cnae') return 'cnae';
    if(name==='ncm') return 'ncm';
    if(name==='cest') return 'cest';
    if(name==='ean_gtin') return 'gtin';
    if(name==='nbs') return 'nbs';
    if(name==='service_list_item') return 'service-item';
    if(name==='rntrc') return 'digits8';
    if(name==='driver_license') return 'digits11';
    if(name==='suframa') return 'digits9';
    return '';
  };

  const enhanceTextMask=input=>{
    if(!(input instanceof HTMLInputElement) || input.dataset.maskReady==='1') return;
    const maskName=inferTextMask(input);
    const formatter=textMasks[maskName];
    if(!formatter) return;

    input.dataset.maskReady='1';
    input.dataset.mask=maskName;
    if(!input.inputMode && maskName!=='cpf-cnpj') input.inputMode='numeric';
    input.autocomplete=input.autocomplete||'off';

    const apply=()=>{
      const before=input.value;
      const formatted=formatter(before);
      if(before!==formatted) input.value=formatted;
    };

    input.addEventListener('input',apply);
    input.addEventListener('blur',apply);
    apply();
  };

  document.querySelectorAll('input').forEach(enhanceTextMask);

  const textMaskObserver=new MutationObserver(mutations=>{
    mutations.forEach(mutation=>{
      mutation.addedNodes.forEach(node=>{
        if(!(node instanceof Element)) return;
        if(node.matches?.('input')) enhanceTextMask(node);
        node.querySelectorAll?.('input').forEach(enhanceTextMask);
      });
    });
  });
  textMaskObserver.observe(document.body,{childList:true,subtree:true});

  window.NextorUI={enhanceSelect,enhanceNumberInput,enhanceMoneyInput,enhanceTextMask};

  // Abas de formulários
  document.querySelectorAll('[data-tabs]').forEach(tabs=>{
    const buttons=[...tabs.querySelectorAll('[data-tab-target]')];
    buttons.forEach(button=>button.addEventListener('click',()=>{
      const target=button.getAttribute('data-tab-target');
      buttons.forEach(b=>b.classList.toggle('active',b===button));
      document.querySelectorAll('[data-tab-panel]').forEach(panel=>{
        const active=panel.getAttribute('data-tab-panel')===target;
        panel.classList.toggle('active',active);
        panel.hidden=!active;
      });
    }));
  });

  // Switches
  document.querySelectorAll('.switch-field input[type="checkbox"]').forEach(input=>{
    const label=input.closest('.switch-field')?.querySelector('[data-switch-label]');
    const sync=()=>{if(label) label.textContent=input.checked?'Sim':'Não';};
    input.addEventListener('change',sync); sync();
  });
  document.querySelectorAll('.inverse-switch input[type="checkbox"]').forEach(input=>{
    const label=input.closest('.inverse-switch')?.querySelector('[data-active-label]');
    const sync=()=>{if(label) label.textContent=input.checked?'Não':'Sim';};
    input.addEventListener('change',sync); sync();
  });

  // Cadastro de produtos
  document.querySelectorAll('[data-price-area]').forEach(area=>{
    const cost=area.querySelector('[data-cost-price]');
    const sale=area.querySelector('[data-sale-price]');
    const output=area.querySelector('[data-margin-output]');
    const calc=()=>{
      const c=parseFloat(cost?.dataset.moneyValue||cost?.value||'0')||0;
      const s=parseFloat(sale?.dataset.moneyValue||sale?.value||'0')||0;
      const margin=s>0?((s-c)/s)*100:0;
      if(output) output.value=margin.toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2});
    };
    cost?.addEventListener('input',calc);
    sale?.addEventListener('input',calc);
    calc();
  });

  document.querySelectorAll('[data-stock-control]').forEach(toggle=>{
    const form=toggle.closest('form');
    const sync=()=>{
      form?.querySelectorAll('[data-stock-field]').forEach(field=>{
        field.readOnly=!toggle.checked;
        field.closest('.field')?.classList.toggle('field-disabled',!toggle.checked);
      });
    };
    toggle.addEventListener('change',sync);sync();
  });

  document.querySelectorAll('[data-tax-unit-toggle]').forEach(toggle=>{
    const form=toggle.closest('form');
    const select=form?.querySelector('[data-tax-unit-field]');
    const sync=()=>{
      if(select) select.disabled=!toggle.checked;
    };
    toggle.addEventListener('change',sync);sync();
  });

  document.querySelectorAll('[data-product-image]').forEach(input=>{
    input.addEventListener('change',()=>{
      const file=input.files?.[0];
      if(!file) return;
      const preview=input.closest('.editor-panel')?.querySelector('[data-product-photo-preview]');
      if(!preview) return;
      const reader=new FileReader();
      reader.addEventListener('load',()=>{
        preview.innerHTML='';
        const img=document.createElement('img');
        img.src=String(reader.result||'');
        img.alt='Prévia do produto';
        preview.appendChild(img);
      },{once:true});
      reader.readAsDataURL(file);
    });
  });

  // Endereços de entrega dinâmicos
  const deliveryList=document.querySelector('[data-delivery-list]');
  const deliveryTemplate=document.getElementById('deliveryAddressTemplate');
  let deliverySeq=document.querySelectorAll('[data-delivery-card]').length;
  document.querySelector('[data-add-delivery]')?.addEventListener('click',()=>{
    if(!deliveryList || !deliveryTemplate) return;
    const html=deliveryTemplate.innerHTML.replaceAll('__INDEX__',String(deliverySeq++));
    const holder=document.createElement('div');
    holder.innerHTML=html.trim();
    const card=holder.firstElementChild;
    deliveryList.appendChild(card);
    card.querySelectorAll('select').forEach(enhanceSelect);
    card.scrollIntoView({behavior:'smooth',block:'nearest'});
  });
  document.addEventListener('click',e=>{
    const remove=e.target.closest('[data-remove-delivery]');
    if(remove) remove.closest('[data-delivery-card]')?.remove();
  });

  // Cidades por UF
  async function loadCities(ufSelect,preferred=''){
    const scope=ufSelect.closest('[data-address-scope]');
    const citySelect=scope?.querySelector('[data-city-select]');
    if(!citySelect) return;
    const uf=ufSelect.value;
    citySelect.innerHTML='<option value="">'+(uf?'Carregando...':'Selecione o estado')+'</option>';
    if(!uf) return;
    try{
      const response=await fetch('https://servicodados.ibge.gov.br/api/v1/localidades/estados/'+encodeURIComponent(uf)+'/municipios?orderBy=nome');
      if(!response.ok) throw new Error('Falha ao carregar cidades');
      const cities=await response.json();
      citySelect.innerHTML='<option value="">Selecione a cidade</option>';
      cities.forEach(city=>{
        const option=document.createElement('option');
        option.value=city.nome; option.textContent=city.nome;
        if(preferred && city.nome.toLocaleLowerCase('pt-BR')===preferred.toLocaleLowerCase('pt-BR')) option.selected=true;
        citySelect.appendChild(option);
      });
      citySelect.dispatchEvent(new Event('change',{bubbles:true}));
    }catch(_){
      citySelect.innerHTML='<option value="'+preferred+'">'+(preferred||'Não foi possível carregar')+'</option>';
    }
  }
  document.querySelectorAll('[data-uf-select]').forEach(select=>{
    const preferred=select.closest('[data-address-scope]')?.querySelector('[data-city-select]')?.dataset.currentCity||'';
    if(select.value) loadCities(select,preferred);
  });
  document.addEventListener('change',e=>{
    if(e.target.matches('[data-uf-select]') && !e.detail?.skipCities) loadCities(e.target,'');
  });

  // Busca de CEP
  document.addEventListener('click',async e=>{
    const button=e.target.closest('[data-cep-search]');
    if(!button) return;
    const scope=button.closest('[data-address-scope]');
    const cepInput=scope?.querySelector('[data-cep-input]');
    const cep=(cepInput?.value||'').replace(/\D/g,'');
    if(cep.length!==8){cepInput?.focus();return;}
    if(cepInput) cepInput.dataset.lastAutoLookup=cep;
    button.disabled=true;
    try{
      const response=await fetch('https://viacep.com.br/ws/'+cep+'/json/');
      const data=await response.json();
      if(data.erro) throw new Error('CEP não encontrado');
      const address=scope.querySelector('[data-address-input]');
      const district=scope.querySelector('[data-district-input]');
      const uf=scope.querySelector('[data-uf-select]');
      const city=scope.querySelector('[data-city-select]');
      if(address) address.value=data.logradouro||'';
      if(district) district.value=data.bairro||'';
      if(uf){
        uf.value=data.uf||'';
        uf.dispatchEvent(new CustomEvent('change',{bubbles:true,detail:{skipCities:true}}));
        await loadCities(uf,data.localidade||'');
      }else if(city) city.value=data.localidade||'';
    }catch(err){
      nextorNotify('Não foi possível consultar o CEP agora. Verifique sua conexão e tente novamente.',{type:'error',title:'Consulta de CEP'});
    }finally{button.disabled=false;}
  });

  // Autopreenchimento de CNPJ
  document.querySelector('[data-cnpj-autofill]')?.addEventListener('click',async e=>{
    const button=e.currentTarget;
    const form=button.closest('form');
    const documentInput=form?.querySelector('[data-cnpj-document]');
    const cnpj=(documentInput?.value||'').toUpperCase().replace(/[^0-9A-Z]/g,'');
    if(cnpj.length!==14){
      documentInput?.focus();
      nextorNotify('Informe um CNPJ válido com 14 caracteres.',{type:'warning',title:'Consulta de CNPJ'});
      return;
    }
    if(documentInput) documentInput.dataset.lastAutoLookup=cnpj;
    const set=(name,value)=>{
      const field=form.querySelector('[name="'+name+'"]');
      if(field && value!==undefined && value!==null){
        field.value=value;
        field.dispatchEvent(new Event('input',{bubbles:true}));
      }
    };
    button.disabled=true;
    const oldText=button.querySelector('span')?.textContent;
    if(button.querySelector('span')) button.querySelector('span').textContent='Buscando...';
    try{
      const response=await fetch('/customers/cnpj/'+encodeURIComponent(cnpj),{
        headers:{'Accept':'application/json'}
      });
      const data=await response.json().catch(()=>({}));
      if(!response.ok) throw new Error(data.message||'Não foi possível consultar o CNPJ.');
      set('name',data.razao_social);
      set('trade_name',data.nome_fantasia);
      set('email',data.email);
      set('phone',data.ddd_telefone_1);
      set('zip_code',data.cep);
      set('address',data.logradouro);
      set('address_number',data.numero);
      set('address_complement',data.complemento);
      set('district',data.bairro);
      const uf=form.querySelector('[name="state"]');
      if(uf){
        uf.value=data.uf||'';
        uf.dispatchEvent(new CustomEvent('change',{bubbles:true,detail:{skipCities:true}}));
        await loadCities(uf,data.municipio||'');
      }
    }catch(err){
      nextorNotify(err.message||'Não foi possível consultar o CNPJ agora.',{type:'error',title:'Consulta de CNPJ'});
    }finally{
      button.disabled=false;
      if(button.querySelector('span')) button.querySelector('span').textContent=oldText||'Autopreencher';
    }
  });

  const lookupTimers=new WeakMap();
  document.addEventListener('input',event=>{
    const input=event.target;
    if(!(input instanceof HTMLInputElement)) return;

    if(input.matches('[data-cep-input]')){
      const value=(input.value||'').replace(/\D/g,'');
      const previous=lookupTimers.get(input);
      if(previous) clearTimeout(previous);
      if(value.length!==8 || input.dataset.lastAutoLookup===value) return;
      lookupTimers.set(input,setTimeout(()=>{
        lookupTimers.delete(input);
        const button=input.closest('[data-address-scope]')?.querySelector('[data-cep-search]');
        if(button && !button.disabled) button.click();
      },320));
      return;
    }

    if(input.matches('[data-cnpj-document]')){
      const value=(input.value||'').toUpperCase().replace(/[^0-9A-Z]/g,'');
      const previous=lookupTimers.get(input);
      if(previous) clearTimeout(previous);
      if(value.length!==14 || input.dataset.lastAutoLookup===value) return;
      lookupTimers.set(input,setTimeout(()=>{
        lookupTimers.delete(input);
        const button=input.closest('form')?.querySelector('[data-cnpj-autofill]');
        if(button && !button.disabled) button.click();
      },360));
    }
  });

  const app = document.getElementById('appRoot');
  if (app) {
    const mobile = () => window.innerWidth <= 760;
    try { if (localStorage.getItem('erp.sidebar.collapsed') === '1' && !mobile()) app.classList.add('sidebar-collapsed'); } catch (_) {}
    document.querySelector('[data-sidebar-toggle]')?.addEventListener('click', () => {
      if (mobile()) { app.classList.toggle('mobile-menu-open'); document.getElementById('mobileOverlay').hidden = !app.classList.contains('mobile-menu-open'); }
      else { app.classList.toggle('sidebar-collapsed'); try { localStorage.setItem('erp.sidebar.collapsed', app.classList.contains('sidebar-collapsed') ? '1' : '0'); } catch (_) {} }
    });
    document.getElementById('mobileOverlay')?.addEventListener('click', () => { app.classList.remove('mobile-menu-open'); document.getElementById('mobileOverlay').hidden = true; });
    const search = document.getElementById('commandSearch');
    const results = document.getElementById('commandResults');
    if (search && results) {
      const update = () => { const q = search.value.trim().toLocaleLowerCase('pt-BR'); let n=0; results.querySelectorAll('a').forEach(a=>{ const match = !q || a.textContent.toLocaleLowerCase('pt-BR').includes(q); a.hidden = !match; if (match) n++; }); results.hidden = !n; };
      search.addEventListener('focus',update); search.addEventListener('input',update);
      search.addEventListener('blur',()=>setTimeout(()=>results.hidden=true,140));
      search.addEventListener('keydown', e=>{if(e.key==='Enter'){const first=results.querySelector('a:not([hidden])');if(first){e.preventDefault();window.location.href=first.href;}}});
      document.addEventListener('keydown',e=>{ if ((e.ctrlKey||e.metaKey)&&e.key.toLowerCase()==='k'){e.preventDefault();search.focus();search.select();} if(e.key==='Escape')results.hidden=true; });
    }
  }
  document.querySelectorAll('[data-dialog-open]').forEach(button=>button.addEventListener('click',()=>{
    const id=button.getAttribute('data-dialog-open');document.getElementById(id)?.showModal();
  }));
  document.querySelectorAll('[data-dialog-close]').forEach(button=>button.addEventListener('click',()=>button.closest('dialog')?.close()));
  document.querySelectorAll('[data-filter-toggle]').forEach(button=>button.addEventListener('click',()=>{
    const panel=document.getElementById(button.getAttribute('data-filter-toggle'));
    if(panel) panel.hidden=!panel.hidden;
  }));
  document.querySelectorAll('[data-print-page]').forEach(button=>button.addEventListener('click',()=>window.print()));
  document.querySelectorAll('[data-refresh-page]').forEach(button=>button.addEventListener('click',()=>window.location.reload()));
  document.addEventListener('submit',async e=>{
    const form=e.target.closest?.('[data-confirm-submit]');
    if(!form) return;
    if(form.dataset.confirmBypass==='1'){
      delete form.dataset.confirmBypass;
      return;
    }
    const message=form.getAttribute('data-confirm-submit');
    if(!message) return;
    e.preventDefault();
    const confirmed=await nextorConfirm(message,{
      title:'Confirmar exclusão',
      confirmLabel:'Excluir',
      cancelLabel:'Cancelar',
      type:'danger'
    });
    if(!confirmed) return;
    form.dataset.confirmBypass='1';
    form.requestSubmit();
  });

  const selectedChecks=card=>[...card.querySelectorAll('[data-row-select]')].filter(x=>x.checked);
  const exportTable=(card,filename,selectedOnly=false)=>{
    const table=card?.querySelector('.cms-table');
    if(!table) return;
    const selectedRows=new Set(selectedChecks(card).map(x=>x.closest('tr')));
    const tableRows=[...table.querySelectorAll('tr')].filter((tr,index)=>index===0||!selectedOnly||selectedRows.has(tr));
    const lines=tableRows.map(tr=>
      [...tr.children].filter(cell=>!cell.classList.contains('select-cell')&&!cell.classList.contains('action-cell')).map(cell=>{
        const value=cell.innerText.replace(/\s+/g,' ').trim().replace(/"/g,'""');
        return '"'+value+'"';
      }).join(';')
    );
    const blob=new Blob(['\uFEFF'+lines.join('\r\n')],{type:'text/csv;charset=utf-8;'});
    const url=URL.createObjectURL(blob);
    const a=document.createElement('a');
    a.href=url;a.download=filename||'exportacao.csv';
    document.body.appendChild(a);a.click();a.remove();URL.revokeObjectURL(url);
  };
  const printSelected=card=>{
    const selectedRows=new Set(selectedChecks(card).map(x=>x.closest('tr')));
    card.querySelectorAll('.cms-table tbody tr').forEach(tr=>tr.classList.toggle('print-excluded',!selectedRows.has(tr)));
    window.print();
    card.querySelectorAll('.print-excluded').forEach(tr=>tr.classList.remove('print-excluded'));
  };
  const prepareBulkForm=(form,ids)=>{
    if(!form) return false;
    form.querySelectorAll('[data-generated-bulk]').forEach(el=>el.remove());
    ids.forEach(id=>{
      const input=document.createElement('input');
      input.type='hidden';input.name='ids[]';input.value=id;input.dataset.generatedBulk='1';
      form.appendChild(input);
    });
    return true;
  };
  const submitBulkForm=(formId,ids)=>{
    const form=document.getElementById(formId);
    if(!prepareBulkForm(form,ids)) return;
    form.submit();
  };

  document.querySelectorAll('.cms-card').forEach(card=>{
    const getAll=()=>card.querySelector('[data-check-all]');
    if(!getAll()) return;

    const getRows=()=>[...card.querySelectorAll('[data-row-select]')];
    const count=card.querySelector('[data-selection-count]');
    const menu=card.querySelector('[data-bulk-menu]');
    const apply=card.querySelector('[data-bulk-apply]');

    const update=()=>{
      const all=getAll();
      const rows=getRows();
      const selected=rows.filter(x=>x.checked);
      if(all){
        all.checked=rows.length>0 && selected.length===rows.length;
        all.indeterminate=selected.length>0 && selected.length<rows.length;
      }
      card.querySelectorAll('[data-bulk-submit]').forEach(b=>b.disabled=selected.length===0);
      card.querySelectorAll('[data-requires-selection]').forEach(b=>b.disabled=selected.length===0);
      card.querySelectorAll('[data-requires-single]').forEach(b=>b.disabled=selected.length!==1);
      if(menu) menu.disabled=selected.length===0;
      if(apply) apply.disabled=selected.length===0 || !menu?.value;
      if(count){
        count.hidden=selected.length===0;
        count.textContent=selected.length+' selecionado'+(selected.length===1?'':'s');
      }
    };

    card.addEventListener('change',event=>{
      if(event.target.matches?.('[data-check-all]')){
        getRows().forEach(x=>x.checked=event.target.checked);
        update();
        return;
      }
      if(event.target.matches?.('[data-row-select]') || event.target===menu) update();
    });

    card.querySelectorAll('[data-bulk-submit]').forEach(button=>button.addEventListener('click',async()=>{
      const selected=getRows().filter(x=>x.checked).map(x=>x.value);
      if(!selected.length) return;
      const msg=button.getAttribute('data-confirm');
      if(msg){
        const confirmed=await nextorConfirm(msg,{
          title:'Confirmar ação',
          confirmLabel:'Confirmar',
          cancelLabel:'Cancelar',
          type:button.classList.contains('grid-tool-danger')||button.classList.contains('danger')?'danger':'warning'
        });
        if(!confirmed) return;
      }
      submitBulkForm(button.getAttribute('data-bulk-submit'),selected);
    }));

    card.querySelectorAll('[data-bulk-open-dialog]').forEach(button=>button.addEventListener('click',()=>{
      const selected=getRows().filter(x=>x.checked).map(x=>x.value);
      if(!selected.length) return;
      const dialog=document.getElementById(button.getAttribute('data-bulk-open-dialog'));
      const form=dialog?.querySelector('form');
      if(!prepareBulkForm(form,selected)) return;
      const countLabel=dialog.querySelector('[data-bulk-dialog-count]');
      if(countLabel) countLabel.textContent=selected.length+' lançamento'+(selected.length===1?'':'s')+' selecionado'+(selected.length===1?'':'s');
      document.querySelectorAll('.finance-action-menu').forEach(actionMenu=>actionMenu.hidden=true);
      document.querySelectorAll('[data-finance-action-toggle]').forEach(toggle=>toggle.setAttribute('aria-expanded','false'));
      dialog.showModal();
    }));

    card.querySelectorAll('[data-selected-url-template]').forEach(button=>button.addEventListener('click',()=>{
      const selected=getRows().filter(x=>x.checked).map(x=>x.value);
      if(selected.length!==1) return;
      const template=button.getAttribute('data-selected-url-template')||'';
      if(!template) return;
      window.location.href=template.replace('__ID__',encodeURIComponent(selected[0]));
    }));

    apply?.addEventListener('click',async()=>{
      const selected=getRows().filter(x=>x.checked);
      if(!selected.length || !menu?.value) return;
      const option=menu.selectedOptions[0];
      const msg=option?.dataset.confirm;
      if(msg){
        const confirmed=await nextorConfirm(msg,{
          title:'Confirmar ação',
          confirmLabel:'Confirmar',
          cancelLabel:'Cancelar',
          type:'danger'
        });
        if(!confirmed) return;
      }
      if(menu.value==='local:export'){
        exportTable(card,(card.querySelector('[data-export-table]')?.getAttribute('data-export-table')||'selecionados.csv').replace('.csv','-selecionados.csv'),true);
        return;
      }
      if(menu.value==='local:print'){
        printSelected(card);
        return;
      }
      const dialogId=option?.dataset.bulkDialog;
      if(dialogId){
        const dialog=document.getElementById(dialogId);
        const form=dialog?.querySelector('form');
        if(!prepareBulkForm(form,selected.map(x=>x.value))) return;
        const countLabel=dialog.querySelector('[data-bulk-dialog-count]');
        if(countLabel) countLabel.textContent=selected.length+' lançamento'+(selected.length===1?'':'s')+' selecionado'+(selected.length===1?'':'s');
        dialog.showModal();
        return;
      }
      submitBulkForm(menu.value,selected.map(x=>x.value));
    });

    card.addEventListener('nextor:live-updated',update);
    update();
  });

  const liveSearchStates=new WeakMap();
  const buildLiveSearchUrl=form=>{
    const action=form.getAttribute('action') || window.location.pathname;
    const url=new URL(action,window.location.origin);
    const params=new URLSearchParams();
    new FormData(form).forEach((value,key)=>{
      const text=String(value??'').trim();
      if(text!=='') params.append(key,text);
    });
    params.delete('page');
    url.search=params.toString();
    return url;
  };

  const syncLiveSearchExtras=doc=>{
    const currentMeta=document.querySelector('.page-title-meta');
    const nextMeta=doc.querySelector('.page-title-meta');
    if(currentMeta && nextMeta) currentMeta.innerHTML=nextMeta.innerHTML;

    document.querySelectorAll('[data-live-sync]').forEach(current=>{
      const key=current.getAttribute('data-live-sync');
      if(!key) return;
      const next=doc.querySelector('[data-live-sync="'+CSS.escape(key)+'"]');
      if(next) current.innerHTML=next.innerHTML;
    });
  };

  const runLiveSearch=async(form,forcedUrl=null)=>{
    const targetId=form.dataset.liveTarget;
    const target=targetId ? document.getElementById(targetId) : null;
    if(!target) return;

    const state=liveSearchStates.get(form) || {};
    state.controller?.abort();
    const controller=new AbortController();
    state.controller=controller;
    liveSearchStates.set(form,state);

    const url=forcedUrl ? new URL(forcedUrl,window.location.origin) : buildLiveSearchUrl(form);
    form.classList.add('is-live-searching');
    form.setAttribute('aria-busy','true');
    target.classList.add('is-live-loading');

    try{
      const fetcher=window.NextorFetch || window.fetch.bind(window);
      const response=await fetcher(url.toString(),{
        headers:{'X-Requested-With':'XMLHttpRequest','Accept':'text/html'},
        signal:controller.signal,
        credentials:'same-origin'
      });
      if(!response.ok) throw new Error('Não foi possível atualizar a busca.');
      const html=await response.text();
      if(controller.signal.aborted) return;

      const doc=new DOMParser().parseFromString(html,'text/html');
      const nextTarget=doc.getElementById(targetId);
      if(!nextTarget) throw new Error('A área de resultados da busca não foi encontrada.');

      target.innerHTML=nextTarget.innerHTML;
      syncLiveSearchExtras(doc);
      history.replaceState({},'',url.pathname+url.search+url.hash);
      target.dispatchEvent(new CustomEvent('nextor:live-updated',{bubbles:true}));
    }catch(error){
      if(error?.name!=='AbortError'){
        nextorNotify(error?.message||'Não foi possível atualizar a busca.',{type:'error',title:'Busca'});
      }
    }finally{
      const latest=liveSearchStates.get(form);
      if(latest?.controller===controller){
        form.classList.remove('is-live-searching');
        form.removeAttribute('aria-busy');
        target.classList.remove('is-live-loading');
      }
    }
  };

  document.querySelectorAll('form[data-live-search]').forEach(form=>{
    const state={timer:null,controller:null};
    liveSearchStates.set(form,state);
    const input=form.querySelector('[data-live-search-input],input[name="search"]');
    const delay=Math.max(120,Number(form.dataset.liveDelay||240));

    const schedule=()=>{
      clearTimeout(state.timer);
      state.timer=setTimeout(()=>runLiveSearch(form),delay);
    };

    input?.addEventListener('input',schedule);
    form.addEventListener('change',event=>{
      if(event.target.matches('select,input[type="checkbox"],input[type="radio"]')) runLiveSearch(form);
    });
    form.addEventListener('submit',event=>{
      event.preventDefault();
      clearTimeout(state.timer);
      runLiveSearch(form);
    });

    const target=document.getElementById(form.dataset.liveTarget||'');
    target?.addEventListener('click',event=>{
      const link=event.target.closest('a[href]');
      if(!link || !link.closest('.table-footerbar,.card-pagination,.pagination')) return;
      const url=new URL(link.href,window.location.origin);
      if(url.origin!==window.location.origin) return;
      event.preventDefault();
      runLiveSearch(form,url);
    });
  });

  document.addEventListener('change',event=>{
    const check=event.target.closest?.('[data-return-item-toggle]');
    if(!check) return;
    const id=check.dataset.returnItemToggle;
    const qty=document.querySelector('[data-return-qty="'+CSS.escape(id)+'"]');
    const reason=document.querySelector('[data-return-reason="'+CSS.escape(id)+'"]');
    const enabled=check.checked && !check.disabled;
    if(qty){
      qty.disabled=!enabled;
      if(enabled && Number(qty.value)<=0) qty.value=Math.min(1,Number(qty.max||1));
      if(!enabled) qty.value='0';
    }
    if(reason) reason.disabled=!enabled;
    check.closest('tr')?.classList.toggle('return-item-selected',enabled);
  });

  const closeFinanceActionMenus=(except=null)=>{
    document.querySelectorAll('.finance-action-menu').forEach(menu=>{
      if(menu===except) return;
      menu.hidden=true;
    });
    document.querySelectorAll('[data-finance-action-toggle]').forEach(toggle=>{
      const target=document.getElementById(toggle.getAttribute('data-finance-action-toggle'));
      if(target!==except) toggle.setAttribute('aria-expanded','false');
    });
  };

  document.querySelectorAll('[data-finance-action-toggle]').forEach(toggle=>toggle.addEventListener('click',event=>{
    event.stopPropagation();
    if(toggle.disabled) return;
    const menu=document.getElementById(toggle.getAttribute('data-finance-action-toggle'));
    if(!menu) return;
    const willOpen=menu.hidden;
    closeFinanceActionMenus(menu);
    menu.hidden=!willOpen;
    toggle.setAttribute('aria-expanded',willOpen?'true':'false');
  }));

  document.addEventListener('click',event=>{
    if(!event.target.closest('.finance-action-menu-wrap')) closeFinanceActionMenus();
  });

  document.querySelectorAll('[data-export-table]').forEach(button=>button.addEventListener('click',()=>{
    exportTable(button.closest('.cms-card'),button.getAttribute('data-export-table')||'exportacao.csv',false);
  }));

  document.getElementById('stock-type')?.addEventListener('change',e=>{
    const el=document.getElementById('stock-qty-label'); if(el) el.textContent=e.target.value==='adjustment'?'Novo saldo final *':'Quantidade *';
  });

  const saleBody=document.getElementById('saleItems');
  if(!saleBody) return;

  const products=JSON.parse(document.getElementById('sale-products')?.textContent||'[]');
  const services=JSON.parse(document.getElementById('sale-services')?.textContent||'[]');
  const oldRows=JSON.parse(document.getElementById('sale-old-items')?.textContent||'[]');
  const oldPayments=JSON.parse(document.getElementById('sale-old-payments')?.textContent||'[]');
  const paymentBody=document.getElementById('salePayments');
  const operationType=document.getElementById('saleOperationType');
  const operationDate=document.querySelector('[name="operation_date"]');
  const submitSale=document.getElementById('submitSale');
  const saleCurrency=new Intl.NumberFormat('pt-BR',{style:'currency',currency:'BRL'});
  let saleSeq=0;
  let paymentSeq=0;
  let currentSaleTotal=0;
  let saleHydrating=true;

  const moneyValue=input=>{
    if(!input) return 0;
    if(input.dataset.moneyReady==='1') return parseMoneyText(input.value);
    return Number(input.value||0)||0;
  };

  const setMoneyValue=(input,value)=>{
    if(!input) return;
    const amount=Math.max(0,Number(value)||0);
    input.value=moneyFormatter.format(amount);
    input.dataset.moneyValue=amount.toFixed(2);
    const hidden=input.nextElementSibling?.matches?.('[data-money-hidden="1"]') ? input.nextElementSibling : null;
    if(hidden) hidden.value=amount.toFixed(2);
  };

  const itemSource=type=>type==='product' ? products : type==='service' ? services : [];

  const closeSaleSuggestions=(except=null)=>{
    document.querySelectorAll('.sale-item-suggestions').forEach(menu=>{
      if(menu===except) return;
      menu.hidden=true;
    });
  };

  const saleItemMeta=(type,item)=>{
    if(type==='product'){
      const stock=Number(item.stock_quantity||0).toLocaleString('pt-BR',{maximumFractionDigits:3});
      return [item.sku, saleCurrency.format(Number(item.sale_price||0)), 'Estoque '+stock+' '+(item.unit||'UN')]
        .filter(Boolean).join(' · ');
    }
    return [
      item.service_list_item ? 'Item '+item.service_list_item : null,
      item.cnae ? 'CNAE '+item.cnae : null,
      saleCurrency.format(Number(item.sale_price||0))
    ].filter(Boolean).join(' · ');
  };

  function addSaleRow(initial={}){
    const idx=saleSeq++;
    const type=initial.item_type||'product';
    const tr=document.createElement('tr');
    tr.className='sale-item-row';
    tr.innerHTML=
      '<td class="sale-item-main">'+
        '<div class="sale-item-picker">'+
          '<select class="sale-item-kind" aria-label="Tipo do item">'+
            '<option value="product" data-icon="product">Produto</option>'+
            '<option value="service" data-icon="service">Serviço</option>'+
            '<option value="freight" data-icon="freight">Frete</option>'+
            '<option value="expense" data-icon="expense">Outras despesas</option>'+
          '</select>'+
          '<div class="sale-item-search-wrap">'+
            '<input class="sale-item-search" type="text" autocomplete="off" placeholder="Adicionar item">'+
            '<div class="sale-item-suggestions" hidden></div>'+
          '</div>'+
          '<input class="sale-product-id" type="hidden">'+
          '<input class="sale-service-id" type="hidden">'+
        '</div>'+
        '<input class="sale-item-note" type="text" placeholder="Observações adicionais">'+
      '</td>'+
      '<td><input class="sale-unit-price" type="number" min="0" step="0.01" data-number-kind="money" value="0"></td>'+
      '<td><input class="sale-qty" type="number" min="0.001" step="0.001" value="1"></td>'+
      '<td><input class="sale-discount" type="number" min="0" step="0.01" data-number-kind="money" value="0"></td>'+
      '<td class="sale-line-total">R$ 0,00</td>'+
      '<td class="sale-row-actions">'+
        '<button type="button" class="btn-icon sale-row-edit" data-tooltip="Editar item" aria-label="Editar item"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m16 4 4 4M3 17l-.5 4.5L7 21 20 8a2.8 2.8 0 0 0-4-4L3 17Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></button>'+
        '<button type="button" class="btn-icon row-action-danger sale-row-remove" data-tooltip="Remover item" aria-label="Remover item"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18M8 6V4h8v2M5 6l1 15h12l1-15M10 10v7M14 10v7" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></button>'+
      '</td>';

    const kind=tr.querySelector('.sale-item-kind');
    const search=tr.querySelector('.sale-item-search');
    const suggestions=tr.querySelector('.sale-item-suggestions');
    const productId=tr.querySelector('.sale-product-id');
    const serviceId=tr.querySelector('.sale-service-id');
    const price=tr.querySelector('.sale-unit-price');
    const qty=tr.querySelector('.sale-qty');
    const discount=tr.querySelector('.sale-discount');
    const note=tr.querySelector('.sale-item-note');

    kind.name=`items[${idx}][item_type]`;
    search.name=`items[${idx}][description]`;
    productId.name=`items[${idx}][product_id]`;
    serviceId.name=`items[${idx}][service_id]`;
    price.name=`items[${idx}][unit_price]`;
    qty.name=`items[${idx}][quantity]`;
    discount.name=`items[${idx}][discount]`;
    note.name=`items[${idx}][notes]`;

    kind.value=type;
    qty.value=String(initial.quantity||1);
    price.value=String(initial.unit_price||0);
    discount.value=String(initial.discount||0);
    note.value=initial.notes||'';
    productId.value=initial.product_id||'';
    serviceId.value=initial.service_id||'';

    const initialSource=itemSource(type);
    const initialId=type==='product' ? initial.product_id : type==='service' ? initial.service_id : null;
    const selected=initialSource.find(item=>String(item.id)===String(initialId||''));

    if(selected){
      search.value=selected.name;
      search.dataset.selectedLabel=selected.name;
      if(initial.unit_price===undefined || initial.unit_price===null || initial.unit_price==='') price.value=String(selected.sale_price||0);
    } else if(type==='freight' || type==='expense'){
      search.value=initial.description || (type==='freight'?'Frete':'Outras despesas');
    } else {
      search.value=initial.description||'';
    }

    const configureType=()=>{
      const current=kind.value;
      const free=current==='freight'||current==='expense';
      search.placeholder=free ? 'Descrição' : current==='service' ? 'Buscar serviço...' : 'Buscar produto...';
      search.classList.toggle('free-description',free);

      if(free){
        productId.value='';
        serviceId.value='';
        suggestions.hidden=true;
        if(!search.value || search.dataset.selectedLabel===search.value){
          search.value=current==='freight'?'Frete':'Outras despesas';
        }
        delete search.dataset.selectedLabel;
      } else if(search.value && !search.dataset.selectedLabel){
        renderSuggestions();
      }

      recalcSale();
    };

    const chooseItem=item=>{
      search.value=item.name;
      search.dataset.selectedLabel=item.name;
      if(kind.value==='product'){
        productId.value=item.id;
        serviceId.value='';
      } else {
        serviceId.value=item.id;
        productId.value='';
      }
      setMoneyValue(price,Number(item.sale_price||0));
      suggestions.hidden=true;
      search.setCustomValidity('');
      recalcSale();

      if(tr===saleBody.lastElementChild){
        addSaleRow();
      }
    };

    const renderSuggestions=()=>{
      const typeNow=kind.value;
      const source=itemSource(typeNow);
      if(!source.length){
        suggestions.hidden=true;
        return;
      }

      const term=search.value.trim().toLocaleLowerCase('pt-BR');
      const matches=source.filter(item=>{
        const hay=[
          item.name,
          item.sku,
          item.service_list_item,
          item.cnae
        ].filter(Boolean).join(' ').toLocaleLowerCase('pt-BR');
        return !term || hay.includes(term);
      }).slice(0,10);

      suggestions.innerHTML='';
      if(!matches.length){
        const empty=document.createElement('div');
        empty.className='sale-suggestion-empty';
        empty.textContent='Nenhum item encontrado';
        suggestions.appendChild(empty);
      } else {
        matches.forEach(item=>{
          const option=document.createElement('button');
          option.type='button';
          option.className='sale-suggestion';
          option.innerHTML='<strong></strong><small></small>';
          option.querySelector('strong').textContent=item.name;
          option.querySelector('small').textContent=saleItemMeta(typeNow,item);
          option.addEventListener('mousedown',event=>{
            event.preventDefault();
            chooseItem(item);
          });
          suggestions.appendChild(option);
        });
      }

      closeSaleSuggestions(suggestions);
      suggestions.hidden=false;
    };

    search.addEventListener('focus',()=>{
      if(kind.value==='product'||kind.value==='service') renderSuggestions();
    });
    search.addEventListener('input',()=>{
      if(search.dataset.selectedLabel && search.value!==search.dataset.selectedLabel){
        productId.value='';
        serviceId.value='';
        delete search.dataset.selectedLabel;
      }
      if(kind.value==='product'||kind.value==='service') renderSuggestions();
      recalcSale();
    });

    kind.addEventListener('change',()=>{
      productId.value='';
      serviceId.value='';
      search.value=kind.value==='freight'?'Frete':kind.value==='expense'?'Outras despesas':'';
      delete search.dataset.selectedLabel;
      setMoneyValue(price,0);
      configureType();
    });

    qty.addEventListener('input',recalcSale);
    price.addEventListener('input',recalcSale);
    discount.addEventListener('input',recalcSale);

    tr.querySelector('.sale-row-edit').addEventListener('click',()=>{
      search.focus();
      if(kind.value==='product'||kind.value==='service') renderSuggestions();
      else search.select();
    });

    tr.querySelector('.sale-row-remove').addEventListener('click',()=>{
      tr.remove();
      if(!saleBody.querySelector('tr')) addSaleRow();
      recalcSale();
    });

    saleBody.appendChild(tr);
    enhanceSelect(kind);
    enhanceNumberInput(price);
    enhanceNumberInput(qty);
    enhanceNumberInput(discount);

    if(selected){
      setMoneyValue(price,Number(initial.unit_price!==undefined && initial.unit_price!=='' ? initial.unit_price : selected.sale_price||0));
    } else {
      setMoneyValue(price,Number(initial.unit_price||0));
    }
    setMoneyValue(discount,Number(initial.discount||0));

    configureType();
    recalcSale();
  }

  function paymentRows(){
    return [...paymentBody.querySelectorAll('[data-payment-row]')];
  }

  function setPaymentIndexes(){
    const rows=paymentRows();
    rows.forEach((row,index)=>{
      row.querySelector('.payment-position').textContent=(index+1)+'/'+rows.length;
    });
  }

  function addPayment(initial={},auto=false){
    if(!paymentBody) return;
    const idx=paymentSeq++;
    const tr=document.createElement('tr');
    tr.dataset.paymentRow='1';
    tr.dataset.auto=auto?'1':'0';
    tr.innerHTML=
      '<td class="payment-position"></td>'+
      '<td><input class="payment-amount" type="number" min="0" step="0.01" data-number-kind="money" value="0"></td>'+
      '<td><input class="payment-date" type="date"></td>'+
      '<td><select class="payment-method">'+
        '<option value="">Selecione</option>'+
        '<option value="cash">Dinheiro</option>'+
        '<option value="pix">PIX</option>'+
        '<option value="debit_card">Cartão de débito</option>'+
        '<option value="credit_card">Cartão de crédito</option>'+
        '<option value="bank_slip">Boleto</option>'+
        '<option value="bank_transfer">Transferência</option>'+
        '<option value="other">Outro</option>'+
      '</select></td>'+
      '<td><label class="payment-receivable"><input type="hidden" value="0"><input type="checkbox" value="1" checked><span>A receber</span></label></td>'+
      '<td><button type="button" class="btn-icon row-action-danger" data-tooltip="Remover parcela" aria-label="Remover parcela"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18M8 6V4h8v2M5 6l1 15h12l1-15M10 10v7M14 10v7" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></button></td>';

    const amount=tr.querySelector('.payment-amount');
    const date=tr.querySelector('.payment-date');
    const method=tr.querySelector('.payment-method');
    const hiddenReceive=tr.querySelector('.payment-receivable input[type="hidden"]');
    const receive=tr.querySelector('.payment-receivable input[type="checkbox"]');

    amount.name=`payments[${idx}][amount]`;
    date.name=`payments[${idx}][due_date]`;
    method.name=`payments[${idx}][payment_method]`;
    hiddenReceive.name=`payments[${idx}][receivable]`;
    receive.name=`payments[${idx}][receivable]`;

    amount.value=String(initial.amount??currentSaleTotal??0);
    date.value=initial.due_date || operationDate?.value || '';
    method.value=initial.payment_method||'';
    receive.checked=String(initial.receivable??'1')!=='0';

    paymentBody.appendChild(tr);
    enhanceNumberInput(amount);
    enhanceSelect(method);
    setMoneyValue(amount,Number(initial.amount??currentSaleTotal??0));

    amount.addEventListener('input',()=>{
      tr.dataset.auto='0';
      recalcPayments();
    });
    date.addEventListener('change',recalcPayments);
    receive.addEventListener('change',recalcPayments);
    const removePayment=tr.querySelector('.row-action-danger');
    removePayment?.addEventListener('click',()=>{
      tr.remove();
      if(!paymentRows().length) addPayment({amount:currentSaleTotal},true);
      splitAutomaticPayments();
      recalcPayments();
    });

    setPaymentIndexes();
    recalcPayments();
  }

  function splitAutomaticPayments(){
    const rows=paymentRows();
    if(!rows.length) return;
    const totalCents=Math.round(currentSaleTotal*100);
    const base=Math.floor(totalCents/rows.length);
    let remainder=totalCents-(base*rows.length);

    rows.forEach(row=>{
      const cents=base+(remainder>0?1:0);
      if(remainder>0) remainder--;
      row.dataset.auto='1';
      setMoneyValue(row.querySelector('.payment-amount'),cents/100);
    });
    recalcPayments();
  }

  function recalcPayments(){
    if(!paymentBody) return;
    const total=paymentRows().reduce((sum,row)=>sum+moneyValue(row.querySelector('.payment-amount')),0);
    const output=document.getElementById('paymentsTotal');
    if(output) output.textContent=saleCurrency.format(total);

    const mismatch=Math.abs(total-currentSaleTotal)>0.011;
    paymentBody.closest('.sale-finance-table-wrap')?.classList.toggle('payment-mismatch',mismatch);
    if(submitSale) submitSale.dataset.paymentMismatch=mismatch?'1':'0';

    updateSaleSubmitState();
  }

  function updateSaleSubmitState(){
    const rows=[...saleBody.querySelectorAll('.sale-item-row')];
    const validItems=rows.filter(row=>{
      const type=row.querySelector('.sale-item-kind')?.value;
      const qty=Number(row.querySelector('.sale-qty')?.value||0);
      const selected=type==='product'
        ? !!row.querySelector('.sale-product-id')?.value
        : type==='service'
          ? !!row.querySelector('.sale-service-id')?.value
          : !!row.querySelector('.sale-item-search')?.value.trim();
      return qty>0 && selected;
    }).length;

    const paymentMismatch=submitSale?.dataset.paymentMismatch==='1';
    if(submitSale) submitSale.disabled=validItems===0||paymentMismatch;
  }

  function recalcSale(){
    let total=0;
    let discountTotal=0;
    let qtyTotal=0;
    let validItems=0;
    const isSale=(operationType?.value||'sale')==='sale';

    saleBody.querySelectorAll('.sale-item-row').forEach(row=>{
      const type=row.querySelector('.sale-item-kind').value;
      const qtyInput=row.querySelector('.sale-qty');
      const priceInput=row.querySelector('.sale-unit-price');
      const discountInput=row.querySelector('.sale-discount');
      const search=row.querySelector('.sale-item-search');
      const qty=Number(qtyInput.value)||0;
      const price=moneyValue(priceInput);
      const discount=moneyValue(discountInput);
      const gross=price*qty;
      const line=Math.max(0,gross-discount);
      let valid=false;

      discountInput.setCustomValidity(discount>gross?'O desconto não pode ser maior que o valor do item.':'');
      search.setCustomValidity('');

      if(type==='product'){
        const id=row.querySelector('.sale-product-id').value;
        const item=products.find(product=>String(product.id)===String(id));
        if(!item && search.value.trim()) search.setCustomValidity('Selecione um produto da lista.');
        const controlsStock=String(item?.control_stock??'1')!=='0';
        const stock=Number(item?.stock_quantity||0);
        qtyInput.setCustomValidity(isSale && item && controlsStock && qty>stock ? 'Quantidade superior ao estoque disponível.' : '');
        valid=!!item && qty>0;
      } else if(type==='service'){
        const serviceId=row.querySelector('.sale-service-id').value;
        if(!serviceId && search.value.trim()) search.setCustomValidity('Selecione um serviço da lista.');
        qtyInput.setCustomValidity('');
        valid=!!serviceId && qty>0;
      } else {
        if(!search.value.trim()) search.setCustomValidity('Informe a descrição do item.');
        qtyInput.setCustomValidity('');
        valid=!!search.value.trim() && qty>0;
      }

      row.querySelector('.sale-line-total').textContent=saleCurrency.format(valid?line:0);

      if(valid){
        validItems++;
        total+=line;
        discountTotal+=discount;
        qtyTotal+=qty;
      }
    });

    currentSaleTotal=total;

    document.getElementById('summaryQty').textContent=qtyTotal.toLocaleString('pt-BR',{maximumFractionDigits:3});
    document.getElementById('summaryDiscount').textContent=saleCurrency.format(discountTotal);
    document.getElementById('summaryTotal').textContent=saleCurrency.format(total);
    document.getElementById('saleSaveTotal').textContent=saleCurrency.format(total);

    const label=(operationType?.value||'sale')==='quote'?'Orçamento':'Venda';
    document.getElementById('saleOperationLabel').textContent=label;

    const finance=document.getElementById('saleFinanceSection');
    if(finance) finance.hidden=validItems===0;

    if(!saleHydrating && validItems>0 && paymentRows().length===0){
      addPayment({
        amount:total,
        due_date:operationDate?.value||''
      },true);
    }

    const rows=paymentRows();
    if(rows.length===1 && rows[0].dataset.auto==='1'){
      setMoneyValue(rows[0].querySelector('.payment-amount'),total);
    }

    recalcPayments();
    updateSaleSubmitState();
  }

  document.getElementById('sale-form')?.addEventListener('submit',()=>{
    saleBody.querySelectorAll('.sale-item-row').forEach(row=>{
      const type=row.querySelector('.sale-item-kind')?.value;
      const search=row.querySelector('.sale-item-search');
      const hasSelected=type==='product'
        ? !!row.querySelector('.sale-product-id')?.value
        : type==='service'
          ? !!row.querySelector('.sale-service-id')?.value
          : !!search?.value.trim();

      const isVisualPlaceholder=(type==='product'||type==='service') && !hasSelected && !search?.value.trim();
      if(isVisualPlaceholder){
        row.querySelectorAll('[name]').forEach(control=>control.disabled=true);
      }
    });
  });

  document.addEventListener('click',event=>{
    if(!event.target.closest('.sale-item-search-wrap')) closeSaleSuggestions();
  });

  document.getElementById('addSaleItem')?.addEventListener('click',()=>addSaleRow());

  document.getElementById('addSalePayment')?.addEventListener('click',()=>{
    addPayment({amount:0,due_date:operationDate?.value||''},true);
    splitAutomaticPayments();
  });

  operationType?.addEventListener('change',recalcSale);
  operationDate?.addEventListener('change',()=>{
    paymentRows().forEach(row=>{
      const date=row.querySelector('.payment-date');
      if(!date.value) date.value=operationDate.value;
    });
  });

  document.getElementById('saleCustomer')?.addEventListener('change',event=>{
    const option=event.target.selectedOptions?.[0];
    if(!option || !option.value) return;
    const finalConsumer=document.getElementById('saleFinalConsumer');
    if(finalConsumer){
      finalConsumer.checked=option.dataset.finalConsumer!=='0';
      finalConsumer.dispatchEvent(new Event('change',{bubbles:true}));
    }
  });

  if(oldRows.length){
    oldRows.forEach(item=>addSaleRow(item));
  } else {
    addSaleRow();
  }

  if(oldPayments.length){
    oldPayments.forEach(payment=>addPayment(payment,false));
  }

  saleHydrating=false;
  recalcSale();
})();
