(() => {
  const { esc, toolbar, table, status, operationPending, scheduleResultExpiry } = window.SpeedboxVpnManagement;
  const statuses = {
    stopping: 'در حال غیرفعال‌سازی', deleting: 'در حال حذف',
  };
  const headers = ['نام', 'سرور خروجی', 'مسیر اتصال', 'ورودی', 'اکانت‌ها', 'کاربران آنلاین', 'وضعیت', 'عملیات'];
  const count = value => Number(value || 0).toLocaleString('fa-IR');

  window.SpeedboxAnyConnect = {
    mount(content, ui) {
      let disposed = false;
      let fetching = false;
      let editing = false;
      let acting = false;
      let timer;
      let resultTimer;
      let rows = [];
      let servers = [];

      function schedule() {
        clearTimeout(timer);
        if (disposed) return;
        timer = setTimeout(() => {
          if (document.hidden || editing || acting || ui.isBusy?.()) schedule();
          else void load(true);
        }, rows.some(operationPending) ? 1500 : 5000);
      }

      async function load(background = false) {
        if (disposed || fetching) return;
        clearTimeout(timer);
        fetching = true;
        try {
          if (!content.querySelector('#acSearch')) content.innerHTML = toolbar('AnyConnect', 'ac');
          content.querySelector('[data-ac-new]').onclick = () => openForm();
          content.querySelector('#acSearch').oninput = render;
          const results = await Promise.all([
            ui.api('/anyconnect', { background }),
            background ? null : ui.api('/servers'),
          ]);
          if (disposed) return;
          rows = results[0].data ?? [];
          if (results[1]) servers = results[1].data ?? [];
          if (!editing) render();
        } catch (error) {
          if (!disposed && !background && error.name !== 'AbortError') throw error;
        } finally {
          fetching = false;
          schedule();
        }
      }

      function controls(row) {
        const { id, enabled, status: state, last_error: error } = row;
        const deleting = state === 'deleting';
        const stopping = state === 'stopping';
        const disabled = acting || operationPending(row) || state === 'queued' || ((deleting || stopping) && !error);
        const attr = disabled ? 'disabled' : '';
        const button = (action, label, kind) => `<button class="button ${kind}" type="button" data-ac-action="${action}" data-id="${esc(id)}" ${attr}>${label}</button>`;
        const main = deleting ? '' : [
          button(enabled || stopping ? 'stop' : 'start', stopping ? (error ? 'تلاش دوباره غیرفعال‌سازی' : statuses.stopping) : enabled ? 'غیرفعال‌سازی' : 'نصب و فعال‌سازی', enabled || stopping ? 'entity-restart' : 'entity-install'),
          stopping ? '' : button('edit', 'ویرایش', 'entity-edit'),
          enabled ? button('update', 'همگام‌سازی', 'entity-report') : '',
        ].join('');
        return `<div class="actions">${main}<button type="button" class="button entity-report" data-ac-report="${esc(id)}">گزارش</button>${stopping ? '' : button('delete', deleting ? (error ? 'تلاش دوباره حذف' : statuses.deleting) : 'حذف', 'entity-delete')}</div>`;
      }

      const serverName = id => servers.find(server => String(server.id) === String(id))?.name ?? '—';

      function render() {
        const target = content.querySelector('[data-ac-list]');
        if (disposed || !target) return;
        const now = Date.now();
        const query = content.querySelector('#acSearch').value.trim().toLowerCase();
        const entries = rows.filter(row => [row.name, row.endpoint_host, row.endpoint_port, serverName(row.managed_server_id), serverName(row.ingress_server_id)]
          .join(' ').toLowerCase().includes(query));
        const markup = table(headers, entries.map(row => {
          return `<tr data-ac-row="${esc(row.id)}">
            <td><bdi dir="auto"><b>${esc(row.name)}</b></bdi></td>
            <td><bdi dir="auto">${esc(serverName(row.managed_server_id))}</bdi></td>
            <td>${row.ingress_server_id ? `تونل<small dir="auto">${esc(serverName(row.ingress_server_id))}</small>` : 'مستقیم'}</td>
            <td><bdi dir="ltr">${esc(row.endpoint_host)}:${esc(row.endpoint_port)}</bdi></td>
            <td>${count(row.accounts_count)}</td><td>${count(row.status === 'ready' ? row.online_sessions : 0)}</td>
            <td>${status(row, ui.operationStatus, now)}</td><td>${controls(row)}</td>
          </tr>`;
        }).join(''), query);
        const scroll = target.querySelector('.table-wrap')?.scrollLeft ?? 0;
        target.innerHTML = ui.entityLayout ? ui.entityLayout(markup, 'anyconnect') : markup;
        if (target.querySelector('.table-wrap')) target.querySelector('.table-wrap').scrollLeft = scroll;
        clearTimeout(resultTimer);
        resultTimer = scheduleResultExpiry(entries, now, render);
        target.querySelectorAll('[data-ac-report]').forEach(button => {
          button.onclick = () => ui.showDeployments(Number(button.dataset.acReport), 'anyconnect');
        });
        target.querySelectorAll('[data-ac-action]').forEach(button => button.onclick = () => {
          const row = rows.find(item => String(item.id) === button.dataset.id);
          const action = button.dataset.acAction;
          if (!row) return;
          if (action === 'edit') return openForm(row);
          if (action === 'delete' || action === 'stop') return confirmAction(row, action);
          void requestAction(row, action);
        });
      }

      async function requestAction(row, action, confirmation = null) {
        if (acting) return;
        acting = true;
        if (confirmation) confirmation.disabled = true;
        render();
        try {
          const url = '/anyconnect/' + row.id;
          if (action === 'delete') await ui.api(url, { method: 'DELETE', background: true });
          else await ui.api(url + '/actions', { method: 'POST', body: JSON.stringify({ action }), background: true });
          if (disposed) return;
          if (confirmation) ui.closeModal();
          await load(true);
        } catch (error) {
          if (!disposed && error.name !== 'AbortError') ui.toast(error.message, 'error');
        } finally {
          acting = false;
          if (confirmation) confirmation.disabled = false;
          render();
          schedule();
        }
      }

      function onClose() {
        editing = false;
        schedule();
      }

      function confirmAction(row, action) {
        editing = true;
        clearTimeout(timer);
        const title = action === 'delete' ? 'حذف سرویس' : 'غیرفعال‌سازی سرویس';
        ui.openModal(title, `<p dir="rtl">${title} «<bdi dir="auto">${esc(row.name)}</bdi>» را تأیید کنید.</p>
          <div class="form-actions"><button type="button" class="button server-delete-confirm" data-ac-confirm>تأیید</button>
          <button type="button" class="button cancel" data-close>انصراف</button></div>`, { kind: 'server-delete', onClose });
        const button = ui.modalBody().querySelector('[data-ac-confirm]');
        button.onclick = () => requestAction(row, action, button);
      }

      function openForm(row) {
        editing = true;
        clearTimeout(timer);
        const options = selected => servers.map(server => `<option value="${esc(server.id)}" ${String(server.id) === String(selected) ? 'selected' : ''}>${esc(server.name || server.host)}</option>`).join('');
        const field = (name, label, value = '', type = 'text', attrs = '') =>
          `<div class="field"><label for="ac-${name}">${label}</label>
           <input class="form-input" id="ac-${name}" name="${name}" value="${esc(value)}" type="${type}" ${attrs}></div>`;
        const locked = row ? 'disabled' : '';
        let suffix = 1;
        const names = new Set(rows.map(item => item.name));
        while (names.has('AnyConnect ' + suffix)) suffix++;
        ui.openModal(row ? 'ویرایش \u2066AnyConnect\u2069' : 'ساخت \u2066AnyConnect\u2069', `<form data-ac-form>
          <div class="form-grid">
          ${field('name', 'نام', row?.name ?? 'AnyConnect ' + suffix, 'text', 'required maxlength="100" dir="auto"')}
          <div class="wg-field-row">
            <div class="field"><label for="ac-server">سرور خروجی</label><select class="form-input" id="ac-server" name="managed_server_id" required ${locked}>
              <option value="">انتخاب</option>${options(row?.managed_server_id)}</select></div>
            <div class="field"><label for="ac-mode">مسیر اتصال</label><select class="form-input" id="ac-mode" name="mode" ${locked}>
              <option value="direct">مستقیم</option><option value="tunnel" ${row?.ingress_server_id ? 'selected' : ''}>تونل</option></select></div>
          </div>
          <div class="wg-field-row" data-ac-tunnel>
            <div class="field"><label for="ac-ingress">سرور ورودی ایران</label><select class="form-input" id="ac-ingress" name="ingress_server_id" ${locked}>
              <option value="">انتخاب</option>${options(row?.ingress_server_id)}</select></div>
            ${field('link_port', 'پورت ارتباط دو سرور', row?.link_port, 'number', 'min="1024" max="65535" placeholder="خودکار" ' + locked)}
          </div>
          <div class="wg-field-row">
            ${field('endpoint_host', 'دامنه ورودی', row?.endpoint_host, 'text', 'required dir="ltr" ' + locked)}
            ${field('endpoint_port', 'پورت ورودی', row?.endpoint_port ?? 443, 'number', 'required min="1" max="65535" ' + locked)}
          </div>
          <details class="wg-advanced" data-ac-cert ${row ? '' : 'open'}><summary>گواهی TLS</summary>
            <div class="ac-certificate-form">
              <div class="field"><label for="ac-cert-mode">روش گواهی</label>
                <select id="ac-cert-mode" name="cert_mode">
                  <option value="dns">DNS-01 (ACME)</option>
                  <option value="http">HTTP-01 (ACME)</option>
                  <option value="content">محتوای گواهی و کلید خصوصی</option>
                  ${row && (row.certificate?.mode ?? 'existing') === 'existing' ? '<option value="existing">گواهی فعلی (موجود روی سرور)</option>' : ''}
                </select>
              </div>
              <div class="ac-certificate-settings" data-ac-cert-settings></div>
            </div>
          </details>
          <details class="wg-advanced" data-ac-advanced><summary>تنظیمات پیشرفته</summary>
            <div class="wg-field-row">
              ${field('listen_port', 'پورت خروجی (<bdi dir="ltr">TCP / UDP</bdi>)', row?.listen_port ?? 443, 'number', 'required min="1" max="65535" ' + locked)}
              ${field('network', 'شبکه داخلی IPv4', row?.network, 'text', 'dir="ltr" placeholder="خودکار" ' + locked)}
              <div data-ac-tunnel-network>${field('link_network', 'شبکه ارتباط دو سرور', row?.link_network, 'text', 'placeholder="خودکار" disabled')}</div>
              ${field('mtu', 'MTU', row?.mtu ?? 1300, 'number', 'required min="1280" max="1500"')}
              ${field('dns', 'DNS', (row?.dns ?? ['1.1.1.1', '8.8.8.8']).join(', '), 'text', 'required dir="ltr"')}
            </div>
          </details>
          </div><div class="form-actions"><button type="submit" class="button primary">${row ? 'ذخیره تغییرات' : 'ساخت'}</button></div></form>`, { kind: 'anyconnect', onClose });
        const form = ui.modalBody().querySelector('[data-ac-form]');
        const { mode, ingress_server_id: ingress, link_port: linkPort, cert_mode: certMode,
          listen_port: listen, endpoint_port: endpoint } = form.elements;
        const savedCertificate = row?.certificate;
        certMode.value = savedCertificate?.mode ?? (row ? 'existing' : 'dns');
        const dnsEditor = window.SpeedboxCertificateDns;
        const dnsConfig = {
          dns_provider: savedCertificate?.dns_provider ?? '',
          has_saved_secret: savedCertificate?.mode === 'dns',
          saved_secret_mode: savedCertificate?.mode,
          saved_dns_fields: savedCertificate?.saved_dns_fields ?? [],
        };
        const certSettings = form.querySelector('[data-ac-cert-settings]');
        const drawDns = () => {
          const container = certSettings.querySelector('[data-ac-dns]');
          const options = { context: 'tunnel', prefix: 'ac-dns' };
          container.innerHTML = dnsEditor.render(dnsConfig, options);
          dnsEditor.bind(container, dnsConfig, { ...options, onChange: drawDns });
          ui.enhanceControls?.(container);
        };
        const drawCertificate = () => {
          const mode = certMode.value;
          if (mode === 'dns' || mode === 'http') {
            certSettings.innerHTML = field('cert_email', 'ایمیل ACME', savedCertificate?.email, 'email', 'placeholder="اختیاری"')
              + (mode === 'dns' ? '<div data-ac-dns></div>'
                : field('cert_http_port', 'پورت چالش HTTP', savedCertificate?.http_port ?? 80, 'number', 'required min="1" max="65535"')
                  + '<p class="plan-form-hint" dir="rtl">Let’s Encrypt به پورت عمومی ۸۰ دامنهٔ ورودی وصل می‌شود. اگر پورت چالش را تغییر می‌دهید، درخواست‌های چالش باید از پورت ۸۰ به پورت انتخابی هدایت شوند.</p>');
            if (mode === 'dns') drawDns();
          } else if (mode === 'content') {
            const saved = savedCertificate?.mode === 'content';
            const area = (name, label) => `<div class="field"><label for="ac-${name}">${label}</label>
              <textarea class="form-input" id="ac-${name}" name="${name}" dir="ltr" spellcheck="false" rows="4"
                ${saved ? 'placeholder="ذخیره شده است"' : 'required'}></textarea></div>`;
            certSettings.innerHTML = area('cert_content', 'محتوای گواهی') + area('key_content', 'کلید خصوصی');
          } else {
            certSettings.innerHTML = '<p class="plan-form-hint" dir="rtl">گواهی موجود برای دامنهٔ ورودی از روی سرور خروجی خوانده و هنگام نصب بررسی می‌شود.</p>';
          }
          ui.enhanceControls?.(certSettings);
        };
        certMode.onchange = drawCertificate;
        drawCertificate();
        const adjust = () => {
          const tunneled = mode.value === 'tunnel';
          form.querySelector('[data-ac-tunnel]').hidden = !tunneled;
          form.querySelector('[data-ac-tunnel-network]').hidden = !tunneled;
          ingress.disabled = linkPort.disabled = !!row || !tunneled;
          ingress.required = !row && tunneled;
          if (!row && !tunneled) listen.value = endpoint.value;
        };
        mode.onchange = adjust;
        endpoint.oninput = () => { if (mode.value === 'direct') listen.value = endpoint.value; };
        listen.oninput = () => { if (mode.value === 'direct') endpoint.value = listen.value; };
        adjust();
        form.addEventListener('invalid', event => {
          const advanced = event.target.closest('details');
          if (advanced) advanced.open = true;
        }, true);
        form.onsubmit = async event => {
          event.preventDefault();
          const body = Object.fromEntries(new FormData(form));
          body.certificate = { mode: certMode.value };
          if (certMode.value === 'dns' || certMode.value === 'http') body.certificate.email = body.cert_email;
          if (certMode.value === 'http') body.certificate.http_port = Number(body.cert_http_port);
          if (certMode.value === 'dns') {
            dnsEditor.capture(certSettings, dnsConfig, { context: 'tunnel' });
            Object.assign(body.certificate, { dns_provider: dnsConfig.dns_provider, dns_env: dnsConfig.dns_env ?? {} });
          } else if (certMode.value === 'content') {
            Object.assign(body.certificate, { cert_content: body.cert_content, key_content: body.key_content });
          }
          for (const key of ['mode', 'cert_mode', 'cert_email', 'cert_http_port', 'cert_content', 'key_content']) delete body[key];
          body.dns = body.dns.split(/[,،]/).map(item => item.trim()).filter(Boolean);
          const button = form.querySelector('[type=submit]');
          button.disabled = true;
          try {
            await ui.api(row ? '/anyconnect/' + row.id : '/anyconnect', {
              method: row ? 'PUT' : 'POST', body: JSON.stringify(body),
            });
            if (disposed) return;
            ui.closeModal();
            await load(true);
          } catch (error) {
            if (!disposed && error.name !== 'AbortError') ui.toast(error.message, 'error');
          } finally {
            button.disabled = false;
          }
        };
        ui.enhanceControls?.(form);
      }

      return { load, redraw: render, dispose() { disposed = true; clearTimeout(timer); clearTimeout(resultTimer); } };
    },
  };
})();
