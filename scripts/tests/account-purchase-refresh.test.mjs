// Real SFC purchase handlers; test-only named exports replace minifier aliases.
import assert from 'node:assert/strict';
import { loadSourceUi } from './helpers/source-ui.mjs';
const { JSDOM } = await import(process.env.SPEEDBOX_TEST_JSDOM || process.env.PARTNER_TEST_JSDOM || 'jsdom');
const fresh = { id: 11, email: 'demo@owner.com', token: 'new-token', subscribe_url: 'https://fixture.test/new-token', u: 0, d: 0, transfer_enable: 20 * 1073741824,
  expired_at: 1900000000, plan_id: 5, group_id: 3, device_limit: 4, reset_count: 5, banned: 0,
  account_plan_name: 'New tariff', account_group_type: 'volume', account_is_volume: 1, account_tariff_group_id: 7 };
let cases = 0;
for (const role of ['admin', 'representative']) {
  const dom = new JSDOM('<div id="fixture"></div>', {url:'http://fixture.test',runScripts:'outside-only',pretendToBeVisual:true});
  const win = dom.window;
  win.matchMedia=()=>({matches:false,addEventListener(){},removeEventListener(){}});
  win.fetch=async()=>({json:async()=>({})});win.AbortController=AbortController;win.scrollTo=()=>{};
  win.HTMLCanvasElement.prototype.toDataURL=()=> 'data:image/png;base64,VEVTVA==';
  win.HTMLCanvasElement.prototype.getContext=function(){return {createImageData:(w,h)=>({data:new Uint8ClampedArray(w*h*4)}),clearRect(){},putImageData(){}}};
  const ui = await loadSourceUi(dom);
  ui.http.get=async()=>({data:{data:[],user:{},plan_groups:[],groups:[],volume_groups:[]}});
  ui.http.post=async()=>({data:{}});
  const component=role==='admin'?ui.Users:ui.Panel;
  const app=ui.Vue.createApp({...component,setup(props,context){component.setup(props,context);return()=>null}},{role:role==='admin'?1:2,userCode:'fixture'}),renderErrors=[];
  app.config.errorHandler=error=>renderErrors.push(error);app.use(ui.router);
  const state=app.mount('#fixture');
  const flush=async()=>{for(let i=0;i<12;i++){await Promise.resolve();await ui.Vue.nextTick();}};
  await flush();
  for (const kind of ['renew','topup']) for (const scenario of ['success','post-error','read-error','wrong-id','changed-modal','busy']) {
    const modal={id:11,email:fresh.email,d:900,transfer_enable:10,expired_at:1,account_tariff_group_id:7,account_group_type:'time',account_is_volume:0};
    Object.assign(state.viewUserData,modal);state.users=[{...modal}];state.tokenHit=[{...modal}];
    state.showViewUser=true;state.tariffPlanGroups=[{id:7,name:'Tariff',group_type:'time',enable:1}];state.selectedTariffGroupId=7;
    state.extendChoice='1m';state.topupGB=3;
    const posts=[],reads=[],toasts=ui.useToast().toasts;toasts.splice(0);
    let release;const pause=new Promise(done=>{release=done});
    ui.http.post=async(url,body)=>{if(url==='/users/online')return {data:{data:[]}};assert.equal(url,(role==='admin'?'/admin':'')+'/users/'+(kind==='renew'?'extend':'topup'));posts.push({url,body});if(scenario==='busy')await pause;if(scenario==='post-error')throw {response:{data:{message:'Server rejected purchase'}}};return {data:{}};};
    ui.http.get=async url=>{reads.push(url);if(scenario==='read-error')throw Error('Offline');if(scenario==='changed-modal')Object.assign(state.viewUserData,{id:12,email:'other@owner.com',d:77});return {data:{data:scenario==='wrong-id'?{...fresh,id:12}:{...fresh}}};};
    const purchase=kind==='renew'?()=>state.submitExtend():()=>state.submitTopup();
    const first=purchase();
    if(scenario==='busy'){await purchase();assert.equal(posts.length,1,'Double click cannot charge twice');release();}
    await first;await flush();
    assert.equal(posts.length,1);assert.equal(posts[0].url,(role==='admin'?'/admin':'')+'/users/'+(kind==='renew'?'extend':'topup'));
    assert.equal(posts[0].body.email,fresh.email);assert.equal(kind==='renew'?state.extendBusy:state.topupBusy,false);
    const successes=toasts.filter(item=>item.type==='success'),errors=toasts.filter(item=>item.type==='error');
    if(scenario==='post-error'){
      assert.equal(reads.length,0);assert.equal(successes.length,0);assert.deepEqual(Array.from(errors,item=>item.message),['Server rejected purchase']);
    }else{
      assert.deepEqual(reads,['/users/11/details']);assert.equal(successes.length,1);assert.equal(errors.length,0,'A post-purchase read failure adds no error toast');
      if(['read-error','wrong-id'].includes(scenario))assert.equal(state.users[0].d,900);
      else{
        for(const list of [state.users,state.tokenHit])for(const [field,value]of Object.entries(fresh))assert.equal(list[0][field],value,field+' refreshes in both lists');
        if(scenario==='changed-modal'){assert.equal(state.viewUserData.id,12);assert.equal(state.viewUserData.d,77);}
        else{
          for(const [field,value]of Object.entries(fresh))assert.equal(state.viewUserData[field],value,field+' refreshes in modal');
          assert.equal(state.viewUserData.inviteLink,fresh.subscribe_url);assert.equal(state.viewUserData.qrDataUrl,'data:image/png;base64,VEVTVA==');
        }
      }
    }
    cases++;
  }
  assert.deepEqual(renderErrors,[]);app.unmount();dom.window.close();
}
console.log('Admin and representative SFC renewal/topup handlers: '+cases+' success, failure, double click and stale modal scenarios passed.');
