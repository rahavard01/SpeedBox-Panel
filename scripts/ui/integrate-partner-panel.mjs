import { readFileSync, writeFileSync, readdirSync, unlinkSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

export function integratePartnerPanel(root, manifest) {
  const directory = path.join(root, 'public/build/assets');
  const entry = manifest['resources/js/app.js'];
  let bundle = readFileSync(path.join(root, 'public/build', entry.file), 'utf8');
  const marker = '/*speedbox-partner-accounts*/';
  const endMarker = '/*speedbox-partner-accounts:end*/';
  const factory = readFileSync(path.join(root, 'scripts/ui/partner-accounts.mjs'), 'utf8').replace('export function ', 'function ');
  if (bundle.includes(marker)) {
    const a = bundle.indexOf(marker), z = bundle.indexOf(endMarker, a);
    bundle = bundle.slice(0, a) + marker + '\n' + factory + '\n' + endMarker + (z < 0 ? '' : bundle.slice(z + endMarker.length));
    writeFileSync(path.join(root, 'public/build', entry.file), bundle);
  } else {
    const start = bundle.indexOf('__name:"Panel"');
    // Panel is the layout containing the representative account table.
    if (start < 0) throw new Error('Representative Panel build changed');
    let panel = bundle.slice(start);
    const replace = (old, next) => {
      if (!panel.includes(old)) throw new Error('Representative Panel anchor changed: ' + old);
      panel = panel.replace(old, next);
    };
    replace('return(v,l)=>{const n0=yo("router-link"),k0=yo("router-view");return k(),x(j0,null,[b("div",null,', 'const speedboxPartner=speedboxCreatePartnerAccounts({rows:()=>cb.value,transactionHistory:Js,trafficHistory:Gs});return(v,l)=>{const n0=yo("router-link"),k0=yo("router-view");return k(),x(j0,null,[speedboxPartner.view(),b("div",null,');
    replace('l[91]||(l[91]=b("th",null,"وضعیت",-1))', 'b("th",{class:"speedbox-online-column"},"آنلاین / سقف"),l[91]||(l[91]=b("th",null,"وضعیت",-1))');
    replace('b("td",null,[b("span",{class:q0(["badge",Q.banned===0?', 'b("td",{class:"speedbox-online-column"},[speedboxPartner.onlineBadge(Q)]),b("td",null,[b("span",{class:q0(["badge",Q.banned===0?');
    replace('b("td",gy,', 'b("td",{...gy,colspan:9},');
    replace('b("td",{colspan:8,style:', 'b("td",{colspan:9,style:');
    replace('body:l1(({item:Q})=>[b("div",Hy,', 'body:l1(({item:Q})=>[b("div",{class:"mobile-info-box"},[b("small",null,"آنلاین / سقف"),speedboxPartner.onlineBadge(Q)]),b("div",Hy,');
    for (const handler of ['W1', 'r1']) replace('title:"تاریخچه تراکنش‌ها","aria-label":"تاریخچه تراکنش‌ها",onClick:' + handler + '=>Js(Q)', 'title:"تاریخچه","aria-label":"تاریخچه",onClick:' + handler + '=>speedboxPartner.openHistory(Q)');
    bundle = bundle.slice(0, start) + panel + '\n' + marker + '\n' + factory + '\n' + endMarker;
    writeFileSync(path.join(root, 'public/build', entry.file), bundle);
  }
  // Replace the old modal chooser buttons with reactive, anchored menus.
  bundle = readFileSync(path.join(root, 'public/build', entry.file), 'utf8');
  bundle = bundle.replaceAll('"آنلاین / سقف"', '"آنلاین"');
  // Match the admin card: expiry and consumption share the final two-column row.
  // Keep the usage click handler and progress bar intact.
  for (const [old, next] of [
    ['b("div",Qy,', 'b("div",{class:"mobile-info-box mobile-expiry-box"},'],
    ['class:"mobile-info-box mobile-info-box--full usage-cell--clickable",onClick:r1=>Gs(Q)', 'class:"mobile-info-box mobile-user-usage-box usage-cell--clickable",onClick:r1=>Gs(Q)'],
  ]) {
    if (bundle.includes(old)) {
      if (bundle.indexOf(old) !== bundle.lastIndexOf(old)) throw new Error('Representative mobile card anchor is ambiguous');
      bundle = bundle.replace(old, next);
    } else if (!bundle.includes(next)) throw new Error('Representative mobile card changed');
  }
  writeFileSync(path.join(root, 'public/build', entry.file), bundle);
  if (!bundle.includes('/*speedbox-partner-history-menu*/')) {
    for (const [handler, cache, attributes, mobile] of [['W1', 99, 'Iy', false], ['r1', 107, 'MN', true]]) {
      const old = `b("button",{type:"button",class:"transaction-icon-btn${mobile ? ' transaction-icon-btn--mobile' : ''}",title:"تاریخچه","aria-label":"تاریخچه",onClick:${handler}=>speedboxPartner.openHistory(Q)},[...l[${cache}]||(l[${cache}]=[b("i",{class:"fas fa-receipt"},null,-1)])],8,${attributes})`;
      if (!bundle.includes(old)) throw new Error('Representative history button changed');
      bundle = bundle.replace(old, `speedboxPartner.historyButton(Q${mobile ? ',true' : ''})`);
    }
    if (!bundle.includes('b("table",hy,')) throw new Error('Representative table changed');
    bundle = bundle.replace('b("table",hy,', 'b("table",{...hy,class:"users-table speedbox-partner-accounts-table"},');
    bundle += '\n/*speedbox-partner-history-menu*/\n';
    writeFileSync(path.join(root, 'public/build', entry.file), bundle);
  }
  const partners = manifest['resources/js/pages/admin/Partners.vue'];
  const oldName = path.basename(partners.file);
  let source = readFileSync(path.join(directory, oldName), 'utf8');
  const previous = source;
  if (!source.includes('code_locked:Boolean(s.code_locked)')) {
    if (!source.includes('panel_code:s.panel_code,access:')) throw new Error('Partner row mapping changed');
    source = source.replace('panel_code:s.panel_code,access:', 'panel_code:s.panel_code,code_locked:Boolean(s.code_locked),access:');
  }
  if (!source.includes('/*speedbox-partner-code-lock*/')) {
    const old = 'placeholder:"پسوند ساخت اکانت ها"},null,512),[[E,n.value.panel_code,void 0,{trim:!0}]])';
    const next = 'placeholder:"پسوند ساخت اکانت ها",readonly:Boolean(c.value?.code_locked),title:c.value?.code_locked?"کد پنل پس از ساخت اولین اکانت قابل تغییر نیست":""},null,520,["readonly","title"]),[[E,n.value.panel_code,void 0,{trim:!0}]]),c.value?.code_locked?t("small",{class:"speedbox-code-lock-note"},"کد پنل پس از ساخت اولین اکانت قابل تغییر نیست"):null';
    if (!source.includes(old)) throw new Error('Partner profile code input changed');
    source = source.replace(old, next) + '\n/*speedbox-partner-code-lock*/\n';
  }
  if (source !== previous) rotate(partners, directory, source, 'Partners-speedbox-');
  const cssPath = path.join(root, 'public/speedbox/panel-controls.css');
  const styles = '/*speedbox-partner-account-styles*/';
  const css = readFileSync(cssPath, 'utf8').split(styles)[0].trimEnd();
  writeFileSync(cssPath, css + '\n' + styles + '\n' + readFileSync(path.join(root, 'scripts/ui/partner-accounts.css'), 'utf8'));

}

function rotate(entry, directory, source, prefix) {
  const oldName = path.basename(entry.file);
  const newName = prefix + createHash('sha256').update(source).digest('hex').slice(0, 12) + '.js';
  writeFileSync(path.join(directory, newName), source);
  for (const file of readdirSync(directory).filter(file => file.endsWith('.js') && file !== oldName && file !== newName)) {
    const content = readFileSync(path.join(directory, file), 'utf8');
    if (content.includes(oldName)) writeFileSync(path.join(directory, file), content.replaceAll(oldName, newName));
  }
  entry.file = 'assets/' + newName;
  if (oldName !== newName) unlinkSync(path.join(directory, oldName));
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
  const manifestPath = path.join(root, 'public/build/manifest.json');
  const manifest = JSON.parse(readFileSync(manifestPath, 'utf8'));
  integratePartnerPanel(root, manifest);
  const entry = manifest['resources/js/app.js'];
  rotate(entry, path.join(root, 'public/build/assets'), readFileSync(path.join(root, 'public/build', entry.file), 'utf8'), 'app-speedbox-');
  writeFileSync(manifestPath, JSON.stringify(manifest, null, 2) + '\n');
  console.log('Representative account panel integrated:', entry.file);
}
