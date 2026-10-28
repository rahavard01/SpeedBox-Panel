import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';

const source = readFileSync(new URL('../ui/partner-accounts.mjs', import.meta.url), 'utf8');
const mounted = [], unmounted = [], watches = [], requests = [];
const vnode = (type, props, children, flag) => ({ type, props, children, flag });
let rows = [{ id: 11, email: 'one@owner.com', device_limit: 2 }], rejectHistory = false, delayed;
let interval, cleared = false, transactions;
const context = vm.createContext({
  G: value => ({ value }), b: vnode, x: vnode, l2: vnode, Ft: 'teleport', k: () => {}, Ue: fn => { fn?.(); return Promise.resolve(); }, xt: value => value.split('@')[0], _n: value => (Number(value) / 1073741824) + ' GB',
  x1: (getter, callback, options) => { watches.push({ getter, callback }); if (options.immediate) callback(); },
  be: fn => mounted.push(fn), e2: fn => unmounted.push(fn), AbortController, Intl, Date,
  document: { hidden: false, body: { style: { overflow: '' } }, addEventListener() {}, removeEventListener() {} }, window: { innerWidth: 1200, innerHeight: 800, addEventListener() {}, removeEventListener() {} }, setInterval: fn => { interval = fn; return 7; }, clearInterval: id => { cleared = id === 7; },
  w0: {
    post: async (url, body) => { requests.push({ url, body }); if (delayed) return delayed.promise; return { data: { data: body.ids.map(id => ({ id, online_count: 2, device_limit: id === 11 ? 2 : 0 })) } }; },
    get: async (url, options) => {
      requests.push({ url, options });
      if (delayed) return delayed.promise;
      if (rejectHistory) throw { response: { data: { errors: { page: ['خطای آزمایشی'] } } } };
      return { data: { data: [{ id: 1, reset_time: '2026-10-11 00:00:00', reset_type: 'manual', old_total: 1073741824, new_total: 0 }], pagination: { total: 12, last_page: 2 }, account: { reset_count: 13, last_reset_at: 1791676800, next_reset_at: 1794355200 } } };
    },
  },
});
vm.runInContext(source.replace('export function ', 'function ') + '\nthis.create=speedboxCreatePartnerAccounts;', context);
const ui = context.create({ rows: () => rows, transactionHistory: row => { transactions = row; } });
const flush = async () => { await Promise.resolve(); await Promise.resolve(); };
const nodes = node => !node || typeof node !== 'object' ? [] : [node, ...(Array.isArray(node) ? node : Array.isArray(node.children) ? node.children : []).flatMap(nodes)];
const buttons = () => nodes(ui.view()).filter(node => node.type === 'button');
const labelText = value => typeof value === 'string' ? value : Array.isArray(value) ? value.map(labelText).join('') : value && typeof value === 'object' ? labelText(value.children) : '';
const click = label => { const node = buttons().find(node => labelText(node.children) === label); assert.ok(node, 'Button exists: ' + label); return node.props.onClick(); };
await flush(); mounted.forEach(fn => fn());
assert.deepEqual(JSON.parse(JSON.stringify(requests[0].body.ids)), [11]);
assert.equal(ui.onlineBadge(rows[0]).children[1].children, '۲ / ۲');
assert.ok(ui.onlineBadge(rows[0]).props.class.includes('is-at-limit'));
assert.equal(ui.view().flag, -2, 'Factory updates participate in the compiled Vue block');
ui.openHistory(rows[0]);
assert.deepEqual(buttons().map(node => labelText(node.children)), ['تاریخچه تراکنش‌ها', 'تاریخچه بازنشانی ترافیک']);
assert.ok(!nodes(ui.view()).some(node => node.props?.role === 'dialog'));
click('تاریخچه تراکنش‌ها'); assert.equal(transactions, rows[0]);
ui.openHistory(rows[0]); await click('تاریخچه بازنشانی ترافیک');
assert.equal(requests.at(-1).url, '/users/11/reset-history');
assert.equal(requests.at(-1).options.params.page, 1);
assert.ok(nodes(ui.view()).some(node => node.children === 'دستی'));
assert.ok(nodes(ui.view()).some(node => node.children === '1 GB'));
assert.ok(nodes(ui.view()).some(node => node.children === '۱۳'));
assert.deepEqual(buttons().map(node => labelText(node.children)), ['بازگشت']);
click('بازگشت'); rejectHistory = true; ui.openHistory(rows[0]); await click('تاریخچه بازنشانی ترافیک');
assert.ok(nodes(ui.view()).some(node => node.props?.role === 'alert' && node.children === 'خطای آزمایشی'));
rejectHistory = false; await click('تلاش مجدد');
assert.equal(nodes(ui.view()).filter(node => node.props?.role === 'alert').length, 0);
// Changing account while a response is in flight must never show the old history.
let resolve; delayed = { promise: new Promise(done => { resolve = done; }) };
ui.openHistory(rows[0]); const pending = click('تاریخچه بازنشانی ترافیک'); click('بازگشت');
ui.openHistory({ id: 12, email: 'two@owner.com' });
resolve({ data: { data: [{ id: 9, reset_type: 'stale' }] } }); await pending;
assert.ok(!nodes(ui.view()).some(node => node.props?.role === 'dialog'));
assert.ok(!nodes(ui.view()).some(node => node.children === 'stale'));
delayed = null;
rows = [{ id: 12, email: 'two@owner.com', device_limit: 0 }];
watches[0].callback(); await flush();
assert.equal(ui.onlineBadge(rows[0]).children[1].children, '۲ / ∞');
const before = requests.length; context.document.hidden = true; interval(); await flush(); assert.equal(requests.length, before);
unmounted.forEach(fn => fn()); assert.equal(cleared, true);
assert.ok(requests.every(request => request.url.endsWith('/reset-history') || request.url === '/users/online'), 'No traffic reset mutation is available');
console.log('Representative online counts, history menu, summaries and errors, stale responses and cleanup passed.');
