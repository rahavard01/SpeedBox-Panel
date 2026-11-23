import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import vm from 'node:vm'
import { effectScope, ref, watch } from 'vue'
import { invoiceDateBounds, invoiceDateKey } from '../../resources/js/utils/invoice-date-range.js'
import { invoiceDateOptions, useInvoiceDateRange } from '../../resources/js/composables/useInvoiceDateRange.js'

const at = new Date('2026-11-23T20:30:00Z')
assert.equal(invoiceDateKey(at), '2026-11-24', 'Tehran midnight must not depend on browser timezone')
assert.equal(invoiceDateKey('2026-11-23 23:59:59'), '2026-11-23', 'SQL dates are already local to the panel')
assert.equal(invoiceDateKey('2026-11-23T20:29:59.999Z'), '2026-11-23')
assert.equal(invoiceDateKey('2026-11-24T00:00:00+03:30'), '2026-11-24')
assert.equal(invoiceDateKey('invalid'), '')
assert.equal(invoiceDateKey(null), '')
assert.deepEqual(invoiceDateBounds('last7days', '2026-01-02'), { start: '2025-12-27', end: '2026-01-02' })
assert.deepEqual(invoiceDateBounds('last30days', '2024-03-01'), { start: '2024-02-01', end: '2024-03-01' })
assert.equal(invoiceDateBounds('custom', '2026-11-24', { start: '', end: '2026-11-23' }), null)
assert.equal(invoiceDateBounds('custom', '2026-11-24', { start: '2026-11-24', end: '2026-11-23' }), null)
assert.deepEqual(invoiceDateOptions.map(option => option.value), ['today', 'last7days', 'last30days', 'custom'])

const scope = effectScope()
scope.run(() => {
  const poll = ref(at.getTime())
  const { range, dates, bounds } = useInvoiceDateRange(poll)
  assert.equal(bounds.value, null)
  range.value = 'today'
  assert.deepEqual(bounds.value, { start: '2026-11-24', end: '2026-11-24' })
  let changes = 0
  watch(bounds, () => changes++, { flush: 'sync' })
  poll.value += 60_000
  assert.equal(changes, 0, 'polling on the same day keeps the active page')
  range.value = 'custom'
  dates.value = { start: '2026-11-22', end: '' }
  assert.deepEqual(bounds.value, { start: '2026-11-24', end: '2026-11-24' }, 'keep the applied range while the dates are incomplete')
  dates.value = { start: '2026-11-22', end: '2026-11-22' }
  assert.deepEqual(bounds.value, { start: '2026-11-22', end: '2026-11-22' })
  const timestamps = ['2026-11-21 23:59:59', '2026-11-22 00:00:00', '2026-11-22 23:59:59', '2026-11-23 00:00:00']
  const rows = timestamps.filter(value => {
    const key = invoiceDateKey(value)
    return key >= bounds.value.start && key <= bounds.value.end
  })
  assert.deepEqual(rows, timestamps.slice(1, 3), 'include both ends of a same-day range')
  dates.value = { start: '2026-11-23', end: '2026-11-22' }
  assert.equal(bounds.value.start, '2026-11-22', 'reversed inputs never replace a valid range')
  range.value = ''
  assert.equal(bounds.value, null)
  range.value = 'today'
  poll.value += 86_400_000
  assert.equal(bounds.value.end, '2026-11-25', 'an open invoice page rolls forward at Tehran midnight')
})
scope.stop()

const buildPath = relative => new URL('../../' + relative, import.meta.url)
const read = relative => readFileSync(buildPath(relative), 'utf8')
const browser = { window: {} }
vm.runInNewContext(read('public/speedbox/jalali-calendar.js'), browser)
const { monthDays, persianParts } = browser.window.SpeedboxJalaliCalendar
const yearStart = persianParts(new Date('2025-03-21T12:00:00Z'))
const days = monthDays(yearStart.year, 1).filter(Boolean)
assert.equal(days[0].iso, '2025-03-21')
assert.equal(days.length, 31)
assert.equal(days.at(-1).iso, '2025-04-20')
const view = read('resources/views/app.blade.php')
assert.ok(view.indexOf('/speedbox/jalali-calendar.js') < view.indexOf('/speedbox/infrastructure.js'))
const rank = read('public/speedbox/infrastructure.js')
assert.match(rank, /monthDays\(year,month\)/)
assert.match(read('resources/js/components/InvoiceJalaliDatePicker.vue'), /monthDays\(year\.value, month\.value\)/)
for (const page of ['resources/js/pages/Billing.vue', 'resources/js/pages/admin/Invoices.vue']) {
  const source = read(page)
  assert.ok(source.indexOf('if (bounds)') < source.indexOf('if (!query)'))
  assert.match(source, /watch\(\[searchQuery, transactionType, dateBounds\]/)
  assert.match(source, /<InvoiceDateRange v-if="transactionDateRange === 'custom'"/)
  const toolbar = source.indexOf('class="table-toolbar invoice-billing-toolbar"')
  const dates = source.indexOf('<InvoiceDateRange v-if=', toolbar)
  const filters = source.slice(toolbar, dates)
  assert.equal((filters.match(/<div\b/g) || []).length + 1, (filters.match(/<\/div>/g) || []).length, 'the custom range is outside the flex toolbar')
  assert.match(source, /flex-direction:column !important;\s*flex-wrap:nowrap;/)
}
const dateRow = read('resources/js/components/InvoiceDateRange.vue')
assert.match(dateRow, /\.invoice-billing-toolbar \+ \.invoice-date-range \{[^}]*width: 90%/)
assert.match(read('resources/js/components/InvoiceFilterSelect.vue'), /<NativeSelect[^>]+:allow-empty="allowEmpty"/)
const css = read('public/speedbox/infrastructure.css')
assert.match(css, /\.rank-dates\{display:grid;grid-template-columns:repeat\(2,minmax\(0,1fr\)\)/)
assert.match(css, /max-width:480px[^\n]+\.rank-dates\{grid-template-columns:1fr/)
console.log('PASS: Tehran date boundaries, date filtering, the shared Jalali calendar and both invoice layouts.')
