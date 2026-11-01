// Test the maintained page components; mocked API and no live account mutations.
import assert from 'node:assert/strict';
import { loadSourceUi } from './helpers/source-ui.mjs';
import { reservationOperatorLabel } from '../../resources/js/utils/reservation-status.js';
const { JSDOM } = await import(process.env.SPEEDBOX_TEST_JSDOM || 'jsdom');
const rows = ['reserved', 'activated', 'cancelled', null].map((state, index) => ({
  id: index + 1, type: 'account_extend', status: 'success', amount: -1000, signed_amount: -1000,
  balance_after: 9000, created_at: '2026-10-26 00:57:00', performed_by_role: 1,
  plan_label: 'یک ماهه', tariff_group_name: 'طرح ۶۰ گیگ', affected_users: ['alice@rep.com'],
  reservation_state: state, reservation_history: state ? [
    { state: 'reserved', created_at: '2026-10-26 00:57:00', performed_by_role: 1 },
    ...(state === 'reserved' ? [] : [{ state, created_at: '2026-10-30 04:01:00', performed_by_role: state === 'activated' ? 'system' : 2 }]),
  ] : [],
}));
let checks = 0;
for (const role of ['admin', 'representative', 'billing', 'invoices']) {
  const dom = new JSDOM('<div id="fixture"></div>', { url: 'https://fixture.test', runScripts: 'outside-only', pretendToBeVisual: true });
  const win = dom.window;
  win.matchMedia = () => ({ matches: true, addEventListener() {}, removeEventListener() {} });
  win.fetch = async () => ({ json: async () => ({}) }); win.AbortController = AbortController; win.scrollTo = () => {};
  win.HTMLCanvasElement.prototype.toDataURL = () => 'data:image/png;base64,VEVTVA==';
  win.HTMLCanvasElement.prototype.getContext = () => ({ createImageData: (w, h) => ({ data: new Uint8ClampedArray(w * h * 4) }), clearRect() {}, putImageData() {} });
  const ui = await loadSourceUi(dom);
  win.__SPEEDBOX_USE_MOBILE__ = () => ui.Vue.ref(true);
  win.__SPEEDBOX_RENDER_NATIVE_SELECT__ = props => ui.Vue.h('select', { value: props.value }, []);
  ui.http.get = async url => ({ data: url.includes('transactions') ? rows : { data: [], user: {}, plan_groups: [], groups: [], volume_groups: [] } });
  ui.http.post = async () => { throw Error('This layout test must not mutate accounts'); };
  const app = ui.Vue.createApp({ admin: ui.Users, representative: ui.Panel, billing: ui.Billing, invoices: ui.Invoices }[role], { role: role === 'admin' ? 1 : 2, userCode: 'rep' });
  const errors = []; app.config.errorHandler = error => errors.push(error.message); app.use(ui.router);
  const state = app.mount('#fixture');
  const flush = async () => { for (let i = 0; i < 20; i++) { await Promise.resolve(); await ui.Vue.nextTick(); } };
  await flush();
  if (role === 'admin' || role === 'representative') {
    state.transactionRecordsUser = { id: 100, email: 'alice@rep.com' };
    state.transactionRecords = rows; state.transactionPagination.total = rows.length; state.showTransactionRecords = true;
    await flush();
  }
  const cards = [...win.document.querySelectorAll(role === 'admin' || role === 'representative' ? '.transaction-records-cards .mobile-card-row' : '.mobile-card-row')];
  assert.equal(cards.length, rows.length); checks++;
  for (const card of cards) {
    const date = card.querySelector('.transaction-date-box'), operator = card.querySelector('.transaction-operator-box');
    assert.equal(date.querySelector('small').textContent, 'تاریخ');
    assert.equal(operator.querySelector('small').textContent, 'اپراتور');
    assert(!card.textContent.includes('سابقه رزرو')); checks += 3;
    const lines = [...date.querySelectorAll('.reservation-history-line')];
    for (const line of lines) {
      const content = line.querySelector('.reservation-history-content');
      assert.equal(content.children.length, 2); // Badge and timestamp; operator has its own box.
      assert(content.children[0].classList.contains('reservation-status'));
      assert.equal(content.children[1].tagName, 'BDI');
      assert(content.children[1].textContent.trim()); checks += 4;
    }
    if (lines.length === 2) {
      assert.equal(operator.querySelector('.transaction-operator-sequence').textContent.trim(), lines[1].textContent.includes('فعال‌سازی') ? 'ادمین - سیستم' : 'ادمین - کاربر'); checks++;
    }
  }
  assert.deepEqual(errors, []); checks++;
  app.unmount(); dom.window.close();
}
assert.equal(reservationOperatorLabel({ performed_by_role: 'telegram_bot' }, role => role), 'telegram_bot'); checks++;
console.log(`Transaction history layout: ${checks} checks passed across admin/representative history and billing cards.`);
