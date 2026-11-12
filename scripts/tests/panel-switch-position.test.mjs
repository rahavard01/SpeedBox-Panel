import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';

const source=readFileSync(new URL('../../public/speedbox/register-infrastructure.js',import.meta.url),'utf8');
function harness(serverMode=false,mobile=false){
  const mounted=[],unmounted=[],ticks=[],observers=[],markers=[];
  const listeners=new Map(),viewportListeners=new Map(),documentListeners=new Map();
  const events=map=>({addEventListener(name,fn){if(!map.has(name))map.set(name,new Set());map.get(name).add(fn)},removeEventListener(name,fn){map.get(name)?.delete(fn)}});
  const geometry={headerTop:128,barTop:128,right:2690,width:300,height:56};
  const holder={};
  const bar={querySelector:()=>({getBoundingClientRect:()=>({right:geometry.right})}),getBoundingClientRect:()=>({top:geometry.barTop}),before(marker){markers.push(marker);marker.parentElement=holder;marker.isConnected=true}};
  const wrapper={style:{},closest:()=>bar,contains:()=>false,querySelectorAll:()=>[],remove(){this.removed=true}};
  const trigger={getBoundingClientRect:()=>({width:geometry.width,height:geometry.height}),focus(){}};
  const window={innerWidth:2732,scrollX:0,scrollY:0,...events(listeners),visualViewport:events(viewportListeners),matchMedia:()=>({matches:mobile,...events(new Map())})};
  const document={...events(documentListeners),body:{appendChild(node){node.moved=true}},createElement(){return{style:{},setAttribute(){},getBoundingClientRect:()=>({top:geometry.headerTop-window.scrollY}),remove(){this.removed=true;this.isConnected=false}}}};
  const context=vm.createContext({window,document,ResizeObserver:class{constructor(fn){this.fn=fn;this.targets=new Set();observers.push(this)}observe(target){this.targets.add(target)}disconnect(){this.targets.clear();this.disconnected=true}}});
  vm.runInContext(source,context);
  window.__SPEEDBOX_EXTEND_PANEL__({router:{addRoute(){}},element(tag,props,children){
    if(props?.ref)props.ref.value=props.class==='speedbox-panel-switch__trigger'?trigger:wrapper;
    return{tag,props,children};
  },ref:value=>({value}),nextTick:fn=>ticks.push(fn),onMounted:fn=>mounted.push(fn),onUnmounted:fn=>unmounted.push(fn),watch(){},notifications(){}});
  const render=window.__SPEEDBOX_PANEL_SWITCH__.setup({serverMode,navigate(){}});
  render();mounted.forEach(fn=>fn());ticks.splice(0).forEach(fn=>fn());
  const emit=(map,name)=>[...(map.get(name)||[])].forEach(fn=>fn({}));
  return{geometry,window,wrapper,holder,observers,markers,resize:()=>emit(listeners,'resize'),viewportResize:()=>emit(viewportListeners,'resize'),observe:()=>observers.forEach(o=>o.fn()),dispose:()=>unmounted.forEach(fn=>fn()),listeners,viewportListeners,documentListeners};
}

for(const [serverMode,mobile] of [[false,false],[true,false],[false,true]]){
  const h=harness(serverMode,mobile);
  assert.equal(h.wrapper.style.top,'36px','initial selector is centered in the header gap');
  // Browser zoom reduces CSS viewport, scaled header and button together.
  Object.assign(h.geometry,{headerTop:64,barTop:64,height:28,width:150,right:1320});
  h.window.innerWidth=1366;h.resize();
  assert.equal(h.wrapper.style.top,'18px','zoom must not reuse the old header boundary');
  assert.equal(h.wrapper.style.left,'1170px','horizontal placement follows the resized hamburger');
  // Resize while scrolled: the fixed bar no longer describes its document slot.
  h.window.scrollY=500;
  Object.assign(h.geometry,{headerTop:96,barTop:0,height:42,width:225,right:1980});
  h.window.innerWidth=2048;h.viewportResize();
  assert.equal(h.wrapper.style.top,'27px','zoom while scrolled uses the natural header boundary');
  h.window.scrollY=750;h.observe();
  assert.equal(h.wrapper.style.top,'27px','scroll cannot make the selector follow a sticky bar');
  h.geometry.headerTop=120;h.observe();
  assert.equal(h.wrapper.style.top,'39px','header layout changes are measured again');
  h.geometry.height=50;h.observe();
  assert.equal(h.wrapper.style.top,'35px','font/control resize keeps the current midpoint');
  h.window.innerWidth=320;Object.assign(h.geometry,{width:280,right:20});h.resize();
  assert.equal(h.wrapper.style.left,'8px','selector stays inside the narrow viewport');
  assert.equal(h.markers.length,1,'one zero-size anchor per mount');
  assert.ok(h.observers[0].targets.has(h.holder),'header container is observed as well as its button');
  h.dispose();
  assert.ok(h.markers[0].removed&&h.wrapper.removed&&h.observers[0].disconnected,'unmount removes anchor, floating control and observer');
  for(const map of [h.listeners,h.viewportListeners,h.documentListeners])for(const callbacks of map.values())assert.equal(callbacks.size,0,'unmount removes event listeners');
}
console.log('PANEL SWITCH POSITION: zoom, resize while scrolled, visual viewport, layout changes, mobile and cleanup passed');
