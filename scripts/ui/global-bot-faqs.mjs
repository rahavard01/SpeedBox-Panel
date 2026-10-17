// Admin-only editor. Its API stores a single list used by every user bot.
export function createGlobalBotFaqEditor({ element: h, ref, mounted, unmounted, http, feedback }) {
  return {
    name: 'GlobalBotFaqEditor',
    setup() {
      const items = ref([]), loading = ref(true), loaded = ref(false), saving = ref(false), error = ref('');
      let alive = true, sequence = 0;
      const normalize = rows => (rows || []).map(row => ({ ...row, key: 'faq-' + row.id }));
      mounted(async () => {
        try {
          const result = await http.get('/admin/user-bot/faqs');
          if (alive) { items.value = normalize(result.data?.data); loaded.value = true; }
        } catch { if (alive) error.value = 'دریافت سوالات متداول انجام نشد؛ صفحه را تازه‌سازی کنید.'; }
        finally { if (alive) loading.value = false; }
      });
      unmounted(() => { alive = false; });
      function move(index, step) {
        const next = index + step;
        if (next < 0 || next >= items.value.length) return;
        const rows = [...items.value]; [rows[index], rows[next]] = [rows[next], rows[index]]; items.value = rows;
      }
      async function save() {
        if (saving.value || !loaded.value) return;
        error.value = '';
        if (items.value.some(row => !row.question.trim() || !row.answer.trim())) {
          error.value = 'سوال و پاسخ همهٔ موارد را وارد کنید.'; return;
        }
        saving.value = true;
        try {
          const payload = items.value.map(({ id, question, answer }) => ({ ...(id ? { id } : {}), question: question.trim(), answer: answer.trim(), enabled: true }));
          const result = await http.put('/admin/user-bot/faqs', { items: payload });
          if (alive) { items.value = normalize(result.data?.data); feedback?.('سوالات متداول ذخیره شد'); }
        } catch (failure) {
          if (alive) error.value = Object.values(failure?.response?.data?.errors || {}).flat()[0] || 'ذخیرهٔ سوالات متداول انجام نشد.';
        } finally { if (alive) saving.value = false; }
      }
      const iconButton = (icon, label, disabled, action) => h('button', { type: 'button', class: 'faq-order-btn' + (icon === 'delete' ? ' faq-delete-btn' : ''), 'aria-label': label, 'data-sb-tooltip': label, disabled: disabled || saving.value, onClick: action }, [
        h('svg', { viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', 'stroke-width': 2, 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'aria-hidden': 'true', focusable: 'false' }, [
          icon === 'delete' ? h('path', { d: 'M3 6h18M9 6V4h6v2M5 6l1 14h12l1-14M10 10v6M14 10v6' }) : h('polyline', { points: icon === 'up' ? '6 15 12 9 18 15' : '6 9 12 15 18 9' }),
        ]),
      ]);
      return () => h('section', { class: 'tariff-group-card userbot-faq-card', dir: 'rtl' }, [
        h('div', { class: 'tariff-group-head' }, [h('div', { class: 'tariff-group-title userbot-main-title' }, [h('label', null, [h('i', { class: 'fas fa-question-circle', style: { marginLeft: '5px' } }), 'سوالات متداول ربات کاربران'])])]),
        h('p', { class: 'bulk-hint warning faq-scope-note' }, [h('i', { class: 'fas fa-exclamation-circle', 'aria-hidden': 'true' }), h('span', null, 'سوالات متداول در همهٔ ربات‌های کاربران نمایش داده می‌شوند.')]),
        loading.value ? h('p', { class: 'faq-empty', role: 'status' }, 'در حال دریافت…') : null,
        !loading.value && !items.value.length ? h('div', { class: 'faq-empty faq-empty-state' }, [h('i', { class: 'fas fa-question-circle', 'aria-hidden': 'true' }), h('span', null, 'هنوز سوالی ثبت نشده است')]) : null,
        h('div', { class: 'userbot-faq-list' }, items.value.map((row, index) => h('div', { class: 'userbot-faq-row', key: row.key }, [
          h('div', { class: 'faq-row-controls' }, [
            h('span', { class: 'faq-number' }, 'سوال ' + (index + 1).toLocaleString('fa-IR')),
            iconButton('up', 'انتقال به بالا', index === 0, () => move(index, -1)),
            iconButton('down', 'انتقال به پایین', index === items.value.length - 1, () => move(index, 1)),
            iconButton('delete', 'حذف سوال', false, () => { items.value = items.value.filter(item => item !== row); }),
          ]),
          h('label', { class: 'faq-field' }, [h('span', null, 'سوال'), h('input', { type: 'text', class: 'form-input faq-input', value: row.question, maxlength: 128, disabled: saving.value, placeholder: 'متن سوال', onInput: event => { row.question = event.target.value; } })]),
          h('label', { class: 'faq-field' }, [h('span', null, 'پاسخ'), h('textarea', { class: 'form-input help-textarea faq-input', value: row.answer, maxlength: 3500, rows: 4, disabled: saving.value, placeholder: 'پاسخ سوال', onInput: event => { row.answer = event.target.value; } })]),
        ]))),
        error.value ? h('p', { class: 'faq-error', role: 'alert' }, error.value) : null,
        h('div', { class: 'page-actions faq-actions' }, [
          h('button', { type: 'button', class: 'btn-create-user1 faq-add', disabled: !loaded.value || saving.value || items.value.length >= 50, onClick: () => { items.value.push({ key: 'draft-' + ++sequence, question: '', answer: '' }); } }, [h('i', { class: 'fas fa-plus', 'aria-hidden': 'true' }), h('span', null, 'افزودن سوالات')]),
          h('button', { type: 'button', class: 'btn-create-user1 faq-save', disabled: !loaded.value || saving.value, onClick: save }, saving.value ? 'در حال ذخیره…' : 'ذخیره'),
        ]),
      ]);
    },
  };
}

