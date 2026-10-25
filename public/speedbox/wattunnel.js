/* WatTunnel's own form; only supported engine settings reach its API. */
window.SpeedboxWatTunnel=(()=>{
'use strict';
const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const clone=x=>JSON.parse(JSON.stringify(x));
function mount(ctx){
 const {api,show,body,caps,field,select,toggle,btn,safe,refresh,toast,closeModal,testFlow,data,enhanceControls,validateMappings,parsePorts,portsText,methodSummary}=ctx;
 const drafts=new Map();
 async function editor(t,seed=null){
  const cap=await caps();
  if(!t&&seed&&drafts.has('new')&&(Number(drafts.get('new').iran_server_id)!==Number(seed.iran_server_id)||Number(drafts.get('new').foreign_server_id)!==Number(seed.foreign_server_id)))drafts.delete('new');
  let d=clone(t?{...cap.wat_defaults,...t.settings,name:t.name}:drafts.get('new')||cap.wat_defaults);
  if(seed)d={...d,...seed};d.engine='wattunnel';
  if(!t){d.address_source='servers';if(!d.name)d.name=[d.iran_server_id,d.foreign_server_id].map(id=>data().servers.find(s=>Number(s.id)===Number(id))?.name||'').join(' — ');}
  d.public_host=d.public_host||data().servers.find(s=>Number(s.id)===Number(d.iran_server_id))?.host||'';
  let expanded=false;
  let mapRows=d.mappings.map(m=>({public:portsText([m]),target:portsText([{public_port:m.target_port,public_end:m.target_end??m.target_port}])}));
  if(d.__ports?.mapRows)mapRows=clone(d.__ports.mapRows);
  const expand=value=>parsePorts(value).flatMap(m=>Array.from({length:m.public_end-m.public_port+1},(_,i)=>m.public_port+i));
  function mappings(){
   const result=[];
   for(const row of mapRows){
    const a=expand(row.public),b=expand(row.target);
    if(a.length!==b.length)throw Error('تعداد پورت‌های ایران و خارج در هر ردیف باید برابر باشد.');
    for(let i=0;i<a.length;i++){
     const last=result.at(-1);
     if(last&&last.public_end===a[i]-1&&last.target_end===b[i]-1){last.public_end=a[i];last.target_end=b[i];}
     else result.push({public_port:a[i],public_end:a[i],target_port:b[i],target_end:b[i]});
    }
   }
   validateMappings(result);return result;
  }
  function portFields(){
   return `<div class="tunnel-mapping-heading"><h4>پورت‌های اتصال</h4>${btn('<span aria-hidden="true">+</span> افزودن پورت','data-wat-add')}</div><div class="wat-mappings">${mapRows.map((m,i)=>`<div class="wat-map" data-wat-map="${i}">${[['public','پورت اتصال کاربران روی ایران'],['target','پورت نود روی سرور خارج']].map(([k,l])=>`<div class="field"><label for="wat-map-${i}-${k}">${l}</label><input id="wat-map-${i}-${k}" class="form-input" value="${esc(m[k])}" data-port="${k}" required dir="ltr"></div>`).join('')}${btn('حذف',`data-wat-remove="${i}" aria-label="حذف این پورت" ${mapRows.length===1?'disabled':''}`,'danger')}</div>`).join('')}</div><p class="tunnel-muted">برای چند پورت از کاما استفاده کنید؛ در هر ردیف، دو فهرست به ترتیب صعودی جفت می‌شوند و باید تعدادشان برابر باشد.</p>`;
  }
  const numeric=new Set(['tunnel_port','mtu','conns','sessions','lanes','max_mbps','tls_frag','ping_every','udp_idle','monitor_interval','restart_hours']);
  const input=(k,l,opts={})=>field(k,l,d[k],opts).replace('<input ','<input data-wat-setting ');
  const pick=(k,l,opts)=>select(k,l,d[k],opts).replace('<select ','<select data-wat-setting ');
  const check=(k,l)=>toggle(k,l,d[k]).replace('<input ','<input data-wat-setting ');
  const protocols=()=>{if(d.connection_mode==='cdn')return [['tcp','TCP'],['udp','UDP'],['both','TCP و UDP']];const r=d.__test?.rows?.find(r=>r.id===d.result_id);const allowed=r?['tcp','udp'].filter(p=>['ok','unstable'].includes(r.protocols?.[p]?.status)):['tcp','udp'];return [['tcp','TCP'],['udp','UDP'],['both','TCP و UDP']].filter(([v])=>v==='both'?allowed.length===2:allowed.includes(v));};
  function capture(){
   const f=body().querySelector('[data-wat-editor]');if(!f)return;
   for(const el of f.querySelectorAll('[data-wat-setting]'))d[el.name]=el.type==='checkbox'?el.checked:numeric.has(el.name)?Number(el.value):el.name==='reverse'?el.value==='true':el.value;
   mapRows=[...f.querySelectorAll('[data-wat-map]')].map(row=>Object.fromEntries([...row.querySelectorAll('[data-port]')].map(el=>[el.dataset.port,el.value])));
   expanded=!!f.querySelector('[data-wat-advanced]')?.open;window.SpeedboxTunnelCdn?.capture(f,d);
   if(!t)drafts.set('new',clone({...d,__ports:{mapRows}}));
  }
  function draw(){
   const cdnMethod={engine:'wattunnel',kind:d.reverse?'reverse':'direct',transport:d.transport,orientation:'normal'};
   if(!window.SpeedboxTunnelCdn?.supported(cdnMethod))d.connection_mode='direct';
   const carrier=d.transport,icmp=carrier==='icmp',tls=['tls','wss','h2','grpc'].includes(carrier),http=['ws','wss','h2','h2c','grpc'].includes(carrier),pool=!['icmp','udp'].includes(carrier);
   if(icmp)d.ip_family='ipv4';if(carrier==='udp')d.reverse=false;if(icmp||d.reverse)d.sessions=1;
   const r=d.__test?.rows?.find(r=>r.id===d.result_id),unstable=r&&['tcp','udp'].filter(p=>d.protocol==='both'||p===d.protocol).some(p=>r.protocols?.[p]?.status==='unstable');
   show(t?'ویرایش تونل WatTunnel':'ساخت تونل WatTunnel',`<form data-wat-editor class="tunnel-builder wat-editor">${methodSummary(d,[d.iran_server_id,d.foreign_server_id].map(id=>data().servers.find(s=>Number(s.id)===Number(id))?.name||''))}<div class="form-grid">${input('name','نام تونل',{required:true})}${input('public_host','آدرس اتصال کاربران',{required:true,hint:'IP یا دامنهٔ ورودی سرور ایران؛ این آدرس در کانفیگ کاربران استفاده می‌شود.'})}${!icmp?input('tunnel_port','پورت ارتباط بین دو سرور',{type:'number',min:1,max:65535,required:true,hint:'این پورت برای ارتباط خود تونل است؛ با پورت کاربران متفاوت باشد.'}):''}${pick('protocol','ترافیک پورت‌ها',protocols())}</div>${t?`<details class="network-section"><summary>روش ارتباط</summary><div class="form-grid">${pick('transport','حامل ارتباط',cap.wat_carriers.map(c=>[c,c.toUpperCase()]))}${pick('reverse','جهت ارتباط',carrier==='udp'?[['false','ایران به خارج']]:[['false','ایران به خارج'],['true','خارج به ایران — معکوس']])}${pick('ip_family','نوع IP',icmp?[['ipv4','IPv4']]:[['ipv4','IPv4'],['ipv6','IPv6']])}</div><p class="tunnel-muted">تغییر روش، ارتباط را برای اعمال تنظیمات مجدد راه‌اندازی می‌کند.</p></details>`:''}${window.SpeedboxTunnelCdn?.render(d,cdnMethod,{editing:!!t,blockedDirect:!!r&&!r.selectable,listener:{side:d.reverse?'ایران':'خارج',host:data().servers.find(s=>Number(s.id)===Number(d.reverse?d.iran_server_id:d.foreign_server_id))?.host||''}})||''}${portFields()}<details data-wat-advanced class="tunnel-advanced network-advanced" ${expanded?'open':''}><summary>تنظیمات پیشرفتهٔ WatTunnel</summary><div class="network-advanced-content"><div class="form-grid">${input('mtu','MTU بستهٔ تونل',{type:'number',min:0,max:icmp||carrier==='udp'?1400:16384,hint:'صفر: مقدار خودکار حامل. مقدار دستی از ۲۵۶ شروع می‌شود.'})}${input('max_mbps','سقف سرعت هر نشست (MB/s)',{type:'number',min:0,max:1250,hint:'مگابایت بر ثانیه؛ صفر: پیش‌فرض موتور.'})}${pool?input('conns','تعداد اتصال‌های حامل',{type:'number',min:1,max:64}):''}${!icmp&&!d.reverse?input('sessions','تعداد نشست مستقل',{type:'number',min:1,max:16}):''}${icmp?`${input('lanes','تعداد مسیر ICMP',{type:'number',min:1,max:16})}${pick('icmp_type','نوع پیام ICMP',[['echo','Echo'],['timestamp','Timestamp'],['info','Info'],['mask','Mask']])}`:''}${pick('fec','بازیابی بسته‌های ازدست‌رفته',[['off','خاموش'],['auto','خودکار']])}${pool?pick('tcp_cc','کنترل ازدحام اتصال حامل',[['system','پیش‌فرض سیستم'],['bbr','BBR'],['cubic','CUBIC']]):''}${input('ping_every','فاصلهٔ پیام نگه‌دارنده (ثانیه)',{type:'number',min:1,max:300})}${d.protocol!=='tcp'?input('udp_idle','مهلت بیکاری جریان UDP (ثانیه)',{type:'number',min:5,max:3600}):''}${http?input('path','مسیر HTTP حامل',{required:true,hint:'برای نمونه /wat؛ در دو سمت یکسان است.'}):''}${http?input('host_header','نام Host / Authority',{hint:'اختیاری؛ دامنهٔ مقصد درخواست HTTP.'}):''}${tls?`${input('sni','نام SNI',{hint:'اختیاری؛ نام دامنه در آغاز ارتباط TLS.'})}${input('tls_pin','اثر انگشت گواهی SHA-256',{hint:'اختیاری؛ ۶۴ رقم هگز. باید متعلق به گواهی سمت شنونده باشد.'})}${pick('fingerprint','اثر انگشت TLS',['go','chrome','firefox','safari','edge','random'].map(v=>[v,v]))}${input('tls_frag','اندازهٔ قطعهٔ ClientHello',{type:'number',min:0,max:16384,hint:'صفر: بدون قطعه‌بندی.'})}`:''}</div>${tls?check('tls_small_ch','ClientHello کوچک'):''}${check('hedge','تکرار بسته‌های کوچک برای کاهش تأخیر')}${check('monitor','پایش دوره‌ای اتصال')}<div class="form-grid">${d.monitor?input('monitor_interval','فاصلهٔ پایش (ثانیه)',{type:'number',min:5,max:300}):''}${input('restart_hours','راه‌اندازی مجدد دوره‌ای (ساعت)',{type:'number',min:0,max:168,hint:'صفر: خاموش.'})}</div></div></details>${toggle('telegram_alerts','هشدار قطع تونل در تلگرام',d.telegram_alerts??Boolean(data().alerting?.available)).replace('<input ','<input data-wat-setting ')}${!data().alerting?.available?`<p class="tunnel-muted">${esc(data().alerting?.reason||'هشدار تلگرام در تنظیمات پنل فعال نشده است.')}</p>`:''}${unstable&&d.connection_mode!=='cdn'?'<label class="tunnel-check tunnel-warning"><input name="accept_unstable" type="checkbox" required>این مسیر در تست ناپایدار بود؛ با اطلاع از نتیجه انتخابش می‌کنم.</label>':''}<div class="form-actions network-navigation"><button type="submit" class="button primary">${t?'ذخیره و اعمال تغییرات':'ساخت تونل'}</button>${!t?btn('بازگشت به نتایج','data-wat-back','network-back'):''}${btn('انصراف','data-close','cancel')}</div></form>`);
   const f=body().querySelector('[data-wat-editor]');
   f.querySelectorAll('[name=transport],[name=reverse],[name=ip_family],[name=protocol],[name=monitor]').forEach(el=>el.onchange=()=>{capture();draw();});
   f.querySelector('[data-wat-add]')?.addEventListener('click',()=>{capture();if(mapRows.length>=40){toast('حداکثر ۴۰ ردیف پورت قابل ثبت است.','error');return;}mapRows.push({public:'',target:''});draw();});
   f.querySelectorAll('[data-wat-remove]').forEach(b=>b.onclick=()=>{capture();mapRows.splice(Number(b.dataset.watRemove),1);draw();});
   f.querySelector('[data-wat-back]')?.addEventListener('click',safe(async()=>{capture();await testFlow('wattunnel');}));
   f.onsubmit=safe(async()=>{
    capture();d.mappings=mappings();validateMappings(d.mappings);
    const payload={...d};for(const k of Object.keys(payload))if(k.startsWith('__'))delete payload[k];
    if(f.elements.accept_unstable)payload.accept_unstable=f.elements.accept_unstable.checked;
    const answer=await api('/network-tunnels'+(t?'/'+t.id:''),{method:t?'PUT':'POST',body:JSON.stringify(payload)});
    if(!t)drafts.delete('new');toast(answer.message);closeModal();await refresh();
   });
   window.SpeedboxTunnelCdn?.bind(f,d,cdnMethod,{api,captureAll:capture,redraw:draw,toast,payload:()=>{d.mappings=mappings();const payload={...d,engine:'wattunnel',...(t?{tunnel_id:t.id}:{})};for(const k of Object.keys(payload))if(k.startsWith('__'))delete payload[k];return payload;}});
   enhanceControls(body());
  }
  draw();
 }
 function details(t){
  const s=t.settings,stamp=v=>v?new Date(v*1000).toLocaleString('fa-IR'):'بررسی نشده',volume=n=>Number.isFinite(Number(n))?(Number(n)/1073741824).toFixed(2)+' GiB':'—';
  show('جزئیات تونل WatTunnel',`<div class="tunnel-details"><section class="tunnel-detail-summary"><header><h3>${esc(t.name)}</h3><span dir="ltr">WatTunnel ${esc(s.version)}</span></header><p>${esc(t.message)}</p><dl><dt>حامل</dt><dd dir="ltr">${esc(t.transport.toUpperCase())} · ${s.ip_family==='ipv6'?'IPv6':'IPv4'}</dd><dt>جهت اتصال</dt><dd>${s.reverse?'خارج به ایران — معکوس':'ایران به خارج'}</dd><dt>پروتکل پورت‌ها</dt><dd dir="ltr">${esc(s.protocol.toUpperCase())}</dd><dt>پورت‌های ایران ← خارج</dt><dd>${s.mappings.map(m=>`<bdi class="tunnel-detail-endpoint" dir="ltr">${m.public_port}–${m.public_end} → ${m.target_port}–${m.target_end}</bdi>`).join('')}</dd></dl></section>${window.SpeedboxTunnelCdn?.details(t)||''}<div class="tunnel-end-status">${[['iran','سرور ایران'],['foreign','سرور خارج']].map(([key,title])=>{const r=t.remote_status?.[key],st=r?.engine_stats||{};return `<section><h4>${title}</h4><p>${esc(t[key]?.name)} · ${!r?'بررسی نشده':r.active?'سرویس روشن است':'سرویس متوقف است'}</p><dl><dt>ارتباط موتور</dt><dd>${r?.connected===true?'برقرار':r?.connected===false?'قطع':'تأیید نشده'}</dd><dt>دریافت / ارسال</dt><dd dir="ltr">${r?.traffic_available?volume(r.totals?.rx)+' / '+volume(r.totals?.tx):'—'}</dd><dt>تأخیر موتور</dt><dd dir="ltr">${st.srtt_ms==null?'—':Number(st.srtt_ms).toFixed(1)+' ms'}</dd><dt>نشست / جریان TCP</dt><dd dir="ltr">${st.sessions??'—'} / ${st.streams??'—'}</dd>${r?.certificate_sha256?`<dt>اثر انگشت گواهی TLS</dt><dd><bdi dir="ltr" style="overflow-wrap:anywhere">${esc(r.certificate_sha256)}</bdi></dd>`:''}<dt>آخرین بررسی</dt><dd>${stamp(r?.checked_at)}</dd></dl></section>`;}).join('')}</div></div>`);
 }
 return {editor,details,reset(){drafts.clear();}};
}
return {mount};
})();
