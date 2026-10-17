import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
import { speedboxRefreshPurchasedAccount } from '../ui/account-purchase-refresh.mjs';

const manifest = JSON.parse(readFileSync(new URL('../../public/build/manifest.json', import.meta.url)));
const fresh = { id: 11, email: 'demo@owner.com', token: 'new-token', u: 0, d: 0, transfer_enable: 20 * 1073741824,
  expired_at: 1900000000, plan_id: 5, group_id: 3, device_limit: 4, reset_count: 5, banned: 0,
  account_plan_name: 'New tariff', account_group_type: 'volume', account_is_volume: 1, account_tariff_group_id: 7 };
const ref = value => ({ value });
let cases = 0;
for (const role of ['admin', 'representative']) {
  const file = manifest[role === 'admin' ? 'resources/js/pages/admin/Users.vue' : 'resources/js/app.js'].file;
  const source = readFileSync(new URL('../../public/build/' + file, import.meta.url), 'utf8');
  const marker = role === 'admin' ? '/*speedbox-admin-purchase-handlers*/' : '/*speedbox-panel-purchase-handlers*/';
  const start = source.indexOf(marker);
  const stop = source.indexOf(role === 'admin' ? 'return te([O,G]' : 'x1([S2,xe]', start);
  assert.ok(start >= 0 && stop > start, 'Actual shipped purchase handlers are present');
  assert.ok(source.includes(role === 'admin' ? 'b.id=Number(s.id),b.email=' : 'y1.id=Number(v.id),y1.email='), 'Opening the modal retains account id');
  for (const kind of ['renew', 'topup']) {
    const method = role === 'admin' ? kind === 'renew' ? 'es' : 'ts' : kind === 'renew' ? 'ga' : 'iM';
    for (const scenario of ['success', 'post-error', 'read-error', 'wrong-id', 'changed-modal', 'busy']) {
      const modal = { id: 11, email: fresh.email, d: 900, transfer_enable: 10, expired_at: 1, account_tariff_group_id: 7 };
      const lists = [[{ ...modal }], [{ ...modal }]];
      const successes = [], errors = [], posts = [], reads = [];
      let release;
      const pause = new Promise(done => { release = done; });
      const http = {
        post: async (url, body) => { posts.push({ url, body }); if (scenario === 'busy') await pause; if (scenario === 'post-error') throw { response: { data: { message: 'Server rejected purchase' } } }; return { data: {} }; },
        get: async url => {
          reads.push(url);
          if (scenario === 'read-error') throw Error('Offline');
          if (scenario === 'changed-modal') Object.assign(modal, { id: 12, email: 'other@owner.com', d: 77 });
          return { data: { data: scenario === 'wrong-id' ? { ...fresh, id: 12 } : { ...fresh } } };
        },
      };
      const busy = ref(false), period = ref('1m'), gigabytes = ref(3), groups = ref([{ id: 7 }]);
      const context = vm.createContext({ speedboxRefreshPurchasedAccount, Number, Math, console,
        B: http, w0: http, b: modal, y1: modal, G: ref(true), xe: role === 'admin' ? ref(lists[1]) : ref(true),
        Ze: ref(lists[0]), bt: ref(lists[0]), Xo: ref(lists[1]),
        Y: busy, Y2: busy, dt: busy, aM: busy, M: period, Ee: period, V: gigabytes, m2: gigabytes,
        K: groups, M2: groups, $: ref(7), b2: ref(7), ke: ref(false), So: ref(false), J: ref(false), N: ref('time'),
        ze() {}, Po() {}, Kt: token => 'https://test/' + token, bp: token => 'https://test/' + token,
        Jt: async link => 'qr:' + link, Ab: async link => 'qr:' + link,
        ce: message => successes.push(message), N1: message => successes.push(message),
        _: message => errors.push(message), i1: message => errors.push(message), Ya: value => value, Ra: value => value,
        qa: ref(false), lb: ref(false), we: ref(1), rt: ref(1), Qt: String, Ob: String,
        Ke: ref(''), Vn: ref(''), W: ref(true), E2: ref(true), X: ref(true), j2: ref(true), be: ref('topup'), eo: ref('topup'),
      });
      vm.runInContext(source.slice(start, stop) + '\nthis.purchase=' + method + ';', context);
      const first = context.purchase();
      if (scenario === 'busy') { await context.purchase(); assert.equal(posts.length, 1, 'Double click cannot charge twice'); release(); }
      await first;
      assert.equal(posts.length, 1);
      assert.equal(posts[0].url, (role === 'admin' ? '/admin' : '') + '/users/' + (kind === 'renew' ? 'extend' : 'topup'));
      assert.equal(posts[0].body.email, fresh.email);
      assert.equal(busy.value, false);
      if (scenario === 'post-error') {
        assert.equal(reads.length, 0); assert.equal(successes.length, 0); assert.deepEqual(errors, ['Server rejected purchase']);
      } else {
        assert.deepEqual(reads, ['/users/11/details']); assert.equal(successes.length, 1);
        if (['read-error', 'wrong-id'].includes(scenario)) {
          assert.equal(errors.length, 0, 'Read failure adds no toast after a successful purchase'); assert.equal(lists[0][0].d, 900);
        } else {
          assert.equal(errors.length, 0);
          for (const list of lists) for (const [field, value] of Object.entries(fresh)) assert.equal(list[0][field], value, field + ' refreshes in both lists');
          if (scenario === 'changed-modal') { assert.equal(modal.id, 12); assert.equal(modal.d, 77); }
          else {
            for (const [field, value] of Object.entries(fresh)) assert.equal(modal[field], value, field + ' refreshes in modal');
            assert.equal(modal.qrDataUrl, 'qr:https://test/new-token');
          }
        }
      }
      cases++;
    }
  }
}
console.log('Actual admin and representative renewal/topup handlers: ' + cases + ' success, failure, double click and stale modal scenarios passed.');
