// The same UiTooltip used by Dashboard's new-user comparisons. Desktop hover
// closes normally; a touch tap toggles it and an outside tap dismisses it.
export function createPanelTooltip({ ref, mounted, unmounted, block, element, text, component, slots, transition, transitionComponent, empty }) {
  let sequence = 0;
  return {
    __name: 'UiTooltip',
    props: { openDelay: { type: Number, default: 80 }, closeDelay: { type: Number, default: 80 } },
    setup(props) {
      const open = ref(false), wrapper = ref(null), id = 'speedbox-panel-tip-' + ++sequence;
      let openTimer, closeTimer, touch = false, touchOpen = false;
      const clear = () => { clearTimeout(openTimer); clearTimeout(closeTimer); };
      const hide = () => { clear(); open.value = false; touchOpen = false; };
      const show = () => { if (touch || touchOpen) return; clear(); openTimer = setTimeout(() => { open.value = true; }, props.openDelay); };
      const close = () => { if (touchOpen) return; clear(); closeTimer = setTimeout(hide, props.closeDelay); };
      const outside = event => { if (wrapper.value && !wrapper.value.contains(event.target)) hide(); };
      mounted(() => document.addEventListener('pointerdown', outside, true));
      unmounted(() => { clear(); document.removeEventListener('pointerdown', outside, true); });
      function tap(event) {
        if (!touch && !window.matchMedia?.('(hover: none)').matches) return;
        if (!event.target.closest('.custom-tooltip-trigger')) return;
        event.stopPropagation(); clear(); open.value = !open.value; touchOpen = open.value;
      }
      function keyboard(event) {
        if (event.key === 'Escape') { event.stopPropagation(); event.preventDefault(); hide(); }
        else if (event.key === 'Enter' || event.key === ' ') {
          event.stopPropagation(); event.preventDefault(); clear(); open.value = !open.value;
        }
      }
      return context => {
        block();
        return element('span', { class: 'custom-tooltip-wrapper', ref: wrapper,
          onPointerenter: event => { if (event.pointerType === 'mouse') { touch = false; touchOpen = false; } },
          onPointerdown: event => { touch = event.pointerType === 'touch'; if (touch) clear(); },
          onMouseenter: show, onMouseleave: close, onFocusin: show, onFocusout: close, onClick: tap }, [
          text('span', { class: 'custom-tooltip-trigger', tabindex: '0', 'aria-describedby': open.value ? id : undefined, onKeydown: keyboard }, [slots(context.$slots, 'trigger')]),
          component(transitionComponent, { name: 'fade' }, { default: transition(() => [open.value ? (block(), element('div', { key: 0, id, class: 'custom-tooltip', role: 'tooltip' }, [slots(context.$slots, 'content'), text('span', { class: 'custom-tooltip-arrow', 'aria-hidden': 'true' })])) : empty('', true)]), _: 3 }),
        ]);
      };
    },
  };
}
