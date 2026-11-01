// Real maintained Vue components; mocked API, no live account writes.
import assert from 'node:assert/strict';
import { reservationStatusLabel } from '../../resources/js/utils/reservation-status.js';
import { loadSourceUi } from './helpers/source-ui.mjs';
const { JSDOM } = await import(process.env.SPEEDBOX_TEST_JSDOM || 'jsdom');
let checks = 0;
for (const role of ['admin', 'representative']) {
  const dom = new JSDOM('<div id="fixture"></div>', {url:'https://fixture.test',runScripts:'outside-only',pretendToBeVisual:true});
  const win=dom.window;win.matchMedia=()=>({matches:false,addEventListener(){},removeEventListener(){}});
  win.fetch=async()=>({json:async()=>({})});win.AbortController=AbortController;win.scrollTo=()=>{};
  win.HTMLCanvasElement.prototype.toDataURL=()=> 'data:image/png;base64,VEVTVA==';
  win.HTMLCanvasElement.prototype.getContext=function(){return {createImageData:(w,h)=>({data:new Uint8ClampedArray(w*h*4)}),clearRect(){},putImageData(){}}};
  const warningTimers=new Map();let warningSequence=0;
  const nativeTimeout=win.setTimeout.bind(win),nativeClear=win.clearTimeout.bind(win);
  win.setTimeout=(callback,delay,...args)=>{if(delay===6000){const id=--warningSequence;warningTimers.set(id,()=>callback(...args));return id;}return nativeTimeout(callback,delay,...args);};
  win.clearTimeout=id=>{if(!warningTimers.delete(id))nativeClear(id);};
  const ui=await loadSourceUi(dom);
  const reserve={id:9,title:'سه‌ماهه ۶۰ گیگ ماهانه',traffic_label:'۶۰ گیگابایت ماهانه',duration_days:90,activation_label:'پس از پایان اعتبار طرح فعلی، طرح رزرو فعال خواهد شد.',can_refund:true};
  let policy={reservation:reserve,account_has_periodic_reset:false,account_ended:false,renewal_mode:2,renewal_plan_methods:{1:{'1m':false,'3m':false},2:{'1m':true}}};
  ui.http.get=async url=>({data:url==='/users/reservation'?{data:policy}:{data:[],user:{},plan_groups:[],groups:[],volume_groups:[]}});
  let posts=0;ui.http.post=async url=>{if(/\/(extend|topup)$/.test(url))posts++;return{data:{}}};
  const component=role==='admin'?ui.Users:ui.Panel;
  const app=ui.Vue.createApp(component,{role:role==='admin'?1:2,userCode:'fixture'}),errors=[];
  app.config.errorHandler=e=>errors.push(e);app.use(ui.router);const state=app.mount('#fixture');
  const flush=async()=>{for(let i=0;i<12;i++){await Promise.resolve();await ui.Vue.nextTick();}};
  await flush();
  const account={id:11,email:'demo@fixture.com',token:'x',plan_id:1,u:10,d:0,transfer_enable:10737418240,expired_at:Math.floor(Date.now()/1000)+86400*28,banned:0,account_group_type:'time',account_is_volume:0,account_tariff_group_id:1,account_plan_name:'Fixture',reservation:reserve};
  state.users=[account];Object.assign(state.viewUserData,account,policy);state.showViewUser=true;state.viewMode='result';
  state.tariffPlanGroups=[{id:1,name:'No reset',group_type:'time',plan_enabled:{'1m':true,'3m':true}},{id:2,name:'Monthly',group_type:'time',plan_enabled:{'1m':true}}];
  await flush();
  let actions=win.document.querySelector('.view-actions-row');
  assert.ok(actions.textContent.includes('تمدید اکانت'));assert.ok(!actions.textContent.includes('ترافیک اضافه'));checks+=2;
  assert.ok(win.document.querySelector('.account-reservation').textContent.includes(reserve.title));checks++;
  await state.toggleExtendPane();await flush();assert.equal(state.reservationWarning,true);assert.equal(state.showExtendPane,false);assert.equal(posts,0);checks+=3;
  assert.ok(win.document.querySelector('[role="alert"]').textContent.includes('امکان خرید طرح رزرو دیگری'));checks++;
  const icon=win.document.querySelector('.reservation-marker');assert.ok(icon);checks++;
  assert.equal(icon.tagName.toLowerCase(),'svg');assert.ok(icon.nextElementSibling.classList.contains('reservation-expiry-text'));checks+=2;
  icon.closest('.custom-tooltip-trigger').dispatchEvent(new win.KeyboardEvent('keydown',{key:'Enter',bubbles:true}));await flush();
  const tooltip=win.document.querySelector('[role="tooltip"]');assert.ok(tooltip);assert.ok(tooltip.querySelector('.reservation-tooltip-line').firstElementChild.classList.contains('reservation-symbol'));checks++;assert.equal(tooltip.querySelector('.reservation-tooltip-line').textContent.trim(),'طرح رزرو: '+reserve.title);assert.ok(!tooltip.textContent.includes('فعال‌سازی'));checks+=3;
  const card=win.document.querySelector('.account-reservation');
  assert.equal(card.querySelectorAll('.reservation-heading .reservation-symbol').length,1);
  assert.ok(card.querySelector('.reservation-heading').firstElementChild.classList.contains('reservation-symbol'));
  assert.equal(card.querySelectorAll('button').length,0);checks+=3;
  assert.equal(warningTimers.size,1);const firstTimer=[...warningTimers.keys()][0];
  await state.toggleExtendPane();await flush();
  assert.equal(warningTimers.size,1);assert.ok(!warningTimers.has(firstTimer));checks+=3;
  for(const [id,callback] of [...warningTimers]){warningTimers.delete(id);callback();}await flush();
  assert.equal(state.reservationWarning,false);assert.equal(card.querySelector('.reservation-repeat-warning'),null);
  assert.ok(card.textContent.includes(reserve.title));checks+=3;
  actions=win.document.querySelector('.reservation-primary-actions');
  {
    const cancel=actions.querySelector('.reservation-cancel-trigger');assert.ok(cancel);assert.equal(cancel.querySelectorAll('i,svg').length,0);checks+=2;
    cancel.click();await flush();
    const confirmation=card.querySelector('.reservation-cancel-confirmation');assert.ok(confirmation.textContent.includes('کیف پول پرداخت‌کننده'));
    assert.deepEqual([...actions.querySelectorAll('button')].map(b=>b.textContent),['تأیید لغو','انصراف']);
    assert.equal(warningTimers.size,0);assert.equal(posts,0);checks+=4;
    actions.querySelector('.reservation-abort').click();await flush();
    assert.equal(card.querySelector('.reservation-cancel-confirmation'),null);assert.ok(actions.textContent.includes('تمدید اکانت'));checks+=2;
  }
  state.showExtendPane=true;await flush();
  const pane=win.document.querySelector('.account-renew-pane'),notice=pane.querySelector('.reservation-purchase-notice');
  assert.ok(pane.querySelector('.plans-grid').compareDocumentPosition(notice)&win.Node.DOCUMENT_POSITION_FOLLOWING);
  assert.ok(notice.compareDocumentPosition(pane.querySelector('.create-actions'))&win.Node.DOCUMENT_POSITION_FOLLOWING);
  assert.equal(notice.dir,'rtl');assert.ok(notice.querySelector('.fa-exclamation-triangle'));assert.equal(notice.tagName,'P');checks+=5;
  state.showExtendPane=false;
  policy={...policy,account_has_periodic_reset:true};Object.assign(state.viewUserData,policy);state.reservationWarning=false;await flush();
  actions=win.document.querySelector('.view-actions-row');assert.ok(actions.textContent.includes('ترافیک اضافه'));checks++;
  await state.toggleTopupPane();await flush();assert.equal(state.showTopupPane,true);assert.equal(state.reservationWarning,false);checks+=2;
  state.showTopupPane=false;Object.assign(state.viewUserData,{account_group_type:'volume',account_is_volume:1,account_has_periodic_reset:false});
  if(role==='admin')state.viewIsVolumeAccount=true;
  await flush();actions=win.document.querySelector('.view-actions-row');assert.ok(actions.textContent.includes('خرید ترافیک'));assert.ok(!actions.textContent.includes('تمدید اکانت'));checks+=2;
  Object.assign(state.viewUserData,{account_group_type:'time',account_is_volume:0});if(role==='admin')state.viewIsVolumeAccount=false;await state.toggleExtendPane();await flush();assert.equal(warningTimers.size,1);checks++;
  // Active accounts can reserve either reset type; immediate mode keeps its guard.
  policy={...policy,reservation:null};state.showExtendPane=false;state.showTopupPane=false;
  for(const periodic of [false,true])for(const mode of [0,1,2,3]){
    policy={...policy,account_has_periodic_reset:periodic,renewal_mode:mode};
    Object.assign(state.viewUserData,policy);await state.toggleExtendPane();await flush();
    const native=win.document.querySelector('#'+(role==='admin'?'admin':'partner')+'-renew-tariff');
    native.click();await flush();
    const options=[...win.document.querySelectorAll('[role="listbox"] [role="option"]')].map(b=>b.textContent.trim());
    assert.ok(options.includes('Monthly'));checks++;
    assert.equal(options.includes('No reset'),!periodic||mode>=2);checks++;
    const choice=[...win.document.querySelectorAll('[role="listbox"] [role="option"]')].find(b=>b.textContent.trim()===(periodic&&mode<2?'Monthly':'No reset'));
    choice.click();await flush();
    const month=[...win.document.querySelectorAll('.account-renew-pane .plans-grid button')].find(b=>b.textContent.includes('یک ماهه'));
    assert.equal(month.disabled,false);checks++;
    state.showExtendPane=false;await flush();
  }
  assert.deepEqual(errors,[]);app.unmount();assert.equal(warningTimers.size,0);checks++;dom.window.close();
}
console.log('Subscription reservation UI: '+checks+' checks passed for admin and representative modals, timed/repeated warnings, footer cancellation, notice order, repeat purchase, extra traffic and integrated tooltip.');

for(const [state,label] of [['reserved','رزرو موفق'],['cancelled','لغو رزرو موفق'],['activated','فعال‌سازی موفق']]){
  assert.equal(reservationStatusLabel({status:'success',reservation_state:state},'موفق'),label);checks++;
  for(const status of ['failed','pending']){assert.equal(reservationStatusLabel({status,reservation_state:state},status),status);checks++;}
}
console.log('Reservation selection and compact payment statuses: '+checks+' total checks passed.');


assert.equal(reservationStatusLabel({status:'success',reservation_state:'activated',reservation_history:[{state:'reserved'},{state:'activated'}]},'موفق'),'رزرو موفق');
