/* Server mode uses the original Panel drawer; system settings live on one page. */
const speedboxServerSections = [
  { view: 'overview', label: 'داشبورد', icon: 'chart-line' },
  { view: 'configuration', label: 'پیکربندی سیستم', icon: 'cogs' },
  { view: 'servers', label: 'مدیریت سرورها', icon: 'server' },
  { view: 'nodes', label: 'مدیریت نودها', icon: 'project-diagram' },
  { view: 'emergency-configs', label: 'کانفیگ‌های اضطراری', icon: 'life-ring' },
  { view: 'plans', label: 'مدیریت پلن‌ها', icon: 'tags' },
  { view: 'routing', label: 'گروه‌های دسترسی و مسیریابی', icon: 'route' }
];
const speedboxConfigurationAliases = ['site-settings','security-settings','subscription-settings','server-settings','subscription-templates','settings'];
window.__SPEEDBOX_SERVER_MENU__ = ({ element, component, link, slot }) =>
  speedboxServerSections.map(item => component(link, {
    class: 'drawer-link', 'data-speedbox-view': item.view,
    to: item.view === 'overview' ? '/admin/infrastructure' : '/admin/infrastructure/' + item.view
  }, { default: slot(() => [
    element('span', null, item.label),
    element('i', { class: 'fas fa-' + item.icon, 'aria-hidden': 'true' })
  ]) }));
let speedboxPanelVue;
window.__SPEEDBOX_IS_MOBILE__ = () => window.matchMedia('(max-width: 767px)').matches;
window.__SPEEDBOX_USE_MOBILE__ = () => {
  const { ref, onMounted, onUnmounted } = speedboxPanelVue;
  const media = window.matchMedia('(max-width: 767px)'), mobile = ref(media.matches);
  const update = () => { mobile.value = media.matches; };
  onMounted(() => media.addEventListener('change', update));
  onUnmounted(() => media.removeEventListener('change', update));
  return mobile;
};
window.__SPEEDBOX_RENDER_NATIVE_SELECT__ = ({ value, options = [], placeholder, change, bare = false, surface = '', controlClass = '', ...attrs }) => {
  const { element } = speedboxPanelVue;
  const rows = Array.from(options, option => option && typeof option === 'object'
    ? { value: option.value ?? option.id ?? '', label: option.label ?? option.value ?? option.id ?? '', disabled: option.disabled }
    : { value: option ?? '', label: option ?? '' });
  if (placeholder && !rows.some(row => String(row.value) === '')) rows.unshift({ value: '', label: placeholder });
  const select = element('select', { ...attrs, class: ['speedbox-native-select', attrs.class].filter(Boolean).join(' '), value: String(value ?? ''),
    onChange: event => { const row = rows.find(row => String(row.value) === event.target.value); change(row ? row.value : event.target.value); }
  }, rows.map(row => element('option', { key: String(row.value), value: String(row.value), disabled: row.disabled }, String(row.label))));
  if (bare) return select;
  const selected = rows.find(row => String(row.value) === String(value ?? ''));
  const scope = { 'data-v-f8294ed3': '' };
  return element('div', { ...scope, key: 'speedbox-native-select', class: 'invoice-custom-select speedbox-native-surface ' + controlClass + (surface ? ' continuous-select-' + surface : ''), dir: 'rtl' }, [
    element('button', { ...scope, type: 'button', class: 'invoice-custom-select__trigger', tabindex: '-1', 'aria-hidden': 'true', disabled: attrs.disabled }, [
      element('span', scope, String(selected?.label ?? placeholder ?? 'انتخاب کنید')),
      element('i', { ...scope, class: 'fas fa-chevron-down', 'aria-hidden': 'true' })
    ]), select
  ]);
};
window.__SPEEDBOX_PANEL_SWITCH__ = {
  name: 'SpeedboxPanelSwitch',
  props: ['serverMode', 'navigate', 'beforeOpen'],
  setup(props) {
    const { element, ref, nextTick, onMounted, onUnmounted } = speedboxPanelVue;
    const open = ref(false), wrapper = ref(null), trigger = ref(null);
    const mobile = window.__SPEEDBOX_USE_MOBILE__();
    let placementObserver, panelBar, headerTop, floatingRoot;
    const placeAboveBar = () => {
      const bar = panelBar || wrapper.value?.closest('.top-bar');
      const hamburger = bar?.querySelector('.hamburger-menu');
      if (!bar || !hamburger || !trigger.value) return;
      const rect = bar.getBoundingClientRect(), anchor = hamburger.getBoundingClientRect();
      const control = trigger.value;
      const width = control?.getBoundingClientRect().width || 0;
      const height = control?.getBoundingClientRect().height || 0;
      const left = Math.max(8, Math.min(anchor.right - width, window.innerWidth - width - 8));
      // Remember the header's document boundary, rather than a sticky bar's
      // current screen position. Recompute the midpoint when control size changes.
      headerTop ??= window.scrollY + rect.top;
      const placementTop = Math.max(0, (headerTop - height) / 2);
      wrapper.value.style.left = `${window.scrollX + left}px`;
      wrapper.value.style.top = `${placementTop}px`;
    };
    const panels = [
      { serverMode: false, label: 'پنل مدیریت اصلی', icon: 'th-large' },
      { serverMode: true, label: 'پنل مدیریت سرورها', icon: 'server' }
    ];
    const close = (focus = false) => { open.value = false; if (focus) trigger.value?.focus(); };
    const focusOption = index => nextTick(() => {
      wrapper.value?.querySelectorAll('[role=menuitemradio]')[index]?.focus();
    });
    const show = () => { props.beforeOpen?.(); open.value = true; focusOption(props.serverMode ? 1 : 0); };
    const outside = event => { if (!wrapper.value?.contains(event.target)) close(); };
    const scroll = event => { if (!wrapper.value?.contains(event.target)) close(); };
    onMounted(() => {
      document.addEventListener('click', outside);
      window.addEventListener('scroll', scroll, true);
      window.addEventListener('resize', close);
      window.addEventListener('resize', placeAboveBar);
      placementObserver = new ResizeObserver(placeAboveBar);
      panelBar = wrapper.value?.closest('.top-bar');
      // Anchor the selector in the document's header space, outside the bar's
      // backdrop-filter containing block. It scrolls out with the header and
      // never follows the bar's sticky/fixed position. The unmount hook cleans up.
      if (panelBar) { placementObserver.observe(panelBar); placeAboveBar(); floatingRoot = wrapper.value; document.body.appendChild(floatingRoot); }
      if (trigger.value) placementObserver.observe(trigger.value);
      nextTick(placeAboveBar);
    });
    onUnmounted(() => {
      document.removeEventListener('click', outside);
      window.removeEventListener('scroll', scroll, true);
      window.removeEventListener('resize', close);
      window.removeEventListener('resize', placeAboveBar);
      placementObserver?.disconnect();
      floatingRoot?.remove();
    });
    const keys = event => {
      if (mobile.value) return;
      if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); close(true); }
      if (event.key === 'Tab') close();
      if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) return;
      event.preventDefault();
      if (!open.value) { show(); return; }
      const items = [...wrapper.value.querySelectorAll('[role=menuitemradio]')];
      const index = items.indexOf(document.activeElement);
      focusOption(event.key === 'Home' ? 0 : event.key === 'End' ? 1 : (index + (event.key === 'ArrowDown' ? 1 : -1) + 2) % 2);
    };
    return () => {
      const current = panels[props.serverMode ? 1 : 0];
      return element('div', {
        ref: wrapper, class: 'speedbox-panel-switch speedbox-panel-switch--above', dir: 'rtl',
        'data-speedbox-mode': props.serverMode ? 'servers' : 'main', onKeydown: keys,
        onFocusout: event => { if (!event.currentTarget.contains(event.relatedTarget)) close(); }
      }, [
        mobile.value ? window.__SPEEDBOX_RENDER_NATIVE_SELECT__({ value: props.serverMode ? 'servers' : 'main',
          options: panels.map(panel => ({ value: panel.serverMode ? 'servers' : 'main', label: panel.label })),
          'aria-label': 'انتخاب پنل', class: 'speedbox-panel-native', bare: true,
          change: value => { close(); props.beforeOpen?.(); if ((value === 'servers') !== props.serverMode) props.navigate(value === 'servers'); }
        }) : null,
        element('button', {
          ref: trigger, type: 'button', class: 'speedbox-panel-switch__trigger',
          tabindex: mobile.value ? '-1' : null, 'aria-hidden': mobile.value ? 'true' : null,
          'aria-haspopup': 'menu', 'aria-expanded': String(open.value),
          'aria-controls': 'speedbox-panel-menu', 'aria-label': `انتخاب پنل؛ ${current.label}`,
          onClick: () => open.value ? close(true) : show()
        }, [
          element('span', null, current.label),
          element('i', { class: 'fas fa-chevron-down', 'aria-hidden': 'true' })
        ]),
        open.value && !mobile.value ? element('div', {
          id: 'speedbox-panel-menu', class: 'speedbox-panel-switch__menu', role: 'menu', 'aria-label': 'انتخاب پنل'
        }, panels.map(panel => element('button', {
          type: 'button', role: 'menuitemradio', tabindex: '-1',
          'aria-checked': String(panel.serverMode === props.serverMode),
          class: 'speedbox-panel-switch__option' + (panel.serverMode === props.serverMode ? ' is-selected' : ''),
          onClick: () => { close(true); if (panel.serverMode !== props.serverMode) props.navigate(panel.serverMode); }
        }, [
          element('i', { class: `fas fa-${panel.icon}`, 'aria-hidden': 'true' }),
          element('span', null, panel.label),
          panel.serverMode === props.serverMode ? element('i', { class: 'fas fa-check', 'aria-hidden': 'true' }) : null
        ]))) : null
      ].filter(Boolean));
    };
  }
};
window.__SPEEDBOX_TARIFF_PLAN_SELECT__ = {
  name: 'TariffPlanSelect',
  props: ['modelValue', 'options', 'surface', 'inputId', 'label', 'disabled'],
  emits: ['update:modelValue'],
  setup(props, { emit }) {
    const { element, ref, nextTick, onMounted, onUnmounted } = speedboxPanelVue;
    const open = ref(false), wrapper = ref(null), trigger = ref(null);
    const mobile = window.__SPEEDBOX_USE_MOBILE__();
    const close = (focus = false) => { open.value = false; if (focus) trigger.value?.focus(); };
    const focusOption = index => nextTick(() => {
      const options = wrapper.value?.querySelectorAll('[role=option]') || [];
      options[Math.max(0, Math.min(index, options.length - 1))]?.focus();
    });
    const show = () => {
      open.value = true;
      focusOption((props.options || []).findIndex(option => String(option.id) === String(props.modelValue)));
    };
    const outside = event => { if (!wrapper.value?.contains(event.target)) close(); };
    const scroll = event => { if (!wrapper.value?.contains(event.target)) close(); };
    onMounted(() => { document.addEventListener('click', outside); window.addEventListener('scroll', scroll, true); window.addEventListener('resize', close); });
    onUnmounted(() => { document.removeEventListener('click', outside); window.removeEventListener('scroll', scroll, true); window.removeEventListener('resize', close); });
    const keys = event => {
      if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); close(true); return; }
      if (event.key === 'Tab') close();
      if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) return;
      event.preventDefault();
      if (!open.value) { show(); return; }
      const options = [...wrapper.value.querySelectorAll('[role=option]')];
      const index = options.indexOf(document.activeElement);
      focusOption(event.key === 'Home' ? 0 : event.key === 'End' ? options.length - 1 : (index + (event.key === 'ArrowDown' ? 1 : -1) + options.length) % options.length);
    };
    return () => {
      const options = props.options || [];
      const selected = options.find(option => String(option.id) === String(props.modelValue));
      if (mobile.value) return window.__SPEEDBOX_RENDER_NATIVE_SELECT__({ value: props.modelValue, options,
        id: props.inputId, disabled: props.disabled, 'aria-label': props.label || 'پلن',
        surface: props.surface, controlClass: 'tariff-plan-select', change: value => emit('update:modelValue', value) });
      return element('div', { ref: wrapper, class: 'invoice-custom-select tariff-plan-select' + (props.surface === 'white' ? ' continuous-select-white' : '') + (props.surface === 'panel' ? ' continuous-select-panel' : '') + (open.value ? ' is-open' : ''), dir: 'rtl', onKeydown: keys,
        onFocusout: event => { if (!event.currentTarget.contains(event.relatedTarget)) close(); }
      }, [
        element('button', { ref: trigger, id: props.inputId, disabled: props.disabled, type: 'button', class: 'invoice-custom-select__trigger', 'aria-label': props.label || 'پلن', 'aria-haspopup': 'listbox', 'aria-expanded': String(open.value), onClick: () => open.value ? close(true) : show() }, [
          element('span', null, selected?.label || 'انتخاب پلن'), element('i', { class: 'fas fa-chevron-down', 'aria-hidden': 'true' })
        ]),
        open.value ? element('div', { class: 'invoice-custom-select__menu', role: 'listbox', 'aria-label': props.label || 'پلن' }, options.map(option => {
          const active = String(option.id) === String(props.modelValue);
          return element('button', { key: option.id, type: 'button', role: 'option', tabindex: '-1', 'aria-selected': String(active), class: 'invoice-custom-select__option' + (active ? ' is-selected' : ''),
            onClick: () => { emit('update:modelValue', option.id); close(true); }
          }, option.label);
        })) : null
      ].filter(Boolean));
    };
  }
};
window.__SPEEDBOX_EXTEND_PANEL__ = ({ router, element, onMounted, onUnmounted, watch, ref, nextTick, notifications }) => {
  speedboxPanelVue = { element, ref, nextTick, onMounted, onUnmounted, watch, router };
  router.addRoute('Admin', {
    path: 'infrastructure/:view?',
    name: 'AdminInfrastructure',
    meta: { requiresAuth: true, requiresAdmin: true, title: 'پنل مدیریت سرورها' },
    beforeEnter(to) {
      if (to.params.view && !speedboxServerSections.some(item => item.view === to.params.view) && !speedboxConfigurationAliases.includes(to.params.view)) {
        return '/admin/infrastructure';
      }
    },
    component: {
      name: 'SpeedboxInfrastructure',
      setup() {
        const notify = notifications();
        let session;
        watch(() => router.currentRoute.value.params.view, view => session?.showView(view || 'overview'));
        onMounted(() => {
          session = window.SpeedboxInfrastructure.mount(
            document.getElementById('speedbox-infrastructure'),
            { router, notify, view: router.currentRoute.value.params.view || 'overview' }
          );
        });
        onUnmounted(() => session?.dispose());
        return () => element('section', {
          id: 'speedbox-infrastructure',
          class: 'speedbox-infrastructure',
          dir: 'rtl',
          innerHTML: document.getElementById('speedbox-infrastructure-template').innerHTML
        });
      }
    }
  });
};

