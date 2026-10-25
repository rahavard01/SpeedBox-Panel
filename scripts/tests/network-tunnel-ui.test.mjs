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
const html=`<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">${css}<link rel="stylesheet" href="/speedbox/panel-controls.css"><link rel="stylesheet" href="/speedbox/branding.css"><link rel="stylesheet" href="/speedbox/infrastructure.css"><link rel="stylesheet" href="/speedbox/tunnels.css"><style>body{margin:0;background:#17171c;color:#eee}.center-box{margin-top:30px}</style><div id="app"><div class="center-box"><div class="speedbox-infrastructure" id="mount">${template}</div></div></div><link rel="stylesheet" href="/speedbox/network-tunnels.css"><script src="/speedbox/network-tunnels.js"></script><script src="/speedbox/tunnels.js"></script><script src="/speedbox/certificate-dns.js"></script><script src="/speedbox/node-editor.js"></script><script src="/speedbox/infrastructure.js"></script></html>`;
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
   const real=ctx.api,open=ctx.openNode;ctx.openNode=(node,prefill)=>{nodeCalls.push({node,prefill});return open(node,prefill);};ctx.api=async(url,options={})=>{requests.push({url,method:options.method||'GET',payload:options.body?JSON.parse(options.body):null});const answer=await real(url,options);if(url.includes('network-tunnel-tests')&&answer.data){if(window.fixtureProtocol)for(const row of answer.data.results||[]){row.protocols.tcp.status=window.fixtureProtocol==='udp'?'down':'ok';row.protocols.udp.status=window.fixtureProtocol==='unstable'?'unstable':'ok';row.selectable=true;}if(window.fixturePending){answer.operation.status='running';answer.data.status='running';} }if(url.endsWith('/cancel'))window.fixturePending=false;return answer;};
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
try{
 for(const width of [1280,768,390,360]){
  console.log('Checking tested creation flow '+width);const page=await context.newPage();await page.setViewportSize({width,height:950});page.on('pageerror',e=>errors.push(e.message));await start(page);
  check(await page.locator('.network-file-tools,[data-import-network]').count()===0,'configuration-file menu removed');
  check(await page.locator('.tunnel-toolbar>div:first-child>p').count()===0,'redundant workspace introduction removed');
  check(await page.locator('.tunnel-toolbar').evaluate(e=>getComputedStyle(e).borderBottomWidth==='0px'),'no toolbar divider at any width');
  check(await page.locator('.network-filters').evaluate(e=>e.parentElement.classList.contains('tunnel-toolbar')),'filters share the toolbar');
  if(width===1280)check(await page.locator('.tunnel-toolbar').evaluate(e=>{const nodes=[e.querySelector('[data-new-tunnel]'),e.querySelector('.network-filters'),e.querySelector('.tunnel-toolbar-actions')].map(x=>x.getBoundingClientRect());return Math.max(...nodes.map(r=>(r.top+r.bottom)/2))-Math.min(...nodes.map(r=>(r.top+r.bottom)/2))<3;}),'desktop title/filter/actions share a single row');
  check(await page.locator('.tunnel-toolbar h2,[data-tunnel-count]').count()===0,'redundant title/count removed');
  check(await page.locator('.tunnel-card').first().evaluate(e=>getComputedStyle(e).backgroundImage.includes('linear-gradient')),'cards use the approved subtle dark gradient');
  if(width>700)check(await page.locator('.tunnel-toolbar').evaluate(e=>{const t=e.getBoundingClientRect(),f=e.querySelector('.network-filters').getBoundingClientRect(),c=e.querySelector('[data-new-tunnel]').getBoundingClientRect(),r=e.querySelector('[data-reload-tunnels]').getBoundingClientRect();return Math.abs((t.left+t.right)-(f.left+f.right))<2&&c.left>f.right&&r.right<f.left;}),'desktop filter is exactly centered between right create and left refresh');
  const gostCard=page.locator('[data-tunnel-card="605"]'),greCard=page.locator('[data-tunnel-card="606"]');
  await gostCard.locator('.tunnel-actions>summary').click();await page.locator('.tunnel-preview-banner p').click();await greCard.locator('.tunnel-actions>summary').click();
  check(await page.locator('.tunnel-actions[open]').count()===1,'opening a menu leaves exactly one action menu open');
  await page.locator('.tunnel-preview-banner p').click();check(await page.locator('.tunnel-actions[open]').count()===0,'outside click closes action menus');
  await gostCard.locator('.tunnel-actions>summary').click();await page.keyboard.press('Escape');check(await page.locator('.tunnel-actions[open]').count()===0,'Escape closes action menus');
  const gostActions=await gostCard.locator('[data-tunnel-action]').evaluateAll(es=>es.map(e=>e.dataset.tunnelAction));
  const greActions=await greCard.locator('[data-tunnel-action]').evaluateAll(es=>es.map(e=>e.dataset.tunnelAction));
  check(!gostActions.includes('history')&&greActions.includes('history'),'engine action lists differ by supported tools');
  check(gostActions.includes('optimize')&&greActions.includes('engine-update'),'optimization and updates available for both engines');
  console.log('  details and menus');
  for(const [id,engine]of[['601','BackPack'],['605','GOST'],['606','GRE']]){
   console.log('   detail '+engine);
   await page.locator(`[data-tunnel-detail="${id}"]`).click();
   check(await page.locator('#modalTitle').innerText()==='جزئیات تونل '+engine,'detail title names '+engine);
   check(await page.locator('#modalBody .tunnel-details .tunnel-detail-summary').count()===1,'shared detail layout for '+engine);
   check(await page.locator('#modalBody .form-actions button').count()===1&&await page.locator('#modalBody .form-actions button').innerText()==='بستن','details have one close action for '+engine);
   check(await page.locator('#modalBody .tunnel-time-value').evaluateAll(es=>es.every(e=>{const [clock,date]=[e.querySelector('bdi:first-child'),e.querySelector('bdi:last-child')].map(x=>x.getBoundingClientRect());return clock.left>date.left;})),'time is right of date in '+engine+' details');
   check(await page.locator('#modalBody .tunnel-node-options').count()===1,'node tools appear in '+engine+' details');
   if(engine!=='BackPack')check(await page.locator('#modalBody [data-network-edit],#modalBody [data-network-logs]').count()===0,'operational controls moved out of '+engine+' details');
   await page.locator('#modalBody [data-close]').click();
  }
  console.log('  maintenance');
  await action(page,gostCard,'optimize');await page.locator('[name=side][value=foreign]').uncheck();await page.locator('[data-maintenance-form] button[type=submit]').click();
  await page.waitForFunction(()=>document.querySelector('[data-maintenance-form]')?.textContent.includes('عملیات نمایشی انجام شد'));
  let reviewedPayload=await page.evaluate(()=>requests.filter(r=>r.url==='/network-tunnels/605/actions').at(-1).payload);
  check(reviewedPayload.confirm===true&&reviewedPayload.sides.join(',')==='iran','optimization submits selected hosts through the shared form');await page.locator('#modalBody [data-close]').click();
  await action(page,greCard,'engine-update');
  check((await page.locator('[data-maintenance-form]').innerText()).includes('کرنل لینوکس را ارتقا نمی‌دهد'),'GRE update describes its own management tool');
  await page.locator('[data-maintenance-form] button[type=submit]').click();
  await page.waitForFunction(()=>document.querySelector('[data-maintenance-form] button[type=submit]')?.disabled);
  reviewedPayload=await page.evaluate(()=>requests.filter(r=>r.url==='/network-tunnels/606/actions').at(-1).payload);
  check(reviewedPayload.action==='engine-update'&&reviewedPayload.confirm===true,'GRE update uses its engine action');await page.locator('#modalBody [data-close]').click();
  await action(page,greCard,'engine-update');check(await page.locator('[data-maintenance-form] button[type=submit]').isDisabled(),'up-to-date engine cannot be updated again');await page.locator('#modalBody [data-close]').click();
  await gostCard.locator('[data-tunnel-detail]').click();check(await page.locator('[data-network-new-node]').count()===1,'node tools live inside details');await page.locator('#modalBody [data-close]').click();
  console.log('  scroll preservation');
  await page.setViewportSize({width,height:430});await action(page,gostCard,'edit');await page.locator('[data-advanced]>summary').click();await page.locator('[name=name]').fill('scroll draft');
  const scrollBefore=await page.locator('#modalBackdrop').evaluate(e=>{e.scrollTop=Math.min(220,e.scrollHeight-e.clientHeight);return e.scrollTop;});
  check(scrollBefore>30,'scroll regression uses a genuinely scrolled modal');
  await page.locator('[name=restart_enabled]').evaluate(e=>e.click());await page.waitForTimeout(80);
  check(Math.abs(await page.locator('#modalBackdrop').evaluate(e=>e.scrollTop)-scrollBefore)<3,'restart toggle preserves scroll');
  check(await page.locator('[name=name]').inputValue()==='scroll draft'&&await page.locator('[name=restart_hours]').count()===1,'restart toggle preserves draft and shows interval');
  const monitorScroll=await page.locator('#modalBackdrop').evaluate(e=>e.scrollTop);await page.locator('[name=monitor]').evaluate(e=>e.click());await page.waitForTimeout(80);
  check(Math.abs(await page.locator('#modalBackdrop').evaluate(e=>e.scrollTop)-monitorScroll)<3,'monitor toggle preserves scroll');
  check(await page.locator('[name=monitor_port]').count()===0,'TCP tunnel hides optional UDP monitoring port');
  await page.locator('#modalBody [data-close]').click();await page.setViewportSize({width,height:950});

  console.log('  creation and port mappings');
  check(await page.locator('[data-tunnel-card]').count()===6,'all engines stay in one workspace');
  check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'workspace fits '+width);
  check(await page.locator('.network-filters').evaluate(e=>e.classList.contains('segmented')&&getComputedStyle(e).gap==='0px'),'uses management-users segmented filter');
  check(await page.locator('[data-tunnel-card]').evaluateAll(cards=>cards.every(card=>{const b=card.querySelector('.network-engine-badge').getBoundingClientRect(),s=card.querySelector('.tunnel-card-state .tunnel-badge').getBoundingClientRect();return s.left>=b.right-1&&Math.abs((b.top+b.bottom)-(s.top+s.bottom))<3;})),'status is on the right of the engine badge in the same row');
  check(await page.locator('.network-engine-badge').evaluateAll(es=>es.every(e=>{const a=e.getBoundingClientRect(),b=e.firstElementChild.getBoundingClientRect();return Math.abs((a.top+a.bottom)-(b.top+b.bottom))<2;})),'badge text vertically centered');
  await page.locator('[data-engine-filter=gost]').click();check(await page.locator('[data-tunnel-card]').count()===1&&await page.locator('[data-engine-filter].active').count()===1,'filter changes cards and active selection');
  await page.locator('[data-new-tunnel]').click();check(await page.locator('#modalTitle').innerText()==='انتخاب نوع تونل','clear picker title');
  check(await page.locator('#modalBody [data-import-network]').count()===0&&await page.locator('#modalBody [data-close]').innerText()==='انصراف','picker has cancel and import is elsewhere');
  check(await page.locator('.network-choice-content').evaluate(e=>{const p=e.querySelector('p').getBoundingClientRect(),c=e.querySelector('.network-engine-picker').getBoundingClientRect(),f=e.closest('.modal-fields').getBoundingClientRect();return Math.abs((p.top-f.top)-(c.top-p.bottom))<3;}),'balanced picker spacing');await page.locator('[data-close]').click();
  await choose(page,'gost');
  check(await page.locator('[data-network-editor],[name=ports],[name=version]').count()===0,'GOST asks for two servers before settings');
  await runTest(page);check(await page.locator('[data-select-network-result]').count()===2,'GOST compares both versions in results');
  check((await page.locator('.network-protocol-results').first().innerText()).includes('TCP')&&(await page.locator('.network-protocol-results').first().innerText()).includes('UDP'),'TCP and UDP results are distinct');
  check((await page.locator('.tunnel-result-metrics').first().innerText()).includes('Mbps'),'results show transfer speeds');
  await selectResult(page);await page.locator('[name=name]').fill('GOST '+width);
  check(await page.locator('#modalTitle').innerText()==='ساخت تونل GOST','configuration title names engine');
  check(await page.locator('[name=iran_server_id]').isDisabled()&&await page.locator('[name=foreign_server_id]').isDisabled(),'configuration is bound to tested server pair');
  check(await page.locator('[name=public_host],[name=peer_host],[name=local_endpoint]').count()===0,'addresses need not be re-entered');
  check(await page.locator('[name=ip_family]').inputValue()==='ipv4','IPv4 default when registered');
  check(await page.locator('[name=transport]').count()===0&&await page.locator('[name=version] option').count()===2,'GOST has tested versions and no GRE or gRPC choices');
  check(await page.locator('[name=version] option').evaluateAll(es=>es.every(e=>!/[۰-۹]/.test(e.textContent))),'version digits are Latin');
  check(!(await page.locator('[data-network-editor]').innerText()).includes('مدیریت سرورها'),'redundant address explanation removed');
  await setPorts(page,'۴۴۳،۲۰۵۳,8443 8444');await page.locator('[data-advanced]>summary').click();await page.locator('[name=restart_enabled]').check();await page.locator('[name=restart_hours]').fill('3');
  check(await page.locator('[name=telegram_alerts]').isChecked(),'new tunnel alert defaults on when the bot is ready');
  await page.locator('[name=telegram_alerts]').uncheck();
  check(await page.locator('[name=quota_gb],[name=gre_key]').count()===0,'GOST has no GRE specialist settings');
  await page.locator('[data-back-test]').click();check(await page.locator('[data-network-test]').count()===1,'back returns to test results');await selectResult(page);
  check(await page.locator('[name=name]').inputValue()==='GOST '+width&&await page.locator('[data-map-row]').count()===4&&await page.locator('[name=restart_hours]').inputValue()==='3','test/settings back preserves draft');
  const nav=await page.locator('.network-navigation>.button').evaluateAll(es=>es.map(e=>{const r=e.getBoundingClientRect();return [r.height,getComputedStyle(e).borderRadius,getComputedStyle(e).boxShadow];}));check(nav.every(x=>x[0]===nav[0][0]&&x[1]===nav[0][1]&&x[2]==='none'),'navigation geometry and shadows match');
  await setPorts(page,'443,443');await save(page);check((await page.evaluate(()=>messages.at(-1))).includes('تکراری'),'duplicate ports rejected');
  await setPorts(page,'0,65536');await save(page);check(await page.locator('[data-map-key=public_port]').first().evaluate(e=>!e.validity.valid),'invalid ports cannot be submitted');
  await setPorts(page,'443,8443,8444');
  check(await page.locator('[data-map-row]').count()===3&&await page.locator('[data-map-key=public_end],[data-map-key=target_end]').count()===0,'unequal mode has individual two-port rows');
  await page.locator('[data-map-row="0"] [data-map-key=target_port]').fill('445');await page.locator('[data-add-map]').click();await page.locator('[data-map-row="3"] [data-map-key=public_port]').fill('9443');await page.locator('[data-map-row="3"] [data-map-key=target_port]').fill('9445');
  check((await page.locator('[data-add-map]').innerText()).includes('+'),'add port includes plus');
  check(await page.locator('[data-map-row]').evaluateAll(es=>es.every(e=>e.querySelector('[data-remove-map]').getBoundingClientRect().right<=e.querySelector('[data-map-key=target_port]').getBoundingClientRect().left+1)),'delete button stays alongside both port inputs');
  check(await page.locator('[data-map-row]').evaluateAll(es=>es.every(e=>{const inputs=[...e.querySelectorAll('[data-map-key]')].map(x=>x.getBoundingClientRect());const b=e.querySelector('[data-remove-map]').getBoundingClientRect();return Math.abs(inputs[0].bottom-inputs[1].bottom)<2&&Math.abs(inputs[0].bottom-b.bottom)<2;})),'port inputs and delete button share the same baseline');
  await page.locator('[data-remove-map="1"]').click();await save(page);
  let payload=await page.evaluate(()=>requests.filter(r=>r.url==='/network-tunnels'&&r.method==='POST').at(-1).payload);
  check(payload.test_id&&payload.result_id&&payload.mappings[0].target_port===445&&payload.mappings[0].target_end===445&&payload.restart_hours===3&&payload.telegram_alerts===false,'creation carries measured result and exact settings');
  check(!Object.keys(payload).some(k=>k.startsWith('__')),'internal wizard state never reaches API');
  const card=page.locator('[data-tunnel-card]').filter({has:page.locator('h3',{hasText:'GOST '+width})});await card.waitFor();const first=await card.getAttribute('data-tunnel-card');
  await page.locator('.tunnel-history>summary').click();await page.locator(`[data-history="${payload.test_id}"]`).click();
  check(await page.locator('[data-network-test]').count()===1,'recent tests restore GOST/GRE results after closing');
  await page.locator('[data-close]').click();
  check(await card.locator('[data-tunnel-action=add-peer],[data-tunnel-action=node],[data-tunnel-action=diagnose]').count()===0,'GOST has no unnecessary peer, node or diagnostic shortcuts');
  await page.locator('[data-engine-filter=all]').click();await create(page,'gre','GRE '+width,'904','905');
  check(await page.locator('[name=version],[name=ip_family]').count()===0&&await page.locator('[data-gre-settings]').count()===1,'GRE has a separate model form with no redundant family/version');
  check(await page.locator('[name=transport] option').count()===2,'only measured IPv4 GRE models can be selected');await page.locator('[name=transport]').selectOption('gretap',{force:true});
  await setPorts(page,'24443');await page.locator('[data-advanced]>summary').click();await page.getByText('تنظیمات تخصصی شبکه',{exact:true}).click();await page.locator('[name=mtu]').fill('1380');await page.getByText('سقف ترافیک',{exact:true}).click();await page.locator('[name=quota_gb]').fill('50');await page.locator('[name=nat]').selectOption('snat',{force:true});await page.locator('[name=snat_address]').fill('192.0.2.99');
  check(await page.locator('[name=mtu]').inputValue()==='1380'&&await page.locator('[name=quota_gb]').inputValue()==='50','dynamic GRE settings preserve values');
  check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'GRE advanced form fits '+width);
  await save(page);payload=await page.evaluate(()=>requests.filter(r=>r.url==='/network-tunnels'&&r.method==='POST').at(-1).payload);check(payload.transport==='gretap'&&payload.result_id.includes('gretap')&&payload.ip_family==='ipv4'&&payload.mtu===1380&&payload.quota_gb===50&&payload.telegram_alerts===true,'model selection binds correct measured result and family');
  if(width===1280){
   await page.evaluate(()=>{networkData().servers.find(s=>s.id===901).host='2001:db8::1';networkData().servers.find(s=>s.id===902).host='2001:db8::2';});
   await create(page,'gre','IPv6 GRE');await page.locator('[name=transport]').selectOption('ip6gretap',{force:true});await setPorts(page,'25443');await save(page);payload=await page.evaluate(()=>requests.filter(r=>r.url==='/network-tunnels'&&r.method==='POST').at(-1).payload);check(payload.transport==='ip6gretap'&&payload.ip_family==='ipv6'&&payload.local_endpoint==='2001:db8::1','IPv6 GRE test binds both IPv6 endpoints');
   await page.evaluate(()=>{networkData().servers.find(s=>s.id===901).host='2001:db8::1';networkData().servers.find(s=>s.id===902).host='2001:db8::2';});
   await create(page,'gost','IPv6 GOST');await setPorts(page,'26443');await save(page);payload=await page.evaluate(()=>requests.filter(r=>r.url==='/network-tunnels'&&r.method==='POST').at(-1).payload);check(payload.peer_host==='2001:db8::2'&&payload.ip_family==='ipv6','GOST tests registered IPv6 instead of silently falling back');
   await page.evaluate(()=>{networkData().servers.find(s=>s.id===901).host='192.0.2.10';networkData().servers.find(s=>s.id===902).host='192.0.2.20';window.fixtureProtocol='udp';});
   await create(page,'gost','UDP only');check(await page.locator('[name=protocol] option').count()===1&&await page.locator('[name=protocol]').inputValue()==='udp','TCP failure never enables TCP or both in configuration');await page.locator('[data-close]').click();
   await page.evaluate(()=>window.fixtureProtocol='unstable');await create(page,'gost','unstable UDP');await page.locator('[name=protocol]').selectOption('udp',{force:true});check(await page.locator('[name=accept_unstable]').count()===1,'unstable protocol requires explicit acceptance');await page.locator('[data-close]').click();
   await page.evaluate(()=>{window.fixtureProtocol=null;window.fixturePending=true;});await choose(page,'gre');await page.locator('[data-network-test] button[type=submit]').click();check(await page.locator('[data-select-network-result]:not(:disabled)').count()===0&&await page.locator('[data-network-editor]').count()===0,'running tests cannot advance to creation');await page.locator('[data-cancel-network-test]').click();check(await page.locator('[data-network-test] [name=iran_server_id]').isEnabled(),'cancelled test releases its form state');await page.locator('[data-close]').click();
   await page.evaluate(()=>{const t=structuredClone(networkData().data.find(t=>t.id===605));t.settings.mappings=[{public_port:12000,public_end:12002,target_port:443,target_end:445,destinations:[{address:'192.0.2.99',port:1443,weight:1}]}];return networkSession.editor(t,'gost');});await page.locator('[data-map-key=target_port]').fill('543');await save(page);payload=await page.evaluate(()=>requests.filter(r=>r.url==='/network-tunnels/605'&&r.method==='PUT').at(-1).payload);check(payload.mappings[0].target_end===545&&payload.mappings[0].destinations[0].port===1443,'legacy spans and custom targets survive editing');
  }
  await start(page,'off');
  await create(page,'gost','bot disabled');await page.locator('[data-advanced]>summary').click();
  check(!await page.locator('[name=telegram_alerts]').isChecked(),'new tunnel defaults off when the bot is unavailable');
  await page.locator('[name=telegram_alerts]').evaluate(e=>e.click());check(!await page.locator('[name=telegram_alerts]').isChecked()&&(await page.evaluate(()=>messages.at(-1))).includes('ربات'),'unavailable bot rejects enabling with a toast');
  await page.locator('[data-close]').click();
  await page.locator('[data-new-tunnel]').click();await page.locator('[data-engine=backpack]').click();check(await page.locator('#modalTitle').innerText()==='ساخت تونل BackPack','BackPack retains matching title');await page.locator('[name=iran_server_id]').selectOption('901',{force:true});await page.locator('[name=foreign_server_id]').selectOption('902',{force:true});await page.locator('[data-back-engines]').click();await page.locator('[data-engine=backpack]').click();check(await page.locator('[name=iran_server_id]').inputValue()==='901','BackPack back preserves servers');await page.locator('[data-test-form] button[type=submit]').click();await page.locator('[data-select-result="normal:reverse:wss"]').click();await page.locator('[name=name]').fill('BackPack saved');await page.locator('[data-back-results]').click();await page.locator('[data-select-result="normal:reverse:wss"]').click();check(await page.locator('[name=name]').inputValue()==='BackPack saved','BackPack configuration back preserves draft');check(!await page.locator('[name=telegram_alerts]').isChecked(),'BackPack defaults off without a ready bot');await page.locator('[name=telegram_alerts]').evaluate(e=>e.click());check(!await page.locator('[name=telegram_alerts]').isChecked(),'BackPack also enforces the bot checkbox gate');await page.keyboard.press('Escape');
  check(await page.evaluate(()=>calls.length===0),'local fixtures never contact real APIs');await page.evaluate(()=>session.dispose());await page.close();
 }
 check(errors.length===0,'no browser errors: '+errors.join('; '));console.log('PASS: '+assertions+' network browser assertions');
}catch(error){for(const page of context.pages())console.error('UI failure context:',await page.evaluate(()=>({title:document.getElementById('modalTitle')?.textContent,modals:window.modalEvents,messages:window.messages,requests:window.requests?.map(r=>r.method+' '+r.url)})).catch(()=>null));throw error;}finally{await browser.close();await new Promise(r=>server.close(r));}
