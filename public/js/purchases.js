document.addEventListener('DOMContentLoaded',()=>{
  const form=document.getElementById('purchaseForm');
  const tbody=document.querySelector('[data-purchase-items]');
  if(!form || !tbody)return;
  const parse=(id)=>{try{return JSON.parse(document.getElementById(id).textContent)}catch{return []}};
  const products=parse('purchaseProductsJson');
  const previous=parse('purchaseItemsJson');
  const productMap=new Map(products.map(p=>[String(p.id),p]));
  const reais=n=>new Intl.NumberFormat('pt-BR',{style:'currency',currency:'BRL'}).format(n/100);
  const cents=s=>Math.round(Math.max(0,Number(s)||0)*100);
  const mills=s=>Math.round(Math.max(0,Number(s)||0)*1000);
  let next=0;
  function add(data={}) {
    const idx=next++;
    const tr=document.createElement('tr');
    tr.innerHTML='<td><select name="items['+idx+'][product_id]" required data-product><option value="">Selecione um produto</option></select></td>'+
      '<td><input name="items['+idx+'][quantity]" type="number" min="0.001" step="0.001" value="1" required data-qty></td>'+
      '<td><input name="items['+idx+'][unit_cost]" type="number" min="0" step="0.01" value="0.00" required data-cost></td>'+
      '<td class="price-strong nowrap" data-line-total>R$ 0,00</td>'+
      '<td class="action-cell"><button type="button" class="btn-icon" data-purchase-remove aria-label="Remover produto" data-tooltip="Remover produto">×</button></td>';
    const select=tr.querySelector('[data-product]');
    for(const product of products) {
      const option=new Option(product.sku+' — '+product.name+' ('+product.unit+')',product.id);
      select.add(option);
    }
    select.value=String(data.product_id??'');
    tr.querySelector('[data-qty]').value=data.quantity??'1';
    tr.querySelector('[data-cost]').value=data.unit_cost??(productMap.get(select.value)?.cost_price??'0.00');
    select.addEventListener('change',()=>{
      tr.querySelector('[data-cost]').value=productMap.get(select.value)?.cost_price??'0.00';
      recalc();
    });
    tr.querySelector('[data-purchase-remove]').addEventListener('click',()=>{tr.remove();recalc()});
    tr.querySelectorAll('input').forEach(x=>x.addEventListener('input',recalc));
    tbody.appendChild(tr);
    recalc();
  }
  function recalc(){
    let subtotal=0;
    tbody.querySelectorAll('tr').forEach(tr=>{
      const line=Math.round(mills(tr.querySelector('[data-qty]').value)*cents(tr.querySelector('[data-cost]').value)/1000);
      subtotal+=line;
      tr.querySelector('[data-line-total]').textContent=reais(line);
    });
    const freight=cents(form.querySelector('[name="freight"]').value);
    const discount=cents(form.querySelector('[name="discount"]').value);
    form.querySelectorAll('[data-purchase-subtotal]').forEach(x=>x.textContent=reais(subtotal));
    form.querySelectorAll('[data-purchase-total]').forEach(x=>x.textContent=reais(subtotal+freight-discount));
  }
  form.querySelector('[data-purchase-add]')?.addEventListener('click',()=>add());
  form.querySelectorAll('[data-purchase-money]').forEach(x=>x.addEventListener('input',recalc));
  if(previous.length)previous.forEach(add);else add();
  form.addEventListener('submit',e=>{
    if(!tbody.querySelector('tr')){e.preventDefault();alert('Inclua ao menos um produto.');return}
    const ids=[...tbody.querySelectorAll('[data-product]')].map(x=>x.value);
    if(ids.some(x=>!x)||ids.length!==new Set(ids).size){e.preventDefault();alert('Escolha produtos diferentes em cada linha.');}
  });
});
