/* Native extension shared by the production build and editable Vue dev mode. */
(function () {
  if (window.__SPEEDBOX_PARTNER_STATISTICS__) return;
  window.__SPEEDBOX_PARTNER_STATISTICS__ = true;
  function start() {
    const tip = document.createElement('div');
    tip.id = 'speedbox-partner-statistics';
    tip.className = 'custom-tooltip speedbox-shared-tooltip speedbox-partner-statistics';
    tip.setAttribute('role', 'tooltip'); tip.hidden = true;
    const body = document.createElement('div');
    const arrow = document.createElement('span'); arrow.className = 'custom-tooltip-arrow';
    tip.append(body, arrow); document.body.append(tip);
    let target = null, code = '', pinned = false, timer = null, scheduled = false, request = null;
    const onPage = () => /^\/admin\/partners\/?$/.test(location.pathname);
    const trigger = event => onPage() && event.target instanceof Element ? event.target.closest('[data-partner-statistics]') : null;

    function decorate() {
      scheduled = false;
      if (!onPage()) { hide(); return; }
      const root = [...document.querySelectorAll('.section-wrap')].find(node => node.querySelector('.topup-title')?.textContent.trim() === 'مدیریت نمایندگان');
      if (!root) { hide(); return; }
      const candidates = [...root.querySelectorAll('.mobile-partner-code')];
      root.querySelectorAll('.billing-table').forEach(table => {
        const index = [...table.querySelectorAll('thead th')].findIndex(cell => cell.textContent.trim() === 'کد پنل');
        if (index < 0) return;
        table.querySelectorAll('tbody tr').forEach(row => {
          const node = row.cells[index]?.querySelector('.code-badge');
          if (node) candidates.push(node);
        });
      });
      candidates.forEach(node => {
        const value = node.textContent.trim();
        if (!value || value === '—') { node.removeAttribute('data-partner-statistics'); return; }
        node.setAttribute('data-partner-statistics', value);
        node.setAttribute('tabindex', '0'); node.setAttribute('role', 'button');
        node.setAttribute('aria-label', `آمار کاربران نماینده ${value}`);
      });
      if (target && (!target.isConnected || target.dataset.partnerStatistics !== code)) hide();
      else if (target) position();
    }
    function hide() {
      clearTimeout(timer); timer = null;
      request?.abort(); request = null;
      if (target) {
        const ids = (target.getAttribute('aria-describedby') || '').split(' ').filter(id => id && id !== tip.id);
        if (ids.length) target.setAttribute('aria-describedby', ids.join(' ')); else target.removeAttribute('aria-describedby');
      }
      tip.hidden = true; target = null; code = ''; pinned = false;
    }
    function position() {
      if (!target?.isConnected || !onPage()) { hide(); return; }
      const rect = target.getBoundingClientRect(), width = tip.offsetWidth, height = tip.offsetHeight;
      const left = Math.max(8, Math.min(innerWidth - width - 8, rect.left + rect.width / 2 - width / 2));
      const above = rect.top >= height + 14;
      tip.style.left = left + 'px';
      tip.style.top = Math.max(8, Math.min(innerHeight - height - 8, above ? rect.top - height - 10 : rect.bottom + 10)) + 'px';
      tip.dataset.side = above ? 'top' : 'bottom';
      arrow.style.left = Math.max(10, Math.min(width - 10, rect.left + rect.width / 2 - left)) + 'px';
    }
    function message(text, icon) {
      body.replaceChildren();
      const line = document.createElement('div'); line.className = 'partner-statistics-message';
      const glyph = document.createElement('i'); glyph.className = icon; glyph.setAttribute('aria-hidden', 'true');
      const label = document.createElement('span'); label.textContent = text;
      line.append(glyph, label); body.append(line); position();
    }
    function counts(data) {
      body.replaceChildren();
      [['total','کل کاربران','users'],['active','کاربران فعال','check-circle'],['online','کاربران آنلاین','wifi'],['expired','کاربران منقضی','clock']].forEach(([key,label,icon]) => {
        const line = document.createElement('div'); line.className = 'partner-statistics-row';
        const glyph = document.createElement('i'); glyph.className = 'fas fa-' + icon; glyph.setAttribute('aria-hidden', 'true');
        const text = document.createElement('span'); text.textContent = label;
        const value = document.createElement('b'); value.textContent = Number(data[key] || 0).toLocaleString('fa-IR');
        line.append(glyph, text, value); body.append(line);
      });
      position();
    }
    async function show(node, pin = false) {
      clearTimeout(timer); timer = null;
      if (target === node && code === node.dataset.partnerStatistics) { pinned = pin || pinned; return; }
      hide();
      target = node; code = node.dataset.partnerStatistics; pinned = pin;
      tip.hidden = false;
      const ids = new Set((node.getAttribute('aria-describedby') || '').split(' ').filter(Boolean)); ids.add(tip.id);
      node.setAttribute('aria-describedby', [...ids].join(' '));
      message('در حال دریافت آمار…', 'fas fa-spinner fa-spin');
      const controller = new AbortController(); request = controller;
      try {
        const token = localStorage.getItem('token');
        const response = await fetch('/api/admin/partners/account-statistics?' + new URLSearchParams({panel_code: code}), {
          headers: { Accept: 'application/json', Authorization: `Bearer ${token || ''}` },
          credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
        });
        if (!response.ok) throw new Error('Request failed');
        const result = await response.json();
        if (request === controller && target === node && node.isConnected) counts(result.data);
      } catch (error) {
        if (error.name !== 'AbortError' && request === controller && target === node) message('دریافت آمار انجام نشد؛ دوباره امتحان کنید.', 'fas fa-exclamation-circle');
      } finally { if (request === controller) request = null; }
    }
    document.addEventListener('pointerover', event => {
      if (event.pointerType === 'touch' || pinned) return;
      const node = trigger(event);
      if (!node || node.contains(event.relatedTarget)) return;
      clearTimeout(timer); timer = setTimeout(() => { if (node.isConnected && onPage()) show(node); }, 80);
    });
    document.addEventListener('pointerout', event => {
      const node = trigger(event);
      if (node && !node.contains(event.relatedTarget) && !pinned) hide();
    });
    document.addEventListener('focusin', event => { const node = trigger(event); if (node && !pinned) show(node); });
    document.addEventListener('focusout', event => { if (!pinned && target && !target.contains(event.relatedTarget)) hide(); });
    document.addEventListener('click', event => {
      const node = trigger(event);
      if (node) {
        if (target === node && pinned) hide(); else show(node, true);
      } else if (pinned && !tip.contains(event.target)) hide();
    });
    document.addEventListener('pointerdown', event => { if (pinned && !trigger(event) && !tip.contains(event.target)) hide(); });
    document.addEventListener('keydown', event => {
      if (event.key === 'Escape') hide();
      else if ((event.key === 'Enter' || event.key === ' ') && trigger(event)) { event.preventDefault(); trigger(event).click(); }
    });
    document.addEventListener('scroll', hide, true); window.addEventListener('resize', hide);
    window.addEventListener('popstate', hide);
    document.addEventListener('visibilitychange', () => { if (document.hidden) hide(); });
    new MutationObserver(records => {
      if (records.every(record => tip.contains(record.target))) return;
      if (!scheduled) { scheduled = true; requestAnimationFrame(decorate); }
    }).observe(document.body, { childList: true, characterData: true, subtree: true });
    decorate();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true }); else start();
})();
