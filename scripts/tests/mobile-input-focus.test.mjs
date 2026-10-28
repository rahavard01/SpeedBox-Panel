// Focus regression using the real Vue components and shared menu registration.
// Requires npm ci and jsdom (or PARTNER_TEST_JSDOM pointing to its module).
import assert from 'node:assert/strict';
import {readFileSync,writeFileSync,mkdtempSync,rmSync,mkdirSync} from 'node:fs';
import {resolve,join} from 'node:path';
import {fileURLToPath,pathToFileURL} from 'node:url';
import {build} from 'vite';
import vue from '@vitejs/plugin-vue';
const {JSDOM}=await import(process.env.PARTNER_TEST_JSDOM||'jsdom');
const root=fileURLToPath(new URL('../../',import.meta.url));
const cache=join(root,'node_modules','.cache');mkdirSync(cache,{recursive:true});
const temp=mkdtempSync(join(cache,'mobile-focus-'));
const dom=new JSDOM('<!doctype html><html><body></body></html>',{url:'https://fixture.test',runScripts:'outside-only',pretendToBeVisual:true});
const w=dom.window;let mobile=true;
w.matchMedia=()=>({get matches(){return mobile;},addEventListener(){},removeEventListener(){}});
w.ResizeObserver=class{observe(){}disconnect(){}};
for(const key of ['window','document','Element','HTMLElement','SVGElement','Node','Event','localStorage','ResizeObserver'])Object.defineProperty(globalThis,key,{value:key==='window'?w:w[key],configurable:true});
Object.defineProperty(globalThis,'navigator',{value:w.navigator,configurable:true});
const failures=[];
try{
 const entry=join(temp,'entry.js');
 writeFileSync(join(temp,'http.js'),'export default {get:async()=>({data:{}}),post:async()=>({data:{}})};');
 writeFileSync(entry,`export {createApp,h,ref,nextTick,onMounted,onBeforeUnmount,watch} from 'vue';\nexport {default as AccountPlanSelect} from ${JSON.stringify(resolve(root,'resources/js/components/AccountPlanSelect.vue'))};\nexport {default as UserAccountActions} from ${JSON.stringify(resolve(root,'resources/js/components/UserAccountActions.vue'))};\n`);
 await build({configFile:false,root,plugins:[vue()],logLevel:'error',resolve:{alias:[{find:'@/lib/http',replacement:join(temp,'http.js')},{find:'@',replacement:resolve(root,'resources/js')}]},build:{lib:{entry,formats:['es'],fileName:'components'},outDir:join(temp,'build'),minify:false,emptyOutDir:true}});
 const v=await import(pathToFileURL(join(temp,'build/components.js')));
 w.eval(readFileSync(resolve(root,'public/speedbox/register-infrastructure.js'),'utf8'));
 w.__SPEEDBOX_EXTEND_PANEL__({router:{addRoute(){}},element:v.h,ref:v.ref,nextTick:v.nextTick,onMounted:v.onMounted,onUnmounted:v.onBeforeUnmount,watch:v.watch,notifications:()=>({})});
 const components=[
  ['panel switch / main',w.__SPEEDBOX_PANEL_SWITCH__,{serverMode:false,navigate(){}}],
  ['panel switch / servers',w.__SPEEDBOX_PANEL_SWITCH__,{serverMode:true,navigate(){}}],
  ['shared tariff selector',w.__SPEEDBOX_TARIFF_PLAN_SELECT__,{modelValue:1,options:[{id:1,label:'Plan'}]}],
  ['account operations',v.UserAccountActions,{user:{id:8,email:'fixture@example.test'},mobile:true}],
  ['account plan selector',v.AccountPlanSelect,{modelValue:1,inputId:'plan-fixture',options:[{id:1,label:'Plan'}]}]
 ];
 for(const touch of [true,false]){
  mobile=touch;
  for(const [name,component,props]of components){
   const host=w.document.createElement('div');host.className='top-bar';host.innerHTML='<button class="hamburger-menu">Menu</button><div data-component></div><input data-user-input value="unchanged"><textarea data-user-notes>notes</textarea>';
   w.document.body.appendChild(host);const app=v.createApp(component,props);app.mount(host.querySelector('[data-component]'));await v.nextTick();
   try{
    for(const input of host.querySelectorAll('input[data-user-input],textarea')){
     input.focus();input.setSelectionRange(2,2);
     for(let i=0;i<4;i++){w.dispatchEvent(new w.Event('resize'));await v.nextTick();}
     if(w.document.activeElement!==input)failures.push(`${name} (${touch?'mobile':'desktop'}) moved focus to ${w.document.activeElement.tagName}`);
     assert.equal(input.selectionStart,2);assert.equal(input.value,input.tagName==='INPUT'?'unchanged':'notes');
    }
    // Explicit Escape must still return focus when using a desktop dropdown.
    if(!touch&&component===w.__SPEEDBOX_TARIFF_PLAN_SELECT__){
     const trigger=host.querySelector('.invoice-custom-select__trigger');trigger.click();await v.nextTick();
     host.querySelector('[role=option]').dispatchEvent(new w.KeyboardEvent('keydown',{key:'Escape',bubbles:true}));await v.nextTick();assert.equal(w.document.activeElement,trigger);
    }
   }finally{app.unmount();host.remove();}
  }
 }
 assert.deepEqual(failures,[]);
 console.log('PASS: repeated keyboard resize preserves input/textarea focus and caret with main/server switches, plan selectors and account operations; Escape still restores focus');
}finally{dom.window.close();rmSync(temp,{recursive:true,force:true});}
