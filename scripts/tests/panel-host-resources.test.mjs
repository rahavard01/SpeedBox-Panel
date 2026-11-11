import assert from 'node:assert/strict';
import vm from 'node:vm';
import {readFileSync} from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'../..');
const code=readFileSync(path.join(root,'public/speedbox/infrastructure.js'),'utf8');
const grid={innerHTML:''},stamp={textContent:''},listeners=new Map(),scheduled=new Map();let next=0,calls=0;
const resources={cpu:32.5,memory:{used:750,total:1000,percent:75},disk:{used:950,total:1000,percent:95},sampled_at:1000};
const context=vm.createContext({AbortController,Date,Number,console,clearTimeout:id=>scheduled.delete(id),timers:new Set(),disposed:false,
  panelResourceTimer:null,panelResourceVersion:0,panelResourceRequest:null,panelResourceWarmup:0,
  state:{view:'overview',overview:{resources}},document:{hidden:false,addEventListener:(name,fn)=>listeners.set(name,fn)},
  content:{querySelector:selector=>selector.includes('grid')?grid:stamp},
  later:(fn,delay)=>{const id=++next;scheduled.set(id,{fn,delay});return id;},
  escape:s=>String(s).replace(/</g,'&lt;'),faDigits:s=>String(s),bytes:s=>s+' B',
  api:async(url)=>{calls++;assert.equal(url,'/overview?resources_only=1');return {data:{resources}};}});
vm.runInContext(code.slice(code.indexOf('  function renderPanelResources('),code.indexOf('  function rankCard('))+';globalThis.test={renderPanelResources,schedulePanelResources,loadPanelResources,cancelPanelResources};',context);
const t=context.test;t.renderPanelResources(resources);
assert.equal((grid.innerHTML.match(/role="meter"/g)||[]).length,3);
assert.ok(grid.innerHTML.includes('panel-resource-ring medium')&&grid.innerHTML.includes('panel-resource-ring high'));
assert.ok(stamp.textContent.includes('آخرین دریافت'));
t.renderPanelResources({cpu:null});assert.equal((grid.innerHTML.match(/aria-valuenow/g)||[]).length,0,'unknown values are never shown as zero');
t.renderPanelResources(resources,true);assert.ok(stamp.textContent.includes('به‌روزرسانی نشد'));
t.schedulePanelResources();assert.equal([...scheduled.values()].at(-1).delay,30000);
context.state.overview.resources={cpu:null};
for(let i=0;i<3;i++)t.schedulePanelResources();
assert.equal([...scheduled.values()].at(-1).delay,30000,'unsupported CPU cannot trigger fast polling forever');
context.document.hidden=true;listeners.get('visibilitychange')();assert.equal(scheduled.size,0);
await t.loadPanelResources();assert.equal(calls,0,'hidden dashboard sends no polling requests');
context.document.hidden=false;context.state.view='servers';await t.loadPanelResources();assert.equal(calls,0,'other views do not poll');
context.state.view='overview';await t.loadPanelResources();assert.equal(calls,1);
t.cancelPanelResources();assert.equal(scheduled.size,0);
const overview=code.slice(code.indexOf('  function renderOverview('),code.indexOf('  async function loadOverview('));
assert.ok(overview.indexOf('panel-host-resources')<overview.indexOf('stats-grid'),'resources appear above stat cards');
console.log('PANEL RESOURCE UI: rings, thresholds, unknown values, lightweight polling and lifecycle passed');
