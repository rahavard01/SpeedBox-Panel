import assert from 'node:assert/strict';
import { createSpeedboxNotificationQueue } from '../ui/notification-queue.mjs';
const values = new Map(), storage = { getItem: key => values.get(key) || null, setItem: (key, value) => values.set(key, value) };
let time = 1000, id = 0, user = 'admin:1', visible = true;
const tasks = new Map(), displayed = [];
const schedule = (fn, delay) => { const key = ++id; tasks.set(key, { fn, at: time + delay }); return key; };
function tick(milliseconds) {
  const end = time + milliseconds;
  for (;;) {
    const next = [...tasks].filter(([, item]) => item.at <= end).sort((a, b) => a[1].at - b[1].at)[0];
    if (!next) break;
    tasks.delete(next[0]); time = next[1].at; next[1].fn();
  }
  time = end;
}
const create = () => createSpeedboxNotificationQueue({ storage, scope: () => user, display: (message, opts) => displayed.push({ message, opts }), canDisplay: () => visible, schedule, cancel: key => tasks.delete(key), now: () => time });
let queue = create();
for (let n = 1; n <= 10; n++) queue.notify('Same message', { notificationKey: 'event:' + n });
assert.equal(displayed.length, 1, 'A burst displays only one notice at a time');
assert.equal(JSON.parse(values.get('speedbox:notices:v1:admin:1')).pending.length, 9);
queue.notify('Same message', { notificationKey: 'event:1' });
queue.notify('Same message', { notificationKey: 'event:2' });
assert.equal(JSON.parse(values.get('speedbox:notices:v1:admin:1')).pending.length, 9, 'Both displayed and queued IDs are deduplicated');
tick(4850); assert.equal(displayed.length, 2, 'Next notice waits for a full reading interval');
queue.dispose(); queue = create(); queue.resume();
assert.equal(displayed.length, 3, 'Refresh resumes queued notices instead of losing already-acknowledged events');
queue.notify('Same message', { notificationKey: 'event:1' });
tick(40000); assert.equal(displayed.length, 10, 'Every event appears once, including equal message texts');
queue.counts('tickets', { count: 1, notification_ids: [21] }, 'Ticket');
tick(4850);
queue.dispose(); queue = create(); queue.counts('tickets', { count: 1, notification_ids: [21] }, 'Ticket');
assert.equal(displayed.length, 11, 'Refresh does not replay existing unread admin items');
queue.counts('tickets', { count: 1, notification_ids: [22] }, 'Ticket');
assert.equal(displayed.length, 12, 'A new item is detected even if total unread count stays unchanged');
tick(4850); user = 'partner:2'; queue.notify('Same message', { notificationKey: 'event:1' });
assert.equal(displayed.length, 13, 'Displayed state is scoped to the signed-in user');
tick(4850); visible = false; queue.notify('Delayed host', { notificationKey: 'host:1' });
assert.equal(displayed.length, 13);
assert.equal(JSON.parse(values.get('speedbox:notices:v1:partner:2')).seen['host:1'], undefined);
visible = true; tick(250); assert.equal(displayed.length, 14, 'Pending notice is marked seen only when displayed');
queue.dispose();
console.log('Notification ordering, durable pending queue, event deduplication, refresh, equal-count updates and user isolation passed.');
