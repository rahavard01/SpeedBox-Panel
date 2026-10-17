import { readFileSync, writeFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

// Guarded edits to the shipped Panel build; keep all prior assets for open tabs.
export function integrateLastPolish(root, manifest) {
  const directory = path.join(root, 'public/build/assets');
  const read = file => readFileSync(path.join(directory, file), 'utf8');
  const active = new Map();
  const visit = file => {
    if (active.has(file) || !file.endsWith('.js')) return;
    const source = read(file); active.set(file, source);
    for (const match of source.matchAll(/["']\.\/([^"']+\.js)["']/g)) visit(match[1]);
  };
  for (const entry of Object.values(manifest)) if (entry.file?.endsWith('.js')) visit(path.basename(entry.file));
  const mainFile = path.basename(manifest['resources/js/app.js'].file);
  const change = (source, before, after, count = 1) => {
    if (source.split(before).length - 1 !== count) throw Error('Final polish anchor changed: ' + before.slice(0, 100));
    return source.replaceAll(before, after);
  };
  let main = active.get(mainFile);
  if (!main.includes('/*speedbox-last-polish*/')) {
    const partnerStart = main.indexOf('__name:"Panel"');
    const replaceFunction = (name, replacement) => {
      const start = main.indexOf('async function ' + name + '()', partnerStart);
      if (start < 0) throw Error('Partner handler missing: ' + name);
      main = main.slice(0, start) + replacement + main.slice(functionEnd(main, start));
    };
    replaceFunction('k2', 'async function k2(){await speedboxRefreshPartnerNotices(false)}');
    replaceFunction('xs', 'async function xs(){await speedboxRefreshPartnerNotices(true)}');
    replaceFunction('Fo', 'async function Fo(){await speedboxRefreshPartnerNotices(false)}');
    main = change(main, 'async function k2()', `let speedboxNoticeRequest=null;
async function speedboxRefreshPartnerNotices(showToasts){
  if(!lM())return;
  try{
    if(!speedboxNoticeRequest)speedboxNoticeRequest=w0.get("/tickets/unread/count").then(response=>response.data).finally(()=>{speedboxNoticeRequest=null});
    const data=await speedboxNoticeRequest;
    B.value=Number(data?.count||0);R.value=Number(data?.announcement_count||0);
    try{localStorage.setItem(Ep,String(R.value))}catch{}
    if(showToasts)for(const notice of data?.notifications||[])re(
      notice.ticket_number?"پاسخ جدیدی برای تیکت شماره "+new Intl.NumberFormat("fa-IR").format(notice.ticket_number)+" ثبت شد":"پاسخ جدیدی برای تیکت شما ثبت شد",
      {mode:"global",notificationKey:"ticket-reply:"+notice.id});
  }catch{}
}
async function k2()`);
    main = change(main, 'R.value=s.pending_counts.announcements??R.value,localStorage.setItem(Ep,String(R.value))', 'R.value=Number(s.pending_counts.announcements??0),B.value=Number(s.pending_counts.tickets??0),localStorage.setItem(Ep,String(R.value))');
    main = change(main, 'try{const v=Number(localStorage.getItem(Ep));!Number.isNaN(v)&&v>(R.value??0)&&(R.value=v)}catch{}', '');
    main = change(main, 'xs(),Fo(),L=Number(B.value||0)', 'xs(),L=Number(B.value||0)');
    main = change(main, 'to(),xs(),Fo()},15e3)', 'to(),xs()},15e3)');
    main = change(main, '+" :تاریخ انتشار"', '', 2);
    main = change(main, 'b("div",Hw,[b("div",{class:"create-modal",onClick:l[61]', 'b("div",Hw,[b("div",{class:"create-modal tariff-view-modal",onClick:l[61]');
    main += '\n/*speedbox-last-polish*/\n';
    active.set(mainFile, main);
  }
  if (!main.includes('/*speedbox-persist-notice-navigation*/')) {
    main = change(main, 'replace:s=!0,closable:c=!0}={}){const a=Date.now()', 'replace:s=!0,closable:c=!0,persistentNotice:A=!1}={}){const a=Date.now()');
    main = change(main, 'duration:o,leaving:!1,position:t6(n)', 'duration:o,persistentNotice:A,leaving:!1,position:t6(n)');
    main = change(main, 'display:(message,options)=>Bc({type:"notif",message,...options}),canDisplay:', 'display:(message,options)=>Bc({type:"notif",message,...options,persistentNotice:true}),canDisplay:');
    main = change(main, 'const o=()=>{t?et.items.splice(0):(et.items.forEach(n=>n.leaving=!0),setTimeout(()=>et.items.splice(0),320))};', 'const o=()=>{const items=et.items.filter(item=>!item.persistentNotice);t?items.forEach(item=>Ou(item.id)):(items.forEach(item=>item.leaving=!0),setTimeout(()=>items.forEach(item=>Ou(item.id)),320))};');
    main += '\n/*speedbox-persist-notice-navigation*/\n';
    active.set(mainFile, main);
  }
  if (!main.includes('/*speedbox-preserve-startup-replies*/')) {
    main = change(main, 'be(async()=>{ea(),await Kn(),await ub(),fe({immediate:!0})})', 'be(async()=>{ea(),await Kn(),await ub(),et.items.filter(item=>!item.persistentNotice).forEach(item=>Ou(item.id))})');
    main += '\n/*speedbox-preserve-startup-replies*/\n';
    active.set(mainFile, main);
  }
  if (!main.includes('/*speedbox-notice-logout-scope*/')) {
    main = change(main, 'const data=await speedboxNoticeRequest;\n    B.value=', 'const data=await speedboxNoticeRequest;\n    if(!lM())return;\n    B.value=');
    main = change(main, 'const items=et.items.filter(item=>!item.persistentNotice);t?', 'const items=et.items.filter(item=>!item.persistentNotice||!localStorage.getItem("token"));t?');
    main += '\n/*speedbox-notice-logout-scope*/\n';
    active.set(mainFile, main);
  }
  if (!main.includes('/*speedbox-visual-card-order*/')) {
    const marker = 'b("i",{class:q0(["fas fa-circle",{"circle-unread":m(C)}])},null,2)';
    main = change(main, 'b("h3",cS,[f0(j(C.title||"—")+" ",1),' + marker + '])', 'b("h3",cS,[' + marker + ',f0(j(C.title||"—")+" ",1)])');
    const category = 'b("span",{class:q0([B(_.category).cls,"chip-right"])},[b("i",{class:q0(B(_.category).icon),"aria-hidden":"true"},null,2),f0(" "+j(B(_.category).text),1)],2)';
    main = change(main, 'b("div",MX,[b("span",bX,j(S(_.published_at)),1),' + category + '])', 'b("div",MX,[' + category + ',b("span",bX,j(S(_.published_at)),1)])');
    main += '\n/*speedbox-visual-card-order*/\n';
    active.set(mainFile, main);
  }

  const settingsFile = path.basename(manifest['resources/js/pages/admin/Settings.vue'].file);
  let settings = active.get(settingsFile);
  if (!settings.includes('/*speedbox-compact-tariff-controls*/')) {
    for (const [kind, actions, binding] of [['volume_groups', 'Ht', 'Xt'], ['groups', 'fs', 'bs']]) {
      const access = `t("button",{type:"button",class:"partner-access-badge1",onClick:I(r=>me(n),["stop"])},m(_e(n)),9,${binding})`;
      const old = `d(speedboxTariffConnection(n),[[S,!B.${kind}]]),t("div",${actions},[${access},`;
      const next = `d(t("div",{class:"tariff-secondary-controls"},[${access},pe(speedboxTariffLimitControl,{group:n,options:N.value},null,8,["group","options"])]),[[S,!B.${kind}]]),t("div",${actions},[`;
      settings = change(settings, old, next);
    }
    const factory = readFileSync(path.join(root, 'scripts/ui/tariff-limit-control.mjs'), 'utf8').replace('export function ', 'function ');
    settings += '\n/*speedbox-compact-tariff-controls*/\n' + factory + '\nconst speedboxTariffLimitControl=createSpeedboxTariffLimitControl({element:t,ref:A});\n';
    active.set(settingsFile, settings);
  }
  if (!settings.includes('/*speedbox-limit-control-shape*/')) {
    if (settings.includes("text = 'پیش‌فرض · ' +")) settings = change(settings, "text = 'پیش‌فرض · ' +", "text = 'پیش‌فرض -' +");
    else if (!settings.includes("text = 'پیش‌فرض -' +")) throw Error('Connection display prefix changed');
    if (settings.includes("class: 'form-input', type: 'text', inputmode: 'numeric'")) settings = change(settings, "class: 'form-input', type: 'text', inputmode: 'numeric'", "class: 'form-input tariff-limit-input', type: 'text', inputmode: 'numeric'");
    else if (!settings.includes("class: 'form-input tariff-limit-input', type: 'text', inputmode: 'numeric'")) throw Error('Connection input class changed');
    settings += '\n/*speedbox-limit-control-shape*/\n';
    active.set(settingsFile, settings);
  }

  const helperFile = [...active.keys()].find(file => active.get(file).includes('__name:"UserAccountActions"'));
  if (!helperFile) throw Error('Account action helper missing');
  let helper = active.get(helperFile);
  if (!helper.includes('/*speedbox-delete-user-wording*/')) {
    const encoded = text => JSON.stringify(text).replace(/[^\x00-\x7f]/g, char => '\\u' + char.charCodeAt(0).toString(16).toUpperCase().padStart(4, '0'));
    helper = change(helper, 'delete:' + encoded('حذف اکانت'), 'delete:' + JSON.stringify('حذف کاربر'));
    helper = change(helper, encoded('این اکانت حذف می‌شود و اتصال ربات آن بسته می‌شود. سوابق مالی حفظ می‌شوند و بازپرداخت خودکار انجام نمی‌شود.'), JSON.stringify('این کاربر حذف خواهد شد، اما سوابق مالی او حفظ می‌شود.'));
    helper = change(helper, encoded('تأیید حذف اکانت'), JSON.stringify('تأیید حذف'));
    helper = change(helper, 'e[50]||(e[50]=m(' + encoded('اکانت: ') + ',-1))', 'm(f.value==="delete"?"نام کاربری: ":"اکانت: ")');
    helper = change(helper, 'class:"button danger operation-danger"', 'class:f.value==="delete"?"button operation-delete-confirm":"button danger operation-danger"');
    helper += '\n/*speedbox-delete-user-wording*/\n';
    active.set(helperFile, helper);
  }

  // Rotate changed leaves first, then the root, then point every active module to
  // the same root. Archived modules are not rewritten or removed.
  for (const [file, prefix] of [[settingsFile, 'Settings-final-'], [helperFile, 'SpeedboxAccountActions-final-']]) {
    const source = active.get(file);
    if (existsEqual(file, source)) continue;
    const next = prefix + digest(source) + '.js';
    if (file === next) continue;
    active.delete(file); active.set(next, source);
    for (const [other, text] of active) active.set(other, text.replaceAll(file, next));
    for (const entry of Object.values(manifest)) if (entry.file === 'assets/' + file) entry.file = 'assets/' + next;
  }
  const nextMain = 'app-speedbox-' + digest(active.get(mainFile)) + '.js';
  if (nextMain !== mainFile) {
    const source = active.get(mainFile); active.delete(mainFile); active.set(nextMain, source);
    for (const [file, text] of active) if (file !== nextMain) active.set(file, text.replaceAll(mainFile, nextMain));
    manifest['resources/js/app.js'].file = 'assets/' + nextMain;
  }
  for (const [file, source] of active) if (!existsEqual(file, source)) writeFileSync(path.join(directory, file), source);
  function existsEqual(file, source) { try { return read(file) === source; } catch { return false; } }
}

function digest(source) { return createHash('sha256').update(source).digest('hex').slice(0, 12); }
function functionEnd(source, start) {
  let depth = 0, quote = null, escaped = false;
  for (let index = source.indexOf('{', start); index < source.length; index++) {
    const char = source[index];
    if (quote) { if (escaped) escaped = false; else if (char === '\\') escaped = true; else if (char === quote) quote = null; continue; }
    if ('"\'`'.includes(char)) { quote = char; continue; }
    if (char === '{') depth++;
    if (char === '}' && --depth === 0) return index + 1;
  }
  throw Error('Unclosed function');
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
  const manifestPath = path.join(root, 'public/build/manifest.json');
  const manifest = JSON.parse(readFileSync(manifestPath, 'utf8'));
  integrateLastPolish(root, manifest);
  writeFileSync(manifestPath, JSON.stringify(manifest, null, 2) + '\n');
  const cssPath = path.join(root, 'public/speedbox/panel-controls.css');
  const marker = '\n/*speedbox-last-polish-styles*/\n';
  const previous = readFileSync(cssPath, 'utf8').split(marker)[0];
  writeFileSync(cssPath, previous + marker + readFileSync(path.join(root, 'scripts/ui/last-polish.css'), 'utf8') + '\n/*speedbox-visual-review-styles*/\n' + readFileSync(path.join(root, 'scripts/ui/visual-review.css'), 'utf8'));
  console.log('Final review integrated:', manifest['resources/js/app.js'].file);
}
