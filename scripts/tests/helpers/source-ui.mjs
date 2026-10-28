// Compile the maintained Vue components with the production Vite/Vue config.
// Test-only exports avoid depending on names chosen by the minifier. No files
// are written to public/build and no application/API code is substituted.
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';
import { build, loadConfigFromFile } from 'vite';

export const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
export const read = file => readFileSync(path.join(root, file), 'utf8');
let bundle;
async function compiled() {
  if (bundle) return bundle;
  const loaded = await loadConfigFromFile({ command: 'build', mode: 'production' }, path.join(root, 'vite.config.js'));
  const components = {
    Users: 'pages/admin/Users.vue', Settings: 'pages/admin/Settings.vue', Partners: 'pages/admin/Partners.vue',
    AdminDashboard: 'pages/admin/Dashboard.vue', Dashboard: 'pages/Dashboard.vue', Panel: 'pages/Panel.vue',
    Billing: 'pages/Billing.vue', Invoices: 'pages/admin/Invoices.vue', Requests: 'pages/admin/Requests.vue',
    Tickets: 'pages/Tickets.vue', Announcements: 'pages/Announcements.vue', Tutorials: 'pages/Tutorials.vue',
    AdminAnnouncements: 'pages/admin/Announcements.vue', AdminTutorials: 'pages/admin/Tutorials.vue',
    UserAccountActions: 'components/UserAccountActions.vue', InvoiceFilterSelect: 'components/InvoiceFilterSelect.vue',
    AccountPlanSelect: 'components/AccountPlanSelect.vue', GlobalBotFaqs: 'components/GlobalBotFaqs.vue',
  };
  const entry = [
    "export * as Vue from 'vue';",
    "export { default as http } from '@/lib/http';",
    "export { default as router } from '@/router';",
    "export * from '@/composables/useToast';",
    "export * from '@/utils/commonUtils';",
    "export * from '@/speedbox/partner-accounts';",
    "export * from '@/speedbox/global-bot-faqs';",
    ...Object.entries(components).map(([name, file]) => `export { default as ${name} } from '@/` + file + "';"),
  ].join('\n');
  const exposes = {
    'pages/admin/Settings.vue': 'form,saveAll,toggleUserBotSetting,toggleUserBotCryptoOption',
    'pages/Panel.vue': 'bootstrapAnnouncements,ticketUnreadCount,totalHeaderBadge,speedboxRefreshPartnerNotices,users,tokenHit,viewUserData,showViewUser,extendChoice,extendBusy,topupGB,topupBusy,tariffPlanGroups,selectedTariffGroupId,submitExtend,submitTopup',
    'pages/admin/Users.vue': 'users,tokenHit,viewUserData,showViewUser,extendChoice,extendBusy,topupGB,topupBusy,tariffPlanGroups,selectedTariffGroupId,submitExtend,submitTopup',
  };
  const fixture = {
    name: 'source-ui-test-fixture', enforce: 'pre',
    resolveId: id => id === 'speedbox:test-ui' ? '\0speedbox:test-ui' : undefined,
    load: id => id === '\0speedbox:test-ui' ? entry : undefined,
    transform(source, id) {
      if (id.includes('?')) return;
      const relative = path.relative(path.join(root, 'resources/js'), id).replaceAll(path.sep, '/');
      if (exposes[relative]) return source.replace('</script>', `\ndefineExpose({${exposes[relative]}})\n</script>`);
    },
  };
  const result = await build({ ...loaded.config, configFile: false, root, logLevel: 'error',
    plugins: [fixture, loaded.config.plugins[1]],
    resolve: { alias: { '@': path.join(root, 'resources/js') } },
    build: { write: false, minify: false, cssMinify: false, cssCodeSplit: false, manifest: false,
      rollupOptions: { input: 'speedbox:test-ui', preserveEntrySignatures: 'strict', output: { format: 'es', inlineDynamicImports: true } } },
  });
  const output = (Array.isArray(result) ? result[0] : result).output;
  bundle = { code: output.find(file => file.type === 'chunk' && file.isEntry).code,
    css: output.filter(file => file.type === 'asset' && file.fileName.endsWith('.css')).map(file => file.source).join('\n') };
  return bundle;
}

export async function loadSourceUi(dom) {
  const source = await compiled();
  const module = new vm.SourceTextModule(source.code, { context: dom.getInternalVMContext(),
    initializeImportMeta: meta => { meta.url = 'http://fixture.test/source-ui.js'; } });
  await module.link(() => { throw Error('Unexpected external fixture import'); });
  await module.evaluate();
  const ui = module.namespace;
  dom.window.__SPEEDBOX_EXTEND_PANEL__?.({ router: ui.router, element: ui.Vue.createElementVNode,
    onMounted: ui.Vue.onMounted, onUnmounted: ui.Vue.onBeforeUnmount, watch: ui.Vue.watch,
    ref: ui.Vue.ref, nextTick: ui.Vue.nextTick, notifications: ui.useToast });
  return { ...module.namespace, css: source.css };
}
