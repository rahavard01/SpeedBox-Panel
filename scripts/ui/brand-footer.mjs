export function speedboxBrandFooter(h) {
  return h('footer', { class: 'speedbox-brand-footer', dir: 'ltr' }, [
    h('span', { class: 'speedbox-brand-credit' }, 'Powered by'),
    h('img', { class: 'speedbox-brand-logo', src: '/favicon-128x128.png', width: 18, height: 18, alt: '', 'aria-hidden': 'true' }),
    h('span', { class: 'speedbox-brand-name' }, 'SPEEDBOX'),
  ]);
}
