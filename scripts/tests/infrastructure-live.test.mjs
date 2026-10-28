// Real UI modules in a DOM; run with PARTNER_TEST_JSDOM pointing to jsdom.
import {readFileSync} from 'node:fs';
import assert from 'node:assert/strict';
const {JSDOM}=await import(process.env.PARTNER_TEST_JSDOM||'jsdom');
const read=p=>readFileSync(new URL('../../'+p,import.meta.url),'utf8');
const template=read('resources/views/infrastructure.blade.php').match(/<template[^>]*>([\s\S]*)<\/template>/)[1];
const tick=()=>new Promise(r=>setTimeout(r,15));
async function fixture(mobile,view){
 const dom=new JSDOM('<div id="root">'+template+'</div>',{url:'https://fixture.test',runScripts:'outside-only',pretendToBeVisual:true});
 const w=dom.window,errors=[];w.matchMedia=()=>({matches:mobile,addEventListener(){},removeEventListener(){}});w.ResizeObserver=class{observe(){}disconnect(){}};
 w.HTMLElement.prototype.scrollIntoView=function(){this.dataset.scrolled='1';};
 const viewport=new w.EventTarget();viewport.height=800;viewport.offsetTop=0;Object.defineProperty(w,'visualViewport',{value:viewport});
 w.localStorage.setItem('token','fixture');w.localStorage.setItem('user','{"role":1}');
 let count=0,phase='running',log='first\n',blocked=null,release=null;
 const timer=w.setTimeout.bind(w);w.setTimeout=(fn,ms,...args)=>timer(fn,[1500,3000].includes(ms)?20:ms,...args);
 w.fetch=async url=>{
  const path=String(url).split('/infrastructure')[1].split('?')[0];let data=[];
  if(path==='/servers')data=[{id:1,name:'test',host:'127.0.0.1',ssh_port:22,status:'online',deployments:[],machine:{is_active:true,load_status:{cpu:12,mem:{total:100,used:20},disk:{total:200,used:50}}}}];
  if(path==='/servers/1/deployments'){
   count++;if(blocked)await new Promise(resolve=>{release=resolve;});
   data=[{id:9,action:'install',status:phase,progress:phase==='running'?55:100,log,created_at:'2026-10-27T12:00:00Z'}];
  }
  return {ok:true,json:async()=>({data,meta:{kernel_capabilities:{protocols:{shadowsocks:['singbox','xray']},features:{ss2022_chacha:{kernels:['singbox','xray']}}}}})};
 };
 w.eval(read('public/speedbox/node-editor.js'));w.eval(read('public/speedbox/infrastructure.js'));
 const ui=w.SpeedboxInfrastructure.mount(w.document.querySelector('#root'),{view,router:{replace(){},push(){}},notify:{error:m=>errors.push(m),notif(){},success(){}}});
 await tick();await tick();
 return {dom,w,ui,errors,viewport,get count(){return count;},set phase(v){phase=v;},set log(v){log=v;},block(){blocked=true;},release(){blocked=false;release?.();}};
}
{
 const f=await fixture(false,'servers'),d=f.w.document;
 d.querySelector('[data-server-log]').click();await tick();
 const pre=d.querySelector('.log-box');assert.equal(pre.textContent,'first\n');
 Object.defineProperties(pre,{scrollHeight:{value:1000,configurable:true},clientHeight:{value:100,configurable:true}});pre.scrollTop=50;pre.focus();
 f.log='first\nsecond <img src=x onerror=alert(1)>';await tick();await tick();
 assert.equal(d.querySelector('.log-box'),pre,'poll replaced the log DOM');assert.equal(d.activeElement,pre);assert.equal(pre.scrollTop,50,'reader scroll was reset');assert.equal(pre.querySelector('img'),null);
 f.phase='succeeded';await tick();await tick();const count=f.count;await tick();await tick();assert.equal(f.count,count,'terminal log kept polling');
 d.querySelector('[data-close]').click();
 // A late result must not reopen/replace another modal.
 f.phase='running';f.block();d.querySelector('[data-server-log]').click();await tick();d.querySelector('[data-close]').click();d.querySelector('#addServer').click();f.release();await tick();
 assert.ok(d.querySelector('#serverForm'));assert.equal(d.querySelector('[data-deployment-log]'),null);
 assert.deepEqual(f.errors,[]);f.ui.dispose();f.dom.window.close();
}
for(const mobile of [true,false]){
 const f=await fixture(mobile,'nodes'),d=f.w.document;
 d.querySelector('#addNode').click();d.querySelector('[data-advanced]').click();
 const dialog=d.querySelector('.node-sub-modal');assert.ok(dialog);
 assert.equal(d.activeElement.tagName,mobile?'SECTION':'BUTTON','mobile opened keyboard or desktop lost focus');
 dialog.querySelector('[data-sub-close]').click();
 const input=d.querySelector('[data-path=name]');input.focus();input.value='typed';input.setSelectionRange(2,2);input.dispatchEvent(new f.w.Event('input',{bubbles:true}));await tick();
 assert.equal(d.activeElement,input);assert.equal(input.value,'typed');assert.equal(input.selectionStart,2);
 f.viewport.height=350;f.viewport.dispatchEvent(new f.w.Event('resize'));await tick();assert.equal(d.activeElement,input);assert.equal(input.value,'typed');assert.equal(d.querySelector('#infrastructureOverlay').style.getPropertyValue('--infrastructure-viewport-height'),'');
 assert.deepEqual(f.errors,[]);f.ui.dispose();f.viewport.height=900;f.viewport.dispatchEvent(new f.w.Event('resize'));f.dom.window.close();
}
console.log('PASS: live logs, reader focus/scroll, safe text, terminal stop, late result cancellation, mobile/desktop focus, typing and keyboard viewport');
