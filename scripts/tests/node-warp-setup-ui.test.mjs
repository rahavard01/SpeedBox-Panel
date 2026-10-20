import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
const {JSDOM}=await import(process.env.SPEEDBOX_TEST_JSDOM||'jsdom');
const code=readFileSync(new URL('../../public/speedbox/node-editor.js',import.meta.url),'utf8');
let checks=0;
const check=(condition,label)=>{assert.ok(condition,label);checks++;};
const tick=()=>new Promise(resolve=>setImmediate(resolve));
async function mount({status={installed:true,ready:true,account:'free'},supported=true,fail=false}={}){
 const dom=new JSDOM('<section class="infrastructure-modal"><div id="body"></div></section>',{runScripts:'outside-only',url:'http://localhost/'});
 const w=dom.window,calls=[],messages=[];
 w.eval(code);
 const data={supported,agent_state:supported?'supported':'unsupported',status,operation:null};
 const api=async(url,opts={})=>{const body=opts.body?JSON.parse(opts.body):null;calls.push({url,body,method:opts.method||'GET'});if(opts.method==='POST'){if(fail)throw Error('operation failed');return {message:'queued'};}return {data};};
 const dispose=w.SpeedboxNodeEditor.mount({node:{id:7,name:'Test',type:'vless',machine_id:1,kernel:'xray',host:'node.test',port:'443',server_port:443,warp_enabled:true},state:{nodes:[],groups:[],routes:[],machines:[{id:1,name:'First'},{id:2,name:'Second'}]},api,openModal:(title,body)=>w.document.getElementById('body').innerHTML=body,closeModal(){},toast:message=>messages.push(message),reload(){},enhanceControls:root=>root.querySelectorAll('input').forEach(e=>e.classList.add('form-input')),isSaving:()=>false});
 await tick();const q=s=>w.document.querySelector(s);
 return {w,q,calls,data,messages,async click(selector){q(selector).click();await tick();await tick();},async select(value){q('[data-warp-account]').value=value;q('[data-warp-account]').dispatchEvent(new w.Event('change'));await tick();},close(){dispose();w.close();}};
}
{
 const t=await mount({status:{installed:false,ready:false,account:'unknown',error:'WARP روی سرور نصب نیست؛ ابتدا آماده‌سازی را اجرا کنید.'},supported:false});
 const text=t.q('.node-warp-card').textContent;
 check(t.q('[data-path="warp_enabled"]').getAttribute('aria-label')==='WARP','short title');
 check(!t.q('[data-warp-action="upgrade"]'),'no separate upgrade button');
 check(!t.q('[data-warp-action="refresh"]'),'no refresh button');
 check(!text.includes('آماده‌سازی را اجرا'),'old error suppressed');
 check((text.match(/WARP روی این سرور نصب نیست/g)||[]).length===1,'not-installed message shown once');
 check(t.q('.node-warp-main').textContent==='نصب و راه‌اندازی','single setup action');
 check(t.q('[data-warp-action="test"]').parentElement===t.q('.node-warp-main').parentElement,'test shares same action row');
 check(t.calls.every(c=>c.method==='GET'),'opening form only reads');
 check(t.q('[data-warp-account]').value==='free','free default on new install');
 await t.click('[data-warp-action="install"]');
 const post=t.calls.find(c=>c.method==='POST');
 check(post.url==='/machines/1/warp'&&post.body.action==='prepare','single durable setup endpoint');
 check(post.body.account==='keep','default setup never replaces an existing paid account');
 check(!t.q('.node-warp-main').disabled,'request lock released');t.close();
}
{
 const t=await mount({status:null,supported:false});
 check(!!t.q('[data-warp-action="install"]'),'setup available before first host check');
 check(!t.q('.node-warp-card').textContent.includes('نرم‌افزار این سرور را به‌روزرسانی کنید'),'no obsolete software warning');
 await t.select('plus');await t.click('[data-warp-action="install"]');
 check(t.calls.every(c=>c.method==='GET'),'missing plus license blocks submission');
 t.q('#nodeWarpLicense').value='secret-123';t.q('#nodeWarpLicense').dispatchEvent(new t.w.Event('input'));
 await t.click('[data-warp-action="install"]');
 const post=t.calls.find(c=>c.method==='POST');check(post.body.account==='plus'&&post.body.license==='secret-123','explicit plus setup retains selected license');
 check(!t.q('#nodeWarpLicense'),'license cleared after acceptance');t.close();
}
{
 const t=await mount({status:{installed:true,ready:true,account:'plus'}});
 check(!t.q('.node-warp-main'),'ready server has no install action');
 check(t.q('.node-warp-card').textContent.includes('حساب سرور: WARP+'),'current shared account visible');
 check(t.q('details').open===false,'account editor stays collapsed');
 check(t.q('[data-warp-account]').options.length===2,'two account types only');
 await t.select('free');check(t.calls.every(c=>c.method==='GET'),'selecting free does not downgrade');
 await t.click('[data-warp-action="change"]');check(t.calls.find(c=>c.method==='POST').body.account==='free','only explicit account change downgrades');t.close();
}
{
 const t=await mount({status:{installed:true,ready:false,account:'plus'},fail:true});
 check(t.q('.node-warp-main').textContent==='راه‌اندازی مجدد','failed connection shows restart');
 await t.select('free');await t.click('[data-warp-action="prepare"]');
 const post=t.calls.find(c=>c.method==='POST');check(post.body.account==='keep'&&post.body.license===null,'restart preserves account despite selector');
 check(!t.q('.node-warp-main').disabled,'failure unlocks controls');t.close();
}
{
 const t=await mount({status:{installed:true,ready:true,account:'plus'},supported:false});
 check(!!t.q('.node-warp-main'),'unsupported software can be prepared even when existing WARP is connected');
 t.data.operation={status:'running',action:'upgrade',log:'technical output'};
 await t.select('plus');
 check(t.q('.node-warp-card').textContent.includes('در حال به‌روزرسانی نرم‌افزار سرور'),'compact progress text');
 check(t.q('[data-warp-action="test"]').disabled,'running operation cannot be submitted again');t.close();
}
{
 const t=await mount();
 t.q('[data-path="machine_id"]').value='2';t.q('[data-path="machine_id"]').dispatchEvent(new t.w.Event('change'));await tick();
 check(t.calls.at(-1).url==='/machines/2/warp','new server status loaded automatically');
 await t.click('[data-warp-action="test"]');check(t.calls.find(c=>c.method==='POST').body.action==='test','connection test remains separate');t.close();
}
{
 const t=await mount({status:{installed:false,ready:false,account:'unknown',error:'setup failed',error_code:'operation_failed'}});
 check(t.q('[role="alert"]').textContent==='setup failed','real setup failure remains visible before installation');t.close();
}
console.log(`WARP setup form: PASS (${checks} checks; simulated API, no host operations)`);
