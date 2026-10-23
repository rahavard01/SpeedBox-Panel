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
const html=`<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">${css}<link rel="stylesheet" href="/speedbox/panel-controls.css"><link rel="stylesheet" href="/speedbox/branding.css"><link rel="stylesheet" href="/speedbox/infrastructure.css"><link rel="stylesheet" href="/speedbox/tunnels.css"><style>body{margin:0;background:#17171c;color:#eee}.center-box{margin-top:30px}</style><div id="app"><div class="center-box"><div class="speedbox-infrastructure" id="mount">${template}</div></div></div><link rel="stylesheet" href="/speedbox/network-tunnels.css"><script src="/speedbox/network-tunnels.js"></script><script src="/speedbox/tunnels.js"></script><script src="/speedbox/node-editor.js"></script><script src="/speedbox/infrastructure.js"></script></html>`;
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

try {
 for(const width of [390,1280]){
  const page=await context.newPage();await page.setViewportSize({width,height:900});await start(page);
  for(let cycle=0;cycle<3;cycle++){
   await page.locator('[data-new-tunnel]').click();
   check(await page.locator('#modalBackdrop').evaluate(e=>!e.hidden),'create opens after navigation');
   check(await page.locator('[data-engine]').count()===3,'create shows engine choices, not a forced resumed test');
   await page.keyboard.press('Escape');
   await page.locator('[data-reload-tunnels]').click();
   await page.locator('[data-reload-tunnels].is-refreshed').waitFor();
   check(true,'refresh calls the current session');
   await page.evaluate(()=>{window.fetch=async()=>({ok:true,status:200,json:async()=>({data:[]})});session.showView('servers');});
   await page.locator('#addServer').waitFor();await page.evaluate(()=>session.showView('tunnels'));
   await page.locator('[data-tunnel-card="606"]').waitFor();
  }
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
  check(await page.locator('[data-engine]').count()===3,'new create does not force an existing test to reopen');
  await page.keyboard.press('Escape');await page.evaluate(()=>session.dispose());await page.close();
 }
 console.log('PASS:',assertions,'navigation and progress assertions on mobile and desktop');
} finally {await browser.close();await new Promise(r=>server.close(r));}
