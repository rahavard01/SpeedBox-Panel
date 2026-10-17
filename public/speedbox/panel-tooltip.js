(function () {
  if (window.__SPEEDBOX_SHARED_TOOLTIP__) return;
  window.__SPEEDBOX_SHARED_TOOLTIP__ = true;
  function start() {
    const tip = document.createElement('div');
    tip.id = 'speedbox-shared-tooltip'; tip.className = 'custom-tooltip speedbox-shared-tooltip';
    tip.setAttribute('role', 'tooltip'); tip.hidden = true;
    const content = document.createElement('span');
    const arrow = document.createElement('span'); arrow.className = 'custom-tooltip-arrow';
    tip.append(content, arrow); document.body.append(tip);
    let target = null, pinned = false, timer = null;
    function migrate(root) {
      const nodes = root.nodeType === 1 ? [root, ...root.querySelectorAll('[title]')] : [];
      for (const node of nodes) {
        if (!node.hasAttribute('title')) continue;
        const text = node.getAttribute('title');
        if (text) {
          node.setAttribute('data-sb-tooltip', text);
          if (!node.matches('input,textarea,select')) {
            node.setAttribute('data-sb-tooltip-tap', '');
            if (!node.hasAttribute('tabindex') && !node.matches('button,a')) node.setAttribute('tabindex', '0');
          }
        }
        else node.removeAttribute('data-sb-tooltip');
        node.removeAttribute('title');
        if (text && !node.hasAttribute('aria-label') && !node.textContent.trim() && node.matches('button,a')) node.setAttribute('aria-label', text);
      }
    }
    function trigger(event) {
      const node = event.target instanceof Element ? event.target.closest('[data-sb-tooltip]') : null;
      return node && !node.disabled ? node : null;
    }
    function hide() {
      clearTimeout(timer);
      if (target) {
        const ids = (target.getAttribute('aria-describedby') || '').split(' ').filter(id => id && id !== tip.id);
        if (ids.length) target.setAttribute('aria-describedby', ids.join(' ')); else target.removeAttribute('aria-describedby');
      }
      tip.hidden = true; target = null; pinned = false;
    }
    function position() {
      if (!target?.isConnected) { hide(); return; }
      const rect = target.getBoundingClientRect(), width = tip.offsetWidth, height = tip.offsetHeight;
      const left = Math.max(8, Math.min(innerWidth - width - 8, rect.left + rect.width / 2 - width / 2));
      const above = rect.top >= height + 14;
      const top = above ? rect.top - height - 10 : rect.bottom + 10;
      tip.style.left = left + 'px'; tip.style.top = Math.max(8, Math.min(innerHeight - height - 8, top)) + 'px';
      tip.dataset.side = above ? 'top' : 'bottom';
      arrow.style.left = Math.max(10, Math.min(width - 10, rect.left + rect.width / 2 - left)) + 'px';
    }
    function show(node, pin = false) {
      clearTimeout(timer);
      if (target !== node) hide();
      const text = node.getAttribute('data-sb-tooltip');
      if (!text) return;
      target = node; pinned = pin;
      // Text, not HTML: imported names, URLs and FAQ labels cannot inject markup.
      content.textContent = text; tip.hidden = false;
      const ids = new Set((node.getAttribute('aria-describedby') || '').split(' ').filter(Boolean)); ids.add(tip.id);
      node.setAttribute('aria-describedby', [...ids].join(' ')); position();
    }
    document.addEventListener('pointerover', event => {
      if (event.pointerType === 'touch' || pinned) return;
      const node = trigger(event);
      if (!node || node.contains(event.relatedTarget)) return;
      clearTimeout(timer); timer = setTimeout(() => show(node), 80);
    });
    document.addEventListener('pointerout', event => {
      if (!pinned && target && !target.contains(event.relatedTarget)) hide();
      else if (!pinned && !target) clearTimeout(timer);
    });
    document.addEventListener('focusin', event => { const node = trigger(event); if (node && !pinned) show(node); });
    document.addEventListener('focusout', event => { if (!pinned && target && !target.contains(event.relatedTarget)) hide(); });
    document.addEventListener('click', event => {
      const node = trigger(event);
      if (node && node.hasAttribute('data-sb-tooltip-tap')) {
        if (target === node && pinned) hide(); else show(node, true);
      } else if (pinned && !tip.contains(event.target)) hide();
    });
    document.addEventListener('keydown', event => {
      if (event.key === 'Escape') hide();
      else if ((event.key === 'Enter' || event.key === ' ') && trigger(event)?.hasAttribute('data-sb-tooltip-tap')) { event.preventDefault(); trigger(event).click(); }
    });
    document.addEventListener('scroll', hide, true); window.addEventListener('resize', hide);
    migrate(document.body);
    const observer = new MutationObserver(records => {
      for (const record of records) {
        if (record.type === 'attributes') migrate(record.target);
        else for (const node of record.addedNodes) migrate(node);
      }
      if (target && !target.isConnected) hide();
      else if (target && !tip.hidden) {
        const text = target.getAttribute('data-sb-tooltip') || '';
        if (content.textContent !== text) content.textContent = text;
        if (!text) hide(); else position();
      }
    });
    observer.observe(document.body, { subtree: true, childList: true, attributes: true, attributeFilter: ['title', 'data-sb-tooltip'] });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true }); else start();
})();
