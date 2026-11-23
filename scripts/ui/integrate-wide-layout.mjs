import {readFileSync,writeFileSync,existsSync} from 'node:fs';
import {createRequire} from 'node:module';
import path from 'node:path';

// Use Vite's existing PostCSS dependency; no browser-time style rewriting.
function parser(root){
  const require=createRequire(path.join(root,'package.json'));
  if(process.env.SPEEDBOX_POSTCSS_PATH)return require(process.env.SPEEDBOX_POSTCSS_PATH);
  return require(require.resolve('postcss',{paths:[path.dirname(require.resolve('vite/package.json'))]}));
}
const geometry=/^(?:width|height|min-width|max-width|min-height|max-height|inline-size|block-size|min-inline-size|max-inline-size|padding(?:-.+)?|margin(?:-.+)?|gap|row-gap|column-gap|font-size|font|line-height|letter-spacing|border(?:-.+)?-radius|border-radius|flex-basis|grid-template-columns|grid-template-rows|background-size|text-indent)$/;
const appRegions=/(?:modal|operation-|user-list-filter|user-row-actions|invoice-custom-select|account-plan-select|traffic-records|transaction-|speedbox-partner-history|user-actions|actions-select|segmented|seg-btn|btn-create-user1|save-btn|msg-text)/;
const scope=':root:root body';
const appSpacing=/(?:topup-title|table-toolbar|page-actions)/;
const appControls=/(?:\.btn-create-user[12]?(?![\w-])|\.table-toolbar\b.*\.form-control\b)/;
const reservationRegions=/(?:account-reservation|reservation-heading|reservation-facts|reservation-activation|reservation-feedback|reservation-primary-actions|reservation-symbol)/;

// The entry bundle also contains representative and authentication pages.
// Discover their Vue scopes from stable component selectors, so rebuilding a
// component cannot silently leave its wide-screen rules attached to an old ID.
// Nested controls have independent scopes, including their teleported dialogs.
const representativeMarkers=/\.(?:login-container|kpi-grid|topup-root|announce-excerpt|video-wrap|tickets-filter-wrap|mobile-agent-header|userbot-top-table|sms-device-manager)\b/;
const shellRegions=/\.(?:profile-dropdown|pd-[\w-]+|drawer-(?:submenu|sublink|wallet[\w-]*)|sub-dot|edit-form|edit-row|edit-label|edit-input|pw-wrap|pw-toggle|method-text1?)\b/;

export function scaleValue(value){
  // Only lengths: leave percentages, viewport caps, timings and colours intact.
  return value.replace(/(-?(?:\d*\.)?\d+)(px|rem)\b/g,(_,number,unit)=>{
    const n=Number(number)*(unit==='rem'?16:1);
    return n===0?'0':`calc(${Number(n.toFixed(6))} * var(--speedbox-ui-unit))`;
  });
}
function desktopBranch(rule){
  for(let p=rule.parent;p;p=p.parent){
    if(p.type!=='atrule')continue;
    if(/keyframes$/i.test(p.name))return false;
    if(p.name==='media'){
      // These files already have hand-written wide overrides. Generate from
      // baseline rules, not from those overrides (which would scale twice).
      if(/min-width\s*:\s*(?:1367|1[4-9]\d\d|[2-9]\d{3})px/i.test(p.params))return false;
      const branches=p.params.split(',');
      if(branches.every(branch=>{const m=branch.match(/max-width\s*:\s*(\d+)px/i);return m&&Number(m[1])<1367;}))return false;
    }
  }
  return true;
}
function stylesheetRows(postcss,text,kind){
  const rows=[];
  const ast=postcss.parse(text),representativeScopes=new Set();
  if(kind==='app')ast.walkRules(rule=>{
    if(representativeMarkers.test(rule.selector))for(const match of rule.selector.matchAll(/\[data-v-([\w-]+)\]/g))representativeScopes.add(match[1]);
  });
  ast.walkRules(rule=>{
    if(!desktopBranch(rule))return;
    const selectors=postcss.list.comma(rule.selector).filter(selector=>{
      const representative=kind==='app'&&[...representativeScopes].some(id=>selector.includes(`[data-v-${id}]`));
      if(kind==='app'&&!representative&&!shellRegions.test(selector)&&!appRegions.test(selector)&&!appSpacing.test(selector)&&!appControls.test(selector)&&!/(?:ban-row|ban-text|ios-toggle)/.test(selector))return false;
      if(kind==='reservation'&&!reservationRegions.test(selector))return false;
      if(/dashboard-overview|topbar-holder|side-drawer|hamburger-menu/.test(selector)&&!shellRegions.test(selector))return false;
      return !/^(?:html|body|:root|\*|from|to|\d+%)$/.test(selector.trim());
    }).map(selector=>{
      // The dashboard already has its own complete responsive treatment.
      selector=selector.replace(/\.speedbox-infrastructure(?![-\w])/g,'.speedbox-infrastructure:not(:has(.dashboard-overview))');
      return scope+' '+selector.replace(/^(?:html\s+)?body\s+/, '');
    });
    if(!selectors.length)return;
    const declarations=[];
    rule.each(d=>{
      if(d.type!=='decl')return;
      // Runtime Blade values (for example traffic progress) stay in the page.
      if(d.value.includes('--speedbox-template-value'))return;
      // Preserve title/action/toolbar spacing around standalone filters without
      // replaying their unrelated field widths or layout declarations.
      const representative=kind==='app'&&[...representativeScopes].some(id=>rule.selector.includes(`[data-v-${id}]`));
      if(kind==='app'&&!representative&&appSpacing.test(rule.selector)&&!appRegions.test(rule.selector)&&!appControls.test(rule.selector)&&!/^(?:margin(?:-.+)?|gap|row-gap|column-gap)$/.test(d.prop))return;
      const toggleOffset=/\.ios-toggle\b/.test(rule.selector)&&/^(?:top|left|transform)$/.test(d.prop);
      // Only the dropdown's baseline gap scales; script-placed wrapper anchors
      // and unrelated menu positions must remain untouched.
      const panelMenuOffset=/\.speedbox-panel-switch__menu\b/.test(rule.selector)&&d.prop==='top';
      const componentOffset=(representative||kind==='subscription')&&/^(?:top|right|bottom|left|inset(?:-.+)?|transform|flex)$/.test(d.prop);
      if(!geometry.test(d.prop)&&!toggleOffset&&!panelMenuOffset&&!componentOffset&&!(d.prop.startsWith('--')&&/\d(?:px|rem)\b/.test(d.value)))return;
      if(d.prop==='font'){
        const size=d.value.match(/(-?(?:\d*\.)?\d+)(px|rem)\b/);
        if(size)declarations.push(`font-size:${scaleValue(size[0])}!important`);
        return; // A font shorthand would also reset family, weight and style.
      }
      const value=scaleValue(d.value);
      // Font rules must also beat the previous inherited-font !important rules.
      declarations.push(`${d.prop}:${value}${d.important||d.prop==='font-size'?'!important':''}`);
    });
    if(!declarations.length)return;
    let css=selectors.join(',')+'{'+declarations.join(';')+'}';
    for(let p=rule.parent;p&&p.type!=='root';p=p.parent){
      if(p.type==='atrule'&&p.name!=='layer')css=`@${p.name} ${p.params}{${css}}`;
    }
    rows.push(css);
  });
  return rows;
}

export function integrateWideLayout(root){
  const postcss=parser(root),read=name=>readFileSync(path.join(root,name),'utf8').replace(/\r\n/g,'\n');
  const manifest=JSON.parse(read('public/build/manifest.json'));
  const sources=[];
  for(const [entry,asset] of Object.entries(manifest)){
    if(entry!=='resources/js/app.js'&&!/^resources\/js\/pages\/admin\//.test(entry))continue;
    for(const css of asset.css||[])sources.push({name:'public/build/'+css,kind:entry==='resources/js/app.js'||entry==='resources/js/pages/admin/Dashboard.vue'?'app':'page'});
  }
  const rows=[];
  for(const source of sources)rows.push(...stylesheetRows(postcss,read(source.name),source.kind));
  for(const name of ['panel-controls','bot-faqs','infrastructure','network-tunnels','tunnels','emergency-configs','partner-statistics']){
    rows.push(...stylesheetRows(postcss,read(`public/speedbox/${name}.css`),'page'));
  }
  rows.push(...stylesheetRows(postcss,read('resources/css/alerts.css'),'page'));
  rows.push(...stylesheetRows(postcss,read('resources/css/subscription-reservations.css'),'reservation'));
  // Match the baseline cascade: specialized account controls override the
  // shared infrastructure form rules at equal specificity.
  for(const name of ['UserAccountActions','UserActionsSelect','UserListFilters']){
    const file=`resources/js/components/${name}.vue`;
    if(!existsSync(path.join(root,file)))continue;
    for(const block of read(file).matchAll(/<style\b[^>]*>([\s\S]*?)<\/style>/g))rows.push(...stylesheetRows(postcss,block[1],'page'));
  }
  const overrides=read('scripts/ui/wide-layout-overrides.css');
  const css='/* Generated by scripts/ui/integrate-wide-layout.mjs. Baseline: 1366px. */\n'
    +'.speedbox-infrastructure .dashboard-summary .dashboard-card-title>i{color:silver;font-size:18px}\n'
    +'@media(min-width:1367px){\n:root{--speedbox-ui-unit:.07320644vw}\n'+rows.join('\n')+'\n'+overrides+'\n}\n';
  postcss.parse(css); // Fail the build on malformed generated CSS.
  writeFileSync(path.join(root,'public/speedbox/wide-layout.css'),css);
  // The public subscription is a standalone Blade page, outside the app bundle.
  const subscription=read('resources/views/client/subscribe.blade.php');
  const subscriptionRows=[];
  for(const block of subscription.matchAll(/<style\b[^>]*>([\s\S]*?)<\/style>/g)){
    if(block[1].includes('@font-face'))continue;
    const style=block[1].replace(/\{\{[\s\S]*?\}\}/g,'var(--speedbox-template-value)');
    subscriptionRows.push(...stylesheetRows(postcss,style,'subscription'));
  }
  const subscriptionCss='/* Generated by scripts/ui/integrate-wide-layout.mjs. Baseline: 1366px. */\n@media(min-width:1367px){\n:root{--speedbox-ui-unit:.07320644vw}\n'+subscriptionRows.join('\n')+'\n}\n';
  postcss.parse(subscriptionCss);
  writeFileSync(path.join(root,'public/speedbox/subscription-wide.css'),subscriptionCss);
  return {rules:rows.length,sources:sources.length};
}



