window.SpeedboxWireGuard={mount(content,{api,toast,openModal,closeModal,modalBody,enhanceControls,entityLayout,isBusy}){
  let disposed=false,timer=null,version=0,fetching=false,editing=false,acting=false,rows=[],servers=[];
  const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const labels={draft:'آماده نصب',queued:'در صف',sync_queued:'در حال همگام‌سازی',ready:'آماده',failed:'ناموفق',disabled:'غیرفعال',deleting:'در حال حذف',delete_failed:'حذف ناموفق'};
  const schedule=()=>{clearTimeout(timer);if(!disposed)timer=setTimeout(()=>{if(document.hidden||editing||acting||isBusy?.()){schedule();return;}load(true).catch(()=>{});},5000);};
  const removing=i=>['deleting','delete_failed'].includes(i.status);
  const busy=i=>['queued','sync_queued','deleting'].includes(i.status);
  function actions(i){
    const disabled=busy(i)||acting?'disabled':'';
    const main=removing(i)?'':`<button type="button" class="button ${i.enabled?'entity-restart':'entity-install'}" data-wg-action="${i.enabled?'disable':'enable'}" data-id="${i.id}" ${disabled}>${i.status==='queued'?(i.enabled?(i.public_key?'در حال فعال‌سازی':'در حال نصب'):'در حال غیرفعال‌سازی'):i.enabled?'غیرفعال‌سازی':i.public_key?'فعال‌سازی':'نصب و فعال‌سازی'}</button><button type="button" class="button entity-edit" data-wg-edit="${i.id}" ${disabled}>ویرایش</button>${i.public_key?`<button type="button" class="button entity-report" data-wg-action="sync" data-id="${i.id}" ${disabled}>${i.status==='sync_queued'?'در حال همگام‌سازی':'همگام‌سازی'}</button>`:''}`;
    return `<div class="actions">${main}<button type="button" class="button entity-delete" data-wg-delete="${i.id}" ${disabled}>${i.status==='deleting'?'در حال حذف':i.status==='delete_failed'?'تلاش دوباره حذف':'حذف'}</button></div>`;
  }
  async function operate(path,options){
    if(acting)return;acting=true;render();
    try{const r=await api(path,{...options,background:true});toast(r.message);await load(true);}
    catch(err){if(!disposed&&err.name!=='AbortError')toast(err.message,'error');}
    finally{acting=false;if(!disposed)render();schedule();}
  }
  function nextName(){let n=1;const names=new Set(rows.map(i=>i.name));while(names.has(`WireGuard ${n}`))n++;return `WireGuard ${n}`;}
  function usedPorts(server,exclude){
    const used=new Set();for(const row of rows){if(row.id===exclude)continue;
      if(Number(row.managed_server_id)===Number(server)){used.add(Number(row.listen_port));if(row.mode==='tunnel')used.add(Number(row.tunnel_port));}
      if(row.mode==='tunnel'&&Number(row.ingress_server_id)===Number(server)){used.add(Number(row.endpoint_port));used.add(Number(row.tunnel_port));}
    }return used;
  }
  function freePort(server,exclude,extra=[]){const used=usedPorts(server,exclude);extra.forEach(p=>used.add(Number(p)));for(let p=51820;p<=65535;p++)if(!used.has(p))return p;throw new Error('پورت آزاد برای این سرور یافت نشد');}
  function shell(){if(content.querySelector('#wgSearch'))return;content.innerHTML='<div class="toolbar entity-toolbar"><button type="button" class="button primary" data-wg-new><i class="fas fa-plus" aria-hidden="true"></i> <span dir="rtl">ساخت <bdi dir="ltr">WireGuard</bdi></span></button><input class="search" id="wgSearch" placeholder="جستجو" aria-label="جستجوی WireGuard"></div><article class="panel-card entity-list-shell" data-wg-list></article>';content.querySelector('[data-wg-new]').onclick=()=>form();content.querySelector('#wgSearch').oninput=render;}
  function render(){
    const query=(content.querySelector('#wgSearch')?.value||'').trim().toLowerCase();
    const list=rows.filter(i=>`${i.name} ${i.server?.name||''} ${i.ingress?.name||''} ${i.endpoint_host}`.toLowerCase().includes(query));
    const markup=`<div class="table-wrap"><table class="wg-table"><thead><tr><th>نام</th><th>سرور خروجی</th><th>مسیر اتصال</th><th>ورودی</th><th>پروفایل‌ها</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody>${list.map(i=>`<tr data-wg-row="${i.id}"><td><b>${esc(i.name)}</b></td><td>${esc(i.server?.name||'—')}</td><td>${i.mode==='tunnel'?`تونل${i.ingress?.name?'<small>'+esc(i.ingress.name)+'</small>':''}`:'مستقیم'}</td><td><bdi>${esc(i.endpoint_host)}:${esc(i.endpoint_port)}</bdi></td><td>${Number(i.peers_count||0).toLocaleString('fa-IR')}</td><td><span class="badge ${i.status==='ready'?'online':['failed','delete_failed'].includes(i.status)?'failed':i.status==='disabled'?'offline':'queued'}">${esc(labels[i.status]||i.status)}</span>${i.last_error?`<p class="wg-error">${esc(i.last_error)}</p>`:''}</td><td>${actions(i)}</td></tr>`).join('')||`<tr><td colspan="7" class="entity-loading-cell" dir="rtl">${query?'موردی با این جستجو پیدا نشد':'هنوز سرویسی تعریف نشده است'}</td></tr>`}</tbody></table></div>`;
    const target=content.querySelector('[data-wg-list]');if(!target)return;
    const scroll=target.querySelector('.table-wrap')?.scrollLeft||0;
    target.innerHTML=entityLayout?entityLayout(markup,'wireguard'):markup;
    if(target.querySelector('.table-wrap'))target.querySelector('.table-wrap').scrollLeft=scroll;
    target.querySelectorAll('[data-wg-edit]').forEach(b=>b.onclick=()=>form(rows.find(i=>i.id===Number(b.dataset.wgEdit))));
    target.querySelectorAll('[data-wg-action]').forEach(b=>b.onclick=()=>operate(`/wireguard/${b.dataset.id}/actions`,{method:'POST',body:JSON.stringify({action:b.dataset.wgAction})}));
    target.querySelectorAll('[data-wg-delete]').forEach(b=>b.onclick=()=>{if(confirm('این سرویس و منابع اختصاصی نصب‌شده آن حذف شوند؟'))operate(`/wireguard/${b.dataset.wgDelete}`,{method:'DELETE'});});
  }
  async function load(background=false){
    if(disposed||fetching)return;clearTimeout(timer);fetching=true;const current=++version;
    try{const r=await api('/wireguard');if(disposed||current!==version)return;rows=r.data||[];servers=r.servers||[];shell();if(!editing)render();}
    catch(err){if(!disposed&&err.name!=='AbortError'&&!background)throw err;}
    finally{fetching=false;schedule();}
  }
  function form(i=null){
    editing=true;clearTimeout(timer);const fixed=!!i?.public_key;let listenEdited=false,endpointEdited=false;
    const input=(name,label,value,{type='text',required=false,extra=''}={})=>`<div class="field"><label for="wg-${name}">${label}</label><input class="form-input" id="wg-${name}" name="${name}" type="${type}" value="${esc(value)}" ${required?'required':''} ${extra}></div>`;
    const options=selected=>servers.map(s=>`<option value="${s.id}" ${String(s.id)===String(selected)?'selected':''}>${esc(s.name)}</option>`).join('');
    openModal(i?'ویرایش \u2066WireGuard\u2069':'ساخت \u2066WireGuard\u2069',`<form data-wg-form><div class="form-grid">
      ${input('name','نام',i?.name||nextName(),{required:true,extra:'maxlength="100"'})}
      <div class="wg-field-row"><div class="field"><label for="wg-server">سرور خروجی</label><select class="form-input" id="wg-server" name="managed_server_id" required ${fixed?'disabled':''}><option value="">انتخاب</option>${options(i?.managed_server_id)}</select></div><div class="field"><label for="wg-mode">مسیر اتصال</label><select class="form-input" id="wg-mode" name="mode" ${fixed?'disabled':''}><option value="direct">مستقیم</option><option value="tunnel" ${i?.mode==='tunnel'?'selected':''}>تونل</option></select></div></div>
      <div class="wg-field-row" data-wg-tunnel><div class="field"><label for="wg-ingress">سرور ورودی ایران</label><select class="form-input" id="wg-ingress" name="ingress_server_id" ${fixed?'disabled':''}><option value="">انتخاب</option>${options(i?.ingress_server_id)}</select></div>${input('tunnel_port','پورت ارتباط دو سرور',i?.tunnel_port||'',{type:'number',extra:`min="1024" max="65535" placeholder="خودکار" ${fixed?'readonly':''}`})}</div>
      <div class="wg-field-row">${input('endpoint_host','IP یا دامنه ورودی',i?.endpoint_host||'',{extra:'dir="ltr" placeholder="خودکار"'})}${input('endpoint_port','پورت ورودی',i?.endpoint_port||51820,{type:'number',required:true,extra:'min="1024" max="65535"'})}</div>
      <details class="wg-advanced"><summary>تنظیمات پیشرفته</summary><div class="wg-field-row">
      ${input('listen_port','پورت UDP خروجی',i?.listen_port||51820,{type:'number',required:true,extra:`min="1024" max="65535" ${fixed?'readonly':''}`})}
      ${input('network','شبکه داخلی IPv4',i?.auto_network&&!fixed?'':i?.network||'',{extra:`dir="ltr" placeholder="خودکار" ${fixed?'readonly':''}`})}
      ${input('dns','DNS',(i?.dns||['1.1.1.1','8.8.8.8']).join(', '),{required:true,extra:'dir="ltr"'})}
      ${input('mtu','MTU',i?.mtu||1380,{type:'number',required:true,extra:`min="1280" max="1420" ${fixed?'readonly':''}`})}
      <div data-wg-link-network>${input('link_network','شبکه ارتباط دو سرور',i?.auto_link_network&&!fixed?'':i?.link_network||'',{extra:`dir="ltr" placeholder="خودکار" ${fixed?'readonly':''}`})}</div>
      </div></details></div><div class="form-actions"><button class="button primary" type="submit">${i?'ذخیره تغییرات':'ساخت'}</button></div></form>`,{kind:'wireguard',onClose:()=>{editing=false;schedule();}});
    const f=modalBody().querySelector('[data-wg-form]');
    const adjust=()=>{const tunneled=f.elements.mode.value==='tunnel';f.querySelector('[data-wg-tunnel]').hidden=!tunneled;f.querySelector('[data-wg-link-network]').hidden=!tunneled;f.elements.ingress_server_id.required=tunneled;f.elements.mtu.max=tunneled?'1300':'1420';if(!fixed&&tunneled&&Number(f.elements.mtu.value)>1300)f.elements.mtu.value=1280;};
    const address=()=>{const id=f.elements.mode.value==='tunnel'?f.elements.ingress_server_id.value:f.elements.managed_server_id.value;f.elements.endpoint_host.value=servers.find(s=>s.id===Number(id))?.host||'';};
    const ports=()=>{if(i||fixed)return;try{
      const exit=f.elements.managed_server_id.value,entry=f.elements.ingress_server_id.value,tunneled=f.elements.mode.value==='tunnel';
      if(exit&&!listenEdited)f.elements.listen_port.value=freePort(exit,null,[f.elements.tunnel_port.value]);
      if(!tunneled){if(!endpointEdited)f.elements.endpoint_port.value=f.elements.listen_port.value;else if(!listenEdited)f.elements.listen_port.value=f.elements.endpoint_port.value;}
      else if(entry&&!endpointEdited)f.elements.endpoint_port.value=freePort(entry,null,[f.elements.tunnel_port.value]);
    }catch(err){toast(err.message,'error');}};
    f.elements.mode.onchange=()=>{adjust();address();ports();};
    f.elements.endpoint_port.oninput=()=>{endpointEdited=true;if(f.elements.mode.value==='direct'&&!fixed)f.elements.listen_port.value=f.elements.endpoint_port.value;};
    f.elements.listen_port.oninput=()=>{listenEdited=true;if(f.elements.mode.value==='direct'&&!fixed)f.elements.endpoint_port.value=f.elements.listen_port.value;};
    if(fixed&&i.mode==='direct')f.elements.endpoint_port.readOnly=true;f.elements.managed_server_id.onchange=()=>{address();ports();};f.elements.ingress_server_id.onchange=()=>{address();ports();};
    adjust();
    f.onsubmit=async ev=>{
      ev.preventDefault();const d=Object.fromEntries(new FormData(f));if(fixed){d.managed_server_id=i.managed_server_id;d.ingress_server_id=i.ingress_server_id;d.mode=i.mode;}
      d.dns=d.dns.split(',').map(s=>s.trim()).filter(Boolean);
      for(const key of ['managed_server_id','ingress_server_id','listen_port','endpoint_port','tunnel_port','mtu'])d[key]=d[key]===''||d[key]==null?null:Number(d[key]);
      if(d.mode!=='tunnel'){d.ingress_server_id=null;d.link_network=null;d.tunnel_port=null;}
      try{const r=await api('/wireguard'+(i?'/'+i.id:''),{method:i?'PUT':'POST',body:JSON.stringify(d)});toast(r.message);closeModal();await load(true);}catch(err){if(!disposed&&err.name!=='AbortError')toast(err.message,'error');}
    };
    enhanceControls?.();
  }
  return{load,redraw:render,dispose(){disposed=true;version++;clearTimeout(timer);}};
}};
