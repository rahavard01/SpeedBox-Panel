import { readFileSync, writeFileSync } from 'node:fs';
import path from 'node:path';

// Panel publishes compiled Vue assets. Preserve its tariff sorting/minimum fields
// while adding the existing device_limit contract to the group header.
export function integrateTariffHeader(root, manifest) {
  const entry = manifest['resources/js/pages/admin/Settings.vue'];
  const file = path.join(root, 'public/build', entry.file);
  let source = readFileSync(file, 'utf8');
  if (source.includes('/*speedbox-tariff-header*/')) return;
  const replace = (before, after, expected = 1) => {
    if (source.split(before).length - 1 !== expected) throw new Error('Panel tariff structure changed: ' + before);
    source = source.replaceAll(before, after);
  };
  replace('linked_plan_id:l.v2_plan_id||"",', 'linked_plan_id:l.v2_plan_id||"",device_limit:l.device_limit??null,', 2);
  replace('v2_plan_id:e.linked_plan_id||null}', 'v2_plan_id:e.linked_plan_id||null,device_limit:e.device_limit??null}');
  replace('v2_plan_id:U.linked_plan_id||null}', 'v2_plan_id:U.linked_plan_id||null,device_limit:U.device_limit??null}');
  replace('v2_plan_id:U.key==="gig"?null:U.linked_plan_id||null}', 'v2_plan_id:U.key==="gig"?null:U.linked_plan_id||null,device_limit:U.key==="gig"?null:U.device_limit??null}');
  replace('[[S,!B.volume_groups]]),t("div",Ht,', '[[S,!B.volume_groups]]),d(speedboxTariffConnection(n),[[S,!B.volume_groups]]),t("div",Ht,');
  replace('[[S,!B.groups]]),t("div",fs,', '[[S,!B.groups]]),d(speedboxTariffConnection(n),[[S,!B.groups]]),t("div",fs,');
  for (const [kind, header] of [['volume_groups', 'Kt'], ['groups', 'us']]) {
    const control = `pe(Re,{index:l,count:s.tariffs.${kind}.length,onMove:r=>Be("${kind}",l,r),onDragStart:r=>xe("${kind}",n.local_id,r),onDragEnd:J},null,8,["index","count","onMove","onDragStart"]),`;
    replace(control, '');
    replace(`t("div",${header},[B.${kind}`, `t("div",${header},[${control}B.${kind}`);
  }
  replace('پلن متصل به پنل', 'پلن', 6);
  for (const [optionValue, updateProps] of [['rs', 'os'], ['Us', 'Ss']]) {
    const native = `d(t("select",{class:"form-input","onUpdate:modelValue":i=>r.linked_plan_id=i},[(p(!0),u(x,null,C(N.value,i=>(p(),u("option",{key:i.id,value:i.id},m(i.label),9,${optionValue}))),128))],8,${updateProps}),[[De,r.linked_plan_id]])`;
    replace(native, 'pe(window.__SPEEDBOX_TARIFF_PLAN_SELECT__,{modelValue:r.linked_plan_id,options:N.value,"onUpdate:modelValue":i=>r.linked_plan_id=i},null,8,["modelValue","options","onUpdate:modelValue"])');
  }
  replace('function at(){', 'function at(){for(const group of [...s.tariffs.groups,...s.tariffs.volume_groups])for(const item of group.items||[])if(item.allowLink&&item.device_limit!=null&&(!/^\\d+$/.test(String(item.device_limit))||!Number.isSafeInteger(Number(item.device_limit))||Number(item.device_limit)>2147483647))return o("محدودیت اتصال باید عدد صحیح نامنفی باشد"),!1;');
  source += `
/*speedbox-tariff-header*/
function speedboxTariffConnection(group){
  const items=(group.items||[]).filter(item=>item.allowLink);
  const values=[...new Set(items.map(item=>item.device_limit==null?"":String(item.device_limit)))];
  const mixed=values.length>1;
  return t("div",{class:"tariff-connection-field"},[
    t("label",null,"محدودیت اتصال هر کاربر"),
    t("input",{class:"form-input",type:"text",inputmode:"numeric",dir:"ltr",
      autocomplete:"off","aria-label":"محدودیت اتصال هر کاربر در طرح "+(group.name||""),
      value:mixed?"":values[0]||"",placeholder:mixed?"مقادیر متفاوت":"پیش‌فرض پلن",
      title:"برای همه تعرفه‌های این طرح؛ خالی: پیش‌فرض پلن، صفر: نامحدود",
      onInput:event=>{const value=event.target.value.replace(/[۰-۹]/g,c=>String("۰۱۲۳۴۵۶۷۸۹".indexOf(c))).replace(/[٠-٩]/g,c=>String("٠١٢٣٤٥٦٧٨٩".indexOf(c))).trim();for(const item of items)item.device_limit=value===""?null:value;}
    },null,8,["value","placeholder","title","aria-label","onInput"])
  ],512);
}
`;
  writeFileSync(file, source);
  const cssFile = path.join(root, 'public/build', entry.css[0]);
  writeFileSync(cssFile, readFileSync(cssFile, 'utf8') + `
/* speedbox-tariff-header: keep all controls on the same baseline and shape */
.settings-final .tariff-group-head{flex-direction:row;align-items:flex-end;gap:10px;flex-wrap:nowrap}
.settings-final .tariff-group-head>.tariff-order-controls{flex:0 0 auto;align-items:center;height:30px}
.settings-final .tariff-group-head>.tariff-order-controls button{height:30px}
.settings-final .tariff-sort-mode .tariff-group-actions{display:none}
.settings-final .tariff-group-title{flex:1 1 180px;min-width:100px}
.settings-final .tariff-group-title .form-input{width:100%;min-width:0;box-sizing:border-box}
.settings-final .tariff-connection-field{display:flex;flex-direction:column;gap:6px;flex:0 0 185px;min-width:0}
.settings-final .tariff-connection-field label{font-size:.82rem;opacity:.86;white-space:nowrap}
.settings-final .tariff-connection-field .form-input{width:100%;height:30px;box-sizing:border-box;border-color:rgba(94,234,212,.4);background:rgba(45,212,191,.08);color:#ccfbf1;text-align:center}
.settings-final .tariff-group-actions{flex:0 0 auto;justify-content:flex-start;flex-wrap:nowrap;align-items:center;gap:8px}
.settings-final .tariff-group-head .partner-access-badge1,.settings-final .tariff-group-head .default-badge,.settings-final .tariff-group-head .btn-mini-danger{display:inline-flex;align-items:center;justify-content:center;box-sizing:border-box;height:30px;line-height:normal;border-radius:8px;padding:0 10px;white-space:nowrap}
@media(max-width:700px){.settings-final .tariff-group-head{flex-wrap:wrap}.settings-final .tariff-group-title{flex:1 1 calc(100% - 195px)}.settings-final .tariff-group-actions{width:100%;justify-content:flex-end}.settings-final .tariff-sort-mode .tariff-group-actions{width:auto}.settings-final .tariff-sort-mode .tariff-group-head{flex-wrap:nowrap}}
`);
}
