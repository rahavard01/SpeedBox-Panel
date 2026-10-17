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
let presence={usersOnline:1,onlineIps:2};
function initialCards(){
 const comparison={today:0,yesterday:0,vsYesterdayPct:0,thisMonth:0,prevMonth:0,vsPrevMonthPct:0};
 return {panelStatus:{partnersTotal:0,usersTotal:0,usersOnline:9,onlineIps:9,usersExpired:0},newUsers:{...comparison},renewals:{...comparison},wallet:{thisMonthToman:0,vsPrevMonthPct:0},deposits:{thisMonthToman:0,prevMonthToman:0,vsPrevMonthPct:0},pending:{deposits:0,partnerRequests:0},speedboxActive:{now:0,last24h:0},speedboxActiveAndroid:{now:0,last24h:0},meta:{generatedAt:'',pollSeconds:60}};
}
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
    if(p==='/panel/dashboard/online')data={data:presence};
    else if(p.endsWith('/dashboard/cards'))data=initialCards(admin?'admin':'partner');
    else if(p==='/user/panel')data={status:'success',user};
    else if(p==='/admin/users'||p==='/users')data=[account];
    else if(p==='/admin/tariffs')data={groups,volume_groups:[{id:40,name:'طرح حجمی',enable:1,min_traffic_gb:10,volume_item:{enable:1,v2_plan_id:1}}]};
    else if(p==='/panel/plans')data={plan_groups:[...groups,{id:40,name:'طرح حجمی',group_type:'volume',min_traffic_gb:10,plan_enabled:{volume_gig:true}}]};
    else if(p==='/settings/link-parts')data={data:{part1:'https://fixture.test',part2:'s',part3:''}};
    else if(p==='/users/by-email')data={data:[account]};
    else if(p.endsWith('/online'))data={data:(body.ids||[1]).map(id=>({id,online_count:1,device_limit:2}))};
    else if(p.endsWith('/details'))data={data:account,plans:[{id:1,name:'طرح ۱',speed_limit:0,device_limit:2}],panel_plan_id:1};
    else if(p.endsWith('/reset-history'))data={data:[{id:1,reset_type:'manual',trigger_source:'manual',reset_time:'2026-10-16 00:00:00',old_total:1073741824,reason:'درخواست کاربر <script>window.badResetReason=true</script>'}],user:account,account,can_reset:true,pagination:{page:1,last_page:1},meta:{current_page:1,last_page:1}};
    else if(req.method==='PATCH'&&p.endsWith('/1')){status=422;data={message:'این نام کاربری تکراری است',errors:{email:['این نام کاربری تکراری است']}};}
    else if(p.endsWith('/reset-traffic')){status=422;data={message:'خطای آزمایشی بازنشانی'};}
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
  presence={usersOnline:1,onlineIps:2};
  const errors=[];const virtualConsole=new VirtualConsole();virtualConsole.on('jsdomError',e=>{if(e.type!=='css-parsing'&&!e.message.includes('CSS stylesheet'))errors.push(e.message)});
  virtualConsole.on('error',(...args)=>errors.push(args.map(String).join(' ')));
  const dom=new JSDOM(html,{url:origin+(role==='admin'?'/admin':'/panel/dashboard'),runScripts:'outside-only',pretendToBeVisual:true,resources:'usable',virtualConsole});
  const win=dom.window;const doc=win.document;const context=dom.getInternalVMContext();let copied='';const requestStart=requests.length;
  Object.defineProperty(win.navigator,'clipboard',{value:{writeText:async value=>{copied=value}}});
  win.matchMedia=()=>({matches:width<768,addEventListener(){},removeEventListener(){}});Object.defineProperty(win,'innerWidth',{value:width});win.AbortController=AbortController;win.fetch=fetch;
  win.ResizeObserver=class{observe(){}disconnect(){}unobserve(){}};
  win.scrollTo=()=>{};const originalTimeout=win.setTimeout.bind(win);win.setTimeout=(fn,delay,...args)=>originalTimeout(fn,delay===5000?25:delay,...args);win.localStorage.setItem('panel_seen_version','3.0');
  win.HTMLCanvasElement.prototype.toDataURL=function(){return 'data:image/png;base64,VEVTVA=='};
  win.HTMLCanvasElement.prototype.getContext=function(){return new Proxy({canvas:this,createImageData:(w,h)=>({width:w,height:h,data:new Uint8ClampedArray(w*h*4)})},{get:(target,key)=>key in target?target[key]:key==='measureText'?()=>({width:10}):key==='createLinearGradient'||key==='createRadialGradient'?()=>({addColorStop(){}}):()=>{},set:(target,key,value)=>(target[key]=value,true)})};
  win.localStorage.setItem('token','fixture-'+role);win.localStorage.setItem('user',JSON.stringify({id:role==='admin'?9:8,role:role==='admin'?1:2,code:'uxrep'}));
  for(const name of scripts)vm.runInContext(readFileSync(path.join(root,'public/speedbox/'+name+'.js'),'utf8'),context);
  const modules=new Map();
  async function load(file){
   file=path.basename(file);if(modules.has(file))return modules.get(file);
   const source=readFileSync(path.join(root,'public/build/assets',file),'utf8');
   const module=new vm.SourceTextModule(source,{context,initializeImportMeta:meta=>meta.url=origin+'/build/assets/'+file,importModuleDynamically:async specifier=>{const dep=await load(specifier);if(dep.status==='linked')await dep.evaluate();return dep;}});
   modules.set(file,module);await module.link(specifier=>load(specifier));return module;
  }
  const entryModule=await load(entry.file);await entryModule.evaluate();
  async function wait(condition,label){const deadline=Date.now()+5000;while(Date.now()<deadline){const value=condition();if(value)return value;await sleep(20)}throw Error('Timed out '+label+'; '+errors.join('; ')+'; '+doc.body.textContent.slice(-1200));}
  const visible=el=>{for(let n=el;n;n=n.parentElement)if(win.getComputedStyle(n).display==='none')return false;return true};
  const button=(label,within=doc)=>[...within.querySelectorAll('button')].find(el=>visible(el)&&el.textContent.trim()===label);
  const label=()=>doc.querySelector('.speedbox-dashboard-online-tip .kpi-value');
  await wait(()=>label()?.textContent.trim()==='۱','live online count replaces stale financial snapshot');
  check(label().textContent.trim()==='۱','One user displayed from live endpoint');
  check(!doc.querySelector('a[href="/admin/app-settings"]'),'Private app menu absent');
  check(!doc.body.textContent.includes('SPEEDBOX-Windows')&&!doc.body.textContent.includes('SPEEDBOX-Android'),'Private app cards absent');
  const trigger=doc.querySelector('.speedbox-dashboard-online-tip .custom-tooltip-trigger');
  if(width<768)trigger.click();else trigger.closest('.custom-tooltip-wrapper').dispatchEvent(new win.MouseEvent('mouseenter'));
  await wait(()=>doc.querySelector('.speedbox-dashboard-online-tip [role="tooltip"]'),'online IP tooltip');
  check(doc.querySelector('.speedbox-dashboard-online-tip [role="tooltip"]').textContent.includes('۲'),'Tooltip uses live IP count');
  presence={usersOnline:0,onlineIps:0};
  await wait(()=>label().textContent.trim()==='۰','disconnect shows zero without reloading page');
  check(doc.querySelector('.speedbox-dashboard-online-tip [role="tooltip"]').textContent.includes('۰'),'Zero IP tooltip updates reactively');
  presence={usersOnline:2,onlineIps:3};win.dispatchEvent(new win.Event('focus'));
  await wait(()=>label().textContent.trim()==='۲','focus refresh and reconnect');
  check(doc.querySelector('.speedbox-dashboard-online-tip [role="tooltip"]').textContent.includes('۳'),'User and IP counts stay separate');
  check(requests.slice(requestStart).filter(r=>r.path.endsWith('/dashboard/cards')).length===1,'Fast polling does not reload financial cards');
  let hidden=true;Object.defineProperty(doc,'hidden',{configurable:true,get:()=>hidden});doc.dispatchEvent(new win.Event('visibilitychange'));await sleep(50);
  const calls=requests.slice(requestStart).filter(r=>r.path==='/api/panel/dashboard/online').length;await sleep(80);
  check(requests.slice(requestStart).filter(r=>r.path==='/api/panel/dashboard/online').length===calls,'Hidden dashboard stops polling');
  presence={usersOnline:0,onlineIps:0};hidden=false;doc.dispatchEvent(new win.Event('visibilitychange'));
  await wait(()=>label().textContent.trim()==='۰','visible dashboard refreshes immediately');
  check(errors.length===0,'No production Vue/DOM errors: '+errors.join('; '));
  console.log(`ONLINE PRESENCE UI OK: ${role}, ${width}px`);dom.window.close();
 }
 // Mount the shipped node list itself, with deterministic API snapshots.
 {
  const dom=new JSDOM('<!doctype html><body><div id="nodes-test"></div></body>',{url:origin,runScripts:'outside-only',pretendToBeVisual:true});
  const win=dom.window,doc=win.document,context=dom.getInternalVMContext();
  const template=readFileSync(path.join(root,'resources/views/infrastructure.blade.php'),'utf8').match(/<template[^>]*>([\s\S]*?)<\/template>/)[1];
  const host=doc.getElementById('nodes-test');host.innerHTML=template;
  win.matchMedia=()=>({matches:false,addEventListener(){},removeEventListener(){}});win.AbortController=AbortController;
  win.localStorage.setItem('token','fixture-admin');win.localStorage.setItem('user',JSON.stringify({role:1}));
  let count=2,calls=0;const notices=[];
  const normalTimeout=win.setTimeout.bind(win);win.setTimeout=(fn,delay,...args)=>normalTimeout(fn,delay===5000?25:delay,...args);
  win.fetch=async url=>{const nodes=String(url).endsWith('/nodes');if(nodes)calls++;return {ok:true,status:200,json:async()=>({data:nodes?[{id:1,name:'Fixture',type:'vless',host:'node.test',port:443,enabled:true,show:true,kernel:'xray',machine_id:1,runtime:{online:false,connected:false,online_users:count}}]:[]})}};
  for(const name of ['node-editor','infrastructure'])vm.runInContext(readFileSync(path.join(root,'public/speedbox/'+name+'.js'),'utf8'),context);
  const mounted=win.SpeedboxInfrastructure.mount(host,{router:{replace(){throw Error('Unexpected auth redirect')},push(){}},notify:(...args)=>notices.push(args),view:'nodes'});
  const cell=()=>host.querySelector('[data-node-row="1"] td:nth-child(6)');
  async function waitNode(expected){const until=Date.now()+3000;while(Date.now()<until){if(cell()?.textContent.trim()===String(expected))return;await sleep(15)}throw Error('Node count did not reach '+expected+'; '+host.textContent+'; '+JSON.stringify(notices))}
  await waitNode(2);check(cell().textContent.trim()==='2','Node uses active count independently of heartbeat');
  count=0;await waitNode(0);check(cell().textContent.trim()==='0','Node disconnect clears displayed count on next poll');
  count=1;await waitNode(1);check(cell().textContent.trim()==='1','Node reconnect updates without page reload');
  mounted.dispose();await sleep(50);const after=calls;await sleep(70);check(calls===after,'Unmounted node view stops polling');
  check(notices.length===0,'Node polling has no UI errors');dom.window.close();
  console.log('ONLINE PRESENCE UI OK: node list, connect/disconnect/reconnect and disposal');
 }
 console.log(`ONLINE PRESENCE UI OK: ${checks} checks; actual production bundles, desktop/mobile and mocked API only.`);
}finally{await new Promise(resolve=>server.close(resolve));}
