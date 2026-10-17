import { readFileSync, writeFileSync, readdirSync } from 'node:fs';
import path from 'node:path';
import { createHash } from 'node:crypto';

// Apply reviewed fixes to Panel's shipped Vue build without importing its source.
export function integrateReviewFixes(root, manifest) {
  const read = file => readFileSync(path.join(root, file), 'utf8');
  const write = (file, text) => writeFileSync(path.join(root, file), text);
  const mainPath = 'public/build/' + manifest['resources/js/app.js'].file;
  let main = read(mainPath);
  if (!main.includes('/*speedbox-reviewed-ui-v1*/')) {
    const replace = (before, after, count = 1) => {
      if (main.split(before).length - 1 !== count) throw Error('Review anchor changed: ' + before.slice(0, 100));
      main = main.replaceAll(before, after);
    };
    replace('function M6(e,t={}){return Bc({type:"notif",message:e,...t})}', 'function M6(e,t={}){return speedboxGetNoticeQueue().notify(e,t)}');
    for (const [name, endpoint, key, counter, previous, message, fallback] of [
      ['u0','/admin/referrals/count','referrals','z0','v0','یک درخواست نمایندگی جدید جهت بررسی دارید',''],
      ['d1','/admin/wallet/receipts/count','deposits','L0','$0','یک واریزی جدید جهت بررسی دارید',', {params:{status:"pending"}}'],
      ['c1','/admin/tickets/count','tickets','U0','C0','یک تیکت جدید جهت بررسی دارید',''],
    ]) {
      const start = main.indexOf('async function ' + name + '()', main.indexOf('LC={__name:"Admin"'));
      if (start < 0) throw Error('Admin notification handler missing: ' + name);
      const end = functionEnd(main, start);
      main = main.slice(0, start) + `async function ${name}(){try{const{data:S0}=await w0.get(${JSON.stringify(endpoint)}${fallback});speedboxGetNoticeQueue().counts(${JSON.stringify(key)},S0,${JSON.stringify(message)});${counter}.value=Number(S0?.count||0);${previous}=${counter}.value}catch{}}` + main.slice(end);
    }
    // Each backend event has a stable key, so equal messages from NEW events work.
    for (const [name, key] of [['ip','"receipt:"+l.id'],['lp','"card:"+l.id'],['Op','"crypto:"+l.id+":"+l.status'],['dp','"adjust:"+n0.id'],['hb','"referral:"+l.id+":"+l.status'],['mb','"commission:"+(l.source||"receipt")+":"+l.id'],['Rb','"announcement:"+n0.id'],['to','"tutorial:"+n0.id+":"+n0.kind+":"+(n0.at||"")']]) {
      const start = main.indexOf('async function ' + name + '()', main.indexOf('__name:"Panel"'));
      if (start < 0) throw Error('Partner notification handler missing: ' + name);
      const end = functionEnd(main, start), source = main.slice(start, end);
      if (!source.includes('{mode:"global"}')) throw Error('Notification options changed: ' + name);
      main = main.slice(0, start) + source.replaceAll('{mode:"global"}', '{mode:"global",notificationKey:' + key + '}') + main.slice(end);
    }
    replace('function u(f){if(y0(f.type)){', 'function u(f){if(y0(f.type)||String(f.type).toLowerCase()==="ai_receipt_check_fee"){');
    replace('const g=f.affected_users??f.username??f.usernames??null;', 'const g=Array.isArray(f.affected_users)&&!f.affected_users.length?(f.username||f.usernames||null):(f.affected_users??f.username??f.usernames??null);');
    // Move the create-ticket action inside the existing glass surface.
    const action = 'b("div",Mx,[b("button",{class:"btn-create-user1",type:"button",disabled:O.value,onClick:M0},j(O.value?"در حال ثبت ...":"ثبت تیکت"),9,bx)])';
    replace('])])])]),' + action + ',b("div",sx,', '])])]),' + action + ']),b("div",sx,');
    const factory = read('scripts/ui/notification-queue.mjs').replace('export function ', 'function ');
    main += '\n/*speedbox-reviewed-ui-v1*/\n' + factory + `
var speedboxNoticeQueue;
function speedboxGetNoticeQueue(){return speedboxNoticeQueue||(speedboxNoticeQueue=createSpeedboxNotificationQueue({
 storage:window.localStorage,
 scope:()=>{let user={};try{user=JSON.parse(localStorage.getItem("user")||"{}")}catch{}user=user||{};return String(user.role??"unknown")+":"+String(user.id??user.code??user.email??"anonymous")},
 display:(message,options)=>Bc({type:"notif",message,...options}),canDisplay:()=>!et.items.length
}));}
Promise.resolve().then(()=>speedboxGetNoticeQueue().resume());
`;
  }
  main = main.replace('function M6(e,t={}){return speedboxGetNoticeQueue().notify(e,t)}', 'function M6(e,t={}){return t.notificationKey?speedboxGetNoticeQueue().notify(e,t):Bc({type:"notif",message:e,...t})}');
  write(mainPath, main);

  const invoices = manifest['resources/js/pages/admin/Invoices.vue'];
  let source = read('public/build/' + invoices.file);
  if (!source.includes('/*speedbox-invoice-panel-owner*/')) {
    const old = 'function g(n){if(Ie(n.type)){';
    if (!source.includes(old)) throw Error('Admin invoice owner formatter changed');
    source = source.replace(old, 'function g(n){if(Ie(n.type)||String(n.type).toLowerCase()==="ai_receipt_check_fee"){')
      .replace('const e=n.affected_users??n.username??n.usernames??null;', 'const e=Array.isArray(n.affected_users)&&!n.affected_users.length?(n.username||n.usernames||null):(n.affected_users??n.username??n.usernames??null);')
      + '\n/*speedbox-invoice-panel-owner*/\n';
    rotate(root, manifest, invoices, source, 'Invoices-speedbox-');
  }
  // The admin reset history has its own formatter, separate from partner history.
  const userFile = read('public/build/' + manifest['resources/js/pages/admin/Users.vue'].file);
  const helperName = userFile.match(/import SpeedboxAccountActions from '\.\/([^']+)'/)[1];
  const helperPath = 'public/build/assets/' + helperName;
  let helper = read(helperPath);
  const previousHelper = helper;
  helper = helper.replace('function Ce(a){return Number(a||0).toLocaleString("en-US")}', 'function Ce(a){return Number(a||0).toLocaleString("fa-IR")}');
  if (helper !== previousHelper || !helperName.startsWith('SpeedboxAccountActions-review-')) rotate(root, manifest, { file: 'assets/' + helperName }, helper, 'SpeedboxAccountActions-review-');

  const settingsPath = 'public/build/' + manifest['resources/js/pages/admin/Settings.vue'].file;
  let settings = read(settingsPath);
  if (!settings.includes('/*speedbox-persian-connection-input*/')) {
    const old = 'value:mixed?"":values[0]||"",placeholder:';
    if (!settings.includes(old)) throw Error('Tariff connection display changed');
    settings = settings.replace(old, 'value:mixed?"":String(values[0]||"").replace(/[0-9]/g,digit=>"۰۱۲۳۴۵۶۷۸۹"[Number(digit)]),placeholder:')
      .replace('trim();for(const item of items)item.device_limit', 'trim();event.target.value=value.replace(/[0-9]/g,digit=>"۰۱۲۳۴۵۶۷۸۹"[Number(digit)]);for(const item of items)item.device_limit')
      + '\n/*speedbox-persian-connection-input*/\n';
    rotate(root, manifest, manifest['resources/js/pages/admin/Settings.vue'], settings, 'Settings-review-');
  } else if (!path.basename(settingsPath).startsWith('Settings-review-')) {
    rotate(root, manifest, manifest['resources/js/pages/admin/Settings.vue'], settings, 'Settings-review-');
  }
}

function rotate(root, manifest, entry, source, prefix) {
  const dir = path.join(root, 'public/build/assets'), old = path.basename(entry.file);
  const main = path.basename(manifest['resources/js/app.js'].file);
  const name = prefix + createHash('sha256').update(source).digest('hex').slice(0, 12) + '.js';
  writeFileSync(path.join(dir, name), source);
  for (const file of readdirSync(dir).filter(file => file.endsWith('.js') && file !== old && file !== name && (!file.startsWith('app-speedbox-') || file === main))) {
    const content = readFileSync(path.join(dir, file), 'utf8');
    if (content.includes(old)) writeFileSync(path.join(dir, file), content.replaceAll(old, name));
  }
  for (const value of Object.values(manifest)) if (value.file === 'assets/' + old) value.file = 'assets/' + name;
}

function functionEnd(source, start) {
  let depth = 0, quote = null, escaped = false, opened = false;
  for (let index = source.indexOf('{', start); index < source.length; index++) {
    const char = source[index];
    if (quote) { if (escaped) escaped = false; else if (char === '\\') escaped = true; else if (char === quote) quote = null; continue; }
    if ('"\'`'.includes(char)) { quote = char; continue; }
    if (char === '{') { depth++; opened = true; }
    if (char === '}' && --depth === 0 && opened) return index + 1;
  }
  throw Error('Unclosed notification handler');
}
