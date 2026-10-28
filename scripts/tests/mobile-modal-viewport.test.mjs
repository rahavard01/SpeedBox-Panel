// Infrastructure dialogs retain the natural-flow/backdrop scrolling used by announcements.
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {resolve} from 'node:path';
import {fileURLToPath} from 'node:url';
import http from 'node:http';
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE||'playwright');
const root=fileURLToPath(new URL('../../',import.meta.url));
const assets=process.env.MODAL_ASSET_ROOT||root;
const source=process.env.MODAL_SOURCE_ROOT||root;
const read=(p,dir=root)=>readFileSync(resolve(dir,p),'utf8');
const manifest=JSON.parse(read('public/build/manifest.json',assets));
const css=(manifest['resources/js/app.js'].css||[]).map(p=>read('public/build/'+p,assets)).join('\n');
const template=read('resources/views/infrastructure.blade.php').match(/<template[^>]*>([\s\S]*)<\/template>/)[1];
const html=`<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>${css}\n${read('public/speedbox/panel-controls.css')}\n${read('public/speedbox/infrastructure.css',source)}\n${read('public/speedbox/tunnels.css')}\nbody{margin:0;background:#14171c;color:white}.modal-outer{display:flex;align-items:center;justify-content:center}.create-modal{background:#191c22;pointer-events:auto;border-radius:24px;padding:24px}#behind{height:1800px}</style></head><body><div id="behind">Background must stay covered</div><div id="root" class="speedbox-infrastructure">${template}</div></body></html>`;
const server=http.createServer((req,res)=>{res.writeHead(200,{'Content-Type':'text/html'});res.end(html);});
await new Promise(r=>server.listen(0,'127.0.0.1',r));
const browser=await chromium.launch({headless:true,...(process.env.BROWSER_EXECUTABLE?{executablePath:process.env.BROWSER_EXECUTABLE}:{}),args:['--no-sandbox']});
try{
 for(const [width,touch]of [[390,true],[1024,true],[1280,false]]){
  const ctx=await browser.newContext({viewport:{width,height:844},hasTouch:touch,isMobile:touch});const page=await ctx.newPage();
  await page.goto(`http://127.0.0.1:${server.address().port}`);
  await page.evaluate(()=>{
   const vp=new EventTarget();Object.assign(vp,{height:844,offsetTop:0,scale:1});Object.defineProperty(window,'visualViewport',{value:vp});window.setKeyboard=(height,offsetTop=0)=>{Object.assign(vp,{height,offsetTop});vp.dispatchEvent(new Event('resize'));vp.dispatchEvent(new Event('scroll'));};
   localStorage.setItem('token','fixture');localStorage.setItem('user','{"role":1}');window.errors=[];
   window.fetch=async()=>({ok:true,json:async()=>({data:[],meta:{kernel_capabilities:{protocols:{shadowsocks:['singbox','xray']},features:{}}}})});
   window.globalScrolls=0;window.elementScrolls=0;const old=HTMLElement.prototype.scrollIntoView;HTMLElement.prototype.scrollIntoView=function(...args){window.elementScrolls++;return old.apply(this,args);};const scroll=window.scrollTo;window.scrollTo=(...args)=>{window.globalScrolls++;return scroll.apply(window,args);};
  });
  await page.addScriptTag({content:read('public/speedbox/node-editor.js')});await page.addScriptTag({content:read('public/speedbox/infrastructure.js',source)});
  await page.evaluate(()=>{window.session=SpeedboxInfrastructure.mount(document.querySelector('#root'),{view:'nodes',router:{replace(){},push(){}},notify:{error:e=>errors.push(e),notif(){}}});});
  await page.locator('#addNode:not(:disabled)').click();
  const verify=async(dialogSelector,inputSelector)=>{
   await page.locator(inputSelector).focus();await page.locator(inputSelector).evaluate(e=>{if(e.type!=='number')e.setSelectionRange(0,0);});
   const y=await page.evaluate(()=>{globalScrolls=0;elementScrolls=0;return window.scrollY;});
   for(const [height,top]of [[340,0],[360,64],[844,0]]){
    await page.evaluate(([h,t])=>setKeyboard(h,t),[height,top]);await page.waitForTimeout(220);
    const d=await page.locator(dialogSelector).evaluate(e=>{const r=e.getBoundingClientRect(),b=e.closest('.modal-backdrop').getBoundingClientRect(),input=document.activeElement.getBoundingClientRect();return {top:r.top,bottom:r.bottom,height:r.height,maxHeight:getComputedStyle(e).maxHeight,overflow:getComputedStyle(e).overflowY,backdropOverflow:getComputedStyle(e.closest('.modal-backdrop')).overflowY,coverTop:b.top,coverBottom:b.bottom,focused:e.contains(document.activeElement),inputTop:input.top,inputBottom:input.bottom,scrolls:[globalScrolls,elementScrolls],y:window.scrollY};});
    assert.ok(d.coverTop<=0&&d.coverBottom>=844,`backdrop clipped: ${JSON.stringify(d)}`);
    assert.ok(d.focused,'keyboard update removed focus');assert.equal(d.y,y,'document moved');assert.deepEqual(d.scrolls,[0,0],'keyboard handler scrolled global ancestors');
    assert.equal(d.maxHeight,'none','dialog was constrained to keyboard height');assert.equal(d.overflow,'visible','dialog gained its own scroll box');assert.equal(d.backdropOverflow,'auto','outer backdrop must own scrolling');
   }
   await page.locator(dialogSelector).evaluate(e=>{const backdrop=e.closest('.modal-backdrop');backdrop.scrollTop=backdrop.scrollHeight;});assert.ok(await page.locator(dialogSelector).evaluate(e=>{const b=e.querySelector('.form-actions button:last-child').getBoundingClientRect(),r=e.closest('.modal-backdrop').getBoundingClientRect();return b.top>=r.top&&b.bottom<=r.bottom;}),'footer cannot be reached with outer scrolling');
  };
  await verify('.node-modal','[data-path="host"]');
  await page.locator('[data-advanced]').click();await verify('.node-sub-modal','.node-sub-modal textarea');await page.locator('[data-sub-close]').click();
  await page.evaluate(()=>session.showView('servers'));await page.locator('#addServer').click();await verify('.server-modal','input[name="host"]');
  if(touch){
   const untouched=await page.evaluate(()=>{const other=document.createElement('div');other.className='speedbox-infrastructure-overlay account-operation-overlay';other.hidden=true;other.innerHTML='<div class="modal-backdrop"><div class="modal-outer"><section class="infrastructure-modal"></section></div></div>';document.body.appendChild(other);const panel=other.querySelector('section'),value=getComputedStyle(panel).maxHeight;other.remove();return value;});
   assert.equal(untouched,'none','unrelated account dialogs inherited the owned viewport layout');
  }
  assert.deepEqual(await page.evaluate(()=>errors),[]);await page.evaluate(()=>session.dispose());await ctx.close();
 }
 console.log('PASS: natural-height node/subdialog/server forms use outer backdrop scrolling, preserve focus through viewport events and keep footer reachable on phone/tablet/desktop');
}finally{await browser.close();await new Promise(r=>server.close(r));}
