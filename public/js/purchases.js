document.addEventListener('DOMContentLoaded',()=>{
  'use strict';
  const form=document.getElementById('purchaseForm');
  const container=form?.querySelector('[data-purchase-items]');
  if(!container)return;

  const readJson=id=>{try{return JSON.parse(document.getElementById(id).textContent)}catch{return []}};
  const products=readJson('purchaseProductsJson');
  const oldItems=readJson('purchaseItemsJson');
  const productMap=new Map(products.map(product=>[String(product.id),product]));
  document.addEventListener('lumeron:catalog-created',event=>{
    const {kind,item}=event.detail||{};
    if(kind!=='products'||!item)return;
    if(!productMap.has(String(item.id)))products.push(item);
    productMap.set(String(item.id),item);
  });
  const currency=new Intl.NumberFormat('pt-BR',{style:'currency',currency:'BRL'});
  const display=cents=>currency.format(cents/100);

  // O ERP transforma os inputs monetários em textos "1.234,56" e campos hidden.
  // Calcular usando Number(input.value) faz a soma zerar quando há vírgulas.
  const decimal=value=>{
    let valueText=String(value??'').replace(/[^0-9,.-]/g,'').trim();
    if(!valueText)return 0;
    if(valueText.includes(','))valueText=valueText.replace(/\./g,'').replace(',','.');
    const n=Number(valueText);
    return Number.isFinite(n)?n:0;
  };
  const cents=input=>Math.round(Math.max(0,decimal(input?.value))*100);
  const mills=input=>Math.round(Math.max(0,decimal(input?.value))*1000);
  const lineFields=['discount','other_costs','external_costs','ipi','icms_st'];
  let rowId=0;

  const field=(id,name,label,value='0.00')=>(
    '<label class="purchase-line-field"><span>'+label+'</span>'+
    '<input name="items['+id+']['+name+']" type="number" data-number-kind="money" min="0" step="0.01" value="'+String(value??'0.00').replace(/[^0-9.]/g,'')+'" data-purchase-field="'+name+'"></label>'
  );

  const notify=message=>{
    const node=form.querySelector('[data-purchase-error]');
    node.textContent=message;
    node.hidden=false;
    node.scrollIntoView({block:'nearest',behavior:'smooth'});
  };

  const moneyAssign=(input,value)=>{
    const n=Math.max(0,Number(value)||0);
    if(input.dataset.moneyReady==='1'){
      input.value=n.toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2});
      input.dataset.moneyValue=n.toFixed(2);
      const hidden=input.nextElementSibling;
      if(hidden?.dataset.moneyHidden==='1')hidden.value=n.toFixed(2);
    }else{
      input.value=n.toFixed(2);
    }
  };

  function addItem(data={}){
    const index=rowId++;
    const line=document.createElement('div');
    line.className='purchase-item-line';
    line.dataset.purchaseItem='1';
    if(data.source_description)line.dataset.importedSource='1';
    line.innerHTML=
      '<div class="purchase-item-primary">'+
        '<div class="purchase-product-cell"><div class="purchase-item-source" data-purchase-source hidden></div>'+
          '<label class="purchase-line-field purchase-product-field"><span>Produto do estoque *</span>'+
          '<select required name="items['+index+'][product_id]" data-quick-create="products" data-product><option value="">Selecione um produto</option></select></label></div>'+
        '<label class="purchase-line-field"><span>Quantidade *</span>'+
          '<input type="number" name="items['+index+'][quantity]" min="0.001" step="0.001" required data-qty value="1"></label>'+
        '<label class="purchase-line-field"><span>Custo unitário *</span>'+
          '<input type="number" name="items['+index+'][unit_cost]" data-number-kind="money" min="0" step="0.01" required data-cost value="0.00"></label>'+
        '<div class="purchase-item-amount"><span>Total do produto</span><strong data-line-total>R$ 0,00</strong></div>'+
        '<button type="button" class="btn-icon purchase-remove" data-purchase-remove aria-label="Remover item" data-tooltip="Remover produto"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M8 6V4h8v2M5 6l1 15h12l1-15M10 10v7M14 10v7"/></svg></button>'+
      '</div>'+
      '<div class="purchase-item-options"><button type="button" class="purchase-details-toggle" data-purchase-toggle aria-expanded="false">▸ Custos e tributos <span data-purchase-charge-hint></span></button><span class="purchase-link-status" data-purchase-link-status></span></div>'+
      '<div class="purchase-item-charges" data-purchase-charges hidden>'+        field(index,'discount','Desconto',data.discount)+
        field(index,'other_costs','Outros custos',data.other_costs)+
        field(index,'external_costs','Custos externos',data.external_costs)+
        field(index,'ipi','IPI',data.ipi)+
        field(index,'icms_st','ICMS-ST',data.icms_st)+
      '</div>';

    const select=line.querySelector('[data-product]');
    const productLabel=line.querySelector('.purchase-product-field');
    const sourceBar=line.querySelector('[data-purchase-source]');
    if(data.source_description){
      sourceBar.hidden=false;
      const source=document.createElement('span');
      source.className='purchase-import-product';
      source.textContent=(data.source_code?data.source_code+' — ':'')+data.source_description;
      const badge=document.createElement('strong');
      badge.textContent='Item da NF-e';
      sourceBar.append(badge,source);
    }
    const mapButton=document.createElement('button');
    mapButton.type='button';mapButton.className='purchase-map-button';
    mapButton.textContent='Vincular / cadastrar';
    mapButton.setAttribute('aria-label','Vincular ou cadastrar produto do estoque');
    productLabel.appendChild(mapButton);
    const chargesPanel=line.querySelector('[data-purchase-charges]');
    const toggle=line.querySelector('[data-purchase-toggle]');
    toggle.addEventListener('click',()=>{
      const expanded=toggle.getAttribute('aria-expanded')!=='true';
      toggle.setAttribute('aria-expanded',String(expanded));
      toggle.firstChild.textContent=(expanded?'▾':'▸')+' Custos e tributos ';
      chargesPanel.hidden=!expanded;
    });
    const updateLinkStatus=()=>{
      const status=line.querySelector('[data-purchase-link-status]');
      status.textContent=select.value?'Vinculado ao estoque':'Pendente de vínculo';
      status.classList.toggle('is-pending',!select.value);
    };
    const selectProduct=product=>{
      if(!productMap.has(String(product.id)))products.push(product);
      productMap.set(String(product.id),product);
      if(![...select.options].some(option=>option.value===String(product.id)))
        select.add(new Option(product.sku+' — '+product.name+' ('+product.unit+')',product.id));
      select.value=String(product.id);
      select.dispatchEvent(new Event('change',{bubbles:true}));
    };
    mapButton.addEventListener('click',()=>{
      window.LumeronCatalog?.pick('products',{
        initial:data.source_description||'',
        defaults:{name:data.source_description||'',sku:data.source_code||'',unit:data.source_unit||'UN'},
        items:products,
        onSelect:selectProduct,
      });
    });
    for(const p of products)select.add(new Option(p.sku+' — '+p.name+' ('+p.unit+')',p.id));
    select.value=String(data.product_id??'');
    updateLinkStatus();
    line.querySelector('[data-qty]').value=data.quantity??'1';
    line.querySelector('[data-cost]').value=data.unit_cost??(productMap.get(select.value)?.cost_price??'0.00');

    select.addEventListener('change',()=>{
      updateLinkStatus();
      if(!line.dataset.importedSource)
        moneyAssign(line.querySelector('[data-cost]'),productMap.get(select.value)?.cost_price??0);
      calculate();
    });
    line.querySelector('[data-purchase-remove]').addEventListener('click',()=>{
      line.remove();calculate();
    });
    container.appendChild(line);
    calculate();
  }

  function calculate(){
    let subtotal=0,qtyTotal=0;
    let lineInvalid=false;
    container.querySelectorAll('[data-purchase-item]').forEach(line=>{
      const quantity=mills(line.querySelector('[data-qty]'));
      const unitCost=cents(line.querySelector('[data-cost]'));
      const base=Math.round(quantity*unitCost/1000);
      const charges=Object.fromEntries(lineFields.map(key=>[key,cents(line.querySelector('[data-purchase-field="'+key+'"]'))]));
      const amount=base-charges.discount+charges.other_costs+charges.external_costs+charges.ipi+charges.icms_st;
      lineInvalid ||= amount<0;
      subtotal+=amount;
      qtyTotal+=quantity;
      line.querySelector('[data-line-total]').textContent=display(amount);
      const extra=charges.other_costs+charges.external_costs+charges.ipi+charges.icms_st-charges.discount;
      const hint=line.querySelector('[data-purchase-charge-hint]');
      if(hint)hint.textContent=extra!==0?' · '+(extra>0?'+':'−')+display(Math.abs(extra)):'';

    });
    const freight=cents(form.querySelector('[name="freight"],[data-money-name="freight"]'));
    const otherExpenses=cents(form.querySelector('[name="other_expenses"],[data-money-name="other_expenses"]'));
    const discount=cents(form.querySelector('[name="discount"],[data-money-name="discount"]'));
    const total=subtotal+freight+otherExpenses-discount;
    form.querySelectorAll('[data-purchase-subtotal]').forEach(x=>x.textContent=display(subtotal));
    form.querySelectorAll('[data-purchase-total]').forEach(x=>x.textContent=display(total));
    form.querySelector('[data-purchase-empty]').hidden=container.children.length>0;
    const error=form.querySelector('[data-purchase-error]');
    if(error)error.hidden=true;
    form.dataset.purchaseValid=(!lineInvalid && total>0 && total<=999999999999)?'1':'0';
    return {total,subtotal,quantity:qtyTotal};
  }

  form.querySelector('[data-purchase-add]').addEventListener('click',()=>{
    window.LumeronCatalog?.pick('products',{
      items:products,
      onSelect:product=>{
        if(!productMap.has(String(product.id)))products.push(product);
        productMap.set(String(product.id),product);
        const existing=[...container.querySelectorAll('[data-purchase-item]')]
          .find(line=>line.querySelector('[data-product]')?.value===String(product.id));
        if(existing){
          const qty=existing.querySelector('[data-qty]');
          qty.value=String((decimal(qty.value)||0)+1);
          calculate();
          qty.focus();return;
        }
        addItem({product_id:product.id,quantity:'1',unit_cost:product.cost_price});
      }
    });
  });
  form.querySelector('[data-quick-supplier]')?.addEventListener('click',()=>{
    window.LumeronCatalog?.create('suppliers',{
      initial:form.dataset.importSupplierName||'',
      defaults:{name:form.dataset.importSupplierName||'',document:form.dataset.importSupplierDocument||''},
      onSelect:supplier=>{
        const select=form.querySelector('select[name="supplier_id"]');
        if(!select)return;
        select.add(new Option(supplier.name+(supplier.document?' — '+supplier.document:''),supplier.id,false,true));
        select.value=String(supplier.id);
        select.dispatchEvent(new Event('change',{bubbles:true}));
      }
    });
  });
  container.addEventListener('input',()=>requestAnimationFrame(calculate));
  container.addEventListener('change',()=>requestAnimationFrame(calculate));
  form.querySelectorAll('[data-purchase-extra]').forEach(node=>{
    node.addEventListener('input',()=>requestAnimationFrame(calculate));
    node.addEventListener('change',()=>requestAnimationFrame(calculate));
  });
  const payable=form.querySelector('[data-purchase-payable]');
  const due=form.querySelector('[data-purchase-due]');
  payable?.addEventListener('change',()=>{
    if(payable.checked && !due.value)due.focus();
  });

  // Delegação especial para inputs monetários aprimorados pelo erp.js.
  // Os campos ficam nomeados no hidden; o input visível continua atualizando o resumo.
  if(oldItems.length)oldItems.forEach(addItem);
  requestAnimationFrame(calculate);

  form.addEventListener('submit',event=>{
    const rows=[...container.querySelectorAll('[data-purchase-item]')];
    const ids=rows.map(row=>row.querySelector('[data-product]').value);
    if(!rows.length){
      event.preventDefault();notify('Inclua pelo menos um produto.');return;
    }
    if(ids.some(id=>!id)||ids.length!==new Set(ids).size){
      event.preventDefault();notify('Selecione um produto diferente em cada linha.');return;
    }
    const invalid=rows.some(row=>mills(row.querySelector('[data-qty]'))<=0);
    if(invalid){
      event.preventDefault();notify('Informe quantidades maiores que zero.');return;
    }
    if(calculate().total<=0 || form.dataset.purchaseValid!=='1'){
      event.preventDefault();notify('Confira os descontos e valores: o total precisa ser positivo.');return;
    }
  });
});
