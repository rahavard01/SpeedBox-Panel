import { readFileSync, writeFileSync, readdirSync, unlinkSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import { integrateTariffHeader } from './ui/integrate-tariff-header.mjs';
import { integrateModalSelects } from './ui/integrate-modal-selects.mjs';
import { integrateSettingsSaves } from './ui/integrate-settings-saves.mjs';
import { integratePartnerPanel } from './ui/integrate-partner-panel.mjs';
import { integratePurchaseRefresh } from './ui/integrate-purchase-refresh.mjs';
import { integrateFinalPolish } from './ui/integrate-final-polish.mjs';
import { integrateReviewFixes } from './ui/integrate-review-fixes.mjs';
import { integrateLastPolish } from './ui/integrate-last-polish.mjs';
import { integrateBranding } from './ui/integrate-branding.mjs';

// The imported Panel repository ships its Vue build, without the Vue source tree.
// Keep this bridge reproducible and fail if that build changes rather than silently
// mounting a second admin layout. The new page remains in the original Admin route.
const root = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const read = p => readFileSync(path.join(root, p), 'utf8');
const write = (p, s) => writeFileSync(path.join(root, p), s);
const manifest = JSON.parse(read('public/build/manifest.json'));
integrateTariffHeader(root, manifest);
integrateModalSelects(root, manifest);
integrateSettingsSaves(root, manifest);
integratePartnerPanel(root, manifest);
integratePurchaseRefresh(root, manifest);
integrateFinalPolish(root, manifest);
integrateReviewFixes(root, manifest);
integrateLastPolish(root, manifest);
integrateBranding(root, manifest);
const entry = manifest['resources/js/app.js'];
const oldName = path.basename(entry.file);
let bundle = read('public/build/' + entry.file);
const oldHook = 'window.__SPEEDBOX_EXTEND_PANEL__?.({router:us,element:b,onMounted:be,onUnmounted:e2,notifications:ot});';
const previousHook = 'window.__SPEEDBOX_EXTEND_PANEL__?.({router:us,element:b,onMounted:be,onUnmounted:e2,watch:x1,notifications:ot});';
const hook = 'window.__SPEEDBOX_EXTEND_PANEL__?.({router:us,element:b,onMounted:be,onUnmounted:e2,watch:x1,ref:G,nextTick:Ue,notifications:ot});';
bundle = bundle.replace(oldHook, hook).replace(previousHook, hook);
if (!bundle.includes(hook)) {
  const anchor = 'Cs.use(us);Cs.mount("#app")';
  if (!bundle.includes(anchor)) throw new Error('Panel bootstrap changed; update the integration bridge.');
  bundle = bundle.replace(anchor, hook + anchor);
}
const drawerBranch = nativeMenu => '/*speedbox-drawer-switch:start*/(o.path.startsWith("/admin/infrastructure")?(k(),x(j0,{key:"speedbox-server-menu"},window.__SPEEDBOX_SERVER_MENU__({element:b,component:b1,link:Y0,slot:l1}),64)):(k(),x(j0,{key:"speedbox-main-menu"},[' + nativeMenu + '],64)))/*speedbox-drawer-switch:end*/';
// A keyed Vue fragment isolates the menu branches from the shell's compiled block.
bundle = bundle.replace(/\/\*speedbox-drawer-switch:start\*\/\.\.\.\(o\.path\.startsWith\("\/admin\/infrastructure"\)\?window\.__SPEEDBOX_SERVER_MENU__\(\{element:b,component:b1,link:Y0,slot:l1\}\):\[([\s\S]*?)\]\)\/\*speedbox-drawer-switch:end\*\//, (_, nativeMenu) => drawerBranch(nativeMenu));
if (!bundle.includes('/*speedbox-drawer-switch:start*/')) {
  const adminStart = bundle.indexOf('LC={__name:"Admin"');
  const start = bundle.indexOf('b1(Y0,{class:"drawer-link",to:n("")}', adminStart);
  const end = bundle.indexOf(']),b("div",eC,', start);
  if (adminStart < 0 || start < 0 || end < 0) throw new Error('Panel drawer changed; update the integration bridge.');
  const oldEntry = 'b1(Y0,{class:"drawer-link","data-speedbox-menu":"true",to:n("infrastructure")},{default:l1(()=>[b("span",null,"پنل مدیریت سرورها"),b("i",{class:"fas fa-server","aria-hidden":"true"})]),_:1},8,["to"]),';
  const nativeMenu = bundle.slice(start, end).replace(oldEntry, '');
  const entry = oldEntry.slice(0, -1);
  const switchedMenu = drawerBranch(nativeMenu + ',' + entry);
  bundle = bundle.slice(0, start) + switchedMenu + bundle.slice(end);
}
// Move the mode switch out of both drawers and into the original Admin top bar.
const serverDrawerEntry = 'b1(Y0,{class:"drawer-link","data-speedbox-menu":"true",to:n("infrastructure")},{default:l1(()=>[b("span",null,"پنل مدیریت سرورها"),b("i",{class:"fas fa-server","aria-hidden":"true"})]),_:1},8,["to"])';
bundle = bundle.replace(',' + serverDrawerEntry, '').replace(serverDrawerEntry + ',', '');
// Remove the previous two-line control beside the profile icon.
bundle = bundle.replace(/\/\*speedbox-topbar-switch\*\/[\s\S]*?(?=b\("div",\{onClick:o0\[0\])/, '');
if (!bundle.includes('/*speedbox-panel-dropdown*/')) {
  const adminStart = bundle.indexOf('LC={__name:"Admin"');
  const start = bundle.indexOf('b("div",{onClick:P0(H0,["stop"]),class:"hamburger-menu"', adminStart);
  const end = bundle.indexOf(',b("div",FE,', start);
  if (adminStart < 0 || start < 0 || end < 0) throw new Error('Panel hamburger changed; update the integration bridge.');
  const hamburger = bundle.slice(start, end);
  const dropdown = 'b1(window.__SPEEDBOX_PANEL_SWITCH__,{serverMode:o.path.startsWith("/admin/infrastructure"),beforeOpen:()=>{m.value=!1;t0.value=!1;H.value=!1;y0()},navigate:serverMode=>{t0.value=!1;H.value=!1;y0();t.push(serverMode?"/admin/infrastructure":"/admin").catch(()=>{})}},null,8,["serverMode"])';
  bundle = bundle.slice(0, start) + '/*speedbox-panel-dropdown*/b("div",{class:"speedbox-navigation-actions"},[' + dropdown + ',' + hamburger + '])' + bundle.slice(end);
}
// The existing center logo returns to the dashboard of the currently selected mode.
const homeLink = 'const o0=S0?`/admin/${S0}`:"/admin";t.push(o0)';
const modeHomeLink = 'const o0=S0?`/admin/${S0}`:o.path.startsWith("/admin/infrastructure")?"/admin/infrastructure":"/admin";t.push(o0)';
if (bundle.includes(homeLink)) bundle = bundle.replace(homeLink, modeHomeLink);
else if (!bundle.includes(modeHomeLink)) throw new Error('Panel home navigation changed.');
bundle = bundle.replace('A1=N0(()=>K0.value>0&&!H.value)', 'A1=N0(()=>!o.path.startsWith("/admin/infrastructure")&&K0.value>0&&!H.value)');
const newName = 'app-speedbox-' + createHash('sha256').update(bundle).digest('hex').slice(0, 12) + '.js';
write('public/build/assets/' + newName, bundle);
for (const name of readdirSync(path.join(root, 'public/build/assets'))) {
  if (name.endsWith('.js') && name !== oldName && name !== newName && !name.startsWith('app-speedbox-')) {
    const source = read('public/build/assets/' + name);
    const next = source.replaceAll(oldName, newName);
    if (next !== source) write('public/build/assets/' + name, next);
  }
}
entry.file = 'assets/' + newName;
write('public/build/manifest.json', JSON.stringify(manifest, null, 2) + '\n');
// Keep the previous entry available for tabs that loaded it before this update.
write('public/speedbox/register-infrastructure.js', read('scripts/ui/register-infrastructure.js'));

// Reuse the actual rules of existing controls rather than approximating their theme.
const mainCss = entry.css.map(file => read('public/build/' + file)).join('\n');
const settingsCss = manifest['resources/js/pages/admin/Settings.vue'].css.map(file => read('public/build/' + file)).join('\n');
const dashboardCss = manifest['resources/js/pages/admin/Dashboard.vue'].css.map(file => read('public/build/' + file)).join('\n');
const rules = css => [...css.matchAll(/([^{}]+)\{([^{}]*)\}/g)].map(m => ({
  selector: m[1].trim().replace(/\[data-v-[\w-]+\]/g, ''), body: m[2]
}));
const scope = ':where(.speedbox-infrastructure,.speedbox-infrastructure-overlay)';
const shared = ['/* Generated by scripts/integrate-panel-ui.mjs from the existing Panel styles. */'];
function reuse(css, selector, aliases = selector, ruleScope = scope) {
  const rule = rules(css).filter(r => r.selector === selector).at(-1);
  if (!rule) throw new Error('Missing shared Panel style: ' + selector);
  shared.push(aliases.split(',').map(s => ruleScope + ' ' + s.trim()).join(',') + '{' + rule.body + '}');
}
reuse(mainCss, '.btn-create-user', '.button');
reuse(mainCss, '.btn-create-user', '.speedbox-panel-switch__trigger', '.speedbox-panel-switch');
reuse(mainCss, '.invoice-custom-select__menu', '.speedbox-panel-switch__menu', '.speedbox-panel-switch');
reuse(mainCss, '.invoice-custom-select__option', '.speedbox-panel-switch__option', '.speedbox-panel-switch');
reuse(settingsCss, '.settings-card', '.panel-card');
reuse(dashboardCss, '.kpi-box', '.stat-card');
reuse(dashboardCss, '.kpi-label', '.stat-label');
reuse(dashboardCss, '.kpi-value', '.stat-value');
reuse(settingsCss, '.form-input', '.form-input');
reuse(settingsCss, '.form-input:focus');
for (const rule of rules(settingsCss)) {
  if (rule.selector.startsWith('.switch-line.ios input[type=checkbox]')) {
    shared.push(rule.selector.split(',').map(s => scope + ' ' + s.trim()).join(',') + '{' + rule.body + '}');
  }
}
const semanticScope = ':is(.speedbox-infrastructure,.speedbox-infrastructure-overlay)';
const semanticControls = [[".btn-create-user:hover",".button:hover:not(:disabled)"],[".btn-create-user1",".button.primary,.button.success"],[".btn-create-user1:hover",".button.primary:hover:not(:disabled),.button.success:hover:not(:disabled)"],[".create-modal .back-button a",".button.cancel,.button.danger"],[".create-modal .back-button a:hover",".button.cancel:hover:not(:disabled),.button.danger:hover:not(:disabled)"],[".segmented",".segmented"],[".seg-btn",".seg-btn"],[".seg-btn.active",".seg-btn.active"],[".create-modal .tariff-tabs .seg-btn:not(.active):hover",".seg-btn:not(.active):hover"]];
for (const [source, aliases] of semanticControls) {
  const rule = rules(mainCss).find(rule => rule.selector === source);
  if (!rule) throw new Error('Missing original Panel control: ' + source);
  let body = rule.body;
  if (source === '.btn-create-user1') body += ';background:#2cf07e66;margin:0;padding:8px 20px';
  if (source === '.create-modal .back-button a') body += ';margin:0';
  if (source === '.segmented') body += ';margin:0 0 14px';
  shared.push(aliases.split(',').map(alias => semanticScope + ' ' + alias).join(',') + '{' + body + '}');
}
const modalControls = [[".create-modal",".infrastructure-modal"],[".create-modal .final-result1",".infrastructure-modal .modal-fields"],[".create-modal .create-header",".infrastructure-modal .create-header"],[".create-modal .create-header h3",".infrastructure-modal .create-header h3"],[".edit-form .edit-input",".infrastructure-modal .form-input"],[".edit-form .edit-label",".infrastructure-modal .field>label"],[".btn-create-user",".toolbar .button.primary,.panel-head .button.primary"],[".btn-create-user:hover",".toolbar .button.primary:hover:not(:disabled),.panel-head .button.primary:hover:not(:disabled)"]];
for (const [source, aliases] of modalControls) {
  const rule = rules(mainCss).find(rule => rule.selector === source);
  if (!rule) throw new Error('Missing original Panel modal style: ' + source);
  let body = rule.body;
  if (source === '.create-modal .final-result1') body += ';width:100%;max-width:100%!important;justify-content:stretch;margin:0';
  if (source === '.btn-create-user') body += ';margin:0';
  if (source === '.btn-create-user:hover') body += ';color:#fff;border-color:rgba(255,255,255,.2)';
  shared.push(aliases.split(',').map(alias => semanticScope + ' ' + alias).join(',') + '{' + body + '}');
}
shared.push(['.button.primary','.button.success','.button.cancel','.button.danger'].map(selector => semanticScope + ' ' + selector).join(',') + '{box-shadow:none}');
const announcementCss = manifest['resources/js/pages/admin/Announcements.vue'].css.map(file => read('public/build/' + file)).join('\n');
const feedbackControls = [["ann",".sel,.inp,textarea.inp",".infrastructure-modal input.form-input,.infrastructure-modal textarea.form-input"],["ann",".actions .btn-mini",".button.entity-edit,.button.entity-delete,.button.entity-install,.button.entity-restart,.button.entity-report"],["ann",".actions .btn-mini:hover",".button.entity-edit:hover:not(:disabled),.button.entity-delete:hover:not(:disabled),.button.entity-install:hover:not(:disabled),.button.entity-restart:hover:not(:disabled),.button.entity-report:hover:not(:disabled)"],["ann",".actions .btn-mini.danger",".button.entity-delete"],["main",".creating-overlay",".creating-overlay"],["main",".creating-box",".creating-box"]];
for (const [source, selector, aliases] of feedbackControls) {
  const rule = rules(source === 'ann' ? announcementCss : mainCss).find(rule => rule.selector === selector);
  if (!rule) throw new Error('Missing original Panel feedback control: ' + selector);
  let body = rule.body;
  if (selector === '.sel,.inp,textarea.inp') body += ';font-size:12px;height:34px;line-height:normal;font-family:inherit';
  if (selector === '.actions .btn-mini') body += ';display:inline-flex;align-items:center;justify-content:center;height:29px;line-height:17px;box-sizing:border-box;margin:0;box-shadow:none;gap:0;white-space:nowrap';
  shared.push(aliases.split(',').map(alias => semanticScope + ' ' + alias).join(',') + '{' + body + '}');
}
// Server actions retain the Announcement geometry with semantic colors.
const installButton = rules(announcementCss).find(rule => rule.selector === '.actions .btn-mini.warn');
if (!installButton) throw new Error('Missing original Panel install action style.');
shared.push(semanticScope + ' .button.entity-install{' + installButton.body + '}');
shared.push(semanticScope + ' .button.entity-restart{background:linear-gradient(180deg,#70501f,#ad803f91)!important}');
shared.push(semanticScope + ' .button.entity-report{background:linear-gradient(180deg,#35446b,#6478a591)!important}');
// Bind the new dropdown DOM to the original InvoiceFilterSelect's own scoped CSS.
// Keep its geometry and behaviour; the reference palette below fixes parent tint.
const invoiceScope = mainCss.match(/\.invoice-custom-select__trigger\[(data-v-[\w-]+)\]/)?.[1];
if (!invoiceScope) throw new Error('Original InvoiceFilterSelect CSS scope changed.');
shared.push('.speedbox-infrastructure,.speedbox-infrastructure-overlay{--speedbox-invoice-scope:' + invoiceScope + '}');
// The supplied invoice screenshot is the appearance reference. Preserve the
// original control geometry, with opaque colors so every parent renders alike.
const invoiceClosedSurface = 'linear-gradient(180deg,#4c4f56 0%,#484b50 25%,#484745 55%,#49413e 100%)';
const invoiceOpenSurface = 'linear-gradient(180deg,#303030,#2c2c2c)';
shared.push('.speedbox-infrastructure,.speedbox-infrastructure-overlay{--speedbox-select-closed:' + invoiceClosedSurface + ';--speedbox-select-open:' + invoiceOpenSurface + '}');
for (const [selector, surface] of [['.invoice-custom-select__trigger', 'var(--speedbox-select-closed)'], ['.invoice-custom-select__menu', 'var(--speedbox-select-open)']]) {
  const rule = rules(mainCss).find(rule => rule.selector === selector);
  if (!rule) throw new Error('Original invoice surface changed.');
  shared.push(semanticScope + ' ' + selector + '{' + rule.body.replace(/background:[^;]+;/, 'background:' + surface + ';') + '}');
}
shared.push(semanticScope + ' .invoice-custom-select__option:hover,' + semanticScope + ' .invoice-custom-select__option.is-selected{background:#434343;color:#fff}');
const searchRule = rules(mainCss).filter(rule => rule.selector === '.table-toolbar .search-field .form-control' && rule.body.includes('background:')).at(-1);
if (!searchRule) throw new Error('Original Panel search control changed.');
shared.push('.speedbox-infrastructure .entity-toolbar .search{' + searchRule.body + ';height:38px;margin:0;padding:0 14px;width:auto;min-width:0;flex:1 1 0}');
for (const suffix of [':focus','::placeholder',':focus::placeholder']) {
  for (const rule of rules(mainCss).filter(rule => rule.selector === '.table-toolbar .search-field .form-control' + suffix)) {
    shared.push('.speedbox-infrastructure .entity-toolbar .search' + suffix + '{' + rule.body + '}');
  }
}
write('public/speedbox/panel-controls.css', shared.join('\n') + '\n/*speedbox-partner-account-styles*/\n' + read('scripts/ui/partner-accounts.css'));
write('public/speedbox/panel-controls.css', read('public/speedbox/panel-controls.css') + '\n' + read('scripts/ui/mobile-selects.css'));
write('public/speedbox/panel-controls.css', read('public/speedbox/panel-controls.css') + '\n' + read('scripts/ui/review-fixes.css'));
write('public/speedbox/panel-controls.css', read('public/speedbox/panel-controls.css') + '\n/*speedbox-last-polish-styles*/\n' + read('scripts/ui/last-polish.css'));
write('public/speedbox/panel-controls.css', read('public/speedbox/panel-controls.css') + '\n/*speedbox-visual-review-styles*/\n' + read('scripts/ui/visual-review.css'));
console.log('Panel shell and shared controls integrated:', newName);



