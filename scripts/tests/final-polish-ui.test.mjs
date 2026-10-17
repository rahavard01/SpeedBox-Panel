// Actual shipped Vue runtime and infrastructure UI, with delayed network responses.
// node --experimental-vm-modules scripts/tests/final-polish-ui.test.mjs
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
const { JSDOM } = await import(process.env.PARTNER_TEST_JSDOM || 'jsdom');
const read = path => readFileSync(new URL('../../' + path, import.meta.url), 'utf8');
const manifest = JSON.parse(read('public/build/manifest.json'));
const source = read('public/build/' + manifest['resources/js/app.js'].file)
  .replace('Cs.mount("#app")', 'void 0').replace('Cs.use(us)', 'void 0')
  + '\nexport{ac as testApp,k9 as testFilter,b1 as testVNode,dl as testRemaining};';
const dom = new JSDOM('<div class="top-bar"><button class="hamburger-menu"></button><div id="fixture"></div></div>',
  { url: 'http://fixture.test/', runScripts: 'outside-only', pretendToBeVisual: true });
const win = dom.window;
const listeners = new Set();
const media = { matches: false, addEventListener: (_, fn) => listeners.add(fn), removeEventListener: (_, fn) => listeners.delete(fn) };
win.matchMedia = () => media; win.ResizeObserver = class { observe() {} disconnect() {} };
win.HTMLElement.prototype.getBoundingClientRect = function () {
  if (this.classList.contains('top-bar')) return {top:120,right:400,left:0,height:50,width:400};
  if (this.classList.contains('hamburger-menu')) return {right:360,left:340,top:120,height:30,width:20};
  if (this.classList.contains('speedbox-panel-switch__trigger')) return {height:media.matches?30:28,width:160};
  return {top:0,left:0,right:0,height:0,width:0};
};
win.fetch = async () => ({ json: async () => ({}) }); win.AbortController = AbortController;
win.eval(read('public/speedbox/register-infrastructure.js'));
const module = new vm.SourceTextModule(source, { context: dom.getInternalVMContext(), initializeImportMeta: meta => { meta.url = 'http://fixture.test/main.js'; } });
await module.link(() => { throw Error('Unexpected import'); }); await module.evaluate();
const userPage = read('public/build/' + manifest['resources/js/pages/admin/Users.vue'].file);
const helperName = userPage.match(/import SpeedboxAccountActions from '\.\/([^']+)'/)[1];
const helperSource = read('public/build/assets/' + helperName);
const mainName = manifest['resources/js/app.js'].file.split('/').at(-1);
assert.ok(![...helperSource.matchAll(/from["']\.\/(app-speedbox-[^"']+)["']/g)].some(match => match[1] !== mainName), 'Active account helpers must share the current Vue runtime');
const picker = new vm.SourceTextModule(read('public/build/assets/SpeedboxDatePicker-a1.js'), { context: dom.getInternalVMContext() });
await picker.link(() => module);
const helperFilterName = helperSource.match(/(\w+)=\{__name:"InvoiceFilterSelect"/)[1];
const helper = new vm.SourceTextModule(helperSource + '\nexport{' + helperFilterName + ' as testHelperFilter};', { context: dom.getInternalVMContext(), initializeImportMeta: meta => { meta.url = 'http://fixture.test/' + helperName; } });
await helper.link(specifier => specifier.endsWith('SpeedboxDatePicker-a1.js') ? picker : module); await helper.evaluate();
const { testApp, testFilter, testVNode: h, testRemaining, a1: ref, R: nextTick } = module.namespace;
const flush = async () => { await Promise.resolve(); await nextTick(); await Promise.resolve(); await nextTick(); };
const mobile = async value => { media.matches = value; for (const listener of listeners) listener(); await flush(); };
let planValue, filterValue, modeValue, helperValue;
const disabled = ref(false);
const app = testApp({ setup() { return () => h('div', null, [
  h(windowComponent('__SPEEDBOX_TARIFF_PLAN_SELECT__'), { inputId: 'plan', modelValue: 2, options: [{ id: 1, label: 'First' }, { id: 2, label: 'Second' }], disabled: disabled.value, 'onUpdate:modelValue': value => { planValue = value; } }),
  h(testFilter, { modelValue: 'paid', placeholder: 'Status', options: [{ value: '', label: 'All' }, { value: 'paid', label: 'Paid' }, { value: 'failed', label: 'Failed', disabled: true }], 'onUpdate:modelValue': value => { filterValue = value; } }),
  h(helper.namespace.testHelperFilter, { modelValue: 'a', placeholder: 'Operation', options: ['a','b'], 'onUpdate:modelValue': value => { helperValue = value; } }),
  h(windowComponent('__SPEEDBOX_PANEL_SWITCH__'), { serverMode: false, navigate: value => { modeValue = value; } })
]); } });
function windowComponent(name) { return win[name]; }
app.mount('#fixture'); await flush();
assert.equal(win.document.querySelector('.speedbox-panel-switch').style.top,'46px');
assert.equal(win.document.querySelectorAll('select').length, 0, 'Desktop retains custom dropdowns');
win.document.querySelector('#plan').click(); await flush();
assert.equal(win.document.querySelectorAll('#fixture [role="option"]').length, 2);
await mobile(true);
win.dispatchEvent(new win.Event('resize'));
assert.equal(win.document.querySelector('.speedbox-panel-switch').style.top,'45px','Mode switch is vertically centered again after its mobile size changes');
assert.equal(win.document.querySelectorAll('select').length, 4, 'Tariffs, both filter implementations and mode switch use native controls on mobile');
const plan = win.document.querySelector('#plan'); assert.equal(plan.tagName, 'SELECT'); assert.equal(plan.value, '2');
assert.ok(plan.classList.contains('speedbox-native-select'));
assert.equal(plan.parentElement.querySelector('.invoice-custom-select__trigger span').textContent, 'Second', 'Native selection retains the original visible surface');
plan.value = '1'; plan.dispatchEvent(new win.Event('change', { bubbles: true })); await flush(); assert.equal(planValue, 1, 'Numeric model IDs are preserved');
disabled.value = true; await flush(); assert.equal(plan.disabled, true);
const filter = win.document.querySelector('select[aria-label="Status"]'); assert.equal(filter.value, 'paid');
assert.equal(filter.options.length, 3, 'Existing empty option is not duplicated'); assert.equal(filter.options[2].disabled, true);
filter.value = ''; filter.dispatchEvent(new win.Event('change', { bubbles: true })); await flush(); assert.equal(filterValue, '');
const operation = win.document.querySelector('select[aria-label="Operation"]');
operation.value = 'b'; operation.dispatchEvent(new win.Event('change', { bubbles: true })); await flush(); assert.equal(helperValue, 'b');
const panel = win.document.querySelector('select[aria-label="انتخاب پنل"]'); assert.ok(panel,win.document.body.innerHTML);
assert.ok(panel.classList.contains('speedbox-panel-native'));
assert.equal(panel.options[0].textContent, 'پنل مدیریت اصلی');
const key = new win.KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true, cancelable: true }); panel.dispatchEvent(key);
assert.equal(key.defaultPrevented, false, 'Native keyboard selection is not intercepted by custom menu');
panel.value = 'servers'; panel.dispatchEvent(new win.Event('change', { bubbles: true })); await flush(); assert.equal(modeValue, true);
await mobile(false); assert.equal(win.document.querySelectorAll('select').length, 0, 'Resizing back restores desktop controls');
assert.equal(testRemaining(200000 + 3 * 86400, 200000), '۳ روز باقی مانده');
assert.equal(testRemaining(200000 - 2 * 3600, 200000), '۲ ساعت قبل منقضی شده');
app.unmount(); assert.equal(listeners.size, 0, 'Media listeners are cleaned up');

// Mount the actual compiled Partners page: optimized child arrays reject a raw
// null in a conditional code-lock note. Exercise create/close twice in both modes.
const partners = new vm.SourceTextModule(read('public/build/' + manifest['resources/js/pages/admin/Partners.vue'].file), { context: dom.getInternalVMContext() });
await partners.link(() => module); await partners.evaluate();
module.namespace.v.get = async () => ({ data: { data: [], groups: [], volume_groups: [] } });
win.scrollTo = () => {};
for (const isMobile of [false, true]) {
  await mobile(isMobile);
  const errors = [], page = testApp(partners.namespace.default);
  page.config.errorHandler = error => errors.push(error);
  page.mount('#fixture'); await flush();
  for (let attempt = 0; attempt < 2; attempt++) {
    [...win.document.querySelectorAll('button')].find(button => button.textContent.includes('نماینده جدید')).click(); await flush();
    assert.ok(win.document.querySelector('.create-modal #cr-name'), 'New representative form opens');
    assert.equal(win.document.querySelector('#cr-role').tagName, isMobile ? 'SELECT' : 'BUTTON');
    if (isMobile) {
      const role = win.document.querySelector('#cr-role'); role.value = '2'; role.dispatchEvent(new win.Event('change', { bubbles: true })); await flush();
      assert.equal(role.parentElement.querySelector('button span').textContent, 'کاربر');
    }
    [...win.document.querySelectorAll('.create-modal a')].find(link => link.textContent === 'بازگشت').click(); await flush();
    assert.equal(win.document.querySelector('.create-modal'), null);
  }
  assert.deepEqual(errors, [], 'Representative modal mounts and updates without Vue render errors');
  page.unmount(); assert.equal(listeners.size, 0);
}
dom.window.close();

const template = read('resources/views/infrastructure.blade.php').match(/<template[^>]*>([\s\S]*)<\/template>/)[1];
for (const view of ['overview','servers','nodes','plans','routing']) {
  const fixture = new JSDOM('<div id="root">' + template + '</div>', { url: 'http://fixture.test/', runScripts: 'outside-only', pretendToBeVisual: true });
  const window = fixture.window; const pending = [];
  let isMobile = true;
  const changes = new Set();
  window.matchMedia = () => ({ get matches() { return isMobile; }, addEventListener: (_, fn) => changes.add(fn), removeEventListener: (_, fn) => changes.delete(fn) });
  window.AbortController = AbortController;
  window.localStorage.setItem('token', 'test'); window.localStorage.setItem('user', '{"role":1}');
  window.fetch = url => new Promise(resolve => pending.push({ url, resolve }));
  window.SpeedboxNodeEditor = { protocolBadge: () => '', mount: () => () => {} };
  window.eval(read('public/speedbox/infrastructure.js'));
  const notices = [];
  const ui = window.SpeedboxInfrastructure.mount(window.document.querySelector('#root'), { view, router: { replace: () => {} }, notify: { error: message => notices.push(message), notif: () => {} } });
  const content = window.document.querySelector('#content');
  if (view === 'overview') {
    assert.equal(content.querySelectorAll('.stat-card').length, 4);
    assert.ok([...content.querySelectorAll('.stat-value')].every(node => /^۰(?: \/ ۰)?$/.test(node.textContent)), 'Statistics render zeros before network resolves');
    assert.equal(content.querySelectorAll('.queue-grid .panel-card').length, 2);
    assert.equal(content.querySelectorAll('.rank-list').length, 2);
  } else if (view === 'routing') assert.equal(content.querySelectorAll('h2').length, 2);
  else {
    assert.ok(content.querySelector('thead th'), view + ' preserves table headings while loading');
    assert.ok(content.querySelector('tbody td').textContent.includes('در حال'));
  }
  await new Promise(resolve => setTimeout(resolve, 0));
  if (view === 'overview') {
    const select = content.querySelector('select'); assert.equal(select.getAttribute('aria-hidden'), null); assert.equal(select.tabIndex, 0);
    isMobile = false; for (const change of changes) change();
    assert.equal(select.getAttribute('aria-hidden'), 'true'); assert.equal(select.tabIndex, -1);
    isMobile = true; for (const change of changes) change(); assert.equal(select.getAttribute('aria-hidden'), null);
  }
  if (['overview','servers','plans'].includes(view)) {
    for (const request of pending.splice(0)) request.resolve({ ok: true, json: async () => ({ data: request.url.endsWith('/overview') ? { managed_servers:0,online_machines:0,machines:0,online_nodes:0,nodes:0,active_users:0 } : [] }) });
    await new Promise(resolve => setTimeout(resolve, 0));
    if (view === 'overview') {
      const job = { job_id:'sample-1',name:'App\\Jobs\\DeployManagedServer',queue:'deployments',status:'succeeded',attempts:1,started_at:'2026-10-11 00:00:00',finished_at:'2026-10-11 00:00:05',duration:5,exception_class:null };
      for (const request of pending.splice(0)) request.resolve({ ok:true,json:async()=>({data:request.url.endsWith('/queue')?{status:'running',wait:5,recentJobs:1,jobsPerMinute:1,peakJobsPerMinute:1,failedJobs:0,processes:1,workload:[{name:'default',pending:0,running:0,delayed:0}],active_queues:['default','deployments'],queueWithMaxRuntime:{runtime:5,name:'deployments'},recent:[job]}:[],period:{}}) });
      await new Promise(resolve => setTimeout(resolve, 0));
      const wait = content.querySelector('.queue-duration');
      assert.equal(wait.dir,'rtl'); assert.equal(wait.firstElementChild.textContent,'۵'); assert.equal(wait.lastElementChild.textContent,'ثانیه');
      content.querySelector('[data-queue-action="recent"]').click();
      window.document.querySelector('[data-job-detail]').click();
      assert.equal(window.document.querySelectorAll('.job-detail-grid>div').length,8);
      assert.equal(window.document.querySelector('.job-error-detail'),null);
      assert.ok(window.document.querySelector('.infrastructure-modal.job-modal'));
      assert.equal(content.querySelector('.queue-workload'),null);
      assert.equal(content.querySelector('.queue-active-names'),null);
      assert.ok(content.querySelector('.queue-run-status.is-running svg'));
      assert.ok(window.document.querySelector('.job-detail-grid').textContent.includes('۵ ثانیه'));
      job.exception_class='RuntimeException';
      content.querySelector('[data-queue-action="recent"]').click();
      window.document.querySelector('[data-job-detail]').click();
      assert.equal(window.document.querySelectorAll('.job-detail-grid>div').length,9);
      assert.equal(window.document.querySelector('.job-error-detail b').textContent,'RuntimeException');
    } else {
      content.querySelector(view === 'servers' ? '#addServer' : '#addPlan').click();
      await new Promise(resolve => setTimeout(resolve,0));
      const select = window.document.querySelector(view === 'servers' ? '#authType' : '#planGroup');
      assert.equal(select.parentElement.querySelectorAll('.invoice-custom-select__trigger').length,1);
      assert.equal(select.getAttribute('aria-hidden'),null);
      assert.equal(select.parentElement.querySelector('button').getAttribute('aria-hidden'),'true');
      if (view === 'servers') {
        select.value = 'key'; select.dispatchEvent(new window.Event('change',{bubbles:true}));
        assert.equal(select.parentElement.querySelector('button span').textContent,'کلید خصوصی');
      }
    }
    // The coloured protocol trigger stays the only visible surface; its original
    // select supplies mobile selection and changes the colour/label in place.
    const picker = window.document.createElement('div'); picker.className='node-protocol-head';
    picker.innerHTML='<select data-protocol-picker><option value="vless" data-color="#4875ed">VLESS</option><option value="trojan" data-color="#ffca28">Trojan</option></select>';
    content.append(picker); await new Promise(resolve => setTimeout(resolve,0));
    const protocol = picker.querySelector('select'), wrap = protocol.parentElement;
    assert.ok(wrap.classList.contains('protocol-select'));
    protocol.value='trojan';protocol.dispatchEvent(new window.Event('change',{bubbles:true}));
    assert.equal(wrap.querySelector('button span').textContent,'Trojan');
    assert.equal(wrap.style.getPropertyValue('--protocol-color'),'#ffca28');
    assert.equal(wrap.querySelectorAll('select').length,1);
  }
  ui.dispose();
  for (const item of pending) item.resolve({ ok: true, status: 200, json: async () => ({ data: [] }) });
  await new Promise(resolve => setTimeout(resolve, 0)); assert.equal(notices.length, 0, 'Disposed requests cause no late error notices');
  assert.equal(changes.size, 0); fixture.window.close();
}
console.log('Actual Vue mobile/desktop native selectors, bindings, keyboard behavior, relative dates, loading shells and request cleanup passed.');
