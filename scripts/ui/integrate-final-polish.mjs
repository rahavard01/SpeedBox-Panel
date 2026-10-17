import { readFileSync, writeFileSync, readdirSync } from 'node:fs';
import { createHash } from 'node:crypto';
import path from 'node:path';

const digits = '.replace(/[0-9]/g,digit=>"۰۱۲۳۴۵۶۷۸۹"[Number(digit)])';
export function integrateFinalPolish(root, manifest) {
  const directory = path.join(root, 'public/build/assets');
  const main = path.basename(manifest['resources/js/app.js'].file);
  const active = new Set(Object.values(manifest).map(entry => path.basename(entry.file)));
  // Follow active modules, keeping archived builds available for already-open tabs.
  const scanned = new Set();
  for (const file of active) {
    if (!file.endsWith('.js') || scanned.has(file) || (file.startsWith('app-speedbox-') && file !== main)) continue;
    scanned.add(file);
    const source = readFileSync(path.join(directory, file), 'utf8');
    for (const match of source.matchAll(/["']\.\/([^"']+\.js)["']/g)) {
      if (!match[1].startsWith('app-speedbox-') || match[1] === main) active.add(match[1]);
    }
  }
  for (const file of readdirSync(directory).filter(file => file.endsWith('.js') && active.has(file))) {
    let source = readFileSync(path.join(directory, file), 'utf8');
    const original = source;
    // Compiled element arrays require a comment VNode for an absent conditional
    // child. A raw null in the code-lock note prevents the create modal mounting.
    if (source.includes('/*speedbox-partner-code-lock*/')) {
      source = source.replaceAll('"کد پنل پس از ساخت اولین اکانت قابل تغییر نیست"):null', '"کد پنل پس از ساخت اولین اکانت قابل تغییر نیست"):p("",!0)');
    }
    if (source.includes('function speedboxOnlineBadge(user)') && !source.includes('/*speedbox-persian-online*/')) {
      source = source.replace("(count===null?'—':String(count))+' / '+(limit>0?String(limit):'∞')", "(count===null?'—':String(count).replace(/[0-9]/g,digit=>'۰۱۲۳۴۵۶۷۸۹'[Number(digit)]))+' / '+(limit>0?String(limit).replace(/[0-9]/g,digit=>'۰۱۲۳۴۵۶۷۸۹'[Number(digit)]):'∞')");
      source = source.replace("(limit>0?limit:'نامحدود')", "(limit>0?String(limit).replace(/[0-9]/g,digit=>'۰۱۲۳۴۵۶۷۸۹'[Number(digit)]):'نامحدود')");
      source += '\n/*speedbox-persian-online*/\n';
    }
    // All components share one Vue instance; older copied helpers can retain stale imports.
    if (file !== main) source = source.replace(/(["'])\.\/app-speedbox-[a-f0-9]+\.js\1/g, (_, quote) => quote + './' + main + quote);
    if (file === main || file === path.basename(manifest['resources/js/pages/admin/Users.vue'].file)) {
      source = source.replace(/(body:\w+\(\(\{item:\w+\}\)=>\[\w+\("div",\{class:"mobile-info-box"\},\[\w+\("small",null,)"آنلاین"/, '$1"دستگاه‌های آنلاین"');
    }
    // Keep the admin account's last-online relative numbers Persian on rebuild.
    if (file === path.basename(manifest['resources/js/pages/admin/Users.vue'].file)) {
      source = source.replace("`${L}<br><bdi dir=\"ltr\">${Pt(a)}</bdi>`", "`${L.replace(/[0-9]/g,digit=>\"۰۱۲۳۴۵۶۷۸۹\"[Number(digit)])}<br><bdi dir=\"ltr\">${Pt(a)}</bdi>`");
    }
    // Explicit display formatters only: date-picker models and API dates stay Latin.
    source = source.replace(/\.format\("jYYYY\/jMM\/jDD(?: HH:mm)?"\)(?!\.replace\(\/\[0-9\])/g, match => match + digits);
    if (file === main && !source.includes('/*speedbox-persian-relative-time*/')) {
      const start = source.indexOf('function dl('), end = source.indexOf('function nL(', start);
      if (start < 0 || end < 0) throw Error('Account remaining-time formatter changed');
      const body = source.slice(start, end).replace(/\$\{([scar])\}/g, (_, value) => '${String(' + value + ')' + digits + '}');
      source = source.slice(0, start) + body + source.slice(end) + '\n/*speedbox-persian-relative-time*/\n';
    }
    if (source.includes('__name:"InvoiceFilterSelect"') && !source.includes('/*speedbox-mobile-native-filter*/')) {
      const marker = source.indexOf('__name:"InvoiceFilterSelect"');
      const start = source.lastIndexOf('{', marker);
      const end = objectEnd(source, start);
      let component = source.slice(start, end);
      const setup = component.match(/setup\((\w+),\{emit:(\w+)\}\)\{/);
      const arrows = [...component.matchAll(/,\(\w+,\w+\)=>\(/g)];
      if (!setup || !arrows.length || !component.endsWith('}}')) throw Error('Invoice filter component changed: ' + file);
      const render = arrows.at(-1).index + arrows.at(-1)[0].length - 1;
      const branch = 'speedboxMobile.value?window.__SPEEDBOX_RENDER_NATIVE_SELECT__({value:' + setup[1] + '.modelValue,options:' + setup[1] + '.options,placeholder:' + setup[1] + '.placeholder,"aria-label":' + setup[1] + '.placeholder,change:value=>' + setup[2] + '("update:modelValue",String(value))}):';
      component = component.slice(0, render) + '(' + branch + component.slice(render, -2) + ')}}';
      component = component.replace(setup[0], setup[0] + 'const speedboxMobile=window.__SPEEDBOX_USE_MOBILE__();');
      source = source.slice(0, start) + component + source.slice(end) + '\n/*speedbox-mobile-native-filter*/\n';
    }
    if (source === original) continue;
    if (file === main) { writeFileSync(path.join(directory, file), source); continue; }
    const stem = file.replace(/(?:-speedbox-[a-f0-9]+)?\.js$/, '');
    const name = stem + '-speedbox-' + createHash('sha256').update(source).digest('hex').slice(0, 12) + '.js';
    writeFileSync(path.join(directory, name), source);
    active.delete(file); active.add(name);
    for (const other of readdirSync(directory).filter(name => name.endsWith('.js') && name !== file && active.has(name))) {
      const content = readFileSync(path.join(directory, other), 'utf8');
      if (content.includes(file)) writeFileSync(path.join(directory, other), content.replaceAll(file, name));
    }
    for (const entry of Object.values(manifest)) if (entry.file === 'assets/' + file) entry.file = 'assets/' + name;
  }
}

function objectEnd(source, start) {
  let depth = 0, quote = null, escaped = false;
  for (let index = start; index < source.length; index++) {
    const ch = source[index];
    if (quote) { if (escaped) escaped = false; else if (ch === '\\') escaped = true; else if (ch === quote) quote = null; continue; }
    if ('"\'`'.includes(ch)) { quote = ch; continue; }
    if (ch === '{') depth++;
    if (ch === '}' && --depth === 0) return index + 1;
  }
  throw Error('Unclosed filter component');
}
