// Real UI modules in a DOM; run with PARTNER_TEST_JSDOM pointing to jsdom.
import {readFileSync} from 'node:fs';
import assert from 'node:assert/strict';
const {JSDOM,VirtualConsole}=await import(process.env.PARTNER_TEST_JSDOM||'jsdom');
const read=p=>readFileSync(new URL('../../'+p,import.meta.url),'utf8');
const template=read('resources/views/infrastructure.blade.php').match(/<template[^>]*>([\s\S]*)<\/template>/)[1];
const tick=()=>new Promise(r=>setTimeout(r,15));
async function fixture(mobile,view,{lifecycle=false}={}){
 const errors=[],virtualConsole=new VirtualConsole();
 virtualConsole.on('jsdomError',error=>errors.push(error));
 const dom=new JSDOM('<div id="root">'+template+'</div>',{url:'https://fixture.test',runScripts:'outside-only',pretendToBeVisual:true,virtualConsole});
 const w=dom.window;w.matchMedia=()=>({matches:mobile,addEventListener(){},removeEventListener(){}});w.ResizeObserver=class{observe(){}disconnect(){}};w.scrollTo=()=>{};
 w.HTMLElement.prototype.scrollIntoView=function(){this.dataset.scrolled='1';};
 const viewport=new w.EventTarget();viewport.height=800;viewport.offsetTop=0;Object.defineProperty(w,'visualViewport',{value:viewport});
 w.localStorage.setItem('token','fixture');w.localStorage.setItem('user','{"role":1}');
 let count=0,phase='running',log='first\n',blocked=null,release=null;
 let operation=lifecycle?null:{action:'install',status:'failed',progress:55,updated_at:new Date().toISOString()};
 let vpnStatus=lifecycle?'stopped':'failed';
 const calls=[];
 const timer=w.setTimeout.bind(w);w.setTimeout=(fn,ms,...args)=>timer(fn,[1500,3000].includes(ms)?20:ms,...args);
 w.fetch=async (url, options)=>{
  const path=String(url).split('/infrastructure')[1].split('?')[0];let data=[];
  const method=options?.method||'GET';
  if(method!=='GET')calls.push({path,method,body:options.body&&JSON.parse(options.body)});
  if(lifecycle&&method==='POST'&&/^\/(wireguard|anyconnect)\/\d+\/actions$/.test(path)){
   vpnStatus='queued';
   operation={action:'enable',status:'queued',progress:0};
  }
  const host={id:1,name:'france',host:'127.0.0.1'};
  const last_error='خواندن کلید میزبان SSH ناموفق بود.';
  if(path==='/wireguard') return {ok:true,json:async()=>({
   data:[{id:1,name:'WireGuard 1',server:host,mode:'direct',endpoint_host:host.host,endpoint_port:51820,enabled:!lifecycle||['queued','ready'].includes(vpnStatus),public_key:'fixture',status:vpnStatus,last_error,operation}],
   servers:[host]
  })};
  if(path==='/anyconnect'&&method==='GET') data=[{id:2,name:'AnyConnect 1',managed_server_id:1,endpoint_host:'vpn.fixture.test',endpoint_port:443,enabled:!lifecycle||['queued','ready'].includes(vpnStatus),status:vpnStatus,last_error,operation}];
  if(path==='/servers')data=[{id:1,name:'france',host:'127.0.0.1',ssh_port:22,status:'online',deployments:[],machine:{is_active:true,load_status:{cpu:12,mem:{total:100,used:20},disk:{total:200,used:50}}}}];
  if(['/servers/1/deployments','/anyconnect/2/operations','/wireguard/1/operations'].includes(path)){
   count++;if(blocked)await new Promise(resolve=>{release=resolve;});
   data=[{id:9,action:'install',status:phase,progress:phase==='running'?55:100,log,created_at:'2026-10-27T12:00:00Z'}];
  }
  return {ok:true,json:async()=>({data,meta:{kernel_capabilities:{protocols:{shadowsocks:['singbox','xray']},features:{ss2022_chacha:{kernels:['singbox','xray']}}}}})};
 };
 for(const script of ['jalali-calendar','certificate-dns','node-editor','wireguard','anyconnect','infrastructure','register-infrastructure'])w.eval(read('public/speedbox/'+script+'.js'));
 const ui=w.SpeedboxInfrastructure.mount(w.document.querySelector('#root'),{view,router:{replace(){},push(){}},notify:{error:m=>errors.push(m),notif(){},success(){}}});
 await tick();await tick();
 return {dom,w,ui,errors,viewport,calls,get count(){return count;},set phase(v){phase=v;},set log(v){log=v;},setVpn(state,value){vpnStatus=state;operation=value&&{updated_at:new Date().toISOString(),...value};},block(){blocked=true;},release(){blocked=false;release?.();}};
}
{
 const f=await fixture(false,'servers'),d=f.w.document;
 d.querySelector('[data-server-log]').click();await tick();
 const pre=d.querySelector('.log-box');assert.equal(pre.textContent,'first\n');
 Object.defineProperties(pre,{scrollHeight:{value:1000,configurable:true},clientHeight:{value:100,configurable:true}});pre.scrollTop=50;pre.focus();
 f.log='first\nsecond <img src=x onerror=alert(1)>';await tick();await tick();
 assert.equal(d.querySelector('.log-box'),pre,'poll replaced the log DOM');assert.equal(d.activeElement,pre);assert.equal(pre.scrollTop,50,'reader scroll was reset');assert.equal(pre.querySelector('img'),null);
 f.phase='succeeded';await tick();await tick();const count=f.count;await tick();await tick();assert.equal(f.count,count,'terminal log kept polling');
 d.querySelector('[data-close]').click();
 // A late result must not reopen/replace another modal.
 f.phase='running';f.block();d.querySelector('[data-server-log]').click();await tick();d.querySelector('[data-close]').click();d.querySelector('#addServer').click();f.release();await tick();
 assert.ok(d.querySelector('#serverForm'));assert.equal(d.querySelector('[data-deployment-log]'),null);
 assert.deepEqual(f.errors,[]);f.ui.dispose();f.dom.window.close();
}
for(const mobile of [true,false]){
 const f=await fixture(mobile,'nodes'),d=f.w.document;
 d.querySelector('#addNode').click();d.querySelector('[data-advanced]').click();
 const dialog=d.querySelector('.node-sub-modal');assert.ok(dialog);
 assert.equal(d.activeElement.tagName,mobile?'SECTION':'BUTTON','mobile opened keyboard or desktop lost focus');
 dialog.querySelector('[data-sub-close]').click();
 const input=d.querySelector('[data-path=name]');input.focus();input.value='typed';input.setSelectionRange(2,2);input.dispatchEvent(new f.w.Event('input',{bubbles:true}));await tick();
 assert.equal(d.activeElement,input);assert.equal(input.value,'typed');assert.equal(input.selectionStart,2);
 f.viewport.height=350;f.viewport.dispatchEvent(new f.w.Event('resize'));await tick();assert.equal(d.activeElement,input);assert.equal(input.value,'typed');assert.equal(d.querySelector('#infrastructureOverlay').style.getPropertyValue('--infrastructure-viewport-height'),'');
 assert.deepEqual(f.errors,[]);f.ui.dispose();f.viewport.height=900;f.viewport.dispatchEvent(new f.w.Event('resize'));f.dom.window.close();
}
for(const mobile of [false,true]){
 const f=await fixture(mobile,'anyconnect'),d=f.w.document;
 const find=(selector)=>d.querySelector(selector);
 const errorMessage='خواندن کلید میزبان SSH ناموفق بود.';
 const badges=()=>[...d.querySelectorAll('[data-ac-row] .badge')].map(badge=>badge.textContent);
 assert.deepEqual(badges(),['ناموفق']);
 const menu=f.w.__SPEEDBOX_SERVER_MENU__({
  element:(tag,attrs,children)=>({tag,attrs,children}),
  component:(_tag,attrs,slots)=>({attrs,children:slots.default()}),
  link:'RouterLink',slot:fn=>fn,
 });
 for(const [serviceName,view] of [['AnyConnect','anyconnect'],['WireGuard','wireguard']]){
  const item=menu.find(item=>item.attrs['data-speedbox-view']===view).children[0];
  assert.equal(item.attrs.dir,'rtl');
  assert.equal(item.children[1].attrs.dir,'ltr');
  assert.equal(item.children[1].children,serviceName);
 }
 const row=mobile?'article[data-ac-row]':'tr[data-ac-row]';
 assert.ok(find(row));
 assert.ok(!find(row).textContent.includes(errorMessage));
 f.log=errorMessage;
 find('[data-ac-report]').click();await tick();
 assert.equal(find('.log-box').dir,'auto');
 assert.equal(find('.log-box').textContent,errorMessage);
 assert.equal(find('[role=progressbar][aria-valuenow="55"]').getAttribute('aria-valuenow'),'55');
 f.log=errorMessage+'\nگزارش تازه';await tick();await tick();
 assert.match(find('.log-box').textContent,/گزارش تازه/);
 f.phase='succeeded';await tick();await tick();
 assert.ok(find('[role=progressbar][aria-valuenow="100"]'));
 find('[data-close]').click();
 assert.match(find(row).textContent,/france/);
 assert.equal(find('[data-ac-new] bdi').textContent,'AnyConnect');
 assert.match(find('[data-ac-list]').textContent,/کاربران آنلاین/);
 assert.ok(find('[data-ac-action="update"].entity-report'));
 const search=find('#acSearch');
 search.value='nowhere';search.dispatchEvent(new f.w.Event('input'));
 assert.equal(find(row),null);
 search.value='france';search.dispatchEvent(new f.w.Event('input'));
 assert.ok(find(row));
 find('[data-ac-new]').click();
 const form=find('[data-ac-form]');
 assert.ok(find('.anyconnect-modal'));
 assert.equal(form.querySelector('[data-ac-advanced]').open,false);
 assert.ok(form.querySelector('[data-ac-cert]').open);
 assert.equal(form.elements.cert_mode.value,'dns');
 assert.ok(form.elements.cert_mode.closest('.ac-certificate-form'));
 assert.deepEqual([...form.elements.cert_mode.options].map(option=>option.value),['dns','http','content']);
 assert.ok(form.querySelector('[data-dns-provider]'));
 assert.ok(form.querySelector('.form-actions [data-close]'));
 assert.equal(form.elements.network.required,false);
 assert.equal(form.elements.network.placeholder,'خودکار');
 assert.ok(form.elements.network.closest('[data-ac-advanced]'));
 assert.equal(form.elements.name.value,'AnyConnect 2');
 assert.equal(form.querySelector('[data-ac-tunnel]').hidden,true);
 const modePicker=form.elements.mode.parentElement;
 modePicker.querySelector('.invoice-custom-select__trigger').click();
 [...modePicker.querySelectorAll('[role=option]')].find(button=>button.textContent==='تونل').click();
 assert.equal(form.querySelector('[data-ac-tunnel]').hidden,false);
 assert.equal(form.elements.ingress_server_id.required,true);
 assert.equal(form.elements.link_network.disabled,true);
 assert.equal(form.elements.link_network.placeholder,'خودکار');
 assert.equal(form.elements.link_port.required,false);
 assert.equal(form.elements.link_port.placeholder,'خودکار');
 form.elements.mode.value='direct';form.elements.mode.dispatchEvent(new f.w.Event('change'));
 form.elements.endpoint_port.value='8443';form.elements.endpoint_port.dispatchEvent(new f.w.Event('input'));
 assert.equal(form.elements.listen_port.value,'8443');
 assert.equal(form.elements.link_port.disabled,true);
 form.elements.managed_server_id.value='1';
 form.elements.endpoint_host.value='fixture.example.test';
 const provider = form.querySelector('[data-dns-provider]');
 provider.value='cloudflare';
 provider.dispatchEvent(new f.w.Event('change',{bubbles:true}));
 const token=form.querySelector('[data-dns-key="CLOUDFLARE_DNS_API_TOKEN"]');
 token.value='fixture-cert-token';
 token.dispatchEvent(new f.w.Event('input',{bubbles:true}));
 form.querySelector('[type=submit]').click();await tick();await tick();
 const create=f.calls.at(-1);
 assert.equal(create.path,'/anyconnect');
 assert.equal(create.method,'POST');
 assert.equal(create.body.network,'');
 assert.equal(create.body.endpoint_port,'8443');
 assert.equal(create.body.listen_port,'8443');
 assert.equal(create.body.link_port,undefined);
 assert.equal(create.body.certificate.mode,'dns');
 assert.equal(create.body.certificate.dns_provider,'cloudflare');
 assert.equal(create.body.certificate.dns_env.CLOUDFLARE_DNS_API_TOKEN,'fixture-cert-token');
 find('[data-ac-action="edit"]').click();
 const edit=find('.anyconnect-modal [data-ac-form]');
 assert.equal(edit.elements.managed_server_id.disabled,true);
 assert.equal(edit.elements.name.disabled,false);
 assert.equal(edit.elements.dns.disabled,false);
 assert.equal(edit.elements.cert_mode.value,'existing');
 edit.elements.cert_mode.value='http';
 edit.elements.cert_mode.dispatchEvent(new f.w.Event('change',{bubbles:true}));
 assert.match(edit.querySelector('[data-ac-cert-settings]').textContent,/پورت ۸۰/);
 assert.equal(edit.querySelector('[data-dns-provider]'),null);
 assert.equal(edit.elements.cert_http_port.value,'80');
 edit.elements.cert_http_port.value='8080';
 edit.elements.cert_email.value='owner@example.test';
 edit.querySelector('[type=submit]').click();await tick();await tick();
 const update=f.calls.at(-1);
 assert.equal(update.path,'/anyconnect/2');
 assert.equal(update.method,'PUT');
 assert.equal(update.body.certificate.mode,'http');
 assert.equal(update.body.certificate.email,'owner@example.test');
 assert.equal(update.body.certificate.http_port,8080);
 find('[data-ac-action="edit"]').click();
 const imported=find('.anyconnect-modal [data-ac-form]');
 imported.elements.cert_mode.value='content';
 imported.elements.cert_mode.dispatchEvent(new f.w.Event('change',{bubbles:true}));
 assert.ok(imported.elements.cert_content.required);
 assert.ok(imported.elements.key_content.required);
 assert.equal(imported.querySelector('.form-actions [type=submit]').textContent,'ذخیره تغییرات');
 find('[data-close]').click();
 find('[data-ac-action="update"]').click();await tick();
 assert.equal(f.calls.at(-1).path,'/anyconnect/2/actions');
 assert.equal(f.calls.at(-1).body.action,'update');
 f.ui.showView('wireguard');await tick();await tick();
 assert.ok(!find('[data-wg-row]').textContent.includes(errorMessage));
 assert.deepEqual([...d.querySelectorAll('[data-wg-row] .badge')].map(badge=>badge.textContent),['ناموفق']);
 f.log=errorMessage;
 find('[data-wg-report]').click();await tick();
 assert.equal(find('.log-box').textContent,errorMessage);
 find('[data-close]').click();
 find('[data-wg-new]').click();
 assert.ok(find('.wireguard-modal .wg-field-row .invoice-custom-select__trigger'));
 assert.equal(find('.wireguard-modal details').open,false);
 find('[data-close]').click();
 f.ui.showView('anyconnect');await tick();await tick();
 find('[data-ac-new]').click();
 assert.ok(find('[data-ac-form]'),'The create button works after revisiting the cached view');
 assert.deepEqual(f.errors,[]);
 f.ui.dispose();f.dom.window.close();
}
for(const mobile of [false,true]){
 for(const [view,rowSelector,startSelector] of [
  ['wireguard','[data-wg-row]','[data-wg-action="enable"]'],
  ['anyconnect','[data-ac-row]','[data-ac-action="start"]'],
 ]){
  const f=await fixture(mobile,view,{lifecycle:true}),d=f.w.document;
  const assertStatus=(label,progress)=>{
   const row=d.querySelector(rowSelector);
   assert.deepEqual([...row.querySelectorAll('.badge')].map(badge=>badge.textContent),[label]);
   const bar=row.querySelector('.server-operation-progress [role=progressbar]');
   assert.equal(bar?.getAttribute('aria-valuenow')??null,progress==null?null:String(progress));
  };
  assertStatus('غیرفعال',null);
  f.setVpn('ready',null);
  f.ui.showView(view);await tick();await tick();
  assertStatus('فعال',null);
  f.setVpn('stopped',null);
  f.ui.showView(view);await tick();await tick();
  d.querySelector(startSelector).click();await tick();await tick();
  assertStatus('در صف',0);

  f.setVpn('queued',{action:'enable',status:'running',progress:45});
  await tick();await tick();
  assertStatus('در حال اجرا',45);
  f.setVpn('failed',{action:'enable',status:'failed',progress:45});
  await tick();await tick();
  assertStatus('ناموفق',45);
  f.ui.showView(view);await tick();await tick();
  assertStatus('ناموفق',45);

  const finishesIn=250;
  f.setVpn('failed',{action:'enable',status:'failed',progress:45,updated_at:new Date(Date.now()-10_000+finishesIn).toISOString()});
  f.ui.showView(view);await tick();await tick();
  assertStatus('ناموفق',45);
  await new Promise(resolve=>setTimeout(resolve,finishesIn));
  assertStatus('غیرفعال',null);

  d.querySelector(startSelector).click();await tick();await tick();
  assertStatus('در صف',0);
  f.setVpn('ready',{action:'enable',status:'succeeded',progress:100});
  await tick();await tick();
  assertStatus('موفق',100);
  f.ui.showView(view);await tick();await tick();
  assertStatus('موفق',100);

  f.setVpn('ready',{action:'enable',status:'succeeded',progress:100,updated_at:new Date(Date.now()-10_000).toISOString()});
  f.ui.showView(view);await tick();await tick();
  assertStatus('فعال',null);

  f.setVpn('ready',{action:'sync',status:'failed',progress:30,updated_at:new Date(Date.now()-11_000).toISOString()});
  f.ui.showView(view);await tick();await tick();
  assertStatus('فعال',null);

  f.setVpn('queued',{action:'sync',status:'running',progress:65,updated_at:new Date(Date.now()-60_000).toISOString()});
  f.ui.showView(view);await tick();await tick();
  assertStatus('در حال اجرا',65);

  assert.deepEqual(f.errors,[]);
  f.ui.dispose();f.dom.window.close();
 }
}
console.log('PASS: live logs, focus and keyboard viewport, VPN management layouts, modal controls and bidirectional status text');
