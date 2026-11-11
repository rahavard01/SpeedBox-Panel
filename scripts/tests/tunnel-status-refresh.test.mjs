import assert from 'node:assert/strict';
import vm from 'node:vm';
import {readFileSync} from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'../..');
const read=name=>readFileSync(path.join(root,name),'utf8');
const infrastructure=read('public/speedbox/infrastructure.js');
let covers=0,finished=0,fetches=0;
const apiContext=vm.createContext({AbortController,DOMException,disposed:false,requests:new AbortController(),
  apiBase:'/api/admin/infrastructure',token:'fixture',savingCount:0,
  savingOverlay:()=>{covers++;return()=>finished++;},router:{replace:()=>{}},
  fetch:async()=>{fetches++;return {ok:true,status:200,json:async()=>({data:{}})};}});
vm.runInContext(infrastructure.slice(infrastructure.indexOf('  async function api('),infrastructure.indexOf('  function savingOverlay('))+';globalThis.api=api;',apiContext);
for(const prefix of ['/tunnels','/network-tunnels']){
  await apiContext.api(`${prefix}/1/actions`,{method:'POST',body:'{"action":"status"}'});
  assert.equal(covers,0,'status uses no saving cover');
}
await apiContext.api('/tunnels/1/actions',{method:'POST',body:'{"action":"restart"}'});
await apiContext.api('/tunnels/1',{method:'PUT',body:'{}'});
assert.equal(covers,2);assert.equal(finished,2,'real mutations retain their saving cover');
apiContext.savingCount=1;
await apiContext.api('/tunnels/1/actions',{method:'POST',body:'{"action":"status"}'});
const previous=fetches;
await assert.rejects(apiContext.api('/servers/1',{method:'PUT'}));
assert.equal(fetches,previous,'concurrent config writes remain protected');
apiContext.fetch=async()=>({ok:false,status:409,json:async()=>({message:'busy'})});
await assert.rejects(apiContext.api('/tunnels/1/actions',{method:'POST',body:'{"action":"status"}'}),e=>e.status===409);

const source=read('public/speedbox/tunnels.js');
const copy=value=>JSON.parse(JSON.stringify(value));
function harness(tunnels,{conflict=false,failed=false,hold=false}={}){
  const calls=[],renders=[],operations=new Map();let next=10,activeId=null,release;
  const fixture={data:copy(tunnels),tests:[],servers:[]};
  const api=async(url,options={})=>{
    calls.push({url,method:options.method||'GET',action:options.body&&JSON.parse(options.body).action});
    if(url==='/tunnels')return copy(fixture);
    if(options.method==='POST'){
      assert.equal(JSON.parse(options.body).action,'status','refresh only checks status');
      if(conflict){conflict=false;const error=new Error('busy');error.status=409;throw error;}
      assert.equal(activeId,null,'shared-host checks never overlap');
      if(hold)await new Promise(resolve=>release=resolve);
      const id=++next,tunnel_id=Number(url.split('/')[2]);activeId=id;
      const op={id,tunnel_id,action:'status',status:'queued'};operations.set(id,op);
      fixture.data.find(t=>t.id===tunnel_id).operation=op;
      return {data:copy(op)};
    }
    const op=operations.get(Number(url.split('/').at(-1)));
    assert.ok(op,'poll known operation');op.status=failed?'failed':'succeeded';op.message=failed?'probe failed':'done';activeId=null;
    fixture.data.find(t=>t.id===op.tunnel_id).message=op.message;
    return {data:copy(op)};
  };
  const content={setAttribute(){},removeAttribute(){},querySelectorAll:()=>[]};
  const context=vm.createContext({window:{matchMedia:()=>({addEventListener(){},removeEventListener(){}}),addEventListener(){},removeEventListener(){}},
    location:{search:'',hostname:'fixture.invalid'},URLSearchParams,DOMException,Date,console,
    setTimeout:(fn,delay)=>setTimeout(fn,delay===2000?0:delay),clearTimeout,api,content,renders,fixture});
  const needle='    return {load,dispose(){';
  assert.ok(source.includes(needle));
  vm.runInContext(source.replace(needle,`    renderCards=()=>renders.push(JSON.parse(JSON.stringify(data.data)));renderHistory=()=>{};
    globalThis.test={checkTunnel,refresh,setData:value=>data=value};
${needle}`),context);
  const session=context.window.SpeedboxTunnels.mount(content,{api,toast(){},modalBody:()=>null});
  context.test.setData(copy(fixture));
  const button={disabled:false,isConnected:true,title:'',classList:{toggle(){}},querySelector:()=>({className:''}),setAttribute(){}};
  return {calls,renders,session,check:context.test.checkTunnel,refresh:()=>context.test.refresh({currentTarget:button}),button,release:()=>release?.()};
}
const tunnels=[
  {id:1,name:'UK',status:'connected',settings:{}},
  {id:2,name:'TR',engine:'gost',status:'offline',settings:{}},
  {id:3,name:'DE',engine:'gre',status:'connected',settings:{}},
  {id:4,name:'Wat',engine:'wattunnel',status:'connected',settings:{}},
  {id:5,status:'deleted',settings:{}},{id:6,status:'stopped',settings:{}},
  {id:7,status:'connected',settings:{enabled:false}}
];
{
  const h=harness(tunnels,{conflict:true});await h.refresh();
  const posts=h.calls.filter(c=>c.method==='POST');
  assert.deepEqual(posts.map(c=>c.url),['/tunnels/1/actions','/tunnels/1/actions','/network-tunnels/2/actions','/network-tunnels/3/actions','/network-tunnels/4/actions']);
  assert.ok(h.renders.some(rows=>rows.find(t=>t.id===1)?.message==='done'),'result updates in the cards');
  assert.equal(h.button.disabled,false);h.session.dispose();
}
{
  const h=harness(tunnels,{hold:true});const first=h.check(tunnels[0]),duplicate=h.check(tunnels[0]);
  assert.equal(first,duplicate,'duplicate clicks share the same check');
  await new Promise(resolve=>setTimeout(resolve,0));h.release();await first;
  assert.equal(h.calls.filter(c=>c.method==='POST').length,1);h.session.dispose();
}
{
  const h=harness(tunnels,{hold:true});const first=h.refresh();
  await new Promise(resolve=>setTimeout(resolve,0));await h.refresh();
  assert.equal(h.calls.filter(c=>c.method==='POST').length,1,'duplicate bulk refresh is ignored');
  h.session.dispose();h.release();await assert.rejects(first,e=>e.name==='AbortError');
  assert.equal(h.calls.filter(c=>c.method==='POST').length,1,'navigation stops further checks');
}
{
  const h=harness(tunnels.slice(0,2),{failed:true});
  await assert.rejects(h.refresh(),/probe failed/);
  assert.equal(h.calls.filter(c=>c.method==='POST').length,2,'one failed probe does not skip the next tunnel');
  assert.equal(h.button.disabled,false);h.session.dispose();
}
console.log('TUNNEL STATUS: no cover, write protection, sequential refresh, retry, deduplication, failures and navigation passed');
