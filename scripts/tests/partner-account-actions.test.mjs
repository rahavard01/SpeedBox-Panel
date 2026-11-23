import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import vm from 'node:vm'
import { createElementVNode as vueElement } from 'vue'
import { accountSecretWarning } from '../../resources/js/speedbox/account-action-copy.js'

const source = readFileSync(new URL('../../resources/js/speedbox/partner-accounts.js', import.meta.url), 'utf8')
  .replace(/^import .*\n/gm, '').replace('export function ', 'function ')
const vnode = (type, props, children) => ({ type, props, children })
const requests = []
const notifications = []
const row = { id: 15, email: 'x7p2@ali.com' }
let renamed, transactions
const data = { id: row.id, email: row.email, username: 'x7p2', suffix: '-ali', can_rename: true, service_type: 'v2ray' }
const context = vm.createContext({
  ref: value => ({ value }), watch() {}, onMounted() {}, onBeforeUnmount() {}, nextTick: fn => Promise.resolve(fn?.()),
  accountSecretWarning,
  openBlock() {}, createElementBlock: vnode, createElementVNode: vnode, createBlock: vnode, Teleport: 'teleport',
  document: { body: { style: { overflow: '' } } }, window: { innerWidth: 375, innerHeight: 800 },
  formatUsername: email => email.replace('@', '-').replace(/\.com$/, ''),
  http: {
    async get(url) { requests.push(url); return { data: { data } } },
    async patch(url, payload) {
      requests.push([url, payload.username])
      return { data: { data: { ...data, email: payload.username + '@ali.com' }, message: 'saved' } }
    },
    async post(url, payload) {
      requests.push([url, payload.confirmed])
      return { data: { message: 'reset' } }
    },
  },
})
vm.runInContext(source + '\nthis.make = speedboxCreatePartnerAccounts;', context)
const ui = context.make({
  rows: () => [row], transactionHistory: value => { transactions = value },
  renamed: value => { renamed = value }, notify: value => notifications.push(value),
})
const all = value => !value || typeof value !== 'object' ? [] : [value, ...(value.children ?? []).flatMap?.(all) ?? []]
const find = (type, test = () => true) => all(ui.view()).find(node => node.type === type && test(node))
const menu = async label => {
  await ui.openHistory(row)
  const button = find('button', node => Array.isArray(node.children) && node.children.includes(label))
  assert.ok(button)
  return button.props.onClick()
}

assert.equal(ui.actionsButton(row).props['aria-label'], 'عملیات اکانت')
assert.equal(ui.actionsButton(row).props.title, undefined)
await ui.openHistory(row)
assert.ok(find('div', node => node.props?.class === 'speedbox-infrastructure-overlay account-actions-menu'))
await ui.openHistory(row)
await menu('ویرایش نام کاربری')
assert.equal(find('input').props.value, 'x7p2')
const editor = find('div', node => node.props?.class === 'partner-username-editor')
assert.equal(editor.props.dir, 'ltr')
assert.equal(editor.children[0].type, 'input')
assert.equal(editor.children[1].children, '–')
assert.equal(editor.children[2].children, 'ali')
const renameClasses = vueElement('section', find('section').props).props.class;
assert.equal(renameClasses, 'infrastructure-modal account-operation-modal partner-account-modal partner-rename-modal')
assert.ok(!JSON.stringify(ui.view()).includes('پیش‌نمایش'))
assert.equal(find('button', node => node.props.type === 'submit').props.class, 'button success operation-primary')
assert.equal(find('button', node => node.children === 'انصراف').props.class, 'button cancel')
find('input').props.onInput({target: {value: '  Reza  '}})
await find('form').props.onSubmit({preventDefault() {}})
assert.equal(renamed.email, 'reza@ali.com')
assert.deepEqual(requests.at(-1), ['/users/15/username', 'reza'])
await menu('بازنشانی UUID و لینک اشتراک')
assert.equal(find('h3').children, 'بازنشانی UUID و لینک')
const resetClasses = vueElement('section', find('section').props).props.class;
assert.equal(resetClasses, 'infrastructure-modal account-operation-modal partner-account-modal')
assert.ok(find('p', node => node.props?.class === 'operation-warning' && node.children === accountSecretWarning))
assert.equal(find('button', node => node.props.type === 'submit').props.class, 'button danger operation-danger')
assert.equal(find('button', node => node.children === 'انصراف').props.class, 'button cancel')
await find('form').props.onSubmit({preventDefault() {}})
assert.deepEqual(requests.at(-1), ['/users/15/reset-secret', true])
assert.deepEqual(notifications, ['saved', 'reset'])
await menu('تاریخچه تراکنش‌ها')
assert.equal(transactions, row)
data.can_rename = false
await menu('ویرایش نام کاربری')
assert.equal(find('input'), undefined)
assert.equal(find('button', node => node.props.type === 'submit'), undefined)
console.log('Partner account actions: menu, scoped username input, rotation and legacy display passed.')
