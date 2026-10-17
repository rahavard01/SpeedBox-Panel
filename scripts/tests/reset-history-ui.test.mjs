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
let historyRecords=[];
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
    else if(p.endsWith('/reset-history'))data={data:historyRecords,user:account,account,can_reset:true,pagination:{page:1,last_page:1},meta:{current_page:1,last_page:1}};
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
  async function wait(condition,label){const deadline=Date.now()+15000;while(Date.now()<deadline){const value=condition();if(value)return value;await sleep(20)}throw Error('Timed out '+label+'; '+errors.join('; ')+'; '+doc.body.textContent.slice(-1200));}
  const visible=el=>{for(let n=el;n;n=n.parentElement)if(win.getComputedStyle(n).display==='none')return false;return true};
  const button=(label,within=doc)=>[...within.querySelectorAll('button')].find(el=>visible(el)&&el.textContent.trim()===label);
  await wait(()=>doc.querySelector('.btn-create-user'),'users page');
  const record=(id,extra={})=>({id,reset_type:'manual',trigger_source:'manual',reset_time:'2026-10-16 00:00:00',old_total:1073741824,...extra});
  const cases=[
   {name:'no history or schedule',records:[],count:0,next:null},
   {name:'fresh scheduled account',records:[],count:0,last:1792000000,next:Math.floor(Date.now()/1000)+86400},
   {name:'legacy empty reason',records:[record(1,{reason:''})],count:1},
   {name:'legacy missing reason',records:[record(1,{metadata:null})],count:1},
   {name:'reason present',records:[record(1,{reason:'درخواست کاربر'})],count:1},
   {name:'mixed history',records:[record(1,{reason:''}),record(2,{reason:'درخواست کاربر'}),record(3,{metadata:null}),record(4,{reason:'دلیل در متادیتا',metadata:{reason:'دلیل در متادیتا'}})],count:4},
   {name:'empty after populated',records:[],count:0,next:null},
  ];
  for(const sample of cases){
   historyRecords=sample.records;account.reset_count=sample.count;account.last_reset_at=sample.last||(sample.count?Math.floor(Date.now()/1000):null);account.next_reset_at=sample.next||null;
   if(role==='admin'){
    (await wait(()=>doc.querySelector('[aria-label="عملیات اکانت"]'),'account row')).click();await sleep(20);
    button('بازنشانی ترافیک').click();await wait(()=>button('تاریخچه بازنشانی ترافیک'),'reset dialog');
    button('تاریخچه بازنشانی ترافیک').click();
   }else{
    (await wait(()=>[...doc.querySelectorAll('[aria-haspopup="menu"]')].at(-1),'partner account row')).click();await sleep(20);button('تاریخچه بازنشانی ترافیک').click();
   }
   const modal=await wait(()=>doc.querySelector('.account-operation-modal'),'history modal');
   await wait(()=>modal.querySelector('.reset-summary'),'history summary');await sleep(30);
   check(modal.querySelectorAll('.reset-log-card').length===sample.records.length,role+' '+sample.name+' shows every record');
   const reasons=sample.records.filter(r=>r.reason||r.metadata?.reason).length;
   check(modal.querySelectorAll('.reset-log-reason').length===reasons,role+' '+sample.name+' shows only available reasons');
   check(!!modal.querySelector('.operation-empty')===(sample.records.length===0),role+' '+sample.name+' empty message');
   check(modal.textContent.includes('سوابق بازنشانی ترافیک'),role+' '+sample.name+' retains heading');
   check(modal.querySelector('.reset-summary').textContent.includes('بازنشانی دوره‌ای بعدی')===!!sample.next,role+' '+sample.name+' preserves next scheduled reset');
   if(!sample.count){check(modal.querySelector('.reset-summary').textContent.includes('تعداد بازنشانی')&&modal.querySelector('.reset-summary-count').textContent==='۰'&&modal.querySelector('.reset-summary').querySelectorAll('dd')[1].textContent==='—',role+' '+sample.name+' zero count and dash, including a stale creation timestamp');}
   if(role==='admin'){
    for(let i=0;i<3;i++){
     button('بازنشانی ترافیک',modal.querySelector('.operation-tabs')).click();await sleep(15);
     check(!!modal.querySelector('textarea'),sample.name+' reset form returns');
     button('تاریخچه بازنشانی ترافیک',modal.querySelector('.operation-tabs')).click();await sleep(15);
     check(modal.querySelectorAll('.reset-log-card').length===sample.records.length,sample.name+' history survives repeated switch');
    }
   }
   check(errors.length===0,role+' '+sample.name+' no Vue/DOM errors: '+errors.join('; '));
   const close=[...modal.querySelectorAll('button')].find(b=>['انصراف','بستن','بازگشت'].includes(b.textContent.trim()));
   check(!!close,'history can close');close.click();await sleep(30);
  }
  check(!requests.slice(requestStart).some(r=>r.method==='POST'&&r.path.endsWith('/reset-traffic')),'Viewing history never resets traffic');
  process.stdout.write(`RESET HISTORY UI OK: ${role}, ${width}px\n`);dom.window.close();
 }
 process.stdout.write(`RESET HISTORY UI OK: ${checks} checks; shipped Vue assets, desktop/mobile DOM and mocked API only.\n`);
}finally{await new Promise(resolve=>server.close(resolve));}
