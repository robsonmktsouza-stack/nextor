import fs from 'node:fs';

const file=process.argv[2];if(!file)throw Error('Informe o arquivo da matriz de navegação.');
const matrix=JSON.parse(fs.readFileSync(file,'utf8'));
const controlHeight=matrix.density==='comfortable'?41:38;
const problems=[],unknown=new Map(),roles=new Map();let examined=0;
for(const row of matrix.results){
 const location={screen:row.name,state:row.state||'default',width:row.width};
 for(const key of ['error','overflow','clipped','compositeClipped','jsErrors'])if(row[key]&&(Array.isArray(row[key])?row[key].length:true))problems.push({...location,check:key,actual:row[key]});
 if(row.http?.at(-1)?.status!==200&&!row.error)problems.push({...location,check:'http',actual:row.http});
 for(const component of row.components||[]){
  examined++;let role=component.role;
  // These existing classes define compact actions even inside page headers.
  if(component.class?.split(' ').includes('grid-primary-action'))role='toolbar-primary-action';
  if(component.class?.split(' ').includes('btn-sm'))role='compact-action';
  if(/\bfinance-action-(icon|split|main)\b/.test(component.class))role='toolbar-secondary-action';
  if(component.class?.split(' ').includes('finance-action-caret'))role='split-segment-action';
  if(component.class?.split(' ').includes('fiscal-purchases-switch-button'))role='toggle-action';
  if(component.class?.split(' ').includes('pdv-receipt-action'))role='responsive-page-action';
  roles.set(role,(roles.get(role)||0)+1);
  if(role.includes('unclassified')){const key=JSON.stringify([component.class,component.id,component.text]);if(!unknown.has(key))unknown.set(key,{...location,class:component.class,id:component.id,text:component.text});continue;}
  const expected={};
  if(['field-input','field-select','field-composite','primary-action','secondary-action','page-primary-action','page-secondary-action'].includes(role)){expected.height=controlHeight;expected.radius='4px';if(role!=='field-composite')expected.fontSize='13.5px';}
  if(['toolbar-primary-action','toolbar-secondary-action'].includes(role)){expected.height=34;expected.radius='4px';expected.fontSize='13.5px';}
  if(role==='compact-action'){expected.height=34;expected.radius='4px';expected.fontSize='13.5px';}
  if(role==='responsive-page-action'){expected.radius='4px';expected.fontSize='13.5px';if(component.height<controlHeight)problems.push({...location,check:'component-min-height',role,expected:controlHeight,actual:component.height});}
  if(['icon-action','toolbar-icon-action'].includes(role)){expected.height=32;expected.radius='4px';}
  if(role==='modal')expected.radius='4px';
  if(role==='modal-title')expected.fontSize='15px';
  if(role==='select-popup'||role==='utilities-popup')expected.radius='4px';
  if(role==='table-header')expected.fontSize='12px';
  if(role==='table-cell')expected.fontSize='13px';
  if(role==='pdv-document-entry'){expected.height=42;expected.fontSize='18px';expected.radius='4px';}
  if(role==='pdv-amount-entry'){expected.height=56;expected.fontSize='24px';expected.radius='4px';}
  if(role==='toggle-track'){expected.width=34;expected.height=18;}
  for(const [key,value]of Object.entries(expected))if(component[key]!==value)problems.push({...location,check:'component-'+key,role,selector:component.id?'#'+component.id:component.name?'[name="'+component.name+'"]':component.class,expected:value,actual:component[key]});
 }
}
const fingerprintsStable=matrix.source_end?JSON.stringify(matrix.source_state.css)===JSON.stringify(matrix.source_end.css):false;
const report={phase:matrix.phase,states:matrix.results.length,routes:matrix.routes,widths:matrix.widths,components:examined,roles:Object.fromEntries([...roles].sort()),fingerprintsStable,unknown:[...unknown.values()],problems};
console.log(JSON.stringify(report,null,2));
if(problems.length||unknown.size)process.exitCode=1;
