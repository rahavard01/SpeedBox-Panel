// Persist queued and displayed notices separately from unread notification badges.
export function createSpeedboxNotificationQueue({ storage, scope, display, canDisplay = () => true, schedule = setTimeout, cancel = clearTimeout, now = Date.now }) {
  let context, state, timer, active = false;
  const memory = new Map();
  const empty = () => ({ pending: [], seen: {}, counts: {} });
  function save() {
    memory.set(context, state);
    try { storage.setItem(context, JSON.stringify(state)); } catch {}
  }
  function load() {
    const key = 'speedbox:notices:v1:' + scope();
    if (key === context) return;
    if (timer) cancel(timer);
    timer = null; active = false; context = key;
    try { state = JSON.parse(storage.getItem(key) || 'null'); } catch { state = null; }
    if (!state || !Array.isArray(state.pending) || !state.seen || !state.counts) state = memory.get(key) || empty();
  }
  function pump() {
    load();
    if (active || timer || !state.pending.length) return;
    if (!canDisplay()) { timer = schedule(() => { timer = null; pump(); }, 250); return; }
    const item = state.pending[0];
    // Mark seen only after the toast has actually entered the visible host.
    display(item.message, { ...item.options, duration: item.duration, replace: false });
    state.pending.shift(); state.seen[item.key] = now(); active = true; save();
    timer = schedule(() => { timer = null; active = false; pump(); }, item.duration + 350);
  }
  function notify(message, options = {}) {
    load();
    const key = String(options.notificationKey || ('message:' + message));
    if (state.seen[key] || state.pending.some(item => item.key === key)) return null;
    const duration = Math.max(3500, Number(options.duration) || 4500);
    state.pending.push({ key, message: String(message), duration, options: { mode: options.mode || 'global', position: options.position || 'top-right', closable: options.closable !== false } });
    save(); pump(); return key;
  }
  function counts(kind, data, message) {
    load();
    if (Array.isArray(data?.notification_ids)) {
      for (const id of data.notification_ids) notify(message, { notificationKey: 'admin:' + kind + ':' + id });
    } else {
      const count = Number(data?.count || 0), previous = state.counts[kind];
      if (count > 0 && (previous == null || count > previous)) notify(message, { notificationKey: 'admin:' + kind + ':count:' + count });
    }
    state.counts[kind] = Number(data?.count || 0); save();
  }
  return { notify, counts, resume: pump, dispose() { if (timer) cancel(timer); timer = null; active = false; } };
}
