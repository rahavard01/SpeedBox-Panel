import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
const { JSDOM } = await import(process.env.PARTNER_TEST_JSDOM || 'jsdom');
const css = createRequire(process.env.PARTNER_TEST_JSDOM || import.meta.url)('css-tree');
function viewportStyles(text,width){
  const ast=css.parse(text);
  const flatten=list=>[...list].map(node=>{
    if(node.type==='Atrule'&&node.name==='media'){
      const query=css.generate(node.prelude),bounds=[...query.matchAll(/(min|max)-width:\s*(\d+)px/g)];
      return bounds.length&&bounds.every(([,kind,value])=>kind==='max'?width<=Number(value):width>=Number(value))?flatten(node.block.children):'';
    }
    return css.generate(node);
  }).join('\n');
  return flatten(ast.children);
}
const read = file => readFileSync(new URL('../../' + file, import.meta.url), 'utf8');
const manifest = JSON.parse(read('public/build/manifest.json'));
const rootFile = manifest['resources/js/app.js'].file;
let source = read('public/build/' + rootFile).replace('Cs.mount("#app")', 'void 0').replace('Cs.use(us)', 'void 0');
const names = ['Billing','Tickets','Announcements','Tutorials'].map(name => {
  const index = source.indexOf('__name:"' + name + '"');
  return source.slice(index - 100, index).match(/([\w$]+)=\{$/)[1];
});
source += '\nexport{ac as ReviewApp,M6 as ReviewNotify,et as ReviewToastState,' + names.map((name, index) => name + ' as Review' + ['Billing','Tickets','Announcements','Tutorials'][index]).join(',') + '};';
const transactions = [{ id:1,type:'ai_receipt_check_fee',amount:-4000,signed_amount:-4000,user_code:'testrep',username:'rep@example.invalid',affected_users:[],balance_after:12345,status:'success',created_at:'2026-10-11T12:00:00Z',performed_by_role:'system' }];
const prices = { overage_per_gb:1500,test:1000,m1:100000,m3:250000,m6:450000,m12:850000 };
const referral = { id:7,status:'pending',referee_name:'New partner',referee_code:'newrep',referee_email:'new@example.invalid',referrer:{id:2,name:'Existing',code:'testrep',partner_plan_type:'time'},pricing_strategy:'custom',created_at:'2026-10-11T12:00:00Z' };
const tariff = { status:'ok',v2_options:[{id:1,label:'Plan',device_limit:3}],volume_groups:[{id:1,name:'Volume',is_default:1,min_traffic_gb:1,items:[{plan_key:'volume_gig',default_price:100,details:'Volume',v2_plan_id:1,device_limit:2}]}],groups:[{id:2,name:'Time',items:['gig','test','1m','3m','6m','12m'].map(plan_key=>({plan_key,default_price:100,details:'Time',v2_plan_id:1,device_limit:2,enable:1}))}] };
for (const width of [375,820,1280]) {
  const mobile=width<900;
  const dom = new JSDOM('<div id="fixture"></div>',{url:'http://fixture.test/',runScripts:'outside-only',pretendToBeVisual:true});
  const win = dom.window;
  win.localStorage.setItem('user','{"id":2,"role":2,"code":"testrep"}');
  win.matchMedia=()=>({matches:mobile,addEventListener(){},removeEventListener(){}});
  win.fetch=async()=>({json:async()=>({})});win.AbortController=AbortController;win.scrollTo=()=>{};
  win.eval(read('public/speedbox/register-infrastructure.js'));
  // Model Vite's late page-style loading, after shared overrides, at each width.
  for (const file of ['public/build/assets/app--UQp4rU8.css','public/speedbox/panel-controls.css',...['Settings','Announcements','Tutorials'].flatMap(name=>manifest['resources/js/pages/admin/'+name+'.vue'].css.map(file=>'public/build/'+file))]) {
    const style=win.document.createElement('style');style.textContent=viewportStyles(read(file),width);win.document.head.append(style);
  }
  let nativeRenders=0;
  const nativeRenderer=win.__SPEEDBOX_RENDER_NATIVE_SELECT__;
  win.__SPEEDBOX_RENDER_NATIVE_SELECT__=options=>{
    if(++nativeRenders>100)throw Error('Native selector render loop: '+options.placeholder);
    return nativeRenderer(options);
  };
  const root=new vm.SourceTextModule(source,{context:dom.getInternalVMContext(),initializeImportMeta:meta=>{meta.url='http://fixture.test/'+rootFile}});
  await root.link(()=>{throw Error('Unexpected core import')});await root.evaluate();
  root.namespace.ReviewNotify('Operation feedback',{replace:false});
  root.namespace.ReviewNotify('Operation feedback',{replace:false});
  assert.equal(root.namespace.ReviewToastState.items.length,2,'Repeated user-action feedback is not suppressed by notification deduplication');
  root.namespace.ReviewToastState.items.splice(0);
  const modules=new Map([[rootFile.split('/').at(-1),root]]);
  async function load(file){
    file=file.split('/').at(-1);
    if(modules.has(file))return modules.get(file);
    const module=new vm.SourceTextModule(read('public/build/assets/'+file),{context:dom.getInternalVMContext(),initializeImportMeta:meta=>{meta.url='http://fixture.test/assets/'+file}});
    modules.set(file,module);await module.link(specifier=>load(specifier));await module.evaluate();return module;
  }
  root.namespace.v.get=async url=>{
    if(url==='/admin/transactions'||url==='/user/transactions')return{data:transactions};
    if(url==='/admin/referrals')return{data:{ok:true,items:[referral],meta:{current_page:1,last_page:1,total:1}}};
    if(url==='/admin/referrals/7')return{data:{ok:true,item:referral,defaults:{commission_percent:10}}};
    if(url.includes('pricing-preview'))return{data:{groups:[{id:2,name:'Time',is_default:1,default:prices}],volume_groups:[]}};
    if(url==='/admin/tariffs')return{data:tariff};
    if(url.includes('/admin/tariff-groups/')&&url.endsWith('/partners'))return{data:{status:'ok',partners:[],selected_partner_ids:[]}};
    if(url==='/announcements')return{data:[{id:1,title:'اطلاعیه',body:'Short',publish_at:'2026-10-11T12:00:00Z'},{id:2,title:'عنوان خیلی بلند '.repeat(20),body:'Long',publish_at:'2026-10-11T12:00:00Z'}]};
    if(url==='/tickets')return{data:{data:[],current_page:1,last_page:1}};
    if(url==='/tutorials')return{data:{items:[{id:1,category:'ios',app_name:'Demo',published_at:'2026-10-11T12:00:00Z'}],has_more:false}};
    if(url==='/admin/users/11/details')return{data:{data:{id:11,email:'sample@testrep.com',u:0,d:0,transfer_enable:1000,banned:0},plans:[]}};
    return{data:{data:[]}};
  };
  const flush=async()=>{for(let n=0;n<8;n++){await Promise.resolve();await root.namespace.R();}};
  const mount=async (component,props)=>{const errors=[],app=root.namespace.ReviewApp(component,props);app.config.errorHandler=error=>errors.push(error);app.mount('#fixture');await flush();return{app,errors}};
  for(const component of [(await load(manifest['resources/js/pages/admin/Invoices.vue'].file)).namespace.default,root.namespace.ReviewBilling]){
    const {app,errors}=await mount(component);
    assert.ok(win.document.querySelector('#fixture').textContent.includes('testrep'),'AI receipt charge displays its panel code in both invoice views');
    assert.ok(!win.document.querySelector('#fixture').textContent.includes('Credit Adjustment'));
    assert.deepEqual(errors,[]);app.unmount();await flush();
  }
  {
    const {app,errors}=await mount(root.namespace.ReviewTickets);
    const button=[...win.document.querySelectorAll('button')].find(node=>node.textContent.includes('تیکت جدید'));assert.ok(button);button.click();await flush();
    assert.ok(win.document.querySelector('.final-result1 .modal-actions-row button'),'Submit ticket is inside the glass form');assert.deepEqual(errors,[]);app.unmount();await flush();
  }
  {
    const {app,errors}=await mount((await load(manifest['resources/js/pages/admin/Requests.vue'].file)).namespace.default);
    const button=[...win.document.querySelectorAll('button')].find(node=>node.textContent.includes('بررسی'));assert.ok(button,win.document.querySelector('#fixture').textContent);button.click();await flush();
    assert.equal(win.document.querySelectorAll('.price-box>.edit-row').length,6,'Custom time pricing retains all six real fields');
    assert.equal(win.document.querySelectorAll('.price-box input').length,6);assert.deepEqual(errors,[]);app.unmount();await flush();
  }
  {
    const {app,errors}=await mount((await load(manifest['resources/js/pages/admin/Settings.vue'].file)).namespace.default);
    win.document.querySelector('.chev-btn').click();await flush();
    for(const secondary of win.document.querySelectorAll('.tariff-secondary-controls')){assert.ok(secondary.querySelector('.partner-access-badge1'));assert.ok(secondary.querySelector('.tariff-connection-field'));}
    const field=win.document.querySelector('.tariff-connection-field input');assert.ok(field);assert.equal(field.value,'۲ کاربر');assert.equal(win.document.querySelector('.tariff-connection-field label'),null);
    const group=field.closest('.tariff-group-card'),access=group.querySelector('.partner-access-badge1'),title=group.querySelector('.tariff-group-title input'),order=group.querySelector('.tariff-order-controls button');
    assert.equal(win.getComputedStyle(field).borderRadius,win.getComputedStyle(access).borderRadius,'Limit control keeps the same shape as partner access after late scoped CSS');
    assert.notEqual(win.getComputedStyle(field).backgroundColor,win.getComputedStyle(access).backgroundColor,'Limit has its own blue glass colour');
    assert.equal(win.getComputedStyle(title).height,win.getComputedStyle(order).height,'Order buttons match the name input height');
    assert.equal(win.getComputedStyle(group.querySelector('.tariff-group-head')).direction,'rtl','First DOM item, the order controls, is on the right');
    if(mobile){
      const head=group.querySelector('.tariff-group-head');
      assert.equal(win.getComputedStyle(head).display,'grid');assert.equal(win.getComputedStyle(group.querySelector('.default-badge')).width,'100%','Default fills the third mobile row');
      assert.equal(win.getComputedStyle(head).width,win.getComputedStyle(group.querySelector('.tariff-table')).width,'Header matches the lower tariff boxes');
      assert.equal(win.getComputedStyle(group.querySelector('.tariff-order-controls')).gridColumn,'1');assert.equal(win.getComputedStyle(group.querySelector('.tariff-group-title')).gridColumn,'2');
      const toolbar=win.document.querySelector('.tariff-groups-toolbar');assert.equal(win.getComputedStyle(toolbar).display,'grid');
      for(const button of toolbar.children)assert.equal(win.getComputedStyle(button).width,'100%','Add and sort buttons stack at full card width');
    }
    else assert.equal(win.getComputedStyle(field.parentElement).width,'132px','Desktop limit no longer stretches across 185px');
    const sortButton=win.document.querySelector('.tariff-sort-toggle');sortButton.click();await flush();
    const sortHead=win.document.querySelector('.tariff-sort-mode .tariff-group-head');assert.ok(sortHead);
    assert.deepEqual([...sortHead.children].filter(node=>win.getComputedStyle(node).display!=='none').map(node=>node.className),['tariff-order-controls','tariff-sort-name'],'Sorting shows only order controls and the plan name');
    sortButton.click();await flush();
    field.focus();await flush();assert.equal(field.value,'۲');
    field.value='۳';field.dispatchEvent(new win.Event('input',{bubbles:true}));await flush();assert.equal(field.value,'۳');
    field.value='0';field.dispatchEvent(new win.Event('input',{bubbles:true}));await flush();assert.equal(field.value,'۰');
    field.value='';field.dispatchEvent(new win.Event('input',{bubbles:true}));await flush();assert.equal(field.value,'');field.blur();await flush();assert.equal(field.value,'پیش‌فرض -۳ کاربر');
    field.focus();await flush();field.value='0';field.dispatchEvent(new win.Event('input',{bubbles:true}));field.blur();await flush();assert.equal(field.value,'نامحدود');
    win.document.querySelector('.partner-access-badge1').click();await flush();
    assert.ok(win.document.querySelector('.partner-access-modal .partner-modal-box'));
    assert.equal(win.getComputedStyle(win.document.querySelector('.partner-access-modal .partner-modal-box')).width,'94%','Inner access box is slightly narrower and centered');
    assert.ok(win.getComputedStyle(win.document.querySelector('.partner-access-modal')).width.includes('400px'),'Outer access modal uses the smaller reviewed width');
    assert.deepEqual(errors,[]);app.unmount();await flush();
  }
  {
    const {app,errors}=await mount(root.namespace.ReviewAnnouncements);
    assert.equal(win.document.querySelectorAll('.announce-head').length,2);
    for(const header of win.document.querySelectorAll('.announce-head')){assert.ok(header.querySelector('.announce-date'));assert.equal(header.querySelector('.announce-title').firstChild.nodeName,'I','Status comes before the title');assert.ok(!header.querySelector('.announce-date').textContent.includes('تاریخ انتشار'));assert.equal(win.getComputedStyle(header).display,'grid','Scoped Panel styles cannot restore the reversed flex layout');assert.equal(win.getComputedStyle(header).direction,'rtl');assert.equal(win.getComputedStyle(header).gridTemplateAreas,'"title date"','Date stays opposite the title on mobile too');}
    assert.deepEqual(errors,[]);app.unmount();await flush();
  }
  {
    const {app,errors}=await mount(root.namespace.ReviewTutorials);
    const date=win.document.querySelector('.announce-time');assert.ok(date);assert.ok(!date.textContent.includes('تاریخ انتشار'));
    assert.ok(date.parentElement.firstElementChild.classList.contains('chip-right'),'Category is the first item in the RTL row, with date opposite it');
    assert.deepEqual(errors,[]);app.unmount();await flush();
  }
  {
    const spacing=[];
    for(const name of ['Announcements','Tutorials']){
      const {app,errors}=await mount((await load(manifest['resources/js/pages/admin/'+name+'.vue'].file)).namespace.default);
      const create=[...win.document.querySelectorAll('button')].find(node=>node.textContent.includes(name==='Announcements'?'اعلان جدید':'آموزش جدید'));assert.ok(create);create.click();await flush();
      const glass=win.document.querySelector('.create-modal .final-result2'),action=glass.querySelector('.modal-actions button');
      const last=name==='Announcements'?glass.querySelector(':scope>.form-row'):glass.querySelector('.modal-body .form-row:last-child');
      spacing.push({gap:win.getComputedStyle(glass).rowGap==='normal'?win.getComputedStyle(glass).gap:win.getComputedStyle(glass).rowGap,margin:win.getComputedStyle(last).marginBottom,button:win.getComputedStyle(action).marginTop});
      assert.deepEqual(errors,[]);app.unmount();await flush();
    }
    assert.deepEqual(spacing[0],spacing[1],'Announcement publication/action gap equals the tutorial form after lazy CSS loads');
  }
  {
    const userSource=read('public/build/'+manifest['resources/js/pages/admin/Users.vue'].file);
    const helper=userSource.match(/import SpeedboxAccountActions from '\.\/([^']+)'/)[1];
    const {app,errors}=await mount((await load(helper)).namespace.default,{user:{id:11,email:'sample@testrep.com'},mobile});
    const trigger=win.document.querySelector('button[aria-haspopup]');assert.ok(trigger);trigger.click();await flush();
    const remove=[...win.document.querySelectorAll('[role="menuitem"]')].find(node=>node.textContent.trim()==='حذف');assert.ok(remove);remove.click();await flush();
    const modal=win.document.querySelector('.account-operation-modal');assert.ok(modal);assert.ok(modal.textContent.includes('حذف کاربر'));assert.ok(modal.querySelector('.operation-confirmation').textContent.includes('نام کاربری:'));
    assert.equal(modal.querySelector('.operation-warning').textContent,'این کاربر حذف خواهد شد، اما سوابق مالی او حفظ می‌شود.');
    const confirm=modal.querySelector('.operation-delete-confirm'),cancel=modal.querySelector('.button.cancel');assert.equal(confirm.textContent,'تأیید حذف');assert.ok(cancel);assert.notEqual(win.getComputedStyle(confirm).backgroundColor,win.getComputedStyle(cancel).backgroundColor,'Confirm deletion is visibly distinct from cancel');
    assert.deepEqual(errors,[]);app.unmount();await flush();
  }
  dom.window.close();
}
console.log('Actual Vue controls, card order and late scoped CSS passed at 375, 820 and 1280 px; announcement action spacing matches Tutorials.');
