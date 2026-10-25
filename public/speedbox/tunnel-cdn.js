/* Shared inline CDN settings; labels and controls follow the existing node editor. */
window.SpeedboxTunnelCdn=(()=>{
 const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
 const supported=m=>(m.engine||'backpack')==='backpack'?m.kind==='reverse'&&['ws','wss','wsmux','wssmux'].includes(m.transport):m.engine==='wattunnel'&&['ws','wss','grpc'].includes(m.transport);
 const secure=m=>['wss','wssmux','grpc'].includes(m.transport);
 const copy=v=>JSON.parse(JSON.stringify(v));
 function defaults(d,m={transport:'wss'}){d.connection_mode??='direct';d.cdn??={};d.cdn.domain??='';d.cdn.port??=Number(d.tunnel_port);d.cdn.edge_ip??='';d.cdn.cert_config??={cert_mode:'content',cert_content:'',key_content:'',email:'',http_port:80,dns_provider:'arvancloud',dns_env:{}};d.cdn.cert_config.saved_secret_mode??=d.cdn.cert_config.has_saved_secret?d.cdn.cert_config.cert_mode:'';return d.cdn;}
 const text=(name,label,value,{area=false,type='text',required=false,hint='',hintHtml='',placeholder='',dir='ltr'}={})=>`<div class="field ${area?'full':''}"><label for="cdn-${name}">${label}</label>${area?`<textarea class="form-input" id="cdn-${name}" data-cdn-field="${name}" dir="ltr" ${required?'required':''} placeholder="${esc(placeholder)}">${esc(value)}</textarea>`:`<input class="form-input" id="cdn-${name}" data-cdn-field="${name}" type="${type}" dir="${dir}" value="${esc(value)}" ${type==='number'?'min="1" max="65535"':''} ${required?'required':''} placeholder="${esc(placeholder)}">`}${hint||hintHtml?`<small dir="rtl">${hintHtml||esc(hint)}</small>`:''}</div>`;
 function render(d,m,{blockedDirect=false,listener='ایران',editing=false}={}){
  if(!supported(m))return '';
  const c=defaults(d,m),cert=c.cert_config,isCdn=d.connection_mode==='cdn';
  const receiver=typeof listener==='object'?listener:{side:listener,host:''};
  const target=[receiver.side,receiver.name,receiver.host].filter(Boolean).map(esc).join(' — ');
  const hintHtml='در پنل <bdi dir="ltr">CDN</bdi>، آی‌پی مبدأ این دامنه را '+(receiver.host?'روی <bdi dir="ltr">'+esc(receiver.host)+'</bdi> تنظیم کنید':'برابر آی‌پی سرور مقصد قرار دهید')+' و پروکسی دامنه را فعال کنید — ابر روشن.';
  return `<div class="tunnel-cdn-settings"><div class="field"><label for="cdn-mode">مسیر ارتباط</label><select id="cdn-mode" data-cdn-mode><option value="direct" ${!isCdn?'selected':''} ${blockedDirect?'disabled':''}>مستقیم</option><option value="cdn" ${isCdn?'selected':''}>CDN</option></select>${blockedDirect?'<small>این روش در تست مستقیم پاسخ نداده است؛ مسیر CDN را جداگانه بررسی کنید.</small>':''}</div>${isCdn?`<section class="tunnel-cdn-section" aria-label="تنظیمات CDN"><div class="tunnel-cdn-heading"><h4>تنظیمات CDN</h4><small>مقصد CDN: <bdi>${target}</bdi></small></div>${Number(c.port)!==Number(d.tunnel_port)?'<p class="tunnel-muted">این تونل قبلاً با دو پورت متفاوت ثبت شده است. برای اعمال فرم تک‌پورت، پورت ارتباط را در سرور و CDN یکسان تنظیم و اتصال را دوباره بررسی کنید.</p>':''}<div class="form-grid">${text('domain','آدرس دامنه',c.domain,{required:true,hintHtml})}${text('edge_ip','IP لبه CDN',c.edge_ip,{hint:'اختیاری؛ با خالی‌گذاشتن، از پاسخ DNS دامنه استفاده می‌شود.'})}</div>${secure(m)?`<div class="tunnel-cdn-certificate"><h4>گواهی TLS</h4><div class="field"><label for="cdn-cert-mode">روش گواهی</label><select id="cdn-cert-mode" data-cdn-cert-mode>${[['content','محتوای گواهی'],['file','فایل گواهی'],['http','HTTP-01 (ACME)'],['dns','DNS-01 (ACME)']].map(([v,l])=>`<option value="${v}" ${cert.cert_mode===v?'selected':''}>${l}</option>`).join('')}</select></div><div class="form-grid">${certificateFields(cert)}</div></div>`:''}${editing?'<p class="tunnel-muted tunnel-cdn-port-note">اگر پورت فعلی در حال استفاده است، برای بررسی مسیر جدید ابتدا تونل موجود را متوقف کنید؛ آزمون آن را خودکار متوقف نمی‌کند.</p>':''}<div class="tunnel-cdn-check"><button type="button" class="button secondary" data-cdn-test>بررسی اتصال CDN</button><button type="button" class="button cancel" data-cdn-cancel hidden>توقف بررسی</button><div data-cdn-status role="status" aria-live="polite">${d.__cdnMessage?esc(d.__cdnMessage):c.test_id?'نتیجهٔ قبلی ذخیره شده است؛ تغییر مسیر به بررسی دوباره نیاز دارد.':'پس از تکمیل گواهی، پورت ارتباط و پورت‌های نود، مسیر CDN را بررسی کنید.'}</div></div></section>`:''}</div>`;
 }
 function certificateFields(c){
  const saved=c.has_saved_secret&&c.saved_secret_mode===c.cert_mode;
  if(c.cert_mode==='content')return text('cert_content','محتوای گواهی',c.cert_content||'',{area:true,required:true,placeholder:'-----BEGIN CERTIFICATE-----\n...\n-----END CERTIFICATE-----'})+text('key_content','محتوای کلید خصوصی',c.key_content||'',{area:true,required:!saved,placeholder:'-----BEGIN PRIVATE KEY-----\n...\n-----END PRIVATE KEY-----',hint:saved?'کلید خصوصی ثبت شده است؛ خالی‌گذاشتن این کادر، کلید قبلی را حذف نمی‌کند.':''});
  if(c.cert_mode==='file')return text('cert_file','مسیر فایل گواهی',c.cert_file||'',{required:true})+text('key_file','مسیر فایل کلید خصوصی',c.key_file||'',{required:true});
  const email=text('email','ایمیل ACME',c.email||'',{type:'email',placeholder:'اختیاری'});
  if(c.cert_mode==='http')return email+text('http_port','پورت چالش HTTP',c.http_port||80,{type:'number',required:true,hint:'درخواست اعتبارسنجی روی پورت عمومی ۸۰ باید به این سرویس برسد.'});
  return email+window.SpeedboxCertificateDns.render(c,{context:'tunnel',prefix:'cdn-dns'});
 }
 function capture(form,d){
  if(!form)return;const c=defaults(d);const mode=form.querySelector('[data-cdn-mode]');if(mode)d.connection_mode=mode.value;
  const method=form.querySelector('[data-cdn-cert-mode]');if(method)c.cert_config.cert_mode=method.value;
  for(const el of form.querySelectorAll('[data-cdn-field]')){
   const key=el.dataset.cdnField;let value=el.type==='number'?Number(el.value):el.value;
   if(key==='dns_env'){
    value={};for(const line of el.value.split(/\r?\n/).filter(x=>x.trim())){const i=line.indexOf('=');if(i<1){el.setCustomValidity('هر خط را به شکل KEY=VALUE وارد کنید.');continue;}value[line.slice(0,i).trim()]=line.slice(i+1).trim();}if(!el.value.trim()||Object.keys(value).length)el.setCustomValidity('');
   }
   if(['domain','edge_ip'].includes(key))c[key]=value;else c.cert_config[key]=value;
  }
  c.port=Number(d.tunnel_port);
  if(c.cert_config.cert_mode==='dns')window.SpeedboxCertificateDns.capture(form,c.cert_config,{context:'tunnel'});
 }
 function stamp(d,m){const clean=copy(d);delete clean.__cdnMessage;delete clean.__cdnPending;delete clean.__test;delete clean.__ports;if(clean.cdn)delete clean.cdn.test_id;return JSON.stringify([clean,m]);}
 function bind(form,d,m,{api,captureAll,redraw,payload,toast=()=>{}}){
  const section=form.querySelector('.tunnel-cdn-settings');if(!section)return;
  for(const el of section.querySelectorAll('[data-cdn-mode],[data-cdn-cert-mode]'))el.addEventListener('change',()=>{captureAll();capture(form,d);d.cdn.test_id=null;d.__cdnMessage='';redraw();});
  if(d.cdn?.cert_config?.cert_mode==='dns')window.SpeedboxCertificateDns.bind(section,d.cdn.cert_config,{context:'tunnel',onChange:()=>{captureAll();d.cdn.test_id=null;d.__cdnMessage='';redraw();}});
  const status=section.querySelector('[data-cdn-status]'),button=section.querySelector('[data-cdn-test]'),cancel=section.querySelector('[data-cdn-cancel]');if(!button)return;
  let polling=false;
  const invalidate=()=>{if(d.cdn){d.cdn.test_id=null;d.__cdnMessage='تنظیمات تغییر کرده است؛ مسیر CDN را دوباره بررسی کنید.';if(status.isConnected)status.textContent=d.__cdnMessage;}};
  form.addEventListener('input',invalidate);
  const show=message=>{d.__cdnMessage=message;if(status.isConnected)status.textContent=message;};
  button.addEventListener('click',async()=>{
   if(polling)return;
   try{
    captureAll();capture(form,d);if(!form.reportValidity())return;
    const body=payload();const before=stamp(d,m);polling=true;button.disabled=true;show('بررسی اتصال CDN در صف قرار می‌گیرد…');
    let answer=await api('/tunnel-cdn-tests',{method:'POST',body:JSON.stringify(body)});
    const id=answer.data.id,opId=answer.operation.id;cancel.hidden=false;
    cancel.onclick=async()=>{cancel.disabled=true;try{await api('/tunnel-operations/'+opId+'/cancel',{method:'POST',body:'{}'});show('در حال توقف و جمع‌آوری آزمون…');}catch(e){toast(e.message,'error');}finally{cancel.disabled=false;}};
    while(['queued','running'].includes(answer.operation.status)){
     show(answer.operation.message||'در حال بررسی مسیر CDN…');await new Promise(r=>setTimeout(r,1200));
     if(!form.isConnected)return;answer=await api('/tunnel-cdn-tests/'+id);
    }
    captureAll();capture(form,d);
    if(answer.operation.status==='succeeded'&&answer.operation.result?.cdn_verified){
     if(stamp(d,m)===before){d.cdn.test_id=id;show('اتصال موتور و انتقال داده از مسیر CDN تأیید شد.');}
     else show('آزمون انجام شد، اما تنظیمات تغییر کرده است؛ دوباره بررسی کنید.');
    }else{d.cdn.test_id=null;show(answer.data.message||answer.operation.message||'مسیر CDN تأیید نشد.');}
   }catch(e){d.cdn.test_id=null;show(e.message);toast(e.message,'error');}
   finally{polling=false;if(button.isConnected)button.disabled=false;if(cancel.isConnected)cancel.hidden=true;}
  });
 }
 function details(t){
  const s=t.settings||{};if(s.connection_mode!=='cdn')return '';
  const observed=Object.values(t.remote_status||{}).map(r=>r?.cdn).filter(Boolean),verified=observed.find(r=>r.cdn_verified===true),cert=observed.find(r=>r.expires_at);
  return `<section class="tunnel-cdn-detail"><h4>مسیر CDN</h4><dl><dt>آدرس دامنه</dt><dd><bdi dir="ltr">${esc(s.cdn.domain)}</bdi></dd><dt>پورت ارتباط بین دو سرور</dt><dd><bdi dir="ltr">${esc(s.tunnel_port)}</bdi></dd>${Number(s.cdn.port)!==Number(s.tunnel_port)?`<dt>پورت CDN در تنظیم قبلی</dt><dd><bdi dir="ltr">${esc(s.cdn.port)}</bdi></dd>`:''}<dt>آخرین بررسی مسیر CDN</dt><dd>${verified?'تأیید شده':'تأیید نشده'}</dd>${verified?`<dt>مقصد اتصال موتور</dt><dd><bdi dir="ltr">${esc(verified.peer_addresses.join(' / '))}</bdi></dd>`:''}${cert?`<dt>اعتبار گواهی</dt><dd>${cert.expires_at*1000<=Date.now()?'منقضی شده — ':''}${esc(new Date(cert.expires_at*1000).toLocaleDateString('fa-IR'))}</dd>`:''}</dl></section>`;
 }
 return {supported,render,capture,bind,details,defaults};
})();
