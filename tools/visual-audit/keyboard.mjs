import fs from 'node:fs';

const output=process.argv[2]||'/workspace/scratch/visual-standardization';
const tab=(await(await fetch('http://127.0.0.1:9228/json/list')).json()).find(t=>t.type==='page');
const ws=new WebSocket(tab.webSocketDebuggerUrl);await new Promise(r=>ws.onopen=r);
let id=0;const pending=new Map();let events=[];
ws.onmessage=e=>{const d=JSON.parse(e.data);if(d.id){const p=pending.get(d.id);pending.delete(d.id);d.error?p.reject(d.error):p.resolve(d.result);}else events.push(d);};
const call=(method,params={})=>new Promise((resolve,reject)=>{pending.set(++id,{resolve,reject});ws.send(JSON.stringify({id,method,params}));});
const evaluate=async expression=>{const r=await call('Runtime.evaluate',{expression,returnByValue:true});if(r.exceptionDetails)throw Error(r.exceptionDetails.text);return r.result.value;};
await call('Page.enable');await call('Page.setLifecycleEventsEnabled',{enabled:true});
await call('Emulation.setDeviceMetricsOverride',{width:1366,height:768,deviceScaleFactor:1,mobile:false});
const nav=await call('Page.navigate',{url:'http://127.0.0.1:8841/products/create'});let loaded=false;
for(let i=0;i<100;i++){await new Promise(r=>setTimeout(r,70));loaded=events.some(e=>e.method==='Page.lifecycleEvent'&&e.params.name==='load'&&e.params.loaderId===nav.loaderId);if(loaded)break;}
if(!loaded)throw Error('O formulário não terminou de carregar.');
const before=await evaluate("(()=>{const i=document.querySelector('input[type=checkbox][name=is_active]'),r=i.closest('label').querySelector('.switch-track').getBoundingClientRect();return {checked:i.checked,x:r.x+r.width/2,y:r.y+r.height/2}})()");
await call('Input.dispatchMouseEvent',{type:'mousePressed',x:before.x,y:before.y,button:'left',clickCount:1});
await call('Input.dispatchMouseEvent',{type:'mouseReleased',x:before.x,y:before.y,button:'left',clickCount:1});
const afterLabel=await evaluate("document.querySelector('input[type=checkbox][name=is_active]').checked");
await evaluate("document.querySelector('input[type=checkbox][name=is_active]').focus()");
await call('Input.dispatchKeyEvent',{type:'keyDown',key:' ',code:'Space',windowsVirtualKeyCode:32,nativeVirtualKeyCode:32,text:' '});
await call('Input.dispatchKeyEvent',{type:'keyUp',key:' ',code:'Space',windowsVirtualKeyCode:32,nativeVirtualKeyCode:32});
await new Promise(resolve=>setTimeout(resolve,220));
const keyboard=await evaluate("(()=>{const i=document.querySelector('input[type=checkbox][name=is_active]'),s=getComputedStyle(i.closest('label').querySelector('.switch-track'));return {checked:i.checked,focused:document.activeElement===i,outline:s.outline,outlineWidth:s.outlineWidth,documentWidth:document.documentElement.scrollWidth}})()");
const report={before:before.checked,afterLabel,keyboard,labelToggles:before.checked!==afterLabel,spaceToggles:keyboard.checked!==afterLabel,focusVisible:parseFloat(keyboard.outlineWidth)>0,noDocumentOverflow:keyboard.documentWidth===1366,submitted:false};
fs.writeFileSync(output+'/keyboard.json',JSON.stringify(report,null,2)+'\n');console.log(JSON.stringify(report));ws.close();
if(!report.labelToggles||!report.spaceToggles||!report.focusVisible||!report.noDocumentOverflow)process.exitCode=1;
