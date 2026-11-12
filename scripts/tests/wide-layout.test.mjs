import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {createRequire} from 'node:module';
import {fileURLToPath} from 'node:url';
import path from 'node:path';
import vm from 'node:vm';
import {integrateWideLayout,scaleValue} from '../ui/integrate-wide-layout.mjs';
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'../..');
const require=createRequire(path.join(root,'package.json'));
const postcss=process.env.SPEEDBOX_POSTCSS_PATH?require(process.env.SPEEDBOX_POSTCSS_PATH):require(require.resolve('postcss',{paths:[path.dirname(require.resolve('vite/package.json'))]}));
let checks=0;const check=(condition,message)=>{assert.ok(condition,message);checks++;};
assert.equal(scaleValue('min(576px,94vw)'),'min(calc(576 * var(--speedbox-ui-unit)),94vw)');checks++;
assert.equal(scaleValue('100% 1.5 300ms #121212'),'100% 1.5 300ms #121212');checks++;
const before=readFileSync(path.join(root,'public/speedbox/wide-layout.css'),'utf8').replace(/\r\n/g,'\n');
const result=integrateWideLayout(root),css=readFileSync(path.join(root,'public/speedbox/wide-layout.css'),'utf8');
assert.equal(css,before,'generator is deterministic');checks++;
check(result.rules>1000,'all page and modal families are included');
const ast=postcss.parse(css);const rules=[];ast.walkRules(r=>rules.push(r));
check(css.indexOf('color:silver;font-size:18px')<css.indexOf('@media(min-width:1367px)'),'base title icon cannot override its wide-screen size');
for(const r of rules){
  if(r.selector.includes('dashboard-summary .dashboard-card-title>i')||r.selector.startsWith('.speedbox-infrastructure-overlay .account-operation-modal .button.operation-danger'))continue;
  let wide=false;for(let p=r.parent;p;p=p.parent)if(p.type==='atrule'&&p.name==='media'&&p.params==='(min-width:1367px)')wide=true;
  check(wide,'generated layout never leaks into mobile: '+r.selector.slice(0,70));
}
const declaration=(fragment,property,expected)=>rules.some(r=>r.selector.includes(fragment)&&r.nodes.some(d=>d.type==='decl'&&d.prop===property&&d.value.includes(expected)));
for(const [fragment,prop,expected] of [
  ['.infrastructure-modal.server-modal','width','576 * var'],
  ['.infrastructure-modal.plan-modal','width','576 * var'],
  ['.protocol-badge','height','28 * var'],
  ['.server-resources','max-width','230 * var'],
  ['.node-traffic','min-width','125 * var'],
  ['.plan-user-count','font-size','11 * var'],
  ['.account-operation-modal','width','576 * var'],
  ['.account-actions-menu button','font-size','11 * var'],
  ['.user-list-filter-dialog','max-width','400 * var'],
  ['.speedbox-brand-footer','font-size','11 * var'],
  ['.vpd-input-group','--dp-height','34 * var']
])check(declaration(fragment,prop,expected),'scaled region '+fragment+' / '+prop);
check(!rules.some(r=>r.nodes.some(d=>d.prop==='font'&&d.important)),'scaling never resets font family or weight');
check(!rules.some(r=>/account-actions-menu|speedbox-partner-history-menu/.test(r.selector)&&r.nodes.some(d=>['left','right','top','bottom','inset','transform'].includes(d.prop))),'CSS does not overwrite script-positioned menu anchors');
check(css.includes('.speedbox-infrastructure:not(:has(.dashboard-overview))'),'existing dashboard proportions stay scoped out');
check(declaration('.transaction-records-table)>thead','backdrop-filter','none'),'invoice header blur cannot cover separator lines');
check(declaration('#app .center-box :is(.billing-table,.transaction-records-table)>thead','backdrop-filter','none'),'invoice correction has the ID specificity required to beat the shared table rule');
check(declaration('::placeholder','font-size','12 * var'),'toolbar placeholder scales with its control');
check(declaration('.hamburger-menu','font-size','20 * var'),'hamburger scales');
check(declaration('.btn-toast-close','inline-size','26 * var'),'toast close control scales');
check(declaration('.msg-icon svg','width','20 * var'),'toast icon scales');
check(css.includes('.operation-danger{background:#835d16b3!important'),'UUID confirmation uses the existing amber confirmation colour');

const js=readFileSync(path.join(root,'public/speedbox/wide-layout.js'),'utf8');
const context=vm.createContext({window:{},document:{readyState:'loading',addEventListener(){}},innerWidth:1920,innerHeight:1080});
vm.runInContext(js.replace('  function adjust(menu){','  window.testPosition=position;\n  function adjust(menu){'),context);
function menuAt(rect,width=1920,height=1080){
  context.innerWidth=width;context.innerHeight=height;
  const menu={isConnected:true,style:{},getBoundingClientRect:()=>({height:350})};
  context.window.testPosition(menu,{isConnected:true,getBoundingClientRect:()=>rect});return menu.style;
}
let style=menuAt({top:900,bottom:930,right:1890});
check(Number.parseFloat(style.width)>280&&style.top==='auto','wide menu scales and opens upward near bottom');
check(Number.parseFloat(style.left)+Number.parseFloat(style.width)<=1908,'menu stays inside right viewport edge');
style=menuAt({top:20,bottom:50,right:25});check(style.left==='12px'&&style.bottom==='auto','menu stays inside left edge and opens down');
style=menuAt({top:20,bottom:50,right:25},390,844);check(Object.keys(style).length===0,'mobile positioning remains untouched');
const subscription=readFileSync(path.join(root,'resources/views/client/subscribe.blade.php'),'utf8');
check(subscription.includes('viewport-fit=cover')&&subscription.includes('color-scheme: dark;')&&subscription.includes('background: #081014;'),'subscription has a dark root canvas and safe viewport');
check((subscription.match(/padding-top: calc\([^\n]+safe-area-inset-top/g)||[]).length===3,'safe-area top padding survives every responsive breakpoint');
console.log(`WIDE LAYOUT: ${checks} checks passed; ${result.rules} generated rules`);
