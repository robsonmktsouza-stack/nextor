import fs from 'node:fs';
import path from 'node:path';
import {execFileSync} from 'node:child_process';
import {createHash} from 'node:crypto';

const arg=(name,fallback)=>process.argv.includes(name)?process.argv[process.argv.indexOf(name)+1]:fallback;
const phase=arg('--phase','before');
const widths=arg('--widths','1366,390').split(',').map(Number);
const quick=process.argv.includes('--quick');
const baselineForms=process.argv.includes('--baseline-forms');
const comfortable=process.argv.includes('--comfortable');
const output=arg('--output','/workspace/scratch/visual-standardization');
const sourceRoot=arg('--source-root',process.cwd());
const sourceState={commit:arg('--source-ref',execFileSync('git',['rev-parse','HEAD'],{encoding:'utf8'}).trim()),workingTree:sourceRoot===process.cwd()?execFileSync('git',['status','--short'],{encoding:'utf8'}).trim():'immutable git archive',css:Object.fromEntries(fs.readdirSync(path.join(sourceRoot,'public/css')).filter(f=>f.endsWith('.css')).map(f=>[f,createHash('sha256').update(fs.readFileSync(path.join(sourceRoot,'public/css',f))).digest('hex')]))};
const base=arg('--base','http://127.0.0.1:8841');
if(!/^http:\/\/127\.0\.0\.1:\d+$/.test(base))throw Error('Only loopback audit servers are allowed.');
fs.mkdirSync(path.join(output,phase),{recursive:true});
const ids=JSON.parse(fs.readFileSync(path.join(output,'fixtures.json'),'utf8'));
const routes=[
 ['dashboard','/dashboard','dashboard'],
 ['products','/products','list'],['product-create','/products/create','form'],['product-edit',`/products/${ids.product}/edit`,'form'],
 ['customers','/customers','list'],['customer-create','/customers/create','form'],['customer-edit',`/customers/${ids.customer}/edit`,'form'],
 ['services','/services','list'],['service-create','/services/create','form'],['service-edit',`/services/${ids.service}/edit`,'form'],
 ['sales','/sales','list'],['sale-create','/sales/create','form'],['sale-detail',`/sales/${ids.sale}`,'detail'],['quote-create','/sales/create?operation_type=quote','form'],['quote-detail',`/sales/${ids.quote}`,'detail'],
 ['returns','/sales/returns','list'],['return-create','/sales/returns/create','form'],['return-detail',`/sales/returns/${ids.returned}`,'detail'],
 ['purchases','/purchases','list'],['purchase-create','/purchases/create','form'],['purchase-edit',`/purchases/${ids.purchase}/edit`,'form'],['purchase-detail',`/purchases/${ids.purchase}`,'detail'],['stock','/stock','list'],
 ['finance','/finance','dashboard'],['receivables','/finance/entries?type=receivable','list'],['payables','/finance/entries?type=payable','list'],
 ['entry-create','/finance/entries/create?type=receivable','form'],['entry-payable-create','/finance/entries/create?type=payable','form'],['entry-edit',`/finance/entries/${ids.entry}/edit`,'form'],['entry-detail',`/finance/entries/${ids.entry}`,'detail'],
 ['transfers','/finance/transfers','list'],['transfer-create','/finance/transfers/create','form'],['recurrences','/finance/recurrences','list'],['recurrence-create','/finance/recurrences/create','form'],
 ['receipts','/finance/receipts','list'],['receipt-create','/finance/receipts/create','form'],['reconciliation','/finance/reconciliation','list'],['statement-import','/finance/reconciliation/import','form'],['finance-settings','/finance/settings','settings'],
 ['receipt-print',`/finance/receipts/${ids.receipt}/print`,'print'],['pdv-receipt',`/pdv/receipt/${ids.pdvSale}?print=0&status=1`,'status'],
 ['pdv','/pdv','pdv'],['cash-registers','/pdv/cash/registers','settings'],['cash-history','/pdv/cash/history','list'],['cash-report',`/pdv/cash/${ids.cash}/report`,'detail'],
 ...['nfe','nfce','nfse','cte','mdfe'].map(m=>['fiscal-'+m,'/fiscal?tab='+m,'list']),
 ['received','/fiscal/purchases','list'],['nfe-create','/fiscal/nfe/create','form'],['nfe-edit',`/fiscal/nfe/drafts/${ids.draft}/edit`,'form'],['nfce-create','/fiscal/nfce/create','form'],['nfe-drafts','/fiscal/nfe/drafts','list'],
 ['received-detail',`/fiscal/purchases/${ids.incoming}`,'detail'],['fiscal-detail',`/fiscal/${ids.fiscalDetail}`,'detail'],['nfce-correction',`/fiscal/${ids.rejected}/correct-nfce`,'form'],
 ['nfce-readonly',`/fiscal/${ids.readonlyNfce}`,'detail'],
 ['nfe-natures','/fiscal/nfe/natures','list'],['nfe-inutilizations','/fiscal/nfe/inutilizations','form'],['nfce-inutilizations','/fiscal/nfce/inutilizations','form'],['tax-groups','/fiscal/tax-groups','list'],['tax-group-create','/fiscal/tax-groups/create','form'],['tax-group-edit',`/fiscal/tax-groups/${ids.taxGroup}/edit`,'form'],['tax-rules','/fiscal/rules','form'],['fcp','/fiscal/fcp','form'],
 ['catalog','/fiscal/catalog','list'],['catalog-benefits','/fiscal/catalog?kind=benefit&uf=AC','list'],['catalog-coverage','/fiscal/catalog?tab=coverage','list'],['catalog-history','/fiscal/catalog?tab=history','list'],
 ...['general','printing','catalog','operations','inventory','pdv','chart','accounts','payments','billing','fiscal','tax','nfe','nfce','nfse','cte','accounting','users','integrations','system'].map(tab=>['settings-'+tab,'/settings?tab='+tab,'settings']),
 ['license','/license-status','status'],['login','/login','auth'],['password-request','/forgot-password','auth'],['password-reset','/reset-password/visual-fixture-token?email=visual@example.test','auth'],
 ['license-blocked-preview','/__visual-audit/license-blocked','preview'],['session-expired-preview','/__visual-audit/session-expired','preview'],
];
const quickNames=new Set(['dashboard','products','product-create','customers','customer-create','services','service-create','sale-create','purchase-create','purchase-detail','entry-create','stock','pdv','cash-registers','fiscal-nfe','nfce-create','nfe-create','tax-rules','tax-group-create','catalog','settings-general','settings-users','settings-integrations','login']);
const selectedNames=arg('--names','').split(',').filter(Boolean);
const chosen=selectedNames.length?routes.filter(r=>selectedNames.includes(r[0])):baselineForms?routes.filter(r=>['product-create','sale-create','purchase-create','entry-create','login'].includes(r[0])):quick?routes.filter(r=>quickNames.has(r[0])):routes;
const tabs=await(await fetch('http://127.0.0.1:9228/json/list')).json();
const ws=new WebSocket(tabs.find(t=>t.type==='page').webSocketDebuggerUrl);
await new Promise((resolve,reject)=>{ws.onopen=resolve;ws.onerror=reject;});
let next=1;const pending=new Map();let events=[];
ws.onmessage=({data})=>{const p=JSON.parse(data);if(p.id){const entry=pending.get(p.id);pending.delete(p.id);if(p.error)entry?.reject(p.error);else entry?.resolve(p.result);}else events.push(p);};
const cdp=(method,params={})=>new Promise((resolve,reject)=>{const id=next++;pending.set(id,{resolve,reject});ws.send(JSON.stringify({id,method,params}));setTimeout(()=>{if(pending.delete(id))reject(Error(method+' timeout'));},12000).unref();});
const evaluate=async expression=>{const reply=await cdp('Runtime.evaluate',{expression,returnByValue:true});if(reply.exceptionDetails)throw Error(reply.exceptionDetails.text);return reply.result.value;};
await cdp('Page.enable');await cdp('Runtime.enable');await cdp('Network.enable');
await cdp('Page.setLifecycleEventsEnabled',{enabled:true});
await cdp('Network.setCacheDisabled',{cacheDisabled:true});
await cdp('Network.setBlockedURLs',{urls:['*fonts.googleapis.com*','*fonts.gstatic.com*','*servicodados.ibge.gov.br*','*viacep.com.br*','*brasilapi.com.br*']});
await cdp('Emulation.setLocaleOverride',{locale:'pt-BR'});
const stateExpression=`(${function(){
 const visible=e=>{const r=e.getBoundingClientRect(),s=getComputedStyle(e);if(r.width<=5||r.height<=5||s.display==='none'||s.visibility==='hidden'||Number(s.opacity)===0)return false;
  for(let p=e.parentElement;p;p=p.parentElement){const ps=getComputedStyle(p),pr=p.getBoundingClientRect();if(p.matches('details:not([open])')&&!p.querySelector(':scope>summary')?.contains(e))return false;if(Number(ps.opacity)===0||ps.visibility==='hidden'||ps.display==='none')return false;if(['hidden','clip'].includes(ps.overflowX)&&(r.right<=pr.left||r.left>=pr.right))return false;if(['hidden','clip'].includes(ps.overflowY)&&(r.bottom<=pr.top||r.top>=pr.bottom))return false;}return true;};
 const rounded=n=>Math.round(n*100)/100;
 const info=e=>{const s=getComputedStyle(e),r=e.getBoundingClientRect();return {tag:e.tagName,id:e.id,class:e.className,name:e.name||'',disabled:Boolean(e.disabled),readonly:Boolean(e.readOnly),text:(e.innerText||e.getAttribute('aria-label')||'').trim().slice(0,65),height:rounded(r.height),width:rounded(r.width),left:rounded(r.left),right:rounded(r.right),top:rounded(r.top),bottom:rounded(r.bottom),font:s.fontFamily,fontSize:s.fontSize,fontWeight:s.fontWeight,lineHeight:s.lineHeight,border:s.border,borderColor:s.borderColor,radius:s.borderTopLeftRadius,background:s.backgroundColor,padding:s.padding,shadow:s.boxShadow,color:s.color,overflowX:s.overflowX};};
 const components=[];
 const editable=e=>e.closest('.sale-cart-table,.sale-items-table,.purchase-items-table,.nfce-items-table,.nfe-items-table,.editable-table,td');
 const composite=e=>e.closest('.numeric-input,.input-prefix,.money-input,.input-action-group,.input-icon-group,.login-password-field,.login-subdomain-field');
 for(const e of document.querySelectorAll('input:not([type=hidden]):not([type=checkbox]):not([type=radio]):not([type=file]),textarea,.ui-select-trigger,select:not(.ui-select-native)')){
  if(!visible(e))continue;
  let role=e.tagName==='TEXTAREA'?'textarea':e.matches('.ui-select-trigger,select')?'field-select':'field-input';
  if(e.closest('.table-footerbar,.card-pagination,.bulk-actions'))role='compact-'+role;
  else if(e.closest('.grid-filter-panel,.toolbar-filters,.catalog-filterbar,.table-search,.table-search-group'))role='filter-'+role;
  else if(editable(e))role='grid-'+role;
  if(e.closest('.pdv-search-box,.pdv-entry-search,.pdv-product-search'))role='pdv-search';
  if(e.id==='pdvConsumerDocumentInput')role='pdv-document-entry';
  if(['pdvDiscountModalInput','pdvCashModalInput'].includes(e.id))role='pdv-amount-entry';
  if(e.id==='commandSearch')role='command-search';
  if(composite(e))role='inner-'+role;
  components.push({role,...info(e)});
 }
 const compositeClipped=[];
 for(const e of document.querySelectorAll('.numeric-input,.input-prefix,.money-input,.input-action-group,.input-icon-group,.login-password-field,.login-subdomain-field'))if(visible(e)){
  components.push({role:editable(e)?'grid-field-composite':'field-composite',...info(e),inputName:e.querySelector('input')?.name||''});
  const outer=e.getBoundingClientRect();for(const i of e.querySelectorAll('input'))if(visible(i)){const r=i.getBoundingClientRect();if(r.left<outer.left-1||r.right>outer.right+1||r.top<outer.top-1||r.bottom>outer.bottom+1)compositeClipped.push({wrapper:info(e),input:info(i)});}
 }
 for(const e of document.querySelectorAll('button,input[type=submit],summary,.btn,.grid-primary-action,.grid-tool,.grid-filter-button,.btn-icon,.input-action-button,.close-dialog,.pdv-receipt-action,.pdv-cash-report-history,.pdv-modal-primary,.pdv-modal-secondary,.pdv-topbar-button,.erp-catalog-footer button,.user-message-page a')){
  if(!visible(e))continue;
  if(e.matches('.ui-select-trigger'))continue;
  let role=e.matches('.btn-icon,.input-action-button,.close-dialog,.nextor-confirm-x,.pdv-modal-close')?'icon-action':e.matches('.btn-success,.btn-primary,.grid-primary-action,.pdv-modal-primary,.login-submit,[data-confirm-ok]')?'primary-action':e.matches('.btn,.grid-tool,.grid-filter-button,.bulk-apply,.period-arrow,.period-current,.pdv-modal-secondary,[data-confirm-cancel],.user-message-page a')?'secondary-action':'unclassified-action';
  if(e.closest('.grid-actionbar'))role='toolbar-'+role;
  else if(e.closest('.page-actions'))role='page-'+role;
  if(e.matches('.pdv-topbar-button'))role='pdv-topbar-action';
  if(e.matches('.editor-tab,[data-nfce-tab],.settings-tab'))role='tab-navigation';
  else if(e.matches('summary'))role='menu-trigger';
  else if(e.matches('.table-pager-button,.pager-button'))role='pagination-action';
  else if(e.matches('.cms-topbar-icon')||e.closest('.topbar,.sidebar,.command-search,.command-palette'))role='shell-action';
  else if(e.matches('.pdv-command-button'))role='pdv-shortcut-action';
  else if(e.matches('.pdv-finish'))role='pdv-checkout-action';
  else if(e.matches('.pdv-client-option')||e.closest('.pdv-operations-list,.pdv-payment-options'))role='choice-card-action';
  else if(e.matches('.sale-suggestion'))role='autocomplete-option-action';
  else if(e.matches('.pdv-cash-text-action,.pdv-split-toggle,.pdv-consumer-skip,.pdv-cash-exact,.nfe-clear-field,.nfe-section-toggle,.nfce-remove'))role='inline-action';
  else if(e.matches('.pdv-cash-open-cancel,#pdvSalesApply,#pdvSalesToday,#pdvSalesPrevious,#pdvSalesNext'))role='secondary-action';
  else if(e.matches('.pdv-cash-report-back,.pdv-cash-report-print'))role='page-secondary-action';
  else if(e.matches('.pdv-cash-report-close'))role='icon-action';
  else if(e.matches('.purchase-map-button'))role='lookup-action';
  else if(e.matches('.purchase-details-toggle'))role='disclosure-action';
  else if(e.matches('.ui-select-option'))role='select-option';
  else if(e.closest('.fiscal-utilities-dropdown'))role='menu-option-action';
  else if(e.closest('.receipt-print-toolbar'))role='print-action';
  if(role==='icon-action'&&e.closest('.input-action-group,.login-password-field,.login-subdomain-field'))role='inner-icon-action';
  if(e.id==='loginTogglePassword')role='inner-icon-action';
  if(e.matches('#pdvSalesApply,#pdvSalesToday,#pdvSalesPrevious,#pdvSalesNext'))role='toolbar-secondary-action';
  if(e.id==='pdvConsumerApply')role='pdv-document-confirm';
  if(e.matches('.pdv-receipt-action'))role='responsive-page-action';
  if(e.matches('.pdv-cash-report-history'))role='page-secondary-action';
  if(e.matches('.finance-action-icon,.finance-action-split,.finance-action-main'))role='toolbar-secondary-action';
  if(e.matches('.finance-action-caret'))role='split-segment-action';
  if(e.matches('.fiscal-purchases-switch-button'))role='toggle-action';
  if(e.matches('.btn-sm'))role='compact-action';
  if(e.matches('.grid-primary-action'))role='toolbar-primary-action';
  if(role==='unclassified-action'&&e.closest('form[action$="/logout"]'))role='session-action';
  if(role==='unclassified-action'&&e.getAttribute('onclick')?.includes('print('))role='print-action';
  components.push({role,...info(e)});
 }
 for(const e of document.querySelectorAll('.cms-card,.editor-panel,.table-scroll,.grid-actionbar,.dialog-footer,.editor-savebar,.erp-dialog,.nextor-modal-window,dialog,.nextor-confirm-dialog'))if(visible(e))components.push({role:e.matches('.nfe-workspace-dialog,.nfce-workspace-dialog')?'workspace-modal':e.matches('dialog,.erp-dialog,.nextor-modal-window,.nextor-confirm-dialog')?'modal':e.matches('.grid-actionbar')?'toolbar':e.matches('.dialog-footer,.editor-savebar')?'savebar':e.matches('.table-scroll')?'table-container':'panel',...info(e)});
 for(const e of [...document.querySelectorAll('.cms-table thead th,.cms-table tbody td')].filter(visible).slice(0,12))components.push({role:e.tagName==='TH'?'table-header':'table-cell',...info(e)});
 for(const e of document.querySelectorAll('.ui-select-menu,.fiscal-utilities-dropdown,.nextor-tooltip,.dialog-header h2,.pdv-modal-head h2,.pdv-receipt-header h2,.pdv-cash-report-heading h2,.nextor-confirm-title'))if(visible(e))components.push({role:e.matches('.ui-select-menu')?'select-popup':e.matches('.fiscal-utilities-dropdown')?'utilities-popup':e.matches('.nextor-tooltip')?'tooltip':'modal-title',...info(e)});
 for(const e of document.querySelectorAll('.dialog-header,.pdv-modal-head,.pdv-receipt-header,.pdv-cash-report-toolbar,.nextor-confirm-head,.dialog-header p,.pdv-modal-head p,.pdv-modal-help>span,.pdv-operations-list small'))if(visible(e))components.push({role:e.matches('.dialog-header,.pdv-modal-head,.pdv-receipt-header,.pdv-cash-report-toolbar,.nextor-confirm-head')?'modal-header':'modal-helper',...info(e)});
 for(const e of document.querySelectorAll('.switch-track,.permission-switch-track,.fiscal-purchases-switch-track'))if(visible(e)){const thumb=getComputedStyle(e,'::after'),custom=e.matches('.fiscal-purchases-switch-track')?e.querySelector('.fiscal-purchases-switch-thumb'):null,t=custom?getComputedStyle(custom):thumb;components.push({role:'toggle-track',...info(e),thumb:{width:t.width,height:t.height,top:t.top,left:t.left,transform:t.transform}});}
 const clipped=[];
 for(const e of document.querySelectorAll('button,summary,a.btn,input:not([type=hidden]),textarea,.ui-select-trigger,[role=dialog]')){
  if(!visible(e))continue;const r=e.getBoundingClientRect();if(r.right<=innerWidth+2&&r.left>=-2)continue;
  let p=e.parentElement,localScroll=false;while(p&&p!==document.body){const s=getComputedStyle(p);if(['auto','scroll'].includes(s.overflowX)&&p.scrollWidth>p.clientWidth+1){localScroll=true;break;}p=p.parentElement;}
  if(!localScroll)clipped.push(info(e));
 }
 return {url:location.href,title:document.title,width:innerWidth,height:innerHeight,documentWidth:document.documentElement.scrollWidth,overflow:document.documentElement.scrollWidth>innerWidth+2,components,clipped,compositeClipped,visibleForms:[...document.forms].filter(f=>[...f.querySelectorAll('input,select,textarea,button')].some(visible)).length,headings:[...document.querySelectorAll('h1,h2')].filter(visible).map(e=>e.textContent.trim()),tabs:[...document.querySelectorAll('.editor-tab[data-tab-target],.editor-tab[data-nfe-jump],[data-nfce-tab]')].map(e=>({text:e.textContent.trim(),target:e.dataset.tabTarget||e.dataset.nfeJump||e.dataset.nfceTab}))};
}.toString()})()`;
const results=[];
let sourceEnd=null;
const browserVersion=await cdp('Browser.getVersion');
const save=()=>fs.writeFileSync(path.join(output,phase+'-matrix.json'),JSON.stringify({phase,density:comfortable?'comfortable':'compact',baseline:'5e81321',source_state:sourceState,source_end:sourceEnd,browser:browserVersion,node:process.version,routes:chosen.length,widths,results},null,2)+'\n');
async function measure(name,route,kind,width,state='default'){
 const data=await evaluate(stateExpression);
 const allowedRedirect=name==='finance-settings'&&new URL(data.url).pathname==='/settings';
 if(new URL(data.url).pathname!==new URL(base+route).pathname&&!allowedRedirect)throw Error(`Unexpected redirect while measuring ${state}: ${data.url}`);
 if(kind==='form'&&!data.visibleForms)throw Error(`The expected form was not visible in ${state}.`);
 const assertVisible=async selector=>{const ok=await evaluate(`(()=>{const e=document.querySelector(${JSON.stringify(selector)});if(!e)return false;const r=e.getBoundingClientRect();return r.width>5&&r.height>5&&!e.hidden&&getComputedStyle(e).visibility!=='hidden';})()`);if(!ok)throw Error(`Required visual target was not open in ${state}: ${selector}`);};
 if(name.startsWith('nfe-')&&['nfe-create','nfe-edit'].includes(name))await assertVisible('#nfeEditorDialog.is-open:not([hidden])');
 if(['nfce-create','nfce-correction','nfce-readonly'].includes(name))await assertVisible('#nfceEditor.is-open:not([hidden])');
 if(state.startsWith('modal-')){
  const modal=state.slice(6),selector=modal==='confirm'?'#nextorConfirm.show:not([hidden])':modal==='quick-product'?'#erpCatalogDialog[open]':modal.startsWith('item')?'#nfeItemDialog.is-open:not([hidden])':modal==='operations'?'#pdvOperationsModal[open]':modal==='payment'?'#pdvPaymentModal[open]':modal==='cash-open'?'#pdvCashOpenDialog[open]':modal==='receipt-history'?'#pdvHistoryReceiptModal[open]':modal==='nature'?'#nature-create[open]':modal==='settle'?'#finance-settle[open]':modal.startsWith('nfe')?`#${modal}.is-open:not([hidden])`:`#${modal}[open]`;
  await assertVisible(selector);
 }
 if(state.startsWith('tab-')){const tab=state.slice(4);await assertVisible(['nfce-create','nfce-correction','nfce-readonly'].includes(name)?`[data-nfce-panel="${tab}"]:not([hidden])`:`#${tab},[data-tab-panel="${tab}"]:not([hidden])`);}
 if(state==='select-open')await assertVisible('.ui-select.open .ui-select-menu:not([hidden])');
 if(state==='menu-utilities')await assertVisible('details.fiscal-utilities[open] .fiscal-utilities-dropdown');
 if(state==='tooltip-open')await assertVisible('.nextor-tooltip:not([hidden])');
 const documentResponses=events.filter(e=>e.method==='Network.responseReceived'&&e.params.type==='Document').map(e=>({url:e.params.response.url,status:e.params.response.status,views:(Object.entries(e.params.response.headers).find(([key])=>key.toLowerCase()==='x-visual-audit-views')?.[1]||'').split(',').filter(Boolean)}));
 const renderedViews=[...new Set(events.filter(e=>e.method==='Network.responseReceived').flatMap(e=>(Object.entries(e.params.response.headers).find(([key])=>key.toLowerCase()==='x-visual-audit-views')?.[1]||'').split(',').filter(Boolean)))];
 const entry={name,route,kind,state,width,...data,assertions:{route:true,form:kind!=='form'||data.visibleForms>0,target:true},http:documentResponses,renderedViews,jsErrors:events.filter(e=>e.method==='Runtime.exceptionThrown').map(e=>e.params.exceptionDetails.exception?.description||e.params.exceptionDetails.text)};
 if(width===1366||width===390||entry.overflow||entry.clipped.length){
  const file=`${name}-${state}-${width}.png`;
  const shot=await cdp('Page.captureScreenshot',{format:'png',captureBeyondViewport:false,fromSurface:true});fs.writeFileSync(path.join(output,phase,file),Buffer.from(shot.data,'base64'));entry.screenshot=path.join(output,phase,file);
 }
 results.push(entry);return entry;
}
for(const width of widths){
 const height=width===390?844:width===1920?1080:768;
 await cdp('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false});
 for(const [name,route,kind]of chosen){
  events=[];
  try{
   const navigation=await cdp('Page.navigate',{url:base+route});
   let loaded=false;
   for(let i=0;i<100;i++){
    await new Promise(r=>setTimeout(r,70));
    loaded=events.some(e=>e.method==='Page.lifecycleEvent'&&e.params.name==='load'&&e.params.loaderId===navigation.loaderId);
    if(loaded&&await evaluate("document.readyState==='complete'"))break;
   }
   if(!loaded)throw Error('The current document did not finish loading; no visual measurement was accepted.');
   await cdp('Runtime.evaluate',{expression:'document.fonts.ready',awaitPromise:true});
   await new Promise(r=>setTimeout(r,120));
   await cdp('Runtime.evaluate',{expression:'new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)))',awaitPromise:true});
   if(comfortable)await evaluate("document.body.classList.remove('system-compact');document.body.classList.add('system-comfortable');");
   if(name==='quote-create')await evaluate("(()=>{const select=document.getElementById('saleOperationType');if(select){select.value='quote';select.dispatchEvent(new Event('change',{bubbles:true}));}})()");
   const entry=await measure(name,route,kind,width);
   if(width===1366)console.log(`${phase} ${name} ${entry.http.at(-1)?.status} overflow=${entry.overflow} clipped=${entry.clipped.length}`);
   if(!quick&&!baselineForms&&(width===1366||width===390)){
    if(['product-create','product-edit','received','settings-general','settings-users'].includes(name)){
     for(const checked of [false,true]){await evaluate(`document.querySelectorAll('.switch-field input[type=checkbox],.permission-switch input[type=checkbox]').forEach(i=>i.checked=${checked});document.querySelectorAll('.fiscal-purchases-switch-button').forEach(b=>b.setAttribute('aria-checked','${checked}'));`);await new Promise(r=>setTimeout(r,220));await measure(name,route,kind,width,'toggles-'+(checked?'on':'off'));}
    }
    if(['product-create','nfe-create'].includes(name)){
     await evaluate("(()=>{const t=[...document.querySelectorAll('.field .ui-select-trigger')].find(e=>e.getBoundingClientRect().width>5);if(!t)throw Error('Missing dropdown trigger');t.click();})()");await new Promise(r=>setTimeout(r,180));await measure(name,route,kind,width,'select-open');await evaluate('document.body.click()');
    }
    if(name==='fiscal-nfce'){
     await evaluate("document.querySelector('details.fiscal-utilities').open=true");await measure(name,route,kind,width,'menu-utilities');await evaluate("document.querySelector('details.fiscal-utilities').open=false");
    }
    if(name==='products'){
     await evaluate("(()=>{const t=document.querySelector('[data-tooltip]');if(!t)throw Error('Missing tooltip target');t.dispatchEvent(new PointerEvent('pointerover',{bubbles:true}));})()");await new Promise(r=>setTimeout(r,180));await measure(name,route,kind,width,'tooltip-open');await evaluate("document.querySelector('[data-tooltip]').dispatchEvent(new PointerEvent('pointerout',{bubbles:true}))");
    }
    for(const tab of entry.tabs.slice(1)){
     await evaluate(`(()=>{const t=[...document.querySelectorAll('.editor-tab[data-tab-target],.editor-tab[data-nfe-jump],[data-nfce-tab]')].find(e=>(e.dataset.tabTarget||e.dataset.nfeJump||e.dataset.nfceTab)===${JSON.stringify(tab.target)});t?.click();})()`);
     await new Promise(r=>setTimeout(r,80));await measure(name,route,kind,width,'tab-'+tab.target);
    }
    if(['tax-rules','tax-group-create','tax-group-edit','settings-users'].includes(name)){
     await evaluate("document.querySelectorAll('details:not([hidden])').forEach(d=>d.open=true)");await measure(name,route,kind,width,'details-expanded');
    }
   }
   let modalActions=name==='products'?[['confirm',"window.NextorConfirm?.('Conferir apenas a aparência, sem executar operação.',{title:'Confirmação visual',type:'action'});true"]]
    :name==='sale-create'?[['quick-product',"window.LumeronCatalog?.create('products');true"]]
    :name==='nfe-create'?[['item',"document.getElementById('nfeAddProduct')?.click();true"]]
    :name==='pdv'?[['operations',"document.getElementById('pdvOperations')?.click();true"],['payment',"document.getElementById('pdvPaymentModal')?.showModal();true"],['cash-open',"document.getElementById('pdvCashOpenDialog')?.showModal();true"]]
    :name==='nfe-natures'?[['nature',"document.querySelector('[data-dialog-open=\"nature-create\"]')?.click();true"]]:[];
   if(name==='entry-detail')modalActions.push(['settle',"document.querySelector('[data-dialog-open=\"finance-settle\"]')?.click();true"]);
   if(['pdv','receivables','payables'].includes(name)){
    const dialogs=await evaluate("[...document.querySelectorAll('dialog[id]')].map(d=>d.id).filter(id=>!['erpCatalogDialog','pdvPaymentModal','pdvCashOpenDialog'].includes(id))");
    const existing=new Set(modalActions.map(m=>m[0]));
    for(const id of dialogs)if(!existing.has(id))modalActions.push([id,`document.getElementById(${JSON.stringify(id)})?.showModal();true`]);
   }
   if(name==='pdv')modalActions.push(['receipt-history',`(async()=>{const response=await fetch('/pdv/sales/${ids.pdvSale}/receipt-preview');if(!response.ok)throw Error('Receipt preview failed');const template=document.createElement('template');template.innerHTML=await response.text();const dialog=template.content.querySelector('#pdvHistoryReceiptModal');if(!dialog)throw Error('Missing receipt dialog');document.body.appendChild(dialog);window.LumeronPdvReceipt.openHistory(dialog);return true;})()`]);
   if(name==='nfe-create')for(const id of ['nfeReferenceDialog','nfeDuplicateDialog'])modalActions.push([id,`document.querySelector('[data-nfe-modal-open="${id}"]')?.click();true`]);
   if(!quick&&!baselineForms&&(width===1366||width===390))for(const [modal,action]of modalActions){
    await evaluate("document.querySelectorAll('dialog[open]').forEach(d=>d.close());document.querySelectorAll('[data-nfe-modal].is-open:not(#nfeEditorDialog)').forEach(d=>d.querySelector('[data-nfe-modal-close]')?.click());");
    const actionResult=await cdp('Runtime.evaluate',{expression:action,returnByValue:true,awaitPromise:true});if(actionResult.exceptionDetails)throw Error(actionResult.exceptionDetails.text);await new Promise(r=>setTimeout(r,180));await measure(name,route,kind,width,'modal-'+modal);
    if(name==='nfe-create'&&modal==='item'){await evaluate("document.querySelectorAll('#nfeItemDialog details').forEach(d=>d.open=true)");await measure(name,route,kind,width,'modal-item-expanded');}
   }
  }catch(error){results.push({name,route,kind,width,error:String(error)});console.log(name,width,String(error));}
  save();
 }
}
sourceEnd={css:Object.fromEntries(fs.readdirSync(path.join(sourceRoot,'public/css')).filter(f=>f.endsWith('.css')).map(f=>[f,createHash('sha256').update(fs.readFileSync(path.join(sourceRoot,'public/css',f))).digest('hex')]))};
save();ws.close();
