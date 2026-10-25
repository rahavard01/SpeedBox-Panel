import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {createRequire} from 'node:module';
const require=createRequire(import.meta.url);
const {JSDOM}=require(process.env.SPEEDBOX_TEST_JSDOM||'jsdom');
const source=p=>readFileSync(new URL('../../'+p,import.meta.url),'utf8');
const template=source('resources/views/infrastructure.blade.php').replace(/^[\s\S]*?<template[^>]*>/,'').replace(/<\/template>[\s\S]*$/,'');
const dom=new JSDOM(`<div class="speedbox-infrastructure" id="mount">${template}</div>`,{url:'http://localhost/?tunnel_preview=full',runScripts:'outside-only',pretendToBeVisual:true});
const w=dom.window;
w.matchMedia=()=>({matches:false,addEventListener(){},removeEventListener(){}});
w.scrollTo=()=>{};
const delay=ms=>new Promise(r=>setTimeout(r,ms));
async function wait(check){for(let i=0;i<300;i++){if(check())return;await delay(10);}throw Error('UI result timed out: '+w.document.body.textContent);}
let errors=[];w.addEventListener('error',e=>errors.push(e.message));
for(const name of ['wattunnel','network-tunnels','tunnels','node-editor','infrastructure'])w.eval(source('public/speedbox/'+name+'.js'));
w.localStorage.setItem('token','fixture');w.localStorage.setItem('user',JSON.stringify({role:1}));
const notify={success(){},error:m=>errors.push(m),notif(){}};
let deleted=false;w.fetch=async(url,options={})=>{
 const path=String(url).replace('/api/admin/infrastructure','');
 let data;
 if(path==='/servers')data={data:deleted?[]:[{id:901,name:'retired server',host:'192.0.2.1',ssh_user:'root',ssh_port:22,status:'ready',connection_status:'offline',deployments:[]}]};
 else if(path==='/servers/901'&&options.method==='DELETE'){deleted=true;data={data:{id:99},message:'queued'};}
 else if(path==='/server-removals/99')data={data:{status:'succeeded',message:'سرور حذف شد. پاک‌سازی سرور تأیید نشد.'}};
 else throw Error('Unexpected API: '+path);
 return {ok:true,status:200,json:async()=>data};
};
const session=w.SpeedboxInfrastructure.mount(w.document.getElementById('mount'),{router:{push(){},replace(){}},notify,view:'tunnels'});
const q=s=>w.document.querySelector(s);
try{
 await wait(()=>q('[data-tunnel-card="601"]'));
 q('[data-tunnel-card="601"] [data-tunnel-action=delete]').click();await wait(()=>q('[data-confirm-tunnel-delete]'));
 q('[data-confirm-tunnel-delete]').click();await wait(()=>q('#modalTitle')?.textContent==='نتیجهٔ حذف');
 assert.equal(q('[data-tunnel-card="601"]'),null);assert.equal(q('.tunnel-log-columns'),null);
 q('[data-close]').click();
 q('[data-tunnel-card="605"] [data-tunnel-action=delete]').click();await wait(()=>q('[data-confirm-network]'));await delay(10);
 q('[data-confirm-network]').click();await wait(()=>q('#modalTitle')?.textContent==='نتیجهٔ حذف');
 assert.equal(q('[data-tunnel-card="605"]'),null);assert.equal(q('.tunnel-log-columns'),null);q('[data-close]').click();
 session.showView('servers');await wait(()=>q('[data-server-delete="901"]'));
 q('[data-server-delete="901"]').click();assert.equal(q('#serverDeleteMode'),null);
 q('[data-confirm-server-delete]').click();await wait(()=>q('#modalTitle')?.textContent==='نتیجهٔ حذف');
 assert.match(q('#modalBody').textContent,/پاک‌سازی سرور تأیید نشد/);assert.equal(q('[data-server-delete="901"]'),null);
 assert.deepEqual(errors,[]);
 console.log('PASS: real removal dialogs for BackPack, network tunnel and unreachable server; short results and preserved single deletion flow');
}finally{session.dispose();dom.window.close();}
