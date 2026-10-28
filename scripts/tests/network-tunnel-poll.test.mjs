import assert from 'node:assert/strict';
import vm from 'node:vm';
import {readFileSync} from 'node:fs';
const source=readFileSync(new URL('../../public/speedbox/network-tunnels.js',import.meta.url),'utf8');
const start=source.indexOf('\n async function poll(){\n  if(disposed||!watch)');
const end=source.indexOf('\n async function action',start);
assert.ok(start>=0&&end>start,'production operation-result poll found');
const poll=source.slice(start,end);let checks=0;
async function test({fail=false,completed=false,refreshFail=false,dispose=false,supersede=false}={}){
 let timers=0,results=0,refreshes=0,progress={textContent:''};
 const ctx=vm.createContext({disposed:false,watch:{id:7,t:{}},watchTimer:4,
  api:async()=>{if(fail)throw Error('temporary read failure');if(dispose)ctx.disposed=true;if(supersede)ctx.watch={id:8,t:{}};return {data:{status:completed?'succeeded':'running',message:'working'}};},
  refresh:async()=>{refreshes++;if(refreshFail)throw Error('temporary final refresh failure');},
  result:()=>{results++;},body:()=>({querySelector:()=>progress}),clearTimeout:()=>{},setTimeout:()=>{timers++;return 5;}});
 await vm.runInContext(poll+'\npoll()',ctx);
 return {ctx,timers,results,refreshes,progress};
}
let r=await test({fail:true});assert.equal(r.timers,1);assert.equal(r.ctx.watch.id,7);checks+=2;
r=await test();assert.equal(r.timers,1);assert.equal(r.progress.textContent,'working');checks+=2;
r=await test({completed:true,refreshFail:true});assert.equal(r.timers,1);assert.equal(r.ctx.watch.id,7);assert.equal(r.results,0);checks+=3;
r=await test({completed:true});assert.equal(r.timers,0);assert.equal(r.ctx.watch,null);assert.equal(r.results,1);assert.equal(r.refreshes,1);checks+=4;
for(const options of [{dispose:true},{supersede:true}]){r=await test({...options,completed:true});assert.equal(r.timers,0);assert.equal(r.results,0);assert.equal(r.refreshes,0);checks+=3;}
console.log(`NETWORK RESULT POLLING: ${checks} checks passed`);
