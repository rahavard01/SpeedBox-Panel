// Vue/runtime aliases below belong to the shipped Panel bundle. The bridge
// embeds this factory there so the representative page keeps its existing shell.
export function speedboxCreatePartnerAccounts({ rows, transactionHistory }) {
  const online = G({}), modal = G(null), selected = G(null), history = G([]);
  const busy = G(false), failure = G(''), account = G(null), menu = G(false), menuStyle = G({});
  let trigger, menuElement, dialogElement, previousOverflow;
  let alive = true, timer, onlineAbort, onlineSequence = 0, historySequence = 0;
  const ids = () => [...new Set(rows().map(row => Number(row.id)).filter(id => id > 0))].slice(0, 500);
  const errorText = error => Object.values(error?.response?.data?.errors || {}).flat()[0] || error?.response?.data?.message || 'دریافت اطلاعات انجام نشد؛ دوباره تلاش کنید';
  async function pollOnline() {
    const current = ids(), sequence = ++onlineSequence;
    onlineAbort?.abort();
    if (!current.length) { online.value = {}; return; }
    onlineAbort = new AbortController();
    try {
      const response = await w0.post('/users/online', { ids: current }, { signal: onlineAbort.signal });
      if (!alive || sequence !== onlineSequence) return;
      online.value = Object.fromEntries((response.data?.data || []).map(row => [Number(row.id), row]));
    } catch (error) {
      if (alive && sequence === onlineSequence && error?.code !== 'ERR_CANCELED') online.value = {};
    }
  }
  x1(() => ids().join(','), () => { online.value = {}; pollOnline(); }, { immediate: true });
  be(() => {
    timer = setInterval(() => { if (!document.hidden) pollOnline(); }, 15000);
    document.addEventListener('pointerdown', outside); document.addEventListener('keydown', keyboard);
    window.addEventListener('scroll', scroll, true); window.addEventListener('resize', closeMenu);
  });
  e2(() => {
    alive = false; ++onlineSequence; ++historySequence; onlineAbort?.abort(); clearInterval(timer);
    document.removeEventListener('pointerdown', outside); document.removeEventListener('keydown', keyboard);
    window.removeEventListener('scroll', scroll, true); window.removeEventListener('resize', closeMenu);
    unlockScroll();
  });
  function onlineBadge(row) {
    const status = online.value[Number(row.id)];
    const count = status ? Number(status.online_count) : null;
    const limit = Number(row.device_limit ?? status?.device_limit ?? 0);
    const state = count === null || count <= 0 ? 'is-offline' : limit > 0 && count >= limit ? 'is-at-limit' : 'is-online';
    k();
    const digits = value => String(value).replace(/[0-9]/g, digit => '۰۱۲۳۴۵۶۷۸۹'[Number(digit)]);
    return x('span', { class: 'speedbox-online-count ' + state, dir: 'ltr', 'data-sb-tooltip': limit > 0 ? 'سقف اتصال: ' + digits(limit) + ' کاربر' : 'سقف اتصال: نامحدود', 'data-sb-tooltip-tap': '', tabindex: 0 }, [
      b('i', { class: 'fas fa-desktop', 'aria-hidden': 'true' }),
      b('bdi', null, (count === null ? '—' : digits(count)) + ' / ' + (limit > 0 ? digits(limit) : '∞')),
    ], -2);
  }
  function closeMenu(restoreFocus = false) {
    menu.value = false;
    if (restoreFocus === true) trigger?.focus();
  }
  function unlockScroll() {
    if (previousOverflow !== undefined) { document.body.style.overflow = previousOverflow; previousOverflow = undefined; }
  }
  function close() {
    modal.value = null; ++historySequence; busy.value = false; unlockScroll();
    Ue(() => trigger?.focus());
  }
  async function openHistory(row, event) {
    if (menu.value && Number(selected.value?.id) === Number(row.id)) return closeMenu(true);
    if (modal.value) close();
    selected.value = row; trigger = event?.currentTarget;
    const rectangle = trigger?.getBoundingClientRect() || { right: window.innerWidth / 2, top: 24, bottom: 48 };
    const width = Math.min(205, window.innerWidth - 24), height = 78;
    menuStyle.value = { width: width + 'px', left: Math.max(12, Math.min(rectangle.right - width, window.innerWidth - width - 12)) + 'px',
      ...(rectangle.bottom + height + 6 > window.innerHeight ? { bottom: Math.max(12, window.innerHeight - rectangle.top + 6) + 'px' } : { top: rectangle.bottom + 6 + 'px' }) };
    menu.value = true;
    await Ue(); if (menu.value) menuElement?.querySelector('button')?.focus();
  }
  function outside(event) {
    if (!trigger?.contains(event.target) && !menuElement?.contains(event.target)) closeMenu();
  }
  function scroll(event) { if (!menuElement?.contains(event.target)) closeMenu(); }
  function keyboard(event) {
    if (event.key === 'Escape') {
      if (menu.value) { event.preventDefault(); closeMenu(true); }
      else if (modal.value) { event.preventDefault(); close(); }
    }
    if (event.key === 'Tab' && modal.value) {
      const buttons = [...dialogElement?.querySelectorAll('button:not(:disabled)') || []];
      const first = buttons[0], last = buttons.at(-1);
      if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
      else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
    }
  }
  function menuKeyboard(event) {
    const buttons = [...menuElement?.querySelectorAll('button') || []], index = buttons.indexOf(document.activeElement);
    if (['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) {
      event.preventDefault();
      buttons[event.key === 'Home' ? 0 : event.key === 'End' ? buttons.length - 1 : (index + (event.key === 'ArrowDown' ? 1 : -1) + buttons.length) % buttons.length]?.focus();
    }
    if (event.key === 'Tab') closeMenu();
  }
  async function loadHistory() {
    const sequence = ++historySequence, id = Number(selected.value?.id);
    modal.value = 'resets'; busy.value = true; failure.value = ''; history.value = []; account.value = null;
    if (previousOverflow === undefined) { previousOverflow = document.body.style.overflow; document.body.style.overflow = 'hidden'; }
    Ue(() => { if (modal.value) dialogElement?.focus(); });
    try {
      const response = await w0.get('/users/' + id + '/reset-history', { params: { page: 1 } });
      if (!alive || sequence !== historySequence) return;
      history.value = (response.data?.data || []).slice(0, 10);
      account.value = response.data?.account || {};
    } catch (error) { if (alive && sequence === historySequence) failure.value = errorText(error); }
    finally { if (alive && sequence === historySequence) busy.value = false; }
  }
  function choose(kind) {
    const row = selected.value; closeMenu();
    if (kind === 'resets') return loadHistory();
    if (kind === 'transactions') transactionHistory(row);
  }
  const tehranTime = value => {
    if (!value) return '—';
    const raw = String(value).replace(' ', 'T');
    const date = /^\d+$/.test(raw) ? new Date(Number(raw) * 1000) : new Date(/[Zz]|[+-]\d\d:\d\d$/.test(raw) ? raw : raw + 'Z');
    if (Number.isNaN(date.getTime())) return '—';
    const parts = Object.fromEntries(new Intl.DateTimeFormat('fa-IR-u-nu-arabext', { timeZone: 'Asia/Tehran', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).formatToParts(date).map(part => [part.type, part.value]));
    return parts.year + '/' + parts.month + '/' + parts.day + ' ' + parts.hour + ':' + parts.minute;
  };
  const amount = value => _n(Number(value || 0));
  const icon = name => b('i', { class: name, 'aria-hidden': 'true' });
  const detail = (label, value, props = {}) => b('div', null, [b('dt', null, label), b('dd', props, value)]);
  const button = (label, handler, props = {}) => b('button', { type: 'button', onClick: handler, ...props }, label);
  function historyButton(row, mobile = false) {
    k();
    return x('button', { type: 'button', class: 'transaction-icon-btn' + (mobile ? ' transaction-icon-btn--mobile' : ''),
      title: 'تاریخچه', 'aria-label': 'تاریخچه', 'aria-haspopup': 'menu', 'aria-expanded': menu.value && Number(selected.value?.id) === Number(row.id),
      onClick: event => openHistory(row, event) }, [icon('fas fa-receipt')], -2);
  }
  function historyView() {
    const labels = { manual: 'دستی', periodic: 'دوره‌ای شمسی', renewal: 'تمدید', monthly: 'ماهانه', first_day_month: 'ابتدای ماه', yearly: 'سالانه', first_day_year: 'ابتدای سال', purchase: 'خرید' };
    const sources = { manual: 'مدیر', admin: 'مدیر', cron: 'زمان‌بندی', order: 'تمدید / سفارش', auto: 'خودکار', api: 'API', user_access: 'دریافت اشتراک', gift_card: 'کارت هدیه' };
    if (busy.value) return b('div', { class: 'operation-loading', role: 'status' }, [icon('fas fa-spinner fa-spin'), ' در حال دریافت اطلاعات…']);
    if (failure.value) return b('div', null, [b('p', { role: 'alert', class: 'operation-warning' }, failure.value),
      button('تلاش مجدد', loadHistory, { class: 'speedbox-history-retry' })]);
    const summary = account.value || {};
    return b('div', { class: 'reset-panel' }, [b('div', { class: 'reset-history-grid' }, [
      b('section', { class: 'reset-surface reset-summary-card' }, [
        b('h4', null, [icon('fas fa-chart-bar'), 'خلاصه بازنشانی ترافیک']),
        b('dl', { class: 'reset-summary' }, [
          detail('تعداد بازنشانی', Number(summary.reset_count || 0).toLocaleString('fa-IR'), { dir: 'ltr' }),
          detail('آخرین بازنشانی', summary.last_reset_at ? tehranTime(summary.last_reset_at) : 'ثبت نشده', { dir: 'ltr' }),
          detail('بازنشانی دوره‌ای بعدی', summary.next_reset_at ? tehranTime(summary.next_reset_at) : 'زمان‌بندی نشده', { dir: 'ltr' }),
        ]),
      ]),
      b('section', { class: 'reset-surface reset-records-card' }, [
        b('header', null, [b('h4', null, [icon('fas fa-history'), 'سوابق بازنشانی ترافیک']), b('small', null, '۱۰ رکورد آخر')]),
        b('div', { class: 'reset-records-scroll' }, history.value.length ? history.value.map(record =>
          b('article', { key: record.id, class: 'reset-log-card' }, [
            b('div', { class: 'reset-log-tags' }, [b('span', null, labels[record.reset_type] || record.reset_type),
              ...(record.trigger_source ? [b('span', null, sources[record.trigger_source] || record.trigger_source)] : [])]),
            b('dl', { class: 'reset-log-details' }, [
              detail([icon('far fa-clock'), ' زمان بازنشانی'], [b('bdi', { dir: 'ltr' }, tehranTime(record.reset_time))]),
              detail('ترافیک مصرف‌شده پیش از بازنشانی', [b('bdi', { dir: 'ltr' }, amount(record.old_total))], { class: 'reset-used' }),
            ]),
          ])) : [b('div', { class: 'operation-empty' }, [icon('fas fa-history'), b('p', null, 'سابقه‌ای ثبت نشده است.')])]),
      ]),
    ])]);
  }
  function view() {
    // Teleport avoids clipping by table scroll containers and the Panel backdrop.
    // BAIL keeps these children reactive inside the compiled parent block.
    const children = [];
    if (menu.value) children.push(b('div', { ref: element => { menuElement = element; }, class: 'speedbox-partner-history-menu',
      style: menuStyle.value, role: 'menu', dir: 'rtl', onKeydown: menuKeyboard }, [
      button([icon('fas fa-history'), 'تاریخچه تراکنش‌ها'], () => choose('transactions'), { role: 'menuitem' }),
      button([icon('fas fa-sync-alt'), 'تاریخچه بازنشانی ترافیک'], () => choose('resets'), { role: 'menuitem' }),
    ]));
    if (modal.value) children.push(b('div', { class: 'speedbox-infrastructure-overlay account-operation-overlay' }, [
      b('div', { class: 'modal-backdrop account-operation-backdrop', onClick: event => { if (event.target === event.currentTarget) close(); } }, [
        b('div', { class: 'modal-outer', onClick: event => { if (event.target === event.currentTarget) close(); } }, [
          b('section', { ref: element => { dialogElement = element; }, class: 'infrastructure-modal account-operation-modal account-reset-modal speedbox-history-modal', role: 'dialog',
            'aria-modal': 'true', 'aria-labelledby': 'speedbox-partner-history-title', tabindex: -1, dir: 'rtl' }, [
            b('header', { class: 'announcement-modal-heading account-operation-header' }, [b('h3', { id: 'speedbox-partner-history-title', class: 'topup-title' }, 'تاریخچه بازنشانی ترافیک')]),
            b('header', { class: 'traffic-modal-subtitle', dir: 'rtl' }, [b('div', { class: 'traffic-modal-subtitle__content' }, [
              icon('fas fa-user'), b('span', null, xt(account.value?.email || selected.value?.email || '')),
            ])]),
            historyView(),
            b('footer', { class: 'back-button speedbox-history-back' }, [button('بازگشت', close)]),
          ]),
        ]),
      ]),
    ]));
    k(); return l2(Ft, { to: 'body' }, [b('div', { class: 'speedbox-partner-history' }, children)], -2);
  }
  return { onlineBadge, historyButton, openHistory, view };
}
