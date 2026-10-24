window.SpeedboxInfrastructure = { mount(root, { router, notify, view = 'overview' }) {
  const overlay = root.querySelector('#infrastructureOverlay');
  document.body.appendChild(overlay);
  const scope = {
    getElementById: id => root.querySelector('#' + id) || overlay.querySelector('#' + id),
    querySelector: selector => root.querySelector(selector) || overlay.querySelector(selector),
    querySelectorAll: selector => [...root.querySelectorAll(selector), ...overlay.querySelectorAll(selector)]
  };
  let disposed = false;
  let requests = new AbortController();
  const timers = new Set();
  const viewCache = new Map();
  let savingCount = 0;
  let serverPollTimer = null;
  let serverFetchVersion = 0;
  let nodePollTimer = null;
  let nodeFetchVersion = 0;
  let nodeEditorDispose = null;
  let emergencySession = null;
  let tunnelSession = null;
  let modalCloseHandler = null;
  let draggingNode = null;
  const later = (callback, delay) => { const id = setTimeout(() => { timers.delete(id); if (!disposed) callback(); }, delay); timers.add(id); return id; };
  const apiBase = '/api/admin/infrastructure';
  const token = localStorage.getItem('token');
  let user = null;
  try { user = JSON.parse(localStorage.getItem('user') || 'null'); } catch (_) {}
  if (!token || Number(user?.role) !== 1) { router.replace('/login'); overlay.remove(); return { dispose() {}, showView() {} }; }

  const rankRequests = {node:null, user:null};
  let calendarSession = null;
  const rankState = {node:{range:'today', data:[]}, user:{range:'today', data:[]}};
  const state = {view, queue:null, overview:null, servers:[], machines:[], nodes:[], groups:[], routes:[], plans:[], settings:null};
  const content = scope.getElementById('content');
  const modalBackdrop = scope.getElementById('modalBackdrop');
  const titles = {
    tunnels:'مدیریت تونل‌ها', configuration:'پیکربندی سیستم', overview:'داشبورد', servers:'مدیریت سرورها', nodes:'مدیریت نودها', 'emergency-configs':'کانفیگ‌های اضطراری',
    plans:'مدیریت پلن‌ها', routing:'گروه‌های دسترسی و مسیریابی', settings:'تنظیمات سرورها',
    'site-settings':'تنظیمات سایت', 'security-settings':'تنظیمات امنیت',
    'subscription-settings':'تنظیمات سابسکرایب', 'server-settings':'تنظیمات سرورها',
    'subscription-templates':'قالب‌های سابسکرایب'
  };

  const dashboardIcons = {"Network": "<svg class=\"dashboard-icon\" xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\" aria-hidden=\"true\"><rect x=\"16\" y=\"16\" width=\"6\" height=\"6\" rx=\"1\" /><rect x=\"2\" y=\"16\" width=\"6\" height=\"6\" rx=\"1\" /><rect x=\"9\" y=\"2\" width=\"6\" height=\"6\" rx=\"1\" /><path d=\"M5 16v-3a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1v3\" /><path d=\"M12 12V8\" /></svg>", "Users": "<svg class=\"dashboard-icon\" xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\" aria-hidden=\"true\"><path d=\"M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2\" /><circle cx=\"9\" cy=\"7\" r=\"4\" /><path d=\"M22 21v-2a4 4 0 0 0-3-3.87\" /><path d=\"M16 3.13a4 4 0 0 1 0 7.75\" /></svg>", "Timer": "<svg class=\"dashboard-icon\" xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\" aria-hidden=\"true\"><line x1=\"10\" x2=\"14\" y1=\"2\" y2=\"2\" /><line x1=\"12\" x2=\"15\" y1=\"14\" y2=\"11\" /><circle cx=\"12\" cy=\"14\" r=\"8\" /></svg>", "Cpu": "<svg class=\"dashboard-icon\" xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\" aria-hidden=\"true\"><rect width=\"16\" height=\"16\" x=\"4\" y=\"4\" rx=\"2\" /><rect width=\"6\" height=\"6\" x=\"9\" y=\"9\" rx=\"1\" /><path d=\"M15 2v2\" /><path d=\"M15 20v2\" /><path d=\"M2 15h2\" /><path d=\"M2 9h2\" /><path d=\"M20 15h2\" /><path d=\"M20 9h2\" /><path d=\"M9 2v2\" /><path d=\"M9 20v2\" /></svg>", "Activity": "<svg class=\"dashboard-icon\" xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\" aria-hidden=\"true\"><path d=\"M22 12h-2.48a2 2 0 0 0-1.93 1.46l-2.35 8.36a.25.25 0 0 1-.48 0L9.24 2.18a.25.25 0 0 0-.48 0l-2.35 8.36A2 2 0 0 1 4.49 12H2\" /></svg>", "ArrowUp": "<svg class=\"dashboard-icon\" xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\" aria-hidden=\"true\"><path d=\"m5 12 7-7 7 7\" /><path d=\"M12 19V5\" /></svg>", "ArrowDown": "<svg class=\"dashboard-icon\" xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\" aria-hidden=\"true\"><path d=\"M12 5v14\" /><path d=\"m19 12-7 7-7-7\" /></svg>", "Calendar": "<svg class=\"dashboard-icon\" xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\" aria-hidden=\"true\"><path d=\"M8 2v4\" /><path d=\"M16 2v4\" /><rect width=\"18\" height=\"18\" x=\"3\" y=\"4\" rx=\"2\" /><path d=\"M3 10h18\" /></svg>", "CircleCheck": "<svg class=\"dashboard-icon\" xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\" aria-hidden=\"true\"><circle cx=\"12\" cy=\"12\" r=\"10\" /><path d=\"m9 12 2 2 4-4\" /></svg>", "CircleAlert": "<svg class=\"dashboard-icon\" xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\" aria-hidden=\"true\"><circle cx=\"12\" cy=\"12\" r=\"10\" /><line x1=\"12\" x2=\"12\" y1=\"8\" y2=\"12\" /><line x1=\"12\" x2=\"12.01\" y1=\"16\" y2=\"16\" /></svg>", "Eye": "<svg class=\"dashboard-icon\" xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\" aria-hidden=\"true\"><path d=\"M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z\" /><circle cx=\"12\" cy=\"12\" r=\"3\" /></svg>"};
  dashboardIcons.Server='<svg class="dashboard-icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="3" width="20" height="7" rx="2"/><rect x="2" y="14" width="20" height="7" rx="2"/><path d="M6 6.5h.01M6 17.5h.01"/></svg>';
  dashboardIcons.Layers='<svg class="dashboard-icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m12 3 10 5-10 5L2 8l10-5Zm-10 9 10 5 10-5M2 16l10 5 10-5"/></svg>';
  const dashboardIcon = name => dashboardIcons[name] || "";
  const escape = value => String(value ?? '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
  const faDigits = value => String(value ?? '').replace(/[0-9]/g, digit => '۰۱۲۳۴۵۶۷۸۹'[Number(digit)]);
  const number = value => Number(value || 0).toLocaleString('fa-IR');
  const bytes = value => { let n=Number(value||0), u=['B','KB','MB','GB','TB'], i=0; while(n>=1024&&i<u.length-1){n/=1024;i++} return `${n.toFixed(i?1:0)} ${u[i]}`; };
  const timeAgo = ts => { if(!ts)return 'هنوز متصل نشده'; const s=Math.max(0,Math.floor(Date.now()/1000-Number(ts))); if(s<60)return `${faDigits(s)} ثانیه پیش`; if(s<3600)return `${faDigits(Math.floor(s/60))} دقیقه پیش`; if(s<86400)return `${faDigits(Math.floor(s/3600))} ساعت پیش`; return `${faDigits(Math.floor(s/86400))} روز پیش`; };
  const statusLabel = s => ({online:'آنلاین',deploying:'در حال نصب',failed:'ناموفق',draft:'آماده نصب',degraded:'نیازمند بررسی',queued:'در صف',running:'در حال اجرا',succeeded:'موفق',released:'بازگشت به صف',cancelled:'متوقف شد',offline:'آفلاین'}[s]||s||'نامشخص');
  const badge = (s, label) => `<span class="badge ${escape(s)}">${escape(label||statusLabel(s))}</span>`;

  async function api(path='', options={}) {
    const signal = options.signal || requests.signal;
    const mutation = ['POST','PUT','PATCH','DELETE'].includes((options.method || 'GET').toUpperCase());
    if(mutation && savingCount) throw new Error('لطفاً تا پایان عملیات جاری صبر کنید.');
    const finish = mutation ? savingOverlay() : () => {};
    try {
      const response = await fetch(apiBase + path, {
        ...options, signal,
        headers:{'Accept':'application/json','Content-Type':'application/json','Authorization':`Bearer ${token}`,...(options.headers||{})}
      });
      if(response.status===401){ router.replace('/login'); throw new Error('نشست شما منقضی شده است.'); }
      const data = await response.json().catch(()=>({}));
      if (disposed || signal.aborted) throw new DOMException('View unmounted', 'AbortError');
      if(!response.ok) throw new Error(Object.values(data.errors||{})?.flat()?.[0] || data.message || 'خطا در ارتباط با سرور');
      return data;
    } finally { finish(); }
  }
  function savingOverlay(){
    savingCount++;
    const target = !modalBackdrop.hidden ? overlay.querySelector('.infrastructure-modal') : content;
    const layer=document.createElement('div'); layer.className='creating-overlay'; layer.role='status'; layer.setAttribute('aria-live','polite');
    layer.innerHTML='<div class="creating-box"><span>در حال ذخیره…</span><i class="fas fa-spinner fa-spin" aria-hidden="true"></i></div>';
    target.appendChild(layer); target.setAttribute('aria-busy','true');
    return () => { savingCount--; layer.remove(); target.removeAttribute('aria-busy'); };
  }

  function toast(message,type='success'){
    if (!disposed && message) (notify[type] || notify.notif)(message, { mode: 'global' });
  }
  function empty(title){ return `<div class="empty"><strong>${escape(title)}</strong></div>`; }
  function loadingTable(headers,kind=''){const markup=`<div class="table-wrap"><table><thead><tr>${headers.map(label=>`<th>${escape(label)}</th>`).join('')}</tr></thead><tbody><tr><td class="entity-loading-cell" colspan="${headers.length}">${loading()}</td></tr></tbody></table></div>`;return kind?entityCardLayout(markup,kind):markup;}
  function loading(){ return '<div class="loading" role="status" aria-live="polite"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i><span>در حال دریافت اطلاعات…</span></div>'; }
  function openModal(title,body,options={}){
    const previousTitle=scope.getElementById('modalTitle').textContent;
    const previousBody=scope.getElementById('modalBody');
    const signature=form=>form?[form.id,...form.getAttributeNames().filter(n=>n.startsWith('data-')).sort()].join('|'):null;
    const previousForm=signature(previousBody.querySelector('form'));
    const disclosures=new Map();
    if(options.kind==='tunnel')for(const el of previousBody.querySelectorAll('details')){const key=el.querySelector('summary')?.textContent;const states=disclosures.get(key)||[];states.push(el.open);disclosures.set(key,states);}
    const visible=!modalBackdrop.hidden;
    const scroll=[modalBackdrop,overlay.querySelector('.infrastructure-modal'),previousBody,previousBody.querySelector('.modal-fields')].map(el=>({top:el?.scrollTop||0,left:el?.scrollLeft||0}));
    const focused=previousBody.contains(document.activeElement)?document.activeElement:null;
    const focusName=focused?.getAttribute('name'),focusId=focused?.id;
    const selection=focused&&typeof focused.selectionStart==='number'?[focused.selectionStart,focused.selectionEnd]:null;
    modalCloseHandler=options.onClose||null;
    nodeEditorDispose?.(); nodeEditorDispose=null;
    overlay.querySelector('.infrastructure-modal').classList.toggle('node-modal',!!options.node);
    for(const kind of ['group','routing','plan','server','server-delete','job','emergency','emergency-delete','tunnel']) overlay.querySelector('.infrastructure-modal').classList.toggle(`${kind}-modal`, options.kind===kind);
    scope.getElementById('modalTitle').textContent=title;
    const modalBody=scope.getElementById('modalBody');
    modalBody.innerHTML=body;
    const form=modalBody.querySelector('form');
    const container=form || modalBody;
    if(!options.node){const fields=document.createElement('div'); fields.className='modal-fields';
      [...container.children].filter(child=>!child.classList.contains('form-actions')).forEach(child=>fields.appendChild(child));
      container.prepend(fields);}
    let actions=container.querySelector('.form-actions');
    if(!actions){actions=document.createElement('div');actions.className='form-actions';container.appendChild(actions);}
    if(!container.querySelector('[data-close]')) actions.insertAdjacentHTML('beforeend',`<button class="button cancel" type="button" data-close>${form?'انصراف':'بستن'}</button>`);
    modalBody.querySelectorAll('[data-close]').forEach(button=>{button.classList.add('cancel');button.onclick=closeModal;});
    const preserve=options.preserveView??(visible&&previousTitle===title&&previousForm!==null&&previousForm===signature(form));
    modalBackdrop.hidden=false; document.body.style.overflow='hidden';
    const restore=()=>{
      if(modalBackdrop.hidden||modalBody.firstElementChild!==rendered)return;
      const next=focusName?[...modalBody.querySelectorAll('[name]')].find(el=>el.name===focusName):focusId?[...modalBody.querySelectorAll('[id]')].find(el=>el.id===focusId):null;
      if(next&&!next.disabled){next.focus({preventScroll:true});if(selection&&typeof next.setSelectionRange==='function')try{next.setSelectionRange(...selection);}catch{}}
      [modalBackdrop,overlay.querySelector('.infrastructure-modal'),modalBody,modalBody.querySelector('.modal-fields')].forEach((el,i)=>{if(el){el.scrollTop=scroll[i].top;el.scrollLeft=scroll[i].left;}});
    };
    const rendered=modalBody.firstElementChild;
    if(preserve){
      for(const el of modalBody.querySelectorAll('details')){const states=disclosures.get(el.querySelector('summary')?.textContent);if(states?.length)el.open=states.shift();}
      restore();if(options.kind!=='tunnel')requestAnimationFrame(restore);}else modalBackdrop.scrollTop=0;
  }
  function closeModal(force=false){ if(savingCount && force!==true)return; nodeEditorDispose?.();nodeEditorDispose=null;modalBackdrop.hidden=true; document.body.style.overflow='';const handler=modalCloseHandler;modalCloseHandler=null;handler?.(); }
  modalBackdrop.addEventListener('click',e=>{if(e.target===modalBackdrop)closeModal()});

  async function loadView(){
    const currentView = state.view;
    if(!content.children.length && !['overview','servers','nodes','plans','routing'].includes(currentView)) content.innerHTML=loading();
    try{
      if(currentView==='overview') await loadOverview();
      if(currentView==='servers') await loadServers();
      if(currentView==='nodes') await loadNodes();
      if(currentView==='emergency-configs') {
        emergencySession?.dispose();
        emergencySession=window.SpeedboxEmergencyConfigs.mount(content,{api,toast,openModal,closeModal,modalBody:()=>scope.getElementById('modalBody')});
        await emergencySession.load();
      }
      if(currentView==='tunnels') {
        tunnelSession?.dispose();
        tunnelSession=window.SpeedboxTunnels.mount(content,{api,toast,openModal,closeModal,modalBody:()=>scope.getElementById('modalBody'),navigate:switchView,enhanceControls,
          openNode:async(node,prefill)=>{const [nodes,machines,groups,routes]=await Promise.all([api('/nodes'),api('/machines'),api('/groups'),api('/routes')]);state.nodes=nodes.data;state.kernelCapabilities=nodes.meta?.kernel_capabilities;state.machines=machines.data;state.groups=groups.data;state.routes=routes.data;nodeForm(node?.id?state.nodes.find(n=>n.id===node.id):null,prefill);}
        });
        await tunnelSession.load();
      }
      if(currentView==='plans') await loadPlans();
      if(currentView==='routing') await loadRouting();
      if(currentView==='configuration' || configurationViews[currentView]) await loadSystemConfiguration();
    }catch(e){ if(disposed || e.name==='AbortError')return; content.innerHTML=empty('دریافت اطلاعات ناموفق بود'); toast(e.message,'error'); }
  }

  function renderOverview(o,initial=false){
    content.innerHTML=`
      <div class="stats-grid">
        ${stat('سرورهای مدیریت‌شده',o.managed_servers,'server','servers')}
        ${stat('ماشین‌های آنلاین',`${o.online_machines} / ${o.machines}`,'heartbeat')}
        ${stat('نودهای فعال',`${o.online_nodes} / ${o.nodes}`,'project-diagram','nodes')}
        ${stat('کاربران فعال',o.active_users,'users')}
      </div>
      <div class="dashboard-grid">
        <article class="panel-card"><div class="panel-head"><div><h2>آخرین سرورها</h2></div><button class="button secondary small invoice-surface-button" data-go="servers">مشاهده همه</button></div>${initial?loading():recentServerCards(state.servers.slice(0,6))}</article>
        <article class="panel-card"><div class="panel-head"><div><h2>وضعیت سرورها</h2></div></div><div class="machine-list">${initial?loading():state.machines.length?state.machines.slice(0,5).map(machineCard).join(''):empty('ماشینی وجود ندارد')}</div></article>
      </div>
      <div class="traffic-rank-grid">${rankCard('node')}${rankCard('user')}</div>
      <div class="queue-grid"><article class="panel-card" id="queueStatusCard"></article><article class="panel-card" id="jobDetailsCard"></article></div>`;
    bindRankControls();
    renderQueue({status:'not_seen',wait:0,recentJobs:0,jobsPerMinute:0,peakJobsPerMinute:0,failedJobs:0,processes:0,workload:[],active_queues:[],queueWithMaxRuntime:null});
    content.querySelector('[data-go="servers"]')?.addEventListener('click',()=>switchView('servers'));
    content.querySelectorAll('[data-stat-go]').forEach(link=>link.addEventListener('click',event=>{
      if(event.button!==0||event.ctrlKey||event.metaKey||event.shiftKey||event.altKey)return;
      event.preventDefault();switchView(link.dataset.statGo);
    }));
  }
  async function loadOverview(){
    if(!content.querySelector('.dashboard-grid')) renderOverview({managed_servers:0,online_machines:0,machines:0,online_nodes:0,nodes:0,active_users:0},true);
    const [overview,servers,machines,nodes]=await Promise.all([api('/overview'),api('/servers'),api('/machines'),api('/nodes')]);
    if(disposed||state.view!=='overview')return;
    state.overview=overview.data;state.servers=servers.data;state.machines=machines.data;state.nodes=nodes.data;
    renderOverview(state.overview);
    await Promise.all([loadRank('node'),loadRank('user'),loadQueue()]);
  }
  function rankCard(type) {
    const rank = rankState[type];
    return `<article class="panel-card traffic-rank-card" data-rank-card="${type}">
      <div class="panel-head"><h2 class="dashboard-card-title">${dashboardIcon(type==='node'?'Network':'Users')}<span>${type==='node'?'رتبه‌بندی ترافیک نودها':'رتبه‌بندی ترافیک کاربران'}</span></h2>
        <div class="rank-range"><select data-rank-range="${type}" aria-label="بازهٔ رتبه‌بندی ${type==='node'?'نودها':'کاربران'}">${[['today','امروز'],['last7days','۷ روز گذشته'],['last30days','۳۰ روز گذشته'],['custom','بازهٔ دلخواه']].map(([value,label])=>`<option value="${value}" ${rank.range===value?'selected':''}>${label}</option>`).join('')}</select>${dashboardIcon('Activity')}</div>
      </div>
      <div class="rank-dates" data-rank-dates="${type}" ${rank.range!=='custom'?'hidden':''}>${rankDateControl(type,'start','از تاریخ')}${rankDateControl(type,'end','تا تاریخ')}</div>
      <div class="rank-list" data-rank-list="${type}">${loading()}</div>
    </article>`;
  }
  function todayDate() {
    const parts = Object.fromEntries(new Intl.DateTimeFormat('en-CA',{timeZone:'Asia/Tehran',year:'numeric',month:'2-digit',day:'2-digit'}).formatToParts(new Date()).map(part=>[part.type,part.value]));
    return `${parts.year}-${parts.month}-${parts.day}`;
  }
  function bindRankControls() {
    content.querySelectorAll('[data-rank-range]').forEach(select=>select.onchange=()=>{
      closeRankCalendar();
      const type=select.dataset.rankRange, rank=rankState[type];rank.range=select.value;
      content.querySelector(`[data-rank-dates="${type}"]`).hidden=rank.range!=='custom';loadRank(type);
    });
    content.querySelectorAll('[data-rank-date]').forEach(button=>button.onclick=()=>openRankCalendar(button));
  }
  // Persian calendar is used for every displayed date. ISO dates stay internal to the API.
  const persianDateFormat = new Intl.DateTimeFormat('fa-IR-u-ca-persian',{timeZone:'Asia/Tehran',year:'numeric',month:'2-digit',day:'2-digit'});
  const persianPartsFormat = new Intl.DateTimeFormat('en-US-u-ca-persian',{timeZone:'UTC',year:'numeric',month:'numeric',day:'numeric'});
  const persianMonths = ['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];
  const faNumber = value => Number(value).toLocaleString('fa-IR',{useGrouping:false});
  const displayDate = iso => iso ? persianDateFormat.format(new Date(iso+'T12:00:00Z')) : '—';
  function persianParts(date) {
    const parts=Object.fromEntries(persianPartsFormat.formatToParts(date).map(part=>[part.type,part.value]));
    return {year:Number(parts.year),month:Number(parts.month),day:Number(parts.day)};
  }
  function persianMonthStart(year,month) {
    const target=year*10000+month*100+1;
    let low=Math.floor(Date.UTC(year+621,0,1)/86400000),high=Math.floor(Date.UTC(year+622,11,31)/86400000);
    while(low<high){
      const mid=Math.floor((low+high)/2),p=persianParts(new Date(mid*86400000));
      if(p.year*10000+p.month*100+p.day<target)low=mid+1;else high=mid;
    }
    return low;
  }
  function rankDateControl(type,edge,label) {
    const value=rankState[type][edge]||todayDate();
    return `<div class="rank-date-control"><span>${label}</span><div class="rank-date-picker"><button type="button" class="rank-date-trigger" data-rank-date="${type}" data-date-edge="${edge}" data-date-value="${value}" aria-label="${label}، ${displayDate(value)}" aria-haspopup="dialog" aria-expanded="false" aria-controls="rank-calendar-${type}-${edge}">${dashboardIcon('Calendar')}<b>${displayDate(value)}</b><i class="fas fa-chevron-down rank-date-caret" aria-hidden="true"></i></button></div></div>`;
  }
  function closeRankCalendar(restoreFocus=false) {
    if(!calendarSession)return;
    const {trigger,picker,menu,card}=calendarSession;
    calendarSession=null;
    menu.remove();picker.classList.remove('is-calendar-open');picker.onfocusout=null;
    card?.classList.remove('is-calendar-open');trigger.setAttribute('aria-expanded','false');
    if(restoreFocus&&trigger.isConnected)trigger.focus();
  }
  function openRankCalendar(trigger) {
    if(calendarSession?.trigger===trigger){closeRankCalendar(true);return;}
    closeRankCalendar();closeAllSelects();
    const picker=trigger.closest('.rank-date-picker'),card=trigger.closest('.traffic-rank-card');
    const menu=document.createElement('div');
    menu.id=trigger.getAttribute('aria-controls');menu.className='rank-calendar-menu';
    menu.setAttribute('role','dialog');menu.setAttribute('aria-label','انتخاب تاریخ شمسی');
    picker.appendChild(menu);picker.classList.add('is-calendar-open');card?.classList.add('is-calendar-open');
    trigger.setAttribute('aria-expanded','true');calendarSession={trigger,picker,menu,card};
    picker.onfocusout=e=>{if(e.relatedTarget&&!picker.contains(e.relatedTarget))closeRankCalendar();};
    const selected=trigger.dataset.dateValue,initial=persianParts(new Date(selected+'T12:00:00Z'));
    let year=initial.year,month=initial.month;
    const choose=iso=>{
      if(!trigger.isConnected)return;
      trigger.dataset.dateValue=iso;
      trigger.querySelector('b').textContent=displayDate(iso);
      trigger.setAttribute('aria-label',`${trigger.dataset.dateEdge==='start'?'از تاریخ':'تا تاریخ'}، ${displayDate(iso)}`);
      rankState[trigger.dataset.rankDate][trigger.dataset.dateEdge]=iso;
      closeRankCalendar(true);loadRank(trigger.dataset.rankDate);
    };
    const render=()=>{
      const first=persianMonthStart(year,month),next=persianMonthStart(month===12?year+1:year,month===12?1:month+1);
      const offset=(new Date(first*86400000).getUTCDay()+1)%7,today=todayDate();
      const cells=Array.from({length:offset},()=>'<span aria-hidden="true"></span>');
      for(let day=1;day<=next-first;day++){
        const iso=new Date((first+day-1)*86400000).toISOString().slice(0,10);
        cells.push(`<button type="button" class="jalali-day${iso===selected?' is-selected':''}${iso===today?' is-today':''}" data-calendar-day="${iso}" aria-label="${displayDate(iso)}" aria-pressed="${iso===selected}">${faNumber(day)}</button>`);
      }
      menu.innerHTML=`<div class="jalali-calendar"><div class="jalali-calendar-head"><button type="button" class="button small" data-calendar-prev aria-label="ماه قبل"><i class="fas fa-chevron-right" aria-hidden="true"></i></button><select data-calendar-month aria-label="ماه">${persianMonths.map((name,index)=>`<option value="${index+1}" ${index+1===month?'selected':''}>${name}</option>`).join('')}</select><select data-calendar-year aria-label="سال">${Array.from({length:201},(_,index)=>initial.year-100+index).map(value=>`<option value="${value}" ${value===year?'selected':''}>${faNumber(value)}</option>`).join('')}</select><button type="button" class="button small" data-calendar-next aria-label="ماه بعد"><i class="fas fa-chevron-left" aria-hidden="true"></i></button></div><div class="jalali-calendar-grid">${['ش','ی','د','س','چ','پ','ج'].map(name=>`<span class="jalali-weekday">${name}</span>`).join('')}${cells.join('')}</div><div class="jalali-calendar-footer"><button type="button" class="button small" data-calendar-today>امروز</button></div></div>`;
      menu.querySelector('[data-calendar-month]').onchange=e=>{month=Number(e.target.value);render();};
      menu.querySelector('[data-calendar-year]').onchange=e=>{year=Number(e.target.value);render();};
      const move=step=>{month+=step;if(month<1){month=12;year--;}if(month>12){month=1;year++;}year=Math.max(initial.year-100,Math.min(initial.year+100,year));render();};
      menu.querySelector('[data-calendar-prev]').onclick=()=>move(-1);
      menu.querySelector('[data-calendar-next]').onclick=()=>move(1);
      menu.querySelector('[data-calendar-today]').onclick=()=>choose(today);
      menu.querySelectorAll('[data-calendar-day]').forEach(button=>button.onclick=()=>choose(button.dataset.calendarDay));
      menu.querySelector('.jalali-calendar-grid').onkeydown=e=>{
        const days=[...menu.querySelectorAll('[data-calendar-day]')],index=days.indexOf(e.target);
        const steps={ArrowRight:-1,ArrowLeft:1,ArrowDown:7,ArrowUp:-7};
        if(index>=0&&e.key in steps){e.preventDefault();days[Math.max(0,Math.min(days.length-1,index+steps[e.key]))].focus();}
      };
      enhanceControls(menu);
      menu.querySelector('.jalali-day.is-selected, .jalali-day')?.focus();
    };
    hideRankTooltip();render();
  }
  const trafficGB = value => `${(Number(value||0)/1024**3).toFixed(2)} GB`;
  const entityTooltip=document.createElement('div');entityTooltip.className='custom-tooltip speedbox-entity-tooltip';entityTooltip.id='speedbox-entity-tooltip';entityTooltip.role='tooltip';entityTooltip.hidden=true;document.body.appendChild(entityTooltip);
  let entityTooltipTarget=null;
  function hideEntityTooltip(){entityTooltipTarget?.removeAttribute('aria-describedby');entityTooltipTarget=null;entityTooltip.hidden=true;}
  function showEntityTooltip(target){
    if(!target?.isConnected)return;
    hideEntityTooltip();entityTooltipTarget=target;target.setAttribute('aria-describedby',entityTooltip.id);
    entityTooltip.textContent=target.dataset.entityTooltip;entityTooltip.hidden=false;
    const r=target.getBoundingClientRect(),w=entityTooltip.offsetWidth,h=entityTooltip.offsetHeight;
    entityTooltip.style.left=Math.max(8,Math.min(innerWidth-w-8,r.left+r.width/2-w/2))+'px';
    entityTooltip.style.top=Math.max(8,Math.min(innerHeight-h-8,r.top>h+12?r.top-h-10:r.bottom+10))+'px';
  }
  function entityTooltipEvent(event){const target=event.target.closest?.('[data-entity-tooltip]');if(target&&root.contains(target))showEntityTooltip(target);else if(event.type==='click')hideEntityTooltip();}
  function leaveEntityTooltip(event){if(event.target.closest?.('[data-entity-tooltip]')&&!event.target.contains(event.relatedTarget))hideEntityTooltip();}
  root.addEventListener('pointerover',entityTooltipEvent);root.addEventListener('focusin',entityTooltipEvent);root.addEventListener('pointerout',leaveEntityTooltip);root.addEventListener('focusout',leaveEntityTooltip);document.addEventListener('click',entityTooltipEvent);
  window.addEventListener('scroll',hideEntityTooltip,true);window.addEventListener('resize',hideEntityTooltip);
  const rankTooltip=document.createElement('div');rankTooltip.className='custom-tooltip speedbox-rank-tooltip';rankTooltip.role='tooltip';rankTooltip.hidden=true;document.body.appendChild(rankTooltip);
  function hideRankTooltip(){rankTooltip.hidden=true;}
  function showRankTooltip(target,row,period) {
    rankTooltip.innerHTML=`<div><span>ترافیک فعلی</span><b dir="ltr">${trafficGB(row.value)}</b></div><div><span>ترافیک بازهٔ قبلی</span><b dir="ltr">${trafficGB(row.previousValue)}</b></div><div><span>تغییر مصرف</span><b class="${row.change>=0?'traffic-up':'traffic-down'}" dir="ltr">${row.change>=0?'+':''}${faDigits(row.change)}٪</b></div><div class="rank-tooltip-period">${displayDate(period.start)} — ${displayDate(period.end)}</div><span class="custom-tooltip-arrow"></span>`;
    rankTooltip.hidden=false;const rect=target.getBoundingClientRect(),width=rankTooltip.offsetWidth,height=rankTooltip.offsetHeight;
    rankTooltip.style.left=Math.max(8,Math.min(window.innerWidth-width-8,rect.left+rect.width/2-width/2))+'px';
    const above=rect.top>=height+14;rankTooltip.dataset.side=above?'top':'bottom';
    rankTooltip.style.top=Math.max(8,above?rect.top-height-10:rect.bottom+10)+'px';
    rankTooltip.querySelector('.custom-tooltip-arrow').style.left=Math.max(10,Math.min(width-10,rect.left+rect.width/2-parseFloat(rankTooltip.style.left)))+'px';
  }
  async function loadRank(type) {
    if(disposed||state.view!=='overview')return;
    const list=content.querySelector(`[data-rank-list="${type}"]`);if(!list)return;
    rankRequests[type]?.abort();
    const rank=rankState[type],query=new URLSearchParams({type,range:rank.range});
    if(rank.range==='custom'){
      rank.start=content.querySelector(`[data-rank-date="${type}"][data-date-edge="start"]`).dataset.dateValue;
      rank.end=content.querySelector(`[data-rank-date="${type}"][data-date-edge="end"]`).dataset.dateValue;
      if(!rank.start||!rank.end)return;
      if(rank.end<rank.start){list.innerHTML=empty('تاریخ پایان باید پس از تاریخ شروع باشد');return;}
      query.set('start_date',rank.start);query.set('end_date',rank.end);
    }
    const controller=new AbortController();rankRequests[type]=controller;
    try{
      const result=await api('/traffic-rank?'+query,{signal:controller.signal});
      if(disposed||state.view!=='overview'||!list.isConnected)return;
      rank.data=result.data;rank.period=result.period;hideRankTooltip();
      const maximum=Math.max(1,...rank.data.map(row=>Number(row.value)));
      list.innerHTML=rank.data.length?rank.data.map((row,index)=>`<div class="rank-row" tabindex="0" data-rank-index="${index}"><div class="rank-row-top"><b class="rank-name" title="${escape(row.name)}" dir="auto">${escape(row.name)}</b><span class="rank-change ${row.change>=0?'traffic-up':'traffic-down'}" dir="ltr">${dashboardIcon(row.change>=0?'ArrowUp':'ArrowDown')}${faDigits(Math.abs(row.change))}٪</span></div><div class="rank-row-bottom"><div class="rank-bar"><span style="width:${Math.min(100,Number(row.value)/maximum*100)}%"></span></div><span class="rank-traffic" dir="ltr">${trafficGB(row.value)}</span></div></div>`).join(''):empty('مصرفی ثبت نشده است');
      list.querySelectorAll('[data-rank-index]').forEach(item=>{
        const show=()=>showRankTooltip(item,rank.data[Number(item.dataset.rankIndex)],rank.period);
        item.onmouseenter=show;item.onfocus=show;item.onmouseleave=hideRankTooltip;item.onblur=hideRankTooltip;
      });
    }catch(error){if(error.name==='AbortError'||disposed)return;list.innerHTML=empty('دریافت رتبه‌بندی ناموفق بود');toast(error.message,'error');}
  }
  function queueMetric(label,value,extra='',action='') {
    return `<${action?'button':'div'} class="queue-metric" ${action?`type="button" data-queue-action="${action}"`:''}><span>${label}</span><b>${value===null||value===undefined?'—':escape(faDigits(value))}</b>${extra}</${action?'button':'div'}>`;
  }
  function queueDisplayName(name){return ({default:'صف عمومی',deployments:'عملیات سرورها'})[name]||name||'—';}
  function queueLanes(data,selectedNames){
    const workload=Array.isArray(data.workload)?data.workload:[],active=Array.isArray(data.active_queues)?data.active_queues:[];
    const names=selectedNames||[...new Set(['default','deployments',...workload.map(queue=>queue.name).filter(Boolean)])];
    return `<section class="queue-lanes" aria-label="صف‌های پردازش">${names.map(name=>{
      const queue=workload.find(item=>item.name===name),inline=data.status==='inline',listening=inline||(data.status==='running'&&active.includes(name));
      const count=value=>value===null||value===undefined?'—':number(value);
      return `<div class="queue-lane" data-queue-name="${escape(name)}"><div class="queue-lane-heading"><div class="queue-lane-identity"><span class="queue-lane-icon" aria-hidden="true">${dashboardIcon(name==='deployments'?'Server':'Layers')}</span><div><b>${escape(queueDisplayName(name))}</b><code dir="ltr">${escape(name)}</code></div></div><span class="queue-lane-status badge ${inline||listening?'online':'not_seen'} ${listening?'is-listening':''}">${inline?'اجرای مستقیم':listening?'فعال':'مشاهده نشده'}</span></div><div class="queue-lane-counts"><span>منتظر <bdi>${count(queue?.pending)}</bdi></span><span>در حال اجرا <bdi>${count(queue?.running)}</bdi></span><span>زمان‌بندی‌شده <bdi>${count(queue?.delayed)}</bdi></span></div></div>`;
    }).join('')}</section>`;
  }
  function renderQueue(data){
    const statusCard=scope.getElementById('queueStatusCard'),detailsCard=scope.getElementById('jobDetailsCard');
    if(!statusCard||!detailsCard)return;
      const completed=Number(data.recentJobs||0),pending=(data.workload||[]).reduce((total,queue)=>total+Number(queue.pending||0)+Number(queue.running||0),0);
      const recentProgress=`<span class="queue-mini-progress"><i style="width:${completed/(completed+pending||1)*100}%"></i></span>`;
      const minuteProgress=`<span class="queue-mini-progress"><i style="width:${Math.min(100,Number(data.jobsPerMinute||0)/Math.max(1,Number(data.peakJobsPerMinute||0))*100)}%"></i></span>`;
      const healthy=['running','inline'].includes(data.status),statusText={running:'فعال',inline:'اجرای مستقیم',not_seen:'مشاهده نشده'}[data.status]||'نامشخص';
      statusCard.innerHTML=`<div class="panel-head"><h2 class="dashboard-card-title">${dashboardIcon('Timer')}<span>وضعیت صف</span></h2></div><div class="queue-running"><span class="queue-run-status ${healthy?'is-running':'is-stopped'}">${dashboardIcon(healthy?'CircleCheck':'CircleAlert')} پردازش صف</span>${badge(healthy?'online':'not_seen',statusText)}<div>زمان انتظار <b class="queue-duration" dir="rtl">${data.wait===null?'—':`<bdi>${number(data.wait)}</bdi><span>ثانیه</span>`}</b></div></div><div class="queue-metrics">${queueMetric('کارهای اخیر (۱ ساعت)',data.recentJobs,recentProgress,'recent')}${queueMetric('کارها در دقیقه',data.jobsPerMinute,minuteProgress)}</div>`;
      const generalNames=[...new Set(['default',...(data.workload||[]).map(queue=>queue.name).filter(name=>name&&name!=='deployments')])];
      statusCard.insertAdjacentHTML('beforeend',queueLanes(data,generalNames));
      detailsCard.innerHTML=`<div class="panel-head"><h2 class="dashboard-card-title">${dashboardIcon('Cpu')}<span>جزئیات کارها</span></h2></div><div class="queue-metrics">${queueMetric('کارهای ناموفق (۷ روز)',data.failedJobs,`<span class="queue-metric-meta">${dashboardIcon('Eye')}</span>`,'failed')}${queueMetric('طولانی‌ترین اجرای صف',data.queueWithMaxRuntime?faDigits(data.queueWithMaxRuntime.runtime)+' ثانیه':'—',`<span class="queue-metric-meta" dir="ltr">${escape(queueDisplayName(data.queueWithMaxRuntime?.name))}</span>`)}</div><div class="queue-processes"><span>پردازش‌های فعال</span><b>${number(data.processes)}</b></div>`;
      detailsCard.insertAdjacentHTML('beforeend',queueLanes(data,['deployments']));
  }
  async function loadQueue() {
    if(disposed||state.view!=='overview')return;
    const statusCard=scope.getElementById('queueStatusCard'),detailsCard=scope.getElementById('jobDetailsCard');if(!statusCard)return;
    try{
      const {data}=await api('/queue');if(disposed||state.view!=='overview'||!statusCard.isConnected)return;state.queue=data;
      renderQueue(data);
      statusCard.querySelector('[data-queue-action=recent]').onclick=()=>showQueueJobs(data.recent,'کارهای اخیر');
      detailsCard.querySelector('[data-queue-action=failed]').onclick=async()=>{try{const result=await api('/queue/failed-jobs');showQueueJobs(result.data,'کارهای ناموفق')}catch(error){if(error.name!=='AbortError')toast(error.message,'error')}};
    }catch(error){if(error.name==='AbortError'||disposed)return;statusCard.innerHTML=empty('دریافت وضعیت صف ناموفق بود');detailsCard.innerHTML='';toast(error.message,'error');}
  }
  const jobName = name => String(name||'عملیات صف').split('\\').at(-1);
  const jobDate = value => {
    if(!value)return '—';
    let timestamp=String(value).replace(' ','T');
    if(!/(Z|[+-]\d{2}:?\d{2})$/.test(timestamp))timestamp+='+03:30';
    const date=new Date(timestamp);
    return Number.isNaN(date.getTime())?'—':'\u2066'+date.toLocaleDateString('fa-IR-u-ca-persian',{timeZone:'Asia/Tehran'})+' — '+date.toLocaleTimeString('fa-IR',{timeZone:'Asia/Tehran',hour:'2-digit',minute:'2-digit',hour12:false})+'\u2069';
  };
  function showQueueJobs(jobs,title) {
    openModal(title,jobs.length?`<div class="queue-job-list">${jobs.map((job,index)=>`<button type="button" class="queue-job" data-job-detail="${index}"><span><b dir="ltr">${escape(jobName(job.name))}</b><small>${jobDate(job.finished_at||job.started_at)}</small></span>${badge(job.status)}</button>`).join('')}</div>`:empty('کاری ثبت نشده است'));
    scope.querySelectorAll('[data-job-detail]').forEach(button=>button.onclick=()=>{
      const job=jobs[Number(button.dataset.jobDetail)];
      const fields=[['شناسه',job.job_id],['کار',jobName(job.name)],['صف',queueDisplayName(job.queue)],['وضعیت',statusLabel(job.status)],['تعداد تلاش',job.attempts==null?'—':number(job.attempts)],['مدت اجرا',job.duration==null?'—':faDigits(job.duration)+' ثانیه'],['شروع',jobDate(job.started_at)],['پایان',jobDate(job.finished_at)]];
      openModal('جزئیات کار',`<div class="job-detail-grid">${fields.map(([label,value])=>`<div><span>${label}</span><b dir="${label==='مدت اجرا'?'rtl':'auto'}">${escape(value??'—')}</b></div>`).join('')}${job.exception_class?`<div class="job-error-detail"><span>نوع خطا</span><b dir="ltr">${escape(job.exception_class)}</b></div>`:''}</div>`,{kind:'job'});
    });
  }
  function dashboardPoll() {
    later(async()=>{
      if(state.view==='overview'&&modalBackdrop.hidden&&!calendarSession)await Promise.all([loadRank('node'),loadRank('user'),loadQueue()]);
      if(!disposed)dashboardPoll();
    },30000);
  }
  dashboardPoll();
  window.addEventListener('scroll',hideRankTooltip,true);
  window.addEventListener('resize',hideRankTooltip);

  function stat(label,value,icon,view){const tag=view?'a':'article';return `<${tag} class="stat-card stat-icon-${icon}${view?' stat-link':''}"${view?` href="/admin/infrastructure/${view}" data-stat-go="${view}"`:''}><div class="stat-label"><i class="fas fa-${icon}" aria-hidden="true"></i> ${escape(label)}</div><div class="stat-value">${escape(faDigits(value))}</div></${tag}>`}
  function machineCard(m){const cpu=Number(m.load_status?.cpu||0);return `<div class="machine-item"><div class="machine-top"><div class="entity"><span class="entity-icon"><i class="fas fa-server" aria-hidden="true"></i></span><div><b>${escape(m.name)}</b><small>تعداد نودهای متصل: <bdi dir="ltr">${Number(m.nodes_count||0).toLocaleString('fa-IR')}</bdi></small></div></div>${badge(m.online?'online':'offline')}</div><div class="machine-meta"><span>پردازنده<b>${cpu.toFixed(1)}%</b></span><span>آخرین اتصال<b>${timeAgo(m.last_seen_at)}</b></span></div><div class="meter"><span style="width:${Math.min(100,cpu)}%"></span></div></div>`}

  function cancelServerRefresh(){if(serverPollTimer!==null){clearTimeout(serverPollTimer);timers.delete(serverPollTimer);serverPollTimer=null;}}
  function scheduleServerRefresh(){
    cancelServerRefresh();
    if(state.view!=='servers')return;
    const pending=state.servers.some(s=>['queued','running'].includes(s.deployments?.[0]?.status));
    serverPollTimer=later(async()=>{
      serverPollTimer=null;
      if(state.view!=='servers')return;
      if(savingCount){scheduleServerRefresh();return;}
      try{await loadServers(true);}catch(e){if(e.name!=='AbortError'&&state.view==='servers')scheduleServerRefresh();}
    },pending?4000:10000);
  }
  async function loadServers(background=false){
    cancelServerRefresh();
    const version=++serverFetchVersion;
    if(!background||!scope.getElementById('serverSearch')){
      const query=scope.getElementById('serverSearch')?.value||'';
      content.innerHTML=`<div class="toolbar entity-toolbar"><button class="button primary" id="addServer"><i class="fas fa-plus" aria-hidden="true"></i> افزودن سرور</button><input class="search" id="serverSearch" placeholder="جستجو"></div><article class="panel-card entity-list-shell"></article>`;
      scope.getElementById('serverSearch').value=query;
      bindServers();
      content.querySelector('.panel-card').innerHTML=loadingTable(['سرور','وضعیت','نودهای متصل','منابع','آخرین عملیات','عملیات'],'servers');
    }
    const data=await api('/servers');
    if(version!==serverFetchVersion||state.view!=='servers')return;
    state.servers=data.data;
    renderServerRows();
    scheduleServerRefresh();
  }
  function deploymentProgress(deployment){
    const value=Number(deployment.progress);
    const progress=Number.isFinite(value)?Math.max(0,Math.min(100,value)):0;
    const label=new Intl.NumberFormat('en-US',{style:'percent',maximumFractionDigits:0}).format(progress/100);
    return `<div class="deployment-progress ${escape(deployment.status)}"><small class="progress-value" dir="ltr">${label}</small><div class="progress" role="progressbar" aria-label="پیشرفت عملیات" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${progress}"><span style="width:${progress}%"></span></div></div>`;
  }
  function serverLoad(machine){
    const load=machine?.load_status;if(!load)return '<span class="resource-unseen">بدون گزارش منابع</span>';
    const memory=Number(load.mem?.total??load.mem_total??0),disk=Number(load.disk?.total??load.disk_total??0),memUsed=Number(load.mem?.used??load.mem_used??0),diskUsed=Number(load.disk?.used??load.disk_used??0);
    const metrics=[['microchip','CPU',Number(load.cpu||0),`${Number(load.cpu||0).toFixed(1)}%`],['memory','حافظه',memory?memUsed/memory*100:0,`${bytes(memUsed)} / ${bytes(memory)}`],['hdd','دیسک',disk?diskUsed/disk*100:0,`${bytes(diskUsed)} / ${bytes(disk)}`]];
    return `<div class="server-resources">${metrics.map(([icon,label,percent,value])=>{const n=Math.max(0,Math.min(100,Number.isFinite(percent)?percent:0));return `<div class="resource-row"><span class="resource-label"><i class="fas fa-${icon}" aria-hidden="true"></i> ${label}</span><span class="resource-value" dir="ltr">${escape(value)}</span><div class="resource-meter ${n>=90?'high':n>=70?'medium':''}" role="progressbar" aria-label="${label}" aria-valuenow="${n.toFixed(1)}" aria-valuemin="0" aria-valuemax="100"><i style="width:${n}%"></i></div></div>`;}).join('')}</div>`;
  }
  // One set of controls: table on desktop, labeled cards on mobile.
  function recentServerCards(list) {
    if (!list.length) return empty('سروری ثبت نشده است');
    return `<div class="recent-server-list">${list.map(server => {
      const connection = server.connection_status || 'not_seen';
      const label = ({inactive:'غیرفعال',not_seen:'هنوز متصل نشده'})[connection];
      return `<article class="recent-server-item"><header><div class="recent-server-name"><b>${escape(server.name)}</b><small dir="ltr">${escape(server.host)}</small></div>${badge(connection, label)}</header><footer><span>نودهای متصل: <b dir="ltr">${Number(server.machine?.nodes_count || 0).toLocaleString('fa-IR')}</b></span><small>${timeAgo(server.machine?.last_seen_at)}</small></footer></article>`;
    }).join('')}</div>`;
  }
  function entityCardLayout(markup, kind) {
    const template = document.createElement('template');
    template.innerHTML = markup;
    const table = template.content.querySelector('table');
    if (!table) return markup;
    table.classList.add('mobile-entity-table');
    table.dataset.mobileLayout = kind;
    table.parentElement.classList.add('mobile-entity-wrap');
    const labels = [...table.querySelectorAll('thead th')].map(cell => cell.textContent.trim());
    table.querySelectorAll('tbody tr').forEach(row => {
      [...row.cells].forEach((cell, index) => {
        cell.dataset.label = labels[index] || '';
        if (cell.querySelector('.actions')) cell.classList.add('entity-actions-cell');
      });
    });
    return template.innerHTML;
  }
  function serverTable(list,actions=true){
    if(!list.length)return empty('سروری ثبت نشده است');
    const markup = `<div class="table-wrap"><table><thead><tr><th>سرور</th><th>وضعیت</th><th>نودهای متصل</th><th>منابع</th><th>آخرین عملیات</th>${actions?'<th>عملیات</th>':''}</tr></thead><tbody>${list.map(s=>{
      const d=s.deployments?.[0],busy=['queued','running'].includes(d?.status)||s.status==='removing';
      const connection=s.connection_status||'not_seen';
      return `<tr><td><div class="entity server-identity"><div><b>${escape(s.name)}</b><small>${escape(s.ssh_user)}@${escape(s.host)}:${s.ssh_port}</small></div></div></td><td>${badge(connection,({inactive:'غیرفعال',not_seen:'هنوز متصل نشده'})[connection])}${busy?`<small class="server-operation-label">${s.status==='removing'?'در حال حذف':statusLabel(d?.status)}</small>`:''}<small class="server-last-seen">${timeAgo(s.machine?.last_seen_at)}</small></td><td><span class="server-node-count" dir="ltr">${Number(s.machine?.nodes_count||0).toLocaleString('fa-IR')}</span><small class="server-system">${escape(s.operating_system||'—')} ${escape(s.architecture||'')}</small></td><td>${serverLoad(s.machine)}</td><td class="server-operation-progress">${d?`${badge(d.status)}${deploymentProgress(d)}`:'—'}</td>${actions?`<td><div class="actions"><button class="button entity-install" data-server-action="install" data-id="${s.id}" ${busy?'disabled':''} title="${s.status==='draft'?'نصب سرویس مدیریت نود':'نصب دوباره فایل‌ها و سرویس مدیریت نود و تنظیم مجدد اتصال به پنل'}">${s.status==='draft'?'نصب':'نصب مجدد'}</button><button class="button entity-restart" data-server-action="restart" data-id="${s.id}" ${busy?'disabled':''} title="راه‌اندازی مجدد هسته‌های نود؛ بدون ریبوت ماشین">راه‌اندازی مجدد هسته‌ها</button><button class="button entity-report" data-server-log="${s.id}">گزارش</button><button class="button entity-edit" data-server-edit="${s.id}" ${busy?'disabled':''}>ویرایش</button><button class="button entity-delete" data-server-delete="${s.id}" ${busy?'disabled':''}>حذف</button></div></td>`:''}</tr>`;
    }).join('')}</tbody></table></div>`;
    return actions ? entityCardLayout(markup, 'servers') : markup;
  }
  function deleteServer(id){
    const server=state.servers.find(s=>s.id==id);if(!server)return;
    openModal('حذف سرور',`<div class="field"><label for="serverDeleteMode">روش حذف</label><select id="serverDeleteMode"><option value="cleanup">پاک‌سازی سرور و حذف از پنل</option><option value="panel">حذف فقط از پنل (بدون نیاز به دسترسی سرور)</option></select></div><p class="server-delete-description" id="serverDeleteDescription"></p><div class="form-actions"><button type="button" class="button server-delete-confirm" data-confirm-server-delete>حذف</button><button type="button" class="button cancel" data-close>انصراف</button></div>`,{kind:'server-delete'});
    const mode=scope.getElementById('serverDeleteMode'), description=scope.getElementById('serverDeleteDescription');
    const update=()=>{description.textContent=mode.value==='panel'
      ? `سرور «${server.name}» فقط از پنل حذف می‌شود و دسترسی آن به پنل قطع خواهد شد. این روش برای سرور منقضی‌شده یا بدون دسترسی است؛ فایل‌ها و سرویس‌های روی سرور پاک نمی‌شوند. نودهای متصل به این سرور حذف نمی‌شوند؛ اتصال آن‌ها قطع و تا انتخاب سرور جدید غیرفعال می‌شوند.`
      : `سرویس مدیریت نود، هسته‌ها، فایل‌های اجرایی، اسکریپت نصب و تنظیمات آن از سرور «${server.name}» پاک می‌شوند. پس از موفقیت، سرور از پنل حذف می‌شود. نودهای متصل به این سرور حذف نمی‌شوند؛ اتصال آن‌ها قطع و تا انتخاب سرور جدید غیرفعال می‌شوند. اگر دسترسی به سرور ندارید، روش «حذف فقط از پنل» را انتخاب کنید.`;};
    mode.onchange=update;update();
    scope.querySelector('[data-confirm-server-delete]').onclick=async()=>{try{const r=await api(`/servers/${id}`,{method:'DELETE',body:JSON.stringify({panel_only:mode.value==='panel'})});toast(r.message);closeModal();await loadServers();}catch(e){if(e.name!=='AbortError')toast(e.message,'error');}};
  }
  function bindServers(){
    scope.getElementById('addServer').onclick=()=>serverForm();
    scope.getElementById('serverSearch').oninput=renderServerRows;
  }
  function renderServerRows(){
    const q=(scope.getElementById('serverSearch')?.value||'').toLowerCase();
    const list=state.servers.filter(s=>`${s.name} ${s.host}`.toLowerCase().includes(q));
    content.querySelector('.panel-card').innerHTML=serverTable(list,true);
    bindServerRows();
  }
  function bindServerRows(){
    content.querySelectorAll('[data-server-action]').forEach(b=>b.onclick=()=>runDeployment(b.dataset.id,b.dataset.serverAction));
    content.querySelectorAll('[data-server-edit]').forEach(b=>b.onclick=()=>serverForm(state.servers.find(x=>x.id==b.dataset.serverEdit)));
    content.querySelectorAll('[data-server-log]').forEach(b=>b.onclick=()=>showDeployments(b.dataset.serverLog));
    content.querySelectorAll('[data-server-delete]').forEach(b=>b.onclick=()=>deleteServer(b.dataset.serverDelete));
  }
  function serverForm(server=null){
    openModal(server?'ویرایش سرور':'افزودن سرور',`<form id="serverForm"><div class="form-grid">
      ${field('name','نام سرور',server?.name||'',true)}${field('host','IP یا دامنه',server?.host||'',true,'text','ltr')}
      ${field('ssh_port','پورت SSH',server?.ssh_port||22,true,'number','ltr')}${field('ssh_user','کاربر SSH',server?.ssh_user||'root',true,'text','ltr')}
      <div class="field"><label>روش ورود</label><select name="auth_type" id="authType"><option value="password" ${server?.auth_type!=='key'?'selected':''}>رمز عبور</option><option value="key" ${server?.auth_type==='key'?'selected':''}>کلید خصوصی</option></select></div>
      <div class="field" id="passwordField"><label>رمز عبور</label><input name="password" type="password" autocomplete="new-password" placeholder="${server?'بدون تغییر':'رمز SSH'}"></div>
      <div class="field full" id="keyField"><label>کلید خصوصی SSH</label><textarea name="private_key" placeholder="-----BEGIN OPENSSH PRIVATE KEY-----"></textarea></div>
      ${field('private_key_passphrase','رمز کلید خصوصی (اختیاری)','',false,'password')}
      ${field('host_fingerprint','اثر انگشت SSH (اختیاری)',server?.host_fingerprint||'',false,'text','ltr')}
    </div><div class="form-actions"><button class="button primary">${server?'ذخیره تغییرات':'ذخیره و نصب خودکار'}</button><button type="button" class="button secondary" data-close>انصراف</button></div></form>`,{kind:'server'});
    const toggle=()=>{const key=scope.getElementById('authType').value==='key';scope.getElementById('keyField').style.display=key?'grid':'none';scope.getElementById('passwordField').style.display=key?'none':'grid'}; scope.getElementById('authType').onchange=toggle;toggle();
    scope.querySelector('[data-close]').onclick=closeModal;
    scope.getElementById('serverForm').onsubmit=async e=>{e.preventDefault();const body=Object.fromEntries(new FormData(e.target));try{const r=await api(server?`/servers/${server.id}`:'/servers',{method:server?'PUT':'POST',body:JSON.stringify(body)});toast(r.message);closeModal();await loadServers();}catch(err){toast(err.message,'error')}};
  }
  async function runDeployment(id,action){try{const r=await api(`/servers/${id}/deploy`,{method:'POST',body:JSON.stringify({action,kernel:'singbox'})});toast(r.message);await loadServers();}catch(e){toast(e.message,'error')}}
  const deploymentActionLabel=action=>({install:'نصب',upgrade:'ارتقا',repair:'نصب مجدد',restart:'راه‌اندازی مجدد هسته‌ها',check:'بررسی وضعیت',uninstall:'حذف کامل سرویس مدیریت نود',warp_prepare:'آماده‌سازی WARP',warp_test:'تست اتصال WARP'}[action]||action);
  async function showDeployments(id){try{const {data}=await api(`/servers/${id}/deployments`);openModal('گزارش عملیات',data.length?data.map(d=>`<div class="machine-item"><div class="machine-top"><b>${escape(deploymentActionLabel(d.action))} · ${jobDate(d.created_at)}</b>${badge(d.status)}</div>${deploymentProgress(d)}<pre class="log-box" dir="auto">${escape(d.log||'بدون گزارش')}</pre></div>`).join(''):empty('گزارشی وجود ندارد'))}catch(e){toast(e.message,'error')}}

  function cancelNodeRefresh(){if(nodePollTimer!==null){clearTimeout(nodePollTimer);timers.delete(nodePollTimer);nodePollTimer=null;}}
  function scheduleNodeRefresh(){
    cancelNodeRefresh();if(state.view!=='nodes')return;
    nodePollTimer=later(async()=>{nodePollTimer=null;if(state.view!=='nodes')return;if(savingCount||draggingNode!==null){scheduleNodeRefresh();return;}try{await loadNodes(true);}catch(e){if(e.name!=='AbortError'&&state.view==='nodes')scheduleNodeRefresh();}},5000);
  }
  async function loadNodes(background=false){
    cancelNodeRefresh();const version=++nodeFetchVersion;
    if(!background||!scope.getElementById('nodeSearch')){
      const query=scope.getElementById('nodeSearch')?.value||'';
      content.innerHTML=`<div class="toolbar entity-toolbar"><button class="button primary" id="addNode"><i class="fas fa-plus" aria-hidden="true"></i> ساخت نود</button><input class="search" id="nodeSearch" placeholder="جستجو"></div><article class="panel-card entity-list-shell"></article>`;
      content.querySelector('.panel-card').innerHTML=loadingTable(['ترتیب','شماره نود','نود','نمایش','کاربران آنلاین','سرور مقصد','هسته','پورت','ترافیک مصرفی','عملیات'],'nodes');
      scope.getElementById('nodeSearch').value=query;
      scope.getElementById('addNode').disabled=true;
      scope.getElementById('addNode').onclick=()=>nodeForm();scope.getElementById('nodeSearch').oninput=renderNodeRows;
    }
    const results=background?[await api('/nodes')]:await Promise.all([api('/nodes'),api('/machines'),api('/groups'),api('/routes')]);
    if(version!==nodeFetchVersion||state.view!=='nodes')return;
    state.nodes=results[0].data;state.kernelCapabilities=results[0].meta?.kernel_capabilities;
    if(!background){state.machines=results[1].data;state.groups=results[2].data;state.routes=results[3].data;scope.getElementById('addNode').disabled=false;}
    if(draggingNode===null){hideEntityTooltip();renderNodeRows();}scheduleNodeRefresh();
  }
  function nodeTraffic(node){
    const usage=node.traffic_usage||{total:Number(node.u||0)+Number(node.d||0),limit:Number(node.transfer_enable||0)};
    const used=Number(usage.total||0),limit=Number(usage.limit||0);
    const percent=limit>0?Math.max(0,used/limit*100):0;
    const title=`مصرف: ${bytes(used)}${limit>0?` / سقف: ${bytes(limit)} / ${percent.toFixed(1)}%`:''}${usage.shared?` / مصرف مشترک با نود ${usage.source_name}`:''}`;
    return `<div class="node-traffic" title="${escape(title)}"><span dir="ltr">${escape(bytes(used))}${limit>0?` / ${escape(bytes(limit))}`:''}</span>${limit>0?`<div class="node-traffic-bar"><span class="${percent>=90?'danger':''}" style="width:${Math.min(100,percent).toFixed(1)}%"></span></div>`:''}${usage.shared?'<small>مشترک با نود والد</small>':''}</div>`;
  }
  function nodeStatusDot(node){
    const runtime=node.runtime||{};
    const code=runtime.connection_state||(!node.machine_id?'unassigned':!node.enabled?'disabled':runtime.online?(Number(runtime.online_users)>0?'active':'idle'):runtime.connected?'kernel_stopped':'offline');
    const states={active:['green','در حال اجرای عادی'],idle:['orange','بدون استفاده یا غیرعادی'],waiting_for_users:['orange','بدون استفاده یا غیرعادی'],initializing:['orange','بدون استفاده یا غیرعادی']};
    const [color,label]=states[code]||['red','در حال اجرا نیست'];
    return `<button type="button" class="node-runtime-dot ${color}" data-entity-tooltip="${escape(label)}" aria-label="${escape(label)}"><span aria-hidden="true"></span></button>`;
  }
  function nodeTable(list){
    if(!list.length)return empty('نودی ساخته نشده است');
    const markup = `<div class="table-wrap"><table class="node-table"><thead><tr><th>ترتیب</th><th>شماره نود</th><th>نود</th><th>نمایش</th><th>کاربران آنلاین</th><th>سرور مقصد</th><th>هسته</th><th>پورت</th><th>ترافیک مصرفی</th><th>عملیات</th></tr></thead><tbody>${list.map(n=>{
      return `<tr data-node-row="${n.id}"><td><div class="node-order-controls"><button type="button" class="node-drag" draggable="true" data-drag-node="${n.id}" aria-label="جابه‌جایی نود" title="برای مرتب‌سازی بکشید"><i class="fas fa-grip-vertical" aria-hidden="true"></i></button><span><button type="button" data-node-move="${n.id}" data-step="-1" aria-label="یک ردیف بالاتر" ${state.nodes[0]?.id===n.id?'disabled':''}>⌃</button><button type="button" data-node-move="${n.id}" data-step="1" aria-label="یک ردیف پایین‌تر" ${state.nodes.at(-1)?.id===n.id?'disabled':''}>⌄</button></span></div></td><td><span class="node-number"><span class="node-id-badge"><bdi dir="ltr">${escape(n.id)}${n.parent_id?` <span class="node-parent-arrow" aria-hidden="true">→</span> ${escape(n.parent_id)}`:''}</bdi></span>${window.SpeedboxNodeEditor.protocolBadge(n.type)}</span></td><td><div class="entity node-identity"><div><div class="node-name-line">${nodeStatusDot(n)}<b>${escape(n.name)}</b></div><small dir="ltr">${escape(n.host)}</small></div></div></td><td><label class="switch-line ios"><input type="checkbox" data-node-show="${n.id}" aria-label="نمایش ${escape(n.name)} در سابسکرایب" ${n.show?'checked':''}></label></td><td><span dir="ltr">${Number(n.runtime?.online_users||0)}</span>${n.parent_id?'<small class="server-system">مشترک با نود اصلی</small>':''}</td><td>${escape(n.machine?.name||'—')}${n.parent_id?`<small class="server-system">نود اصلی: ${escape(n.parent_id)}</small>`:''}</td><td><span class="badge">${n.kernel==='xray'?'Xray':'Sing-box'}</span></td><td><span dir="ltr">${escape(n.port)}</span></td><td>${nodeTraffic(n)}</td><td><div class="actions"><button class="button entity-edit" data-node-edit="${n.id}">ویرایش</button><button class="button entity-copy" data-node-clone="${n.id}">کپی</button><button class="button entity-delete" data-node-delete="${n.id}">حذف</button></div></td></tr>`;
    }).join('')}</tbody></table></div>`;
    return entityCardLayout(markup, 'nodes');
  }
  function renderNodeRows(){
    const q=(scope.getElementById('nodeSearch')?.value||'').toLowerCase();
    content.querySelector('.panel-card').innerHTML=nodeTable(state.nodes.filter(n=>`${n.id} ${n.name} ${n.host} ${n.type} ${n.kernel} ${n.machine?.name}`.toLowerCase().includes(q)));bindNodeRows();
  }
  async function saveNodeOrder(ids){
    cancelNodeRefresh();nodeFetchVersion++;
    try{const r=await api('/nodes/order',{method:'PUT',body:JSON.stringify({ids})});toast(r.message);await loadNodes(true);}catch(e){if(e.name!=='AbortError'){toast(e.message,'error');await loadNodes(true).catch(()=>scheduleNodeRefresh());}}
  }
  function moveNode(id,target,after=false){const ids=state.nodes.map(n=>Number(n.id));const from=ids.indexOf(Number(id));if(from<0||Number(id)===Number(target))return;ids.splice(from,1);const to=ids.indexOf(Number(target));if(to<0)return;ids.splice(to+(after?1:0),0,Number(id));saveNodeOrder(ids);}
  function bindNodeRows(){
    content.querySelectorAll('[data-node-edit]').forEach(b=>b.onclick=()=>nodeForm(state.nodes.find(n=>n.id==b.dataset.nodeEdit)));
    content.querySelectorAll('[data-node-clone]').forEach(b=>b.onclick=async()=>{try{const r=await api(`/nodes/${b.dataset.nodeClone}/clone`,{method:'POST'});toast(r.message);await loadNodes();}catch(e){if(e.name!=='AbortError')toast(e.message,'error');}});
    content.querySelectorAll('[data-node-delete]').forEach(b=>b.onclick=async()=>{if(!confirm('این نود حذف شود؟'))return;try{const r=await api(`/nodes/${b.dataset.nodeDelete}`,{method:'DELETE'});toast(r.message);await loadNodes();}catch(e){if(e.name!=='AbortError')toast(e.message,'error');}});
    content.querySelectorAll('[data-node-show]').forEach(input=>input.onchange=async()=>{const id=input.dataset.nodeShow,value=input.checked;input.disabled=true;try{const r=await api(`/nodes/${id}/flags`,{method:'PATCH',body:JSON.stringify({show:value})});const node=state.nodes.find(n=>n.id==id);if(node)node.show=value;toast(r.message);}catch(e){input.checked=!value;if(e.name!=='AbortError')toast(e.message,'error');}finally{input.disabled=false;}});
    content.querySelectorAll('[data-node-move]').forEach(b=>b.onclick=()=>{const index=state.nodes.findIndex(n=>n.id==b.dataset.nodeMove),step=Number(b.dataset.step),target=state.nodes[index+step];if(target)moveNode(b.dataset.nodeMove,target.id,step>0);});
    content.querySelectorAll('[data-drag-node]').forEach(handle=>{
      handle.ondragstart=e=>{if(savingCount){e.preventDefault();return;}draggingNode=Number(handle.dataset.dragNode);cancelNodeRefresh();nodeFetchVersion++;e.dataTransfer.effectAllowed='move';e.dataTransfer.setData('text/plain',String(draggingNode));handle.closest('tr').classList.add('is-dragging');};
      handle.ondragend=()=>{draggingNode=null;content.querySelectorAll('.is-dragging,.node-drop-target').forEach(row=>row.classList.remove('is-dragging','node-drop-target'));scheduleNodeRefresh();};
    });
    content.querySelectorAll('[data-node-row]').forEach(row=>{
      row.ondragover=e=>{if(draggingNode!==null){e.preventDefault();e.dataTransfer.dropEffect='move';row.classList.add('node-drop-target');}};
      row.ondragleave=()=>row.classList.remove('node-drop-target');
      row.ondrop=e=>{e.preventDefault();const id=draggingNode;draggingNode=null;const rect=row.getBoundingClientRect();if(id!==null)moveNode(id,row.dataset.nodeRow,e.clientY>rect.top+rect.height/2);};
    });
  }
  function nodeForm(node=null,prefill=null){nodeEditorDispose=window.SpeedboxNodeEditor.mount({node,prefill,state,api,openModal,closeModal,toast,reload:()=>state.view==='tunnels'?tunnelSession.load():loadNodes(),enhanceControls,isSaving:()=>savingCount>0});}

  const trafficResetOptions = [['','مطابق تنظیمات سیستم'],['0','ابتدای هر ماه'],['1','ماهانه در روز پایان دوره'],['2','بدون بازنشانی'],['3','ابتدای هر سال'],['4','سالانه در روز پایان دوره']];
  const trafficResetLabel = value => trafficResetOptions.find(([key])=>key===String(value ?? ''))?.[1] || 'مطابق تنظیمات سیستم';
  const planTags = plan => {if(Array.isArray(plan?.tags))return plan.tags;if(typeof plan?.tags==='string'){try{const tags=JSON.parse(plan.tags);return Array.isArray(tags)?tags:[];}catch(_){return [];}}return [];};
  async function loadPlans(){
    const query=scope.getElementById('planSearch')?.value||'';
    content.innerHTML=`<div class="toolbar entity-toolbar"><button class="button primary" id="addPlan"><i class="fas fa-plus" aria-hidden="true"></i> ساخت پلن</button><input class="search" id="planSearch" placeholder="جستجو" aria-label="جستجوی پلن"></div><article class="panel-card entity-list-shell"></article>`;
    content.querySelector('.panel-card').innerHTML=loadingTable(['نام پلن','گروه دسترسی','ترافیک پلن','محدودیت سرعت','محدودیت اتصال','بازنشانی ترافیک','عملیات'],'plans');
    scope.getElementById('addPlan').disabled=true;
    const [plans,groups]=await Promise.all([api('/plans'),api('/groups')]);
    if(state.view!=='plans')return;
    state.plans=plans.data;state.groups=groups.data;
    scope.getElementById('planSearch').value=query;
    scope.getElementById('planSearch').oninput=renderPlanRows;
    scope.getElementById('addPlan').disabled=false;scope.getElementById('addPlan').onclick=()=>planForm();renderPlanRows();
  }
  function planUserStats(plan){
    const total=Number(plan.users_count||0),active=Number(plan.active_users_count||0);
    return `<div class="plan-user-stats"><button type="button" class="plan-user-count total" data-entity-tooltip="کل کاربران این پلن، شامل اشتراک‌های منقضی‌شده" aria-label="کل کاربران: ${total}">${dashboardIcon('Users')}<span>${faDigits(total)}</span></button><button type="button" class="plan-user-count active" data-entity-tooltip="کاربران فعال این پلن" aria-label="کاربران فعال این پلن: ${active}">${dashboardIcon('Users')}<span>${faDigits(active)}</span></button></div>`;
  }
  function renderPlanRows(){
    const query=(scope.getElementById('planSearch')?.value||'').trim().toLowerCase();
    const list=state.plans.filter(p=>`${p.name} ${planTags(p).join(' ')} ${state.groups.find(g=>g.id==p.group_id)?.name||''}`.toLowerCase().includes(query));
    content.querySelector('.panel-card').innerHTML=list.length?entityCardLayout(`<div class="table-wrap"><table class="plan-table"><thead><tr><th>نام پلن</th><th>گروه دسترسی</th><th>ترافیک پلن</th><th>محدودیت سرعت</th><th>محدودیت اتصال</th><th>بازنشانی ترافیک</th><th>عملیات</th></tr></thead><tbody>${list.map(p=>`<tr><td><b>${escape(p.name)}</b>${planUserStats(p)}</td><td>${escape(state.groups.find(g=>g.id==p.group_id)?.name||p.group_id||'—')}</td><td><span dir="ltr">${escape(p.transfer_enable)} GB</span></td><td>${Number(p.speed_limit)>0?`<span dir="ltr">${escape(p.speed_limit)} Mbps</span>`:'نامحدود'}</td><td>${Number(p.device_limit)>0?escape(p.device_limit):'نامحدود'}</td><td>${escape(trafficResetLabel(p.reset_traffic_method))}</td><td><div class="actions"><button class="button entity-edit" data-plan-edit="${p.id}">ویرایش</button><button class="button entity-delete" data-plan-delete="${p.id}">حذف</button></div></td></tr>`).join('')}</tbody></table></div>`, 'plans'):empty(query?'پلنی با این جستجو پیدا نشد':'پلنی تعریف نشده است');
    content.querySelectorAll('[data-plan-edit]').forEach(b=>b.onclick=()=>planForm(state.plans.find(p=>p.id==b.dataset.planEdit)));
    content.querySelectorAll('[data-plan-delete]').forEach(b=>b.onclick=()=>deletePlan(state.plans.find(p=>p.id==b.dataset.planDelete)));
  }
  function planForm(plan=null){
    openModal(plan?'ویرایش پلن':'ساخت پلن',`<form id="planForm"><div class="form-grid">
      ${field('name','نام پلن',plan?.name||'',true)}
      ${field('tags','تگ‌های پلن',planTags(plan).join(', '))}
      <div class="field"><label for="planGroup">گروه دسترسی</label><select id="planGroup" name="group_id" required><option value="" disabled ${!plan?.group_id?'selected':''}>انتخاب گروه دسترسی</option>${state.groups.map(g=>`<option value="${g.id}" ${plan?.group_id==g.id?'selected':''}>${escape(g.name)}</option>`).join('')}</select></div>
      ${field('transfer_enable','ترافیک پلن (GB)',plan?.transfer_enable??'',true,'number','ltr')}
      ${field('speed_limit','محدودیت سرعت (Mbps)',plan?.speed_limit??'',false,'number','ltr')}
      ${field('device_limit','محدودیت اتصال (تعداد IP)',plan?.device_limit??'',false,'number','ltr')}
      ${field('capacity_limit','محدودیت ظرفیت',plan?.capacity_limit??'',false,'number','ltr')}
      <div class="field"><label for="planReset">روش بازنشانی ترافیک</label><select id="planReset" name="reset_traffic_method">${trafficResetOptions.map(([value,label])=>`<option value="${value}" ${String(plan?.reset_traffic_method??'')===value?'selected':''}>${label}</option>`).join('')}</select></div>
      <p class="plan-form-hint">ظرفیت، سقف تعداد کاربران فعال این پلن است؛ خالی یعنی نامحدود و صفر یعنی توقف ساخت کاربر جدید.</p>
      ${plan?'<div class="switch-field full"><span>اعمال تغییرات روی کاربران فعلی</span><label class="switch-line ios"><input type="checkbox" name="force_update" aria-label="اعمال تغییرات روی کاربران فعلی"></label></div><p class="plan-form-hint">با فعال‌کردن این گزینه، گروه دسترسی، ترافیک، محدودیت سرعت و اتصال کاربران این پلن به‌روز می‌شود. محدودیت اختصاصی تعرفه، مصرف و تاریخ انقضای آن‌ها حفظ می‌شود.</p>':''}
      </div><div class="form-actions"><button class="button primary" type="submit">ذخیره پلن</button></div></form>`,{kind:'plan'});
    const form=scope.getElementById('planForm');
    for(const key of ['transfer_enable','speed_limit','device_limit','capacity_limit'])form.elements[key].min='0';
    for(const key of ['speed_limit','device_limit','capacity_limit'])form.elements[key].placeholder='بدون محدودیت';
    form.onsubmit=async e=>{
      e.preventDefault();const body=Object.fromEntries(new FormData(form));
      body.group_id=Number(body.group_id);body.transfer_enable=Number(body.transfer_enable);
      for(const key of ['speed_limit','device_limit','capacity_limit','reset_traffic_method'])body[key]=body[key]===''?null:Number(body[key]);
      body.tags=[...new Set(body.tags.split(/[,،\n]/).map(tag=>tag.trim()).filter(Boolean))];
      body.force_update=!!form.elements.force_update?.checked;
      try{const r=await api(plan?`/plans/${plan.id}`:'/plans',{method:plan?'PUT':'POST',body:JSON.stringify(body)});toast(r.message);closeModal();await loadPlans();}catch(err){if(err.name!=='AbortError')toast(err.message,'error');}
    };
  }
  function deletePlan(plan){
    if(!plan)return;
    openModal('حذف پلن',`<p class="server-delete-description">پلن «${escape(plan.name)}» حذف شود؟ پلنی که توسط کاربران یا تعرفه‌های فروش استفاده می‌شود قابل حذف نیست.</p><div class="form-actions"><button type="button" class="button server-delete-confirm" data-confirm-plan-delete>حذف</button><button type="button" class="button cancel" data-close>انصراف</button></div>`,{kind:'server-delete'});
    scope.querySelector('[data-confirm-plan-delete]').onclick=async()=>{try{const r=await api(`/plans/${plan.id}`,{method:'DELETE'});toast(r.message);closeModal();await loadPlans();}catch(err){if(err.name!=='AbortError')toast(err.message,'error');}};
  }
  async function loadRouting(){
    if(!content.children.length) content.innerHTML=`<div class="two-columns">${['گروه‌های دسترسی','قوانین مسیریابی'].map(title=>`<article class="panel-card"><div class="panel-head"><h2>${title}</h2></div>${loading()}</article>`).join('')}</div>`;
    const [groups,routes]=await Promise.all([api('/groups'),api('/routes')]);
    if(disposed||state.view!=='routing')return;
    state.groups=groups.data;state.routes=routes.data;content.innerHTML=`<div class="two-columns"><article class="panel-card"><div class="panel-head"><div><h2>گروه‌های دسترسی</h2></div><button class="button primary routing-create" id="addGroup"><i class="fas fa-plus" aria-hidden="true"></i> گروه جدید</button></div>${simpleList(state.groups,'group')}</article><article class="panel-card"><div class="panel-head"><div><h2>قوانین مسیریابی</h2></div><button class="button primary routing-create" id="addRoute"><i class="fas fa-plus" aria-hidden="true"></i> قانون جدید</button></div>${simpleList(state.routes,'route')}</article></div>`;bindRouting()
  }
  function simpleList(list,type){if(!list.length)return empty(type==='group'?'گروهی وجود ندارد':'قانونی وجود ندارد');return `<div class="machine-list">${list.map(x=>`<div class="machine-item"><div class="machine-top"><div><b>${escape(type==='group'?x.name:x.remarks)}</b>${type==='route'?`<small style="display:block;color:var(--muted)">${escape(({block:'مسدودسازی',direct:'اتصال مستقیم',dns:'DNS',proxy:'پروکسی'})[x.action]||x.action)} · ${escape(JSON.stringify(x.match))}</small>`:''}</div><div class="actions"><button class="button entity-edit" data-${type}-edit="${x.id}">ویرایش</button><button class="button entity-delete" data-${type}-delete="${x.id}">حذف</button></div></div></div>`).join('')}</div>`}
  function bindRouting(){scope.getElementById('addGroup').onclick=()=>groupForm();scope.getElementById('addRoute').onclick=()=>routeForm();content.querySelectorAll('[data-group-edit]').forEach(b=>b.onclick=()=>groupForm(state.groups.find(x=>x.id==b.dataset.groupEdit)));content.querySelectorAll('[data-route-edit]').forEach(b=>b.onclick=()=>routeForm(state.routes.find(x=>x.id==b.dataset.routeEdit)));content.querySelectorAll('[data-group-delete]').forEach(b=>b.onclick=()=>deleteEntity('groups',b.dataset.groupDelete));content.querySelectorAll('[data-route-delete]').forEach(b=>b.onclick=()=>deleteEntity('routes',b.dataset.routeDelete))}
  function groupForm(x=null){openModal(x?'ویرایش گروه دسترسی':'گروه دسترسی جدید',`<form id="smallForm">${field('name','نام گروه',x?.name||'',true)}<div class="form-actions"><button class="button primary">ذخیره</button></div></form>`,{kind:'group'});smallSubmit('groups',x)}
  function routeForm(x=null){
    const actions = [['block','مسدودسازی'],['direct','اتصال مستقیم'],['dns','DNS'],['proxy','پروکسی']];
    const rules = Array.isArray(x?.match) ? x.match.join('\n') : '';
    openModal(x?'ویرایش قانون مسیریابی':'قانون مسیریابی جدید',`<form id="smallForm"><div class="form-grid">${field('remarks','نام قانون',x?.remarks||'',true)}<div class="field full"><label for="routeRules">شرایط اعمال قانون</label><textarea id="routeRules" name="match" dir="ltr" required placeholder="domain:example.com&#10;ip:1.1.1.1">${escape(rules)}</textarea></div><div class="field"><label for="routeAction">عملیات</label><select id="routeAction" name="action">${actions.map(([value,label])=>`<option value="${value}" ${value===(x?.action||'block')?'selected':''}>${label}</option>`).join('')}</select></div><div id="routeActionValue" class="field"><label for="routeTarget">مقصد عملیات</label><input id="routeTarget" name="action_value" dir="ltr" value="${escape(x?.action_value||'')}"></div></div><div class="form-actions"><button class="button primary">ذخیره</button></div></form>`,{kind:'routing'});
    const form=scope.getElementById('smallForm'), action=form.elements.action, value=form.elements.action_value;
    const update=()=>{const needed=['dns','proxy'].includes(action.value);scope.getElementById('routeActionValue').hidden=!needed;value.disabled=!needed;scope.getElementById('routeActionValue').querySelector('label').textContent=action.value==='dns'?'آدرس DNS':'مقصد پروکسی';};
    action.onchange=update; update(); smallSubmit('routes',x,true);
  }
  function smallSubmit(kind,x,json=false){scope.getElementById('smallForm').onsubmit=async e=>{
    e.preventDefault();const body=Object.fromEntries(new FormData(e.target));
    if(json){
      const text=body.match.trim();
      if(text.startsWith('[')){try{body.match=JSON.parse(text);if(!Array.isArray(body.match))throw Error();}catch(_){toast('شرایط اعمال قانون معتبر نیست.','error');return;}}
      else body.match=text.split(/\r?\n/).map(rule=>rule.trim()).filter(Boolean);
      if(!body.match.length){toast('حداقل یک شرط برای اعمال قانون وارد کنید.','error');return;}
      if(!['dns','proxy'].includes(body.action))body.action_value=null;
    }
    try{const r=await api(`/${kind}${x?`/${x.id}`:''}`,{method:x?'PUT':'POST',body:JSON.stringify(body)});toast(r.message);closeModal();loadRouting()}catch(err){if(err.name!=='AbortError')toast(err.message,'error')}
  };}
  async function deleteEntity(kind,id){if(!confirm('این مورد حذف شود؟'))return;try{const r=await api(`/${kind}/${id}`,{method:'DELETE'});toast(r.message);loadRouting()}catch(e){toast(e.message,'error')}}


  const configurationViews = {'site-settings':'general','security-settings':'general','subscription-settings':'subscribe','server-settings':'server',settings:'server','subscription-templates':'templates'};
  async function loadSystemConfiguration() {
    if(content.querySelector('.configuration-shell')) {
      await Promise.all([...content.querySelectorAll('[data-configuration-panel]')].filter(panel=>!panel.querySelector('form')).map(async target=>{
        const section=target.dataset.configurationPanel;
        try{if(section==='templates')await loadTemplates(target,()=>target.isConnected);else await loadConfiguration(section,target,()=>target.isConnected);}
        catch(error){if(error.name!=='AbortError')toast(error.message,'error');}
      }));
      return;
    }
    const tabs = [['general','عمومی و امنیت'],['subscribe','تنظیمات سابسکرایب'],['server','تنظیمات سرورها'],['templates','قالب‌های سابسکرایب']];
    let active = configurationViews[state.view] || 'general';
    content.innerHTML = `<section class="configuration-shell"><nav class="segmented configuration-tabs" aria-label="بخش‌های پیکربندی سیستم">${tabs.map(([key,label])=>`<button type="button" class="seg-btn" data-section="${key}">${label}</button>`).join('')}</nav>${tabs.map(([key])=>`<div data-configuration-panel="${key}" ${key!==active?'hidden':''}></div>`).join('')}</section>`;
    const panels=[...content.querySelectorAll('[data-configuration-panel]')];
    const select = section => {
      if(savingCount)return;
      active=section;
      content.querySelectorAll('[data-section]').forEach(button=>{button.classList.toggle('active',button.dataset.section===active);button.setAttribute('aria-pressed',String(button.dataset.section===active));});
      panels.forEach(panel=>panel.hidden=panel.dataset.configurationPanel!==active);
    };
    content.querySelectorAll('[data-section]').forEach(button=>button.onclick=()=>select(button.dataset.section));
    select(active);
    await Promise.all(panels.map(async target=>{
      const section=target.dataset.configurationPanel;
      const valid=()=>!disposed && (target.isConnected || viewCache.has(state.view) || viewCache.has('configuration'));
      target.innerHTML=loading();
      try{if(section==='templates')await loadTemplates(target,valid);else await loadConfiguration(section,target,valid);}
      catch(error){if(error.name!=='AbortError' && !disposed){target.innerHTML=empty('دریافت تنظیمات ناموفق بود')+`<button type="button" class="button secondary" data-retry>تلاش دوباره</button>`;target.querySelector('[data-retry]').onclick=()=>{target.innerHTML='';(section==='templates'?loadTemplates(target):loadConfiguration(section,target)).catch(e=>toast(e.message,'error'));};toast(error.message,'error');}}
    }));
  }
  function configurationField(field, value) {
    const name = escape(field.name), label = escape(field.label);
    const hint = field.description ? `<small class="plan-form-hint">${escape(field.description)}</small>` : '';
    if (field.type === 'boolean') return `<div class="switch-field full"><span>${label}${hint}</span><label class="switch-line ios"><input type="checkbox" name="${name}" aria-label="${label}" ${value ? 'checked' : ''}></label></div>`;
    if (field.type === 'select') return `<div class="field"><label for="config-${name}">${label}</label><select id="config-${name}" name="${name}">${Object.entries(field.options).map(([key,text])=>`<option value="${escape(key)}" ${Number(key) === Number(value) ? 'selected' : ''}>${escape(text)}</option>`).join('')}</select>${hint}</div>`;
    const secret = field.name === 'server_token';
    const input = `<input id="config-${name}" name="${name}" value="${escape(value ?? '')}" type="${secret ? 'password' : field.type === 'integer' ? 'number' : 'text'}" dir="ltr" ${field.type === 'integer' ? `required min="${field.min}" max="${field.max}" step="1"` : ''} ${secret ? 'autocomplete="new-password" spellcheck="false"' : ''}>`;
    return `<div class="field ${secret || ['subscribe_url','server_ws_url'].includes(field.name) ? 'full' : ''}"><label for="config-${name}">${label}</label>${secret ? `<div class="configuration-secret">${input}<button class="token-eye" type="button" id="toggleServerToken" aria-label="نمایش توکن"><i class="fas fa-eye" aria-hidden="true"></i></button><button class="token-generate" type="button" id="generateServerToken" aria-label="ساخت توکن جدید" title="ساخت توکن جدید"><i class="fas fa-sync-alt" aria-hidden="true"></i></button></div>` : input}</div>`;
  }
  async function loadConfiguration(section,target=content,valid=()=>true) {
    const {data,fields} = await api('/configuration/' + section);
    if(!valid()) return;
    state.settings = data;
    target.innerHTML = `<article class="panel-card configuration-card configuration-${section}"><form id="configurationForm-${section}"><div class="form-grid">${fields.map(field=>configurationField(field,data[field.name])).join('')}</div><div class="form-actions"><button class="button primary" type="submit"><i class="fas fa-save" aria-hidden="true"></i> ذخیره تنظیمات</button></div></form></article>`;
    const form = target.querySelector('form');
    form.oninput = () => { form.dataset.dirty='true'; };
    form.onchange = form.oninput;
    if (section === 'server') {
      const input = form.elements.server_token;
      target.querySelector('#toggleServerToken').onclick = event => {
        input.type = input.type === 'password' ? 'text' : 'password';
        const button = event.currentTarget;
        button.setAttribute('aria-label',input.type === 'text' ? 'پنهان کردن توکن' : 'نمایش توکن');
        button.innerHTML = `<i class="fas fa-${input.type === 'text' ? 'eye-slash' : 'eye'}" aria-hidden="true"></i>`;
      };
      target.querySelector('#generateServerToken').onclick = () => {
        if (input.value && !confirm('توکن جدید ساخته شود؟ پس از ذخیره، عامل‌های استفاده‌کننده از توکن عمومی باید به‌روزرسانی شوند.')) return;
        form.dataset.dirty='true';
        input.value = Array.from(crypto.getRandomValues(new Uint8Array(32)),value=>value.toString(16).padStart(2,'0')).join('');
      };
    }
    form.onsubmit = async event => {
      event.preventDefault();
      if(form.dataset.busy==='true') return;
      const body = {};
      fields.forEach(field=>{
        const control = form.elements[field.name];
        body[field.name] = field.type === 'boolean' ? control.checked : ['integer','select'].includes(field.type) ? Number(control.value) : control.value.trim() || null;
      });
      const submit = form.querySelector('[type=submit]');
      submit.disabled = true; form.dataset.busy='true';
      [...form.elements].forEach(control=>control.disabled=true);
      try {
        const result = await api('/configuration/' + section,{method:'PUT',body:JSON.stringify(body)});
        state.settings = result.data; form.dataset.dirty='false'; toast(result.message);
      } catch (error) { if (error.name !== 'AbortError') toast(error.message,'error'); }
      finally { [...form.elements].forEach(control=>control.disabled=false); form.dataset.busy='false'; }
    };
  }
  async function loadTemplates(target=content,valid=()=>true) {
    const {data} = await api('/configuration/templates');
    if(!valid()) return;
    let current = data[0], saved = current.content;
    target.innerHTML = `<article class="panel-card configuration-card template-card"><form id="templateForm"><nav class="segmented template-tabs" aria-label="قالب سابسکرایب">${data.map(item=>`<button class="seg-btn ${item.name===current.name?'active':''}" type="button" data-template="${escape(item.name)}" aria-pressed="${item.name===current.name}">${escape(item.label)}</button>`).join('')}</nav><div class="field"><label for="templateContent">محتوای قالب</label><textarea id="templateContent" name="content" dir="ltr" spellcheck="false" autocapitalize="off" required>${escape(current.content)}</textarea></div><div class="form-actions"><button class="button primary" type="submit"><i class="fas fa-save" aria-hidden="true"></i> ذخیره قالب</button><button class="button secondary" type="button" id="resetTemplate">بازیابی قالب پیش‌فرض</button></div></form></article>`;
    const form = target.querySelector('#templateForm'), editor = target.querySelector('#templateContent'), selectors = [...target.querySelectorAll('[data-template]')], reset = target.querySelector('#resetTemplate');
    let busy = false;
    editor.oninput = () => { form.dataset.dirty = String(editor.value !== saved); };
    selectors.forEach(button=>button.onclick=()=>{
      if(button.dataset.template===current.name || busy) return;
      if(editor.value!==saved && !confirm('تغییرات ذخیره‌نشده کنار گذاشته شود؟')) return;
      current=data.find(item=>item.name===button.dataset.template); saved=current.content; editor.value=saved; form.dataset.dirty='false';
      selectors.forEach(item=>{item.classList.toggle('active',item===button);item.setAttribute('aria-pressed',String(item===button));});
    });
    const setBusy = value => {
      busy=value; form.dataset.busy=String(value); selectors.forEach(button=>button.disabled=value);
      editor.readOnly=value; form.querySelector('[type=submit]').disabled=value; reset.disabled=value;
    };
    form.onsubmit = async event => {
      event.preventDefault(); if (busy) return; setBusy(true);
      try {
        const result = await api('/configuration/templates/' + current.name,{method:'PUT',body:JSON.stringify({content:editor.value})});
        current.content = editor.value; saved = current.content; form.dataset.dirty='false'; toast(result.message);
      } catch (error) { if (error.name !== 'AbortError') toast(error.message,'error'); }
      finally { setBusy(false); }
    };
    reset.onclick = async () => {
      if (busy || !confirm('قالب انتخاب‌شده با نسخه پیش‌فرض جایگزین شود؟')) return;
      setBusy(true);
      try {
        const result = await api('/configuration/templates/' + current.name,{method:'DELETE'});
        current.content = result.data.content; saved = current.content; editor.value = saved; form.dataset.dirty='false'; toast(result.message);
      } catch (error) { if (error.name !== 'AbortError') toast(error.message,'error'); }
      finally { setBusy(false); }
    };
  }
  function field(name,label,value='',required=false,type='text',dir='',step=''){return `<div class="field"><label>${escape(label)}</label><input name="${name}" value="${escape(value)}" type="${type}" ${required?'required':''} ${dir?`dir="${dir}"`:''} ${step?`step="${step}"`:''}></div>`}

  function enhanceControls(container) {
    const invoiceScope = getComputedStyle(root).getPropertyValue('--speedbox-invoice-scope').trim() || 'data-v-f8294ed3';
    container.querySelectorAll('input:not([type=checkbox]),textarea').forEach(input=>{ input.dir=input.classList.contains('search')?'auto':'ltr'; if(input.type==='number')input.inputMode=input.step.includes('.')?'decimal':'numeric'; });
    container.querySelectorAll('input:not([type=checkbox]), textarea, select').forEach(el => el.classList.add('form-input'));
    container.querySelectorAll('select:not([data-enhanced])').forEach(select => {
      select.dataset.enhanced = 'true';
      const wrap = document.createElement('div');
      const protocolPicker=select.hasAttribute('data-protocol-picker');
      wrap.className = 'invoice-custom-select'+(protocolPicker?' protocol-select':'');
      wrap.setAttribute(invoiceScope,'');
      select.before(wrap); wrap.appendChild(select);
      select.classList.add('infrastructure-native-select');
      select.setAttribute('aria-hidden','true');
      select.tabIndex=-1;
      const trigger = document.createElement('button');
      trigger.setAttribute(invoiceScope,'');
      trigger.type='button'; trigger.className='invoice-custom-select__trigger';
      trigger.setAttribute('aria-haspopup','listbox'); trigger.setAttribute('aria-expanded','false');
      const label = document.createElement('span'); const caret = document.createElement('i');
      label.setAttribute(invoiceScope,''); caret.setAttribute(invoiceScope,'');
      caret.className='fas fa-chevron-down'; caret.setAttribute('aria-hidden','true'); trigger.append(label,caret);
      const menu=document.createElement('div'); menu.className='invoice-custom-select__menu'; menu.role='listbox'; menu.hidden=true;
      menu.setAttribute(invoiceScope,'');
      if(select.multiple)menu.setAttribute('aria-multiselectable','true');
      const accessibleName = select.getAttribute('aria-label') || select.closest('.field')?.querySelector('label')?.textContent || select.name;
      trigger.setAttribute('aria-label',accessibleName); menu.setAttribute('aria-label',accessibleName);
      const update=()=>{
        label.textContent=[...select.selectedOptions].map(o=>o.textContent).join('، ') || 'انتخاب کنید';
        const color=select.selectedOptions[0]?.dataset.color;
        if(protocolPicker){
          wrap.style.setProperty('--protocol-color',color||'#555');
          wrap.style.setProperty('--protocol-ink',['trojan','tuic','mieru'].includes(select.value)?'#101010':'#fff');
          label.dir='ltr';
        }else if(color){label.innerHTML=window.SpeedboxNodeEditor.protocolBadge(select.value);}
        trigger.disabled=select.disabled;
        menu.replaceChildren();
        [...select.options].forEach(option=>{
          const button=document.createElement('button'); button.type='button'; button.role='option';
          button.setAttribute(invoiceScope,'');
          button.className='invoice-custom-select__option'+(option.selected?' is-selected':'');
          button.textContent=option.textContent; button.disabled=option.disabled;
          if(protocolPicker){
            button.style.setProperty('--protocol-color',option.dataset.color||'#555');
            button.style.setProperty('--protocol-ink',['trojan','tuic','mieru'].includes(option.value)?'#101010':'#fff');
            button.dir='ltr';
          }else if(option.dataset.color){button.innerHTML=window.SpeedboxNodeEditor.protocolBadge(option.value);}
          button.setAttribute('aria-selected',String(option.selected));
          button.onclick=()=>{
            if(select.multiple)option.selected=!option.selected; else select.value=option.value;
            select.dispatchEvent(new Event('change',{bubbles:true}));
            if(!select.multiple)closeSelect(wrap);
            else menu.children[[...select.options].indexOf(option)]?.focus();
          }; menu.appendChild(button);
        });
        if(select.hasAttribute('data-allow-custom')){
          const search=document.createElement('input');search.type='text';search.className='form-input select-cipher-search';search.dir='ltr';search.placeholder='جستجو یا رمزنگاری سفارشی';search.setAttribute('aria-label','جستجو یا وارد کردن رمزنگاری سفارشی');
          const custom=document.createElement('button');custom.type='button';custom.className='invoice-custom-select__option';custom.hidden=true;
          const options=[...menu.children];search.oninput=()=>{const q=search.value.trim();options.forEach(b=>b.hidden=!b.textContent.toLowerCase().includes(q.toLowerCase()));custom.hidden=!q||[...select.options].some(o=>o.value===q);custom.textContent='استفاده از '+q;};
          custom.onclick=()=>{const value=search.value.trim();if(!value)return;const option=new Option(value,value,true,true);select.appendChild(option);select.dispatchEvent(new Event('change',{bubbles:true}));closeSelect(wrap);trigger.focus({preventScroll:true});};
          search.onkeydown=e=>{if(e.key==='Enter'){e.preventDefault();if(!custom.hidden)custom.click();else options.find(b=>!b.hidden)?.click();}};
          menu.prepend(search);menu.appendChild(custom);
        }
      };
      trigger.onclick=()=>{
        const open=menu.hidden;
        closeAllSelects();
        if(open){wrap.classList.add('is-open');menu.hidden=false;trigger.setAttribute('aria-expanded','true');menu.querySelector('.is-selected,button')?.focus();}
      };
      trigger.onkeydown=e=>{if(e.key==='ArrowDown'){e.preventDefault();if(menu.hidden)trigger.click();else menu.querySelector('button')?.focus({preventScroll:true});}};
      menu.onkeydown=e=>{
        const items=[...menu.querySelectorAll('button:not(:disabled)')].filter(button=>!button.hidden); const index=items.indexOf(document.activeElement);
        if(e.key==='ArrowDown'||e.key==='ArrowUp'){e.preventDefault();items[(index+(e.key==='ArrowDown'?1:-1)+items.length)%items.length]?.focus();}
        if(e.key==='Escape'){e.preventDefault();e.stopPropagation();closeSelect(wrap);trigger.focus({preventScroll:true});}
      };
      select.addEventListener('change',update);
      select.addEventListener('invalid',()=>{if(!mobileMedia.matches)trigger.focus();wrap.classList.add('is-invalid');});
      wrap.append(trigger,menu);
      if(select.hasAttribute('data-allow-custom')){
        const custom=document.createElement('input');custom.className='form-input speedbox-mobile-custom';custom.placeholder='رمزنگاری سفارشی';custom.setAttribute('aria-label','رمزنگاری سفارشی');custom.dir='ltr';
        custom.onchange=()=>{const value=custom.value.trim();if(!value)return;if(![...select.options].some(option=>option.value===value))select.add(new Option(value,value));select.value=value;select.dispatchEvent(new Event('change',{bubbles:true}));custom.value='';};wrap.append(custom);
      }
      update();
    });
    updateNativeSelects();
  }
  const mobileMedia = window.matchMedia('(max-width: 767px)');
  function updateNativeSelects(){
    scope.querySelectorAll('.infrastructure-native-select').forEach(select=>{
      const trigger=select.parentElement.querySelector('.invoice-custom-select__trigger');
      if(mobileMedia.matches){
        select.removeAttribute('aria-hidden');select.removeAttribute('tabindex');
        trigger?.setAttribute('aria-hidden','true');if(trigger)trigger.tabIndex=-1;
        if(select.parentElement.classList.contains('is-open'))closeSelect(select.parentElement);
      }else{
        select.setAttribute('aria-hidden','true');select.tabIndex=-1;
        trigger?.removeAttribute('aria-hidden');trigger?.removeAttribute('tabindex');
      }
    });
    if(mobileMedia.matches)closeAllSelects();
  }
  mobileMedia.addEventListener('change',updateNativeSelects);
  function closeSelect(wrap){wrap.classList.remove('is-open');wrap.querySelector('[role=listbox]').hidden=true;wrap.querySelector('.invoice-custom-select__trigger').setAttribute('aria-expanded','false');}
  function closeAllSelects(){scope.querySelectorAll('.invoice-custom-select.is-open').forEach(closeSelect);}
  const closeActionMenus=except=>scope.querySelectorAll('details.tunnel-actions[open],details[data-panel-menu][open]').forEach(menu=>{if(!except?.includes(menu))menu.open=false;});
  const onOutsideSelect=e=>{closeActionMenus(e.composedPath());const path=e.composedPath();if(!path.some(el=>el.classList?.contains('invoice-custom-select')))closeAllSelects();if(calendarSession&&!path.includes(calendarSession.picker))closeRankCalendar();};
  const onEscape=e=>{if(e.key==='Escape'){const menu=scope.querySelector('details.tunnel-actions[open],details[data-panel-menu][open]');if(menu){e.preventDefault();menu.open=false;menu.querySelector('summary')?.focus({preventScroll:true});return;}const open=scope.querySelector('.invoice-custom-select.is-open');if(open){closeSelect(open);open.querySelector('button').focus();}else if(calendarSession){e.preventDefault();closeRankCalendar(true);}else if(!modalBackdrop.hidden)closeModal();}};
  document.addEventListener('click',onOutsideSelect); document.addEventListener('keydown',onEscape);
  const selectObserver=new MutationObserver(()=>{enhanceControls(root);enhanceControls(overlay);});
  selectObserver.observe(root,{childList:true,subtree:true});selectObserver.observe(overlay,{childList:true,subtree:true});
  enhanceControls(root);enhanceControls(overlay);

  function switchView(view) {
    router.push(view === 'overview' ? '/admin/infrastructure' : `/admin/infrastructure/${view}`);
  }
  function showView(view) {
    if (disposed) return;
    const selected = titles[view] ? view : 'overview';
    emergencySession?.dispose(); emergencySession=null;
    tunnelSession?.dispose(); tunnelSession=null;
    cancelServerRefresh(); serverFetchVersion++;cancelNodeRefresh();nodeFetchVersion++;
    if(content.children.length && state.view) viewCache.set(state.view,[...content.childNodes]);
    content.replaceChildren(...(viewCache.get(selected)||[]));
    requests.abort(); Object.values(rankRequests).forEach(controller => controller?.abort()); requests = new AbortController();
    closeRankCalendar(); closeModal(true); closeAllSelects(); hideRankTooltip();
    state.view = selected;
    scope.getElementById('pageTitle').textContent = titles[selected];
    loadView();
  }
  showView(state.view);
  return {
    showView,
    dispose() {
      closeRankCalendar();
      nodeEditorDispose?.();nodeEditorDispose=null;
      emergencySession?.dispose(); emergencySession=null;
    tunnelSession?.dispose(); tunnelSession=null;
      disposed=true; viewCache.clear(); requests.abort(); Object.values(rankRequests).forEach(controller => controller?.abort()); timers.forEach(clearTimeout); selectObserver.disconnect();
      document.removeEventListener('click', onOutsideSelect); document.removeEventListener('keydown', onEscape);
      window.removeEventListener('scroll',hideRankTooltip,true); window.removeEventListener('resize',hideRankTooltip); rankTooltip.remove();
      root.removeEventListener('pointerover',entityTooltipEvent);root.removeEventListener('focusin',entityTooltipEvent);root.removeEventListener('pointerout',leaveEntityTooltip);root.removeEventListener('focusout',leaveEntityTooltip);document.removeEventListener('click',entityTooltipEvent);window.removeEventListener('scroll',hideEntityTooltip,true);window.removeEventListener('resize',hideEntityTooltip);hideEntityTooltip();entityTooltip.remove();
      mobileMedia.removeEventListener('change',updateNativeSelects);
      overlay.remove(); document.body.style.overflow='';
    }
  };
}};




