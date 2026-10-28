// Share one test-only Vite compilation across the restored component suites.
for (const name of [
  'account-purchase-refresh', 'partner-accounts-dom', 'settings-saves',
  'ticket-notifications-ui', 'final-polish-ui', 'review-fixes-ui',
  'stage5-ui', 'tooltip-faq-polish',
]) await import(new URL('./' + name + '.test.mjs', import.meta.url));
console.log('PASS: all eight Vue source component regression suites');
