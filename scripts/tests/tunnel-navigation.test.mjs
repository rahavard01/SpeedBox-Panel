import assert from 'node:assert/strict';
import {createServer} from 'node:http';
import {readFileSync,existsSync,mkdirSync} from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
const {chromium}=await import(process.env.SPEEDBOX_TEST_PLAYWRIGHT||'playwright');
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'../..');
const manifest=JSON.parse(readFileSync(path.join(root,'public/build/manifest.json')));
const css=(manifest['resources/js/app.js'].css||[]).map(f=>`<link rel="stylesheet" href="/build/${f}">`).join('');
const template=readFileSync(path.join(root,'resources/views/infrastructure.blade.php'),'utf8').replace(/^[\s\S]*?<template[^>]*>/,'').replace(/<\/template>[\s\S]*$/,'');
const html=`<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">${css}<link rel="stylesheet" href="/speedbox/panel-controls.css"><link rel="stylesheet" href="/speedbox/branding.css"><link rel="stylesheet" href="/speedbox/infrastructure.css"><link rel="stylesheet" href="/speedbox/network-tunnels.css"><link rel="stylesheet" href="/speedbox/tunnels.css"><style>body{margin:0;background:#17171c;color:#eee}.center-box{margin-top:30px}</style><div id="app"><div class="center-box"><div class="speedbox-infrastructure" id="mount">${template}</div></div></div><script src="/speedbox/wattunnel.js"></script><script src="/speedbox/network-tunnels.js"></script><script src="/speedbox/tunnels.js"></script><script src="/speedbox/node-editor.js"></script><script src="/speedbox/infrastructure.js"></script></html>`;
const server=createServer((req,res)=>{const url=new URL(req.url,'http://localhost');if(url.pathname==='/'){res.setHeader('Content-Type','text/html;charset=utf-8');res.end(html);return;}const p=path.resolve(root,'public','.'+url.pathname);if(!p.startsWith(path.join(root,'public')+path.sep)||!existsSync(p)){res.writeHead(404).end();return;}res.setHeader('Content-Type',p.endsWith('.js')?'text/javascript':p.endsWith('.css')?'text/css':'application/octet-stream');res.end(readFileSync(p));});
await new Promise(r=>server.listen(0,'127.0.0.1',r));const origin=`http://127.0.0.1:${server.address().port}`;
const browser=await chromium.launch({executablePath:process.env.SPEEDBOX_TEST_CHROME||undefined,headless:true,args:['--no-sandbox','--disable-dev-shm-usage',...(process.env.SPEEDBOX_TEST_SWIFTSHADER?['--use-gl=angle','--use-angle=swiftshader','--enable-unsafe-swiftshader','--no-zygote','--single-process']:[])]});

let assertions=0;const check=(yes,message)=>{assert.ok(yes,message);assertions++;};
const errors=[];const context=await browser.newContext();context.setDefaultTimeout(30000);
async function start(page,bot='on'){
 await page.goto(origin+'/?tunnel_preview=full&tunnel_preview_bot='+bot);
 await page.bringToFront();
 await page.addStyleTag({content:'*,*::before,*::after{animation:none!important;transition:none!important;scroll-behavior:auto!important}'});
 await page.evaluate(()=>{
  window.calls=[];window.requests=[];window.messages=[];window.nodeCalls=[];window.modalEvents=[];
  localStorage.setItem('token','test');localStorage.setItem('user',JSON.stringify({role:1}));
  window.fetch=async(...args)=>{calls.push(args);throw Error('unexpected real API request');};
  const mount=SpeedboxNetworkTunnels.mount;
  SpeedboxNetworkTunnels.mount=ctx=>{
   const modal=ctx.openModal;ctx.openModal=(title,...args)=>{modalEvents.push(title);return modal(title,...args);};
   const real=ctx.api,open=ctx.openNode;ctx.openNode=(node,prefill)=>{nodeCalls.push({node,prefill});return open(node,prefill);};ctx.api=async(url,options={})=>{requests.push({url,method:options.method||'GET',payload:options.body?JSON.parse(options.body):null});const answer=await real(url,options);if(url.includes('network-tunnel-tests')&&answer.data){if(window.fixtureProtocol)for(const row of answer.data.results||[]){row.protocols.tcp.status=window.fixtureProtocol==='udp'?'down':'ok';row.protocols.udp.status=window.fixtureProtocol==='unstable'?'unstable':'ok';row.selectable=true;}if(window.fixturePending){answer.operation.status='running';answer.operation.progress=42;answer.data.status='running';} }if(url.endsWith('/cancel'))window.fixturePending=false;return answer;};
   const session=mount(ctx);window.networkSession=session;window.networkData=ctx.data;return session;
  };
  window.session=SpeedboxInfrastructure.mount(document.getElementById('mount'),{router:{push(){},replace(){}},notify:{success:m=>messages.push(m),error:m=>messages.push(m),notif:m=>messages.push(m)},view:'tunnels'});
 });
 await page.locator('[data-tunnel-card="606"]').waitFor();
}
async function choose(page,engine,a='901',b='902'){
 await page.locator('[data-new-tunnel]').click();await page.locator(`[data-engine="${engine}"]`).click();await page.locator('[data-network-test]').waitFor();
 if(a)await page.locator('[name=iran_server_id]').selectOption(a,{force:true});
 if(b)await page.locator('[name=foreign_server_id]').selectOption(b,{force:true});
}
async function runTest(page){await page.locator('[data-network-test] button[type=submit]').click();await page.locator('[data-select-network-result]:not(:disabled)').first().waitFor();}
async function selectResult(page,index=0){await page.locator('[data-select-network-result]:not(:disabled)').nth(index).click();await page.locator('[data-network-editor]').waitFor();}
async function create(page,engine,name,a='901',b='902'){await choose(page,engine,a,b);await runTest(page);await selectResult(page);await page.locator('[name=name]').fill(name);}
async function setPorts(page,values){
 const ports=String(values).replace(/[۰-۹]/g,c=>String('۰۱۲۳۴۵۶۷۸۹'.indexOf(c))).split(/[,،\s]+/).filter(Boolean);
 while(await page.locator('[data-map-row]').count()<ports.length)await page.locator('[data-add-map]').click();
 while(await page.locator('[data-map-row]').count()>ports.length)await page.locator('[data-remove-map]').last().click();
 for(let i=0;i<ports.length;i++)for(const key of ['public_port','target_port'])await page.locator(`[data-map-row="${i}"] [data-map-key=${key}]`).fill(ports[i]);
}
const save=page=>page.locator('[data-network-editor] button[type=submit]').click();
async function action(page,card,name){await card.locator('.tunnel-actions summary').click();await card.locator(`[data-tunnel-action="${name}"]`).click();}

async function liveCases(page,width){
 const fixture=await page.evaluate(()=>networkData());
 for(const engine of ['backpack','gost','gre']){
  await page.goto(origin+'/');
  await page.addStyleTag({content:'*,*::before,*::after{animation:none!important;transition:none!important}'});
  await page.evaluate(({fixture,engine})=>{
   localStorage.setItem('token','test');localStorage.setItem('user',JSON.stringify({role:1}));
   window.messages=[];window.liveReads=0;window.liveTest=null;
   const servers=fixture.servers;
   const row={id:engine==='backpack'?'normal:reverse:tcp':engine+':sample:ipv4',engine,reason:'INTERNAL_FAILURE_DETAIL',label:engine==='backpack'?'Reverse TCP':engine.toUpperCase()+' · IPv4',kind:'reverse',transport:'tcp',orientation:'normal',status:'testing',selectable:false,measurement:'round_trip_1mib',ok:7,total:engine==='backpack'?60:20,rtt:45,speed:null,download:12,upload:8,protocols:{tcp:{status:'testing',ok:7,total:20,rtt:45,download:12,upload:8}}};
   window.fetch=async(url,options={})=>{
    const pathname=new URL(url,location.origin).pathname;
    const endpoint=engine==='backpack'?'/tunnel-tests':'/network-tunnel-tests';
    let answer;
    if(pathname.endsWith('/tunnels'))answer={...fixture,tests:liveTest?[liveTest]:[]};
    else if(pathname.endsWith(endpoint)&&options.method==='POST'){
     liveTest={id:700,engine,status:'running',iran_server_id:servers[0].id,foreign_server_id:servers[1].id,results:engine==='backpack'?[]:Array.from({length:12},(_,i)=>({...row,id:row.id+'-'+i,label:row.label+' '+i,status:'queued',ok:null,total:null,rtt:null,download:null,upload:null,protocols:{}}))};
     answer={data:liveTest,operation:{id:700,status:'running',progress:5},message:'started'};
    }else if(pathname.endsWith(endpoint+'/700')){
     liveReads++;
     if(liveReads===1)throw Error('temporary poll interruption');
     liveTest.results=engine==='backpack'?Array.from({length:16},(_,i)=>({...row,id:row.id+'-'+i,label:row.label+' '+i})):[row,...liveTest.results.slice(1)];
     answer={data:liveTest,operation:{id:700,status:'running',progress:32}};
    }else throw Error('unexpected fixture endpoint '+pathname);
    return {ok:true,status:200,json:async()=>structuredClone(answer)};
   };
   window.session=SpeedboxInfrastructure.mount(document.getElementById('mount'),{router:{push(){},replace(){}},notify:{success:m=>messages.push(m),error:m=>messages.push(m),notif:m=>messages.push(m)},view:'tunnels'});
  },{fixture,engine});
  await page.locator('[data-new-tunnel]').waitFor();await page.locator('[data-new-tunnel]').click();await page.locator(`[data-engine="${engine}"]`).click();
  const form=engine==='backpack'?'[data-test-form]':'[data-network-test]';
  await page.locator(form+' [name=iran_server_id]').selectOption(String(fixture.servers[0].id),{force:true});
  await page.locator(form+' [name=foreign_server_id]').selectOption(String(fixture.servers[1].id),{force:true});
  await page.locator(form+' button[type=submit]').click();
  await page.locator('.tunnel-result-row').first().waitFor();
  check(await page.locator('.tunnel-result-row').count()===(engine==='backpack'?16:12),engine+' candidate cards exist before any sample');
  check((await page.locator('.tunnel-result-row').first().textContent()).includes('—'),engine+' unmeasured values are unknown');
  const positions=await page.evaluate(()=>{
   const elements=[document.querySelector('#modalBackdrop'),document.querySelector('.infrastructure-modal'),document.querySelector('#modalBody'),document.querySelector('#modalBody .modal-fields')];
   for(const e of elements)if(e.scrollHeight>e.clientHeight+100)e.scrollTop=180;
   const disclosure=document.querySelector('.network-protocol-details');if(disclosure)disclosure.open=true;
   return elements.map(e=>e.scrollTop);
  });
  check(positions.some(n=>n>0),engine+' live results are scrollable');
  await page.evaluate(()=>new Promise(r=>requestAnimationFrame(()=>requestAnimationFrame(r))));
  const settled=await page.evaluate(()=>[document.querySelector('#modalBackdrop'),document.querySelector('.infrastructure-modal'),document.querySelector('#modalBody'),document.querySelector('#modalBody .modal-fields')].map(e=>e.scrollTop));
  check(settled.every((n,i)=>Math.abs(n-positions[i])<=1),engine+' never replays an old scroll position over a fresh user scroll');
  const notifications=await page.evaluate(()=>messages.length);
  await page.waitForFunction(()=>liveReads>=2);
  await page.waitForFunction(()=>[...document.querySelectorAll('.tunnel-result-metrics dd')].some(d=>d.textContent.includes('7 /')));
  check(await page.locator('.tunnel-result-row').first().locator('.tunnel-result-metrics').textContent().then(t=>t.includes('7 /')),engine+' displays samples while still running');
  check(await page.locator('.tunnel-result-row button:not(:disabled)').count()===0,engine+' cannot construct before cleanup');
  const updatedPositions=await page.evaluate(()=>[document.querySelector('#modalBackdrop'),document.querySelector('.infrastructure-modal'),document.querySelector('#modalBody'),document.querySelector('#modalBody .modal-fields')].map(e=>e.scrollTop));
  check(updatedPositions.every((n,i)=>Math.abs(n-positions[i])<=1),engine+' live redraw keeps modal position '+JSON.stringify({positions,updatedPositions}));
  check(!(await page.locator('#modalBody').textContent()).includes('INTERNAL_FAILURE_DETAIL'),engine+' hides per-result failure details');
  if(engine!=='backpack')check(await page.locator('.network-protocol-details').first().evaluate(e=>e.open),engine+' protocol details stay open while polling');
  check(await page.evaluate(()=>messages.length)===notifications,engine+' poll interruption retries without a toast');
  if(engine==='backpack')check(await page.locator('.tunnel-result-metrics dt').allTextContents().then(t=>t.includes('سرعت')&&!t.includes('دریافت')), 'BackPack shows one measured speed');
  if(process.env.SPEEDBOX_TEST_SCREENSHOT_DIR){mkdirSync(process.env.SPEEDBOX_TEST_SCREENSHOT_DIR,{recursive:true});await page.screenshot({path:path.join(process.env.SPEEDBOX_TEST_SCREENSHOT_DIR,'live-'+engine+'-'+width+'.png'),fullPage:true});}
  await page.evaluate(()=>session.dispose());
 }
}

async function watCases(page,width){
 await page.evaluate(()=>fixturePending=false);
 await choose(page,'wattunnel');
 check(await page.locator('[name=direction]').count()===1,'WatTunnel owns direction controls');
 await runTest(page);
 const recommended=page.locator('.is-recommended>button').first();
 const color=await recommended.evaluate(e=>({background:getComputedStyle(e).backgroundColor,color:getComputedStyle(e).color,kind:e.className,card:getComputedStyle(e.parentElement).backgroundImage}));
 const other=await page.locator('.tunnel-result-row:not(.is-recommended)>button').first().evaluate(e=>({background:getComputedStyle(e).backgroundColor,color:getComputedStyle(e).color}));
 check(color.kind.includes('secondary')&&color.background===other.background&&color.color===other.color,'recommended action matches all other results with light text');
 check(color.card.includes('31, 32, 36'),'results use charcoal surfaces');
 check(await page.locator('.tunnel-row-note').count()===0,'result cards do not show failure reasons');
 if(process.env.SPEEDBOX_TEST_SCREENSHOT_DIR)await page.screenshot({path:path.join(process.env.SPEEDBOX_TEST_SCREENSHOT_DIR,'wat-results-'+width+'.png'),fullPage:true});
 await page.locator('[data-select-network-result]').nth(1).click();await page.locator('[data-wat-editor]').waitFor();
 check(await page.locator('[name=nat],[name=gre_key],[name=quota_gb]').count()===0,'WatTunnel form excludes GRE/GOST controls');
 check(await page.locator('[name=tunnel_port]').count()===1,'UDP carrier has its own tunnel port');
 check(await page.locator('[data-port=public_end],[data-port=target_end]').count()===0,'Wat form has no redundant range-end fields');
 check(await page.locator('[data-wat-map]').count()===1&&await page.locator('[data-wat-add]').count()===1,'Wat paired port rows and add action are visible immediately');
 check(await page.locator('[data-wat-custom],[name=wat_ports]').count()===0,'Wat has no extra port-mode step');
 check((await page.locator('.tunnel-selected-copy b').textContent())==='WatTunnel · Direct · UDP · IPv4','Wat selection uses the common method pattern');
 await page.locator('[data-wat-map="0"] [data-port=public]').fill('۲۴۴۳،۲۴۴۴');
 await page.locator('[data-wat-map="0"] [data-port=target]').fill('۹۴۴۳،۹۴۴۴');
 await page.locator('[data-wat-add]').click();
 await page.locator('[data-wat-map="1"] [data-port=public]').fill('۸۴۴۳');
 await page.locator('[data-wat-map="1"] [data-port=target]').fill('۸۴۴۳');
 await page.locator('[name=name]').fill('Wat browser fixture');
 await page.locator('[name=protocol]').selectOption('both',{force:true});
 await page.locator('[data-wat-advanced]').evaluate(e=>e.open=true);
 check(await page.locator('[name=conns]').count()===0,'UDP carrier hides pool-only options');
 await page.locator('[name=mtu]').fill('1400');
 const scrollBefore=await page.evaluate(()=>{
  const fields=document.querySelector('#modalBody .modal-fields');
  const els=[document.querySelector('#modalBackdrop'),document.querySelector('.tunnel-modal'),document.querySelector('#modalBody'),fields];
  for(const el of els)if(el.scrollHeight>el.clientHeight+100)el.scrollTop=120;
  document.querySelector('[name=monitor]').focus({preventScroll:true});
  return els.map(e=>e.scrollTop);
 });
 await page.locator('[name=monitor]').evaluate(e=>{e.checked=false;e.dispatchEvent(new Event('change',{bubbles:true}));});
 await page.evaluate(()=>new Promise(requestAnimationFrame));
 const scrollAfter=await page.evaluate(()=>[document.querySelector('#modalBackdrop'),document.querySelector('.tunnel-modal'),document.querySelector('#modalBody'),document.querySelector('#modalBody .modal-fields')].map(e=>e.scrollTop));
 check(scrollBefore.some(n=>n>0)&&scrollAfter.every((n,i)=>Math.abs(n-scrollBefore[i])<=1),'Wat setting redraw preserves modal scroll');
 check(await page.locator('[data-wat-advanced]').evaluate(e=>e.open),'advanced settings stay open after redraw');
 await page.locator('[data-wat-add]').click();
 check(await page.locator('[data-wat-map]').count()===3,'Wat comma groups support adding ports');
 const removeColor=await page.locator('[data-wat-remove]').last().evaluate(e=>getComputedStyle(e).backgroundColor);
 check(removeColor==='rgba(255, 0, 0, 0.4)','port removal uses the shared panel danger style');
 await page.locator('[data-wat-remove]').last().click();
 check(await page.locator('[data-wat-map]').count()===2,'Wat comma groups support removal');
 if(process.env.SPEEDBOX_TEST_SCREENSHOT_DIR)await page.screenshot({path:path.join(process.env.SPEEDBOX_TEST_SCREENSHOT_DIR,'wat-form-debug-'+width+'.png'),fullPage:true});
 const dimensions=await page.locator('.tunnel-modal').evaluate(e=>({width:e.clientWidth,scroll:e.scrollWidth,offenders:[...e.querySelectorAll('*')].filter(x=>x.getBoundingClientRect().right>e.getBoundingClientRect().right+1||x.getBoundingClientRect().left<e.getBoundingClientRect().left-1).slice(0,15).map(x=>({tag:x.tagName,name:x.getAttribute('name'),class:x.className,width:x.getBoundingClientRect().width,left:x.getBoundingClientRect().left}))}));
 check(dimensions.scroll<=dimensions.width+1,'Wat form fits viewport: '+JSON.stringify(dimensions));
 if(process.env.SPEEDBOX_TEST_SCREENSHOT_DIR)await page.screenshot({path:path.join(process.env.SPEEDBOX_TEST_SCREENSHOT_DIR,'wat-form-'+width+'.png'),fullPage:true});
 await page.locator('[data-wat-map="1"] [data-port=public]').fill('8443, 8444');
 const postsBefore=await page.evaluate(()=>requests.filter(r=>r.url==='/network-tunnels'&&r.method==='POST').length);
 await page.locator('[data-wat-editor] button[type=submit]').click();
 await page.waitForFunction(()=>messages.at(-1)?.includes('تعداد پورت‌های ایران و خارج'));
 check(await page.evaluate(()=>requests.filter(r=>r.url==='/network-tunnels'&&r.method==='POST').length)===postsBefore,'mismatched paired lists never reach the create API');
 await page.locator('[data-wat-map="1"] [data-port=public]').fill('8443');
 await page.locator('[data-wat-editor] button[type=submit]').click();
 const card=page.locator('[data-tunnel-card]').filter({has:page.getByRole('heading',{name:'Wat browser fixture',exact:true})});await card.waitFor();
 const created=await page.evaluate(()=>requests.filter(r=>r.url==='/network-tunnels'&&r.method==='POST').at(-1).payload);
 check(created.mappings.length===2&&created.mappings[0].public_port===2443&&created.mappings[0].public_end===2444&&created.mappings[0].target_port===9443&&created.mappings[0].target_end===9444&&created.mappings[1].public_port===8443,'Wat Persian comma lists retain distinct ports and compress ranges');
 check(created.engine==='wattunnel'&&created.transport==='udp'&&created.protocol==='both'&&!('gre_key'in created),'Wat create posts its own validated schema');
 await action(page,card,'edit');await page.locator('[data-wat-editor]').waitFor();
 check(await page.locator('[data-wat-map="0"] [data-port=target]').inputValue()==='9443, 9444','Wat edit preserves an existing unequal target range');
 await page.locator('[name=transport]').selectOption('tls',{force:true});await page.locator('[name=reverse]').selectOption('true',{force:true});
 await page.locator('[data-wat-advanced]').evaluate(e=>e.open=true);
 check(await page.locator('[name=conns],[name=sni],[name=tls_pin]').count()===3,'TLS form exposes pool and certificate settings');
 check(await page.locator('[name=sessions]').count()===0,'reverse form never offers unsupported parallel sessions');
 await page.locator('[data-wat-editor] button[type=submit]').click();await card.waitFor();
 await card.locator('[data-tunnel-detail]').click();await page.locator('.tunnel-details').waitFor();
 check((await page.locator('.tunnel-details').textContent()).includes('WatTunnel'),'WatTunnel owns details');await page.keyboard.press('Escape');
 await card.locator('.tunnel-actions summary').click();
 check(await card.locator('[data-tunnel-action=optimize],[data-tunnel-action=reset-quota],[data-tunnel-action=diagnose]').count()===0,'WatTunnel exposes only its supported operations');
 await card.locator('[data-tunnel-action=stop]').click();await card.waitFor();
 check((await card.textContent()).includes('متوقف'),'WatTunnel stop updates its card');
 await action(page,card,'delete');
 const deletion=await page.locator('[data-confirm-network]').evaluate(e=>({kind:e.className,color:getComputedStyle(e).backgroundColor}));
 const cancellation=await page.locator('.tunnel-modal [data-close]').evaluate(e=>getComputedStyle(e).backgroundColor);
 check(deletion.kind.includes('server-delete-confirm')&&deletion.color!==cancellation,'network delete confirmation uses the same distinct shared style as server and plan deletion');
 await page.locator('[data-confirm-network]').click();await card.waitFor({state:'detached'});
}

async function summaryCases(page){
 for(const [id,form,expected] of [
  [601,'[data-tunnel-setup]','BackPack · Reverse · WSS'],
  [605,'[data-network-editor]','GOST 3 · Direct · TCP · IPv4'],
  [606,'[data-network-editor]','GRE · Direct · GRE · IPv4']
 ]){
  await action(page,page.locator(`[data-tunnel-card="${id}"]`),'edit');await page.locator(form).waitFor();
  check(await page.locator('.tunnel-selected-copy b').textContent()===expected,'shared selected-method heading '+expected);
  check(await page.locator('.tunnel-selected-copy>span').textContent().then(t=>t.includes(' / ')),'every editor shows its server pair below the method');
  await page.keyboard.press('Escape');
 }
 const historyTitles=await page.locator('.tunnel-history-row>span:first-child').allTextContents();
 check(historyTitles.length>0&&historyTitles.every(t=>/^(BackPack|WatTunnel|GOST|GRE) — /.test(t)),'all history rows include the engine, including legacy BackPack: '+JSON.stringify(historyTitles));
}

try {
 for(const width of [390,1280]){
  const page=await context.newPage();await page.setViewportSize({width,height:900});await start(page);
  const aligned=await page.locator('.network-filters').evaluate(e=>[...e.children].every(b=>['flex','inline-flex'].includes(getComputedStyle(b).display)&&getComputedStyle(b).alignItems==='center'&&getComputedStyle(b).justifyContent==='center'));
  check(aligned,'all engine labels share horizontal and vertical centering');
  const bp=page.locator('[data-tunnel-card="601"]');await action(page,bp,'delete');
  const bpStyle=await page.locator('[data-confirm-tunnel-delete]').evaluate(e=>getComputedStyle(e).backgroundColor);
  const cancelStyle=await page.locator('.tunnel-modal [data-close]').evaluate(e=>getComputedStyle(e).backgroundColor);
  check(bpStyle!==cancelStyle,'BackPack delete and cancel stay distinct');await page.keyboard.press('Escape');
  for(let cycle=0;cycle<3;cycle++){
   await page.locator('[data-new-tunnel]').click();
   check(await page.locator('#modalBackdrop').evaluate(e=>!e.hidden),'create opens after navigation');
   check(await page.locator('[data-engine]').count()===4,'create shows engine choices, not a forced resumed test');
   await page.keyboard.press('Escape');
   await page.locator('[data-reload-tunnels]').click();
   await page.locator('[data-reload-tunnels].is-refreshed').waitFor();
   check(true,'refresh calls the current session');
   await page.evaluate(()=>{window.fetch=async()=>({ok:true,status:200,json:async()=>({data:[]})});session.showView('servers');});
   await page.locator('#addServer').waitFor();await page.evaluate(()=>session.showView('tunnels'));
   await page.locator('[data-tunnel-card="606"]').waitFor();
  }
  await page.evaluate(()=>new Promise(r=>requestAnimationFrame(()=>requestAnimationFrame(r))));
  const pageTop=await page.evaluate(()=>{window.scrollTo(0,180);return window.scrollY;});
  await page.locator('[data-reload-tunnels]').evaluate(e=>e.click());
  await page.locator('[data-reload-tunnels].is-refreshed').waitFor();
  await page.evaluate(()=>new Promise(r=>requestAnimationFrame(()=>requestAnimationFrame(r))));
  check(await page.evaluate(top=>Math.abs(window.scrollY-top)<=1,pageTop),'card refresh retains page scroll when content remains '+JSON.stringify({pageTop,after:await page.evaluate(()=>window.scrollY)}));
  await summaryCases(page);
  await page.evaluate(()=>fixturePending=true);
  await choose(page,'gre');await page.locator('[data-network-test] button[type=submit]').click();
  await page.locator('.network-test-results .deployment-progress .progress-value').waitFor();
  check(await page.locator('.network-test-results [role=progressbar]').count()===1,'test uses existing deployment meter with percentage');
  check(await page.locator('.network-test-results .progress-value').textContent()==='42%','percentage reflects the actual operation value');
  if(process.env.SPEEDBOX_TEST_SCREENSHOT_DIR){mkdirSync(process.env.SPEEDBOX_TEST_SCREENSHOT_DIR,{recursive:true});await page.screenshot({path:path.join(process.env.SPEEDBOX_TEST_SCREENSHOT_DIR,'tunnel-progress-'+width+'.png'),fullPage:true});}
  check(await page.locator('.network-test-results .log-box').count()===0,'no extra operations log underneath tunnel tests');
  await page.keyboard.press('Escape');
  check(await page.locator('[data-history]').count()>=3,'closed test remains visible in the recent-test list');
  await page.locator('[data-new-tunnel]').click();
  check(await page.locator('[data-engine]').count()===4,'new create does not force an existing test to reopen');
  await page.keyboard.press('Escape');await watCases(page,width);await liveCases(page,width);await page.evaluate(()=>session.dispose());await page.close();
 }
 console.log('PASS:',assertions,'navigation and progress assertions on mobile and desktop');
} finally {await browser.close();await new Promise(r=>server.close(r));}
