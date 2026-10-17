import { readFileSync, writeFileSync } from 'node:fs';
import path from 'node:path';

// Replace only the requested modal selects. Paging and unrelated controls stay
// native. The original models, IDs, disabled states and option values are kept.
export function integrateModalSelects(root, manifest) {
  const literal = items => JSON.stringify(items.map(([id, label]) => ({ id, label })));
  const types = literal([['time', 'زمانی'], ['volume', 'حجمی'], ['both', 'زمانی + حجمی']]);
  const pricing = '[{id:"default",label:"پیش‌ فرض"},...(n.value.referrer_id?[{id:"referrer",label:"مشابه معرف"}]:[]),{id:"custom",label:"شخصی‌ سازی"}]';
  const referrals = '[{id:"",label:"— انتخاب کنید —"},...pe.value.map(item=>({id:item.id,label:item.code}))]';
  const pages = [
    ['Partners', 'f', 'rt', [
      ['id:"cr-role"', 'cr-role', 'دسترسی', 'n.value.role', literal([['', '— انتخاب کنید —'], ['1', 'ادمین'], ['2', 'کاربر']])],
      ['id:"cr-partner-plan-type"', 'cr-partner-plan-type', 'طرح پنل', 'n.value.partner_plan_type', types],
      ['id:"cr-ref"', 'cr-ref', 'معرف', 'n.value.referrer_id', referrals, 'le.value'],
      ['id:"cr-ps-mode"', 'cr-ps-mode', 'استراتژی قیمت', 'n.value.price_strategy', pricing],
      ['id:"pf-partner-plan-type"', 'pf-partner-plan-type', 'طرح پنل', 'n.value.partner_plan_type', types],
      ['id:"pf-ref"', 'pf-ref', 'معرف', 'n.value.referrer_id', referrals, 'le.value'],
      ['id:"ps-mode"', 'ps-mode', 'استراتژی قیمت', 'n.value.price_strategy', pricing]
    ], 'white'],
    ['Users', 're', 'qe', [
      ['class:"edit-input create-code-mode-select"', 'admin-create-code-mode', 'روش ساخت کد کاربر', 'Te.value', literal([['admin', 'ساخت با کد پنل ادمین'], ['custom', 'ساخت با کد اختصاصی']])]
    ], 'panel'],
    ['Tutorials', 'F', 'Je', [
      ['class:"sel",dir:"rtl"', 'tutorial-category', 'دسته‌بندی', 'o.value.category', literal([['', '— انتخاب کنید —'], ['ios', 'اپل'], ['android', 'اندروید'], ['system', 'سیستم']])]
    ], 'panel']
  ];
  for (const [page, directive, vnode, controls, surface] of pages) {
    const entry = manifest[`resources/js/pages/admin/${page}.vue`];
    const file = path.join(root, 'public/build', entry.file);
    let source = readFileSync(file, 'utf8');
    if (source.includes('/*speedbox-continuous-modal-selects*/')) continue;
    for (const [marker, id, label, model, options, disabled = 'false'] of controls) {
      const candidates = [...source.matchAll(new RegExp(`${directive}\\(t\\("select",`, 'g'))].map(match => match.index);
      const start = candidates.find(index => source.slice(index, expressionEnd(source, index + directive.length)).includes(marker));
      if (start === undefined) throw new Error(`Panel ${page} select changed: ${id}`);
      const end = expressionEnd(source, start + directive.length);
      const original = source.slice(start, end);
      if (!original.includes(model)) throw new Error(`Panel ${page} select model changed: ${id}`);
      const next = `${vnode}(window.__SPEEDBOX_TARIFF_PLAN_SELECT__,{inputId:${JSON.stringify(id)},label:${JSON.stringify(label)},surface:${JSON.stringify(surface)},modelValue:${model},options:${options},disabled:${disabled},"onUpdate:modelValue":value=>${model}=value},null,8,["modelValue","options","disabled","onUpdate:modelValue"])`;
      source = source.slice(0, start) + next + source.slice(end);
    }
    writeFileSync(file, source + '\n/*speedbox-continuous-modal-selects*/\n');
  }
  const partnerEntry = manifest['resources/js/pages/admin/Partners.vue'];
  const partnerFile = path.join(root, 'public/build', partnerEntry.file);
  let partnerSource = readFileSync(partnerFile, 'utf8');
  if (!partnerSource.includes('/*speedbox-consistent-pricing-headings*/')) {
    for (const [heading, cache, originalText] of [
      ['Kl', '83', ' - قیمت‌های شخصی ‌سازی ‌شده '],
      ['Ta', '104', ' - قیمت‌های شخصی‌ سازی‌ شده ']
    ]) {
      const original = `t("div",${heading},[t("bdi",null,o(se(l.name)),1),e[${cache}]||(e[${cache}]=w(${JSON.stringify(originalText)},-1))`;
      if (!partnerSource.includes(original)) throw new Error('Partner custom pricing heading changed');
      const next = `t("div",${heading},[e[${cache}]||(e[${cache}]=w("قیمت‌های شخصی‌سازی‌شده - ",-1)),t("bdi",null,o(se(l.name)),1)`;
      partnerSource = partnerSource.replace(original, next);
    }
    writeFileSync(partnerFile, partnerSource + '\n/*speedbox-consistent-pricing-headings*/\n');
  }
  const cssFile = path.join(root, 'public/build', partnerEntry.css[0]);
  const css = readFileSync(cssFile, 'utf8').split('/*speedbox-stable-partner-modal*/')[0].trimEnd();
  writeFileSync(cssFile, css + `
/*speedbox-stable-partner-modal*/
/* Only pricing forms need a definite width; retain the original modal/cards. */
.create-modal .edit-form[data-v-e47768ba]:has(#cr-ps-mode),
.create-modal .edit-form[data-v-e47768ba]:has(#ps-mode){width:min(420px,calc(92vw - 96px));max-width:100%}
/* Nested read-only cards and editable sibling cards share one spacing rhythm. */
.create-modal .edit-form[data-v-e47768ba]:has(#cr-ps-mode) .price-box,
.create-modal .edit-form[data-v-e47768ba]:has(#ps-mode) .price-box{margin:0!important}
.create-modal .edit-form[data-v-e47768ba] .edit-row--note > div{display:grid;gap:18px}
/* Both forms start pricing cards after 18px + 25px, matching the save gap. */
.create-modal .edit-form[data-v-e47768ba]:has(#cr-ps-mode,#ps-mode) > .edit-row:has(#cr-ps-mode,#ps-mode) + .price-box,
.create-modal .edit-form[data-v-e47768ba]:has(#cr-ps-mode,#ps-mode) > .edit-row--note > div > .price-box:first-child{margin-top:25px!important}
.create-modal .edit-form[data-v-e47768ba] .price-note{line-height:20px}
.create-modal .edit-form[data-v-e47768ba] .price-value{font-size:12px;line-height:20px}
/* Match the create form's final pricing-card gap in both strategies. */
.create-modal .edit-form[data-v-e47768ba]:has(#cr-ps-mode) .edit-row--note .price-box:last-child{margin-bottom:0!important}
/* Editable prices use the same dashed rows and spacing as default prices. */
.create-modal .edit-form[data-v-e47768ba] .price-box > .price-row,
.create-modal .edit-form[data-v-e47768ba] .price-box > .edit-row{
  box-sizing:border-box;height:38px;min-height:0;line-height:20px;
}
.create-modal .edit-form[data-v-e47768ba] .price-box > .edit-row{
  display:flex;justify-content:space-between;align-items:center;gap:10px;
  padding:8px 10px;border:1px dashed rgba(255,255,255,.12);border-radius:8px;
}
.create-modal .edit-form[data-v-e47768ba] .price-box > .edit-row > .edit-label{
  padding:0;font-size:12px;font-weight:700;line-height:20px;color:#cfd8dc;
}
.create-modal .edit-form[data-v-e47768ba] .price-box > .edit-row > .edit-input{
  width:120px;min-width:0;max-width:45%;flex:0 1 120px;
  height:20px;min-height:0;padding:0 8px;border-width:1px;border-radius:6px;line-height:18px;
  font-size:12px;font-weight:700;
}
`);

}

function expressionEnd(source, opening) {
  let depth = 0, quote = null, escape = false;
  for (let i = opening; i < source.length; i++) {
    const char = source[i];
    if (quote) {
      if (escape) escape = false;
      else if (char === '\\') escape = true;
      else if (char === quote) quote = null;
      continue;
    }
    if ('"\'`'.includes(char)) { quote = char; continue; }
    if (char === '(') depth++;
    if (char === ')' && --depth === 0) return i + 1;
  }
  throw new Error('Unclosed Panel select expression');
}
