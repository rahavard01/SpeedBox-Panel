// Browser check of mobile focus font sizes against the actual production CSS.
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {fileURLToPath} from 'node:url';
import {resolve} from 'node:path';
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE||'playwright');
const root=fileURLToPath(new URL('../../',import.meta.url));
const read=p=>readFileSync(resolve(root,p),'utf8');
const manifest=JSON.parse(read('public/build/manifest.json'));
const styles=[...new Set(Object.values(manifest).flatMap(e=>e.css||[]))].map(p=>read('public/build/'+p)).join('\n');
const shared=read('public/speedbox/panel-controls.css');
const legacy=shared.split('/* Keep focused mobile controls readable')[0];
const markup=`<main id="app"><div class="center-box">
 <div class="table-toolbar"><div class="search-field"><input id="search" class="form-control" type="search" value="search"></div></div>
 <div class="create-modal"><form class="edit-form"><input id="profile" class="edit-input"><input id="settings" class="form-input"><textarea id="notes" class="form-input"></textarea><input id="bulk" class="bulk-input"><select id="mode" class="create-code-mode-select"><option>single</option></select><div class="custom-username-input"><input id="username"></div><div class="price-box"><div class="edit-row"><input id="price" class="edit-input"></div></div><div class="vpd-input-group"><input id="calendar" readonly value="2026/10/27"></div></form></div>
 <div class="sms-form"><input id="sms" class="sms-input"></div>
 <div class="speedbox-infrastructure"><input id="server-search" class="search"></div>
 <div class="speedbox-infrastructure-overlay"><div class="infrastructure-modal"><input id="node" class="form-input"><textarea id="tunnel"></textarea></div></div>
 <input id="email" type="email"><input id="number" type="number"><input id="phone" type="tel"><input id="password" type="password"><input id="date" type="date"><input id="checkbox" type="checkbox"><input id="radio" type="radio"><button id="button">Button</button>
 </div></main>`;
const ids=['search','profile','settings','notes','bulk','mode','username','price','calendar','sms','server-search','node','tunnel','email','number','phone','password','date','dynamic'];
const browser=await chromium.launch({headless:true,...(process.env.BROWSER_EXECUTABLE?{executablePath:process.env.BROWSER_EXECUTABLE}:{}),args:['--no-sandbox']});
try{
 for(const [width,touch]of [[390,true],[1024,true],[1280,false]]){
  const ctx=await browser.newContext({viewport:{width,height:900},hasTouch:touch,isMobile:touch});const page=await ctx.newPage();
  await page.setContent(`<style>${styles}\n${legacy}</style>${markup}`);
  const scopes=[...read('vite.config.js').matchAll(/:\s*"([a-f0-9]{8})"/g)].map(m=>'data-v-'+m[1]);
  await page.evaluate(scopes=>{for(const e of document.querySelectorAll('*'))for(const attr of scopes)e.setAttribute(attr,'');const input=document.createElement('input');input.id='dynamic';input.className='sms-input';document.body.appendChild(input);},scopes);
  const before=await page.evaluate(()=>Object.fromEntries([...document.querySelectorAll('[id]')].map(e=>[e.id,getComputedStyle(e).fontSize])));
  const focusedBefore={};
  for(const id of ids){
   if(['calendar','mode','date'].includes(id))continue;
   await page.locator('#'+id).focus();focusedBefore[id]=await page.locator('#'+id).evaluate(e=>getComputedStyle(e).fontSize);await page.locator('#'+id).evaluate(e=>e.blur());
  }
  await page.addStyleTag({content:shared});
  for(const id of ids){
   const actual=await page.locator('#'+id).evaluate(e=>getComputedStyle(e).fontSize);
   assert.equal(actual,touch?'16px':before[id],`${id}: width=${width}, touch=${touch}`);
   // Focus must not reveal a smaller :focus override.
   if(!['calendar','mode','date'].includes(id)){await page.locator('#'+id).focus();assert.equal(await page.locator('#'+id).evaluate(e=>getComputedStyle(e).fontSize),touch?'16px':focusedBefore[id],`focus size changed: ${id}`);}
  }
  for(const id of ['checkbox','radio','button'])assert.equal(await page.locator('#'+id).evaluate(e=>getComputedStyle(e).fontSize),before[id],`non-text control changed: ${id}`);
  if(touch)assert.ok(ids.some(id=>parseFloat(before[id])<16),'negative control was not a small mobile field');
  await ctx.close();
 }
 console.log('PASS: production styles keep 19 field cases at 16px on phones/tablets, including scoped !important and dynamic fields; desktop and non-text controls retain their sizes');
}finally{await browser.close();}
