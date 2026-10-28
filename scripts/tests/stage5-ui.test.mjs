import { readFileSync } from 'node:fs';
import assert from 'node:assert/strict';
import { loadSourceUi } from './helpers/source-ui.mjs';
const { JSDOM } = await import(process.env.SPEEDBOX_TEST_JSDOM || process.env.PARTNER_TEST_JSDOM || 'jsdom');
const read = file => readFileSync(new URL('../../' + file, import.meta.url), 'utf8');
for (const width of [375, 1280]) {
  const dom = new JSDOM('<div id="fixture"></div>', { url: 'http://fixture.test', pretendToBeVisual: true, runScripts: 'outside-only' });
  const win = dom.window;
  Object.defineProperty(win, 'innerWidth', { value: width, writable: true });
  win.localStorage.setItem('user', '{"role":1,"id":1}'); win.localStorage.setItem('token', 'test');
  win.matchMedia = () => ({ matches: width < 768, addEventListener(){}, removeEventListener(){} });
  win.fetch = async () => ({ json: async () => ({}) }); win.AbortController = AbortController; win.scrollTo = () => {};
  win.eval(read('public/speedbox/register-infrastructure.js'));
  win.eval(read('public/speedbox/panel-tooltip.js'));
  win.document.dispatchEvent(new win.Event('DOMContentLoaded'));
  const css = win.document.createElement('style'); css.textContent = read('public/speedbox/bot-faqs.css'); win.document.head.append(css);
  const context = dom.getInternalVMContext();
  const ui = await loadSourceUi(dom);
  const root = {namespace:{StageCreateApp:ui.Vue.createApp,f:ui.Vue.h,a1:ui.Vue.ref,X:ui.Vue.onMounted,W:ui.Vue.onBeforeUnmount,v:ui.http}};
  const factory = {namespace:{createGlobalBotFaqEditor:ui.createGlobalBotFaqEditor}};
  let saved = [{ id: 1, question: 'سؤال اول', answer: 'پاسخ اول', enabled: true }, { id: 2, question: 'سؤال دوم', answer: 'پاسخ دوم', enabled: false }], lastPayload;
  const feedback = [];
  const component = factory.namespace.createGlobalBotFaqEditor({ element: root.namespace.f, ref: root.namespace.a1, mounted: root.namespace.X, unmounted: root.namespace.W,
    http: { get: async url => { assert.equal(url, '/admin/user-bot/faqs'); return { data: { data: saved } }; }, put: async (url, payload) => { assert.equal(url, '/admin/user-bot/faqs'); lastPayload = JSON.parse(JSON.stringify(payload)); saved = payload.items.map((row, index) => ({ ...row, id: row.id || index + 3 })); return { data: { data: saved } }; } }, feedback: message => feedback.push(message) });
  const errors = [], app = root.namespace.StageCreateApp(component); app.config.errorHandler = error => errors.push(error);
  app.mount('#fixture');
  const flush = async () => { for (let i = 0; i < 5; i++) await new Promise(resolve => setImmediate(resolve)); };
  await flush();
  const doc = win.document, row = () => [...doc.querySelectorAll('.userbot-faq-row')];
  assert.equal(row().length, 2);
  row()[1].querySelector('[aria-label="انتقال به بالا"]').click(); await flush();
  assert.equal(row()[0].querySelector('input.faq-input').value, 'سؤال دوم');
  assert.equal(doc.querySelector('input[type="checkbox"]'), null, 'Current FAQ editor saves every published item as enabled');
  [...doc.querySelectorAll('.faq-actions button')].find(node => node.textContent === 'افزودن سوالات').click(); await flush();
  const fresh = row().at(-1), question = fresh.querySelector('input.faq-input'), answer = fresh.querySelector('textarea');
  question.value = 'سؤال جدید'; question.dispatchEvent(new win.Event('input', { bubbles: true }));
  answer.value = 'پاسخ جدید\nخط دوم'; answer.dispatchEvent(new win.Event('input', { bubbles: true }));
  const save = doc.querySelectorAll('.faq-actions button')[1]; save.click(); await flush();
  assert.deepEqual(lastPayload.items.map(item => item.question), ['سؤال دوم', 'سؤال اول', 'سؤال جدید']);
  assert.equal(lastPayload.items[0].enabled, true); assert.equal(lastPayload.items[2].answer, 'پاسخ جدید\nخط دوم');
  assert.equal(feedback.length, 1);
  for (const node of row()) node.querySelector('[aria-label="حذف سوال"]').click(); await flush(); save.click(); await flush();
  assert.equal(lastPayload.items.length, 0, 'Deleting all FAQ entries can be saved');
  // Native titles are migrated to the same RTL visual style and never inject HTML.
  const badge = doc.createElement('span'); badge.title = 'سقف اتصال: ۲ کاربر'; badge.setAttribute('data-sb-tooltip-tap', ''); badge.tabIndex = 0;
  badge.getBoundingClientRect = () => ({ left: width - 30, right: width, top: 120, bottom: 150, width: 30, height: 30 }); doc.body.append(badge); await flush();
  assert.equal(badge.hasAttribute('title'), false);
  badge.click(); await flush(); const tip = doc.getElementById('speedbox-shared-tooltip');
  assert.equal(tip.hidden, false); assert.equal(tip.textContent, 'سقف اتصال: ۲ کاربر');
  assert.equal(win.getComputedStyle(tip).direction, 'rtl');
  assert.ok(badge.getAttribute('aria-describedby').includes(tip.id));
  badge.setAttribute('data-sb-tooltip', 'آی‌پی‌های فعال: ۶۲۰'); await flush(); assert.equal(tip.textContent, 'آی‌پی‌های فعال: ۶۲۰');
  badge.click(); await flush(); assert.equal(tip.hidden, true, 'Second tap closes tooltip');
  badge.focus(); await flush(); assert.equal(tip.hidden, false, 'Keyboard focus opens tooltip');
  doc.dispatchEvent(new win.KeyboardEvent('keydown', { key: 'Escape', bubbles: true })); assert.equal(tip.hidden, true);
  badge.setAttribute('data-sb-tooltip', '<img src=x onerror=alert(1)>'); badge.click(); await flush();
  assert.equal(tip.querySelector('img'), null, 'Tooltip treats text as text');
  doc.body.click(); assert.equal(tip.hidden, true, 'Outside tap closes tooltip');
  badge.click(); badge.remove(); await flush(); assert.equal(tip.hidden, true, 'Removed Vue trigger closes tooltip');
  assert.deepEqual(errors, []);
  app.unmount();
  const cards={panelStatus:{partnersTotal:2,usersTotal:20,usersOnline:4,usersExpired:3,onlineIps:7},newUsers:{today:0,yesterday:0,thisMonth:0,prevMonth:0,vsYesterdayPct:0,vsPrevMonthPct:0},renewals:{today:0,thisMonth:0,vsYesterdayPct:0,vsPrevMonthPct:0},deposits:{thisMonthToman:0,vsPrevMonthPct:0},wallet:{thisMonthToman:0,vsPrevMonthPct:0},speedboxActive:{now:0,last24h:0,windows:{now:0,last24h:0},android:{now:0,last24h:0}},pending:{deposits:0,partnerRequests:0},meta:{generatedAt:'',pollSeconds:60,ranges:{}}};
  for(const [component,endpoint]of [[ui.Dashboard,'/panel/dashboard/cards'],[ui.AdminDashboard,'/admin/dashboard/cards']]){
    let deliver;
    root.namespace.v.get=async url=>url===endpoint?await new Promise(resolve=>{deliver=resolve;}):{data:{status:'success',data:[],count:0}};
    doc.querySelector('#fixture').innerHTML='';
    const page=root.namespace.StageCreateApp(component);page.config.errorHandler=error=>errors.push(error);page.mount('#fixture');await flush();
    const trigger=doc.querySelector('.speedbox-dashboard-online-tip .custom-tooltip-trigger');
    assert.ok(trigger,'Actual dashboard online number is a tooltip trigger');
    deliver({data:cards});await flush();
    assert.equal(trigger.textContent, '۴', 'Async card response updates the online count');
    assert.equal(trigger.textContent.includes('آی‌پی'),false,'No visible IP row is added');
    if (width < 768) trigger.click();
    else { trigger.closest('.custom-tooltip-wrapper').dispatchEvent(new win.MouseEvent('mouseenter')); await new Promise(resolve=>setTimeout(resolve,100)); }
    await flush();
    assert.ok(doc.querySelector('.speedbox-dashboard-online-tip [role="tooltip"]').textContent.includes('۷'));
    page.unmount();await flush();assert.equal(doc.querySelector('.speedbox-dashboard-online-tip'),null);
  }
  assert.deepEqual(errors, []);
  dom.window.close();
}
// Placement checks use maintained component names, never minified symbols.
assert.ok(read('resources/js/pages/admin/Settings.vue').includes('<GlobalBotFaqs'));
assert.equal(read('resources/js/pages/Panel.vue').includes('GlobalBotFaqs'), false, 'No representative FAQ editor');
for (const name of ['Dashboard','admin/Dashboard']) assert.ok(read('resources/js/pages/'+name+'.vue').includes('panelStatus.onlineIps'));
assert.ok(read('resources/js/pages/admin/Users.vue').includes('سقف اتصال: '));
console.log('Stage 5: real Vue FAQ CRUD, ordering, admin editor placement, RTL tooltip touch/keyboard/lifecycle passed at 375 and 1280px.');
