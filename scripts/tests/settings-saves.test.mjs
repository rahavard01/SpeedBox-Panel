import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import vm from 'node:vm';
import assert from 'node:assert/strict';

// Exercise the actual shipped component's render handlers without a browser,
// a database, Telegram, or a dependency on the unavailable original Vue source.
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const manifest = JSON.parse(readFileSync(path.join(root, 'public/build/manifest.json')));
const source = readFileSync(path.join(root, 'public/build', manifest['resources/js/pages/admin/Settings.vue'].file), 'utf8');
const reactive = [], requests = [], notifications = [];
let rejectNext = null;
const vnode = (type, props, children) => ({ type, props, children });
const tariffFixture = () => ({
  status: 'ok', v2_options: [],
  volume_groups: [{ id: 1, name: 'Volume', min_traffic_gb: 1, is_default: 1,
    items: [{ plan_key: 'volume_gig', default_price: 100, details: 'Volume plan', v2_plan_id: 1 }] }],
  groups: [{ id: 2, name: 'Time', items: ['gig', 'test', '1m', '3m', '6m', '12m'].map(plan_key => ({
    plan_key, default_price: 100, details: 'Plan', v2_plan_id: 1, enable: 1,
  })) }],
});
const http = Object.fromEntries(['get', 'put', 'post', 'patch'].map(method => [method, async (url, body) => {
  requests.push({ method, url, body: body && JSON.parse(JSON.stringify(body)) });
  if (rejectNext) { const error = rejectNext; rejectNext = null; throw error; }
  return { data: method === 'get' ? tariffFixture() : {
    status: url.startsWith('/user-bot/') ? 'success' : 'ok', changed: true, data: {},
  } };
}]));
const api = {
  _: component => component, Z: () => {}, i: vnode, f: vnode, j: html => vnode('html', null, html),
  ag: () => ({ success: message => notifications.push({ success: message }), error: message => notifications.push({ error: message }) }),
  an: () => {}, a0: value => { reactive.push(value); return value; }, X: () => {},
  S: value => value, ap: node => node, am: {}, a9: value => String(value ?? ''), F: 'fragment',
  a3: (list, fn) => Array.isArray(list) ? list.map(fn) : Object.entries(list).map(([key, value]) => fn(value, key)),
  aj: {}, al: {}, k: text => vnode('text', null, text), ah: {}, h: () => vnode('comment'),
  l: vnode, U: {}, ar: fn => fn, g: vnode, T: 'teleport', v: http, a1: value => ({ value }), ak: value => value,
};
const context = vm.createContext({ window: {}, document: { body: { style: {} } }, console, setTimeout, clearTimeout });
const imports = source.match(/^import\{([^}]+)\}from"[^"]+";/);
assert.ok(imports, 'expected Settings main import');
for (const item of imports[1].split(',')) {
  const [name, alias] = item.trim().split(' as ');
  assert.ok(name in api, 'provide real import semantics for ' + name);
  context[alias || name] = api[name];
}
vm.runInContext(source.replace(imports[0], '').replace(/export\{(\w+) as default\};/, 'globalThis.component=$1;'), context);
const render = context.component.setup({});
const state = reactive.find(value => value.userBot);
const cache = [];
function allNodes(node) {
  if (!node || typeof node !== 'object') return [];
  if (Array.isArray(node)) return node.flatMap(allNodes);
  return [node, ...allNodes(node.children)];
}
const nodes = () => allNodes(render({}, cache));
const sections = ['tariffs', 'payment', 'card', 'crypto', 'commission', 'userBot'];
function saveButton(section) {
  return nodes().find(node => node.type === 'button' && node.props?.onClick?.toString().includes('O("' + section + '")'));
}
for (const section of sections) assert.ok(saveButton(section), 'independent save button: ' + section);
function reset() { requests.length = 0; notifications.length = 0; }
function writes() { return requests.filter(request => request.method !== 'get'); }
async function save(section) { reset(); await saveButton(section).props.onClick(); }

// No tariff/card/bot completeness checks may block unrelated section saves.
await save('payment');
assert.equal(writes().length, 1);
assert.equal(writes()[0].url, '/admin/payment-settings');
assert.deepEqual(Object.keys(writes()[0].body).sort(), ['methods', 'section']);
assert.equal(writes()[0].body.section, 'payment');
await save('crypto');
assert.equal(writes().length, 1);
assert.equal(writes()[0].body.section, 'crypto');
assert.ok(!('methods' in writes()[0].body));
await save('commission');
assert.equal(writes().length, 1);
assert.equal(writes()[0].url, '/admin/commission-settings');
state.card.holder = 'Test'; state.card.card_number = '1234-5678-9012-3456';
await save('card');
assert.equal(writes().length, 1);
assert.equal(writes()[0].url, '/admin/card-settings');
assert.equal(writes()[0].body.card_number, '1234567890123456');

// Empty disabled bot, including an incomplete enabled draft plan, is saveable.
state.userBot.plans = [{ plan_key: 'draft', title: 'Draft', price: '', is_enabled: true }];
await save('userBot');
assert.equal(writes().length, 1);
assert.equal(writes()[0].url, '/user-bot/settings');
assert.equal(writes()[0].body.is_enabled, false);
assert.equal(writes()[0].body.help_ios, '');
assert.ok(notifications.some(item => item.success?.includes('غیرفعال')));

state.userBot.is_enabled = true;
await save('userBot');
assert.equal(writes().length, 0);
assert.ok(notifications[0].error.includes('توکن'));
state.userBot.bot_token = 'test-token'; state.userBot.card_number = '1234-5678-9012-3456';
await save('userBot');
assert.equal(writes().length, 0);
assert.ok(notifications[0].error.includes('راهنمای'));
for (const field of ['help_ios', 'help_android', 'help_windows']) state.userBot[field] = 'Guide';
await save('userBot');
assert.equal(writes().length, 0);
assert.ok(notifications[0].error.includes('قیمت'));
state.userBot.plans[0].price = 100;
await save('userBot');
assert.equal(writes().length, 1);
assert.equal(writes()[0].body.card_number, '1234567890123456');
state.userBot.is_enabled = false;

// Disabled currency drafts save within the bot; rejected saves restore toggles.
function changeHandler(fragment) {
  return nodes().find(node => node.props?.onChange?.toString().includes(fragment)).props.onChange;
}
state.userBot.crypto_options[0].enabled = true;
reset(); await changeHandler('dt(')();
assert.equal(writes().length, 1);
assert.equal(writes()[0].url, '/user-bot/settings');
state.userBot.crypto_options[0].enabled = false;
rejectNext = { response: { data: { errors: { wallet: ['Wallet error'] } } } };
reset(); await changeHandler('dt(')();
assert.equal(state.userBot.crypto_options[0].enabled, true);
assert.equal(notifications[0].error, 'Wallet error');
state.userBot.is_enabled = true;
rejectNext = { response: { data: { errors: { help_ios: ['Guide error'] } } } };
reset(); await changeHandler('j("is_enabled")')();
assert.equal(state.userBot.is_enabled, false);
assert.equal(notifications[0].error, 'Guide error');

// Server field errors must be surfaced; a failed save releases the busy flag.
rejectNext = { response: { data: { message: 'Generic error', errors: { field: ['Specific field error'] } } } };
await save('commission');
assert.equal(notifications[0].error, 'Specific field error');
await save('commission');
assert.equal(writes().length, 1);
assert.ok(notifications[0].success);

// Tariff saving never writes bot settings, preserves draft bot prices, refreshes
// adjusted prices and clears the percentage so the next save cannot apply it twice.
const fixture = tariffFixture();
state.tariffs.volume_groups = [{ ...fixture.volume_groups[0], items: [{ key: 'volume_gig', price: 100, description: 'Volume', linked_plan_id: 1 }] }];
state.tariffs.groups = [{ ...fixture.groups[0], items: fixture.groups[0].items.map(item => ({
  key: item.plan_key, price: 100, description: 'Plan', linked_plan_id: 1, enabled: true,
})) }];
state.userBot.plans = [{ plan_key: '2_1m', title: 'Draft title', price: 250, description: 'Draft', is_enabled: false }];
state.tariffs.bulk.percent = 10;
await save('tariffs');
assert.deepEqual(writes().map(request => request.url), ['/admin/tariffs', '/admin/tariffs/percent-adjust']);
assert.equal(requests.filter(request => request.method === 'get').length, 2);
assert.equal(state.tariffs.bulk.percent, null);
assert.equal(state.userBot.plans.find(plan => plan.plan_key === '2_1m').price, 250);
await save('tariffs');
assert.deepEqual(writes().map(request => request.url), ['/admin/tariffs']);
console.log('Settings save isolation, bot drafts/readiness, errors, and percentage regressions passed.');
