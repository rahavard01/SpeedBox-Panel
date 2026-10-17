// Keep display labels separate from the numeric/null API contract.
export function createSpeedboxTariffLimitControl({ element, ref }) {
  const persian = value => String(value).replace(/[0-9]/g, digit => '۰۱۲۳۴۵۶۷۸۹'[Number(digit)]);
  const normalize = value => String(value).replace(/[۰-۹]/g, digit => String('۰۱۲۳۴۵۶۷۸۹'.indexOf(digit)))
    .replace(/[٠-٩]/g, digit => String('٠١٢٣٤٥٦٧٨٩'.indexOf(digit))).trim();
  const describe = value => Number(value) === 0 ? 'نامحدود' : persian(value) + ' کاربر';
  return {
    name: 'SpeedboxTariffLimitControl',
    props: { group: Object, options: Array },
    setup(props) {
      const editing = ref(false);
      return () => {
        const items = (props.group.items || []).filter(item => item.allowLink);
        const values = [...new Set(items.map(item => item.device_limit == null ? '' : String(item.device_limit)))];
        const mixed = values.length > 1, raw = mixed ? '' : values[0] || '';
        let text = mixed ? 'مقادیر متفاوت' : raw !== '' ? describe(raw) : 'پیش‌فرض پلن';
        if (!mixed && raw === '' && items.length) {
          const defaults = items.map(item => (props.options || []).find(plan => String(plan.id) === String(item.linked_plan_id)));
          if (defaults.every(Boolean)) {
            const limits = [...new Set(defaults.map(plan => plan.device_limit == null ? '0' : String(plan.device_limit)))];
            text = 'پیش‌فرض -' + (limits.length === 1 ? describe(limits[0]) : 'مقادیر متفاوت');
          }
        }
        return element('div', { class: 'tariff-connection-field' }, [element('input', {
          class: 'form-input tariff-limit-input', type: 'text', inputmode: 'numeric', dir: editing.value ? 'ltr' : 'rtl', autocomplete: 'off',
          value: editing.value ? persian(raw) : text,
          'aria-label': 'محدودیت اتصال هر کاربر در طرح ' + (props.group.name || ''),
          title: 'برای همه تعرفه‌های این طرح؛ خالی: پیش‌فرض پلن، صفر: نامحدود',
          placeholder: mixed ? 'مقادیر متفاوت' : 'پیش‌فرض پلن',
          onFocus: event => { editing.value = true; event.target.value = persian(raw); event.target.select(); },
          onInput: event => {
            const value = normalize(event.target.value);
            for (const item of items) item.device_limit = value === '' ? null : value;
            event.target.value = persian(value);
          },
          onBlur: () => { editing.value = false; },
          onKeydown: event => { if (event.key === 'Enter') event.target.blur(); },
        })]);
      };
    },
  };
}
