import fs from 'node:fs';

const output=process.argv[2]||'/workspace/scratch/visual-standardization';
const tab=(await(await fetch('http://127.0.0.1:9228/json/list')).json()).find(t=>t.type==='page');
const ws=new WebSocket(tab.webSocketDebuggerUrl);await new Promise(r=>ws.onopen=r);
let id=0;const pending=new Map();let events=[];
ws.onmessage=e=>{const d=JSON.parse(e.data);if(d.id){const p=pending.get(d.id);pending.delete(d.id);d.error?p.reject(d.error):p.resolve(d.result);}else events.push(d);};
const call=(method,params={})=>new Promise((resolve,reject)=>{pending.set(++id,{resolve,reject});ws.send(JSON.stringify({id,method,params}));});
await call('Page.enable');await call('DOM.enable');await call('CSS.enable');await call('Page.setLifecycleEventsEnabled',{enabled:true});
const results=[];
for(const [route,selectors]of [['/products/1/edit',['body','input[name=name]']],['/fiscal/nfe/create',['body','#nfeEditorTitle']]]){
 events=[];const nav=await call('Page.navigate',{url:'http://127.0.0.1:8841'+route});let loaded=false;
 for(let i=0;i<100;i++){await new Promise(r=>setTimeout(r,70));loaded=events.some(e=>e.method==='Page.lifecycleEvent'&&e.params.name==='load'&&e.params.loaderId===nav.loaderId);if(loaded)break;}
 if(!loaded)throw Error('A página não terminou de carregar.');
 const root=await call('DOM.getDocument',{depth:-1,pierce:true});
 for(const selector of selectors){
  const {nodeId}=await call('DOM.querySelector',{nodeId:root.root.nodeId,selector});
  const primary=await call('CSS.getPlatformFontsForNode',{nodeId});
  const record={route,selector,fonts:primary.fonts};
  if(!primary.fonts.length){
   const described=await call('DOM.describeNode',{nodeId,depth:-1,pierce:true});const nodes=[];
   const visit=n=>{if(n.nodeType===3&&n.nodeValue?.trim())nodes.push(n.backendNodeId);for(const c of [...(n.children||[]),...(n.shadowRoots||[])])visit(c);};visit(described.node);
   const pushed=await call('DOM.pushNodesByBackendIdsToFrontend',{backendNodeIds:nodes});
   record.shadowTextFonts=[];
   for(const childId of pushed.nodeIds||[])if(childId)record.shadowTextFonts.push(...(await call('CSS.getPlatformFontsForNode',{nodeId:childId})).fonts);
  }
  results.push(record);
 }
}
for(const record of results)for(const key of ['fonts','shadowTextFonts'])if(record[key]){const grouped=new Map();for(const font of record[key]){const id=JSON.stringify([font.familyName,font.postScriptName,font.isCustomFont]);const existing=grouped.get(id);if(existing)existing.glyphCount+=font.glyphCount;else grouped.set(id,{...font});}record[key]=[...grouped.values()];}
fs.writeFileSync(output+'/fonts.json',JSON.stringify({method:'CSS.getPlatformFontsForNode; input inclui texto da árvore interna quando necessário',remoteFontsBlocked:true,results},null,2)+'\n');console.log(JSON.stringify(results));ws.close();
