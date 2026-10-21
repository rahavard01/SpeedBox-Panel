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
const html=`<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">${css}<link rel="stylesheet" href="/speedbox/panel-controls.css"><link rel="stylesheet" href="/speedbox/branding.css"><link rel="stylesheet" href="/speedbox/infrastructure.css"><link rel="stylesheet" href="/speedbox/tunnels.css"><style>body{margin:0;background:#17171c;color:#eee}.center-box{margin-top:30px}</style><div id="app"><div class="center-box"><div class="speedbox-infrastructure" id="mount">${template}</div></div></div><link rel="stylesheet" href="/speedbox/network-tunnels.css"><script src="/speedbox/network-tunnels.js"></script><script src="/speedbox/tunnels.js"></script><script src="/speedbox/node-editor.js"></script><script src="/speedbox/infrastructure.js"></script><script src="/speedbox/emergency-configs.js"></script><link rel="stylesheet" href="/speedbox/emergency-configs.css"></html>`;
const server=createServer((req,res)=>{const url=new URL(req.url,'http://localhost');if(url.pathname==='/'){res.setHeader('Content-Type','text/html;charset=utf-8');res.end(html);return;}const p=path.resolve(root,'public','.'+url.pathname);if(!p.startsWith(path.join(root,'public')+path.sep)||!existsSync(p)){res.writeHead(404).end();return;}res.setHeader('Content-Type',p.endsWith('.js')?'text/javascript':p.endsWith('.css')?'text/css':'application/octet-stream');res.end(readFileSync(p));});
await new Promise(r=>server.listen(0,'127.0.0.1',r));const origin=`http://127.0.0.1:${server.address().port}`;
const browser=await chromium.launch({executablePath:process.env.SPEEDBOX_TEST_CHROME||undefined,headless:true,args:['--no-sandbox','--disable-dev-shm-usage',...(process.env.SPEEDBOX_TEST_SWIFTSHADER?['--use-gl=angle','--use-angle=swiftshader','--enable-unsafe-swiftshader','--no-zygote','--single-process']:[])]});

let assertions=0;const check=(yes,message)=>{assert.ok(yes,message);assertions++;};
const errors=[];const context=await browser.newContext();context.setDefaultTimeout(30000);

try {
 for(const width of [1280,390]) {
  const page=await context.newPage();await page.setViewportSize({width,height:900});page.on('pageerror',e=>errors.push(e.message));
  await page.goto(origin+'/?tunnel_preview=full');await page.bringToFront();await page.addStyleTag({content:'*,*::before,*::after{animation:none!important;transition:none!important}'});
  await page.evaluate(()=>{
   localStorage.setItem('token','test');localStorage.setItem('user',JSON.stringify({role:1}));window.emergencyCalls=[];window.messages=[];
   window.fetch=async()=>{throw Error('Unexpected real API request');};
   const mount=SpeedboxNetworkTunnels.mount;SpeedboxNetworkTunnels.mount=ctx=>{window.uiContext=ctx;return mount(ctx);};
   window.session=SpeedboxInfrastructure.mount(document.getElementById('mount'),{router:{push(){},replace(){}},notify:{success(){},error(){},notif(){}},view:'tunnels'});
  });
  await page.locator('[data-tunnel-card="606"]').waitFor();
  await page.evaluate(async()=>{
   const root=document.createElement('div');root.className='speedbox-infrastructure';document.querySelector('.center-box').append(root);
   let rows=[{id:1,name:'Emergency example',host:'192.0.2.1',port:443,protocol:'trojan',show:true,tags:['SBONLY'],config_uri:'trojan://fixture@192.0.2.1:443'}];
   const api=async(url,options={})=>{
    const payload=options.body?JSON.parse(options.body):null;emergencyCalls.push({url,payload,method:options.method||'GET'});
    if(url==='/emergency-configs'&&options.method==='POST'){rows.push({id:2,...payload,host:'192.0.2.2',port:443,protocol:'trojan'});return {data:rows.at(-1)};}
    if(url==='/emergency-configs')return {data:structuredClone(rows),position:'bottom'};
    if(options.method==='PUT'){Object.assign(rows[0],payload);return {data:structuredClone(rows[0])};}
    return {data:structuredClone(rows[0])};
   };
   window.emergency=SpeedboxEmergencyConfigs.mount(root,{...uiContext,api,toast:m=>messages.push(m)});await emergency.load();
  });
  check(await page.locator('.emergency-client-tag').innerText()==='SpeedBox','restricted emergency config has a compact client badge');
  await page.locator('[data-emergency-action=edit]').click();
  check(await page.locator('[name=tags]').inputValue()==='SBONLY','edit retains tags');
  await page.locator('[name=tags]').fill('SBONLY\nroute-a\nroute-a');await page.locator('#emergencyForm button[type=submit]').click();
  check(await page.evaluate(()=>{const p=emergencyCalls.findLast(x=>x.method==='PUT').payload;return p.tags.join(',')==='SBONLY,route-a'&&p.config_uri.includes('fixture');}),'save preserves config and deduplicates tags');
  await page.locator('[data-emergency-action=add]').click();check(await page.locator('[name=tags]').inputValue()==='','new emergency defaults to unrestricted visibility');
  await page.locator('[name=name]').fill('New emergency');await page.locator('[name=config_uri]').fill('trojan://fixture@192.0.2.2:443');await page.locator('[name=tags]').fill('sbonly');
  check(await page.locator('.emergency-tags-field').evaluate(e=>{const r=e.getBoundingClientRect();return r.left>=0&&r.right<=innerWidth+1;}),'tag field fits the modal at '+width);
  await page.locator('#emergencyForm button[type=submit]').click();
  check(await page.locator('.emergency-client-tag').count()===2,'case-insensitive client badge follows saved tags');
  check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'emergency layout fits '+width);
  await page.evaluate(()=>{emergency.dispose();session.dispose();});await page.close();
 }
 check(errors.length===0,'no browser errors: '+errors.join('; '));console.log('PASS: '+assertions+' emergency tag browser assertions');
} finally {await browser.close();await new Promise(r=>server.close(r));}
