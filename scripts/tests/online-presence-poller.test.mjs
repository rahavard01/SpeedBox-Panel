// Deterministic lifecycle/race tests for the actual dashboard poller; no network.
import {readFileSync} from 'node:fs'
import {fileURLToPath} from 'node:url'
import path from 'node:path'
import vm from 'node:vm'
import assert from 'node:assert/strict'
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'../..')
const context=vm.createContext({AbortController,setTimeout,clearTimeout})
const vue=new vm.SyntheticModule(['ref','onMounted','onBeforeUnmount'],function(){this.setExport('ref',v=>({value:v}));this.setExport('onMounted',()=>{});this.setExport('onBeforeUnmount',()=>{})},{context})
const http=new vm.SyntheticModule(['default'],function(){this.setExport('default',{})},{context})
const module=new vm.SourceTextModule(readFileSync(path.join(root,'resources/js/composables/useOnlinePresence.js'),'utf8'),{context})
await module.link(name=>name==='vue'?vue:http);await module.evaluate()
const create=module.namespace.createPresencePoller
let checks=0;function check(v,label){assert.ok(v,label);checks++}
function target(){return {hidden:false,listeners:new Map(),addEventListener(n,fn){this.listeners.set(n,fn)},removeEventListener(n){this.listeners.delete(n)},emit(n){this.listeners.get(n)?.()}}}
const doc=target(),win=target(),timers=new Map(),pending=[],values=[];let next=0,calls=0
const poll=create({documentTarget:doc,windowTarget:win,setTimer:(fn,delay)=>{check(delay===5000,'Five-second lightweight cadence');timers.set(++next,fn);return next},clearTimer:id=>timers.delete(id),request:options=>{calls++;return new Promise((resolve,reject)=>pending.push({resolve,reject,signal:options.signal}))},update:value=>values.push(JSON.parse(JSON.stringify(value)))})
const flush=async()=>{for(let i=0;i<5;i++)await Promise.resolve()}
poll.start();poll.start();check(calls===1,'Start is idempotent')
poll.refresh();win.emit('focus');check(calls===1,'No overlapping requests')
pending[0].resolve({usersOnline:1,onlineIps:2});await flush();check(values[0].usersOnline===1&&values[0].onlineIps===2,'Valid online update')
check(timers.size===1,'One scheduled refresh')
const tick=timers.values().next().value;tick();check(calls===2&&timers.size===0,'Next refresh begins after prior completion')
doc.hidden=true;doc.emit('visibilitychange');check(pending[1].signal.aborted,'Hidden tab aborts in-flight request')
doc.hidden=false;doc.emit('visibilitychange');check(calls===3,'Returning to tab fetches immediately')
pending[1].resolve({usersOnline:99,onlineIps:99});await flush();check(values.length===1,'Late stale response cannot overwrite current state')
pending[2].resolve({usersOnline:0,onlineIps:0});await flush();check(values.at(-1).usersOnline===0,'Explicit zero is applied')
win.emit('focus');check(calls===4,'Focus refreshes without waiting for timer')
pending[3].reject(new Error('offline'));await flush();check(values.at(-1).usersOnline===0&&values.length===2,'Transport errors do not fabricate a new count')
for(const value of [{usersOnline:-1,onlineIps:1},{usersOnline:2,onlineIps:1},{usersOnline:'1',onlineIps:1},null]){
 poll.refresh();pending.at(-1).resolve(value);await flush();check(values.length===2,'Malformed or inconsistent response ignored')
}
doc.hidden=true;doc.emit('visibilitychange');const before=calls;poll.refresh();check(calls===before&&timers.size===0,'Hidden tab neither polls nor schedules work')
doc.hidden=false;doc.emit('visibilitychange');const last=pending.at(-1);poll.dispose();check(last.signal.aborted&&timers.size===0,'Unmount aborts and clears timers')
last.resolve({usersOnline:7,onlineIps:7});await flush();check(values.length===2,'Unmounted component cannot receive stale updates')
check(doc.listeners.size===0&&win.listeners.size===0,'Unmount removes all listeners')
poll.start();poll.refresh();check(calls===before+1,'Disposed poller cannot restart')
console.log(`ONLINE PRESENCE POLLER OK: ${checks} checks; deterministic timers and mocked requests only.`)
