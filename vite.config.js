import { defineConfig } from 'vite'
import laravel from 'laravel-vite-plugin'
import vue from '@vitejs/plugin-vue'
import { readFileSync } from 'node:fs'
const pkg = JSON.parse(readFileSync(new URL('./package.json', import.meta.url), 'utf8'))
// Stable scope IDs keep the existing shared CSS valid in development and production.
const scopes = {
  "resources/js/App.vue": "760a8046",
  "resources/js/pages/TopUp.vue": "984bc166",
  "resources/js/pages/TelegramSetup.vue": "e83b2fce",
  "resources/js/pages/Admin.vue": "7e393bb8",
  "resources/js/pages/Billing.vue": "8724857f",
  "resources/js/pages/ForgotPassword.vue": "1e1b76fa",
  "resources/js/pages/_Blank.vue": "e37e3d22",
  "resources/js/pages/Tickets.vue": "a4291483",
  "resources/js/pages/Login.vue": "9e8bb84e",
  "resources/js/pages/Tutorials.vue": "f387832b",
  "resources/js/pages/Announcements.vue": "5daea513",
  "resources/js/pages/CoopAgents.vue": "f834d3a7",
  "resources/js/pages/Panel.vue": "a8b4047a",
  "resources/js/pages/ResetPassword.vue": "ffe683ee",
  "resources/js/pages/Dashboard.vue": "b1d23287",
  "resources/js/components/UiTooltip.vue": "2ce08fa2",
  "resources/js/components/ToastHost.vue": "7bfe2fe5",
  "resources/js/components/UserBotSmsDeviceManager.vue": "19a63443",
  "resources/js/components/TariffOrderControls.vue": "a99c93db",
  "resources/js/components/MobileCardTable.vue": "ad27355a",
  "resources/js/components/InvoiceFilterSelect.vue": "f8294ed3",
  "resources/js/pages/admin/Users.vue": "d5ef1c17",
  "resources/js/pages/admin/Settings.vue": "f4269ded",
  "resources/js/pages/admin/Invoices.vue": "6bc44f2d",
  "resources/js/pages/admin/Deposits.vue": "056a7782",
  "resources/js/pages/admin/Tickets.vue": "4c887c43",
  "resources/js/pages/admin/Partners.vue": "e47768ba",
  "resources/js/pages/admin/Tutorials.vue": "dfe9139a",
  "resources/js/pages/admin/Announcements.vue": "92274f2b",
  "resources/js/pages/admin/Dashboard.vue": "9d65eacc",
  "resources/js/pages/admin/Requests.vue": "1caf48dc",
}

export default defineConfig({
  plugins: [
    laravel({
      input: ['resources/js/app.js'],
      buildDirectory: 'build',      // خروجی همچنان public/build
      hotFile: 'public/hot',
      refresh: true,
    }),
    vue({ features: { componentIdGenerator: (file, source, production, hash) => scopes[file] || hash(file) } }),
  ],
  define: {
    __APP_VERSION__: JSON.stringify(pkg.version),
    __BUILD_TIME__: JSON.stringify(new Date().toISOString()),
    __PANEL_RELEASE__: JSON.stringify(pkg.panelRelease || {}),
  },
  build: { emptyOutDir: false },
  server: {
    host: '127.0.0.1',
    port: 5173,
    strictPort: true,
    origin: 'http://127.0.0.1:5173',
    hmr: { host: '127.0.0.1', port: 5173 },
    cors: true,
  },
})
