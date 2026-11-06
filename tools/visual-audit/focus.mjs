import fs from 'node:fs';

const output=process.argv[2]||'/workspace/scratch/visual-standardization';
const tab=(await(await fetch('http://127.0.0.1:9228/json/list')).json()).find(t=>t.type==='page');
const ws=new WebSocket(tab.webSocketDebuggerUrl);await new Promise(r=>ws.onopen=r);
let id=0;const pending=new Map();let events=[];
ws.onmessage=e=>{const d=JSON.parse(e.data);if(d.id){const p=pending.get(d.id);pending.delete(d.id);d.error?p.reject(d.error):p.resolve(d.result);}else events.push(d);};
const call=(method,params={})=>new Promise((resolve,reject)=>{pending.set(++id,{resolve,reject});ws.send(JSON.stringify({id,method,params}));});
const evaluate=async expression=>{const r=await call('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});if(r.exceptionDetails)throw Error(r.exceptionDetails.text);return r.result.value;};
await call('Page.enable');await call('Runtime.enable');await call('Page.setLifecycleEventsEnabled',{enabled:true});
await call('Emulation.setDeviceMetricsOverride',{width:1366,height:768,deviceScaleFactor:1,mobile:false});
const specs=[
 ['product-plain','/products/create','input[name=name]'],
 ['product-prefix','/products/create','[data-cost-price]'],
 ['product-numeric','/products/create','input[name=stock_quantity]'],
 ['customer-action','/customers/create','input[name=document]'],
 ['service-plain','/services/create','input[name=name]'],
 ['sale-icon','/sales/create','input[name=keyword]'],
 ['return-icon','/sales/returns/create','input[name=sale]'],
 ['nfe-numeric','/fiscal/nfe/create','input[name=discount]'],
 ['login-plain','/login','input[name=username]'],
 ['login-password','/login','input[name=password]'],
 ['reset-plain','/reset-password/visual-fixture-token?email=visual@example.test','input[name=password]'],
];
const results=[];
for(const [name,route,selector] of specs){
 try{
  events=[];const nav=await call('Page.navigate',{url:'http://127.0.0.1:8841'+route});let loaded=false;
  for(let i=0;i<100;i++){await new Promise(r=>setTimeout(r,70));loaded=events.some(e=>e.method==='Page.lifecycleEvent'&&e.params.name==='load'&&e.params.loaderId===nav.loaderId);if(loaded)break;}
  if(!loaded)throw Error('The requested document did not finish loading.');
  await new Promise(r=>setTimeout(r,150));
  const result=await evaluate(`(${async function(selector){
   const target=document.querySelector(selector);if(!target)throw Error('Missing focus target '+selector);
   const surface=target.closest('.numeric-input,.input-prefix,.input-icon-group,.input-action-group,.login-password-field,.login-subdomain-field')||target;
   const input=[...surface.querySelectorAll('input')].find(i=>i.type!=='hidden'&&i.getBoundingClientRect().width>5)||target;
   const snapshot=()=>{const s=getComputedStyle(surface),i=getComputedStyle(input),r=surface.getBoundingClientRect();return {height:r.height,radius:s.borderTopLeftRadius,border:s.border,borderColor:s.borderColor,shadow:s.boxShadow,background:s.backgroundColor,inputBackground:i.backgroundColor,color:i.color,fontSize:i.fontSize,font:i.fontFamily,padding:i.padding,lineHeight:i.lineHeight,focused:document.activeElement===input};};
   const settle=()=>new Promise(resolve=>setTimeout(resolve,220));
   document.activeElement?.blur();await settle();const normal=snapshot();input.focus();await settle();const focus=snapshot();input.blur();
   input.readOnly=true;await settle();const readonly=snapshot();input.readOnly=false;input.disabled=true;await settle();const disabled=snapshot();input.disabled=false;
   return {normal,focus,readonly,disabled};
  }.toString()})(${JSON.stringify(selector)})`);
  results.push({name,route,selector,...result});
 }catch(error){results.push({name,route,selector,error:String(error)});}
}
fs.writeFileSync(output+'/focus.json',JSON.stringify({submitted:false,results},null,2)+'\n');
for(const r of results)console.log(r.name,r.error||JSON.stringify({height:r.focus.height,radius:r.focus.radius,border:r.focus.border,shadow:r.focus.shadow,font:r.focus.fontSize}));
ws.close();
