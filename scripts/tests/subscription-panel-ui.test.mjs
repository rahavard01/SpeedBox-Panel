import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
import { parse } from '@babel/parser';
import { speedboxRefreshPurchasedAccount } from '../../resources/js/speedbox/account-purchase-refresh.js';

let checks = 0;
function equal(actual, expected, message) { assert.equal(actual, expected, message); checks++; }
function ok(value, message) { assert.ok(value, message); checks++; }
const ref = value => ({ value });
const paths = ['../../resources/js/pages/Panel.vue', '../../resources/js/pages/admin/Users.vue'];
for (const path of paths) {
  const source = readFileSync(new URL(path, import.meta.url), 'utf8');
  const script = source.match(/<script setup>([\s\S]*?)<\/script>/)[1];
  const ast = parse(script, { sourceType: 'module' });
  const fn = name => {
    const node = ast.program.body.find(node => node.type === 'FunctionDeclaration' && node.id.name === name);
    assert.ok(node, `Actual ${name} handler exists in ${path}`);
    return script.slice(node.start, node.end);
  };
  const admin = path.includes('/admin/');
  const refreshName = admin ? 'speedboxRefreshAdminPurchase' : 'speedboxRefreshPanelPurchase';
  const copied = [], encoded = [], errors = [], requests = [];
  const modal = {};
  const ctx = vm.createContext({
    viewUserData: modal, createResult: {}, clear() {}, loadPlanEnables: async () => {},
    viewMode: ref('result'), extendChoice: ref(''), extendBusy: ref(false), showViewUser: ref(false),
    viewAction: ref(''), viewBanChecked: ref(false), viewIsVolumeAccount: ref(false),
    tariffPlanGroups: ref([]), volumeTariffGroups: ref([]), timeTariffGroups: ref([]),
    selectedTariffGroupId: ref(0), createPlanKind: ref('time'), applyActiveTariffGroup() {},
    users: ref([]), tokenHit: ref([]),
    toastError: message => errors.push(message), toastSuccess() {},
    navigator: { clipboard: { writeText: async value => { copied.push(value); } } },
    QRCode: { toDataURL: async value => { encoded.push(value); return 'qr:' + value; } },
    speedboxRefreshPurchasedAccount,
    http: { get: async (url, options) => { requests.push({ url, options }); return ctx.response; } },
    speedboxCurrentPurchase: target => ctx.showViewUser.value && Number(modal.id) === Number(target.id),
  });
  vm.runInContext(['accountSubscribeUrl', 'buildQr', 'copyInviteLink', 'viewUser', refreshName].map(fn).join('\n'), ctx);
  ok(!script.includes('/settings/link-parts') && !script.includes('buildInviteUrl'), 'No browser reconstruction or base-URL hook');
  ok(fn('submitCreate').includes('const invite = accountSubscribeUrl(createdItem)'), 'New-account display also uses the returned complete link');
  const row = { id: 2, email: 'account@owner.com', token: 'b'.repeat(32) };
  for (const link of [
    'https://sub.example/custom/sub/' + row.token,
    'https://sub.example/s/' + row.token + '?flag=shadowrocket&raw=1#profile',
    'https://gateway.example/import?token=' + row.token + '&sig=a%2Fb%3D#نام',
  ]) {
    ctx.response = { data: { data: [{ ...row, subscribe_url: link }] } };
    const before = requests.length;
    await ctx.viewUser(row);
    equal(requests.length, before + 1, 'Viewing an account only requests its authorized by-email read');
    equal(requests.at(-1).url, '/users/by-email', 'Actual lookup route');
    equal(requests.at(-1).options.params.email, row.email, 'Actual account is requested');
    equal(modal.inviteLink, link, 'Displayed URL preserves full server output');
    equal(encoded.at(-1), link, 'QR receives the displayed URL unchanged');
    equal(modal.qrDataUrl, 'qr:' + link, 'Displayed QR belongs to that URL');
    await ctx.copyInviteLink(modal.inviteLink);
    equal(copied.at(-1), link, 'Clipboard exactly matches display and QR');
    const nextLink = link + (link.includes('#') ? '-renewed' : '?renewed=1');
    ctx.response = { data: { data: { ...row, subscribe_url: nextLink } } };
    ctx.users.value = [{ ...row }];
    ctx.tokenHit.value = [{ ...row }];
    equal(await ctx[refreshName](row), true, 'Successful renewal/topup read refreshes the modal');
    equal(modal.inviteLink, nextLink, 'Refresh uses new complete server URL');
    equal(modal.qrDataUrl, 'qr:' + nextLink, 'Refresh QR matches the new displayed URL');
    equal(ctx.users.value[0].subscribe_url, nextLink, 'List receives new complete URL');
  }
  const beforeQr = encoded.length;
  ctx.response = { data: { data: [{ ...row }] } };
  await ctx.viewUser(row);
  equal(modal.inviteLink, '', 'Missing server link is never fabricated from a token');
  equal(modal.qrDataUrl, '', 'Missing URL does not leave an older QR');
  equal(encoded.length, beforeQr, 'No QR encoding for a missing URL');
  let release;
  ctx.http.get = async () => new Promise(resolve => { release = resolve; });
  const pending = ctx.viewUser(row);
  await new Promise(resolve => setImmediate(resolve));
  modal.id = 3; modal.email = 'other@owner.com'; modal.inviteLink = 'other-link'; modal.qrDataUrl = 'other-qr';
  release({ data: { data: [{ ...row, subscribe_url: 'older-link' }] } });
  await pending;
  equal(modal.inviteLink, 'other-link', 'An older account read cannot replace the current account link');
  equal(modal.qrDataUrl, 'other-qr', 'An older account read cannot replace its QR');
  ctx.http.get = async () => ({ data: { data: [{ ...row, subscribe_url: 'pending-qr' }] } });
  ctx.QRCode.toDataURL = async () => new Promise(resolve => { release = resolve; });
  const pendingQr = ctx.viewUser(row);
  await new Promise(resolve => setImmediate(resolve));
  ctx.showViewUser.value = false; modal.qrDataUrl = 'closed-qr';
  release('old-qr'); await pendingQr;
  equal(modal.qrDataUrl, 'closed-qr', 'Late QR result cannot update a closed modal');
  equal(errors.length, 0, 'Normal view/refresh scenarios do not show errors');
}
console.log(`SUBSCRIPTION PANEL UI OK: ${checks} checks passed using the actual Vue handlers.`);
