import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { readFileSync, existsSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
const { chromium } = await import(process.env.SPEEDBOX_TEST_PLAYWRIGHT || 'playwright');
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const manifest=JSON.parse(readFileSync(path.join(root,'public/build/manifest.json')));
const css=manifest['resources/js/app.js'].css.map(x=>`<link rel="stylesheet" href="/build/${x}">`).join('');
const html=`<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">${css}<link rel="stylesheet" href="/speedbox/infrastructure.css"><link rel="stylesheet" href="/speedbox/panel-controls.css"><style>body{margin:0;background:#17171c;padding:20px}.speedbox-infrastructure-overlay{position:relative}.infrastructure-modal{margin:auto;background:#222228;border:1px solid #555;border-radius:16px}.modal-fields{background:transparent}</style><div class="speedbox-infrastructure-overlay"><section class="create-modal infrastructure-modal node-modal"><div class="announcement-modal-heading"><h3 class="topup-title">ویرایش نود</h3></div><div id="modalBody"></div></section></div><script src="/speedbox/certificate-dns.js"></script><script src="/speedbox/node-editor.js"></script><script src="/speedbox/infrastructure.js"></script></html>`;
const server=createServer((req,res)=>{
 const url=new URL(req.url,'http://localhost');
 if(url.pathname==='/'){res.setHeader('Content-Type','text/html;charset=utf-8');res.end(html);return;}
 const p=path.resolve(root,'public','.'+url.pathname);
 if(!p.startsWith(path.join(root,'public')+path.sep)||!existsSync(p)){res.writeHead(404).end();return;}
 res.setHeader('Content-Type',p.endsWith('.js')?'application/javascript':p.endsWith('.css')?'text/css':'application/octet-stream');res.end(readFileSync(p));
});
await new Promise(r=>server.listen(0,'127.0.0.1',r));
const origin=`http://127.0.0.1:${server.address().port}`;
const browser=await chromium.launch({executablePath:process.env.SPEEDBOX_TEST_CHROME||undefined,headless:true,args:['--no-sandbox']});
let assertions=0;const check=(x,message)=>{assert.ok(x,message);assertions++;};
async function mount(page,{parent=false,stale=false}={}){
 await page.goto(origin);
 await page.evaluate(definition=>window.fixtureKernels=definition,JSON.parse(readFileSync(path.join(root,'agent/internal/model/kernel-capabilities.json'),'utf8')));
 await page.evaluate(({parent,stale})=>{
  window.calls=[];window.toastMessages=[];window.pending=false;window.polls=0;window.stale=stale;
  const payload={id:7,name:'Test node',type:'vless',machine_id:1,kernel:'xray',host:'node.test',port:'443',server_port:443,rate:1,rate_time_enable:false,show:true,enabled:true,warp_enabled:false,protocol_settings:{network:'tcp',tls:0},custom_outbounds:[{tag:'manual',protocol:'socks',settings:{servers:[{address:'127.0.0.1',port:1080}]}}],custom_routes:[{type:'field',domain:['example.test'],outboundTag:'manual'}],...(parent?{parent_id:9}:{})};
  window.original=structuredClone(payload);
  const api=async(url,opts={})=>{
   const body=opts.body?JSON.parse(opts.body):null;window.calls.push({url,method:opts.method||'GET',body});
   if(url.includes('/warp')){
    if(opts.method==='POST'){window.pending=true;window.polls=0;return{message:'queued',data:{id:1}};}
    if(window.stale&&url.includes('/1/'))await new Promise(r=>window.resolveStale=r);
    if(window.pending)window.polls++;
    return{data:{supported:true,status:{installed:true,ready:true,checked_at:Math.floor(Date.now()/1000),account:url.includes('/2/')?'plus':'free',ip:url.includes('/2/')?'203.0.113.2':'203.0.113.1'},operation:window.pending?{status:window.polls>1?'succeeded':'queued',log:'test operation'}:null}};
   }
   return{message:'saved'};
  };
  window.disposeNode=SpeedboxNodeEditor.mount({node:payload,state:{kernelCapabilities:window.fixtureKernels,nodes:parent?[{id:9,name:'Parent',type:'vless',machine_id:1,kernel:'xray',rate:1,warp_enabled:true}]:[],groups:[],routes:[],machines:[{id:1,name:'Host 1',is_active:true},{id:2,name:'Host 2',is_active:true}]},api,openModal:(title,body)=>document.getElementById('modalBody').innerHTML=body,closeModal:()=>{},toast:(message)=>window.toastMessages.push(message),reload:()=>{},enhanceControls:()=>{},isSaving:()=>false});
 },{parent,stale});
}
try{
 for(const width of [1280,390]){
  const page=await browser.newPage({viewport:{width,height:1000}});const errors=[];page.on('pageerror',e=>errors.push(e.message));
  await mount(page);
  check(await page.locator('.node-warp-card').count()===1,'WARP card present');
  check(await page.evaluate(()=>calls.length)===0,'opening a disabled node does not contact or modify its server');
  await page.locator('[data-path="warp_enabled"]').check();await page.waitForFunction(()=>document.querySelector('.node-warp-status')?.textContent.includes('متصل'));
  check(await page.evaluate(()=>calls.every(c=>c.method==='GET')),'toggle never installs WARP implicitly');
  await page.locator('[data-advanced]').click();await page.locator('[data-tab="outbounds"]').click();
  check(JSON.stringify(JSON.parse(await page.locator('[data-path="custom_outbounds"]').inputValue()))===JSON.stringify(await page.evaluate(()=>original.custom_outbounds)),'manual outbounds unchanged');
  check((await page.locator('.node-advanced-content').innerText()).includes('جداگانه'),'automatic outbound explained');
  await page.locator('[data-tab="routing"]').click();
  check(JSON.stringify(JSON.parse(await page.locator('[data-path="custom_routes"]').inputValue()))===JSON.stringify(await page.evaluate(()=>original.custom_routes)),'manual routes unchanged');
  await page.locator('[data-sub-close]').click();
  await page.locator('.node-warp-account summary').click();await page.locator('[data-warp-account]').selectOption('plus');
  await page.locator('#nodeWarpLicense').fill('private-license-123');await page.locator('[data-warp-action="change"]').click();
  await page.waitForFunction(()=>calls.some(c=>c.method==='POST'));
  const prepare=await page.evaluate(()=>calls.find(c=>c.method==='POST'));
  check(prepare.body.license==='private-license-123'&&prepare.body.account==='plus','license sent only to host preparation endpoint');
  await page.waitForFunction(()=>!document.querySelector('#nodeWarpLicense'));
  check((await page.locator('.node-warp-account').getAttribute('open'))===null,'account controls close after explicit update');
  check(await page.locator('[data-warp-action="change"]').isDisabled(),'queued operation cannot be submitted twice');
  await page.waitForFunction(()=>document.querySelector('[data-warp-action="change"]')?.disabled===false);
  check(await page.locator('[data-warp-account]').inputValue()==='free','server account determines selection after refresh');
  if(process.env.SPEEDBOX_TEST_SCREENSHOT&&width===1280)await page.locator('.node-assignment').screenshot({path:process.env.SPEEDBOX_TEST_SCREENSHOT});
  await page.locator('[data-path="warp_enabled"]').uncheck();
  check(await page.locator('.node-warp-content').count()===0,'disabled WARP details collapse');
  await page.locator('#nodeEditorForm button[type="submit"]').click();
  const save=await page.evaluate(()=>calls.find(c=>c.url==='/nodes/7'&&c.method==='PUT'));
  check(save?.body.warp_enabled===false,'node toggle saved independently');
  check(!('license'in save.body)&&!('account'in save.body),'license never enters node payload');
  check(JSON.stringify(save.body.custom_outbounds)===JSON.stringify(await page.evaluate(()=>original.custom_outbounds)),'save preserves custom outbounds');
  check(JSON.stringify(save.body.custom_routes)===JSON.stringify(await page.evaluate(()=>original.custom_routes)),'save preserves custom routes');
  check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'no horizontal overflow');
  check(errors.length===0,'no browser runtime errors');
  await page.evaluate(()=>disposeNode());await page.close();
 }
 for(const initial of [{installed:true,ready:true,account:'plus'},{installed:false,ready:false,account:'unknown'}]){
  const page=await browser.newPage();await mount(page);
  await page.evaluate(initial=>{
   window.fixtureStatus=initial;
   window.fetch=()=>{throw Error('unexpected remote access')};
   disposeNode();calls=[];
   SpeedboxNodeEditor.mount({node:{id:7,name:'node',type:'vless',machine_id:1,kernel:'xray',host:'node.test',port:'443',server_port:443,warp_enabled:true},state:{nodes:[],groups:[],routes:[],machines:[{id:1,name:'Host 1'}]},api:async(url,opts={})=>{const body=opts.body?JSON.parse(opts.body):null;calls.push({url,body,method:opts.method||'GET'});return opts.method==='POST'?{message:'queued'}:{data:{supported:false,agent_state:'unsupported',managed_server_id:22,status:fixtureStatus,operation:null}};},openModal:(t,b)=>document.getElementById('modalBody').innerHTML=b,closeModal(){},toast(){},reload(){},enhanceControls(){},isSaving(){return false;}});
  },initial);
  await page.waitForFunction(()=>document.querySelector('.node-warp-status'));
  check(await page.locator('[data-warp-action="upgrade"]').count()===0,'software upgrade belongs to setup');
  check(await page.evaluate(()=>calls.every(c=>c.method==='GET')),'viewing current server does not change its account');
  if(initial.installed){
   check((await page.locator('.node-warp-content').innerText()).includes('حساب سرور: WARP+'),'existing plus account displayed');
   check(await page.locator('[data-warp-action="install"]').count()===0,'existing host has no repeated install action');
   await page.locator('.node-warp-account summary').click();
   check(await page.locator('[data-warp-account]').inputValue()==='plus','existing plus is selected automatically');
   await page.locator('[data-warp-account]').selectOption('free');
   check((await page.locator('.node-warp-card').innerText()).includes('از WARP+ به رایگان'),'downgrade explains shared effect');
   check(await page.evaluate(()=>calls.every(c=>c.method==='GET')),'selecting free alone never downgrades');
   await page.locator('[data-warp-action="change"]').click();
   await page.waitForFunction(()=>calls.some(c=>c.method==='POST'));
   check(await page.evaluate(()=>calls.find(c=>c.method==='POST').body.account==='free'),'explicit account change uses free');
  }else{
   check(await page.locator('[data-warp-account] option').count()===2,'new setup has only free and plus');
   check(await page.locator('[data-warp-account]').inputValue()==='free','new setup defaults free');
   check(await page.locator('.node-warp-account summary').count()===0,'new install is shown directly');
   await page.locator('[data-warp-action="install"]').click();
   await page.waitForFunction(()=>calls.some(c=>c.method==='POST'));
   check(await page.evaluate(()=>calls.find(c=>c.method==='POST').body.action==='prepare'),'install uses existing host setup endpoint');
  }
  await page.close();
 }
 const parentPage=await browser.newPage();await mount(parentPage,{parent:true});
 check(await parentPage.locator('[data-path="warp_enabled"]').isChecked(),'child inherits enabled parent');
 check(await parentPage.locator('[data-path="warp_enabled"]').isDisabled(),'child cannot override parent');
 check(await parentPage.evaluate(()=>calls.length)===0,'child does not configure shared host independently');
 await parentPage.close();
 const stalePage=await browser.newPage();await mount(stalePage,{stale:true});
 await stalePage.locator('[data-path="warp_enabled"]').check();await stalePage.waitForFunction(()=>typeof resolveStale==='function');
 await stalePage.locator('[data-path="machine_id"]').selectOption('2');await stalePage.waitForFunction(()=>document.querySelector('.node-warp-content')?.textContent.includes('WARP+'));
 await stalePage.evaluate(()=>resolveStale());await stalePage.waitForTimeout(50);
 check((await stalePage.locator('.node-warp-content').innerText()).includes('WARP+'),'stale free-account response from previous host ignored');
 await stalePage.close();
 for(const width of [1280,390]){
  const page=await browser.newPage({viewport:{width,height:1000}});await page.goto(origin);
  await page.evaluate(()=>{
   document.body.innerHTML='<main id="infraRoot" class="speedbox-infrastructure"><h3 id="pageTitle"></h3><section id="content"></section><div id="infrastructureOverlay" class="speedbox-infrastructure-overlay"><div id="modalBackdrop" class="modal-backdrop" hidden><div class="modal-outer"><section class="create-modal infrastructure-modal"><div class="announcement-modal-heading"><h3 id="modalTitle" class="topup-title"></h3></div><div id="modalBody"></div></section></div></div></div></main>';
   localStorage.setItem('token','fixture');localStorage.setItem('user',JSON.stringify({id:1,role:1}));
   window.fetch=async url=>({ok:true,status:200,json:async()=>({data:String(url).endsWith('/machines')?[{id:1,name:'Host 1',is_active:true}]:String(url).endsWith('/warp')?{supported:true,status:{installed:true,ready:true,checked_at:Math.floor(Date.now()/1000),account:'free'},operation:null}:[]})});
   window.infra=SpeedboxInfrastructure.mount(document.getElementById('infraRoot'),{router:{replace(){}},notify:{success(){},error(){},notif(){}},view:'nodes'});
  });
  await page.locator('#addNode:not(:disabled)').click();
  await page.locator('[data-path="machine_id"]').selectOption('1',{force:true});
  await page.locator('[data-path="warp_enabled"]').check();
  await page.waitForFunction(()=>document.querySelector('.node-warp-status')?.textContent.includes('متصل'));
  await page.locator('.node-warp-account summary').click();
  await page.locator('[data-warp-account]').selectOption('plus',{force:true});
  check(await page.locator('#nodeWarpLicense').evaluate(e=>e.classList.contains('form-input')),'license uses real panel input styling');
  check(await page.locator('[data-path="warp_enabled"]').evaluate(e=>getComputedStyle(e).appearance==='none'),'real panel switch appearance applied');
  check(await page.locator('[data-warp-account]').evaluate(e=>e.dataset.enhanced==='true'),'real dropdown enhancement applied');
  check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'real infrastructure form has no horizontal overflow');
  if(process.env.SPEEDBOX_TEST_SCREENSHOT&&width===1280)await page.locator('.node-warp-card').screenshot({path:process.env.SPEEDBOX_TEST_SCREENSHOT});
  await page.evaluate(()=>infra.dispose());await page.close();
 }
 console.log(`WARP UI: PASS (${assertions} assertions; desktop/mobile, manual JSON, license isolation, queue polling, parent, host switch)`);
}finally{await browser.close();await new Promise(r=>server.close(r));}
