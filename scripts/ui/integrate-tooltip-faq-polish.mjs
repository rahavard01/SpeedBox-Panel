import { readFileSync, writeFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

// Keep archived assets intact for open tabs and rotate the complete active
// import graph together so lazy pages use the same Vue runtime after refresh.
export function integrateTooltipFaqPolish(root, manifest) {
  const directory = path.join(root, 'public/build/assets');
  const active = new Map();
  const visit = file => {
    if (active.has(file)) return;
    const source = readFileSync(path.join(directory, file), 'utf8');
    active.set(file, source);
    for (const match of source.matchAll(/["']\.\/([^"']+\.js)["']/g)) visit(match[1]);
  };
  for (const entry of Object.values(manifest)) if (entry.file?.endsWith('.js')) visit(path.basename(entry.file));
  const mainFile = path.basename(manifest['resources/js/app.js'].file);
  if (active.get(mainFile).includes('/*speedbox-tooltip-faq-polish*/')) return;
  const replace = (source, before, after) => {
    if (source.split(before).length !== 2) throw Error('Tooltip/FAQ anchor changed: ' + before.slice(0, 100));
    return source.replace(before, after);
  };

  let main = active.get(mainFile);
  const start = main.indexOf('Sp={__name:"UiTooltip"');
  const end = main.indexOf(',bL={class:"mobile-card-table"', start);
  if (start < 0 || end < 0) throw Error('Shared UiTooltip component changed');
  main = main.slice(0, start) + 'Sp=createPanelTooltip({ref:G,mounted:be,unmounted:e2,block:k,element:x,text:b,component:b1,slots:lo,transition:l1,transitionComponent:Vd,empty:m0})' + main.slice(end);
  main += '\n/*speedbox-tooltip-faq-polish*/\n' + readFileSync(path.join(root, 'scripts/ui/dashboard-tooltip.mjs'), 'utf8').replace('export function ', 'function ') + '\n';
  active.set(mainFile, main);

  const dashboardFile = path.basename(manifest['resources/js/pages/admin/Dashboard.vue'].file);
  let dashboard = active.get(dashboardFile);
  dashboard = replace(dashboard, 's("div",{...Qs,tabindex:0,"data-sb-tooltip-tap":"","data-sb-tooltip":o.value.panelStatus.onlineIps==null?"در حال دریافت آمار آی‌پی‌ها":"آی‌پی‌های فعال: "+n(o.value.panelStatus.onlineIps)+"\\nآی‌پی‌های فعال حساب‌ها؛ اتصال تکراری روی چند نود یک‌بار شمرده می‌شود."},[', 's("div",Qs,[');
  dashboard = replace(dashboard, 's("div",se,l(n(o.value.panelStatus.usersOnline)),1)],8,["data-sb-tooltip"])', 'T(L,{class:"speedbox-dashboard-online-tip"},{trigger:g(()=>[s("span",se,l(n(o.value.panelStatus.usersOnline)),1)]),content:g(()=>[s("bdi",{dir:"rtl"},"آی‌پی‌های آنلاین: "+(o.value.panelStatus.onlineIps==null?"—":n(o.value.panelStatus.onlineIps)),1)]),_:1})])');
  active.set(dashboardFile, dashboard);

  const usersFile = path.basename(manifest['resources/js/pages/admin/Users.vue'].file);
  active.set(usersFile, replace(active.get(usersFile), 't("span",{class:"account-plan-badge",title:sa(a)},i(sa(a)),9,["title"])', 't("span",zs,i(sa(a)),1)'));

  const settingsFile = path.basename(manifest['resources/js/pages/admin/Settings.vue'].file);
  let settings = active.get(settingsFile);
  const factory = settings.indexOf('function createGlobalBotFaqEditor(');
  if (factory < 0) throw Error('Global FAQ editor factory missing');
  // The factory is the final appended section in this shipped Settings build.
  settings = settings.slice(0, factory) + readFileSync(path.join(root, 'scripts/ui/global-bot-faqs.mjs'), 'utf8').replace('export function ', 'function ');
  active.set(settingsFile, settings);

  const release = createHash('sha256').update([...active.values()].join('\n')).digest('hex').slice(0, 12);
  const names = new Map([...active.keys()].map(file => [file, file.replace(/\.js$/, '-polish-' + release + '.js')]));
  for (const [file, source] of active) {
    let result = source;
    for (const [oldName, newName] of names) result = result.replaceAll(oldName, newName);
    writeFileSync(path.join(directory, names.get(file)), result);
  }
  for (const entry of Object.values(manifest)) if (entry.file?.endsWith('.js')) entry.file = 'assets/' + names.get(path.basename(entry.file));
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
  const file = path.join(root, 'public/build/manifest.json');
  const manifest = JSON.parse(readFileSync(file, 'utf8'));
  integrateTooltipFaqPolish(root, manifest);
  writeFileSync(file, JSON.stringify(manifest, null, 2) + '\n');
  console.log('Tooltip and FAQ polish integrated:', manifest['resources/js/app.js'].file);
}
