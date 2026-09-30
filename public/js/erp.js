(() => {
  'use strict';
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
    qty.value=String(quantity);select.addEventListener('change',recalc);qty.addEventListener('input',recalc);
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
