import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';

const source=readFileSync(new URL('../../public/speedbox/infrastructure.js',import.meta.url),'utf8');
// Exercise the real request wrapper with a controlled HTTP boundary.
const code=source.slice(source.indexOf('  async function api('),source.indexOf('  function savingOverlay('));
const messages=[];
const context=vm.createContext({
  requests:new AbortController(),disposed:false,savingCount:0,apiBase:'/api',token:'fixture',
  DOMException,router:{replace(){}},
  fetch:async()=>({ok:true,status:200,json:async()=>({data:{id:1}})}),
});
context.savingOverlay=message=>{messages.push(message);context.savingCount++;return()=>context.savingCount--;};
vm.runInContext(code+'\nthis.request=api;',context);
for(const kind of ['tunnels','network-tunnels']){
  for(const action of ['status','maintenance-status']){
    await context.request(`/${kind}/1/actions`,{method:'POST',body:JSON.stringify({action})});
    assert.equal(messages.length,0,`${kind} ${action} must not flash a saving overlay`);
  }
}
for(const [path,method,body,expected] of [
  ['/tunnels/1/actions','POST',{action:'logs'},'در حال دریافت گزارش…'],
  ['/network-tunnels/1/actions','POST',{action:'logs'},'در حال دریافت گزارش…'],
  ['/tunnels/1/actions','POST',{action:'optimize'},'در حال شروع بهینه‌سازی…'],
  ['/network-tunnels/1/actions','POST',{action:'update'},'در حال شروع به‌روزرسانی…'],
  ['/nodes/1/clone','POST',{},'در حال ساخت کپی…'],
  ['/nodes/1','DELETE',{},'در حال حذف…'],
  ['/nodes/order','PUT',{ids:[1,2]},'در حال ذخیرهٔ ترتیب…'],
  ['/keys/reality','POST',{},'در حال تولید کلید…'],
  ['/nodes','POST',{},'در حال ذخیره…'],
  ['/servers/1/deploy','POST',{},'در حال اجرای عملیات…'],
  ['/tunnel-tests','POST',{},'در حال شروع بررسی اتصال…'],
  ['/network-tunnel-tests','POST',{},'در حال شروع بررسی اتصال…'],
  ['/tunnel-cdn-tests','POST',{},'در حال شروع بررسی اتصال…'],
]){
  await context.request(path,{method,body:JSON.stringify(body)});
  assert.equal(messages.at(-1),expected);
  assert.equal(context.savingCount,0);
}
let release;
context.fetch=()=>new Promise(resolve=>{release=resolve;});
const pending=context.request('/nodes/1',{method:'PUT',body:'{}'});
assert.equal(context.savingCount,1);
await assert.rejects(context.request('/nodes/2',{method:'PUT',body:'{}'}),/عملیات جاری/);
release({ok:false,status:422,json:async()=>({message:'invalid'})});
await assert.rejects(pending,/invalid/);
assert.equal(context.savingCount,0,'failed requests release the busy state');
console.log('Infrastructure busy states: passed');
