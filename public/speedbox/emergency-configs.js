/* Emergency links are independent from managed nodes and their traffic reports. */
window.SpeedboxEmergencyConfigs = {
  mount(root, { api, toast, openModal, closeModal, modalBody }) {
    let disposed = false;
    let rows = [];
    let position = 'bottom';
    let busy = false;
    let draggingId = null;
    const escape = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const number = value => Number(value || 0).toLocaleString('fa-IR');
    const icon = name => `<i class="fas fa-${name}" aria-hidden="true"></i>`;

    function render() {
      if (disposed) return;
      root.innerHTML = `<div class="emergency-page">
        <article class="panel-card emergency-placement">
          <div class="emergency-placement-info"><span class="emergency-placement-icon">${icon('layer-group')}</span><div><h2>جایگاه در لیست سابسکرایب</h2><p>این انتخاب برای مجموعهٔ کانفیگ‌های اضطراری اعمال می‌شود.</p></div></div>
          <div class="emergency-position-control" role="group" aria-label="جایگاه کانفیگ‌های اضطراری">
            ${[['top','ابتدای لیست','arrow-up'],['bottom','انتهای لیست','arrow-down']].map(([value,label,glyph]) => `<button type="button" class="emergency-position-button ${position===value?'is-selected':''}" data-position="${value}" aria-pressed="${position===value}" ${busy?'disabled':''}>${icon(glyph)}<span>${label}</span></button>`).join('')}
          </div>
        </article>
        <article class="panel-card emergency-list-panel">
          <div class="panel-head emergency-list-head"><div><h2>کانفیگ‌های اضطراری <span class="emergency-total">${number(rows.length)}</span></h2><p class="emergency-note">استفاده از این کانفیگ‌ها موجب کسر ترافیک اکانت کاربران نخواهد شد. (این کانفیگ‌ها مخصوص شرایط اضطراری هستند.)</p></div><button type="button" class="button primary" data-emergency-action="add">${icon('plus')} افزودن کانفیگ</button></div>
          ${rows.length ? `<div class="emergency-list">${rows.map(card).join('')}</div>` : `<div class="emergency-empty"><span class="emergency-empty-icon">${icon('life-ring')}</span><strong>هنوز کانفیگی ثبت نشده است</strong><span>برای اضافه کردن لینک، «افزودن کانفیگ» را بزنید.</span></div>`}
        </article>
      </div>`;
    }

    function card(row, index) {
      const host = row.host.includes(':') ? `[${row.host}]` : row.host;
      return `<article class="emergency-card ${row.show?'is-visible':'is-hidden'}" data-emergency-id="${row.id}">
        <div class="emergency-card-main"><div class="emergency-card-identity"><span class="emergency-card-icon">${icon('life-ring')}</span><div class="emergency-card-name"><h3 dir="auto">${escape(row.name)}</h3><span class="emergency-endpoint" dir="ltr">${escape(host)}:${row.port}</span></div></div>
          <div class="emergency-order" role="group" aria-label="ترتیب ${escape(row.name)}"><span class="emergency-order-number">${number(index+1)}</span><button type="button" class="emergency-icon-button emergency-drag" data-emergency-drag="${row.id}" draggable="true" aria-label="جابه‌جایی ${escape(row.name)}" title="برای تغییر ترتیب بکشید">${icon('grip-vertical')}</button><button type="button" class="emergency-icon-button" data-emergency-action="up" data-id="${row.id}" aria-label="${escape(row.name)} یک ردیف بالاتر" title="یک ردیف بالاتر" ${index===0||busy?'disabled':''}>${icon('chevron-up')}</button><button type="button" class="emergency-icon-button" data-emergency-action="down" data-id="${row.id}" aria-label="${escape(row.name)} یک ردیف پایین‌تر" title="یک ردیف پایین‌تر" ${index===rows.length-1||busy?'disabled':''}>${icon('chevron-down')}</button></div>
        </div>
        <div class="emergency-card-footer">
          <div class="emergency-display-group"><div class="emergency-display"><label class="switch-line ios"><input type="checkbox" data-emergency-show="${row.id}" aria-label="نمایش ${escape(row.name)} در لیست" ${row.show?'checked':''} ${busy?'disabled':''}></label></div>${window.SpeedboxNodeEditor.protocolBadge(row.protocol)}${(row.tags||[]).some(tag=>String(tag).toLowerCase()==='sbonly')?'<span class="emergency-client-tag" dir="ltr">SpeedBox</span>':''}</div>
          <div class="actions emergency-actions"><button type="button" class="button entity-edit" data-emergency-action="edit" data-id="${row.id}" aria-label="ویرایش ${escape(row.name)}" ${busy?'disabled':''}>${icon('pen')}<span>ویرایش</span></button><button type="button" class="button entity-delete" data-emergency-action="delete" data-id="${row.id}" aria-label="حذف ${escape(row.name)}" ${busy?'disabled':''}>${icon('trash-alt')}<span>حذف</span></button></div>
        </div>
      </article>`;
    }

    async function load() {
      if (!root.querySelector('.emergency-page')) root.innerHTML='<div class="loading" role="status"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i><span>در حال دریافت اطلاعات…</span></div>';
      const result = await api('/emergency-configs');
      if (disposed) return;
      rows = result.data || [];
      position = result.position === 'top' ? 'top' : 'bottom';
      render();
    }

    async function operation(callback) {
      if (disposed || busy) return;
      busy = true;
      root.setAttribute('aria-busy','true');
      try { await callback(); }
      catch (error) { if (!disposed && error.name!=='AbortError') toast(error.message,'error'); }
      finally { busy=false; root.removeAttribute('aria-busy'); render(); }
    }

    async function edit(row) {
      if (row) {
        const result = await api(`/emergency-configs/${row.id}`);
        if (disposed) return;
        row = result.data;
      }
      openModal(row?'ویرایش کانفیگ اضطراری':'افزودن کانفیگ اضطراری', `<form id="emergencyForm" autocomplete="off">
        <div class="field"><label for="emergencyName">نام نمایشی</label><input id="emergencyName" name="name" required maxlength="150" value="${escape(row?.name||'')}" placeholder="مثلاً مسیر اضطراری آلمان"></div>
        <div class="field emergency-link-field"><label for="emergencyLink">لینک کانفیگ</label><textarea id="emergencyLink" name="config_uri" required maxlength="16384" rows="5" dir="ltr" spellcheck="false" autocapitalize="off" placeholder="vless://…">${escape(row?.config_uri||'')}</textarea></div>
        <div class="field emergency-tags-field"><label for="emergencyTags">تگ کانفیگ</label><textarea id="emergencyTags" name="tags" rows="2" dir="ltr" maxlength="2100">${escape((row?.tags||[]).join('\n'))}</textarea></div>
        <div class="field emergency-modal-display"><label for="emergencyVisible">نمایش در لیست</label><label class="switch-line ios"><input id="emergencyVisible" name="show" type="checkbox" ${row?.show===false?'':'checked'}></label></div>
        <div class="emergency-form-error" role="alert" hidden></div>
        <div class="form-actions"><button class="button primary" type="submit">ذخیره</button></div>
      </form>`,{kind:'emergency'});
      const form=modalBody().querySelector('#emergencyForm');
      const errorBox=form.querySelector('.emergency-form-error');
      form.onsubmit=event=>{
        event.preventDefault();
        if (busy || !form.reportValidity()) return;
        const data={name:form.elements.name.value.trim(),config_uri:form.elements.config_uri.value.trim(),show:form.elements.show.checked,tags:[...new Set(form.elements.tags.value.split(/\r?\n/).map(tag=>tag.trim()).filter(Boolean))]};
        operation(async()=>{
          errorBox.hidden=true;
          try {
            await api(row?`/emergency-configs/${row.id}`:'/emergency-configs',{method:row?'PUT':'POST',body:JSON.stringify(data)});
          } catch(error) {
            if (!disposed && error.name!=='AbortError') {errorBox.textContent=error.message;errorBox.hidden=false;}
            throw error;
          }
          if (disposed) return;
          closeModal(); await load(); toast('کانفیگ ذخیره شد.');
        });
      };
      requestAnimationFrame(()=>{if(!disposed && form.isConnected)form.elements.name.focus();});
    }

    function remove(row) {
      openModal('حذف کانفیگ اضطراری',`<div class="emergency-delete-content"><span class="emergency-delete-icon">${icon('trash-alt')}</span><p>کانفیگ «${escape(row.name)}» حذف شود؟</p><small>در دریافت بعدی اشتراک، این کانفیگ نمایش داده نمی‌شود.</small></div><div class="form-actions"><button type="button" class="button server-delete-confirm" data-emergency-confirm-delete>حذف</button><button type="button" class="button cancel" data-close>انصراف</button></div>`,{kind:'emergency-delete'});
      modalBody().querySelector('[data-emergency-confirm-delete]').onclick=()=>operation(async()=>{
        await api(`/emergency-configs/${row.id}`,{method:'DELETE'});
        if (disposed) return;
        closeModal(); await load(); toast('کانفیگ حذف شد.');
      });
    }

    async function reorder(ids) {
      const result=await api('/emergency-configs/order',{method:'PUT',body:JSON.stringify({ids})});
      if(disposed)return;
      rows=result.data; render();
    }

    function click(event) {
      const button=event.target.closest('button');
      if (!button || !root.contains(button) || button.disabled || busy) return;
      if (button.dataset.position) {
        const next=button.dataset.position;
        if(next===position)return;
        operation(async()=>{await api('/emergency-configs/settings',{method:'PUT',body:JSON.stringify({position:next})});if(!disposed){position=next;render();toast('جایگاه کانفیگ‌ها ذخیره شد.');}});
        return;
      }
      const action=button.dataset.emergencyAction;
      const row=rows.find(item=>item.id===Number(button.dataset.id));
      if(action==='add'||action==='edit') operation(()=>edit(row));
      if(action==='delete'&&row)remove(row);
      if((action==='up'||action==='down')&&row)operation(async()=>{
        const ids=rows.map(item=>item.id), index=ids.indexOf(row.id), target=index+(action==='up'?-1:1);
        if(target<0||target>=ids.length)return;
        [ids[index],ids[target]]=[ids[target],ids[index]];
        await reorder(ids);
      });
    }

    function change(event) {
      const input=event.target.closest('[data-emergency-show]');
      if(!input)return;
      const row=rows.find(item=>item.id===Number(input.dataset.emergencyShow));
      if(!row)return;
      if(busy){input.checked=row.show;return;}
      const desired=input.checked;
      operation(async()=>{
        try {
          const result=await api(`/emergency-configs/${row.id}/flags`,{method:'PATCH',body:JSON.stringify({show:desired})});
          if(disposed)return;
          Object.assign(row,result.data);render();
        }catch(error){input.checked=row.show;throw error;}
      });
    }

    function dragStart(event) {
      const handle=event.target.closest('[data-emergency-drag]');
      if(!handle||busy){event.preventDefault();return;}
      draggingId=Number(handle.dataset.emergencyDrag);
      event.dataTransfer.effectAllowed='move';event.dataTransfer.setData('text/plain',String(draggingId));
      handle.closest('.emergency-card').classList.add('is-dragging');
    }
    function dragOver(event) {
      if(draggingId===null||busy)return;
      const card=event.target.closest('[data-emergency-id]');
      if(card){event.preventDefault();event.dataTransfer.dropEffect='move';}
    }
    function dragEnd() {draggingId=null;root.querySelectorAll('.is-dragging').forEach(card=>card.classList.remove('is-dragging'));}
    function drop(event) {
      const card=event.target.closest('[data-emergency-id]');
      if(!card||draggingId===null||busy)return;
      event.preventDefault();
      const from=draggingId,to=Number(card.dataset.emergencyId);dragEnd();
      if(from===to)return;
      operation(async()=>{const ids=rows.map(item=>item.id);ids.splice(ids.indexOf(from),1);ids.splice(ids.indexOf(to),0,from);await reorder(ids);});
    }
    const listeners={click,change,dragstart:dragStart,dragover:dragOver,dragend:dragEnd,drop};
    Object.entries(listeners).forEach(([event,handler])=>root.addEventListener(event,handler));
    return {load,dispose(){disposed=true;Object.entries(listeners).forEach(([event,handler])=>root.removeEventListener(event,handler));root.removeAttribute('aria-busy');}};
  }
};
