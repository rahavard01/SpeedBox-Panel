import { readFileSync, writeFileSync, readdirSync, unlinkSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

// The repository ships the Panel build rather than its Vue source. Patch the
// validated build once, fail on drift, and rotate assets using the existing bridge.
export function integrateSettingsSaves(root, manifest) {
  const entry = manifest['resources/js/pages/admin/Settings.vue'];
  const oldName = path.basename(entry.file);
  const directory = path.join(root, 'public/build/assets');
  let source = readFileSync(path.join(directory, oldName), 'utf8');
  if (source.includes('/*speedbox-independent-settings-saves*/')) return;
  const replaceFunction = (start, end, next) => {
    const a = source.indexOf(start), b = source.indexOf(end, a);
    if (a < 0 || b < 0) throw new Error('Settings build changed: ' + start);
    source = source.slice(0, a) + next + source.slice(b);
  };
  const error = 'function speedboxSettingsError(error){return Object.values(error?.response?.data?.errors||{}).flat()[0]||error?.response?.data?.message||error?.message||"ذخیره انجام نشد"}';
  const paymentPayload = `function speedboxPaymentPayload(section){
    if(section==="payment")return {section,methods:s.payment.methods};
    return {section,crypto_wallet:{...s.payment.crypto_wallet,
      wallet_address:String(s.payment.crypto_wallet.wallet_address||"").trim(),
      trongrid_api_key:String(s.payment.crypto_wallet.trongrid_api_key||"").trim(),
      bscscan_api_key:String(s.payment.crypto_wallet.bscscan_api_key||"").trim()},crypto_options:K()};
  }`;
  // Payment toggles must not save pending edits in the wallet or bot sections.
  replaceFunction('async function Le(a)', 'function K()', `async function Le(a){
    const previous=!a.enabled;
    if(a.enabled&&!String(a.wallet_address||"").trim()){a.enabled=previous;o("ابتدا آدرس ولت این ارز را وارد کنید");return}
    try{const {data}=await b.put("/admin/payment-settings",speedboxPaymentPayload("crypto"));
      if(data.status!=="ok")throw new Error(data.message||"ذخیره ارز انجام نشد");v(a.enabled?"ارز فعال شد":"ارز غیرفعال شد");
    }catch(error){a.enabled=previous;o(speedboxSettingsError(error))}
  }`);
  replaceFunction('async function ve(a)', 'function Xe()', `async function ve(a){
    const previous=!s.payment.methods[a].enabled,sms=s.payment.methods.card.sms_auto_approve_enabled;
    if(a==="card"&&!s.payment.methods.card.enabled)s.payment.methods.card.sms_auto_approve_enabled=false;
    try{const {data}=await b.put("/admin/payment-settings",speedboxPaymentPayload("payment"));
      if(data.status!=="ok")throw new Error(data.message||"ذخیره وضعیت پرداخت انجام نشد");v("وضعیت روش پرداخت ذخیره شد");
    }catch(error){s.payment.methods[a].enabled=previous;s.payment.methods.card.sms_auto_approve_enabled=sms;o(speedboxSettingsError(error))}
  }
  async function Fe(){
    const previous=!s.payment.methods.card.sms_auto_approve_enabled;
    if(s.payment.methods.card.sms_auto_approve_enabled&&!s.payment.methods.card.enabled){s.payment.methods.card.sms_auto_approve_enabled=previous;o("ابتدا پرداخت کارت به کارت را فعال کنید");return}
    try{const {data}=await b.put("/admin/payment-settings",speedboxPaymentPayload("payment"));
      if(data.status!=="ok")throw new Error(data.message||"ذخیره انجام نشد");v("وضعیت تأیید آنی پرداخت ذخیره شد");
    }catch(error){s.payment.methods.card.sms_auto_approve_enabled=previous;o(speedboxSettingsError(error))}
  }
  async function He(){
    const previous=!s.payment.methods.card.ai_auto_approve_enabled;
    if(s.payment.methods.card.ai_auto_approve_enabled&&(!String(s.payment.methods.card.gemini_api_key||"").trim()||Number(s.payment.methods.card.ai_receipt_check_fee||0)<=0)){
      s.payment.methods.card.ai_auto_approve_enabled=previous;o("کلید هوش مصنوعی و هزینه بررسی رسید را وارد کنید");return}
    try{const {data}=await b.put("/admin/payment-settings",speedboxPaymentPayload("payment"));
      if(data.status!=="ok")throw new Error(data.message||"ذخیره انجام نشد");v("وضعیت تأیید هوشمند رسید ذخیره شد");
    }catch(error){s.payment.methods.card.ai_auto_approve_enabled=previous;o(speedboxSettingsError(error))}
  }`);
  const botStart = source.indexOf('async function te()'), botEnd = source.indexOf('async function ut()', botStart);
  const botSave = source.slice(botStart, botEnd);
  const payloadStart = botSave.indexOf('const{data:n}=await b.put(');
  if (payloadStart < 0) throw new Error('Bot save payload changed');
  // Retain the exact request contract; only readiness is conditional on activation.
  const botRequest = botSave.slice(payloadStart);
  replaceFunction('async function te()', 'async function ut()', `async function te(){
    const a=String(s.userBot.card_number||"").replace(/\\D+/g,"");
    if(s.userBot.is_enabled){
      if(!String(s.userBot.bot_token||"").trim())return o("توکن ربات کاربران را وارد کنید"),false;
      if(!s.userBot.card_payment_enabled&&!s.userBot.crypto_payment_enabled)return o("حداقل یک روش پرداخت برای ربات کاربران باید فعال باشد"),false;
      if(s.userBot.card_payment_enabled&&a.length!==16)return o("شماره کارت ربات کاربران باید 16 رقم باشد"),false;
      if(s.userBot.crypto_payment_enabled&&!pt())return o("حداقل یک ارز فعال با آدرس ولت وارد کنید"),false;
      if(!s.userBot.card_payment_enabled&&(s.userBot.card_sms_auto_approve_enabled||s.userBot.card_ai_auto_approve_enabled))return o("ابتدا پرداخت کارت به کارت را فعال کنید"),false;
      if(!String(s.userBot.help_ios||"").trim()||!String(s.userBot.help_android||"").trim()||!String(s.userBot.help_windows||"").trim())return o("برای فعال‌سازی ربات، راهنمای اتصال iOS، Android و Windows را وارد کنید"),false;
      if(s.userBot.plans.some(plan=>plan.is_enabled&&Number(plan.price||0)<=0))return o("برای طرح‌های فعال ربات قیمت وارد کنید"),false;
    }
    ${botRequest}`);
  replaceFunction('async function dt(a)', 'function it()', `async function dt(a){
    const previous=!a.enabled;
    if(s.userBot.is_enabled&&a.enabled&&!String(a.wallet_address||" ").trim()){a.enabled=previous;o("ابتدا آدرس ولت این ارز را وارد کنید");return}
    try{if(!await te()){a.enabled=previous;return}v(a.enabled?"ارز فعال شد":"ارز غیرفعال شد")}
    catch(error){a.enabled=previous;o(speedboxSettingsError(error))}
  }`);
  // Laravel returns field errors separately from its generic message.
  source = source.replace('o(n?.response?.data?.message||"خطا در تغییر وضعیت")', 'o(speedboxSettingsError(n))');
  source = source.replace('s.userBot.card_sms_auto_approve_enabled=!1),v(n.message', 's.userBot.card_sms_auto_approve_enabled=!1,s.userBot.card_ai_auto_approve_enabled=!1),v(n.message');
  const saveStart = source.indexOf('async function O()'), saveEnd = source.indexOf('async function bt()', saveStart);
  const oldSave = source.slice(saveStart, saveEnd);
  const tariffEnd = oldSave.indexOf('const Pe=');
  if (tariffEnd < 0) throw new Error('Tariff save payload changed');
  let tariffSave = oldSave.slice('async function O(){try{'.length, tariffEnd);
  tariffSave = tariffSave.replace('if(c.value=!0,!at()){c.value=!1;return}', 'if(!at())return;');
  // Clear a successfully applied percentage before any later reload can fail.
  tariffSave = tariffSave.replace('i=!!k.changed}', 'i=!!k.changed;s.tariffs.bulk.percent=null;await se();ee(r)}');
  replaceFunction('async function O()', 'async function bt()', `/*speedbox-independent-settings-saves*/
  ${error}
  ${paymentPayload}
  async function O(section){
    if(c.value)return;c.value=true;
    try{
      if(section==="tariffs"){
        ${tariffSave}
        v("تعرفه‌ها ذخیره شد");return;
      }
      if(section==="card"){
        const holder=String(s.card.holder||"").trim(),card_number=String(s.card.card_number||"").replace(/\\D+/g,"");
        if(!holder){o("نام صاحب کارت را وارد کنید");return}
        if(card_number.length!==16){o("شماره کارت باید 16 رقم باشد");return}
        const {data}=await b.put("/admin/card-settings",{holder,card_number});
        if(data.status!=="ok")throw new Error(data.message||"ذخیره کارت انجام نشد");v("تنظیمات کارت ذخیره شد");return;
      }
      if(section==="payment"||section==="crypto"){
        const {data}=await b.put("/admin/payment-settings",speedboxPaymentPayload(section));
        if(data.status!=="ok")throw new Error(data.message||"ذخیره پرداخت انجام نشد");
        if(section==="payment"&&(!s.payment.methods.card.ai_auto_approve_enabled||!String(s.payment.methods.card.gemini_api_key||"").trim()||Number(s.payment.methods.card.ai_receipt_check_fee||0)<=0)){
          s.payment.methods.card.ai_auto_approve_enabled=false;s.userBot.card_ai_auto_approve_enabled=false;
        }
        v(section==="crypto"?"تنظیمات ارز دیجیتال ذخیره شد":"تنظیمات پرداخت ذخیره شد");return;
      }
      if(section==="commission"){
        const value=s.commission.default_percent;
        if(value==null||String(value).trim()===""){o("درصد پیش‌فرض کمیسیون را وارد کنید");return}
        const {data}=await b.put("/admin/commission-settings",{default_percent:value});
        if(data.status!=="ok")throw new Error(data.message||"ذخیره کمیسیون انجام نشد");v("تنظیمات کمیسیون ذخیره شد");return;
      }
      if(section==="userBot"&&await te())v(s.userBot.is_enabled?"تنظیمات ربات کاربران ذخیره شد":"تنظیمات ربات کاربران ذخیره شد؛ ربات غیرفعال است");
    }catch(error){o(speedboxSettingsError(error))}finally{c.value=false}
  }`);
  const sections = ['tariffs', 'payment', 'card', 'crypto', 'commission', 'userBot'];
  if (source.split('onClick:O,').length - 1 !== sections.length) throw new Error('Settings save buttons changed');
  for (const section of sections) source = source.replace('onClick:O,', `onClick:()=>O("${section}"),`);
  source = source.replace('بعد از هرگونه تغییر در تنظیمات ربات، یکبار گزینه ذخیره باید زده شود.', 'تنظیمات ربات را با دکمه ذخیره همین بخش ثبت کنید؛ ربات خاموش را می‌توانید قبل از تکمیل تنظیمات ذخیره کنید.');
  const newName = 'Settings-speedbox-' + createHash('sha256').update(source).digest('hex').slice(0, 12) + '.js';
  writeFileSync(path.join(directory, newName), source);
  for (const file of readdirSync(directory).filter(file => file.endsWith('.js') && file !== oldName && file !== newName)) {
    const content = readFileSync(path.join(directory, file), 'utf8');
    if (content.includes(oldName)) writeFileSync(path.join(directory, file), content.replaceAll(oldName, newName));
  }
  entry.file = 'assets/' + newName;
  unlinkSync(path.join(directory, oldName));
}

// Standalone refresh for the shipped build, without npm or a source rebuild.
if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const root = path.dirname(path.dirname(path.dirname(fileURLToPath(import.meta.url))));
  const manifestPath = path.join(root, 'public/build/manifest.json');
  const manifest = JSON.parse(readFileSync(manifestPath, 'utf8'));
  integrateSettingsSaves(root, manifest);
  const entry = manifest['resources/js/app.js'];
  const directory = path.join(root, 'public/build/assets');
  const oldName = path.basename(entry.file);
  const source = readFileSync(path.join(directory, oldName), 'utf8');
  const newName = 'app-speedbox-' + createHash('sha256').update(source).digest('hex').slice(0, 12) + '.js';
  writeFileSync(path.join(directory, newName), source);
  for (const file of readdirSync(directory).filter(file => file.endsWith('.js') && file !== oldName && file !== newName)) {
    const content = readFileSync(path.join(directory, file), 'utf8');
    if (content.includes(oldName)) writeFileSync(path.join(directory, file), content.replaceAll(oldName, newName));
  }
  entry.file = 'assets/' + newName;
  writeFileSync(manifestPath, JSON.stringify(manifest, null, 2) + '\n');
  if (oldName !== newName) unlinkSync(path.join(directory, oldName));
  console.log('Independent Settings saves integrated:', newName);
}
