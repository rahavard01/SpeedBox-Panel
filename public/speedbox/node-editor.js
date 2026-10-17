/* Node form contract and transport presets mirror the bundled XBoard admin. */
window.SpeedboxNodeEditor = (() => {
  const protocols = [
    ['shadowsocks','Shadowsocks','#489851'],['vmess','VMess','#CB3180'],['trojan','Trojan','#EBB749'],
    ['hysteria','Hysteria','#5684e6'],['vless','VLESS','#1a1a1a'],['tuic','TUIC','#00C853'],
    ['socks','SOCKS','#2196F3'],['naive','Naive','#9C27B0'],['http','HTTP','#FF5722'],
    ['mieru','Mieru','#4CAF50'],['anytls','AnyTLS','#7E57C2']
  ];
  const templates = {
    tcp:{label:'TCP',content:{acceptProxyProtocol:false,header:{type:'none'}}},
    'tcp-http':{label:'TCP + HTTP',content:{acceptProxyProtocol:false,header:{type:'http',request:{version:'1.1',method:'GET',path:['/'],headers:{Host:['www.example.com']}},response:{version:'1.1',status:'200',reason:'OK'}}}},
    grpc:{label:'gRPC',content:{serviceName:'GunService'}},
    ws:{label:'WebSocket',content:{path:'/',headers:{Host:'v2ray.com'}}},
    h2:{label:'HTTP/2',content:{path:'/',host:['www.google.com']}},
    httpupgrade:{label:'HttpUpgrade',content:{acceptProxyProtocol:false,path:'/',host:'xray.com',headers:{key:'value'}}},
    xhttp:{label:'XHTTP',content:{host:'example.com',path:'/yourpath',mode:'auto',extra:{headers:{},xPaddingBytes:'100-1000',noGRPCHeader:false,noSSEHeader:false,scMaxEachPostBytes:1000000,scMinPostsIntervalMs:30,scMaxBufferedPosts:30,xmux:{maxConcurrency:'16-32',maxConnections:0,cMaxReuseTimes:'64-128',cMaxLifetimeMs:0,hMaxRequestTimes:'800-900',hKeepAlivePeriod:0},downloadSettings:{address:'',port:443,network:'xhttp',security:'tls',tlsSettings:{},xhttpSettings:{path:'/yourpath'},sockopt:{}}}}}
  };
  const padding = ['stop=8','0=30-30','1=100-400','2=400-500,c,500-1000,c,500-1000,c,500-1000,c,500-1000','3=9-9,500-1000','4=500-1000','5=500-1000','6=500-1000','7=500-1000'];
  const clone = value => JSON.parse(JSON.stringify(value));
  const esc = value => String(value ?? '').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const get = (obj,path,fallback='') => path.split('.').reduce((o,k)=>o?.[k],obj) ?? fallback;
  const set = (obj,path,value) => { const keys=path.split('.'); const key=keys.pop(); let o=obj; keys.forEach(k=>{if(!o[k]||typeof o[k]!=='object')o[k]={};o=o[k];});o[key]=value; };
  const merge = (defaults, saved) => { const result=clone(defaults); Object.entries(saved||{}).forEach(([k,v])=>{result[k]=v&&typeof v==='object'&&!Array.isArray(v)&&result[k]&&typeof result[k]==='object'&&!Array.isArray(result[k])?merge(result[k],v):v;});return result; };
  function protocolBadge(type) {
    const protocol=protocols.find(p=>p[0]===type);
    const color=protocol?.[2]||'#555';
    const ink=['trojan','tuic','mieru'].includes(type)?'#101010':'#fff';
    return `<span class="protocol-badge" style="--protocol-color:${color};--protocol-ink:${ink}" dir="ltr">${esc(protocol?.[1]||type)}</span>`;
  }
  function defaults(type) {
    const ech={enabled:false,config:'',query_server_name:'',key:''};
    const tls={server_name:'',allow_insecure:false,ech};
    const base={routing:{}};
    if(type==='shadowsocks')return {...base,cipher:'aes-128-gcm',plugin:'',plugin_opts:'',client_fingerprint:'chrome'};
    if(type==='mieru')return {...base,transport:'TCP',traffic_pattern:''};
    if(type==='socks')return base;
    if(type==='hysteria')return {...base,version:2,alpn:'h2',obfs:{open:false,type:'salamander',password:''},tls:{...tls,pinned_peer_cert_sha256:''},bandwidth:{up:0,down:0}};
    if(type==='tuic')return {...base,version:5,congestion_control:'bbr',alpn:['h3'],udp_relay_mode:'native',tls};
    if(type==='anytls')return {...base,tls,padding_scheme:[]};
    if(['http','naive'].includes(type))return {...base,tls:0,tls_settings:tls};
    return {...base,tls:type==='trojan'?1:0,tls_settings:tls,utls:{enabled:false,fingerprint:'chrome'},network:'tcp',network_settings:{},multiplex:{enabled:false,protocol:'smux',max_connections:4,padding:false,brutal:{enabled:false,up_mbps:100,down_mbps:100}},
      ...(type==='vmess'?{}:{reality_settings:{server_port:443,server_name:'',allow_insecure:false,public_key:'',private_key:'',short_id:'',...(type==='vless'?{fingerprint:'chrome'}:{})}}),
      ...(type==='vless'?{flow:'',encryption:{enabled:false,encryption:'',decryption:''}}:{})};
  }
  function mount({node,state,api,openModal,closeModal,toast,reload,enhanceControls,isSaving}) {
    const draft=merge({name:'',type:'shadowsocks',rate:1,rate_time_enable:false,rate_time_ranges:[],transfer_enable:0,code:'',tags:[],group_ids:[],route_ids:[],parent_id:null,machine_id:null,kernel:'singbox',host:'',port:'',server_port:'',show:false,enabled:true,custom_outbounds:[],custom_routes:[],cert_config:null},node);
    ['rate_time_ranges','tags','group_ids','route_ids','custom_outbounds','custom_routes'].forEach(key=>{if(!Array.isArray(draft[key]))draft[key]=[];});
    draft.protocol_settings=merge(defaults(draft.type),node?.protocol_settings);
    if(!draft.cert_config&&draft.protocol_settings.cert_config)draft.cert_config=clone(draft.protocol_settings.cert_config);
    const cache=new Map([[draft.type,clone(draft.protocol_settings)]]);
    let disposed=false, sub=null;
    const text=(path,label,value,opts={})=>`<div class="field ${opts.wide?'full':''}"><label for="ne-${esc(path)}">${label}</label><input id="ne-${esc(path)}" data-path="${esc(path)}" value="${esc(value)}" ${opts.required?'required':''} type="${opts.number?'number':'text'}" ${opts.number?`step="${opts.step||'1'}" min="${opts.min??0}"`:''} ${opts.placeholder?`placeholder="${esc(opts.placeholder)}"`:''}></div>`;
    const select=(path,label,value,options,{multiple=false,number=false,wide=false,custom=false,protocolPicker=false}={})=>{
      const choices=[...options];if(custom&&value&&!choices.some(o=>String(Array.isArray(o)?o[0]:o)===String(value)))choices.unshift([value,value]);
      return `<div class="field ${wide?'full':''}">${protocolPicker?'':`<label>${label}</label>`}<select data-path="${esc(path)}" ${protocolPicker?`data-protocol-picker aria-label="${esc(label)}"`:""} ${custom?'data-allow-custom':''} ${number?'data-number':''} ${multiple?'multiple':''}>${choices.map(o=>{const [v,l,color]=Array.isArray(o)?o:[o,o];return `<option value="${esc(v)}" ${color?`data-color="${color}"`:''} ${(multiple?(value||[]).map(String).includes(String(v)):String(value??'')===String(v))?'selected':''}>${esc(l)}</option>`;}).join('')}</select></div>`;
    };
    const toggle=(path,label,value)=>`<div class="switch-field"><span>${label}</span><label class="switch-line ios"><input type="checkbox" data-path="${esc(path)}" aria-label="${label}" ${value?'checked':''}></label></div>`;
    const area=(path,label,value,format='text')=>`<div class="field full"><label>${label}</label><textarea data-path="${esc(path)}" data-format="${format}" dir="ltr">${esc(format==='lines'?(value||[]).join('\n'):format==='json'?JSON.stringify(value??{},null,2):format==='env'?Object.entries(value||{}).map(([key,v])=>`${key}=${v??''}`).join('\n'):value)}</textarea></div>`;
    const pf=(path,label,opts={})=>text('protocol_settings.'+path,label,get(draft.protocol_settings,path),opts);
    const ps=(path,label,options,opts={})=>select('protocol_settings.'+path,label,get(draft.protocol_settings,path),options,opts);
    const pt=(path,label)=>toggle('protocol_settings.'+path,label,get(draft.protocol_settings,path));
    const pa=(path,label,format)=>area('protocol_settings.'+path,label,get(draft.protocol_settings,path,format==='lines'?[]:''),format);
    openModal(node?'ویرایش نود':'ساخت نود',`<form id="nodeEditorForm"><div class="node-protocol-head"></div><div class="node-editor-scroll modal-fields"><div class="node-common form-grid"></div><div class="node-protocol-fields form-grid"></div><div class="node-assignment form-grid"></div></div><div class="form-actions node-editor-footer"><button type="button" class="button secondary" data-advanced>تنظیمات پیشرفته</button><span class="node-footer-spacer"></span><button type="button" class="button cancel" data-close>انصراف</button><button class="button primary" type="submit">${node?'ذخیره تغییرات':'ساخت نود'}</button></div></form>`,{node:true});
    const form=document.getElementById('nodeEditorForm');
    const host=form.closest('.infrastructure-modal');
    const bind = (container,target,onChange) => {
      container.querySelectorAll('[data-path]').forEach(input=>{
        const update=()=>{
          try {
            let value=input.type==='checkbox'?input.checked:input.multiple?[...input.selectedOptions].map(o=>['group_ids','route_ids'].includes(input.dataset.path)?Number(o.value):o.value):input.value;
            if(input.dataset.format==='lines')value=input.value.split(/\r?\n/).map(x=>x.trim()).filter(Boolean);
            if(input.dataset.format==='json')value=JSON.parse(input.value||'{}');
            if(input.dataset.format==='env'){value={};input.value.split(/\r?\n/).map(line=>line.trim()).filter(Boolean).forEach(line=>{const i=line.indexOf('=');if(i<1)throw Error();value[line.slice(0,i).trim()]=line.slice(i+1).trim();});}
            if(input.type==='number'||input.hasAttribute('data-number'))value=input.value===''?null:Number(input.value);
            set(target,input.dataset.path,value);input.setCustomValidity('');onChange?.(input);
          }catch(_){input.setCustomValidity('ساختار JSON معتبر نیست.');}
        };
        input.addEventListener('input',update);input.addEventListener('change',update);
      });
      enhanceControls(container);
    };
    function common() {
      form.querySelector('.node-common').innerHTML=`<div class="node-row name-rate">${text('name','نام نود',draft.name,{required:true})}${text('rate','ضریب پایه',draft.rate,{number:true,step:'0.01'})}</div>
        ${toggle('rate_time_enable','ضریب ترافیک زمان‌بندی‌شده',draft.rate_time_enable)}<div class="node-rate-ranges full" ${draft.rate_time_enable?'':'hidden'}></div>
        <div class="node-row">${text('transfer_enable_gb','محدودیت ترافیک (GB)',draft.transfer_enable?(draft.transfer_enable/1073741824).toFixed(2):'',{number:true,step:'0.01',placeholder:'0 = نامحدود'})}${text('code','شناسه سفارشی نود',draft.code)}</div>
        ${area('tags','تگ نود',draft.tags,'lines')}
        ${select('group_ids','گروه‌های دسترسی',draft.group_ids,state.groups.map(g=>[g.id,g.name]),{multiple:true,wide:true})}
        ${text('host','آدرس نود',draft.host,{required:true,wide:true,placeholder:'example.com'})}
        <div class="node-row">${text('port','پورت اتصال',draft.port,{required:true})}${text('server_port','پورت سرور',draft.server_port,{number:true,min:1,required:true})}</div>
        <button type="button" class="button secondary small node-sync-port" data-sync-port>همگام‌سازی پورت سرور با پورت اتصال</button>`;
      bind(form.querySelector('.node-common'),draft,input=>{
        if(input.dataset.path==='transfer_enable_gb')draft.transfer_enable=Math.round(Number(input.value||0)*1073741824);
        if(input.dataset.path==='rate_time_enable'){form.querySelector('.node-rate-ranges').hidden=!input.checked;}
      });
      form.querySelector('[data-sync-port]').onclick=()=>{const port=String(draft.port);if(!/^\d+$/.test(port)||+port<1||+port>65535){toast('برای همگام‌سازی، یک پورت اتصال بین ۱ و ۶۵۵۳۵ وارد کنید.','error');return;}draft.server_port=Number(port);form.querySelector('[data-path="server_port"]').value=port;};
      ranges();
    }
    function ranges() {
      const target=form.querySelector('.node-rate-ranges');
      target.innerHTML=draft.rate_time_ranges.map((r,i)=>`<div class="node-rate-row" data-range="${i}"><input type="time" data-key="start" value="${esc(r.start)}" required aria-label="شروع بازه"><input type="time" data-key="end" value="${esc(r.end)}" required aria-label="پایان بازه"><input type="number" min="0" step="0.01" data-key="rate" value="${esc(r.rate)}" required aria-label="ضریب ترافیک"><button type="button" class="button entity-delete" data-remove-range="${i}">حذف</button></div>`).join('')+'<button type="button" class="button secondary small" data-add-range>افزودن بازه</button>';
      target.querySelectorAll('[data-range] input').forEach(input=>input.oninput=()=>{draft.rate_time_ranges[Number(input.closest('[data-range]').dataset.range)][input.dataset.key]=input.dataset.key==='rate'?Number(input.value):input.value;});
      target.querySelectorAll('[data-remove-range]').forEach(b=>b.onclick=()=>{draft.rate_time_ranges.splice(Number(b.dataset.removeRange),1);ranges();});
      target.querySelector('[data-add-range]').onclick=()=>{draft.rate_time_ranges.push({start:'00:00',end:'23:59',rate:1});ranges();};enhanceControls(target);
    }
    function assignment() {
      const parents=state.nodes.filter(n=>!n.parent_id&&n.id!==node?.id&&n.type===draft.type);
      if(draft.parent_id&&!parents.some(n=>n.id==draft.parent_id))draft.parent_id=null;
      const parent=parents.find(n=>n.id==draft.parent_id);
      if(parent){draft.machine_id=parent.machine_id;draft.kernel=parent.kernel;draft.rate=parent.rate;form.querySelector('[data-path=rate]').value=parent.rate;}
      form.querySelector('[data-path=rate]').disabled=!!parent;
      form.querySelector('.node-assignment').innerHTML=`${select('parent_id','نود والد',draft.parent_id??'', [['','بدون والد'],...parents.map(n=>[n.id,n.name])],{number:true,wide:true})}
        ${select('route_ids','قواعد مسیریابی',draft.route_ids,state.routes.map(r=>[r.id,r.remarks]),{multiple:true,wide:true})}
        <div class="node-row">${select('machine_id','سرور مقصد',draft.machine_id??'',[['','بدون سرور'],...state.machines.filter(m=>m.is_active!==false).map(m=>[m.id,m.name])],{number:true})}${select('kernel','هسته',draft.kernel,[['singbox','Sing-box'],['xray','Xray']])}</div>
        ${toggle('enabled','فعال بودن نود',draft.enabled)}${toggle('show','نمایش در سابسکرایب',draft.show)}`;
      if(parent)form.querySelectorAll('[data-path="machine_id"],[data-path="kernel"]').forEach(el=>el.disabled=true);
      bind(form.querySelector('.node-assignment'),draft,input=>{if(input.dataset.path==='parent_id')assignment();});
    }
    function ech(prefix) {
      return `${pt(prefix+'.enabled','فعال‌سازی ECH')}${get(draft.protocol_settings,prefix+'.enabled')?`<div class="node-ech-fields full"><div class="node-row">${pf(prefix+'.query_server_name','نام عمومی ECH',{placeholder:'ech.example.com'})}<button type="button" class="button secondary" data-generate-ech="${prefix}">تولید کلید ECH</button></div>${pa(prefix+'.config','پیکربندی ECH')}${pa(prefix+'.key','کلید خصوصی ECH')}</div>`:''}`;
    }
    function tls(prefix, pinned=false) {
      return `<div class="node-row node-tls-row">${pf(prefix+'.server_name','نام سرور TLS (SNI)')}${pt(prefix+'.allow_insecure','اجازه گواهی نامعتبر')}</div>${pinned?`<div class="node-remote-field full">${pf(prefix+'.pinned_peer_cert_sha256','اثر انگشت گواهی (SHA-256)')}<button type="button" class="button secondary" data-certificate-fingerprint>دریافت از سرور</button></div>`:''}${ech(prefix+'.ech')}`;
    }
    function utls() {return pt('utls.enabled','اثر انگشت مرورگر (uTLS)')+(get(draft.protocol_settings,'utls.enabled')?ps('utls.fingerprint','اثر انگشت',['chrome','firefox','safari','ios','edge','random']):'');}
    function reality() {
      return `<div class="node-row name-rate">${pf('reality_settings.server_name','مقصد Reality')}${pf('reality_settings.server_port','پورت مقصد',{number:true,min:1})}</div>${pt('reality_settings.allow_insecure','اجازه گواهی نامعتبر مقصد')}
        ${pf('reality_settings.public_key','کلید عمومی',{wide:true})}${pf('reality_settings.private_key','کلید خصوصی',{wide:true})}<button type="button" class="button secondary small" data-reality>تولید کلیدهای Reality</button>
        ${pf('reality_settings.short_id','شناسه کوتاه',{wide:true})}<button type="button" class="button secondary small" data-short-id>تولید شناسه کوتاه</button>${draft.type==='vless'?ps('reality_settings.fingerprint','اثر انگشت Reality',['chrome','firefox','safari','ios','edge','random']):''}`;
    }
    function protocol() {
      const p=draft.protocol_settings, type=draft.type;
      let html='';
      if(['vmess','vless','trojan'].includes(type)) {
        html=ps('tls','امنیت',type==='trojan'?[[1,'TLS'],[2,'Reality']]:type==='vless'?[[0,'بدون TLS'],[1,'TLS'],[2,'Reality']]:[[0,'بدون TLS'],[1,'TLS']],{number:true});
        if(Number(p.tls)===1)html+=tls('tls_settings');
        if(Number(p.tls)===2)html+=reality();
        html+=utls();
        html+=`<div class="node-row transport-row">${ps('network','پروتکل انتقال',['tcp','ws','grpc','h2',...(type==='vless'?['kcp']:[]),'httpupgrade','xhttp'].map(v=>[v,({tcp:'TCP',ws:'WebSocket',grpc:'gRPC',h2:'HTTP/2',kcp:'mKCP',httpupgrade:'HttpUpgrade',xhttp:'XHTTP'})[v]]))}<button type="button" class="button secondary" data-network>ویرایش پروتکل انتقال</button></div>`;
        if(type==='vless')html+=ps('flow','کنترل جریان',[['','بدون جریان'],...['xtls-rprx-direct','xtls-rprx-splice','xtls-rprx-vision'].map(v=>[v,v])])+pt('encryption.enabled','رمزنگاری VLESS')+(get(p,'encryption.enabled')?pf('encryption.encryption','رمزنگاری سمت کاربر',{wide:true})+pf('encryption.decryption','رمزگشایی سمت سرور',{wide:true})+'<button type="button" class="button secondary small" data-vless-encryption>تولید از سرور</button>':'');
      }
      if(type==='shadowsocks') {
        html=ps('cipher','رمزنگاری',['aes-128-gcm','aes-192-gcm','aes-256-gcm','chacha20-ietf-poly1305','2022-blake3-aes-128-gcm','2022-blake3-aes-256-gcm','2022-blake3-chacha20-poly1305'],{custom:true});
        html+=ps('plugin','افزونه',[['','بدون افزونه'],['obfs','Simple Obfs'],['v2ray-plugin','V2Ray Plugin'],['gost-plugin','Gost Plugin'],['shadow-tls','Shadow TLS'],['restls','ResTLS'],['kcptun','KCPTun']]);
        if(p.plugin&&p.plugin!=='none')html+=pf('plugin_opts','تنظیمات افزونه',{wide:true});
        if(['shadow-tls','restls'].includes(p.plugin))html+=ps('client_fingerprint','اثر انگشت کاربر',['chrome','firefox','safari','ios']);
      }
      if(type==='hysteria') {
        html=ps('version','نسخه',[[1,'Hysteria 1'],[2,'Hysteria 2']],{number:true});
        if(Number(p.version)===1)html+=ps('alpn','ALPN',['hysteria','http/1.1','h2','h3']);
        html+=pt('obfs.open','مبهم‌سازی ترافیک');
        if(get(p,'obfs.open'))html+=(Number(p.version)===2?ps('obfs.type','نوع مبهم‌سازی',['salamander']):'')+pf('obfs.password','رمز مبهم‌سازی',{wide:true})+'<button type="button" class="button secondary small" data-obfs-password>تولید رمز</button>';
        html+=tls('tls',true)+`<div class="node-row">${pf('bandwidth.up','سرعت ارسال (Mbps)',{number:true,placeholder:'0 = BBR'})}${pf('bandwidth.down','سرعت دریافت (Mbps)',{number:true,placeholder:'0 = BBR'})}</div>${pf('hop_interval','فاصله پرش پورت (ثانیه)',{number:true})}${pf('port_range','بازه پورت (اختیاری)',{placeholder:'10000-20000'})}`;
      }
      if(type==='tuic')html=ps('version','نسخه',[[5,'TUIC 5'],[4,'TUIC 4']],{number:true})+ps('congestion_control','کنترل ازدحام',['bbr','cubic','new_reno'])+tls('tls')+ps('alpn','ALPN',[['h3','HTTP/3'],['h2','HTTP/2'],['http/1.1','HTTP/1.1']],{multiple:true})+ps('udp_relay_mode','حالت انتقال UDP',['native','quic']);
      if(type==='anytls')html=tls('tls')+pa('padding_scheme','الگوی بسته‌ها (هر خط یک قاعده)','lines')+'<button type="button" class="button secondary small" data-padding>استفاده از الگوی پیش‌فرض</button>';
      if(['http','naive'].includes(type))html=ps('tls','امنیت',[[0,'بدون TLS'],[1,'TLS']],{number:true})+(Number(p.tls)===1?tls('tls_settings'):'');
      if(type==='mieru')html=ps('transport','پروتکل انتقال',['TCP','UDP'])+pf('traffic_pattern','الگوی ترافیک',{wide:true})+'<button type="button" class="button secondary small" data-pattern>تولید الگوی ترافیک</button>';
      const target=form.querySelector('.node-protocol-fields');target.innerHTML=html;
      bind(target,draft,input=>{
        // Redraw only dependencies. Text entry never destroys focus or selection.
        if(['tls','version','plugin','obfs.open','utls.enabled','encryption.enabled','tls.ech.enabled','tls_settings.ech.enabled'].some(path=>input.dataset.path==='protocol_settings.'+path))protocol();
        if((input.dataset.path==='protocol_settings.network'&&['xhttp','kcp'].includes(input.value))||(input.dataset.path==='protocol_settings.encryption.enabled'&&input.checked)){draft.kernel='xray';assignment();}
      });
      target.querySelector('[data-vless-encryption]')?.addEventListener('click',async()=>{
        if(!draft.machine_id){toast('ابتدا سرور مقصد را انتخاب کنید.','error');return;}
        if(draft.parent_id&&state.nodes.find(n=>n.id==draft.parent_id)?.kernel!=='xray'){toast('برای رمزنگاری VLESS، هسته نود والد باید Xray باشد.','error');return;}
        const machineId=draft.machine_id;
        try{const r=await api('/keys/vless-encryption',{method:'POST',body:JSON.stringify({machine_id:machineId})});if(disposed||draft.type!=='vless'||draft.machine_id!==machineId)return;
          draft.protocol_settings.encryption={...draft.protocol_settings.encryption,enabled:true,...r.data};draft.kernel='xray';protocol();assignment();toast('زوج رمزنگاری دریافت شد؛ برای اعمال، فرم نود را ذخیره کنید.');
        }catch(e){if(e.name!=='AbortError')toast(e.message,'error');}
      });
      target.querySelector('[data-certificate-fingerprint]')?.addEventListener('click',async()=>{
        if(!draft.machine_id){toast('ابتدا سرور مقصد را انتخاب کنید.','error');return;}
        const runtimeId=draft.parent_id||node?.id;
        if(!runtimeId){toast('ابتدا نود و تنظیمات گواهی را ذخیره کنید تا گواهی روی سرور آماده شود.','error');return;}
        const saved=state.nodes.find(n=>n.id==runtimeId);
        const initialCert=saved?.cert_config||saved?.protocol_settings?.cert_config||null;
        if(!draft.parent_id&&JSON.stringify(draft.cert_config||null)!==JSON.stringify(initialCert)){toast('ابتدا تغییرات گواهی را ذخیره کنید و پس از آماده شدن گواهی، دوباره دریافت را بزنید.','error');return;}
        const machineId=draft.machine_id;
        try{const r=await api('/nodes/certificate-fingerprint',{method:'POST',body:JSON.stringify({machine_id:machineId,node_id:runtimeId})});if(disposed||draft.type!=='hysteria'||draft.machine_id!==machineId)return;
          set(draft.protocol_settings,'tls.pinned_peer_cert_sha256',r.data.fingerprint);protocol();toast('اثر انگشت گواهی دریافت شد؛ برای اعمال، فرم نود را ذخیره کنید.');
        }catch(e){if(e.name!=='AbortError')toast(e.message,'error');}
      });
      target.querySelector('[data-network]')?.addEventListener('click',networkEditor);
      target.querySelector('[data-reality]')?.addEventListener('click',async()=>{try{const r=await api('/keys/reality',{method:'POST'});if(disposed)return;draft.protocol_settings.reality_settings={...draft.protocol_settings.reality_settings,...r.data};protocol();}catch(e){if(e.name!=='AbortError')toast(e.message,'error');}});
      target.querySelector('[data-short-id]')?.addEventListener('click',()=>{set(p,'reality_settings.short_id',randomHex(8));protocol();});
      target.querySelector('[data-obfs-password]')?.addEventListener('click',()=>{set(p,'obfs.password',randomHex(16));protocol();});
      target.querySelector('[data-padding]')?.addEventListener('click',()=>{p.padding_scheme=[...padding];protocol();});
      target.querySelector('[data-pattern]')?.addEventListener('click',()=>{const varint=n=>{const b=[];while(n>=128){b.push((n&127)|128);n>>>=7;}b.push(n);return b;};const a=[8,...varint(100+randomInt(101)),16,...varint(400+randomInt(201))];p.traffic_pattern=btoa(String.fromCharCode(10,...varint(a.length),...a));protocol();});
      target.querySelectorAll('[data-generate-ech]').forEach(button=>button.onclick=async()=>{const prefix=button.dataset.generateEch;try{const r=await api('/keys/ech',{method:'POST',body:JSON.stringify({public_name:get(p,prefix+'.query_server_name')||'ech.example.com'})});if(disposed)return;set(p,prefix, {...get(p,prefix,{}),...r.data});protocol();}catch(e){if(e.name!=='AbortError')toast(e.message,'error');}});
    }
    function randomInt(max) {const limit=Math.floor(4294967296/max)*max;let n;do{n=crypto.getRandomValues(new Uint32Array(1))[0];}while(n>=limit);return n%max;}
    function randomHex(size) {return [...crypto.getRandomValues(new Uint8Array(size))].map(x=>x.toString(16).padStart(2,'0')).join('');}
    function subDialog(title,width,body,save) {
      closeSub();
      sub=document.createElement('div');sub.className='node-sub-backdrop modal-backdrop';
      sub.innerHTML=`<div class="modal-outer"><section class="create-modal infrastructure-modal node-sub-modal" style="--node-sub-width:${width}px" role="dialog" aria-modal="true" aria-labelledby="nodeSubTitle"><div class="announcement-modal-heading"><h3 class="topup-title" id="nodeSubTitle">${title}</h3></div><form><div class="modal-fields node-sub-body">${body}</div><div class="form-actions"><button class="button primary" type="submit">تأیید</button><button class="button cancel" type="button" data-sub-close>انصراف</button></div></form></section></div>`;
      host.closest('.speedbox-infrastructure-overlay').appendChild(sub);host.setAttribute('inert','');
      sub.querySelector('[data-sub-close]').onclick=()=>closeSub(true);
      sub.onclick=e=>{if(e.target===sub)closeSub(true);};
      sub.addEventListener('keydown',e=>{if(e.key==='Escape'&&!sub.querySelector('.invoice-custom-select.is-open')){e.preventDefault();e.stopPropagation();closeSub(true);}});
      sub.querySelector('form').onsubmit=async e=>{e.preventDefault();try{await save(sub);closeSub(true);form.querySelector('[data-advanced]')?.focus();}catch(error){toast(error.message,'error');}};
      enhanceControls(sub);sub.querySelector('input,textarea,button')?.focus();return sub;
    }
    function closeSub(check=false) {if(check&&isSaving())return;sub?.remove();sub=null;host.removeAttribute('inert');}
    function networkEditor() {
      const network=draft.protocol_settings.network;
      const choices=network==='tcp'?['tcp','tcp-http']:templates[network]?[network]:[];
      const saved=draft.protocol_settings.network_settings;
      const layer=subDialog('ویرایش پیکربندی پروتکل انتقال',425,`<nav class="segmented node-network-presets">${choices.map(key=>`<button type="button" class="seg-btn" data-preset="${key}">استفاده از قالب ${templates[key].label}</button>`).join('')}</nav><div class="field full"><label>پیکربندی پروتکل</label><textarea name="networkJson" class="node-json" dir="ltr">${esc(JSON.stringify(Array.isArray(saved)&&!saved.length?{}:saved||{},null,2))}</textarea></div>`,container=>{
        let parsed;try{parsed=JSON.parse(container.querySelector('[name=networkJson]').value);}catch(_){throw Error('JSON پیکربندی پروتکل معتبر نیست.');}
        if(!parsed||typeof parsed!=='object'||Array.isArray(parsed))throw Error('پیکربندی انتقال باید یک شیء JSON باشد.');draft.protocol_settings.network_settings=parsed;
        if(network==='tcp'&&String(parsed.header?.type||'').trim().toLowerCase()==='http'){
          parsed.header.type='http';draft.kernel='xray';
          if(draft.type==='vless'&&Number(draft.protocol_settings.tls)===0)draft.protocol_settings.flow='';
          assignment();protocol();
        }
      });
      layer.querySelectorAll('[data-preset]').forEach(button=>button.onclick=()=>{layer.querySelector('[name=networkJson]').value=JSON.stringify(templates[button.dataset.preset].content,null,2);layer.querySelectorAll('[data-preset]').forEach(b=>b.classList.toggle('active',b===button));});
    }
    function advanced() {
      const working={cert_config:merge({cert_mode:'none',domain:'',email:'',dns_provider:'',dns_env:{},http_port:80,cert_content:'',key_content:''},draft.cert_config),protocol_settings:clone(draft.protocol_settings),custom_outbounds:clone(draft.custom_outbounds||[]),custom_routes:clone(draft.custom_routes||[])};
      const tabs=[...(!['shadowsocks','socks','mieru'].includes(draft.type)?[['certificate','گواهی TLS']]:[]),...(['vmess','vless','trojan'].includes(draft.type)?[['multiplex','چندگانه‌سازی']]:[]),['outbounds','خروجی‌ها'],['routing','مسیریابی']];
      let active=tabs[0][0];const errors={};
      const layer=subDialog('تنظیمات پیشرفته نود',672,`<nav class="segmented node-advanced-tabs">${tabs.map(([key,label])=>`<button type="button" class="seg-btn" data-tab="${key}">${label}</button>`).join('')}</nav><div class="form-grid node-advanced-content"></div>`,()=>{
        if(Object.keys(errors).length)throw Error('JSON تنظیمات پیشرفته معتبر نیست.');
        draft.cert_config=working.cert_config;draft.protocol_settings=working.protocol_settings;
        // XBoard stores this in protocol_settings; the agent also accepts the dedicated column.
        if(!['shadowsocks','socks','mieru'].includes(draft.type))draft.protocol_settings.cert_config=clone(working.cert_config);
        draft.custom_outbounds=working.custom_outbounds;draft.custom_routes=working.custom_routes;
      });
      function draw() {
        layer.querySelectorAll('[data-tab]').forEach(b=>b.classList.toggle('active',b.dataset.tab===active));
        const target=layer.querySelector('.node-advanced-content'), cert=working.cert_config, mux=get(working,'protocol_settings.multiplex',{});
        let html='';
        if(active==='certificate') {
          html=select('cert_config.cert_mode','روش گواهی',cert.cert_mode,[['none','بدون مدیریت گواهی'],['http','HTTP-01 (ACME)'],['dns','DNS-01 (ACME)'],['self','گواهی خودامضا'],['content','محتوای گواهی'],...(cert.cert_mode==='file'?[['file','فایل گواهی']]:[])],{wide:true});
          if(cert.cert_mode!=='none')html+=text('cert_config.domain','دامنه',cert.domain);
          if(['http','dns'].includes(cert.cert_mode))html+=text('cert_config.email','ایمیل ACME',cert.email);
          if(cert.cert_mode==='http')html+=text('cert_config.http_port','پورت چالش HTTP',cert.http_port,{number:true,min:1});
          if(cert.cert_mode==='dns')html+=text('cert_config.dns_provider','ارائه‌دهنده DNS',cert.dns_provider)+area('cert_config.dns_env','متغیرهای DNS (هر خط KEY=VALUE)',cert.dns_env,'env');
          if(cert.cert_mode==='content')html+=area('cert_config.cert_content','محتوای گواهی',cert.cert_content)+area('cert_config.key_content','محتوای کلید خصوصی',cert.key_content);
          if(cert.cert_mode==='file')html+=text('cert_config.cert_file','مسیر فایل گواهی',cert.cert_file||'',{wide:true})+text('cert_config.key_file','مسیر فایل کلید خصوصی',cert.key_file||'',{wide:true});
        }
        if(active==='multiplex') {
          html=toggle('protocol_settings.multiplex.enabled','فعال‌سازی چندگانه‌سازی',mux.enabled);
          if(mux.enabled)html+=`<div class="node-row">${select('protocol_settings.multiplex.protocol','پروتکل',mux.protocol,['smux','yamux','h2mux'])}${text('protocol_settings.multiplex.max_connections','حداکثر اتصال',mux.max_connections,{number:true,min:1})}</div>`+toggle('protocol_settings.multiplex.padding','پرکردن بسته‌ها',mux.padding)+toggle('protocol_settings.multiplex.brutal.enabled','فعال‌سازی Brutal',mux.brutal?.enabled)+(mux.brutal?.enabled?`<div class="node-row">${text('protocol_settings.multiplex.brutal.up_mbps','سرعت ارسال (Mbps)',mux.brutal.up_mbps,{number:true})}${text('protocol_settings.multiplex.brutal.down_mbps','سرعت دریافت (Mbps)',mux.brutal.down_mbps,{number:true})}</div>`:'');
        }
        if(active==='outbounds')html=area('custom_outbounds','خروجی‌های سفارشی',working.custom_outbounds,'json');
        if(active==='routing')html=area('custom_routes','مسیرهای سفارشی',working.custom_routes,'json');
        target.innerHTML=html;
        bind(target,working,input=>{delete errors[input.dataset.path];if(input.tagName==='SELECT'||input.type==='checkbox')draw();});
        target.querySelectorAll('[data-format=json]').forEach(input=>input.addEventListener('input',()=>{try{const data=JSON.parse(input.value);if(!data||typeof data!=='object'||(input.dataset.path.startsWith('custom_')&&!Array.isArray(data))||(input.dataset.path==='cert_config.dns_env'&&Array.isArray(data)))throw Error();delete errors[input.dataset.path];input.setCustomValidity('');}catch(_){errors[input.dataset.path]=true;input.setCustomValidity('ساختار JSON معتبر نیست.');}}));
        target.querySelectorAll('[data-format=env]').forEach(input=>input.addEventListener('input',()=>{if(input.validity.valid)delete errors[input.dataset.path];else errors[input.dataset.path]=true;}));
      }
      layer.querySelectorAll('[data-tab]').forEach(b=>b.onclick=()=>{if(Object.keys(errors).length){toast('ابتدا JSON این بخش را اصلاح کنید.','error');return;}active=b.dataset.tab;draw();});draw();
    }
    function header() {
      form.querySelector('.node-protocol-head').innerHTML=select('type','پروتکل',draft.type,protocols,{protocolPicker:true});
      const selector=form.querySelector('[data-path=type]');
      selector.onchange=()=>{cache.set(draft.type,clone(draft.protocol_settings));draft.type=selector.value;draft.protocol_settings=merge(defaults(draft.type),cache.get(draft.type));if(['tuic','anytls','naive','mieru'].includes(draft.type))draft.kernel='singbox';header();protocol();assignment();};enhanceControls(form.querySelector('.node-protocol-head'));
    }
    common();header();protocol();assignment();
    form.querySelector('[data-advanced]').onclick=advanced;
    form.onsubmit=async e=>{
      e.preventDefault();if(!form.reportValidity())return;
      const body=clone(draft);delete body.transfer_enable_gb;delete body.runtime;delete body.machine;delete body.parent;delete body.created_at;delete body.updated_at;delete body.id;delete body.u;delete body.d;
      body.protocol_settings=clone(draft.protocol_settings);
      if(body.type==='hysteria'){body.protocol_settings.bandwidth={...body.protocol_settings.bandwidth,up:Number(get(body,'protocol_settings.bandwidth.up',0)),down:Number(get(body,'protocol_settings.bandwidth.down',0))};}
      try{const r=await api(node?`/nodes/${node.id}`:'/nodes',{method:node?'PUT':'POST',body:JSON.stringify(body)});if(disposed)return;toast(r.message);closeModal();await reload();}catch(error){if(error.name!=='AbortError')toast(error.message,'error');}
    };
    return () => {disposed=true;closeSub();};
  }
  return {mount,protocols,protocolBadge};
})();

