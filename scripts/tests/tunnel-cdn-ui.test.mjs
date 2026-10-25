import assert from 'node:assert/strict';
import {createServer} from 'node:http';
import {readFileSync,existsSync} from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
const {chromium}=await import(process.env.SPEEDBOX_TEST_PLAYWRIGHT||'playwright');
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'../..');
const manifest=JSON.parse(readFileSync(path.join(root,'public/build/manifest.json')));
const css=(manifest['resources/js/app.js'].css||[]).map(f=>`<link rel="stylesheet" href="/build/${f}">`).join('');
const template=readFileSync(path.join(root,'resources/views/infrastructure.blade.php'),'utf8').replace(/^[\s\S]*?<template[^>]*>/,'').replace(/<\/template>[\s\S]*$/,'');
const styles=['panel-controls','branding','infrastructure','tunnels','network-tunnels'].map(n=>`<link rel="stylesheet" href="/speedbox/${n}.css">`).join('');
const scripts=['certificate-dns','tunnel-cdn','wattunnel','network-tunnels','tunnels','node-editor','infrastructure'].map(n=>`<script src="/speedbox/${n}.js"></script>`).join('');
const html=`<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">${css}${styles}<style>body{margin:0;background:#17171c;color:#eee}.center-box{margin-top:30px}</style><div id="app"><div class="center-box"><div class="speedbox-infrastructure" id="mount">${template}</div></div></div>${scripts}</html>`;
const server=createServer((req,res)=>{const url=new URL(req.url,'http://localhost');if(url.pathname==='/'){res.setHeader('Content-Type','text/html;charset=utf-8');res.end(html);return;}const p=path.resolve(root,'public','.'+url.pathname);if(!p.startsWith(path.join(root,'public')+path.sep)||!existsSync(p)){res.writeHead(404).end();return;}res.setHeader('Content-Type',p.endsWith('.js')?'text/javascript':p.endsWith('.css')?'text/css':'application/octet-stream');res.end(readFileSync(p));});
await new Promise(r=>server.listen(0,'127.0.0.1',r));const origin=`http://127.0.0.1:${server.address().port}`;
const browser=await chromium.launch({executablePath:process.env.SPEEDBOX_TEST_CHROME||undefined,headless:true,args:['--no-sandbox','--disable-dev-shm-usage',...(process.env.SPEEDBOX_TEST_SWIFTSHADER?['--use-gl=angle','--use-angle=swiftshader','--enable-unsafe-swiftshader']:[])]});
let count=0;const check=(ok,label)=>{assert.ok(ok,label);count++;};
const errors=[];
async function start(page,query=''){
 await page.goto(origin+'/?tunnel_preview=cdn'+query);
 await page.addStyleTag({content:'*,*::before,*::after{animation:none!important;transition:none!important}'});
 await page.evaluate(()=>{
  window.messages=[];window.realRequests=[];localStorage.setItem('token','test');localStorage.setItem('user',JSON.stringify({role:1}));
  window.fetch=async(...args)=>{realRequests.push(args);throw Error('Unexpected real API request');};
  window.session=SpeedboxInfrastructure.mount(document.getElementById('mount'),{router:{push(){},replace(){}},notify:{success:m=>messages.push(m),error:m=>messages.push(m),notif:m=>messages.push(m)},view:'tunnels'});
 });
 await page.locator('[data-tunnel-card="614"]').waitFor();
}
async function edit(page,id){await page.locator(`[data-tunnel-card="${id}"] [data-tunnel-native-action]`).selectOption('edit',{force:true});await page.locator('[data-cdn-mode]').waitFor();}
async function auditCertificateLayout(page){
 const portHint=await page.locator('[name=tunnel_port]').locator('..').locator('small').innerText();
 check(portHint==='این پورت برای ارتباط خود تونل است؛ با پورت کاربران متفاوت باشد.','ordinary port hint is shared across routes and engines');
 check(await page.locator('[data-cdn-field="domain"]').locator('..').locator('small bdi[dir=ltr]').count()===2,'domain guidance isolates CDN and the actual origin address');
 for(const mode of ['content','file','http','dns']){
  await page.locator('[data-cdn-cert-mode]').selectOption(mode,{force:true});
  check(await page.locator('.tunnel-cdn-port-note').evaluate(e=>{const previous=e.previousElementSibling;return getComputedStyle(e).textAlign==='center'&&e.getBoundingClientRect().top-previous.getBoundingClientRect().bottom>=16&&!e.querySelector('svg,i');}),'port notice has a separate gap without an icon in '+mode);
  if(mode==='content'){
   for(const [key,kind] of [['cert_content','CERTIFICATE'],['key_content','PRIVATE KEY']])check(await page.locator('[data-cdn-field="'+key+'"]').getAttribute('placeholder')==='-----BEGIN '+kind+'-----\n...\n-----END '+kind+'-----','PEM placeholder shows both boundaries for '+key);
   check(await page.locator('[data-cdn-field="cert_content"]').locator('..').locator('small').count()===0,'redundant certificate issuer hint removed');
  }
  if(mode==='dns'){
   const credential=page.locator('[data-dns-key="ARVANCLOUD_API_KEY"]');
   check(await credential.getAttribute('placeholder')===((await page.locator('[data-tunnel-setup]').count())?'demo********-key':'ARVANCLOUD_API_KEY'),'saved Arvan preview and new credential hint are distinct');
   check(await credential.inputValue()===''&&await page.locator('.certificate-dns-saved').count()===0,'preview is display-only and replaces the redundant message');
   check(await credential.locator('..').locator('.certificate-dns-hint').count()===1,'credential purpose is placed inside its own field');
   check(await credential.locator('..').evaluate(e=>{const input=e.querySelector('input').getBoundingClientRect(),hint=e.querySelector('.certificate-dns-hint').getBoundingClientRect();return hint.top>=input.bottom&&Math.abs(hint.left-input.left)<2&&Math.abs(hint.width-input.width)<2;}),'credential purpose aligns under its input');
  }
 }
 await page.locator('[data-cdn-mode]').selectOption('direct',{force:true});
 check(await page.locator('[name=tunnel_port]').locator('..').locator('small').innerText()===portHint,'port hint is identical in direct and CDN mode');
 await page.locator('[data-cdn-mode]').selectOption('cdn',{force:true});
}
try{
 for(const width of [1440,390]){
  const page=await browser.newPage({viewport:{width,height:1000}});page.on('pageerror',e=>errors.push(e.message));await start(page);
  check(await page.locator('.tunnel-card:has(.tunnel-badge:text-is("CDN"))').count()===5,'five CDN fixture cards');
  await edit(page,610);
  await auditCertificateLayout(page);
  check(await page.locator('[data-cdn-mode]').inputValue()==='cdn','existing CDN route opens selected');
  check(await page.locator('[data-cdn-cert-mode]').inputValue()==='dns','automatic DNS certificate retained');
  check(await page.locator('[data-dns-key="ARVANCLOUD_API_KEY"]').inputValue()==='','saved credentials not sent to browser');
  check(await page.locator('[name=tunnel_port]').inputValue()==='8443','shared tunnel port is shown once');
  check(await page.locator('[data-cdn-field="port"],[data-cdn-field="origin_port"]').count()===0,'no separate edge or origin input');
  check(await page.locator('[data-cdn-field="edge_ip"]').count()===1,'edge IP remains visible without a toggle');
  check((await page.locator('.tunnel-cdn-heading').innerText()).includes('192.0.2.10'),'receiver caption includes the actual server IP');
  check((await page.locator('[data-cdn-field="domain"]').locator('..').innerText()).includes('پروکسی دامنه'),'domain hint uses the agreed wording');
  check(await page.locator('.tunnel-cdn-section').evaluate(e=>!e.closest('.tunnel-advanced')),'CDN fields inline outside advanced settings');
  await page.locator('[data-cdn-mode]').selectOption('direct',{force:true});
  check(await page.locator('.tunnel-cdn-section').count()===0&&await page.locator('[name=tunnel_port]').count()===1,'direct route hides CDN and keeps ordinary port');
  await page.locator('[data-cdn-mode]').selectOption('cdn',{force:true});
  for(const mode of ['content','file','http','dns']){
   await page.locator('[data-cdn-cert-mode]').selectOption(mode,{force:true});
   const key={content:'key_content',file:'key_file',http:'http_port'}[mode];
   check(await page.locator(mode==='dns'?'[data-dns-key="ARVANCLOUD_API_KEY"]':`[data-cdn-field="${key}"]`).count()===1,'certificate fields switch to '+mode);
  }
  await page.locator('[data-cdn-test]').click();await page.locator('[data-cdn-status]').filter({hasText:'تأیید شد'}).waitFor();
  await page.locator('[name=tunnel_port]').fill('443');
  check((await page.locator('[data-cdn-status]').innerText()).includes('دوباره بررسی'),'settings change invalidates result');

  check(await page.locator('.tunnel-cdn-section').evaluate(e=>{const box=e.getBoundingClientRect();return [...e.querySelectorAll('input.form-input,textarea.form-input,.invoice-custom-select__trigger,label,[data-cdn-status]')].every(x=>{const r=x.getBoundingClientRect();return !r.width||r.left>=box.left-2&&r.right<=box.right+2;});}),'CDN form fits viewport '+width);
  if(process.env.SPEEDBOX_TEST_SCREENSHOT)await page.screenshot({path:process.env.SPEEDBOX_TEST_SCREENSHOT+'-'+width+'.png',fullPage:true});
  const bpLayout=await page.locator('[data-cdn-field="domain"]').evaluate(e=>({input:e.getBoundingClientRect().width,label:e.closest('.field').querySelector('label').getBoundingClientRect().width,columns:getComputedStyle(e.closest('.field')).gridTemplateColumns}));
  await page.locator('#modalBody [data-close]').click();
  await page.locator('[data-tunnel-detail="614"]').click();
  check((await page.locator('.tunnel-cdn-detail').innerText()).includes('تأیید نشده'),'unverified route displayed honestly');await page.locator('#modalBody [data-close]').click();
  await edit(page,613);
  await auditCertificateLayout(page);
  check(await page.locator('[data-wat-editor]').count()===1,'WatTunnel shares CDN fields in its own form');
  const watLayout=await page.locator('[data-cdn-field="domain"]').evaluate(e=>({input:e.getBoundingClientRect().width,label:e.closest('.field').querySelector('label').getBoundingClientRect().width,columns:getComputedStyle(e.closest('.field')).gridTemplateColumns}));
  check(Math.abs(watLayout.input-bpLayout.input)<2&&Math.abs(watLayout.label-bpLayout.label)<2,'WatTunnel and BackPack label/input widths match at '+width);
  await page.locator('[data-cdn-cert-mode]').selectOption('content',{force:true});
  check(await page.locator('[data-cdn-field="cert_content"]').evaluate(e=>Math.abs(e.getBoundingClientRect().width-document.querySelector('[data-cdn-field="domain"]').getBoundingClientRect().width)<2),'WatTunnel certificate textarea uses the full input column');
  await page.locator('[data-cdn-cert-mode]').selectOption('dns',{force:true});
  check((await page.locator('[data-dns-provider]').inputValue())==='arvancloud','WatTunnel uses the shared provider picker');
  await page.locator('[name=transport]').selectOption('tcp',{force:true});
  check(await page.locator('[data-cdn-mode]').count()===0,'unsupported WatTunnel carrier does not offer CDN');await page.locator('#modalBody [data-close]').click();
  await page.locator('[data-new-tunnel]').click();await page.locator('[data-engine=backpack]').click();
  check(await page.locator('[data-cdn-mode]').count()===0,'CDN not offered before method selection');
  await page.locator('[name=iran_server_id]').selectOption('901',{force:true});await page.locator('[name=foreign_server_id]').selectOption('902',{force:true});
  await page.locator('[data-test-form] button[type=submit]').click();
  await page.locator('[data-select-result="normal:reverse:ws"]').click();
  check(await page.locator('[data-cdn-mode]').inputValue()==='cdn','failed direct WS may open CDN settings');
  check(await page.locator('[data-cdn-mode] option[value=direct]').isDisabled(),'failed direct path cannot be selected');
  check(await page.evaluate(()=>realRequests.length)===0,'local fixtures never call real API');await page.close();
 }
 // Exercise the same provider fields inside the real node advanced dialog.
 const nodePage=await browser.newPage({viewport:{width:1280,height:1000}});nodePage.on('pageerror',e=>errors.push(e.message));await nodePage.goto(origin);
 const kernels=JSON.parse(readFileSync(path.join(root,'agent/internal/model/kernel-capabilities.json'),'utf8'));
 await nodePage.evaluate(kernels=>{
  const overlay=document.createElement('div');overlay.className='speedbox-infrastructure-overlay';overlay.innerHTML='<section class="infrastructure-modal node-modal"><div id="node-audit-body"></div></section>';document.body.appendChild(overlay);
  window.nodePayload=null;
  SpeedboxNodeEditor.mount({node:{id:77,name:'Certificate node',type:'vless',machine_id:1,kernel:'xray',host:'node.example.com',port:'443',server_port:443,rate:1,show:true,enabled:true,warp_enabled:false,protocol_settings:{network:'tcp',tls:0},cert_config:{cert_mode:'dns',domain:'node.example.com',email:'',dns_provider:'cf',dns_env:{CF_API_TOKEN:'saved-node-secret'}}},state:{nodes:[],groups:[],routes:[],machines:[{id:1,name:'Host',is_active:true}],kernelCapabilities:kernels},api:async(url,o={})=>{window.nodePayload=JSON.parse(o.body||'{}');return {message:'saved'};},openModal:(title,body)=>document.querySelector('#node-audit-body').innerHTML=body,closeModal:()=>{},toast:()=>{},reload:()=>{},enhanceControls:()=>{},isSaving:()=>false});
 },kernels);
 await nodePage.locator('[data-advanced]').click();
 check(await nodePage.locator('[data-dns-key="CLOUDFLARE_DNS_API_TOKEN"]').inputValue()==='','node advanced dialog hides saved credentials');
 check(await nodePage.locator('[data-path="cert_config.email"]').getAttribute('placeholder')==='اختیاری','node ACME email keeps its title and shows optional placeholder');
 check(await nodePage.locator('[data-path="cert_config.dns_env"]').count()===0,'node raw DNS variables box removed');
 check(await nodePage.locator('[data-dns-key="CLOUDFLARE_DNS_API_TOKEN"]').getAttribute('placeholder')==='save********cret','node credentials use the same masked preview');
 await nodePage.locator('[data-path="cert_config.cert_mode"]').selectOption('content',{force:true});
 for(const [key,kind] of [['cert_content','CERTIFICATE'],['key_content','PRIVATE KEY']])check(await nodePage.locator('[data-path="cert_config.'+key+'"]').getAttribute('placeholder')==='-----BEGIN '+kind+'-----\n...\n-----END '+kind+'-----','node PEM placeholder matches tunnel '+key);
 await nodePage.locator('[data-path="cert_config.cert_mode"]').selectOption('dns',{force:true});
 await nodePage.locator('.node-sub-backdrop button[type=submit]').click();await nodePage.locator('#nodeEditorForm button[type=submit]').click();
 check(await nodePage.evaluate(()=>nodePayload.cert_config.dns_env.CF_API_TOKEN)==='saved-node-secret','saving node with blank credential preserves its value');await nodePage.close();
 const failure=await browser.newPage();failure.on('pageerror',e=>errors.push(e.message));await start(failure,'&tunnel_cdn_result=failed');await edit(failure,610);await failure.locator('[data-cdn-test]').click();await failure.locator('[data-cdn-status]').filter({hasText:'ناموفق'}).waitFor();check(true,'failed CDN test visible');await failure.close();
 check(errors.length===0,'no browser errors: '+errors.join('; '));console.log(`PASS: ${count} CDN browser checks`);
}finally{await browser.close();await new Promise(r=>server.close(r));}
