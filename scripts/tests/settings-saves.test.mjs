import { readFileSync } from 'node:fs';
import assert from 'node:assert/strict';

// Run the maintained component with Vue; minifier aliases are not an API.
import { loadSourceUi } from './helpers/source-ui.mjs';
const { JSDOM } = await import(process.env.SPEEDBOX_TEST_JSDOM || process.env.PARTNER_TEST_JSDOM || 'jsdom');
const requests = [];
let rejectNext = null;
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
  return { data: method === 'get' ? (url === '/admin/tariffs' ? tariffFixture() : {}) : {
    status: url.startsWith('/user-bot/') ? 'success' : 'ok', changed: true, data: {},
  } };
}]));
const dom = new JSDOM('<div id="fixture"></div>', {url:'http://fixture.test',runScripts:'outside-only',pretendToBeVisual:true});
const win = dom.window;
win.matchMedia = () => ({matches:false,addEventListener(){},removeEventListener(){}});
win.fetch = async () => ({json:async()=>({})}); win.AbortController = AbortController; win.scrollTo = () => {};
win.eval(readFileSync(new URL('../../public/speedbox/register-infrastructure.js', import.meta.url), 'utf8'));
const ui = await loadSourceUi(dom);
Object.assign(ui.http, http);
const app = ui.Vue.createApp(ui.Settings), errors = [];
app.config.errorHandler = error => errors.push(error);
const component = app.mount('#fixture');
const flush = async () => { for(let i=0;i<12;i++) {await Promise.resolve();await ui.Vue.nextTick();} };
await flush();
const state = component.form, notifications = ui.useToast().toasts;
function reset() { requests.length = 0; notifications.splice(0); }
function writes() { return requests.filter(request => request.method !== 'get'); }
async function save(section) { reset(); await component.saveAll(section); await flush(); }

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
assert.ok(notifications.some(item => item.type === 'success' && item.message.includes('غیرفعال')));

state.userBot.is_enabled = true;
await save('userBot');
assert.equal(writes().length, 0);
assert.ok(notifications[0].message.includes('توکن'));
state.userBot.bot_token = 'test-token'; state.userBot.card_number = '1234-5678-9012-3456';
await save('userBot');
assert.equal(writes().length, 0);
assert.ok(notifications[0].message.includes('راهنمای'));
for (const field of ['help_ios', 'help_android', 'help_windows']) state.userBot[field] = 'Guide';
await save('userBot');
assert.equal(writes().length, 0);
assert.ok(notifications[0].message.includes('قیمت'));
state.userBot.plans[0].price = 100;
await save('userBot');
assert.equal(writes().length, 1);
assert.equal(writes()[0].body.card_number, '1234567890123456');
state.userBot.is_enabled = false;

// Disabled currency drafts save within the bot; rejected saves restore toggles.
state.userBot.crypto_options[0].enabled = true;
reset(); await component.toggleUserBotCryptoOption(state.userBot.crypto_options[0]);
assert.equal(writes().length, 1);
assert.equal(writes()[0].url, '/user-bot/settings');
state.userBot.crypto_options[0].enabled = false;
rejectNext = { response: { data: { errors: { wallet: ['Wallet error'] } } } };
reset(); await component.toggleUserBotCryptoOption(state.userBot.crypto_options[0]);
assert.equal(state.userBot.crypto_options[0].enabled, true);
assert.equal(notifications[0].message, 'Wallet error');
state.userBot.is_enabled = true;
rejectNext = { response: { data: { errors: { help_ios: ['Guide error'] } } } };
reset(); await component.toggleUserBotSetting('is_enabled');
assert.equal(state.userBot.is_enabled, false);
assert.equal(notifications[0].message, 'Guide error');

// Server field errors must be surfaced; a failed save releases the busy flag.
rejectNext = { response: { data: { message: 'Generic error', errors: { field: ['Specific field error'] } } } };
await save('commission');
assert.equal(notifications[0].message, 'Specific field error');
await save('commission');
assert.equal(writes().length, 1);
assert.ok(notifications[0].type === 'success');

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
assert.deepEqual(errors, []); app.unmount(); dom.window.close();
console.log('Settings save isolation, bot drafts/readiness, errors, and percentage regressions passed.');
