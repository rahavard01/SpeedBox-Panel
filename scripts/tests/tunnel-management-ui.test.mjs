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
const html=`<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">${css}<link rel="stylesheet" href="/speedbox/panel-controls.css"><link rel="stylesheet" href="/speedbox/branding.css"><link rel="stylesheet" href="/speedbox/infrastructure.css"><link rel="stylesheet" href="/speedbox/tunnels.css"><style>body{margin:0;background:#17171c;color:#eee}.center-box{margin-top:30px}</style><div id="app"><div class="center-box"><div class="speedbox-infrastructure" id="mount">${template}</div></div></div><script src="/speedbox/tunnels.js"></script><script src="/speedbox/node-editor.js"></script><script src="/speedbox/infrastructure.js"></script></html>`;
const server=createServer((req,res)=>{const url=new URL(req.url,'http://localhost');if(url.pathname==='/'){res.setHeader('Content-Type','text/html;charset=utf-8');res.end(html);return;}const p=path.resolve(root,'public','.'+url.pathname);if(!p.startsWith(path.join(root,'public')+path.sep)||!existsSync(p)){res.writeHead(404).end();return;}res.setHeader('Content-Type',p.endsWith('.js')?'text/javascript':p.endsWith('.css')?'text/css':'application/octet-stream');res.end(readFileSync(p));});
await new Promise(r=>server.listen(0,'127.0.0.1',r));const origin=`http://127.0.0.1:${server.address().port}`;
const browser=await chromium.launch({executablePath:process.env.SPEEDBOX_TEST_CHROME||undefined,headless:true,args:['--no-sandbox','--disable-dev-shm-usage',...(process.env.SPEEDBOX_TEST_SWIFTSHADER?['--use-gl=angle','--use-angle=swiftshader','--enable-unsafe-swiftshader','--no-zygote','--single-process']:[])]});
const context=await browser.newContext();
let assertions=0;const check=(v,msg)=>{assert.ok(v,msg);assertions++;};
async function mount(page,count=3,url=origin){await page.goto(url);await page.evaluate(count=>{
 localStorage.setItem('token','test');localStorage.setItem('user',JSON.stringify({role:1}));
 window.calls=[];window.messages=[];window.created=false;window.nextOp=30;window.operations={};window.latest=null;
 const servers=[{id:1,name:'ایران — رسپینا',host:'192.0.2.1',machine_id:11},{id:2,name:'خارج — آلمان',host:'192.0.2.2',machine_id:22},{id:3,name:'خارج — فنلاند',host:'192.0.2.3',machine_id:33}].slice(0,count);
 const rows=[{id:'normal:reverse:pck',label:'Reverse PCK',kind:'reverse',transport:'pck',orientation:'normal',status:'ok',ok:60,total:60,rtt:40,download:25,upload:20,selectable:true,recommended:true},{id:'normal:reverse:tcp',label:'Reverse TCP',kind:'reverse',transport:'tcp',orientation:'normal',status:'unstable',ok:40,total:60,rtt:45,download:10,upload:9,selectable:true,recommended:false},{id:'normal:reverse:wss',label:'Reverse WSS',kind:'reverse',transport:'wss',orientation:'normal',status:'down',ok:0,total:60,rtt:0,download:0,upload:0,selectable:false,recommended:false}];
 window.fixtureTest={id:7,iran_server_id:1,foreign_server_id:2,status:'completed',results:rows,message:'بررسی تمام شد',created_at:new Date().toISOString()};
 window.tunnel={id:1,name:'رسپینا — آلمان',iran_server_id:1,foreign_server_id:2,kind:'reverse',transport:'pck',status:'connected',message:'ارتباط تونل برقرار است.',iran:servers[0],foreign:servers[1],settings:{public_host:'ir.example.com',peer_host:'',tunnel_port:8000,accept_udp:false,mappings:[{public_port:443,target_port:5790}]},nodes:[],operation:null};
 window.hosts=Object.fromEntries(servers.map(s=>[s.id,{checked_at:Math.floor(Date.now()/1000),optimized:false,version:'v1.8.4',target_version:'v1.9.0',up_to_date:false}]));
 window.fetch=async(url,opts={})=>{
  url=String(url).replace('/api/admin/infrastructure','');const payload=opts.body?JSON.parse(opts.body):null;calls.push({url,method:opts.method||'GET',payload});let answer={data:[]};
  if(url==='/tunnels'&&!opts.method){if(window.delayReload)await new Promise(resolve=>{window.releaseReload=resolve;});if(window.failReload)throw Error('خطای آزمایشی تازه‌سازی');const twins=servers.length>2?[{...tunnel,id:2,name:'رسپینا — فنلاند',foreign_server_id:3,foreign:servers[2]}]:[];answer={data:created?[{...tunnel,operation:latest},...twins.map(t=>({...t,operation:latest}))]:[],servers,tests:[],host_status:hosts};}
  else if(url==='/tunnel-tests'&&opts.method==='POST')answer={data:fixtureTest,operation:{id:3,status:'succeeded'},message:'تست انجام شد'};
  else if(url==='/tunnel-tests/7')answer={data:fixtureTest,operation:{id:3,status:'succeeded'}};
  else if(url==='/tunnels/1/tests')answer={data:[fixtureTest]};
  else if(url==='/tunnels'&&opts.method==='POST'){created=true;tunnel.name=payload.name;tunnel.settings=payload;answer={data:tunnel,message:'ساخت در صف قرار گرفت'};}
  else if(url==='/tunnels/1'&&opts.method==='PUT'){tunnel.name=payload.name;tunnel.settings=payload;if(payload.test_id){const r=fixtureTest.results.find(r=>r.id===payload.result_id);tunnel.kind=r.kind;tunnel.transport=r.transport;tunnel.orientation=r.orientation;}answer={message:'ویرایش ذخیره شد'};}
  else if(url.includes('/actions')){const id=nextOp++;const op={id,action:payload.action,status:'queued',progress:0,message:'در صف عملیات',payload};operations[id]=op;latest=op;answer={data:op,message:'عملیات در صف قرار گرفت'};}
  else if(url.startsWith('/tunnel-operations/')){const id=Number(url.split('/').at(-1)),op=operations[id];if(op.action==='optimize')for(const side of op.payload.sides)hosts[side==='iran'?1:2].optimized=true;if(op.action==='engine-update')for(const h of Object.values(hosts)){h.up_to_date=true;h.version=h.target_version;}
   op.status='succeeded';op.progress=100;op.message=op.action==='optimize'?'سرورهای انتخاب‌شده بهینه‌سازی شدند.':op.action==='engine-update'?'موتور سرورها به‌روزرسانی شد.':'وضعیت سرورها بررسی شد.';op.result={hosts:Object.fromEntries(servers.map(s=>[s.id,{name:s.name,status:hosts[s.id],logs:op.action==='optimize'?['OK: net.ipv4.tcp_congestion_control = bbr']:[]}]))};answer={data:op};}
  return new Response(JSON.stringify(answer),{status:200,headers:{'Content-Type':'application/json'}});
 };
 const notify={success:m=>messages.push(m),error:m=>messages.push(m),notif:m=>messages.push(m)};
 window.session=SpeedboxInfrastructure.mount(document.getElementById('mount'),{router:{push(){},replace(){}},notify,view:'tunnels'});
 },count);await page.locator('[data-new-tunnel]').waitFor();}
async function action(page,name){await page.locator('[data-tunnel-card="1"] .tunnel-actions summary').click();await page.locator(`[data-tunnel-card="1"] [data-tunnel-action="${name}"]`).click();}
async function screenshot(page,name,width){if(process.env.SPEEDBOX_TEST_SCREENSHOT_DIR){mkdirSync(process.env.SPEEDBOX_TEST_SCREENSHOT_DIR,{recursive:true});await page.screenshot({path:path.join(process.env.SPEEDBOX_TEST_SCREENSHOT_DIR,`${name}-${width}.png`),fullPage:true});}}
async function checkResults(page){
 const layout=await page.evaluate(()=>{
  const list=document.querySelector('.tunnel-result-list'),buttons=[...list.querySelectorAll('[data-select-result]')],rect=list.getBoundingClientRect();
  return {fits:list.scrollWidth<=list.clientWidth+1&&[...list.children].every(e=>{const r=e.getBoundingClientRect();return e.scrollWidth<=e.clientWidth+1&&r.left>=rect.left-1&&r.right<=rect.right+1;}),buttons:buttons.map(b=>{const r=b.getBoundingClientRect(),s=getComputedStyle(b);return [r.width,r.height,s.padding,s.fontSize,s.borderRadius];})};
 });
 check(layout.fits,'every result and metric fits without horizontal scrolling');
 check(layout.buttons.every(b=>Math.abs(b[0]-layout.buttons[0][0])<.1&&Math.abs(b[1]-layout.buttons[0][1])<.1&&JSON.stringify(b.slice(2))===JSON.stringify(layout.buttons[0].slice(2))),'recommended and other method buttons have identical geometry');
}
async function checkPair(page){
 const centered=await page.locator('.tunnel-pair').evaluateAll(pairs=>pairs.every(p=>{const a=p.children[0].getBoundingClientRect(),b=p.children[2].getBoundingClientRect(),icon=p.children[1].querySelector('svg').getBoundingClientRect();return Math.abs((icon.left+icon.right)/2-(a.left+b.right)/2)<1;}));
 check(centered,'connector stays at midpoint of the gap between differently sized server names');
}
async function checkTimes(page){
 check(await page.locator('.tunnel-host-checked .tunnel-checked-time').evaluateAll(rows=>rows.length>0&&rows.every(row=>{const label=row.children[0].getBoundingClientRect(),time=row.querySelector('time'),a=time.children[0].getBoundingClientRect(),b=time.children[2].getBoundingClientRect();return label.left>=time.getBoundingClientRect().right&&a.right<=b.left&&time.getAttribute('datetime').includes('T');})),'maintenance keeps label right and value left, with hour left of date regardless of RTL');
}
try{
 for(const width of [1280,1920,390]){
  const page=await context.newPage(),errors=[];await page.setViewportSize({width,height:1000});page.on('pageerror',e=>errors.push(e.message));
  await mount(page);
  check(await page.locator('[data-empty-action] [data-new-tunnel]').count()===1,'one empty-state create action');
  const reload=page.locator('[data-reload-tunnels]');
  check(await reload.innerText()===''&&await reload.locator('.fa-sync-alt').count()===1,'refresh is icon-only with accessible name');
  await page.evaluate(()=>{window.delayReload=true;});await reload.click();await reload.locator('.fa-spin').waitFor();
  check(await reload.isDisabled(),'refresh spins and disables duplicate clicks during the real request');
  await page.evaluate(()=>{window.delayReload=false;window.releaseReload();});await reload.locator('.fa-check').waitFor();
  check(await reload.getAttribute('aria-label')==='تازه‌سازی شد','successful refresh alone shows the check');
  await reload.locator('.fa-sync-alt:not(.fa-spin)').waitFor();check(await reload.isEnabled(),'check resets to refresh icon');
  await page.evaluate(()=>{window.failReload=true;});await reload.click();await page.waitForFunction(()=>messages.includes('خطای آزمایشی تازه‌سازی'));
  check(await reload.locator('.fa-check').count()===0&&await reload.isEnabled(),'failed refresh never shows success check');
  await page.evaluate(()=>{window.failReload=false;});
  await screenshot(page,'empty',width);await page.locator('[data-new-tunnel]').click();
  check(await page.locator('.infrastructure-modal.tunnel-modal').isVisible(),'creation uses actual shared modal');
  check(await page.locator('[data-test-form] .modal-fields').count()===1,'original glass field surface');
  check(await page.locator('.infrastructure-modal [data-hide-wizard]').count()===0,'no custom header close button');
  check(await page.locator('[data-test-form]>.form-actions [data-close]').innerText()==='انصراف','native cancel at bottom');
  check(await page.evaluate(()=>getComputedStyle(document.querySelector('#modalTitle')).textAlign)==='center','native centered title');
  const spacing=await page.evaluate(()=>{
    const form=document.querySelector('[data-test-form]'),surface=form.querySelector('.modal-fields').getBoundingClientRect(),intro=form.querySelector('.tunnel-help').getBoundingClientRect(),first=form.querySelector('.field').getBoundingClientRect(),toggle=form.querySelector('.switch-field').getBoundingClientRect(),note=form.querySelector('.tunnel-test-note span').getBoundingClientRect();
    return {top:intro.top-surface.top,gap:first.top-intro.bottom,center:Math.abs((note.top+note.bottom)/2-(toggle.bottom+surface.bottom)/2)};
  });
  check(spacing.top>=24&&spacing.top<=34,'intro has deliberate top inset');
  check(spacing.gap>=12&&spacing.gap<=24,'intro to fields is compact');
  check(spacing.center<4,'footer message vertically centered in remaining space');
  await screenshot(page,'create',width);await page.locator('[data-test-form] [data-close]').click();
  await page.locator('[data-new-tunnel]').click();
  await page.locator('#tunnel-iran').selectOption('1',{force:true});await page.locator('#tunnel-foreign').selectOption('1',{force:true});
  await page.locator('[data-test-form] button[type=submit]').click();check(await page.evaluate(()=>!calls.some(c=>c.url==='/tunnel-tests'&&c.method==='POST')),'reject same host before request');
  await page.locator('#tunnel-foreign').selectOption('2',{force:true});await page.locator('[data-test-form] button[type=submit]').click();
  await page.locator('[data-select-result="normal:reverse:pck"]').waitFor();check(await page.locator('[data-select-result="normal:reverse:wss"]').isDisabled(),'failed method disabled');
  await checkResults(page);
  check(await page.locator('#tunnel-iran').isHidden(),'completed test collapses setup to make room for results');
  await page.locator('[data-test-settings]').click();check(await page.locator('#tunnel-iran').isVisible(),'server settings can be reopened without losing results');await page.locator('[data-test-settings]').click();
  await page.locator('[data-result-filter=ok]').click();check(await page.locator('[data-select-result]').count()===1,'successful result filter remains functional');await page.locator('[data-result-filter=all]').click();
  await screenshot(page,'results',width);await page.locator('[data-select-result="normal:reverse:tcp"]').click();
  check(await page.locator('[name=accept_unstable]').evaluate(el=>el.required),'unstable needs acknowledgement');await page.locator('[data-back-results]').click();
  await page.locator('[data-select-result="normal:reverse:pck"]').click();
  check(await page.locator('[data-tunnel-setup] .modal-fields').count()===1,'setup also uses shared field surface');
  check(await page.locator('[data-back-results]').innerText()===''&&await page.locator('[data-back-results] svg').count()===1,'back action is an accessible icon inside selected-method banner');
  await page.locator('[name=public_host]').fill('ir.example.com');await page.locator('[data-map=target_port]').fill('5790');
  await page.locator('[data-add-mapping]').click();check(await page.locator('.tunnel-mapping-row').count()===2,'multiple port mappings');
  const removal=await page.locator('.tunnel-mapping-row').nth(1).evaluate(row=>{const button=row.querySelector('button'),r=button.getBoundingClientRect(),inputs=[...row.querySelectorAll('input')].map(i=>i.getBoundingClientRect());return {red:button.classList.contains('danger')&&getComputedStyle(button).backgroundColor==='rgba(255, 0, 0, 0.4)',height:inputs.every(i=>Math.abs(i.height-r.height)<1),aligned:inputs.every(i=>Math.abs(i.bottom-r.bottom)<1)};});
  check(removal.red&&removal.height,'remove mapping uses native red style and input height');if(width>540)check(removal.aligned,'remove mapping aligns with port input bottoms');
  await page.locator('[data-remove-map="1"]').click();
  await screenshot(page,'setup',width);check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'setup no page overflow');
  await page.locator('[data-tunnel-setup] button[type=submit]').click();await page.locator('[data-tunnel-card="1"]').waitFor();
  check(!(await page.locator('#modalBackdrop').isVisible()),'successful save closes actual modal');
  const create=await page.evaluate(()=>calls.find(c=>c.url==='/tunnels'&&c.method==='POST').payload);check(create.result_id==='normal:reverse:pck'&&create.mappings[0].target_port===5790,'preserve explicit method and mapping');
  await screenshot(page,'cards',width);
  await checkPair(page);check((await page.locator('[data-tunnel-card="1"] .tunnel-endpoint').innerText()).includes('ir.example.com:443'),'card groups public address and port with labelled node port');
  check(await page.locator('[data-tunnel-card="1"] .tunnel-endpoint').evaluate(e=>{const ip=e.querySelector('bdi').getBoundingClientRect(),port=e.querySelector('small').getBoundingClientRect(),row=e.getBoundingClientRect();return ip.right<port.left&&Math.abs(ip.left-row.left)<1&&Math.abs(port.right-row.right)<1&&e.querySelector('small').textContent.startsWith('پورت ')&&!e.textContent.includes('پورت نود');}),'public IP flush left, short port label flush right');
  check(await page.locator('[data-tunnel-card="1"] .tunnel-actions-button').evaluate(e=>getComputedStyle(e).backgroundColor===getComputedStyle(document.querySelector('[data-reload-tunnels]')).backgroundColor),'operations button has original shared secondary color');
  await action(page,'edit');check(await page.locator('[name=public_host]').inputValue()==='ir.example.com','edit preserves values');await page.keyboard.press('Escape');check(!(await page.locator('#modalBackdrop').isVisible()),'Escape closes shared modal');
  await action(page,'optimize');await page.locator('[data-maintenance-form] button[type=submit]:not([disabled])').waitFor();
  check(await page.locator('.tunnel-host-row').count()===2,'optimization offers exactly the tunnel endpoints');
  check((await page.locator('[data-maintenance-form]').innerText()).includes('نیاز به بهینه‌سازی'),'natural optimization label');
  await checkTimes(page);
  await page.locator('[name=side][value=foreign]').uncheck();await page.locator('[data-maintenance-form] button[type=submit]').click();
  await page.getByText('سرورهای انتخاب‌شده بهینه‌سازی شدند.',{exact:true}).waitFor();
  check(await page.evaluate(()=>calls.find(c=>c.payload?.action==='optimize').payload.sides.join(','))==='iran','only selected endpoint submitted');
  check((await page.locator('.tunnel-host-row').first().innerText()).includes('بهینه‌سازی شده'),'real returned state displayed');
  check((await page.locator('.tunnel-host-row').nth(1).innerText()).includes('نیاز به بهینه‌سازی'),'other endpoint stays unchanged');
  check(await page.locator('.tunnel-maintenance-reports details').count()===3,'real per-host output retained');
  await page.locator('.tunnel-maintenance-reports summary').first().click();
  check(await page.locator('.tunnel-maintenance-reports details').first().evaluate(d=>d.querySelector('pre').getBoundingClientRect().top-d.querySelector('summary').getBoundingClientRect().bottom>=11.5),'expanded optimization report separates heading from log box');
  await screenshot(page,'optimized',width);await page.locator('[data-maintenance-form] [data-close]').click();
  await action(page,'engine-update');await page.locator('[data-maintenance-form] button[type=submit]:not([disabled])').waitFor();
  check(await page.locator('.tunnel-host-row').count()===3,'update includes shared Iran other foreign peer');
  check((await page.locator('[data-maintenance-form]').innerText()).includes('نیازمند به‌روزرسانی'),'exact update-needed wording');
  await checkTimes(page);
  await screenshot(page,'update',width);await page.locator('[data-maintenance-form] button[type=submit]').click();
  await page.getByText('موتور سرورها به‌روزرسانی شد.',{exact:true}).waitFor();
  check((await page.locator('.tunnel-host-row').allInnerTexts()).every(t=>t.includes('به‌روز')),'all shared-host results become current');
  check(await page.locator('[data-maintenance-form] button[type=submit]').isDisabled(),'current version cannot be needlessly updated');
  await screenshot(page,'updated',width);check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'maintenance no page overflow');
  await page.locator('[data-maintenance-form] [data-close]').click();await page.locator('[data-tunnel-detail="1"]').click();
  check((await page.locator('#modalBody').innerText()).includes('سرور خارج'),'existing details retained');
  const nodes=await page.locator('.tunnel-node-grid').evaluate(grid=>{const fields=grid.querySelectorAll('.field'),buttons=grid.querySelectorAll(':scope>.button');return [...buttons].every((b,i)=>{const control=fields[i].querySelector('.invoice-custom-select').getBoundingClientRect(),button=b.getBoundingClientRect();return Math.abs(control.left-button.left)<1&&Math.abs(control.width-button.width)<1&&b.classList.contains('secondary');});});
  check(nodes,'both node buttons match their input widths, alignment and color');await screenshot(page,'details',width);await page.keyboard.press('Escape');
  await page.evaluate(()=>{tunnel.remote_status={iran:{active:true,checked_at:Math.floor(Date.now()/1000),metrics:{connected:true,bytes_in:1000,bytes_out:2000}},foreign:{active:true,checked_at:Math.floor(Date.now()/1000),metrics:{connected:true,bytes_in:1000,bytes_out:2000}}};});
  await page.locator('[data-reload-tunnels]').click();await page.locator('[data-tunnel-detail="1"]').click();
  check(await page.locator('.tunnel-end-status dl').evaluateAll(lists=>lists.every(dl=>{const time=dl.querySelector('time'),dt=time.closest('dd').previousElementSibling,other=dl.querySelectorAll('dd')[1];const a=time.children[0].getBoundingClientRect(),b=time.children[2].getBoundingClientRect();return dt.textContent==='آخرین بررسی'&&dt.getBoundingClientRect().left>time.getBoundingClientRect().right&&Math.abs(time.closest('dd').getBoundingClientRect().left-other.getBoundingClientRect().left)<1&&a.right<=b.left;})),'detail timestamp uses same label/value columns and hour before date');
  await screenshot(page,'detail-times',width);await page.keyboard.press('Escape');
  // Edit the same card using a test for its exact pair; drafts survive every round trip.
  await action(page,'edit');await page.locator('[name=name]').fill('نام حفظ‌شده');await page.locator('[data-change-method]').click();
  await page.locator('[data-method-picker] [data-select-result="normal:reverse:tcp"]').waitFor();
  check(await page.locator('#tunnel-iran').count()===0,'method picker does not ask for server selection again');
  check(await page.locator('[data-method-picker] [data-select-result="normal:reverse:wss"]').isDisabled(),'failed method still cannot be selected during editing');
  await page.locator('[data-method-retest]').click();await page.locator('[data-select-result="normal:reverse:tcp"]').waitFor();
  check(await page.evaluate(()=>{const c=calls.filter(c=>c.url==='/tunnel-tests'&&c.method==='POST').at(-1);return c.payload.iran_server_id===1&&c.payload.foreign_server_id===2&&c.payload.compare_both===true;}),'retest fixes both endpoints and includes reverse orientation');
  await screenshot(page,'method-picker',width);await page.locator('[data-select-result="normal:reverse:tcp"]').click();
  check(await page.locator('[name=name]').inputValue()==='نام حفظ‌شده'&&await page.locator('[data-map=target_port]').inputValue()==='5790','method choice preserves name and mappings');
  check(await page.locator('[name=accept_unstable]').evaluate(e=>e.required&&!e.checked),'unstable replacement needs a fresh explicit acknowledgement');
  await page.locator('[data-change-method]').click();await page.locator('[data-select-result="normal:reverse:pck"]').click();
  check(await page.locator('[name=name]').inputValue()==='نام حفظ‌شده','reopening the picker preserves the edit draft');
  await page.locator('[data-change-method]').click();await page.locator('[data-select-result="normal:reverse:tcp"]').click();await page.locator('[name=accept_unstable]').check();
  await page.locator('[data-tunnel-setup] button[type=submit]').click();await page.locator('[data-tunnel-card="1"]').waitFor();
  check(await page.evaluate(()=>{const c=calls.filter(c=>c.url==='/tunnels/1'&&c.method==='PUT').at(-1);return c.payload.test_id===7&&c.payload.result_id==='normal:reverse:tcp'&&c.payload.accept_unstable&&c.payload.name==='نام حفظ‌شده'&&!calls.some(c=>c.url==='/tunnels'&&c.method==='POST'&&c.payload.name==='نام حفظ‌شده');}),'replacement submits PUT to the same card with verified result reference');
  await page.waitForFunction(()=>document.querySelector('[data-tunnel-card="1"] .tunnel-method')?.textContent.includes('TCP'));
  check((await page.locator('[data-tunnel-card="1"] .tunnel-method').innerText()).includes('TCP'),'existing card reflects replacement method after accepted response');
  check(errors.length===0,'browser errors: '+errors.join('; '));await page.evaluate(()=>session.dispose());await page.close();
 }
 const page=await context.newPage();await mount(page,1);await page.locator('[data-new-tunnel]').click();check(await page.locator('[data-go-servers]').count()===1,'insufficient servers keeps navigation action');await page.evaluate(()=>session.dispose());await page.close();
 for(const width of [1280,390]){
  const previewPage=await context.newPage();await previewPage.setViewportSize({width,height:1000});
  await mount(previewPage,3,origin+'/?tunnel_preview=full');
  check(await previewPage.locator('.tunnel-preview-banner').isVisible(),'localhost preview has explicit demo banner');
  check(await previewPage.locator('[data-tunnel-card]').count()===4,'preview provides four diverse cards');
  await checkPair(previewPage);
  check(await previewPage.locator('[data-tunnel-card="601"] .tunnel-link.is-connected').count()===1&&await previewPage.locator('[data-tunnel-card="602"] .tunnel-link.is-disconnected').count()===1,'connected and disconnected cards use distinct status colors');
  check(await previewPage.evaluate(()=>calls.length===0),'opening preview makes no real API call');
  await screenshot(previewPage,'preview-cards',width);
    await previewPage.locator('[data-tunnel-card="601"] .tunnel-actions summary').click();await previewPage.locator('[data-tunnel-card="601"] [data-tunnel-action=optimize]').click();
  await previewPage.locator('[data-maintenance-form] button[type=submit]:not([disabled])').waitFor();
  await previewPage.locator('[data-maintenance-form] button[type=submit]').click();
  await previewPage.locator('.tunnel-operation-result').filter({hasText:'عملیات نمایشی انجام شد'}).waitFor();
  check(await previewPage.evaluate(()=>calls.length===0),'demo Optimize does not contact real API or servers');
  await previewPage.locator('[data-maintenance-form] [data-close]').click();
  await previewPage.locator('.tunnel-history summary').click();await previewPage.locator('[data-history="701"]').click();
  check(await previewPage.locator('[data-select-result]').count()===8,'demo exposes success, unstable, failed and skipped rows');
  await checkResults(previewPage);
  if(width>=1280)check(await previewPage.locator('.infrastructure-modal').evaluate(el=>el.getBoundingClientRect().height<=innerHeight-24),'all eight sample results fit the desktop viewport');
  await screenshot(previewPage,'preview-results',width);await previewPage.keyboard.press('Escape');
  await previewPage.locator('[data-tunnel-card="601"] .tunnel-actions summary').click();await previewPage.locator('[data-tunnel-card="601"] [data-tunnel-action=logs]').click();
  check(await previewPage.locator('.tunnel-log-columns section').evaluateAll(sections=>sections.every(s=>s.querySelector('pre').getBoundingClientRect().top-s.querySelector('h4').getBoundingClientRect().bottom>=11.5)),'both server report headings have a gap above log boxes');
  await screenshot(previewPage,'logs',width);await previewPage.keyboard.press('Escape');
  await previewPage.locator('[data-tunnel-detail="601"]').click();await previewPage.locator('[data-create-tunnel-node]').click();
  check(await previewPage.evaluate(()=>calls.length===0),'demo node action is intercepted too');
  await previewPage.evaluate(()=>session.dispose());await previewPage.close();
 }
 const externalPage=await context.newPage();
 await externalPage.route('http://panel.example.test/**',async route=>{
  const u=new URL(route.request().url());if(u.pathname==='/'){await route.fulfill({status:200,contentType:'text/html',body:html});return;}
  const p=path.resolve(root,'public','.'+u.pathname);
  if(!p.startsWith(path.join(root,'public')+path.sep)||!existsSync(p)){await route.fulfill({status:404,body:''});return;}
  await route.fulfill({status:200,contentType:p.endsWith('.js')?'text/javascript':p.endsWith('.css')?'text/css':'application/octet-stream',body:readFileSync(p)});
 });
 await mount(externalPage,3,'http://panel.example.test/?tunnel_preview=full');
 check(await externalPage.locator('.tunnel-preview-banner').count()===0,'preview is ignored on a non-local hostname');
 check(await externalPage.evaluate(()=>calls.some(c=>c.url==='/tunnels')),'normal API behavior remains on non-local hostname');
 await externalPage.evaluate(()=>session.dispose());await externalPage.close();
 console.log(`PASS: ${assertions} assertions using real infrastructure modal at 1280, 1920 and 390px`);
}finally{await browser.close();await new Promise(r=>server.close(r));}
