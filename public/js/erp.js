(() => {
  'use strict';

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
      window.alert(err.message||'Não foi possível consultar o CEP.');
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
      window.alert(err.message||'Não foi possível consultar o CNPJ.');
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
    tr.innerHTML='<td><select aria-label="Produto" required><option value="">Selecione o produto</option></select><small class="row-stock table-subtitle"></small></td><td><input aria-label="Quantidade" required type="number" min="0.001" step="0.001" value="1"></td><td class="readonly-price">R$ 0,00</td><td class="price-strong row-subtotal">R$ 0,00</td><td><button type="button" class="btn-icon" aria-label="Remover item" title="Remover item">×</button></td>';
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
