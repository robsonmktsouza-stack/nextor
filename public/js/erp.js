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
    setLoadingState(true);
    if(!loadingTimer){
      loadingTimer=setTimeout(()=>{
        loadingTimer=null;
        if(loadingCount>0){
          loadingOverlay.hidden=false;
          requestAnimationFrame(()=>loadingOverlay.classList.add('show'));
        }
      },80);
    }
  };

  const endLoading=()=>{
    loadingCount=Math.max(0,loadingCount-1);
    if(loadingCount>0) return;
    if(loadingTimer){clearTimeout(loadingTimer);loadingTimer=null;}
    loadingOverlay.classList.remove('show');
    setLoadingState(false);
    setTimeout(()=>{if(loadingCount===0) loadingOverlay.hidden=true;},120);
  };

  const resetLoading=()=>{
    loadingCount=0;
    if(loadingTimer){clearTimeout(loadingTimer);loadingTimer=null;}
    loadingOverlay.classList.remove('show');
    loadingOverlay.hidden=true;
    setLoadingState(false);
  };

  window.NextorLoading={show:beginLoading,hide:endLoading,reset:resetLoading};

  // Toda chamada fetch passa automaticamente pelo loading global.
  const nativeFetch=window.fetch.bind(window);
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

  // Navegação interna também exibe o bloqueio.
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
    beginLoading('Carregando...');
    setTimeout(()=>{if(event.defaultPrevented) endLoading();},0);
  });

  // Ao voltar pelo histórico/bfcache, nunca mantém a tela bloqueada.
  window.addEventListener('pageshow',event=>{if(event.persisted) resetLoading();});

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

    const buildOptions=()=>{
      menu.innerHTML='';
      [...select.options].forEach(option=>{
        const item=document.createElement('button');
        item.type='button';
        item.className='ui-select-option';
        item.setAttribute('role','option');
        item.dataset.value=option.value;
        item.textContent=option.textContent;
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
    };
    const sync=()=>{
      const option=select.options[select.selectedIndex];
      value.textContent=option?.textContent || select.getAttribute('placeholder') || 'Selecione';
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
      trigger.focus();
      wrapper.classList.add('open');
      menu.hidden=false;
      trigger.setAttribute('aria-expanded','true');
    });
    new MutationObserver(mutations=>{
      if(mutations.some(m=>m.type==='childList'||m.target.tagName==='OPTION')) buildOptions();
      sync();
    }).observe(select,{attributes:true,childList:true,subtree:true,attributeFilter:['disabled','selected','label']});

    buildOptions();
    sync();
  };

  document.addEventListener('click',e=>{
    if(!e.target.closest('.ui-select')) closeUiSelects();
  });
  document.querySelectorAll('select').forEach(enhanceSelect);
  window.NextorUI={enhanceSelect};

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
    const cnpj=(documentInput?.value||'').replace(/\D/g,'');
    if(cnpj.length!==14){documentInput?.focus();return;}
    const set=(name,value)=>{const field=form.querySelector('[name="'+name+'"]');if(field && value!==undefined && value!==null) field.value=value;};
    button.disabled=true;
    const oldText=button.querySelector('span')?.textContent;
    if(button.querySelector('span')) button.querySelector('span').textContent='Buscando...';
    try{
      const response=await fetch('https://brasilapi.com.br/api/cnpj/v1/'+cnpj);
      if(!response.ok) throw new Error('CNPJ não encontrado.');
      const data=await response.json();
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
      nextorNotify('Não foi possível consultar o CNPJ agora. Verifique sua conexão e tente novamente.',{type:'error',title:'Consulta de CNPJ'});
    }finally{
      button.disabled=false;
      if(button.querySelector('span')) button.querySelector('span').textContent=oldText||'Autopreencher';
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
  document.querySelectorAll('[data-confirm-submit]').forEach(form=>form.addEventListener('submit',e=>{
    const message=form.getAttribute('data-confirm-submit');
    if(message && !window.confirm(message)) e.preventDefault();
  }));

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
  const submitBulkForm=(formId,ids)=>{
    const form=document.getElementById(formId);
    if(!form) return;
    form.querySelectorAll('[data-generated-bulk]').forEach(el=>el.remove());
    ids.forEach(id=>{
      const input=document.createElement('input');
      input.type='hidden';input.name='ids[]';input.value=id;input.dataset.generatedBulk='1';
      form.appendChild(input);
    });
    form.submit();
  };

  document.querySelectorAll('.cms-card').forEach(card=>{
    const all=card.querySelector('[data-check-all]');
    const rows=[...card.querySelectorAll('[data-row-select]')];
    if(!all || !rows.length) return;
    const count=card.querySelector('[data-selection-count]');
    const menu=card.querySelector('[data-bulk-menu]');
    const apply=card.querySelector('[data-bulk-apply]');
    const update=()=>{
      const selected=rows.filter(x=>x.checked);
      all.checked=selected.length===rows.length;
      all.indeterminate=selected.length>0 && selected.length<rows.length;
      card.querySelectorAll('[data-bulk-submit]').forEach(b=>b.disabled=selected.length===0);
      if(menu) menu.disabled=selected.length===0;
      if(apply) apply.disabled=selected.length===0 || !menu?.value;
      if(count){count.hidden=selected.length===0;count.textContent=selected.length+' selecionado'+(selected.length===1?'':'s');}
    };
    all.addEventListener('change',()=>{rows.forEach(x=>x.checked=all.checked);update();});
    rows.forEach(x=>x.addEventListener('change',update));
    menu?.addEventListener('change',update);
    card.querySelectorAll('[data-bulk-submit]').forEach(button=>button.addEventListener('click',()=>{
      const selected=rows.filter(x=>x.checked).map(x=>x.value);
      if(!selected.length) return;
      const msg=button.getAttribute('data-confirm');
      if(msg && !window.confirm(msg)) return;
      submitBulkForm(button.getAttribute('data-bulk-submit'),selected);
    }));
    apply?.addEventListener('click',()=>{
      const selected=rows.filter(x=>x.checked);
      if(!selected.length || !menu?.value) return;
      const option=menu.selectedOptions[0];
      const msg=option?.dataset.confirm;
      if(msg && !window.confirm(msg)) return;
      if(menu.value==='local:export'){
        exportTable(card,(card.querySelector('[data-export-table]')?.getAttribute('data-export-table')||'selecionados.csv').replace('.csv','-selecionados.csv'),true);
        return;
      }
      if(menu.value==='local:print'){
        printSelected(card);
        return;
      }
      submitBulkForm(menu.value,selected.map(x=>x.value));
    });
    update();
  });

  document.querySelectorAll('[data-export-table]').forEach(button=>button.addEventListener('click',()=>{
    exportTable(button.closest('.cms-card'),button.getAttribute('data-export-table')||'exportacao.csv',false);
  }));

  document.getElementById('stock-type')?.addEventListener('change',e=>{
    const el=document.getElementById('stock-qty-label'); if(el) el.textContent=e.target.value==='adjustment'?'Novo saldo final *':'Quantidade *';
  });

  const saleBody = document.getElementById('saleItems');
  if (!saleBody) return;
  const products=JSON.parse(document.getElementById('sale-products')?.textContent||'[]');
  const oldRows=JSON.parse(document.getElementById('sale-old-items')?.textContent||'[]');
  const money=value=>new Intl.NumberFormat('pt-BR',{style:'currency',currency:'BRL'}).format(value);
  let seq=0;
  function row(productId='', quantity='1') {
    const idx=seq++;
    const tr=document.createElement('tr');
    tr.innerHTML='<td><select aria-label="Produto" required><option value="">Selecione o produto</option></select><small class="row-stock table-subtitle"></small></td><td><input aria-label="Quantidade" required type="number" min="0.001" step="0.001" value="1"></td><td class="readonly-price">R$ 0,00</td><td class="price-strong row-subtotal">R$ 0,00</td><td><button type="button" class="btn-icon" aria-label="Remover item" data-tooltip="Remover item">×</button></td>';
    const select=tr.querySelector('select'),qty=tr.querySelector('input');
    select.name=`items[${idx}][product_id]`;qty.name=`items[${idx}][quantity]`;
    for (const p of products) {const o=document.createElement('option');o.value=String(p.id);o.textContent=`${p.sku} — ${p.name}`;if(String(p.id)===String(productId))o.selected=true;select.appendChild(o);}
    qty.value=String(quantity);select.addEventListener('change',recalc);qty.addEventListener('input',recalc);enhanceSelect(select);
    tr.querySelector('button').addEventListener('click',()=>{tr.remove();recalc();});saleBody.appendChild(tr);recalc();
  }
  function recalc() {
    let total=0,items=0,qtySum=0;const selected=[];
    saleBody.querySelectorAll('tr').forEach(tr=>{
      const id=tr.querySelector('select').value,qty=Number(tr.querySelector('input').value)||0;
      const p=products.find(x=>String(x.id)===id);const price=p?Number(p.sale_price):0;
      tr.querySelector('.readonly-price').textContent=money(price);
      tr.querySelector('.row-subtotal').textContent=money(price*qty);
      tr.querySelector('.row-stock').textContent=p?`Disponível: ${Number(p.stock_quantity).toLocaleString('pt-BR',{minimumFractionDigits:3})} ${p.unit}`:'';
      if(p && qty>0){items++;qtySum+=qty;total+=price*qty;selected.push(id);}
      tr.querySelector('input').setCustomValidity(p&&qty>Number(p.stock_quantity)?'Quantidade superior ao estoque disponível.':'');
    });
    const duplicate=selected.length!==new Set(selected).size;
    document.getElementById('summaryItems').textContent=String(items);
    document.getElementById('summaryQty').textContent=qtySum.toLocaleString('pt-BR',{maximumFractionDigits:3});
    document.getElementById('summaryTotal').textContent=money(total);
    document.getElementById('submitSale').disabled=items===0||duplicate;
  }
  document.getElementById('addSaleItem')?.addEventListener('click',()=>row());
  if(oldRows.length)oldRows.forEach(item=>row(item.product_id,item.quantity)); else row();
})();
