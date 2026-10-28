// Run with node --experimental-vm-modules. Install jsdom or set
// PARTNER_TEST_JSDOM to its entry file. Uses the actual shipped Vue runtime.
import assert from 'node:assert/strict';
const { JSDOM } = await import(process.env.SPEEDBOX_TEST_JSDOM || process.env.PARTNER_TEST_JSDOM || 'jsdom');
import { loadSourceUi } from './helpers/source-ui.mjs';
for (const mobile of [false, true]) {
  const dom = new JSDOM('<!doctype html><html><body><div id="fixture"></div><button id="outside">Outside</button></body></html>',
    { url: 'http://fixture.test/', runScripts: 'outside-only', pretendToBeVisual: true });
  const win = dom.window;
  win.matchMedia = () => ({ matches: false, addEventListener() {}, removeEventListener() {} });
  win.fetch = async () => ({ json: async () => ({}) }); win.AbortController = AbortController;
  Object.defineProperty(win, 'innerWidth', { value: mobile ? 375 : 1280 });
  const ui = await loadSourceUi(dom);
  const { createApp: testCreateApp, ref, createElementVNode: b, openBlock: k, createElementBlock: x, nextTick } = ui.Vue;
  const { speedboxCreatePartnerAccounts, http, formatBytes: amount } = ui;
  let transactions, requests = 0, reject = false, delayed, refreshRows;
  http.post = async (_url, { ids }) => ({ data: { data: ids.map(id => ({ id, online_count: 1, device_limit: 2 })) } });
  http.get = async url => {
    assert.equal(url, '/users/11/reset-history'); requests++;
    if (delayed) return delayed;
    if (reject) throw Error('Test offline');
    return { data: { data: [{ id: 1, reset_type: 'manual', trigger_source: 'manual', reset_time: '2026-10-11 00:00:00', old_total: 1073741824 }],
      account: { reset_count: 3, last_reset_at: 1791676800, next_reset_at: 1794355200 } } };
  };
  const component = { setup() {
    const rows = ref([{ id: 11, email: 'demo@owner.com', device_limit: 2 }]);
    const ui = speedboxCreatePartnerAccounts({ rows: () => rows.value, transactionHistory: row => { transactions = row; } });
    refreshRows = () => { rows.value[0].device_limit = 4; };
    return () => { k(); return x('div', null, [ui.view(), b('div', { id: 'badge' }, [ui.onlineBadge(rows.value[0])]), ui.historyButton(rows.value[0], mobile)]); };
  } };
  const app = testCreateApp(component); app.mount('#fixture');
  const flush = async () => { await Promise.resolve(); await nextTick(); await Promise.resolve(); await nextTick(); };
  const trigger = () => win.document.querySelector('[aria-haspopup="menu"]');
  const menuButton = label => [...win.document.querySelectorAll('[role="menuitem"]')].find(button => button.textContent === label);
  await flush(); assert.equal(win.document.querySelector('#badge').textContent, '۱ / ۲');
  refreshRows(); await flush(); assert.equal(win.document.querySelector('#badge').textContent, '۱ / ۴');
  trigger().click(); await flush();
  assert.equal(win.document.querySelector('[role="dialog"]'), null, 'History trigger opens a menu without a modal');
  assert.equal(requests, 0, 'Opening menu does not fetch history');
  assert.deepEqual([...win.document.querySelectorAll('[role="menuitem"]')].map(button => button.textContent), ['تاریخچه تراکنش‌ها', 'تاریخچه بازنشانی ترافیک']);
  assert.equal(win.document.querySelectorAll('[role="menuitem"] i').length, 2);
  assert.equal(trigger().getAttribute('aria-expanded'), 'true');
  assert.ok(parseFloat(win.document.querySelector('[role="menu"]').style.left) >= 12);
  assert.equal(win.document.querySelector('[role="menu"]').style.width, '205px', 'History menu matches admin width');
  win.document.dispatchEvent(new win.KeyboardEvent('keydown', { key: 'Escape', bubbles: true })); await flush();
  assert.equal(win.document.querySelector('[role="menu"]'), null); assert.equal(win.document.activeElement, trigger());
  trigger().click(); await flush(); menuButton('تاریخچه تراکنش‌ها').click(); await flush();
  assert.equal(transactions.id, 11); assert.equal(win.document.querySelector('[role="menu"]'), null);
  trigger().click(); await flush(); win.document.querySelector('#outside').dispatchEvent(new win.Event('pointerdown', { bubbles: true })); await flush();
  assert.equal(win.document.querySelector('[role="menu"]'), null, 'Outside click dismisses menu');
  trigger().click(); await flush(); menuButton('تاریخچه بازنشانی ترافیک').click(); await flush();
  const dialog = win.document.querySelector('[role="dialog"]'); assert.ok(dialog);
  assert.equal(dialog.querySelector('h3').textContent, 'تاریخچه بازنشانی ترافیک'); assert.equal(dialog.querySelector('h3 i'), null);
  assert.ok(dialog.querySelector('.traffic-modal-subtitle .fa-user')); assert.ok(dialog.querySelector('.traffic-modal-subtitle span').textContent.includes('demo'));
  assert.equal(dialog.querySelector('.reset-summary dd').textContent, '۳'); assert.equal(dialog.querySelectorAll('.reset-summary dd').length, 3);
  assert.ok(dialog.querySelector('.reset-log-card').textContent.includes('دستی'));
  assert.ok(dialog.querySelector('.reset-log-card').textContent.includes(amount(1073741824)));
  assert.deepEqual([...dialog.querySelectorAll('button')].map(button => button.textContent), ['بازگشت']);
  assert.equal(win.document.body.style.overflow, 'hidden');
  dialog.querySelector('button').click(); await flush(); assert.equal(win.document.querySelector('[role="dialog"]'), null); assert.equal(win.document.body.style.overflow, '');
  reject = true; trigger().click(); await flush(); menuButton('تاریخچه بازنشانی ترافیک').click(); await flush();
  assert.ok(win.document.querySelector('[role="alert"]')); reject = false;
  [...win.document.querySelectorAll('button')].find(button => button.textContent === 'تلاش مجدد').click(); await flush();
  assert.equal(win.document.querySelector('[role="alert"]'), null);
  [...win.document.querySelectorAll('button')].find(button => button.textContent === 'بازگشت').click(); await flush();
  let release; delayed = new Promise(done => { release = done; });
  trigger().click(); await flush(); menuButton('تاریخچه بازنشانی ترافیک').click(); await flush();
  win.document.dispatchEvent(new win.KeyboardEvent('keydown', { key: 'Escape', bubbles: true })); await flush();
  release({ data: { data: [{ id: 9, reset_type: 'stale' }] } }); await flush();
  assert.equal(win.document.querySelector('[role="dialog"]'), null, 'Late response cannot reopen closed history');
  app.unmount(); assert.equal(win.document.querySelector('.speedbox-partner-history'), null); dom.window.close();
}
console.log('Vue desktop/mobile history menus, transaction dispatch, reset summaries, errors, stale responses and cleanup passed.');
