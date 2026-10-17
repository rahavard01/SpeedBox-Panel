import { readFileSync, writeFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

// Preserve archived builds, and rotate only the active dependency graph.
export function integrateStage5(root) {
  const manifestPath = path.join(root, 'public/build/manifest.json');
  const manifest = JSON.parse(readFileSync(manifestPath, 'utf8'));
  const dir = path.join(root, 'public/build/assets');
  const read = file => readFileSync(path.join(dir, file), 'utf8');
  const active = new Map();
  function visit(file) {
    if (active.has(file)) return;
    const source = read(file); active.set(file, source);
    for (const match of source.matchAll(/["']\.\/([^"']+\.js)["']/g)) visit(match[1]);
  }
  for (const entry of Object.values(manifest)) if (entry.file?.endsWith('.js')) visit(path.basename(entry.file));
  const mainFile = path.basename(manifest['resources/js/app.js'].file);
  const settingsFile = path.basename(manifest['resources/js/pages/admin/Settings.vue'].file);
  const usersFile = path.basename(manifest['resources/js/pages/admin/Users.vue'].file);
  const dashboardFile = path.basename(manifest['resources/js/pages/admin/Dashboard.vue'].file);
  function change(file, before, after, count = 1) {
    const source = active.get(file);
    if (source.split(before).length - 1 !== count) throw Error('Stage 5 anchor changed in ' + file + ': ' + before.slice(0, 80));
    active.set(file, source.replaceAll(before, after));
  }
  if (!active.get(settingsFile).includes('/*speedbox-global-faq-editor*/')) {
    change(settingsFile, 'setup(L){const{success:v,error:o}=Ut(),', 'setup(L){const{success:v,error:o}=Ut();const speedboxGlobalFaqEditor=createGlobalBotFaqEditor({element:t,ref:A,mounted:E,unmounted:speedboxFaqUnmounted,http:b,feedback:v});const ');
    change(settingsFile, 't("div",jl,[', 'pe(speedboxGlobalFaqEditor),t("div",jl,[');
    const factory = readFileSync(path.join(root, 'scripts/ui/global-bot-faqs.mjs'), 'utf8').replace('export function ', 'function ');
    active.set(settingsFile, `import{W as speedboxFaqUnmounted}from"./${mainFile}";` + active.get(settingsFile) + '\n/*speedbox-global-faq-editor*/\n' + factory);
  }
  if (!active.get(mainFile).includes('/*speedbox-ip-tooltip*/')) {
    change(mainFile, 'b("div",DX,[a0[8]', 'b("div",{...DX,tabindex:0,"data-sb-tooltip-tap":"","data-sb-tooltip":_.value.panelStatus.onlineIps==null?"در حال دریافت آمار آی‌پی‌ها":"آی‌پی‌های فعال: "+c0(_.value.panelStatus.onlineIps)+"\\nآی‌پی‌های فعال حساب‌ها؛ اتصال تکراری روی چند نود یک‌بار شمرده می‌شود."},[a0[8]');
    change(mainFile, 'b("div",PX,j(c0(_.value.panelStatus.usersOnline)),1)]),b("div",IX', 'b("div",PX,j(c0(_.value.panelStatus.usersOnline)),1)],8,["data-sb-tooltip"]),b("div",IX');
    const old = "title: count === null ? 'در حال دریافت وضعیت اتصال' : 'اتصال‌های فعال بر اساس IP؛ سقف ' + (limit > 0 ? digits(limit) : 'نامحدود')";
    const next = "'data-sb-tooltip': limit > 0 ? 'سقف اتصال: ' + digits(limit) + ' کاربر' : 'سقف اتصال: نامحدود', 'data-sb-tooltip-tap': '', tabindex: 0";
    change(mainFile, old, next);
    active.set(mainFile, active.get(mainFile) + '\n/*speedbox-ip-tooltip*/\n');
  }
  if (!active.get(usersFile).includes('/*speedbox-online-tooltip*/')) {
    const old = "title:count===null?'در حال دریافت وضعیت اتصال':'اتصال‌های فعال بر اساس IP؛ سقف '+(limit>0?String(limit).replace(/[0-9]/g,digit=>'۰۱۲۳۴۵۶۷۸۹'[Number(digit)]):'نامحدود')";
    const next = "'data-sb-tooltip':limit>0?'سقف اتصال: '+String(limit).replace(/[0-9]/g,digit=>'۰۱۲۳۴۵۶۷۸۹'[Number(digit)])+' کاربر':'سقف اتصال: نامحدود','data-sb-tooltip-tap':'',tabindex:0";
    change(usersFile, old, next);
    active.set(usersFile, active.get(usersFile) + '\n/*speedbox-online-tooltip*/\n');
  }
  if (!active.get(dashboardFile).includes('/*speedbox-ip-tooltip*/')) {
    change(dashboardFile, 's("div",Qs,[e[14]', 's("div",{...Qs,tabindex:0,"data-sb-tooltip-tap":"","data-sb-tooltip":o.value.panelStatus.onlineIps==null?"در حال دریافت آمار آی‌پی‌ها":"آی‌پی‌های فعال: "+n(o.value.panelStatus.onlineIps)+"\\nآی‌پی‌های فعال حساب‌ها؛ اتصال تکراری روی چند نود یک‌بار شمرده می‌شود."},[e[14]');
    change(dashboardFile, 's("div",se,l(n(o.value.panelStatus.usersOnline)),1)]),s("div",ee', 's("div",se,l(n(o.value.panelStatus.usersOnline)),1)],8,["data-sb-tooltip"]),s("div",ee');
    active.set(dashboardFile, active.get(dashboardFile) + '\n/*speedbox-ip-tooltip*/\n');
  }
  const digest = source => createHash('sha256').update(source).digest('hex').slice(0, 12);
  if (active.get(mainFile).includes('/*speedbox-stage5-graph*/')) return;
  active.set(mainFile, active.get(mainFile) + '\n/*speedbox-stage5-graph*/\n');
  const release = digest([...active.values()].join('\n'));
  const names = new Map([...active.keys()].map(file => [file, file === mainFile
    ? 'app-speedbox-stage5-' + release + '.js'
    : file.replace(/\.js$/, '') + '-stage5-' + release + '.js']));
  const matcher = new RegExp([...names.keys()].map(file => file.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('|'), 'g');
  for (const [file, text] of active) writeFileSync(path.join(dir, names.get(file)), text.replace(matcher, name => names.get(name)));
  for (const entry of Object.values(manifest)) {
    const name = path.basename(entry.file || '');
    if (names.has(name)) entry.file = 'assets/' + names.get(name);
  }
  writeFileSync(manifestPath, JSON.stringify(manifest, null, 2) + '\n');
  return;
}
if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) integrateStage5(path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..'));
