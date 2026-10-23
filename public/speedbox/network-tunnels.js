/* Network engines share the authenticated workspace and keep independent forms. */
window.SpeedboxNetworkTunnels=(()=>{
'use strict';
const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const copy=v=>JSON.parse(JSON.stringify(v));
const engines={backpack:{name:'BackPack',text:'بررسی روش‌های اتصال و ساخت با نتیجهٔ تست'},gost:{name:'GOST',text:'اتصال مستقیم ایران و خارج؛ انتخاب نسخه، پورت‌ها و IPv4 یا IPv6'},gre:{name:'GRE',text:'اتصال شبکه بین ایران و خارج با IPv4 یا IPv6'},wattunnel:{name:'WatTunnel',text:'انتقال رمز‌شدهٔ TCP و UDP؛ حامل ICMP و روش‌های TCP، TLS و HTTP'}};
const actions=t=>[
 ['status','بررسی اتصال'],[(t.settings?.enabled===false||t.status==='stopped')?'start':'stop',(t.settings?.enabled===false||t.status==='stopped')?'شروع':'توقف'],
 ['restart','راه‌اندازی مجدد'],['logs','گزارش'],...(t.engine==='wattunnel'?[]:[['optimize','بهینه‌سازی']]),
 ['engine-update','به‌روزرسانی'],
 ...(t.engine==='gre'?[['diagnose','بررسی مسیر و MTU'],['history','تاریخچهٔ اتصال'],...(Number(t.settings?.quota_gb)>0?[['reset-quota','صفر کردن مصرف سهمیه']]:[])]:[]),
 ['edit','ویرایش'],['delete','حذف تونل']
];
const rangeText=(start,end)=>String(start)+(Number(end??start)>Number(start)?'–'+end:'');
const endpoint=(host,port,end)=>`${String(host).includes(':')?'['+host+']':host}:${rangeText(port,end)}`;
const isIPv4=value=>{const a=String(value).split('.');return a.length===4&&a.every(x=>/^(0|[1-9]\d{0,2})$/.test(x)&&Number(x)<=255);};
function portsText(maps){return maps.flatMap(m=>{const start=Number(m.public_port),end=Number(m.public_end??start);return start>0&&end>=start&&end-start<256?Array.from({length:end-start+1},(_,i)=>start+i):[];}).join(', ');}
function parsePorts(value){
 const normalized=String(value||'').replace(/[۰-۹]/g,c=>String('۰۱۲۳۴۵۶۷۸۹'.indexOf(c))).replace(/[٠-٩]/g,c=>String('٠١٢٣٤٥٦٧٨٩'.indexOf(c)));
 const parts=normalized.trim().split(/[,،\s]+/).filter(Boolean);
 if(!parts.length||parts.length>256)throw Error('بین ۱ تا ۲۵۶ پورت وارد کنید.');
 const ports=parts.map(x=>{if(!/^[0-9]{1,5}$/.test(x)||Number(x)<1||Number(x)>65535)throw Error('هر پورت باید عددی بین ۱ تا ۶۵۵۳۵ باشد.');return Number(x);});
 if(new Set(ports).size!==ports.length)throw Error('پورت تکراری وارد شده است.');
 ports.sort((a,b)=>a-b);const maps=[];
 for(const n of ports){const last=maps.at(-1);if(last&&last.public_end===n-1){last.public_end=n;last.target_end=n;}else maps.push({public_port:n,public_end:n,target_port:n,target_end:n,destinations:[]});}
 if(maps.length>40)throw Error('حداکثر ۴۰ مسیر جدا قابل ثبت است؛ پورت‌های متوالی را کنار هم انتخاب کنید.');
 return maps;
}
function validateMappings(maps){
 if(!maps.length||maps.length>40)throw Error('بین ۱ تا ۴۰ مسیر وارد کنید.');
 const used=new Set();let count=0;
 for(const m of maps){const a=m.public_port,b=m.public_end??a,c=m.target_port,d=m.target_end??c;
  if(![a,b,c,d].every(n=>Number.isInteger(n)&&n>=1&&n<=65535)||b<a||d-c!==b-a)throw Error('پورت‌های هر مسیر باید معتبر و بازهٔ ایران و خارج هم‌اندازه باشد.');
  count+=b-a+1;if(count>256)throw Error('حداکثر ۲۵۶ پورت در هر اتصال قابل ثبت است.');
  for(let n=a;n<=b;n++){if(used.has(n))throw Error('پورت ورودی تکراری است.');used.add(n);}
 }
}
const label=t=>engines[t.engine||'backpack']?.name||'BackPack';
function methodLabel(t){
 const s=t.settings||t,engine=t.engine||s.engine||'backpack';
 const transport=String(t.transport||s.transport||'').toUpperCase(),kind=t.kind||s.kind;
 const version=String(t.version||s.version||'');
 const name=engine==='gost'&&version?`GOST ${version.split('.')[0]}`:label({engine});
 const direction=engine==='backpack'?({reverse:'Reverse',direct:'Direct'}[kind]):engine==='wattunnel'?((t.reverse??s.reverse)?'Reverse':'Direct'):'Direct';
 const family=t.ip_family||s.ip_family||(engine==='gre'?(transport.startsWith('IP6')?'ipv6':'ipv4'):null);
 return [name,direction,transport,family==='ipv6'?'IPv6':family==='ipv4'?'IPv4':null,engine==='backpack'&&(t.orientation||s.orientation)==='swapped'?'Swapped':null].filter(Boolean).join(' · ');
}
function methodSummary(method,pair,actions=''){
 return `<div class="tunnel-selected-method"><div class="tunnel-selected-copy"><b dir="ltr">${esc(methodLabel(method))}</b><span>${pair.map(esc).join(' / ')}</span></div>${actions}</div>`;
}

function mount(ctx){
 const {api,openModal,modalBody,closeModal,toast,refresh,data,safe,progress,backpack,enhanceControls,openNode,openMaintenance}=ctx;
 let capabilities=null,disposed=false,watch=null,watchTimer=null;
 const body=()=>modalBody();
 const show=(title,html)=>{openModal(title,html,{kind:'tunnel',onClose:()=>{clearTimeout(watchTimer);watch=null;activeTestEngine=null;clearTimeout(testTimer);}});enhanceControls(body());};
 const btn=(text,attr='',kind='secondary')=>`<button type="button" class="button ${kind}" ${attr}>${text}</button>`;
 const field=(key,title,value,{type='text',hint='',required=false,min,max}={})=>`<div class="field"><label for="net-${key}">${title}</label><input class="form-input" id="net-${key}" name="${key}" type="${type}" value="${esc(value)}" ${required?'required':''} ${min!==undefined?`min="${min}"`:''} ${max!==undefined?`max="${max}"`:''} ${type==='number'?'step="1"':''} dir="${key==='name'?'auto':'ltr'}">${hint?`<small>${hint}</small>`:''}</div>`;
 const select=(key,title,value,options)=>`<div class="field"><label for="net-${key}">${title}</label><select id="net-${key}" class="form-input" name="${key}">${options.map(([v,l])=>`<option value="${esc(v)}" ${String(v)===String(value)?'selected':''}>${esc(l)}</option>`).join('')}</select></div>`;
 const toggle=(key,title,value)=>`<div class="switch-field network-toggle"><span>${title}</span><label class="switch-line ios"><input type="checkbox" name="${key}" ${value?'checked':''} aria-label="${title}"></label></div>`;
 const servers=()=>[['','انتخاب سرور'],...data().servers.map(s=>[s.id,`${s.name} — ${s.host}`])];
 async function caps(){if(!capabilities)capabilities=(await api('/network-tunnels/capabilities')).data;return capabilities;}
 const wat=window.SpeedboxWatTunnel?.mount({...ctx,show,body,caps,field,select,toggle,btn,testFlow,validateMappings,parsePorts,portsText,methodSummary});
 const creationDrafts=new Map();
 const testFlows=new Map();let activeTestEngine=null,testTimer=null;
 async function testFlow(engine,seed=null){
  clearTimeout(testTimer);activeTestEngine=engine;
  if(seed||!testFlows.has(engine))testFlows.set(engine,{seed:seed||{},iran:seed?.iran_server_id||'',foreign:seed?.foreign_server_id||'',test:null,operation:null});
  const state=testFlows.get(engine);state.direction??='normal';state.carriers??=['icmp','udp','tcp','tls','ws','wss','h2','h2c','grpc'];
  const pending=()=>['queued','running'].includes(state.operation?.status);
  async function poll(){
   clearTimeout(testTimer);
   if(disposed||activeTestEngine!==engine||!state.test)return;
   try{const answer=await api('/network-tunnel-tests/'+state.test.id);state.test=answer.data;state.operation=answer.operation;if(activeTestEngine===engine)draw();if(!pending())await refresh();}
   catch(error){/* Keep the last measurement and retry silently. */}
   if(pending()&&activeTestEngine===engine)testTimer=setTimeout(poll,2000);
  }
  const metric=v=>v===null||v===undefined?'—':Number.isFinite(Number(v))?Number(v):'—';
  const rate=v=>v===null||v===undefined?'—':metric(v)+' Mbps';
  function results(){
   if(!state.test)return '';
   const rows=state.test.results||[];
   return `<section class="tunnel-results network-test-results"><div class="tunnel-result-heading"><div><h3>نتیجهٔ تست اتصال</h3><p>${esc(state.operation?.message||state.test.message||'در انتظار شروع تست…')}</p></div></div>${pending()?`${progress(state.operation)}`:''}<div class="tunnel-result-list">${rows.map(row=>`<article class="tunnel-result-row ${row.recommended?'is-recommended':''}"><div class="tunnel-result-method"><b dir="ltr">${esc(row.label)}</b><div><span class="tunnel-badge tunnel-${esc(row.status)}">${{ok:'موفق',unstable:'ناپایدار',down:'ناموفق',testing:'در حال تست',queued:'در انتظار',skipped:'بررسی نشده'}[row.status]||'بررسی نشده'}</span>${row.recommended?'<span class="tunnel-pick">پیشنهادی</span>':''}</div></div><span class="network-result-protocol">آمار <bdi dir="ltr">${row.protocols?.tcp?.status==='down'?'UDP':'TCP'}</bdi></span><dl class="tunnel-result-metrics"><div><dt>پاسخ موفق</dt><dd><bdi dir="ltr">${metric(row.ok)} / ${metric(row.total)}</bdi></dd></div><div><dt>تأخیر</dt><dd><bdi dir="ltr">${row.rtt===null||row.rtt===undefined?'—':metric(row.rtt)+' ms'}</bdi></dd></div><div><dt>دریافت</dt><dd><bdi dir="ltr">${rate(row.download)}</bdi></dd></div><div><dt>ارسال</dt><dd><bdi dir="ltr">${rate(row.upload)}</bdi></dd></div></dl><div class="network-protocol-results">${['tcp','udp'].map(p=>`<span><bdi dir="ltr">${p.toUpperCase()}</bdi>: ${{ok:'پایدار',unstable:'ناپایدار',down:'ناموفق',testing:'در حال تست'}[row.protocols?.[p]?.status]||'اندازه‌گیری نشده'}</span>`).join('')}</div><details class="network-protocol-details"><summary>آمار هر پروتکل</summary><div class="network-protocol-breakdown">${['tcp','udp'].map(p=>{const v=row.protocols?.[p]||{};return `<section><h4 dir="ltr">${p.toUpperCase()}</h4><dl>${[['پاسخ موفق',metric(v.ok)+' / '+metric(v.total)],['تأخیر',metric(v.rtt)+' ms'],['دریافت',rate(v.download)],['ارسال',rate(v.upload)]].map(([title,value])=>`<div><dt>${title}</dt><dd><bdi dir="ltr">${value}</bdi></dd></div>`).join('')}</dl></section>`;}).join('')}</div></details><button type="button" class="button secondary" data-select-network-result="${esc(row.id)}" ${!row.selectable||pending()||state.test.status!=='completed'?'disabled':''}>ادامه با این روش</button></article>`).join('')}</div>${!pending()?'<p class="tunnel-muted">سرعت‌ها نرخ انتقال نمونه در زمان تست‌اند؛ کیفیت مسیر ممکن است تغییر کند.</p>':''}</section>`;
  }
  function draw(){
   if(disposed||activeTestEngine!==engine)return;
   const opened=[...body().querySelectorAll('.network-protocol-details')].map(d=>d.open);
   show('تست تونل '+engines[engine].name,`<form data-network-test class="tunnel-builder"><p class="tunnel-help">سرور ایران و خارج را انتخاب کنید تا ارتباط، سرعت و پایداری مسیر بررسی شود.</p><div class="form-grid">${select('iran_server_id','سرور ایران',state.iran,servers())}${select('foreign_server_id','سرور خارج',state.foreign,servers())}</div>${engine==='wattunnel'?`<div class="wat-test-settings">${select('direction','جهت‌های مورد بررسی',state.direction,[['normal','ایران به خارج'],['reverse','خارج به ایران — معکوس'],['both','هر دو جهت']])}<details class="network-section"><summary>حامل‌های مورد بررسی</summary><div class="wat-carriers">${['icmp','udp','tcp','tls','ws','wss','h2','h2c','grpc'].map(c=>`<label><input type="checkbox" name="wat_carrier" value="${c}" ${state.carriers.includes(c)?'checked':''} ${pending()?'disabled':''}><bdi dir="ltr">${c.toUpperCase()}</bdi></label>`).join('')}</div></details><p class="tunnel-muted">ICMP روی IPv4 بررسی می‌شود؛ حامل UDP حالت معکوس ندارد.</p></div>`:''}${results()}<div class="form-actions network-navigation"><button type="submit" class="button primary" ${pending()?'disabled':''}>${pending()?'در حال تست…':state.test?'تست دوباره':'تست اتصال'}</button>${pending()?btn('توقف تست','data-cancel-network-test'):''}${btn('بازگشت','data-test-engines','network-back')}${btn('انصراف','data-close','cancel')}</div></form>`);
   const f=body().querySelector('[data-network-test]');
   f.querySelectorAll('.network-protocol-details').forEach((d,i)=>d.open=opened[i]||false);
   for(const key of ['iran_server_id','foreign_server_id']){
    f.elements[key].disabled=pending();
    f.elements[key].addEventListener('change',()=>{state.iran=f.elements.iran_server_id.value;state.foreign=f.elements.foreign_server_id.value;state.test=null;state.operation=null;draw();});
   }
   f.querySelector('[data-test-engines]').onclick=()=>{activeTestEngine=null;clearTimeout(testTimer);choose(false);};
   f.querySelector('[data-cancel-network-test]')?.addEventListener('click',safe(async()=>{await api('/tunnel-operations/'+state.operation.id+'/cancel',{method:'POST',body:'{}'});await poll();}));
   f.querySelectorAll('[data-select-network-result]').forEach(b=>b.onclick=safe(async()=>{
    const row=state.test.results.find(r=>r.id===b.dataset.selectNetworkResult),previous=creationDrafts.get(engine)?.draft||state.seed;
    const protocols=['tcp','udp'].filter(p=>['ok','unstable'].includes(row.protocols?.[p]?.status));
    clearTimeout(testTimer);activeTestEngine=null;
    await editor(null,engine,{...previous,engine,iran_server_id:Number(state.test.iran_server_id),foreign_server_id:Number(state.test.foreign_server_id),
     version:row.version,transport:row.transport,reverse:row.reverse,ip_family:row.ip_family,protocol:protocols.includes(previous.protocol)?previous.protocol:protocols[0],
     test_id:state.test.id,result_id:row.id,__verified:true,__resume:true,__test:{rows:state.test.results.filter(r=>r.selectable),selected:row.id}});
   }));
   f.querySelector('[name=direction]')?.addEventListener('change',()=>{state.direction=f.elements.direction.value;});
   if(f.elements.direction)f.elements.direction.disabled=pending();
   f.querySelectorAll('[name=wat_carrier]').forEach(el=>el.onchange=()=>{state.carriers=[...f.querySelectorAll('[name=wat_carrier]:checked')].map(x=>x.value);});
   f.onsubmit=safe(async()=>{
    if(engine==='wattunnel'&&!state.carriers.length)throw Error('حداقل یک حامل را انتخاب کنید.');
    if(!state.iran||!state.foreign||String(state.iran)===String(state.foreign))throw Error('دو سرور متفاوت انتخاب کنید.');
    const answer=await api('/network-tunnel-tests',{method:'POST',body:JSON.stringify({engine,iran_server_id:Number(state.iran),foreign_server_id:Number(state.foreign),...(engine==='wattunnel'?{direction:state.direction,carriers:state.carriers}:{})})});
    state.test=answer.data;state.operation=answer.operation;draw();await refresh();if(pending())testTimer=setTimeout(poll,500);
   });
  }
  draw();if(pending())testTimer=setTimeout(poll,500);
 }
 function choose(reset=true){
  if(reset){creationDrafts.clear();testFlows.clear();wat?.reset();}activeTestEngine=null;clearTimeout(testTimer);
  show('انتخاب نوع تونل',`<div class="network-choice-content"><p class="network-intro">نوع تونل بین سرور ایران و خارج را انتخاب کنید.</p><div class="network-engine-picker">${Object.entries(engines).map(([key,e])=>`<button type="button" class="network-engine-choice network-${key}" data-engine="${key}"><b dir="ltr">${e.name}</b><span>${e.text}</span><small>انتخاب و ادامه ←</small></button>`).join('')}</div></div><div class="form-actions network-navigation">${btn('انصراف','data-close','cancel')}</div>`);
  body().querySelectorAll('[data-engine]').forEach(b=>b.onclick=safe(async()=>{if(b.dataset.engine==='backpack'){backpack();return;}await editor(null,b.dataset.engine);}));
 }
 async function resumeTest(test){
  const answer=await api('/network-tunnel-tests/'+test.id),engine=answer.data.engine;
  testFlows.set(engine,{seed:{},iran:answer.data.iran_server_id,foreign:answer.data.foreign_server_id,test:answer.data,operation:answer.operation});
  await testFlow(engine);
 }
 async function editor(t,engine,seed=null){
  if(!t&&!seed?.__verified){await testFlow(engine,seed);return;}
  if(engine==='wattunnel'){await wat.editor(t,seed);return;}
  const cap=await caps();if(disposed)return;
  const gre=engine==='gre',saved=!t&&(!seed||seed.__resume)?creationDrafts.get(engine):null;
  let draft=copy(saved?.draft||{...cap.defaults,...(t?.settings||{}),engine,name:t?.name||'',iran_server_id:t?.iran_server_id||null,foreign_server_id:t?.foreign_server_id||null});
  if(seed)draft={...draft,...seed,engine};
  if(!t)draft.address_source='servers';
  if(!t&&!saved&&!seed?.__verified){draft.transport=gre?(seed?.transport&&cap.gre_types.includes(seed.transport)?seed.transport:seed?.ip_family==='ipv6'?'ip6gre':'gre'):'tcp';draft.address_source='servers';}
  draft.ip_family=draft.ip_family||(String(draft.peer_host||'').includes(':')||String(draft.transport).startsWith('ip6')?'ipv6':'ipv4');
  if(gre)draft.ip_family=draft.transport.startsWith('ip6')?'ipv6':'ipv4';
  let simple=false;
  let ports=saved?.ports??(seed?.__new_peer?'':portsText(draft.mappings));
  let advanced=saved?.advanced||false,openSections=new Set(saved?.openSections||[]);
  let restartEnabled=saved?.restartEnabled??Number(draft.restart_hours)>0,restartHours=saved?.restartHours||Number(draft.restart_hours)||1;
  if(draft.telegram_alerts===undefined)draft.telegram_alerts=t?false:Boolean(data().alerting?.available);
  const legacyAddress=!!t&&draft.address_source!=='servers';
  const legacyGrpc=!!t&&!gre&&draft.transport==='grpc';
  const legacyDomain=legacyAddress&&!gre&&!isIPv4(draft.peer_host)&&!String(draft.peer_host).includes(':')&&!t.settings.ip_family;
  if(legacyDomain)draft.ip_family='';
  const server=id=>data().servers.find(s=>Number(s.id)===Number(id));
  const host=id=>server(id)?.host||'پس از انتخاب سرور';
  const numberFields=new Set(['monitor_interval','timeout','monitor_port','failure_threshold','recovery_threshold','restart_hours','mtu','ttl','gre_key','tos','tx_queue','hop_limit','encap_limit','flow_label','quota_gb','tunnel_port']);
  function capture(){
   const f=body().querySelector('[data-network-editor]');if(!f)return;
   for(const el of f.querySelectorAll('[data-setting]')){
    let value=el.type==='checkbox'?el.checked:el.value;
    if(numberFields.has(el.name))value=el.value===''&&el.name==='monitor_port'?null:Number(el.value);
    if(['iran_server_id','foreign_server_id'].includes(el.name))value=Number(value)||null;
    draft[el.name]=value;
   }
   if(f.querySelector('[name=ports]'))ports=f.elements.ports.value;
   if(f.querySelector('[data-advanced]'))advanced=f.querySelector('[data-advanced]').open;
   openSections=new Set([...f.querySelectorAll('details[open]')].map(d=>d.querySelector('summary')?.textContent));
   if(f.elements.restart_hours)restartHours=Number(f.elements.restart_hours.value);
   if(f.elements.restart_enabled)restartEnabled=f.elements.restart_enabled.checked;
   draft.restart_hours=restartEnabled?restartHours:0;
   const sources=f.querySelector('[data-sources]');if(sources)draft.allowed_sources=sources.value.split('\n').map(x=>x.trim()).filter(Boolean);
   for(const row of f.querySelectorAll('[data-map-row]')){
    const m=draft.mappings[Number(row.dataset.mapRow)],length=Number(m.public_end??m.public_port)-Number(m.public_port);
    for(const el of row.querySelectorAll('[data-map-key]'))m[el.dataset.mapKey]=Number(el.value);
    m.public_end=m.public_port+length;m.target_end=m.target_port+length;
   }
   if(gre)draft.ip_family=draft.transport.startsWith('ip6')?'ipv6':'ipv4';
  }
  const input=(key,title,value,opts={})=>field(key,title,value,opts).replace('<input ','<input data-setting ');
  const pick=(key,title,value,opts)=>select(key,title,value,opts).replace('<select ','<select data-setting ');
  const check=(key,title,value)=>toggle(key,title,value).replace('<input ','<input data-setting ');
  const plus='<span aria-hidden="true">+</span> ';
  const greNames={gre:'GRE روی IPv4',gretap:'GRETAP روی IPv4 — لایهٔ 2',ip6gre:'GRE روی IPv6',ip6gretap:'GRETAP روی IPv6 — لایهٔ 2'};
  const testedRows=draft.__test?.rows||[];
  const selectedRow=()=>testedRows.find(r=>r.id===draft.result_id);
  const availableProtocols=()=>{const row=selectedRow();return row?['tcp','udp'].filter(p=>['ok','unstable'].includes(row.protocols?.[p]?.status)):['tcp','udp'];};
  function syncSelection(){if(!testedRows.length)return;const row=testedRows.find(r=>r.transport===draft.transport&&r.ip_family===draft.ip_family&&(gre||r.version===draft.version));if(!row)throw Error('برای این تنظیمات، یک نتیجهٔ تست سازگار انتخاب کنید.');draft.result_id=row.id;const allowed=availableProtocols();if(!(draft.protocol==='both'?allowed.length===2:allowed.includes(draft.protocol)))draft.protocol=allowed[0];}
  const protocols=()=>{const allowed=availableProtocols();return pick('protocol','پروتکل پورت‌ها',draft.protocol,[['tcp','TCP'],['udp','UDP'],['both','TCP و UDP']].filter(([v])=>v==='both'?allowed.length===2:allowed.includes(v)));};
  const serverFields=()=>`<div class="form-grid">${pick('iran_server_id','سرور ایران',draft.iran_server_id,servers())}${pick('foreign_server_id','سرور خارج',draft.foreign_server_id,servers())}</div>`;
  const mapInput=(i,key,title,value)=>`<div class="field"><label for="net-map-${i}-${key}">${title}</label><input class="form-input" id="net-map-${i}-${key}" type="number" min="1" max="65535" step="1" value="${Number(value)||''}" data-map-key="${key}" required dir="ltr"></div>`;
  function mapFields(){
   return `<div class="tunnel-mapping-heading"><h4>پورت‌های اتصال</h4>${btn(plus+'افزودن پورت','data-add-map')}</div><div class="network-mapping-list">${draft.mappings.map((m,i)=>`<div class="tunnel-mapping-row network-mapping" data-map-row="${i}">${mapInput(i,'public_port','پورت اتصال کاربران روی ایران',m.public_port)}<span class="tunnel-map-arrow" aria-hidden="true">←</span>${mapInput(i,'target_port','پورت نود روی سرور خارج',m.target_port)}${btn('حذف',`data-remove-map="${i}" aria-label="حذف این پورت" ${draft.mappings.length===1?'disabled':''}`,'danger')}${Number(m.public_end??m.public_port)>Number(m.public_port)?`<p class="tunnel-muted">بازهٔ قبلی حفظ می‌شود: <bdi dir="ltr">${rangeText(m.public_port,m.public_end)} ← ${rangeText(m.target_port,m.target_end)}</bdi></p>`:''}${m.destinations?.length?'<p class="tunnel-muted">مقصدهای اختصاصی قبلی این پورت حفظ می‌شوند.</p>':''}</div>`).join('')}</div>`;
  }

  function monitoringFields(){
   return `<section class="network-advanced-group">${check('monitor','پایش اتصال',draft.monitor)}${draft.monitor?`<div class="form-grid">${input('monitor_interval','فاصلهٔ پایش (ثانیه)',draft.monitor_interval,{type:'number',min:5,max:300})}${input('timeout','مهلت پاسخ (ثانیه)',draft.timeout,{type:'number',min:1,max:10})}${!gre&&(draft.protocol==='udp'||Number(draft.monitor_port)>0)?input('monitor_port','پورت TCP پایش',draft.monitor_port,{type:'number',min:1,max:65535,hint:''}):''}</div>`:''}${gre?check('keepalive','تست مسیر با Ping',draft.keepalive):''}</section>`;
  }
  function restartFields(){
   return `<section class="network-advanced-group">${toggle('restart_enabled','ری‌استارت دوره‌ای',restartEnabled)}${restartEnabled?`<div class="form-grid">${input('restart_hours','هر چند ساعت؟',restartHours,{type:'number',min:1,max:168})}</div>`:''}</section>`;
  }
  function legacyDestinations(){
   return draft.mappings.some(m=>m.destinations?.length)?`<details class="network-section"><summary>مقصدهای اختصاصی قبلی</summary><div class="network-advanced-content">${check('failover','کنارگذاشتن مقصد قطع‌شده',draft.failover)}<div class="form-grid">${pick('balance','تقسیم ترافیک',draft.balance,[['round_robin','نوبتی'],['source_hash','مقصد ثابت برای هر کاربر'],['weighted','بر اساس وزن مقصد']])}${input('failure_threshold','تعداد خطا تا قطع',draft.failure_threshold,{type:'number',min:1,max:20})}${input('recovery_threshold','تعداد پاسخ تا وصل',draft.recovery_threshold,{type:'number',min:1,max:20})}${gre?input('monitor_port','پورت TCP برای پایش',draft.monitor_port,{type:'number',min:1,max:65535}):''}</div></div></details>`:'';
  }
  function greNetworkFields(){
   return `<details class="network-section"><summary>تنظیمات تخصصی شبکه</summary><div class="network-advanced-content"><div class="form-grid">${input('mtu','MTU',draft.mtu,{type:'number',min:576,max:9000,hint:'حداکثر اندازهٔ بستهٔ تونل؛ در صورت نیاز مسیر تغییر دهید.'})}${input('gre_key','کلید GRE',draft.gre_key,{type:'number',min:0,max:4294967295,hint:'برای جداکردن تونل‌های بین دو سرور مشترک؛ صفر یعنی بدون کلید.'})}${input('inner_iran','IP داخلی تونل در ایران',draft.inner_iran,{hint:'اختیاری؛ آدرس خصوصی مسیر تونل. خالی بماند تا خودکار تعیین شود.'})}${input('inner_foreign','IP داخلی تونل در خارج',draft.inner_foreign,{hint:'فقط همراه IP داخلی ایران تنظیم شود؛ مثل 10.10.1.2/30.'})}${input('bind_device','رابط خروجی اینترنت',draft.bind_device,{hint:'اختیاری؛ نام رابط لینوکس، مثل eth0. خالی یعنی انتخاب خودکار.'})}${pick('nat','IP خروجی روی خارج',draft.nat,[['masquerade','خودکار'],['snat','IP ثابت'],['none','IP اصلی کاربر — نیازمند مسیر برگشت']])}${draft.nat==='snat'?input('snat_address','IP ثابت خروجی',draft.snat_address,{required:true}):''}</div>${check('clamp_mss','تنظیم خودکار MSS',draft.clamp_mss)}<p class="tunnel-muted">MSS اندازهٔ بسته‌های TCP را با مسیر تونل هماهنگ می‌کند.</p><div class="field"><label for="net-sources">محدودیت IP کاربران</label><textarea id="net-sources" class="form-input" data-sources dir="ltr" rows="3">${esc((draft.allowed_sources||[]).join('\n'))}</textarea><small>خالی یعنی همه مجازند؛ برای محدودکردن، هر خط یک شبکه مثل 192.0.2.0/24 وارد کنید.</small></div></div></details>`;
  }
  function quotaFields(){
   return `<details class="network-section"><summary>سقف ترافیک</summary><div class="network-advanced-content"><div class="form-grid">${input('quota_gb','سقف ترافیک (GiB)',draft.quota_gb,{type:'number',min:0,max:10000000,hint:'صفر یعنی نامحدود.'})}${pick('quota_period','دورهٔ محاسبه',draft.quota_period,[['total','کل مدت تونل'],['daily','روزانه'],['weekly','هفتگی'],['monthly','ماهانه']])}${pick('quota_direction','ترافیک محاسبه‌شده',draft.quota_direction,[['both','مجموع دریافت و ارسال'],['rx','دریافت'],['tx','ارسال']])}${pick('quota_mode','پس از اتمام سهمیه',draft.quota_mode,[['warn','هشدار در پنل'],['enforce','توقف تونل']])}</div></div></details>`;
  }
  function advancedFields(){
   return `<details class="network-section network-advanced" data-advanced ${advanced?'open':''}><summary>تنظیمات پیشرفته</summary><div class="network-advanced-content">${restartFields()}${monitoringFields()}<section class="network-advanced-group tunnel-alert-setting">${check('telegram_alerts','هشدار قطع تونل در تلگرام',draft.telegram_alerts)}${!data().alerting?.available?`<p class="tunnel-muted">${esc(data().alerting?.reason||'وضعیت ربات بررسی نشده است؛ صفحه را تازه‌سازی کنید.')}</p>`:''}</section>${legacyDestinations()}${gre?greNetworkFields()+quotaFields():''}</div></details>`;
  }
  function addresses(){
   return `<div class="network-server-addresses">${[['iran_server_id','آدرس ایران'],['foreign_server_id','آدرس خارج']].map(([key,title])=>`<div><span>${title}</span><bdi dir="ltr">${esc(host(draft[key]))}</bdi></div>`).join('')}</div>`;
  }
  function gostFields(){
   return `<section class="network-engine-settings" data-gost-settings><div class="form-grid">${input('name','نام تونل',draft.name,{required:true})}${pick('version','نسخهٔ GOST',draft.version,cap.versions.filter(v=>!testedRows.length||testedRows.some(r=>r.version===v&&r.ip_family===draft.ip_family)).map(v=>[v,`GOST ${v.startsWith('2.')?'2':'3'} (${v})`]))}${pick('ip_family','ارتباط سرورها',draft.ip_family,[['ipv4','IPv4'],['ipv6','IPv6'],...(legacyDomain?[['','دامنهٔ قبلی — خودکار']]:[])].filter(([v])=>!testedRows.length||testedRows.some(r=>r.version===draft.version&&r.ip_family===v)))}${protocols()}</div>${serverFields()}${addresses()}${legacyGrpc?`<p class="tunnel-muted">روش gRPC این تونل قدیمی حفظ می‌شود.</p>${input('tunnel_port','پورت gRPC',draft.tunnel_port,{type:'number',min:1,max:65535,required:true})}`:''}</section>`;
  }
  function greFields(){
   return `<section class="network-engine-settings" data-gre-settings><div class="form-grid">${input('name','نام تونل',draft.name,{required:true})}${pick('transport','مدل تونل GRE',draft.transport,cap.gre_types.filter(v=>!testedRows.length||testedRows.some(r=>r.transport===v)).map(v=>[v,greNames[v]||v]))}</div>${serverFields()}${addresses()}<div class="form-grid">${protocols()}</div></section>`;
  }
  function validate(){
   if(!draft.iran_server_id||!draft.foreign_server_id||draft.iran_server_id===draft.foreign_server_id)throw Error('دو سرور متفاوت برای ایران و خارج انتخاب کنید.');
   if(simple)draft.mappings=parsePorts(ports);else validateMappings(draft.mappings);
   if(draft.address_source==='servers'){
    const peer=host(draft.foreign_server_id),local=host(draft.iran_server_id);
    for(const [value,title]of[[peer,'خارج'],...(gre?[[local,'ایران']]:[])]){
     const actual=value.includes(':')?'ipv6':isIPv4(value)?'ipv4':null;
     if(actual&&actual!==draft.ip_family)throw Error(`آدرس ثبت‌شدهٔ سرور ${title} با ${draft.ip_family==='ipv6'?'IPv6':'IPv4'} سازگار نیست؛ آدرس همان سرور را در مدیریت سرورها بررسی کنید.`);
    }
    draft.public_host=local;draft.peer_host=peer;if(gre)draft.local_endpoint=local;
   }
   if(legacyGrpc&&draft.version!=='3.0.0')throw Error('این تونل قدیمی gRPC به GOST 3 نیاز دارد.');
   if(draft.failover&&!draft.monitor)throw Error('برای کنارگذاشتن مقصد قطع‌شده، پایش اتصال را روشن کنید.');
  }
  function draw(){
   if(disposed)return;
   const unstable=selectedRow()&&(draft.protocol==='both'?['tcp','udp']:[draft.protocol]).some(p=>selectedRow().protocols?.[p]?.status==='unstable');
   show((t?'ویرایش تونل ':'ساخت تونل ')+engines[engine].name,`<form data-network-editor data-engine="${engine}">${methodSummary(draft,[server(draft.iran_server_id)?.name||'',server(draft.foreign_server_id)?.name||''])}${(gre?greFields():gostFields())+`<section class="network-port-section">${mapFields()}</section>`+advancedFields()}${unstable?`<label class="tunnel-check tunnel-warning"><input type="checkbox" name="accept_unstable" data-setting required ${draft.accept_unstable?'checked':''}>ناپایداری این پروتکل را در نتیجهٔ تست دیدم و استفاده از آن را تأیید می‌کنم.</label>`:''}<div class="form-actions network-navigation"><button class="button primary" type="submit">${t?'ذخیره و اعمال':'ساخت تونل'}</button>${!t?btn('بازگشت','data-back-test','network-back'):''}${btn('انصراف','data-close','cancel')}</div></form>`);
   const f=body().querySelector('[data-network-editor]');
   f.elements.name.dir='auto';
   const alertInput=f.elements.telegram_alerts;
   if(!data().alerting?.available)alertInput.setAttribute('aria-disabled','true');
   alertInput.addEventListener('change',()=>{if(alertInput.checked&&!data().alerting?.available){alertInput.checked=false;draft.telegram_alerts=false;toast(data().alerting?.reason||'ابتدا ربات پنل/نمایندگان را فعال و حساب ادمین را متصل کنید.','error');}});
   for(const d of f.querySelectorAll('details'))if(openSections.has(d.querySelector('summary')?.textContent))d.open=true;
   if(t||testedRows.length)for(const key of ['iran_server_id','foreign_server_id'])f.elements[key].disabled=true;
   f.querySelector('[data-close]').onclick=()=>{creationDrafts.delete(engine);closeModal();};
   f.querySelector('[data-back-test]')?.addEventListener('click',()=>{capture();creationDrafts.set(engine,{draft:copy(draft),ports,simple,advanced,openSections:[...openSections],restartEnabled,restartHours});testFlow(engine);});
   for(const key of ['iran_server_id','foreign_server_id','version','ip_family','protocol','transport','nat','monitor','restart_enabled'])f.elements[key]?.addEventListener('change',()=>{capture();syncSelection();draw();});

   f.querySelector('[data-add-map]')?.addEventListener('click',()=>{capture();if(draft.mappings.length>=40){toast('حداکثر ۴۰ ردیف پورت قابل ثبت است.','error');return;}draft.mappings.push({public_port:0,target_port:0,public_end:0,target_end:0});draw();});
   f.querySelectorAll('[data-remove-map]').forEach(b=>b.onclick=()=>{capture();draft.mappings.splice(Number(b.dataset.removeMap),1);draw();});
   f.onsubmit=safe(async()=>{
    if(!f.reportValidity())return;capture();
    validate();syncSelection();
    const payload=copy(draft);for(const key of Object.keys(payload))if(key.startsWith('__'))delete payload[key];if(!payload.ip_family)payload.ip_family=null;
    const answer=await api(t?`/network-tunnels/${t.id}`:'/network-tunnels',{method:t?'PUT':'POST',body:JSON.stringify(payload)});
    if(disposed)return;creationDrafts.delete(engine);toast(answer.message);closeModal();await refresh();
   });
  }
  syncSelection();draw();
 }
 function stamp(n){if(!n)return '—';const d=new Date(n*1000);return `<time class="tunnel-time-value" dir="rtl" datetime="${d.toISOString()}"><bdi dir="ltr">${d.toLocaleTimeString('fa-IR',{hour:'2-digit',minute:'2-digit',hour12:false,timeZone:'Asia/Tehran'})}</bdi><span aria-hidden="true">—</span><bdi dir="ltr">${d.toLocaleDateString('fa-IR',{timeZone:'Asia/Tehran'})}</bdi></time>`;}
 const volume=n=>n===null||n===undefined?'—':(n/1073741824).toLocaleString('fa-IR',{maximumFractionDigits:3})+' GiB';
 function details(t){
  if(t.engine==='wattunnel'){wat.details(t);appendNodeOptions(t);return;}
  const s=t.settings,quota=Number(s.quota_gb)>0&&t.engine==='gre',snapshots=Object.values(t.remote_status||{}),exhausted=snapshots.some(r=>r?.quota_exhausted),known=snapshots.some(r=>typeof r?.quota_exhausted==='boolean'),used=snapshots.filter(r=>r?.traffic_available&&r.quota_used).map(r=>s.quota_direction==='rx'?Number(r.quota_used.rx)||0:s.quota_direction==='tx'?Number(r.quota_used.tx)||0:(Number(r.quota_used.rx)||0)+(Number(r.quota_used.tx)||0));
  show('جزئیات تونل '+label(t),`<div class="tunnel-details"><section class="tunnel-detail-summary"><header><h3>${esc(t.name)}</h3><span class="tunnel-badge tunnel-${esc(t.status)}">${esc({connected:'متصل',offline:'قطع',stopped:'متوقف',degraded:'نیازمند بررسی',needs_review:'نیازمند بررسی',failed:'خطا',queued:'در صف',running:'در حال اجرا'}[t.status]||'در حال بررسی')}</span></header>${t.message?`<p class="tunnel-muted">${esc(t.message)}</p>`:''}<dl><dt>سرور ایران</dt><dd>${esc(t.iran?.name)}</dd><dt>سرور خارج</dt><dd>${esc(t.foreign?.name)}</dd><dt>${t.engine==='gost'?'نسخهٔ GOST':'مدل GRE'}</dt><dd><bdi dir="ltr">${esc(t.engine==='gost'?s.version:t.transport.toUpperCase())}</bdi></dd><dt>ترافیک عبوری</dt><dd><bdi dir="ltr">${esc((s.protocol||'tcp').toUpperCase())}</bdi></dd><dt>آدرس کاربران</dt><dd>${s.mappings.map(m=>`<bdi class="tunnel-detail-endpoint" dir="ltr">${esc(endpoint(s.public_host,m.public_port,m.public_end))}</bdi>`).join('')}</dd><dt>پورت‌های خارج</dt><dd>${s.mappings.map(m=>`<bdi class="tunnel-detail-endpoint" dir="ltr">${esc(rangeText(m.target_port,m.target_end))}</bdi>`).join('')}</dd></dl></section><div class="tunnel-end-status">${[['iran','سرور ایران'],['foreign','سرور خارج']].map(([key,title])=>{const r=t.remote_status?.[key],noService=key==='foreign'&&t.engine==='gost'&&t.transport!=='grpc';return `<section><h4>${title}</h4><p>${r?.error?esc(r.error):noService?'فوروارد GOST روی ایران اجرا می‌شود':!r?'هنوز بررسی نشده':r.active?'سرویس روشن است':'سرویس متوقف است'}</p><dl><dt>وضعیت ارتباط</dt><dd>${r?.connected===true?'برقرار':r?.connected===false?'قطع':'تأیید نشده'}</dd><dt>دریافت / ارسال</dt><dd><bdi dir="ltr">${r?.traffic_available?volume(r.totals?.rx)+' / '+volume(r.totals?.tx):'—'}</bdi></dd><dt>آخرین بررسی</dt><dd>${stamp(r?.checked_at)}</dd></dl></section>`;}).join('')}</div>${quota?`<section class="network-quota-summary ${exhausted?'tunnel-warning':''}"><h4>سقف ترافیک</h4><p>${exhausted?(s.quota_mode==='enforce'?'سقف مصرف پر شده و توقف تونل درخواست شده است.':'سقف مصرف پر شده است؛ این حالت تونل را خودکار متوقف نمی‌کند.'):known?'مصرف این دوره به سقف نرسیده است.':'مصرف این دوره هنوز بررسی نشده است.'}</p><dl><dt>مصرف این دوره</dt><dd><bdi dir="ltr">${used.length?volume(Math.max(...used)):'—'}</bdi></dd><dt>سقف این دوره</dt><dd><bdi dir="ltr">${s.quota_gb} GiB</bdi></dd><dt>پس از رسیدن به سقف</dt><dd>${s.quota_mode==='enforce'?'توقف تونل':'هشدار در کارت و جزئیات'}</dd></dl></section>`:''}</div>`);
  appendNodeOptions(t);
 }
 function appendNodeOptions(t){
  const paths=t.settings.mappings.flatMap(m=>Array.from({length:Number(m.public_end??m.public_port)-Number(m.public_port)+1},(_,offset)=>({...m,public_port:Number(m.public_port)+offset,target_port:Number(m.target_port)+offset})));
  body().querySelector('.tunnel-details').insertAdjacentHTML('beforeend',`<section class="tunnel-node-options"><header><h4>استفاده برای نود</h4><p>نود روی سرور خارج اجرا می‌شود؛ آدرس اتصال کاربران، سرور ایران است.</p></header><div class="tunnel-node-grid">${select('node_mapping','پورت موردنظر',0,paths.map((m,i)=>[i,`${m.public_port} ← ${m.target_port}`]))}${btn('ساخت نود جدید',`data-network-new-node ${!t.foreign?.machine_id?'disabled':''}`)}${select('node_id','نود موجود روی خارج','',[['','انتخاب کنید'],...(t.nodes||[]).map(n=>[n.id,n.name])])}${btn('استفاده برای نود موجود','data-network-existing-node')}</div></section>`);
  enhanceControls(body());
  const prefill=()=>{const m=paths[Number(body().querySelector('[name=node_mapping]').value)];if(m.destinations?.length)throw Error('این پورت مقصد اختصاصی دارد؛ نود را از مدیریت نودها تنظیم کنید.');return {machine_id:t.foreign.machine_id,host:t.settings.public_host,port:String(m.public_port),server_port:m.target_port};};
  body().querySelector('[data-network-new-node]').onclick=safe(()=>openNode(null,{...prefill(),name:t.name}));
  body().querySelector('[data-network-existing-node]').onclick=safe(()=>{const node=t.nodes?.find(n=>n.id===Number(body().querySelector('[name=node_id]').value));if(!node)throw Error('یک نود موجود انتخاب کنید.');const p=prefill();if(Number(node.server_port)!==Number(p.server_port))throw Error('پورت خارج باید با پورت اجرای نود برابر باشد.');return openNode(node,p);});
 }

 function result(op,t){const hosts=op.result?.hosts||{};show((op.action==='logs'?'گزارش ':'نتیجهٔ عملیات ')+label(t),`<p>${esc(op.message)}</p>${op.result?.error?`<p class="tunnel-warning">${esc(op.result.error)}</p>`:''}<div class="tunnel-log-columns">${[['iran','سرور ایران'],['foreign','سرور خارج']].map(([k,v])=>`<section><h4>${v}</h4><pre class="log-box" dir="ltr">${esc(hosts[k]?.text||hosts[k]?.logs?.join('\n')||JSON.stringify(hosts[k]||op.result?.rollback_errors?.[k]||{},null,2))}</pre></section>`).join('')}</div>`);}
 async function poll(){if(disposed||!watch)return;const {id,t}=watch;const answer=await api(`/tunnel-operations/${id}`);if(disposed||watch?.id!==id)return;const op=answer.data;if(!['queued','running'].includes(op.status)){watch=null;await refresh();result(op,t);return;}const text=body().querySelector('[data-network-progress]');if(text)text.textContent=op.message;watchTimer=setTimeout(()=>safe(poll)(),2000);}
 async function action(t,a){
  if(['optimize','engine-update'].includes(a)){await openMaintenance(t,a,api);return;}
  if(a==='edit'){await editor(t,t.engine);return;}

  if(a==='delete'||a==='reset-quota'){show(a==='delete'?'حذف تونل':'صفر کردن مصرف سهمیه',`<p>${esc(t.name)}</p><p>${a==='delete'?'سرویس و تنظیمات متعلق به همین تونل از دو سرور حذف شود؟':'مبنای سهمیه از مصرف فعلی محاسبه شود؟'}</p><div class="form-actions">${btn('تأیید', 'data-confirm-network',a==='delete'?'server-delete-confirm':'primary')}${btn('انصراف','data-close','cancel')}</div>`);body().querySelector('[data-close]').onclick=closeModal;body().querySelector('[data-confirm-network]').onclick=safe(()=>submit(t,a,{confirm:true}));return;}
  if(a==='diagnose'){show('عیب‌یابی اتصال',`<p class="network-intro">آزمون روی هر دو سرور اجرا می‌شود و تنظیمات را تغییر نمی‌دهد.</p><div class="network-diagnostics">${[['ping','Ping'],['traceroute','مسیر ارتباط'],...(t.engine==='gre'?[['mtu','کشف MTU']]:[]),['connections','اتصال‌های همین تونل'],['analyze','بررسی کامل']].map(([k,v])=>btn(v,`data-diagnostic="${k}"`)).join('')}</div>`);body().querySelectorAll('[data-diagnostic]').forEach(b=>b.onclick=safe(()=>submit(t,'diagnose',{kind:b.dataset.diagnostic})));return;}
  await submit(t,a);
 }
 async function submit(t,action,extra={}){const answer=await api(`/network-tunnels/${t.id}/actions`,{method:'POST',body:JSON.stringify({action,...extra})});toast(answer.message);await refresh();if(['logs','preview','diagnose','history','optimize','engine-update'].includes(action)){show('در حال دریافت نتیجه',`<p data-network-progress>${esc(answer.data.message)}</p><p class="tunnel-muted">با بستن پنجره، عملیات در صف ادامه پیدا می‌کند.</p>`);watch={id:answer.data.id,t};watchTimer=setTimeout(()=>safe(poll)(),1500);}else closeModal();}
 return {choose,resumeTest,editor,details,action,dispose(){disposed=true;clearTimeout(watchTimer);clearTimeout(testTimer);}};
}
return {mount,label,actions,methodLabel,methodSummary};
})();

