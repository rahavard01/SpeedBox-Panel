// Shipped production Vue assets in jsdom; mocked API only. No live panel access.
// node --experimental-vm-modules this-file.mjs; optional SPEEDBOX_TEST_JSDOM.
import { createServer } from 'node:http';
import { readFileSync, existsSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import assert from 'node:assert/strict';
import vm from 'node:vm';
const { JSDOM, VirtualConsole } = await import(process.env.SPEEDBOX_TEST_JSDOM || 'jsdom');
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const manifest = JSON.parse(readFileSync(path.join(root, 'public/build/manifest.json')));
const requests = [];
let failReset=true;
const account = { id: 1, email: 'fixture@uxrep.com', token: '1234567890abcdef1234567890abcdef', subscribe_url: 'https://fixture.test/hooked/1234567890abcdef1234567890abcdef/client?sig=a%2Bb&x=1#tail', plan_id: 1, group_id: 1, u: 0, d: 1073741824, t: Math.floor(Date.now()/1000), transfer_enable: 10737418240, expired_at: Math.floor(Date.now()/1000)+864000, banned: 0, device_limit: 2, speed_limit: 0, reset_count: 1, account_plan_name: 'طرح ۱', account_group_type: 'time', account_tariff_group_id: 1, account_plan_key: '1m', created_at: Math.floor(Date.now()/1000), updated_at: Math.floor(Date.now()/1000) };
const groups = Array.from({length:30}, (_,i)=>({id:i+1,name:`طرح ${i+1}`,group_type:'time',is_default:i===0?1:0,enable:1,items:['test','1m','3m','6m','12m'].map(plan_key=>({plan_key,enable:1,v2_plan_id:1,default_price:100})),plan_enabled:{test:true,'1m':true,'3m':true,'6m':true,'12m':true,'1m_noexp':true}}));
const entry=manifest['resources/js/app.js'];
const scripts=['node-editor','emergency-configs','infrastructure','register-infrastructure','panel-tooltip','partner-statistics'];
const html='<!doctype html><html lang="fa"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'+entry.css.map(file=>`<link rel="stylesheet" href="/build/${file}">`).join('')+['panel-controls','bot-faqs','branding','infrastructure','emergency-configs','partner-statistics'].map(name=>`<link rel="stylesheet" href="/speedbox/${name}.css">`).join('')+'</head><body><div id="app"></div><script id="__BOOTSTRAP__" type="application/json">{}</script>'+scripts.map(name=>`<script src="/speedbox/${name}.js"></script>`).join('')+`<script type="module" src="/build/${entry.file}"></script></body></html>`;
const server=createServer(async(req,res)=>{
  const url=new URL(req.url,'http://fixture.test');
  if(url.pathname.startsWith('/api/')){
    let raw='';for await(const chunk of req)raw+=chunk;let body={};try{body=JSON.parse(raw)}catch{}
    requests.push({path:url.pathname,method:req.method,body});
    const admin=String(req.headers.authorization||'').includes('admin');const user={id:admin?9:8,role:admin?1:2,name:'Fixture',email:'fixture@panel.test',code:'uxrep',credit:1000000,partner_plan_type:'both'};
    let data={data:[],count:0,status:'success'},status=200;const p=url.pathname.slice(4);
    if(p==='/user/panel')data={status:'success',user};
    else if(p==='/admin/users'||p==='/users')data=[account];
    else if(p==='/admin/tariffs')data={groups,volume_groups:[{id:40,name:'طرح حجمی',enable:1,min_traffic_gb:10,volume_item:{enable:1,v2_plan_id:1}}]};
    else if(p==='/panel/plans')data={plan_groups:[...groups,{id:40,name:'طرح حجمی',group_type:'volume',min_traffic_gb:10,plan_enabled:{volume_gig:true}}]};
    else if(p==='/settings/link-parts')data={data:{part1:'https://fixture.test',part2:'s',part3:''}};
    else if(p==='/users/by-email')data={data:[account]};
    else if(p.endsWith('/online'))data={data:(body.ids||[1]).map(id=>({id,online_count:1,device_limit:2}))};
    else if(p.endsWith('/details'))data={data:account,plans:[{id:1,name:'طرح ۱',speed_limit:0,device_limit:2}],panel_plan_id:1};
    else if(p.endsWith('/reset-history'))data={data:[{id:1,reset_type:'manual',trigger_source:'manual',reset_time:'2026-10-16 00:00:00',old_total:1073741824,reason:'درخواست کاربر <script>window.badResetReason=true</script>'}],user:account,account,can_reset:true,pagination:{page:1,last_page:1},meta:{current_page:1,last_page:1}};
    else if(req.method==='PATCH'&&p.endsWith('/1')){status=422;data={message:'این نام کاربری تکراری است',errors:{email:['این نام کاربری تکراری است']}};}
    else if(p.endsWith('/reset-traffic')){if(failReset){status=422;data={message:'خطای آزمایشی بازنشانی'};}else{account.reset_count=1;account.last_reset_at=Math.floor(Date.now()/1000);account.u=0;account.d=0;data={message:'بازنشانی انجام شد',data:account};}}
    else if(p.endsWith('/users/create'))data={status:'success',created:[{...account,id:2,email:(body.custom_username||'random')+'@uxrep.com'}],errors:[]};
    res.writeHead(status,{'Content-Type':'application/json'});res.end(JSON.stringify(data));return;
  }
  const file=path.resolve(root,'public','.'+decodeURIComponent(url.pathname));
  if(file.startsWith(path.join(root,'public')+path.sep)&&existsSync(file)&&path.extname(file)){
    const type={'.js':'text/javascript','.css':'text/css','.woff2':'font/woff2','.png':'image/png','.ico':'image/x-icon'}[path.extname(file)]||'application/octet-stream';res.writeHead(200,{'Content-Type':type});res.end(readFileSync(file));return;
  }
  res.writeHead(200,{'Content-Type':'text/html; charset=utf-8'});res.end(html);
});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
const origin=`http://127.0.0.1:${server.address().port}`;
let checks=0;const check=(value,label)=>{assert.ok(value,label);checks++};
const sleep=ms=>new Promise(resolve=>setTimeout(resolve,ms));
try{
 for(const width of [1280,390])for(const role of ['admin','partner']){
  failReset=true;account.reset_count=0;account.last_reset_at=null;account.next_reset_at=Math.floor(Date.now()/1000)+86400;
  const errors=[];const virtualConsole=new VirtualConsole();virtualConsole.on('error',(...values)=>errors.push(values.map(String).join(' ')));virtualConsole.on('jsdomError',e=>{if(e.type!=='css-parsing'&&!e.message.includes('CSS stylesheet'))errors.push(e.message)});
  const dom=new JSDOM(html,{url:origin+(role==='admin'?'/admin/users':'/panel/users'),runScripts:'outside-only',pretendToBeVisual:true,resources:'usable',virtualConsole});
  const win=dom.window;const doc=win.document;const context=dom.getInternalVMContext();let copied='';const requestStart=requests.length;
  Object.defineProperty(win.navigator,'clipboard',{value:{writeText:async value=>{copied=value}}});
  win.matchMedia=()=>({matches:width<768,addEventListener(){},removeEventListener(){}});Object.defineProperty(win,'innerWidth',{value:width});win.AbortController=AbortController;win.fetch=fetch;
  win.ResizeObserver=class{observe(){}disconnect(){}unobserve(){}};
  win.scrollTo=()=>{};
  win.HTMLCanvasElement.prototype.toDataURL=function(){return 'data:image/png;base64,VEVTVA=='};
  win.HTMLCanvasElement.prototype.getContext=function(){return new Proxy({canvas:this,createImageData:(w,h)=>({width:w,height:h,data:new Uint8ClampedArray(w*h*4)})},{get:(target,key)=>key in target?target[key]:key==='measureText'?()=>({width:10}):key==='createLinearGradient'||key==='createRadialGradient'?()=>({addColorStop(){}}):()=>{},set:(target,key,value)=>(target[key]=value,true)})};
  win.localStorage.setItem('panel_seen_version','2.2');win.localStorage.setItem('token','fixture-'+role);win.localStorage.setItem('user',JSON.stringify({id:role==='admin'?9:8,role:role==='admin'?1:2,code:'uxrep'}));
  for(const name of scripts)vm.runInContext(readFileSync(path.join(root,'public/speedbox/'+name+'.js'),'utf8'),context);
  const modules=new Map();
  async function load(file){
   file=path.basename(file);if(modules.has(file))return modules.get(file);
   const source=readFileSync(path.join(root,'public/build/assets',file),'utf8');
   const module=new vm.SourceTextModule(source,{context,initializeImportMeta:meta=>meta.url=origin+'/build/assets/'+file,importModuleDynamically:async specifier=>{const dep=await load(specifier);if(dep.status==='linked')await dep.evaluate();return dep;}});
   modules.set(file,module);await module.link(specifier=>load(specifier));return module;
  }
  const entryModule=await load(entry.file);await entryModule.evaluate();
  async function wait(condition,label){const deadline=Date.now()+15000;while(Date.now()<deadline){const value=condition();if(value)return value;await sleep(20)}throw Error('Timed out '+label+'; '+errors.join('; ')+'; '+doc.body.textContent.slice(-1200));}
  const visible=el=>{for(let n=el;n;n=n.parentElement)if(win.getComputedStyle(n).display==='none')return false;return true};
  const button=(label,within=doc)=>[...within.querySelectorAll('button')].find(el=>visible(el)&&el.textContent.trim()===label);
  await wait(()=>doc.querySelector('.btn-create-user'),'users page');
  const releaseModal=await wait(()=>doc.querySelector('.update-modal'),'release 3.0 modal');
  check(releaseModal.querySelector('.update-version-badge').textContent.trim()==='نسخه 3.0','Upgrade popup shows version 3.0');
  const releaseLines=[...releaseModal.querySelectorAll('.update-modal-list li>span:last-child')].map(n=>n.textContent.trim());
  check(JSON.stringify(releaseLines)===JSON.stringify(["مدیریت کاربران، نمایندگان، اشتراک‌ها و سرورها در یک پروژه", "نمایش تعداد IPهای آنلاین در بخش‌های مدیریت کاربران و لیست اشتراک؛ همراه بهبود نمایش اطلاعات داشبورد.", "مشاهدهٔ سوابق بازنشانی ترافیک، نمایش تعداد و زمان آخرین و بازنشانی بعدی.", "ساخت کاربر با نام کاربری دلخواه", "تنظیم و مدیریت سوالات متداول ربات از داخل پنل برای هر نماینده"]),'Only the five approved release notes are shown');
  releaseModal.querySelector('[aria-label="بستن"]').click();await sleep(20);
  check(!doc.querySelector('.update-modal')&&win.localStorage.getItem('panel_seen_version')==='3.0','Dismissing release 3.0 stores the new seen version');

  if(role==='admin'){const expiry=await wait(()=>doc.querySelector('.users-table .expiry-badge'),'admin expiry badge');check(win.getComputedStyle(expiry).fontSize==='11px','Admin expiry date font is slightly larger');}
  button('ساخت کاربر جدید').click();const select=await wait(()=>doc.getElementById(role+'-create-tariff'),'create dropdown');
  if(role==='admin'){
   const codeToggle=doc.getElementById('admin-custom-code-toggle');check(!!codeToggle&&!codeToggle.checked&&!doc.getElementById('admin-custom-code'),'Custom panel code defaults off');codeToggle.click();await sleep(20);const code=doc.getElementById('admin-custom-code');check(!!code&&!code.hasAttribute('placeholder'),'Custom panel code reveals styled input');code.value='custom9';code.dispatchEvent(new win.Event('input',{bubbles:true}));codeToggle.click();await sleep(20);check(!doc.getElementById('admin-custom-code'),'Turning off custom code hides and clears input');codeToggle.click();await sleep(20);check(doc.getElementById('admin-custom-code').value==='','Custom code does not retain a stale value');
  }else check(!doc.getElementById('admin-custom-code-toggle'),'Custom panel code is admin-only');
  const createForm=select.closest('.account-create-form');const actions=createForm.querySelector(':scope > .create-actions');const back=createForm.querySelector(':scope > .back-button');check(win.getComputedStyle(actions).marginTop===win.getComputedStyle(back).marginTop,'Create action and Back use the same gap');check(win.getComputedStyle(actions.querySelector('.btn-create-user1')).marginTop==='0px'&&win.getComputedStyle(back.querySelector('a')).marginTop==='0px','Legacy button and link margins do not stack');check(win.getComputedStyle(select.closest('.account-plan-select')).marginBottom==='0px','Selector adds no extra bottom gap');
  check(select.textContent.includes('انتخاب طرح'),'Create starts at placeholder');check(!select.closest('.account-plan-select').querySelector('label'),'Create has no duplicate plan label');check(select.closest('.account-plan-select').classList.contains('account-plan-select--compact'),'Only create selector uses compact width');check(win.getComputedStyle(select).backgroundImage.includes('linear-gradient'),'Selector retains gradient styling');check(doc.querySelector('.create-modal .btn-create-user1').disabled,'Create waits for selection');
  select.click();await wait(()=>doc.querySelector('[role="listbox"][aria-label="انتخاب طرح"]'),'dropdown list');
  check(doc.querySelectorAll('[role="option"]').length>=31,'Long list includes all plans');check(parseInt(doc.querySelector('[role="listbox"]').style.maxHeight)<=260,'Dropdown height is bounded');
  doc.querySelector('[role="listbox"]').dispatchEvent(new win.KeyboardEvent('keydown',{key:'ArrowDown',bubbles:true}));
  check(doc.activeElement.getAttribute('role')==='option','Keyboard navigation focuses an option');
  [...doc.querySelectorAll('[role="option"]')].find(el=>el.textContent==='طرح 2').click();await sleep(30);
  button('اکانت یک ماهه').click();doc.getElementById(role+'-custom-username-toggle').click();await sleep(20);
  if(role==='admin'){const code=doc.getElementById('admin-custom-code');code.value='custom9';code.dispatchEvent(new win.Event('input',{bubbles:true}));}const input=doc.getElementById(role+'-custom-username');check(!input.hasAttribute('placeholder')&&input.getAttribute('aria-label')==='نام کاربری دلخواه','Username has no placeholder and retains accessible name');check(!input.parentElement.querySelector('label,small'),'Extra username label and suffix note removed');check(win.getComputedStyle(input).height==='36px'&&win.getComputedStyle(input).borderTopWidth==='1px','Username input has explicit matching form styling');check(win.getComputedStyle(doc.getElementById(role+'-custom-username-toggle')).borderRadius==='5px','Username checkbox has custom styling');input.value='alice';input.dispatchEvent(new win.Event('input',{bubbles:true}));
  await sleep(20);doc.querySelector('.create-modal .btn-create-user1').click();await wait(()=>doc.querySelector('.final-username'),'creation result');
  check(requests.some(r=>r.path.endsWith('/users/create')&&r.body.custom_username==='alice'&&r.body.tariff_group_id===2&&r.body.panel_code===(role==='admin'?'custom9':'uxrep')),'Named creation sends prefix and selected tariff');
  const createdLink=await wait(()=>[...doc.querySelectorAll('.final-link')].find(el=>visible(el)&&el.textContent.trim()),'created subscription link');check(createdLink?.textContent.trim()===account.subscribe_url,'Create preserves complete hooked subscription URL');createdLink.click();await sleep(20);check(copied===account.subscribe_url,'Create copies complete URL with encoded query and suffix');check([...doc.querySelectorAll('.final-qr img')].some(visible),'Creation QR is rendered');
  [...doc.querySelectorAll('.modal-backdrop')].at(-1).click();await sleep(30);button('ساخت کاربر جدید').click();await wait(()=>doc.getElementById(role+'-create-tariff'),'reopened creation form');
  check(doc.getElementById(role+'-create-tariff').textContent.includes('انتخاب طرح')&&!doc.getElementById(role+'-custom-username-toggle').checked,'Create reopens with empty selection');if(role==='admin')check(!doc.getElementById('admin-custom-code-toggle').checked&&!doc.getElementById('admin-custom-code'),'Custom panel code resets on reopen');
  [...doc.querySelectorAll('.modal-backdrop')].at(-1).click();await sleep(30);button('مشاهده').click();await wait(()=>button('تمدید اکانت'),'account details');const viewLink=await wait(()=>[...doc.querySelectorAll('.final-link')].find(el=>visible(el)&&el.textContent.trim()),'account subscription link');check(viewLink.textContent.trim()===account.subscribe_url,'View preserves complete hooked URL');viewLink.click();await sleep(20);check(copied===account.subscribe_url,'View copies complete URL');await wait(()=>[...doc.querySelectorAll('.final-qr img')].find(visible),'view QR');check(!requests.slice(requestStart).some(r=>r.path==='/api/settings/link-parts'),'No obsolete link reconstruction request');button('تمدید اکانت').click();await sleep(30);
  check(doc.getElementById(role+'-renew-tariff').textContent.includes('انتخاب طرح'),'Renewal starts at placeholder');const renewPane=doc.getElementById(role+'-renew-tariff').closest('.account-renew-pane');check(win.getComputedStyle(renewPane).rowGap==='16px','Renewal uses consistent spacing');check(win.getComputedStyle(renewPane.querySelector('.create-actions')).marginTop==='0px','Renewal action has no stacked top margin');const outer=win.getComputedStyle(renewPane.closest('.final-result'));const upperGap=(parseFloat(outer.rowGap)||parseFloat(outer.gap))+parseFloat(win.getComputedStyle(renewPane).marginTop);const lowerGap=parseFloat(win.getComputedStyle(renewPane).rowGap)+parseFloat(win.getComputedStyle(renewPane.querySelector('.create-actions>.btn-create-user1')).marginTop);check(upperGap===lowerGap,'Renew action gap equals selector-to-divider gap');check(!doc.getElementById(role+'-renew-tariff').closest('.account-plan-select').querySelector('label'),'Renewal has no duplicate label');check(!doc.getElementById(role+'-renew-tariff').closest('.account-plan-select').classList.contains('account-plan-select--compact'),'Renewal width is preserved');
  doc.getElementById(role+'-renew-tariff').click();await sleep(20);[...doc.querySelectorAll('[role="option"]')].find(el=>el.textContent==='طرح 3').click();await sleep(30);button('یک ماهه').click();await sleep(20);check(!button('تمدید').disabled,'Renewal enables after tariff and duration');[...doc.querySelectorAll('.modal-backdrop')].at(-1).click();await sleep(30);button('مشاهده').click();await wait(()=>button('تمدید اکانت'),'account reopen');button('تمدید اکانت').click();await sleep(30);
  check(doc.getElementById(role+'-renew-tariff').textContent.includes('انتخاب طرح'),'Renewal reopens with empty selection');
  [...doc.querySelectorAll('.modal-backdrop')].at(-1).click();await sleep(30);
  if(role==='admin'){
   doc.querySelector('[aria-label="عملیات اکانت"]').click();await sleep(20);button('ویرایش').click();const email=await wait(()=>doc.getElementById('account-email-1'),'edit dialog');email.value='duplicate@uxrep.com';email.dispatchEvent(new win.Event('input',{bubbles:true}));await sleep(20);[...doc.querySelectorAll('button')].find(el=>visible(el)&&el.textContent.includes('ذخیره')).click();
   const toast=await wait(()=>doc.querySelector('.toast-container .error-message'),'modal error toast');
   check(toast.closest('.toast-container').parentElement===doc.body,'Modal error toast is teleported directly into body');
   check(!toast.closest('.account-operation-overlay'),'Modal error is outside the blurred backdrop');check(Number(win.getComputedStyle(doc.documentElement).getPropertyValue('--toast-z'))>13000,'Toast layer is above modal backdrop');
   button('انصراف').click();await sleep(30);doc.querySelector('[aria-label="عملیات اکانت"]').click();await sleep(20);button('بازنشانی ترافیک').click();await wait(()=>button('تاریخچه بازنشانی ترافیک'),'reset dialog loaded');
   const historyButton=[...doc.querySelectorAll('button')].find(el=>visible(el)&&el.textContent.trim()==='تاریخچه بازنشانی ترافیک');historyButton.click();await sleep(30);
   const summary=await wait(()=>doc.querySelector('.reset-summary'),'fresh reset summary');check(summary.textContent.includes('آخرین بازنشانی')&&summary.textContent.includes('تعداد بازنشانی')&&summary.querySelector('.reset-summary-count').textContent==='۰'&&summary.querySelectorAll('dd')[1].textContent==='—'&&summary.textContent.includes('بازنشانی دوره‌ای بعدی'),'Fresh account shows zero count, dash for last reset and scheduled next reset');
   const reasonBox=await wait(()=>doc.querySelector('.reset-log-reason'),'reason for spacing check');check(parseFloat(win.getComputedStyle(reasonBox).marginBottom)>=10,'Reset reason has a visible gap before badges');
   button('بازنشانی ترافیک',doc.querySelector('.operation-tabs')).click();await sleep(20);
   const textarea=doc.querySelector('.account-operation-modal textarea');textarea.value='حفظ دلیل برای تلاش مجدد';textarea.dispatchEvent(new win.Event('input',{bubbles:true}));await sleep(20);
   button('بازنشانی ترافیک',doc.querySelector('.account-operation-modal footer')).click();await wait(()=>requests.slice(requestStart).some(r=>r.path.endsWith('/reset-traffic')),'reset failure');await sleep(50);
   check(!!doc.querySelector('.account-operation-overlay')&&textarea.value==='حفظ دلیل برای تلاش مجدد','Failed reset keeps dialog and reason');
   const failedToast=[...doc.querySelectorAll('.toast-container .error-message')].find(el=>el.textContent.includes('خطای آزمایشی'));check(!!failedToast&&!failedToast.closest('.account-operation-overlay'),'Reset error is visible outside backdrop');
   failReset=false;const beforeRefresh=requests.length;button('بازنشانی ترافیک',doc.querySelector('.account-operation-modal footer')).click();await wait(()=>!doc.querySelector('.account-operation-overlay'),'successful reset closes modal');
   check(!doc.body.classList.contains('account-operation-open'),'Successful reset releases body modal state');await wait(()=>requests.slice(beforeRefresh).some(r=>r.path==='/api/admin/users'),'users refresh');
   doc.querySelector('[aria-label="عملیات اکانت"]').click();await sleep(20);button('بازنشانی ترافیک').click();await wait(()=>button('تاریخچه بازنشانی ترافیک'),'reopened reset form');button('تاریخچه بازنشانی ترافیک').click();

  }else{
   [...doc.querySelectorAll('[aria-haspopup="menu"]')].at(-1).click();await sleep(20);button('تاریخچه بازنشانی ترافیک').click();
  }
  const reason=await wait(()=>doc.querySelector('.reset-log-reason'),'reset reason');check(reason.textContent.includes('<script>')&&!reason.querySelector('script')&&!win.badResetReason,'Reset reason renders as escaped text');
  if(role==='partner'){const summary=await wait(()=>doc.querySelector('.reset-summary'),'partner fresh summary');check(summary.textContent.includes('تعداد بازنشانی')&&summary.textContent.includes('آخرین بازنشانی')&&summary.querySelector('.reset-summary-count').textContent==='۰'&&summary.querySelectorAll('dd')[1].textContent==='—'&&summary.textContent.includes('بازنشانی دوره‌ای بعدی'),'Partner fresh summary shows zero count, dash and scheduled next reset');check(parseFloat(win.getComputedStyle(reason).marginBottom)>=10,'Partner reason spacing matches admin');}
  check(!!reason.querySelector('i.fa-info-circle')&&reason.querySelector('i').getAttribute('aria-hidden')==='true','Reset reason has a decorative information icon');
  check(win.getComputedStyle(reason).color==='rgb(245, 212, 154)','Reset reason matches the attention text color');
  check(errors.length===0,'No DOM runtime errors: '+errors.join('; '));
  process.stdout.write(`USER EXPERIENCE UI OK: ${role}, ${width}px\n`);dom.window.close();
 }
 process.stdout.write(`USER EXPERIENCE UI OK: ${checks} checks; shipped Vue assets, desktop/mobile DOM and mocked API only.\n`);
}finally{await new Promise(resolve=>server.close(resolve));}
