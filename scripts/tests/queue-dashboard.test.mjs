// node scripts/tests/queue-dashboard.test.mjs (with jsdom available)
import {readFileSync} from 'node:fs';
import assert from 'node:assert/strict';
const {JSDOM}=await import(process.env.PARTNER_TEST_JSDOM||'jsdom');
const read=path=>readFileSync(new URL('../../'+path,import.meta.url),'utf8');
const template=read('resources/views/infrastructure.blade.php').match(/<template[^>]*>([\s\S]*)<\/template>/)[1];
for(const status of ['running','not_seen','inline']){
 const dom=new JSDOM('<div id="root">'+template+'</div>',{url:'http://fixture.test/',runScripts:'outside-only',pretendToBeVisual:true});
 const win=dom.window,errors=[];
 win.matchMedia=()=>({matches:false,addEventListener(){},removeEventListener(){}});
 win.ResizeObserver=class{observe(){} disconnect(){}};
 win.AbortController=AbortController;
 win.localStorage.setItem('token','fixture-token');
 win.localStorage.setItem('user','{"role":1}');
 const data={status,wait:2,recentJobs:3,jobsPerMinute:1,peakJobsPerMinute:3,failedJobs:0,processes:status==='running'?1:0,
  workload:[{name:'default',pending:12,running:1,delayed:2},{name:'deployments',pending:3,running:0,delayed:0}],active_queues:status==='running'?['default','deployments']:[],recent:[]};
 win.fetch=async url=>({ok:true,json:async()=>({data:String(url).endsWith('/queue')?data:String(url).endsWith('/overview')?{managed_servers:0,online_machines:0,machines:0,online_nodes:0,nodes:0,active_users:0}:[],period:{}})});
 win.SpeedboxNodeEditor={protocolBadge:()=>'',mount:()=>()=>{}};
 win.eval(read('public/speedbox/infrastructure.js'));
 const ui=win.SpeedboxInfrastructure.mount(win.document.querySelector('#root'),{view:'overview',router:{replace(){}},notify:{error:m=>errors.push(m),notif(){}}});
 for(let i=0;i<8;i++)await new Promise(resolve=>setTimeout(resolve,0));
 const lanes=win.document.querySelectorAll('.queue-lane');
 assert.equal(lanes.length,2);
 assert.equal(lanes[0].dataset.queueName,'default');assert.equal(lanes[1].dataset.queueName,'deployments');
 assert.equal(lanes[0].querySelector('code').textContent,'default');assert.equal(lanes[1].querySelector('code').textContent,'deployments');
 assert.deepEqual(Array.from(lanes[0].querySelectorAll('bdi'),n=>n.textContent),['۱۲','۱','۲']);
 assert.ok(Array.from(lanes).every(n=>n.querySelector('.queue-lane-icon svg')));
 assert.ok(Array.from(lanes).every(n=>n.querySelector('.queue-lane-status').textContent===(status==='running'?'فعال':status==='inline'?'اجرای مستقیم':'مشاهده نشده')));
 assert.equal(win.document.querySelectorAll('[data-queue-action="recent"]').length,1);
 assert.equal(win.document.querySelectorAll('[data-queue-action="failed"]').length,1);
 assert.deepEqual(errors,[]);
 ui.dispose();dom.window.close();
}
console.log('Queue names, Persian counts, running/stopped/inline states and existing job actions passed.');
