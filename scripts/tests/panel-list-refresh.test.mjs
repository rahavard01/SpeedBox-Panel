// Run with node --test. Isolated DOM boundary tests; no API or browser install.
import assert from 'node:assert/strict';
import test from 'node:test';
import vm from 'node:vm';
import {readFileSync} from 'node:fs';
const source=readFileSync(new URL('../../public/speedbox/infrastructure.js',import.meta.url),'utf8');
const helpers=source.slice(source.indexOf('  function saveListPosition('),source.indexOf('  function loading('));

function fixture(){
 const window={scrollX:0,scrollY:900,scrollTo(p){this.scrollX=p.left;this.scrollY=p.top;}};
 let document;
 class Element{
  constructor(tag,attrs={},text=''){this.tagName=tag;this.attrs=attrs;this.text=text;this.children=[];this.parentElement=null;this.scrollTop=0;this.scrollLeft=0;this.connected=false;}
  get className(){return this.attrs.class||'';}
  get attributes(){return Object.entries(this.attrs).map(([name,value])=>({name,value}));}
  get isConnected(){return this.connected||!!this.parentElement?.isConnected;}
  get outerHTML(){return JSON.stringify([this.tagName,this.attrs,this.text,this.children.map(c=>c.outerHTML)]);}
  getAttribute(name){return this.attrs[name]??null;}
  contains(el){return el===this||this.children.some(c=>c.contains(el));}
  add(child){if(child.parentElement)child.remove();this.children.push(child);child.parentElement=this;return child;}
  remove(){if(this.parentElement){const p=this.parentElement;p.children.splice(p.children.indexOf(this),1);this.parentElement=null;}}
  replaceWith(next){const p=this.parentElement,index=p.children.indexOf(this);next.remove();p.children[index]=next;next.parentElement=p;this.parentElement=null;}
  insertBefore(next,current){next.remove();const index=current?this.children.indexOf(current):this.children.length;assert(index>=0);this.children.splice(index,0,next);next.parentElement=this;}
  replaceChildren(fragment){for(const child of [...this.children])child.remove();for(const child of [...fragment.children])this.add(child);this.scrollLeft=0;window.scrollY=0;}
  querySelectorAll(selector){
   const matches=el=>selector==='[data-node-row],[data-server-row],[data-plan-row]'?['node','server','plan'].some(k=>el.getAttribute(`data-${k}-row`)!==null):
    selector==='.table-wrap,.table-scroll,.table-scroll-rtl'?el.className==='table-wrap':
    [...selector.matchAll(/\[([^=]+)="([^"]*)"\]/g)].every(([,k,v])=>el.getAttribute(k)===v);
   const out=[];const walk=el=>{for(const child of el.children){if(matches(child))out.push(child);walk(child);}};walk(this);return out;
  }
  querySelector(selector){return this.querySelectorAll(selector)[0]||null;}
  focus(options){document.activeElement=this;assert.equal(options.preventScroll,true);}
 }
 const panel=new Element('ARTICLE',{class:'panel-card'});panel.connected=true;
 const content={querySelector:()=>panel};
 function make(spec){
  const root=new Element('FRAGMENT');const wrap=root.add(new Element('DIV',{class:spec.layout==='cards'?'entity-card-list':'table-wrap'}));
  const rows=spec.layout==='cards'?wrap:wrap.add(new Element('TBODY'));
  for(const [id,text] of spec.rows){const row=rows.add(new Element(spec.layout==='cards'?'ARTICLE':'TR',{'data-node-row':String(id)},text));row.add(new Element('INPUT',{'data-node-show':String(id)}));}
  return root;
 }
 document={activeElement:null,createElement(tag){assert.equal(tag,'template');return {set innerHTML(markup){this.content=make(JSON.parse(markup));}};}};
 const context=vm.createContext({content,window,document,CSS:{escape:s=>s}});
 vm.runInContext(helpers,context);
 const initial=make({rows:[[1,'one'],[2,'two'],[3,'three']]});panel.replaceChildren(initial);window.scrollY=900;
 const wrap=panel.querySelectorAll('.table-wrap,.table-scroll,.table-scroll-rtl')[0];wrap.scrollLeft=-300;
 const rows=()=>panel.querySelectorAll('[data-node-row],[data-server-row],[data-plan-row]');
 return {panel,window,document,rows,wrap,update:(rows,layout='table')=>context.updateEntityList(JSON.stringify({rows,layout}))};
}

test('saving one row keeps other rows, the scroll wrapper and both scroll axes',()=>{
 const f=fixture(),[first,second,third]=f.rows();f.update([[1,'one'],[2,'saved'],[3,'three']]);
 assert.equal(f.rows()[0],first);assert.notEqual(f.rows()[1],second);assert.equal(f.rows()[2],third);
 assert.equal(f.panel.querySelectorAll('.table-wrap,.table-scroll,.table-scroll-rtl')[0],f.wrap);
 assert.equal(f.wrap.scrollLeft,-300);assert.equal(f.window.scrollY,900);
});
test('filter, insert, delete and ordering retain the correct keyed identities',()=>{
 const f=fixture(),[one,,three]=f.rows();f.update([[3,'three'],[4,'new'],[1,'one']]);
 assert.deepEqual(f.rows().map(r=>r.getAttribute('data-node-row')),['3','4','1']);
 assert.equal(f.rows()[0],three);assert.equal(f.rows()[2],one);
});
test('layout changes keep the page position and restore a rebuilt table viewport',()=>{
 const f=fixture();f.update([[1,'one'],[2,'two']],'cards');assert.equal(f.window.scrollY,900);
 f.update([[1,'one'],[2,'two']]);assert.equal(f.window.scrollY,900);
});
test('a focused control in a changed row is restored without scrolling',()=>{
 const f=fixture();f.document.activeElement=f.rows()[1].children[0];f.update([[1,'one'],[2,'saved'],[3,'three']]);
 assert.equal(f.document.activeElement,f.rows()[1].children[0]);assert.equal(f.window.scrollY,900);
});
test('an empty filtered result does not restore a stale row or throw',()=>{
 const f=fixture();f.update([]);assert.equal(f.rows().length,0);assert.equal(f.window.scrollY,900);
 f.update([[9,'new']]);assert.equal(f.rows().length,1);
});
