import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
const { JSDOM } = await import(process.env.PARTNER_TEST_JSDOM || 'jsdom');
const read = file => readFileSync(new URL('../../' + file, import.meta.url), 'utf8');
const manifest = JSON.parse(read('public/build/manifest.json'));
const coreFile = manifest['resources/js/app.js'].file;
const source = read('public/build/' + coreFile).replace('Cs.mount("#app")', 'void 0').replace('Cs.use(us)', 'void 0') + '\nexport {ac as TestApp,Sp as TestTooltip,b as TestElement,G as TestRef,be as TestMounted,e2 as TestUnmounted,Ue as TestTick};';
let checks = 0;
const ok = (condition, message) => { assert.ok(condition, message); checks++; };
for (const width of [375, 820, 1280]) {
  const mobile = width < 900;
  const dom = new JSDOM('<div id="fixture"></div><button id="outside">outside</button>', { url: 'http://fixture.test/', runScripts: 'outside-only', pretendToBeVisual: true });
  const win = dom.window;
  win.localStorage.setItem('user', '{"id":1,"role":1,"code":"admin"}');
  win.localStorage.setItem('token', 'fixture-token');
  win.matchMedia = query => ({ matches: query.includes('hover') ? mobile : mobile, addEventListener() {}, removeEventListener() {} });
  win.fetch = async () => ({ json: async () => ({}) }); win.AbortController = AbortController; win.scrollTo = () => {};
  for (const file of ['public/build/assets/app--UQp4rU8.css', 'public/speedbox/bot-faqs.css', ...['Settings','Dashboard','Users'].flatMap(name => manifest['resources/js/pages/admin/' + name + '.vue'].css.map(file => 'public/build/' + file))]) {
    const style = win.document.createElement('style'); style.textContent = read(file); win.document.head.append(style);
  }
  const context = dom.getInternalVMContext();
  const core = new vm.SourceTextModule(source, { context, initializeImportMeta: meta => { meta.url = 'http://fixture.test/' + coreFile; } });
  await core.link(() => { throw Error('Unexpected core import'); }); await core.evaluate();
  const modules = new Map([[coreFile.split('/').at(-1), core]]);
  async function load(file) {
    file = file.split('/').at(-1); if (modules.has(file)) return modules.get(file);
    const module = new vm.SourceTextModule(read('public/build/assets/' + file), { context, initializeImportMeta: meta => { meta.url = 'http://fixture.test/assets/' + file; } });
    modules.set(file, module); await module.link(specifier => load(specifier)); await module.evaluate(); return module;
  }
  const flush = async () => { for (let i = 0; i < 10; i++) { await Promise.resolve(); await core.namespace.TestTick(); } };
  const wait = async ms => { await new Promise(resolve => setTimeout(resolve, ms)); await flush(); };
  function pointer(node, type, pointerType) { const event = new win.Event(type, { bubbles: true }); Object.defineProperty(event, 'pointerType', { value: pointerType }); node.dispatchEvent(event); }
  const mount = async component => {
    const errors = [], app = core.namespace.TestApp(component); app.config.errorHandler = error => errors.push(error);
    app.mount('#fixture'); await flush(); return { app, errors };
  };
  const cards = { panelStatus: { partnersTotal: 1, usersTotal: 10, usersOnline: 3, usersExpired: 2, onlineIps: 7 }, newUsers: { today: 2, yesterday: 1, vsYesterdayPct: 100, thisMonth: 8, prevMonth: 4, vsPrevMonthPct: 100 }, renewals: { today: 1, yesterday: 0, vsYesterdayPct: 0, thisMonth: 4, prevMonth: 2, vsPrevMonthPct: 100 }, deposits: { thisMonthToman: 10000, prevMonthToman: 5000, vsPrevMonthPct: 100 }, pending: { deposits: 0, partnerRequests: 0 }, speedboxActive: { now: 1, last24h: 2 }, speedboxActiveAndroid: { now: 1, last24h: 2 }, meta: { pollSeconds: 60 } };
  const account = { id: 11, email: 'sample@admin.com', account_plan_name: 'طرح آزمایشی', online_count: 1, device_limit: 2, u: 0, d: 0, transfer_enable: 1000, banned: 0, expired_at: Math.floor(Date.now()/1000) + 3600, created_at: Math.floor(Date.now()/1000) };
  core.namespace.v.get = async url => {
    if (url === '/admin/dashboard/cards') return { data: cards };
    if (url === '/user/panel') return { data: { user: { id: 1, role: 1, code: 'admin' } } };
    if (url === '/admin/users') return { data: [account] };
    return { data: { data: [] } };
  };
  core.namespace.v.post = async () => ({ data: { data: [account] } });
  {
    const { app, errors } = await mount((await load(manifest['resources/js/pages/admin/Dashboard.vue'].file)).namespace.default);
    const tip = win.document.querySelector('.speedbox-dashboard-online-tip'); ok(tip, 'Online uses the shared UiTooltip');
    const trigger = tip.querySelector('.custom-tooltip-trigger');
    ok(trigger.textContent === '۳', 'Only the online number is the trigger');
    ok(!tip.closest('.kpi-box').hasAttribute('data-sb-tooltip'), 'The full KPI card does not trigger a tooltip');
    ok(win.getComputedStyle(trigger.querySelector('.kpi-value')).fontSize === '21px', 'The number retains Dashboard scoped styling');
    if (mobile) { pointer(trigger, 'pointerdown', 'touch'); trigger.click(); await flush(); }
    else { tip.dispatchEvent(new win.MouseEvent('mouseenter')); await wait(100); }
    ok(tip.querySelector('[role="tooltip"]')?.textContent === 'آی‌پی‌های آنلاین: ۷', 'Only active-IP statistics are shown');
    if (mobile) {
      pointer(trigger, 'pointerdown', 'touch'); trigger.click(); await wait(120); ok(!tip.querySelector('[role="tooltip"]'), 'Second tap closes');
      pointer(trigger, 'pointerdown', 'touch'); trigger.click(); await flush();
      pointer(win.document.querySelector('#outside'), 'pointerdown', 'touch'); await wait(120); ok(!tip.querySelector('[role="tooltip"]'), 'Outside tap closes');
    } else {
      trigger.click(); tip.dispatchEvent(new win.MouseEvent('mouseleave')); await wait(120); ok(!tip.querySelector('[role="tooltip"]'), 'Desktop click does not pin the tooltip after mouseleave');
    }
    trigger.focus(); if (!mobile) await wait(100);
    trigger.dispatchEvent(new win.KeyboardEvent('keydown', { key: 'Escape', bubbles: true })); await wait(120); ok(!tip.querySelector('[role="tooltip"]'), 'Escape closes');
    ok(errors.length === 0, 'Dashboard renders without Vue errors'); app.unmount(); await flush();
  }
  {
    const { app, errors } = await mount((await load(manifest['resources/js/pages/admin/Users.vue'].file)).namespace.default);
    const badges = [...win.document.querySelectorAll('.account-plan-badge')]; ok(badges.length > 0, 'Users plan badges render');
    ok(badges.every(node => !node.hasAttribute('title') && !node.hasAttribute('data-sb-tooltip') && !node.closest('.custom-tooltip-wrapper')), 'Plan names have no tooltip in desktop or mobile');
    ok(errors.length === 0, 'Users renders without Vue errors'); app.unmount(); await flush();
  }
  {
    const factorySource = read('scripts/ui/global-bot-faqs.mjs');
    ok(read('public/build/' + manifest['resources/js/pages/admin/Settings.vue'].file).includes(factorySource.replace('export function ', 'function ')), 'The shipped FAQ factory matches its maintained source');
    const factory = new vm.SourceTextModule(factorySource, { context }); await factory.link(() => {}); await factory.evaluate();
    let payload, rows = [], failRead = false;
    const http = { get: async () => { if (failRead) throw Error('Read failed'); return { data: { data: rows } }; }, put: async (_, data) => { payload = data; rows = data.items.map((item,index) => ({ ...item, id: item.id || index + 1 })); return { data: { data: rows } }; } };
    const editor = factory.namespace.createGlobalBotFaqEditor({ element: core.namespace.TestElement, ref: core.namespace.TestRef, mounted: core.namespace.TestMounted, unmounted: core.namespace.TestUnmounted, http });
    const { app, errors } = await mount(editor);
    const add = win.document.querySelector('.faq-add'), save = win.document.querySelector('.faq-save');
    ok(win.document.querySelector('.faq-empty'), 'The empty state is visible');
    ok(win.document.querySelector('.userbot-main-title').textContent === 'سوالات متداول ربات کاربران', 'FAQ title uses the reviewed wording');
    ok(add.textContent === 'افزودن سوالات' && add.querySelector('.fa-plus') && save.textContent === 'ذخیره', 'Actions use the reviewed labels and plus icon');
    ok(win.document.querySelector('.faq-empty-state') && win.document.querySelector('.faq-scope-note .fa-exclamation-circle'), 'Empty state is boxed and the shared scope note follows Panel warning hints');
    ok(win.getComputedStyle(save).borderTopWidth === '1px' && win.getComputedStyle(save).borderRadius === '12px', 'Save uses the original Panel button style');
    add.click(); await flush();
    const question = win.document.querySelector('input.faq-input'), answer = win.document.querySelector('textarea.faq-input');
    ok(question && answer, 'Adding creates both editable fields');
    ok(win.document.querySelector('.faq-number').textContent === 'سوال ۱' && !win.document.querySelector('.faq-enabled'), 'Rows are numbered and have no activation toggle');
    ok(win.document.querySelectorAll('.faq-order-btn svg').length === 3 && win.getComputedStyle(win.document.querySelector('.faq-order-btn')).alignItems === 'center', 'The three row actions use centered vector icons');
    ok(win.getComputedStyle(question).borderTopWidth === '1px' && win.getComputedStyle(question).borderRadius === '8px', 'Questions have a visible input border and original radius');
    ok(win.getComputedStyle(question).height === '30px' && win.getComputedStyle(answer).minHeight === '100px', 'Input and textarea have usable heights');
    ok(win.getComputedStyle(question).direction === 'rtl', 'Persian inputs retain RTL');
    save.click(); await flush(); ok(!payload && win.document.querySelector('.faq-error'), 'Empty fields cannot be saved');
    question.value = ' سؤال اول '; question.dispatchEvent(new win.Event('input', { bubbles: true }));
    answer.value = ' پاسخ اول '; answer.dispatchEvent(new win.Event('input', { bubbles: true })); await flush();
    add.click(); await flush();
    const fields = win.document.querySelectorAll('.userbot-faq-row');
    for (const [selector,value] of [['input.faq-input','سؤال دوم'],['textarea.faq-input','پاسخ دوم']]) { const input = fields[1].querySelector(selector); input.value = value; input.dispatchEvent(new win.Event('input', { bubbles: true })); }
    fields[1].querySelector('[aria-label="انتقال به بالا"]').click(); await flush();
    save.click(); await flush();
    ok(payload.items[0].question === 'سؤال دوم' && payload.items.every(row => row.enabled === true), 'Order is saved and every saved FAQ is active');
    ok(payload.items[1].question === 'سؤال اول' && payload.items[1].answer === 'پاسخ اول', 'Fields are trimmed and saved');
    win.document.querySelector('[aria-label="حذف سوال"]').click(); await flush(); save.click(); await flush();
    ok(payload.items.length === 1, 'Deletion persists through saving');
    ok(errors.length === 0, 'FAQ editor renders without Vue errors'); app.unmount(); await flush();
    failRead = true; const failed = await mount(editor);
    ok(win.document.querySelector('.faq-save').disabled && win.document.querySelector('.faq-add').disabled, 'Read failures cannot overwrite the global list');
    failed.app.unmount(); await flush();
  }
  dom.window.close();
}
console.log('Tooltip and FAQ polish:', checks, 'checks passed at 375, 820 and 1280 px.');
