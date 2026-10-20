/* BackPack tunnel management. Uses the existing authenticated infrastructure API. */
window.SpeedboxTunnels = (() => {
  const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const clone=v=>JSON.parse(JSON.stringify(v));
  const active=o=>o&&['queued','running'].includes(o.status);
  const labels={queued:'در صف',running:'در حال اجرا',checking:'در حال بررسی',connected:'متصل',offline:'قطع',stopped:'متوقف',needs_review:'نیازمند بررسی',deleted:'حذف شده',failed:'ناموفق',succeeded:'انجام شد',cancelled:'متوقف شد',completed:'تکمیل شد',ok:'موفق',unstable:'ناپایدار',down:'ناموفق',skipped:'بررسی نشده',testing:'در حال بررسی'};
  const badge=s=>`<span class="tunnel-badge tunnel-${esc(s)}">${esc(labels[s]||'نامشخص')}</span>`;
  const bytes=v=>{if(v===undefined||v===null)return '—';let n=Number(v),i=0;const u=['B','KB','MB','GB','TB'];while(n>=1024&&i<4){n/=1024;i++;}return n.toFixed(i?1:0)+' '+u[i];};
  const numeric=(v,unit)=>v>0?`<bdi dir="ltr">${Number(v).toLocaleString('en-US',{maximumFractionDigits:1})} ${unit}</bdi>`:'—';
  const linkIcon='<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 7h16m-4-4 4 4-4 4M20 17H4m4-4-4 4 4 4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';

  const endpoint=(host,port)=>`${String(host).includes(':')&&!String(host).startsWith('[')?'['+host+']':host}:${port}`;
  const clock=d=>d.toLocaleTimeString('fa-IR',{hour:'2-digit',minute:'2-digit',hour12:false});
  const calendar=d=>d.toLocaleDateString('fa-IR');
  const timeText=stamp=>{const d=new Date(stamp*1000);return '\u2066'+calendar(d)+' — '+clock(d)+'\u2069';};
  const timeValue=stamp=>{
    if(!stamp)return '<span class="tunnel-muted">—</span>';
    const d=new Date(stamp*1000);
    return `<time class="tunnel-time-value" dir="ltr" datetime="${d.toISOString()}"><bdi dir="ltr">${clock(d)}</bdi><span aria-hidden="true">—</span><bdi dir="ltr">${calendar(d)}</bdi></time>`;
  };
  const checkedTime=stamp=>`<div class="tunnel-checked-time"><span>آخرین بررسی</span>${timeValue(stamp)}</div>`;

  // Opt-in localhost-only visual fixtures. No request ever reaches the real API.
  function previewBackend(mode){
    const stamp=()=>Math.floor(Date.now()/1000),iso=()=>new Date().toISOString();
    const servers=[{id:901,name:'نمایشی — ایران رسپینا',host:'192.0.2.10',machine_id:901},{id:902,name:'نمایشی — خارج آلمان',host:'192.0.2.20',machine_id:902},{id:903,name:'نمایشی — خارج فنلاند',host:'192.0.2.30',machine_id:903},{id:904,name:'نمایشی — ایران پیشگامان',host:'198.51.100.10',machine_id:904},{id:905,name:'نمایشی — خارج هلند',host:'198.51.100.20',machine_id:905}];
    const raw=[['reverse','wss','ok',60,42,38,32],['reverse','pck','ok',60,49,31,26],['reverse','tcp','unstable',38,74,12,9],['reverse','tcpmux','unstable',32,87,8,6],['reverse','ws','down',0,0,0,0],['reverse','quic','skipped',0,0,0,0],['direct','xdi','ok',60,57,24,20]];
    const rows=raw.map(([kind,transport,status,ok,rtt,download,upload],i)=>({id:`normal:${kind}:${transport}`,label:`${kind==='reverse'?'Reverse':'Direct'} ${transport.toUpperCase()}`,kind,transport,status,ok,total:60,rtt,download,upload,orientation:'normal',selectable:['ok','unstable'].includes(status),recommended:i===0,reason:status==='skipped'?'نمونهٔ نمایشیِ روشی که آمادهٔ آزمایش نبوده است.':''}));
    rows.push({id:'swapped:reverse:tcp',label:'Reverse TCP',kind:'reverse',transport:'tcp',status:'ok',ok:60,total:60,rtt:52,download:22,upload:18,orientation:'swapped',selectable:false,recommended:false,reason:'نمونهٔ نمایشی: ورود از خارج برای این سناریو قابل انتخاب نیست.'});
    const tests=[{id:701,iran_server_id:901,foreign_server_id:902,compare_both:true,status:'completed',results:rows,message:'نتایج نمونه برای بررسی ظاهر؛ هیچ تست شبکه‌ای اجرا نشده است.',created_at:iso()},{id:702,iran_server_id:904,foreign_server_id:905,compare_both:false,status:'failed',results:[],message:'نمونهٔ نمایشیِ تست ناموفق.',created_at:new Date(Date.now()-3600000).toISOString()}];
    const hosts=Object.fromEntries(servers.map((s,i)=>[s.id,{checked_at:stamp(),optimized:i%2===0,installed:true,compatible:true,up_to_date:i%2===0,version:i%2===0?'v1.9.0':'v1.8.4',target_version:'v1.9.0',reserved_ports_ok:true,limits_ok:true}]));
    const end=(connected=true,active=true)=>({active,checked_at:stamp(),metrics:{connected,bytes_in:1288490188,bytes_out:536870912,taken:iso()},targets:{'5790':true}});
    const make=(id,name,a,b,transport,status,port)=>({id,name,iran_server_id:a,foreign_server_id:b,iran:servers.find(s=>s.id===a),foreign:servers.find(s=>s.id===b),kind:'reverse',transport,orientation:'normal',status,message:'دادهٔ نمایشی برای بررسی ظاهر کارت و جزئیات تونل.',settings:{name,public_host:servers.find(s=>s.id===a).host,peer_host:'',tunnel_port:port,accept_udp:false,mappings:[{public_port:port+1,target_port:5790}]},remote_status:{iran:end(status==='connected',status!=='stopped'),foreign:end(status==='connected',status!=='stopped')},nodes:[{id:801,name:'نود نمایشی',host:'192.0.2.10',port:String(port+1),server_port:5790,machine_id:b}],operation:null});
    let tunnels=mode==='empty'?[]:[make(601,'نمایشی — رسپینا به آلمان',901,902,'wss','connected',8443),make(602,'نمایشی — رسپینا به فنلاند',901,903,'pck','offline',8744),make(603,'نمایشی — پیشگامان به هلند',904,905,'tcp','stopped',7435),make(604,'نمایشی — نام طولانی برای بررسی شکست متن در کارت تونل ایران و خارج',904,902,'tcpmux','needs_review',9443)];
    let next=800,operationId=9000;const operations=new Map();
    function scope(t){const ids=new Set([t.iran_server_id,t.foreign_server_id]);let change;do{change=false;for(const x of tunnels.filter(x=>x.status!=='deleted'))if(ids.has(x.iran_server_id)||ids.has(x.foreign_server_id)){const n=ids.size;ids.add(x.iran_server_id);ids.add(x.foreign_server_id);change||=ids.size!==n;}}while(change);return [...ids];}
    function operation(t,action,payload){
      const ids=scope(t),id=++operationId;
      if(action==='optimize')for(const side of payload.sides||[])hosts[side==='iran'?t.iran_server_id:t.foreign_server_id].optimized=true;
      if(action==='engine-update')for(const hostId of ids){hosts[hostId].up_to_date=true;hosts[hostId].version=hosts[hostId].target_version;}
      const result=['maintenance-status','optimize','engine-update'].includes(action)?{hosts:Object.fromEntries(ids.map(hostId=>[hostId,{name:servers.find(s=>s.id===hostId).name,status:hosts[hostId],logs:action==='maintenance-status'?[]:['DEMO ONLY — no server was contacted.','OK: simulated '+action+' output.']}]))}:{iran:{text:'DEMO ONLY — Iran log preview; no SSH session.'},foreign:{text:'DEMO ONLY — foreign log preview; no SSH session.'}};
      const op={id,action,status:'succeeded',progress:100,message:'عملیات نمایشی انجام شد؛ هیچ تغییری روی سرورها ایجاد نشده است.',result};operations.set(id,op);for(const item of tunnels)if(ids.includes(item.iran_server_id)||ids.includes(item.foreign_server_id))item.operation=op;
      if(action==='delete')t.status='deleted';if(action==='stop')t.status='stopped';if(['start','restart','status'].includes(action))t.status='connected';
      return {data:op,message:op.message};
    }
    return async(url,options={})=>{
      const method=options.method||'GET',body=options.body?JSON.parse(options.body):{};let answer;
      if(url==='/tunnels'&&method==='GET')answer={data:tunnels,tests:mode==='empty'&&tunnels.length===0?[]:tests,servers,host_status:hosts};
      else if(url==='/tunnel-tests'&&method==='POST'){
        const test={...tests[0],id:++next,...body,status:'completed',created_at:iso()};tests.unshift(test);answer={data:test,operation:{id:++operationId,status:'succeeded'},message:'نتایج نمونه بارگذاری شد؛ تست شبکه اجرا نشد.'};
      }else if(/^\/tunnels\/\d+\/tests$/.test(url)){const t=tunnels.find(x=>x.id===Number(url.split('/')[2]));answer={data:tests.filter(x=>x.iran_server_id===t.iran_server_id&&x.foreign_server_id===t.foreign_server_id&&x.status==='completed')};}
      else if(/^\/tunnel-tests\/\d+$/.test(url))answer={data:tests.find(t=>t.id===Number(url.split('/').at(-1))),operation:{status:'succeeded'}};
      else if(/^\/tunnel-operations\/\d+$/.test(url))answer={data:operations.get(Number(url.split('/').at(-1)))};
      else if(/^\/tunnels\/\d+\/actions$/.test(url)){const t=tunnels.find(t=>t.id===Number(url.split('/')[2]));if(!t)throw Error('کارت نمایشی پیدا نشد.');answer=operation(t,body.action,body);}
      else if(url==='/tunnels'&&method==='POST'){
        const test=tests.find(t=>t.id===body.test_id),row=test.results.find(r=>r.id===body.result_id);
        const t=make(++next,body.name,test.iran_server_id,test.foreign_server_id,row.transport,'connected',body.tunnel_port);t.kind=row.kind;t.orientation=row.orientation;t.settings={...body};tunnels.unshift(t);answer={data:t,message:'کارت نمایشی ساخته شد؛ تونلی روی سرورها ساخته نشده است.'};
      }else if(/^\/tunnels\/\d+$/.test(url)&&method==='PUT'){const t=tunnels.find(t=>t.id===Number(url.split('/').at(-1)));t.name=body.name;t.settings={...body};if(body.test_id){const test=tests.find(x=>x.id===body.test_id),row=test.results.find(x=>x.id===body.result_id);t.kind=row.kind;t.transport=row.transport;t.orientation=row.orientation;}answer={data:t,message:'ویرایش نمایشی ذخیره شد؛ تنظیمات سرورها تغییر نکرده است.'};}
      else throw Error('این عملیات در پیش‌نمایش محلی پشتیبانی نمی‌شود.');
      return clone(answer);
    };
  }

  function mount(content,{api,toast,openModal,closeModal,modalBody,navigate,openNode,enhanceControls=()=>{}}) {
    const previewValue=new URLSearchParams(location.search).get('tunnel_preview');
    const preview=['localhost','127.0.0.1','[::1]'].includes(location.hostname)&&['1','full','empty'].includes(previewValue);
    if(preview){api=previewBackend(previewValue);openNode=async()=>toast('در پیش‌نمایش محلی، ساخت و ویرایش نود واقعی اجرا نمی‌شود.','info');}
    let disposed=false,timer=null,busy=false,version=0,refreshTimer=null;
    let data={data:[],servers:[],tests:[]},test=null,testOperation=null,editing=null,selection=null;
    let switching=false,selectedTestId=null;
    let wizard=false,expanded=false,filter='all',draft=null,logOperation=null,maintenance=null;
    const wizardBox=()=>modalBody().querySelector('[data-tunnel-wizard]');
    const modalOptions=()=>({kind:'tunnel',onClose:()=>{switching=false;selectedTestId=null;wizard=false;editing=null;draft=null;selection=null;maintenance=null;}});
    const safe=fn=>async e=>{e?.preventDefault?.();if(busy||disposed)return;busy=true;content.setAttribute('aria-busy','true');try{await fn(e);}catch(error){if(!disposed&&error.name!=='AbortError')toast(error.message,'error');}finally{busy=false;content.removeAttribute('aria-busy');}};
    const serverName=id=>data.servers.find(s=>s.id===Number(id))?.name||'سرور حذف‌شده';
    const summary=()=>`${data.data.filter(t=>t.status!=='deleted').length.toLocaleString('fa-IR')} تونل`;
    function shell() {
      content.innerHTML=`<div class="tunnel-workspace"><div class="tunnel-toolbar"><div><h2>تونل‌های شما <small data-tunnel-count></small></h2><p>اتصال بین سرور ایران و خارج با <bdi>BackPack</bdi></p></div><div class="tunnel-toolbar-actions" data-tunnel-toolbar-actions><button type="button" class="button secondary tunnel-refresh" data-reload-tunnels title="تازه‌سازی" aria-label="تازه‌سازی"><i class="fas fa-sync-alt" aria-hidden="true"></i></button><button type="button" class="btn-create-user tunnel-create" dir="ltr" data-new-tunnel><span>ایجاد تونل</span><i class="fas fa-plus" aria-hidden="true"></i></button></div></div><div data-tunnel-list class="tunnel-grid"></div><details class="tunnel-history" hidden><summary>بررسی‌های اخیر اتصال <small data-history-count></small></summary><div data-tunnel-history></div></details></div>`;
      if(preview){
        const banner=document.createElement('section');banner.className='panel-card tunnel-preview-banner';
        banner.innerHTML='<p>پیش‌نمایش محلی — داده‌های این صفحه نمایشی‌اند؛ هیچ درخواست عملیاتی به سرورها ارسال نمی‌شود.</p><div class="actions"><a class="button secondary" href="?tunnel_preview=empty">فرم و صفحهٔ خالی</a><a class="button secondary" href="?tunnel_preview=full">کارت‌ها و نتایج نمونه</a><a class="button cancel" href="'+esc(location.pathname)+'">خروج از پیش‌نمایش</a></div>';
        content.querySelector('.tunnel-workspace').prepend(banner);
      }
      content.querySelector('[data-new-tunnel]').onclick=safe(async()=>{wizard=true;editing=null;selection=null;draft=null;renderWizard();});
      content.querySelector('[data-reload-tunnels]').onclick=safe(refresh);
    }
    function refreshIcon(button,state){
      button.classList.toggle('is-refreshed',state==='success');
      button.querySelector('i').className=state==='success'?'fas fa-check':state==='loading'?'fas fa-sync-alt fa-spin':'fas fa-sync-alt';
      const label=state==='success'?'تازه‌سازی شد':state==='loading'?'در حال تازه‌سازی':'تازه‌سازی';
      button.title=label;button.setAttribute('aria-label',label);
    }
    async function refresh(event){
      const button=event.currentTarget;clearTimeout(refreshTimer);button.disabled=true;refreshIcon(button,'loading');
      try{
        const loaded=await load();if(disposed||loaded===false)return;
        refreshIcon(button,'success');
        refreshTimer=setTimeout(()=>{if(!disposed&&button.isConnected)refreshIcon(button,'idle');},1600);
      }catch(error){if(!disposed&&button.isConnected)refreshIcon(button,'idle');throw error;}
      finally{button.disabled=false;}
    }
    function syncLayout(){
      const live=data.data.some(t=>t.status!=='deleted');
      content.querySelector('.tunnel-history').hidden=!data.tests.length;
      const button=content.querySelector('[data-new-tunnel]');
      const destination=!live?content.querySelector('[data-empty-action]'):null;
      (destination||content.querySelector('[data-tunnel-toolbar-actions]')).append(button);
    }
    async function load() {
      const request=++version;const answer=await api('/tunnels');if(disposed||request!==version)return false;
      data=answer;
      if(!content.querySelector('.tunnel-workspace'))shell();
      renderCards();renderHistory();
      if(!wizard)renderWizard();
      if(test){await pollTest(false);}
      schedule();return true;
    }
    function schedule(){clearTimeout(timer);if(disposed)return;timer=setTimeout(async()=>{if(disposed)return;try{if(!busy){if(test&&active(testOperation))await pollTest(true);const pending=data.data.some(t=>active(t.operation));if(pending)await refreshCards();}}catch(e){if(e.name!=='AbortError')toast('دریافت وضعیت کامل نشد؛ دوباره تلاش می‌کنیم.','error');}schedule();},4000);}
    async function refreshCards(){const answer=await api('/tunnels');if(disposed)return;data=answer;renderCards();renderHistory();if(logOperation){const op=data.data.find(t=>t.operation?.id===logOperation)?.operation;if(op&&!active(op)){logOperation=null;showLogs(op);}}if(maintenance)await refreshMaintenance();}
    async function pollTest(render=true){if(!test)return;const id=test.id;const answer=await api(`/tunnel-tests/${id}`);if(disposed||test?.id!==id)return;test=answer.data;testOperation=answer.operation;data.tests=[test,...data.tests.filter(t=>t.id!==test.id)].slice(0,10);if(render&&wizard&&(!draft||switching))renderWizard();}
    function renderCards(){
      content.querySelector('[data-tunnel-count]').textContent=summary();
      const live=data.data.filter(t=>t.status!=='deleted');
      // The create button moves between the toolbar and empty state; keep its handler.
      content.querySelector('[data-tunnel-toolbar-actions]').append(content.querySelector('[data-new-tunnel]'));
      content.querySelector('[data-tunnel-count]').hidden=!live.length;
      content.querySelector('[data-tunnel-list]').innerHTML=live.length?live.map(t=>{
        const pending=active(t.operation),s=t.settings;
        return `<article class="panel-card tunnel-card" data-tunnel-card="${t.id}"><header><div><h3>${esc(t.name)}</h3><span class="tunnel-method" dir="ltr">${esc(t.kind==='reverse'?'Reverse':'Direct')} ${esc(t.transport.toUpperCase())}</span></div>${badge(pending?'running':t.status)}</header><div class="tunnel-pair"><div><span>سرور ایران</span><b>${esc(t.iran?.name)}</b></div><span class="tunnel-link ${t.status==='connected'?'is-connected':['offline','failed','stopped'].includes(t.status)?'is-disconnected':'is-pending'}" role="img" aria-label="${esc(labels[t.status]||'نیازمند بررسی')}">${linkIcon}</span><div><span>سرور خارج</span><b>${esc(t.foreign?.name)}</b></div></div><div class="tunnel-address"><span>آدرس اتصال کاربران</span><div class="tunnel-endpoints">${s.mappings.map(m=>`<div class="tunnel-endpoint"><bdi dir="ltr">${esc(endpoint(s.public_host,m.public_port))}</bdi><small>پورت <bdi dir="ltr">${m.target_port}</bdi></small></div>`).join('')}</div></div><p class="tunnel-card-message">${esc(pending?t.operation.message:t.message||'برای دریافت وضعیت، تست اتصال را اجرا کنید.')}</p>${pending?`<progress max="100" value="${Number(t.operation.progress)||0}" aria-label="پیشرفت عملیات"></progress>`:''}<footer><button type="button" class="button secondary tunnel-detail-button" data-tunnel-detail="${t.id}">جزئیات</button><details class="tunnel-actions"><summary class="button secondary tunnel-actions-button">عملیات</summary><div role="group" aria-label="عملیات تونل">${[['status','تست اتصال'],['start','شروع'],['stop','توقف'],['restart','راه‌اندازی مجدد'],['logs','گزارش دو سرور'],['optimize','بهینه‌سازی'],['engine-update','به‌روزرسانی'],['edit','ویرایش'],['delete','حذف تونل']].map(([action,label])=>`<button type="button" data-tunnel-action="${action}" data-id="${t.id}" ${pending?'disabled':''}>${label}</button>`).join('')}</div></details></footer></article>`;
      }).join(''):`<div class="panel-card tunnel-empty"><div class="tunnel-empty-symbol" aria-hidden="true"><span>ایران</span><svg viewBox="0 0 48 32" fill="none"><path d="M7 10h33m-7-6 7 6-7 6M41 23H8m7-6-7 6 7 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg><span>خارج</span></div><h3>هنوز تونلی ساخته نشده است</h3><p>برای شروع، سرور ایران و خارج را انتخاب کنید و روش‌های اتصال را بررسی کنید.</p><div data-empty-action></div><small>هر دو سرور باید در بخش «مدیریت سرورها» اضافه شده باشند.</small></div>`;
      syncLayout();
      content.querySelectorAll('[data-tunnel-detail]').forEach(b=>b.onclick=()=>details(data.data.find(t=>t.id===Number(b.dataset.tunnelDetail))));
      content.querySelectorAll('[data-tunnel-action]').forEach(b=>b.onclick=safe(async()=>{
        const t=data.data.find(t=>t.id===Number(b.dataset.id));
        b.closest('details').open=false;
        if(['optimize','engine-update'].includes(b.dataset.tunnelAction)){await openMaintenance(t,b.dataset.tunnelAction);return;}
        if(b.dataset.tunnelAction==='edit'){editing=t;switching=false;selectedTestId=null;test=null;testOperation=null;selection=null;draft=clone(t.settings);draft.name=t.name;wizard=true;renderWizard();return;}
        if(b.dataset.tunnelAction==='delete'){confirmDelete(t);return;}
        await operation(t,b.dataset.tunnelAction);
      }));
    }
    function renderHistory(){
      content.querySelector('[data-history-count]').textContent=data.tests.length.toLocaleString('fa-IR');
      content.querySelector('[data-tunnel-history]').innerHTML=data.tests.length?data.tests.map(t=>`<button type="button" class="tunnel-history-row" data-history="${t.id}"><span>${esc(serverName(t.iran_server_id))} / ${esc(serverName(t.foreign_server_id))}</span>${badge(t.status)}<small>${timeValue(new Date(t.created_at).getTime()/1000)}</small></button>`).join(''):'<p class="tunnel-muted">هنوز بررسی اتصالی انجام نشده است.</p>';
      content.querySelectorAll('[data-history]').forEach(b=>b.onclick=safe(async()=>{test=data.tests.find(t=>t.id===Number(b.dataset.history));selection=null;editing=null;draft=null;wizard=true;await pollTest(false);renderWizard();}));
      syncLayout();
    }
    function renderWizard(){
      if(!wizard)return;
      if(switching){renderMethodPicker();return;}
      if(draft){renderSetup();return;}
      const pending=active(testOperation),hasResults=test?.status==='completed'&&test.results?.length>0;
      openModal('ایجاد تونل',`<form data-test-form data-tunnel-wizard class="tunnel-builder ${hasResults?'has-results':''}">${hasResults?`<div class="tunnel-test-context"><span>${esc(serverName(test.iran_server_id))} / ${esc(serverName(test.foreign_server_id))}</span><button type="button" class="button secondary" data-test-settings aria-expanded="false">ویرایش سرورها</button></div>`:''}<p class="tunnel-help">برای ساخت تونل، ابتدا هر دو سرور ایران و خارج را در بخش «مدیریت سرورها» اضافه کنید.</p>${data.servers.length<2?`<div class="empty"><strong>برای ادامه، دو سرور متفاوت نیاز دارید.</strong><button type="button" class="button primary" data-go-servers>رفتن به مدیریت سرورها</button></div>`:`<div class="form-grid"><div class="field"><label for="tunnel-iran">سرور ایران</label><select class="form-input" id="tunnel-iran" name="iran_server_id" required ${pending?'disabled':''}><option value="">انتخاب کنید</option>${serverOptions(test?.iran_server_id)}</select></div><div class="field"><label for="tunnel-foreign">سرور خارج</label><select class="form-input" id="tunnel-foreign" name="foreign_server_id" required ${pending?'disabled':''}><option value="">انتخاب کنید</option>${serverOptions(test?.foreign_server_id)}</select></div><div class="switch-field full"><span>بررسی حالت برعکس، حتی اگر حالت معمول موفق بود</span><label class="switch-line ios"><input type="checkbox" name="compare_both" ${test?.compare_both?'checked':''} ${pending?'disabled':''} aria-label="بررسی هر دو حالت"></label></div></div><p class="tunnel-muted tunnel-test-note"><span>اگر حالت معمول جواب ندهد، حالت برعکس خودکار بررسی می‌شود.</span></p>`}<div data-test-results></div><div class="form-actions">${data.servers.length>=2?`<button class="button primary" type="submit" ${pending?'disabled':''}>${pending?'در حال بررسی…':hasResults?'بررسی دوباره':'بررسی روش‌های اتصال'}</button>`:''}<button type="button" class="button cancel" data-close>انصراف</button></div></form>`,modalOptions());
      const form=wizardBox();
      form.querySelector('[data-test-settings]')?.addEventListener('click',event=>{const show=form.classList.toggle('show-test-settings');event.currentTarget.setAttribute('aria-expanded',String(show));event.currentTarget.textContent=show?'بستن تنظیمات':'ویرایش سرورها';});
      form.querySelector('[data-go-servers]')?.addEventListener('click',()=>{closeModal();navigate('servers');});
      form.onsubmit=safe(async()=>{
        if(data.servers.length<2||!form.reportValidity())return;
        const payload={iran_server_id:Number(form.elements.iran_server_id.value),foreign_server_id:Number(form.elements.foreign_server_id.value),compare_both:form.elements.compare_both.checked};
        if(payload.iran_server_id===payload.foreign_server_id)throw Error('سرور ایران و خارج باید متفاوت باشند.');
        const answer=await api('/tunnel-tests',{method:'POST',body:JSON.stringify(payload)});if(disposed)return;test=answer.data;testOperation=answer.operation;toast(answer.message);renderWizard();schedule();
      });
      renderResults();enhanceControls(modalBody());
    }
    function serverOptions(selected){return data.servers.map(s=>`<option value="${s.id}" ${s.id===Number(selected)?'selected':''}>${esc(s.name)} — ${esc(s.host)}</option>`).join('');}
    function renderResults(){
      const box=wizardBox()?.querySelector('[data-test-results]');if(!box||!test)return;
      const rows=test.results||[],pending=active(testOperation),recommended=rows.find(r=>r.recommended);
      const stale=editing&&(!test.updated_at&&!test.created_at||Date.now()-new Date(test.updated_at||test.created_at).getTime()>=86400000);
      const shown=rows.filter(r=>filter==='all'||r.status===filter);
      box.innerHTML=`<div class="tunnel-results"><div class="tunnel-result-heading"><div><h3>نتیجهٔ بررسی اتصال</h3><p>${esc(testOperation?.message||test.message||'در انتظار شروع تست…')}</p></div>${badge(test.status)}</div>${pending?`<div class="tunnel-test-progress"><progress value="${Number(testOperation.progress)||0}" max="100" aria-label="پیشرفت بررسی اتصال"></progress><button type="button" class="button secondary" data-cancel-test ${testOperation.cancel_requested?'disabled':''}>توقف بررسی</button></div>`:''}${recommended?`<div class="tunnel-recommendation"><span>روش پیشنهادی</span><b dir="ltr">${esc(recommended.label)}</b><small>می‌توانید هر روش موفق دیگری را هم انتخاب کنید.</small></div>`:''}${rows.length?`<div class="tunnel-result-filters">${[['all','همهٔ روش‌ها'],['ok','موفق'],['unstable','ناپایدار']].map(([v,l])=>`<button type="button" class="button secondary ${filter===v?'is-selected':''}" data-result-filter="${v}">${l}</button>`).join('')}</div><div class="tunnel-result-list" role="list" aria-label="نتایج روش‌های اتصال">${shown.map(r=>`<article class="tunnel-result-row ${r.recommended?'is-recommended':''}" role="listitem"><div class="tunnel-result-method"><b dir="ltr">${esc(r.label)}</b><div>${badge(r.status)}${r.recommended?'<span class="tunnel-pick">پیشنهادی</span>':''}<span class="tunnel-orientation">${r.orientation==='swapped'?'برعکس':'معمول'}</span></div></div><dl class="tunnel-result-metrics"><div><dt>پاسخ موفق</dt><dd><bdi dir="ltr">${r.ok} / ${r.total}</bdi></dd></div><div><dt>تأخیر</dt><dd>${numeric(r.rtt,'ms')}</dd></div><div><dt>دریافت</dt><dd>${numeric(r.download,'Mbps')}</dd></div><div><dt>ارسال</dt><dd>${numeric(r.upload,'Mbps')}</dd></div></dl><button type="button" class="button ${r.recommended?'primary':'secondary'}" data-select-result="${esc(r.id)}" ${!r.selectable||pending||stale||test.status!=='completed'?'disabled':''}>${editing?'انتخاب این روش':'ساخت با این روش'}</button>${r.reason?`<p class="tunnel-row-note">${esc(r.reason)}</p>`:''}</article>`).join('')}</div><p class="tunnel-muted">این نتایج مربوط به زمان تست هستند؛ کیفیت اتصال ممکن است تغییر کند.</p>`:pending?'<p class="tunnel-muted">آماده‌سازی و بررسی مسیر ممکن است چند دقیقه طول بکشد. بستن صفحه، تست را متوقف نمی‌کند.</p>':'<p class="tunnel-muted">نتیجه‌ای برای نمایش وجود ندارد؛ بررسی اتصال را دوباره اجرا کنید.</p>'}</div>`;
      box.querySelector('[data-cancel-test]')?.addEventListener('click',safe(async()=>{await api(`/tunnel-operations/${testOperation.id}/cancel`,{method:'POST',body:'{}'});await pollTest();}));
      box.querySelectorAll('[data-result-filter]').forEach(b=>b.onclick=()=>{filter=b.dataset.resultFilter;renderResults();});
      box.querySelectorAll('[data-select-result]').forEach(b=>b.onclick=()=>{
        selection=rows.find(r=>r.id===b.dataset.selectResult);
        if(editing&&switching){selectedTestId=test.id;draft.accept_unstable=false;switching=false;renderWizard();return;}
        editing=null;
        draft={name:`${serverName(test.iran_server_id)} — ${serverName(test.foreign_server_id)}`,public_host:data.servers.find(s=>s.id===test.iran_server_id)?.host||'',tunnel_port:8000,accept_udp:selection.kind==='reverse'&&selection.transport==='udp',peer_host:'',mappings:[{public_port:443,target_port:443}],accept_unstable:false};
        renderWizard();
      });
    }
    function renderMethodPicker(){
      const pending=active(testOperation),history=data.tests.filter(t=>Number(t.iran_server_id)===Number(editing.iran_server_id)&&Number(t.foreign_server_id)===Number(editing.foreign_server_id)&&t.status==='completed');
      openModal('تغییر روش اتصال',`<form data-method-picker data-tunnel-wizard class="${test?'has-results':''}"><div class="tunnel-method-intro"><p>${esc(editing.iran?.name||serverName(editing.iran_server_id))} / ${esc(editing.foreign?.name||serverName(editing.foreign_server_id))}</p><p class="tunnel-muted">روش فعلی: <b dir="ltr">${esc(editing.kind==='reverse'?'Reverse':'Direct')} ${esc(editing.transport.toUpperCase())}</b> — نتیجهٔ همین دو سرور را انتخاب کنید یا تست تازه بگیرید. نتیجه باید مربوط به ۲۴ ساعت اخیر باشد.</p></div>${history.length?`<div class="field tunnel-method-history"><label for="tunnel-method-history">بررسی‌های همین دو سرور</label><select id="tunnel-method-history" ${pending?'disabled':''}><option value="">انتخاب نتیجهٔ قبلی</option>${history.map(t=>`<option value="${t.id}" ${t.id===test?.id?'selected':''}>${esc(timeText(new Date(t.updated_at||t.created_at).getTime()/1000))}</option>`).join('')}</select></div>`:''}${!test?'<p class="tunnel-muted">هنوز نتیجه‌ای انتخاب نشده است. برای این دو سرور تست بگیرید.</p>':''}<p class="tunnel-muted">با انتخاب روش، نام تونل و پورت‌های فعلی حفظ می‌شوند. در مرحلهٔ بعد می‌توانید تنظیمات و پشتیبانی UDP را بررسی کنید.</p><div data-test-results></div><div class="form-actions"><button type="button" class="button secondary" data-method-retest ${pending?'disabled':''}>${pending?'در حال بررسی…':'تست دوبارهٔ همین دو سرور'}</button><button type="button" class="button secondary" data-return-edit ${pending?'disabled':''}>بازگشت به ویرایش</button><button type="button" class="button cancel" data-close>انصراف</button></div></form>`,modalOptions());
      const body=wizardBox();body.onsubmit=e=>e.preventDefault();
      body.querySelector('[data-return-edit]').onclick=()=>{switching=false;renderWizard();};
      body.querySelector('#tunnel-method-history')?.addEventListener('change',safe(async e=>{
        const previous=test;test=data.tests.find(t=>t.id===Number(e.target.value))||null;testOperation=null;
        try{if(test)await pollTest(false);}catch(error){test=previous;throw error;}
        if(switching)renderWizard();
      }));
      body.querySelector('[data-method-retest]').onclick=safe(async()=>{
        const answer=await api('/tunnel-tests',{method:'POST',body:JSON.stringify({iran_server_id:Number(editing.iran_server_id),foreign_server_id:Number(editing.foreign_server_id),compare_both:true})});
        if(disposed||!switching)return;test=answer.data;testOperation=answer.operation;data.tests=[test,...data.tests.filter(t=>t.id!==test.id)].slice(0,10);renderWizard();schedule();
      });
      renderResults();enhanceControls(modalBody());
    }
    function field(name,label,value,{number=false,hint=''}={}){return `<div class="field"><label for="bp-${name}">${label}</label><input id="bp-${name}" name="${name}" value="${esc(value)}" class="form-input" type="${number?'number':'text'}" ${number?'min="1" max="65535"':''} required dir="${name==='name'?'auto':'ltr'}">${hint?`<small>${hint}</small>`:''}</div>`;}
    function renderSetup(){
      openModal(editing?'ویرایش تونل':'ایجاد تونل',`<form data-tunnel-setup data-tunnel-wizard class="tunnel-builder"><div class="tunnel-selected-method"><div class="tunnel-selected-copy"><b dir="ltr">${esc(selection?.label||(editing.kind==='reverse'?'Reverse ':'Direct ')+editing.transport.toUpperCase())}</b><span>${esc(serverName(editing?.iran_server_id||test?.iran_server_id))} / ${esc(serverName(editing?.foreign_server_id||test?.foreign_server_id))}</span></div>${editing?'<button type="button" class="button secondary" data-change-method>تغییر روش اتصال</button>':''}${!editing?'<button type="button" class="button secondary tunnel-back-results" data-back-results title="بازگشت به نتایج" aria-label="بازگشت به نتایج"><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M5 12h14m-6-6 6 6-6 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></button>':''}</div>${editing&&selection?`<p class="tunnel-warning tunnel-method-warning">روش جدید: <b dir="ltr">${esc(selection.label)}</b>${selection.orientation==='swapped'?' — حالت برعکس':''}. با ذخیره، ارتباط موقتاً قطع می‌شود؛ روش جدید پس از تأیید اتصال ثبت خواهد شد.</p>`:''}<div class="form-grid">${field('name','نام تونل',draft.name)}${field('public_host','آدرس اتصال کاربران',draft.public_host,{hint:'IP یا دامنهٔ سرور ایران؛ مانند ir.example.com'})}${field('tunnel_port','پورت ارتباط بین دو سرور',draft.tunnel_port,{number:true,hint:'این پورت برای ارتباط خود تونل است؛ با پورت کاربران متفاوت باشد.'})}</div><div class="tunnel-mapping-heading"><h4>پورت‌های اتصال</h4><button type="button" class="button secondary" data-add-mapping>افزودن پورت</button></div><div data-mapping-list></div><div class="switch-field"><span>ترافیک UDP هم منتقل شود</span><label class="switch-line ios"><input name="accept_udp" type="checkbox" ${draft.accept_udp?'checked':''} ${(selection?.kind||editing?.kind)==='reverse'&&(selection?.transport||editing?.transport)==='udp'?'required':''} aria-label="انتقال ترافیک UDP"></label></div><p class="tunnel-muted">برای نودهایی مانند Hysteria و TUIC، پشتیبانی UDP لازم است.${(selection?.kind||editing?.kind)==='reverse'&&(selection?.transport||editing?.transport)==='udp'?' روش Reverse UDP فقط ترافیک UDP را منتقل می‌کند.':''}</p><details class="tunnel-advanced" ${expanded?'open':''}><summary>تنظیمات پیشرفته</summary><div class="field"><label for="bp-peer_host">آدرس ارتباط بین دو سرور</label><input class="form-input" id="bp-peer_host" name="peer_host" dir="ltr" value="${esc(draft.peer_host)}" placeholder="خودکار از اطلاعات سرور"><small>اگر این ارتباط باید با دامنه یا IP دیگری برقرار شود، اینجا وارد کنید.</small></div></details>${selection?.status==='unstable'?`<label class="tunnel-check tunnel-warning"><input name="accept_unstable" type="checkbox" required ${draft.accept_unstable?'checked':''}>این روش در تست ناپایدار بود؛ با اطلاع از نتیجه انتخابش می‌کنم.</label>`:''}<div class="form-actions"><button class="button primary" type="submit">${editing?'ذخیره و اعمال تغییرات':'ساخت تونل'}</button><button type="button" class="button cancel" data-close>انصراف</button></div></form>`,modalOptions());

      const form=wizardBox();
      const capture=()=>{for(const name of ['name','public_host','peer_host'])draft[name]=form.elements[name].value;draft.tunnel_port=Number(form.elements.tunnel_port.value);draft.accept_udp=form.elements.accept_udp.checked;if(form.elements.accept_unstable)draft.accept_unstable=form.elements.accept_unstable.checked;expanded=form.querySelector('details').open;};
      form.querySelector('[data-change-method]')?.addEventListener('click',safe(async()=>{
        capture();switching=true;const tunnelId=editing.id;renderWizard();
        const answer=await api(`/tunnels/${tunnelId}/tests`);
        if(disposed||!switching||editing?.id!==tunnelId)return;
        data.tests=[...answer.data,...data.tests.filter(t=>!answer.data.some(x=>x.id===t.id))];
        const recent=answer.data[0];
        if(!test||Number(test.iran_server_id)!==Number(editing.iran_server_id)||Number(test.foreign_server_id)!==Number(editing.foreign_server_id)){test=recent||null;testOperation=null;}
        renderWizard();
        if(test){await pollTest(false);if(switching)renderWizard();}
      }));
      form.addEventListener('input',capture);form.addEventListener('change',capture);
      form.querySelector('[data-back-results]')?.addEventListener('click',()=>{editing=null;draft=null;selection=null;renderWizard();});
      form.querySelector('[data-add-mapping]').onclick=()=>{capture();if(draft.mappings.length>=20){toast('حداکثر ۲۰ پورت می‌توانید اضافه کنید.','error');return;}draft.mappings.push({public_port:0,target_port:0});renderMappings();};
      function renderMappings(){form.querySelector('[data-mapping-list]').innerHTML=draft.mappings.map((m,i)=>`<div class="tunnel-mapping-row"><div class="field"><label for="bp-public-${i}">پورت اتصال کاربران روی ایران</label><input class="form-input" id="bp-public-${i}" type="number" min="1" max="65535" value="${m.public_port||''}" data-map="public_port" data-index="${i}" required dir="ltr"></div><span class="tunnel-map-arrow" aria-hidden="true">←</span><div class="field"><label for="bp-target-${i}">پورت نود روی سرور خارج</label><input class="form-input" id="bp-target-${i}" type="number" min="1" max="65535" value="${m.target_port||''}" data-map="target_port" data-index="${i}" required dir="ltr"></div><button type="button" class="button danger" data-remove-map="${i}" ${draft.mappings.length===1?'disabled':''} aria-label="حذف این پورت">حذف</button></div>`).join('');form.querySelectorAll('[data-map]').forEach(input=>input.oninput=()=>draft.mappings[Number(input.dataset.index)][input.dataset.map]=Number(input.value));form.querySelectorAll('[data-remove-map]').forEach(b=>b.onclick=()=>{draft.mappings.splice(Number(b.dataset.removeMap),1);renderMappings();});}
      renderMappings();enhanceControls(modalBody());
      form.onsubmit=safe(async()=>{if(!form.reportValidity())return;capture();const payload={...draft,...(!editing?{test_id:test.id,result_id:selection.id}:selection?{test_id:selectedTestId,result_id:selection.id}:{})};const answer=await api(editing?`/tunnels/${editing.id}`:'/tunnels',{method:editing?'PUT':'POST',body:JSON.stringify(payload)});if(disposed)return;toast(answer.message);closeModal();editing=null;draft=null;selection=null;wizard=false;await refreshCards();});
    }
    function maintenanceScope(t){
      const ids=new Set([Number(t.iran_server_id||t.iran?.id),Number(t.foreign_server_id||t.foreign?.id)]);
      let changed;
      do{changed=false;for(const item of data.data.filter(x=>x.status!=='deleted')){
        const a=Number(item.iran_server_id||item.iran?.id),b=Number(item.foreign_server_id||item.foreign?.id);
        if(ids.has(a)||ids.has(b)){if(!ids.has(a)||!ids.has(b))changed=true;ids.add(a);ids.add(b);}
      }}while(changed);
      return [...ids].sort((a,b)=>a-b);
    }
    async function openMaintenance(t,action){
      maintenance={t,action,ids:maintenanceScope(t),selected:['iran','foreign'],operation:null,checking:true,result:null};
      renderMaintenance();
      await requestMaintenanceStatus();
    }
    async function requestMaintenanceStatus(){
      const current=maintenance;if(!current)return;
      current.checking=true;renderMaintenance();
      try{
        const answer=await api(`/tunnels/${current.t.id}/actions`,{method:'POST',body:JSON.stringify({action:'maintenance-status'})});
        if(disposed||maintenance!==current)return;
        current.operation={...answer.data,status:'queued'};await refreshCards();schedule();
      }catch(error){if(maintenance===current){current.checking=false;current.error=error.message;renderMaintenance();}throw error;}
    }
    async function refreshMaintenance(){
      const current=maintenance;if(!current?.operation||!active(current.operation))return;
      if(!modalBody().querySelector('[data-maintenance-form]')){maintenance=null;return;}
      const answer=await api(`/tunnel-operations/${current.operation.id}`);
      if(disposed||maintenance!==current)return;
      current.operation=answer.data;
      if(!active(current.operation)){
        current.checking=false;current.result=current.operation.result||{};
        for(const item of data.data)if(item.operation?.id===current.operation.id)item.operation=current.operation;
        renderCards();
        for(const [id,host] of Object.entries(current.result.hosts||{}))if(host.status)data.host_status={...data.host_status,[id]:host.status};
        if(current.operation.action!=='maintenance-status')toast(current.operation.message,current.operation.status==='succeeded'?'success':'error');
      }
      renderMaintenance();
    }
    function renderMaintenance(){
      const current=maintenance;if(!current||disposed)return;
      const optimize=current.action==='optimize',pending=active(current.operation)||current.checking;
      const allIds=optimize?[Number(current.t.iran_server_id||current.t.iran?.id),Number(current.t.foreign_server_id||current.t.foreign?.id)]:current.ids;
      const states=allIds.map(id=>({id,server:data.servers.find(s=>Number(s.id)===id),status:data.host_status?.[id]}));
      const unknown=states.some((x,index)=>(!optimize||current.selected.includes(index===0?'iran':'foreign'))&&(!x.status||x.status.error));
      const needsUpdate=states.some(x=>x.status&&!x.status.up_to_date);
      const statusText=s=>!s||s.error?'بررسی نشده':optimize?(s.optimized?'بهینه‌سازی شده':'نیاز به بهینه‌سازی'):(s.up_to_date?'به‌روز':'نیازمند به‌روزرسانی');
      openModal(optimize?'بهینه‌سازی سرورهای تونل':'به‌روزرسانی موتور تونل',`<form data-maintenance-form><div class="tunnel-maintenance-intro"><p>${optimize?'سرورهای موردنظر را برای بهینه‌سازی انتخاب کنید.':'نسخهٔ موتور روی سرورهای وابسته بررسی و هماهنگ به‌روزرسانی می‌شود.'}</p><p class="tunnel-muted">${optimize?'این عملیات تنظیمات عمومی سرور را تغییر می‌دهد و روی همهٔ تونل‌ها و سرویس‌های آن سرور اثر دارد.':'به‌روزرسانی می‌تواند ارتباط تونل‌های این سرورها را موقتاً قطع کند. فقط موتور سازگارِ همراه پنل نصب می‌شود.'}</p></div><div class="tunnel-host-list">${states.map(({id,server,status},index)=>`<section class="tunnel-host-row"><div class="tunnel-host-heading">${optimize?`<label class="tunnel-host-choice"><input type="checkbox" name="side" value="${index===0?'iran':'foreign'}" ${current.selected.includes(index===0?'iran':'foreign')?'checked':''} ${pending?'disabled':''}><span>${index===0?'سرور ایران':'سرور خارج'}</span></label>`:`<span>${id===Number(current.t.iran_server_id||current.t.iran?.id)?'سرور ایران':id===Number(current.t.foreign_server_id||current.t.foreign?.id)?'سرور خارج':'سرور وابسته'}</span>`}<strong>${esc(server?.name||'سرور')}</strong><span class="badge ${status&&(optimize?status.optimized:status.up_to_date)&&!status.error?'online':'degraded'}">${statusText(status)}</span></div>${!optimize?`<dl class="tunnel-version-pair"><dt>نسخهٔ نصب‌شده</dt><dd><bdi dir="ltr">${esc(status?.version||'نامشخص')}</bdi></dd><dt>نسخهٔ قابل نصب</dt><dd><bdi dir="ltr">${esc(status?.target_version||'—')}</bdi></dd></dl>`:''}<div class="tunnel-host-checked">${checkedTime(status?.checked_at)}</div>${status?.error?`<p class="tunnel-error">${esc(status.error)}</p>`:''}</section>`).join('')}</div>${!optimize&&states.length>2?`<p class="tunnel-muted tunnel-maintenance-note">این سرورها بین چند تونل مشترک‌اند؛ هر ${states.length.toLocaleString('fa-IR')} سرور وابسته با هم بررسی می‌شوند.</p>`:''}${pending?`<div class="tunnel-maintenance-progress" role="status"><span>${esc(current.operation?.message||'در حال دریافت وضعیت سرورها…')}</span><progress max="100" value="${Number(current.operation?.progress)||0}"></progress><small>خروج از این پنجره، عملیات را متوقف نمی‌کند.</small></div>`:''}${!pending&&current.operation?`<p class="tunnel-operation-result" role="status">${esc(current.operation.message)}</p>`:''}${current.error||current.result?.error?`<p class="tunnel-error" role="alert">${esc(current.error||current.result.error)}</p>`:''}${current.result?.hosts?`<div class="tunnel-maintenance-reports">${Object.values(current.result.hosts).filter(host=>host.logs?.length||host.error||host.rollback_error).map(host=>`<details><summary>گزارش ${esc(host.name)}</summary><pre class="log-box" dir="ltr">${esc([...(host.logs||[]),host.error,host.rollback_error].filter(Boolean).join('\n'))}</pre></details>`).join('')}</div>`:''}<div class="form-actions"><button type="submit" class="button primary" ${pending||unknown||(!optimize&&!needsUpdate)?'disabled':''}>${optimize?'بهینه‌سازی':'به‌روزرسانی'}</button><button type="button" class="button secondary" data-maintenance-refresh ${pending?'disabled':''}>بررسی دوباره</button><button type="button" class="button cancel" data-close>انصراف</button></div></form>`,{kind:'tunnel',onClose:()=>{maintenance=null;}});
      const form=modalBody().querySelector('[data-maintenance-form]');
      form.querySelectorAll('[name=side]').forEach(input=>input.onchange=()=>{current.selected=[...form.querySelectorAll('[name=side]:checked')].map(x=>x.value);renderMaintenance();});
      form.querySelector('[data-maintenance-refresh]').onclick=safe(requestMaintenanceStatus);
      form.onsubmit=safe(async()=>{
        if(pending||unknown)return;
        if(optimize&&!current.selected.length)throw Error('حداقل یک سرور را انتخاب کنید.');
        const payload=optimize?{action:'optimize',sides:current.selected}:{action:'engine-update',confirm:true,server_ids:current.ids};
        const answer=await api(`/tunnels/${current.t.id}/actions`,{method:'POST',body:JSON.stringify(payload)});
        if(disposed||maintenance!==current)return;
        current.operation={...answer.data,status:'queued'};current.checking=false;current.result=null;current.error=null;
        toast(answer.message);renderMaintenance();await refreshCards();schedule();
      });
    }

    async function operation(t,action){const answer=await api(`/tunnels/${t.id}/actions`,{method:'POST',body:JSON.stringify({action})});if(disposed)return;toast(answer.message);if(action==='logs'){if(active(answer.data))logOperation=answer.data.id;else showLogs(answer.data);}await refreshCards();}
    function confirmDelete(t){openModal('حذف تونل',`<p>تونل «${esc(t.name)}» از هر دو سرور حذف شود؟</p><p class="tunnel-muted">نودها حذف نمی‌شوند، اما اتصال آن‌ها از این تونل قطع می‌شود.</p><div class="form-actions"><button type="button" class="button server-delete-confirm" data-confirm-tunnel-delete>حذف از دو سرور</button></div>`);modalBody().querySelector('[data-confirm-tunnel-delete]').onclick=safe(async()=>{const answer=await api(`/tunnels/${t.id}/actions`,{method:'POST',body:JSON.stringify({action:'delete',confirm:true})});closeModal();toast(answer.message);await refreshCards();});}
    function details(t){
      const s=t.settings;
      openModal('جزئیات تونل',`<div class="tunnel-details"><section class="tunnel-detail-summary"><header><h3>${esc(t.name)}</h3>${badge(t.status)}</header><p class="tunnel-muted">${esc(t.message)}</p><dl><dt>سرور ایران</dt><dd>${esc(t.iran?.name)}</dd><dt>سرور خارج</dt><dd>${esc(t.foreign?.name)}</dd><dt>آدرس کاربران</dt><dd>${s.mappings.map(m=>`<bdi class="tunnel-detail-endpoint" dir="ltr">${esc(endpoint(s.public_host,m.public_port))}</bdi>`).join('')}</dd><dt>پورت ارتباط دو سرور</dt><dd><bdi dir="ltr">${s.tunnel_port}</bdi></dd></dl></section><div class="tunnel-end-status">${[['iran','سرور ایران'],['foreign','سرور خارج']].map(([side,label])=>{const r=t.remote_status?.[side],m=r?.metrics;return `<section><h4>${label}</h4><p>${r?.error?esc(r.error):!r?'هنوز بررسی نشده':r.active?'سرویس روشن است':'سرویس متوقف است'}</p><dl><dt>وضعیت ارتباط</dt><dd>${m?.connected===true?'برقرار':m?.connected===false?'قطع':'تأیید نشده'}</dd><dt>دریافت / ارسال</dt><dd><bdi dir="ltr">${bytes(m?.bytes_in)} / ${bytes(m?.bytes_out)}</bdi></dd><dt>آخرین بررسی</dt><dd>${timeValue(r?.checked_at)}</dd></dl></section>`;}).join('')}</div><section class="tunnel-node-options"><header><h4>استفاده برای نود</h4><p>نود روی سرور خارج اجرا می‌شود؛ آدرس اتصال کاربران، سرور ایران است.</p></header><div class="tunnel-node-grid"><div class="field"><label for="tunnel-node-mapping">پورت موردنظر</label><select class="form-input" id="tunnel-node-mapping">${s.mappings.map((m,i)=>`<option value="${i}">${m.public_port} ← ${m.target_port}</option>`).join('')}</select></div><button type="button" class="button secondary" data-create-tunnel-node ${!t.foreign?.machine_id?'disabled':''}>ساخت نود جدید</button><div class="field"><label for="tunnel-existing-node">نود موجود روی سرور خارج</label><select class="form-input" id="tunnel-existing-node"><option value="">انتخاب کنید</option>${(t.nodes||[]).map(n=>`<option value="${n.id}">${esc(n.name)} — ${n.server_port}</option>`).join('')}</select></div><button type="button" class="button secondary" data-edit-tunnel-node>استفاده برای نود موجود</button></div><p class="tunnel-muted">فرم نود باز می‌شود؛ تغییرات پس از بررسی و ذخیرهٔ شما اعمال خواهند شد. تنظیمات TLS و دامنهٔ آن را هم بررسی کنید.</p></section></div>`,{kind:'tunnel'});
      const body=modalBody(),prefill=()=>{const m=s.mappings[Number(body.querySelector('#tunnel-node-mapping').value)];return {machine_id:t.foreign.machine_id,host:s.public_host,port:String(m.public_port),server_port:m.target_port};};
      body.querySelector('[data-create-tunnel-node]').onclick=safe(async()=>{await openNode(null,{...prefill(),name:t.name});});
      body.querySelector('[data-edit-tunnel-node]').onclick=safe(async()=>{const node=t.nodes.find(n=>n.id===Number(body.querySelector('#tunnel-existing-node').value));if(!node)throw Error('یک نود موجود انتخاب کنید.');const p=prefill();if(node.server_port!==p.server_port)throw Error('پورت مقصد تونل باید با پورت اجرای این نود برابر باشد.');await openNode(node,p);});
    }
    function showLogs(op){const result=op.result||{};openModal('گزارش دو سرور',`<div class="tunnel-log-columns">${[['iran','سرور ایران'],['foreign','سرور خارج']].map(([k,l])=>`<section><h4>${l}</h4><pre class="log-box" dir="ltr">${esc(result[k]?.text||result[k]?.error||'گزارشی دریافت نشد.')}</pre></section>`).join('')}</div>`);}
    return {load,dispose(){disposed=true;maintenance=null;wizard=false;version++;clearTimeout(timer);clearTimeout(refreshTimer);}};
  }
  return {mount};
})();
