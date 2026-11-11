import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import {readFileSync} from 'node:fs';

const file=readFileSync(new URL('../../public/speedbox/register-infrastructure.js',import.meta.url),'utf8');
const source=file.slice(file.indexOf('window.__SPEEDBOX_PANEL_SWITCH__ ='),file.indexOf('window.__SPEEDBOX_TARIFF_PLAN_SELECT__ ='));

function fixture(){
  const pending=[],navigations=[],document={activeElement:null};
  function element(tag,props={},children=[]){
    props ||= {};
    const node={tag,props,children:Array.isArray(children)?children:[],
      contains(other){return this===other || this.children.some(c=>c?.contains?.(other));},
      querySelectorAll(selector){const out=[];const walk=n=>{for(const c of n.children){if(!c?.props)continue;if(selector==='[role=menuitemradio]'&&c.props.role==='menuitemradio')out.push(c);walk(c);}};walk(this);return out;},
      focus(options){assert.equal(options?.preventScroll,true);document.activeElement=this;}
    };
    if(props.ref)props.ref.value=node;
    return node;
  }
  const window={__SPEEDBOX_USE_MOBILE__:()=>({value:false})};
  const context=vm.createContext({window,document,speedboxPanelVue:{element,ref:value=>({value}),nextTick:fn=>pending.push(fn),onMounted:()=>{},onUnmounted:()=>{}}});
  vm.runInContext(source,context);
  const props={serverMode:false,beforeOpen:()=>{},navigate:value=>{navigations.push(value);props.serverMode=value;}};
  const render=window.__SPEEDBOX_PANEL_SWITCH__.setup(props);
  let root;
  function draw(){root=render();while(pending.length)pending.shift()();return root;}
  const trigger=()=>root.children.find(n=>n.tag==='button');
  const options=()=>root.querySelectorAll('[role=menuitemradio]');
  const open=()=>{trigger().props.onClick();draw();};
  draw();
  return {draw,open,trigger,options,navigations,document,get root(){return root;}};
}

test('Safari null-relatedTarget blur does not remove the option before its click',()=>{
  const f=fixture();f.open();assert.equal(f.options().length,2);
  f.root.props.onFocusout({currentTarget:f.root,relatedTarget:null});f.draw();
  assert.equal(f.options().length,2);
  f.options()[1].props.onClick();f.draw();assert.deepEqual(f.navigations,[true]);
});
test('switch navigates to servers and back without changing the selected route silently',()=>{
  const f=fixture();f.open();f.options()[1].props.onClick();f.draw();
  f.open();f.options()[0].props.onClick();f.draw();assert.deepEqual(f.navigations,[true,false]);
});
test('keyboard focus is placed without scrolling and Escape dismisses the menu',()=>{
  const f=fixture();f.open();assert.equal(f.document.activeElement.props.role,'menuitemradio');
  f.root.props.onKeydown({key:'Escape',preventDefault(){},stopPropagation(){}});f.draw();
  assert.equal(f.options().length,0);
});
test('moving focus to another control dismisses the menu',()=>{
  const f=fixture();f.open();f.root.props.onFocusout({currentTarget:f.root,relatedTarget:{}});f.draw();
  assert.equal(f.options().length,0);
});
