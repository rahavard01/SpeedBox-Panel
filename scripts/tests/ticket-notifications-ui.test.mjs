import { readFileSync } from 'node:fs';
import assert from 'node:assert/strict';
import { loadSourceUi } from './helpers/source-ui.mjs';
const { JSDOM } = await import(process.env.SPEEDBOX_TEST_JSDOM || process.env.PARTNER_TEST_JSDOM || 'jsdom');
const read = file => readFileSync(new URL('../../' + file, import.meta.url), 'utf8');
async function boot(saved = {}) {
  const dom = new JSDOM('<div id="fixture"></div>', { url:'http://fixture.test/panel', runScripts:'outside-only', pretendToBeVisual:true });
  const win = dom.window;
  win.localStorage.setItem('user', '{"id":2,"role":2,"code":"testrep"}');
  win.localStorage.setItem('token','fixture');
  for (const [key, value] of Object.entries(saved)) win.localStorage.setItem(key, value);
  win.matchMedia = () => ({ matches:true, addEventListener(){}, removeEventListener(){} });
  win.fetch = async () => ({json:async()=>({})}); win.AbortController=AbortController; win.scrollTo=()=>{};
  win.HTMLCanvasElement.prototype.getContext=()=>null;
  let time = 1000, nextId = 0, response = null, resolveFirst;
  const tasks = new Map();
  win.setTimeout = (callback, delay = 0) => { const id = ++nextId; tasks.set(id,{callback,at:time+delay}); return id; };
  win.clearTimeout = id => tasks.delete(id);
  win.setInterval = () => ++nextId; win.clearInterval=()=>{};
  const tick = milliseconds => {
    const end = time + milliseconds;
    for (;;) {
      const task = [...tasks].filter(([, task]) => task.at <= end).sort((a,b)=>a[1].at-b[1].at)[0];
      if (!task) break;
      tasks.delete(task[0]); time=task[1].at;task[1].callback();
    }
    time=end;
  };
  win.eval(read('public/speedbox/register-infrastructure.js'));
  const ui = await loadSourceUi(dom);
  const root = {namespace: {v:ui.http,R:ui.Vue.nextTick,TestRouter:ui.router,TestToastState:{items:ui.useToast().toasts}}};
  const first = new Promise(resolve => {resolveFirst=resolve});
  root.namespace.v.get=async url=>{
    if(url==='/tickets/unread/count')return {data:response || await first};
    if(url==='/user/panel')return{data:{status:'ok',user:{id:2,code:'testrep'}}};
    return{data:[]};
  };
  root.namespace.v.post=async()=>({data:{ok:true}});
  const flush = async()=>{for(let n=0;n<12;n++){await Promise.resolve();await root.namespace.R();}};
  const app=ui.Vue.createApp({...ui.Panel,setup(props,context){ui.Panel.setup(props,context);return()=>null}},{role:2,userCode:'testrep'});
  const errors=[];app.config.errorHandler=error=>errors.push(error);
  app.use(root.namespace.TestRouter);
  app.provide('bootstrap',{guest:false,user:{id:2,code:'testrep'},pending_counts:{announcements:1,tickets:2}});
  const panel=app.mount('#fixture');
  win.__testNotices={refresh:()=>panel.speedboxRefreshPartnerNotices(true),counts:()=>({announcements:panel.bootstrapAnnouncements,tickets:panel.ticketUnreadCount,total:panel.totalHeaderBadge})};
  const snapshot = () => ({count:2,announcement_count:1,notifications:[{id:41,ticket_number:12345},{id:42,ticket_number:12346}],notification_ids:[41,42]});
  assert.equal(win.__testNotices.counts().total,3,'Initial badge includes both counts before delayed requests complete');
  await flush();assert.equal(win.__testNotices.counts().total,3,'A pending request does not replace the total with a partial count');
  return {dom,win,app,errors,root,flush,tick,snapshot,release(data){response=data;resolveFirst(data)},async refresh(data){response=data;await win.__testNotices.refresh();await flush()},save(){return Object.fromEntries(Array.from({length:win.localStorage.length},(_,i)=>{const key=win.localStorage.key(i);return[key,win.localStorage.getItem(key)]}))}};
}

const first=await boot();first.release(first.snapshot());await first.flush();
assert.deepEqual(JSON.parse(JSON.stringify(first.win.__testNotices.counts())),{announcements:1,tickets:2,total:3});
assert.equal(first.root.namespace.TestToastState.items.length,1,'Only the first ticket reply is visible during a burst');
assert.equal(first.root.namespace.TestToastState.items[0].duration,4500,'Ticket replies receive the full reading interval');
assert.ok(first.root.namespace.TestToastState.items[0].message.includes('۱۲٬۳۴۵'));
first.tick(3000);assert.equal(first.root.namespace.TestToastState.items[0].leaving,false,'Reply is not dismissed after the old three-second timeout');
first.tick(1850);await first.flush();assert.equal(first.root.namespace.TestToastState.items.length,1);
assert.ok(first.root.namespace.TestToastState.items[0].message.includes('۱۲٬۳۴۶'),'The second reply waits for the first');
first.tick(4850);await first.flush();assert.equal(first.root.namespace.TestToastState.items.length,0);
const saved=first.save();assert.deepEqual(first.errors,[]);first.app.unmount();first.dom.window.close();

const refreshed=await boot(saved);refreshed.release(refreshed.snapshot());await refreshed.flush();
assert.equal(refreshed.root.namespace.TestToastState.items.length,0,'Actual component remount and persisted storage do not replay seen replies');
await refreshed.refresh({count:2,announcement_count:1,notifications:[{id:42,ticket_number:12346},{id:43,ticket_number:12347}],notification_ids:[42,43]});
assert.equal(refreshed.root.namespace.TestToastState.items.length,1,'A new reply with the same unread count displays once');
assert.equal(refreshed.win.__testNotices.counts().total,3,'Toast presentation preserves the unread badge');
assert.ok(refreshed.root.namespace.TestToastState.items[0].message.includes('۱۲٬۳۴۷'));
await refreshed.refresh({count:1,announcement_count:0,notifications:[{id:43,ticket_number:12347}],notification_ids:[43]});
assert.equal(refreshed.win.__testNotices.counts().total,1,'Reading messages and announcements updates both badge counts together');
refreshed.win.localStorage.removeItem('token');refreshed.win.localStorage.removeItem('user');
await refreshed.root.namespace.TestRouter.push('/login');await refreshed.flush();
assert.equal(refreshed.root.namespace.TestToastState.items.length,0,'Persistent replies are removed when signing out');
assert.deepEqual(refreshed.errors,[]);refreshed.app.unmount();refreshed.dom.window.close();
console.log('Actual partner badge initialization, delayed polling, serialized reply timers, refresh persistence and same-count new replies passed.');
