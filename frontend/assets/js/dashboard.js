/* AgentCore Dashboard JS
   Sidebar handled by Sneat menu.js + helpers.js
*/

(function () {
  'use strict';

  // ── Agent toggle (is_active switch) ─────────────────────────
  document.querySelectorAll('.agent-toggle').forEach(sw => {
    sw.addEventListener('change', function () {
      const id      = this.dataset.id;
      const active  = this.checked;
      fetch('/api/agent-save.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id, is_active: active }),
      }).catch(() => {
        this.checked = !active; // revert on error
      });
    });
  });

  // ── Edit agent modal — populate fields ────────────────────────
  document.querySelectorAll('.edit-agent-btn').forEach(btn => {
    btn.addEventListener('click', function () {
      try {
        const agent = JSON.parse(this.dataset.agent || '{}');
        const f = (id, val) => { const el = document.getElementById(id); if (el) el.value = val ?? ''; };
        f('agentId',       agent.id);
        f('agentName',     agent.name);
        f('agentChannel',  agent.channel);
        f('agentPhone',    agent.phone_number);
        f('agentPrompt',   agent.system_prompt);
        f('agentVoiceId',  agent.voice_id);
        f('agentLlmModel', agent.llm_model);
        const title = document.getElementById('agentModalTitle');
        if (title) title.textContent = 'Editar agente: ' + (agent.name || '');
      } catch(e) { console.warn('agent parse error', e); }
    });
  });

  // ── Toast helper ─────────────────────────────────────────────
  window.showToast = function(msg, type = 'success') {
    const container = document.getElementById('toast-container') || (() => {
      const c = document.createElement('div');
      c.id = 'toast-container';
      c.style.cssText = 'position:fixed;top:1rem;right:1rem;z-index:9999;display:flex;flex-direction:column;gap:.5rem';
      document.body.appendChild(c);
      return c;
    })();

    const toast = document.createElement('div');
    toast.className = `alert alert-${type === 'error' ? 'danger' : type} shadow py-2 px-3 mb-0`;
    toast.style.cssText = 'min-width:240px;animation:fadeIn .2s';
    toast.innerHTML = `<i class="bx bx-${type === 'error' ? 'error-circle' : 'check'} me-2"></i>${msg}`;
    container.appendChild(toast);
    setTimeout(() => toast.remove(), 3500);
  };

  // ── Confirm toast (reemplaza confirm()) ──────────────────────
  window.confirmToast = function(html, yesLabel = 'Confirmar', yesCls = 'btn-danger') {
    return new Promise(resolve => {
      const container = document.getElementById('toast-container') || (() => {
        const c = document.createElement('div');
        c.id = 'toast-container';
        c.style.cssText = 'position:fixed;top:1rem;right:1rem;z-index:9999;display:flex;flex-direction:column;gap:.5rem';
        document.body.appendChild(c);
        return c;
      })();
      const t = document.createElement('div');
      t.className = 'alert alert-warning shadow d-flex flex-column gap-2 mb-0';
      t.style.cssText = 'min-width:290px;animation:fadeIn .2s';
      t.innerHTML = `<div>${html}</div>
        <div class="d-flex gap-2">
          <button class="btn btn-sm ${yesCls} _ct_yes">${yesLabel}</button>
          <button class="btn btn-sm btn-outline-secondary _ct_no">Cancelar</button>
        </div>`;
      container.appendChild(t);
      const timer = setTimeout(() => { t.remove(); resolve(false); }, 8000);
      t.querySelector('._ct_yes').onclick = () => { clearTimeout(timer); t.remove(); resolve(true); };
      t.querySelector('._ct_no').onclick  = () => { clearTimeout(timer); t.remove(); resolve(false); };
    });
  };

  // ════════════════════════════════════════════════════════════
  // ── Notificaciones ──────────────────────────────────────────
  // ════════════════════════════════════════════════════════════

  const NOTIF_POLL_MS  = 15_000;   // polling cada 15 s
  const NOTIF_KEY      = 'ac_notif_last_id';   // localStorage

  // Iconos y colores por tipo
  const NOTIF_META = {
    new_conversation:    { icon: 'bx-phone-incoming', color: 'danger',  label: 'Llamada' },
    new_lead:            { icon: 'bx-user-plus',      color: 'success', label: 'Nuevo lead' },
    appointment_reminder:{ icon: 'bx-calendar-check', color: 'warning', label: 'Cita' },
    campaign_completed:  { icon: 'bx-megaphone',      color: 'info',    label: 'Campaña' },
  };

  const elBadge      = document.getElementById('notif-badge');
  const elList       = document.getElementById('notif-list');
  const elEmpty      = document.getElementById('notif-empty');
  const elMarkAll    = document.getElementById('btn-mark-all-read');
  const elToggle     = document.getElementById('notifToggle');

  if (!elBadge || !elList) return; // navbar no está en esta página

  let unreadCount = 0;
  let lastId      = parseInt(localStorage.getItem(NOTIF_KEY) || '0');
  let initialized = false;

  // ── Formatear fecha relativa ──────────────────────────────
  function relativeTime(isoStr) {
    const diff = Math.floor((Date.now() - new Date(isoStr)) / 1000);
    if (diff < 60)   return 'hace un momento';
    if (diff < 3600) return `hace ${Math.floor(diff / 60)} min`;
    if (diff < 86400)return `hace ${Math.floor(diff / 3600)} h`;
    return new Date(isoStr).toLocaleDateString('es-MX', { day:'numeric', month:'short' });
  }

  // ── Construir item de notificación ────────────────────────
  function buildItem(n) {
    const meta  = NOTIF_META[n.type] || { icon: 'bx-bell', color: 'secondary', label: '' };
    const li    = document.createElement('li');
    li.className = `list-group-item list-group-item-action px-3 py-2 notif-item${n.is_read ? '' : ' notif-unread'}`;
    li.dataset.id = n.id;
    li.style.cssText = 'cursor:pointer;border-radius:0;transition:background .15s';
    if (!n.is_read) li.style.background = 'rgba(105,108,255,.04)';

    li.innerHTML = `
      <div class="d-flex align-items-start gap-2">
        <span class="avatar avatar-sm flex-shrink-0 mt-1">
          <span class="avatar-initial rounded-circle bg-label-${meta.color}">
            <i class="bx ${meta.icon}" style="font-size:.85rem"></i>
          </span>
        </span>
        <div class="flex-grow-1 overflow-hidden">
          <div class="d-flex justify-content-between align-items-start gap-1">
            <span class="fw-semibold text-truncate" style="font-size:.83rem">${escHtml(n.title)}</span>
            ${!n.is_read ? '<span class="flex-shrink-0" style="width:7px;height:7px;border-radius:50%;background:#696cff;margin-top:5px"></span>' : ''}
          </div>
          ${n.body ? `<div class="text-muted text-truncate" style="font-size:.75rem">${escHtml(n.body)}</div>` : ''}
          <div class="text-muted" style="font-size:.7rem">${relativeTime(n.created_at)}</div>
        </div>
      </div>`;

    // Click → marcar leída + navegar si hay link
    li.addEventListener('click', () => {
      if (!n.is_read) markRead([n.id], li);
      if (n.link) window.location.href = n.link;
    });

    return li;
  }

  function escHtml(s) {
    return String(s || '')
      .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
      .replace(/"/g,'&quot;');
  }

  // ── Actualizar badge ──────────────────────────────────────
  function updateBadge(delta) {
    unreadCount = Math.max(0, unreadCount + delta);
    if (unreadCount > 0) {
      elBadge.textContent = unreadCount > 99 ? '99+' : unreadCount;
      elBadge.classList.remove('d-none');
    } else {
      elBadge.classList.add('d-none');
    }
    elMarkAll && (elMarkAll.style.display = unreadCount > 0 ? '' : 'none');
  }

  // ── Marcar como leídas ────────────────────────────────────
  async function markRead(ids, itemEl) {
    try {
      await fetch('/api/notifications-proxy.php?action=read', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify(ids ? { ids } : {}),
      });
    } catch { /* non-fatal */ }

    if (itemEl) {
      itemEl.classList.remove('notif-unread');
      itemEl.style.background = '';
      const dot = itemEl.querySelector('[style*="border-radius:50%"]');
      if (dot) dot.remove();
      updateBadge(-1);
    } else {
      // Marcar todas
      elList.querySelectorAll('.notif-unread').forEach(el => {
        el.classList.remove('notif-unread');
        el.style.background = '';
        const dot = el.querySelector('[style*="border-radius:50%"]');
        if (dot) dot.remove();
      });
      const prev = unreadCount;
      unreadCount = 0;
      updateBadge(0);
      _ = prev; // silence unused
    }
  }

  // ── Insertar notificaciones en el dropdown ─────────────────
  function insertNotifications(rows, prepend = true) {
    if (!rows.length) return;

    // Quitar el "Sin notificaciones"
    elEmpty && elEmpty.remove();

    rows.forEach(n => {
      const item = buildItem(n);
      if (prepend) {
        elList.insertBefore(item, elList.firstChild);
      } else {
        elList.appendChild(item);
      }
      if (!n.is_read) updateBadge(+1);
      // Actualizar lastId
      if (n.id > lastId) {
        lastId = n.id;
        localStorage.setItem(NOTIF_KEY, lastId);
      }
    });
  }

  // ── Toast para notificaciones nuevas ──────────────────────
  function toastNotif(n) {
    const meta = NOTIF_META[n.type] || { icon: 'bx-bell', color: 'primary' };
    const container = document.getElementById('toast-container') || (() => {
      const c = document.createElement('div');
      c.id = 'toast-container';
      c.style.cssText = 'position:fixed;top:1rem;right:1rem;z-index:9999;display:flex;flex-direction:column;gap:.5rem';
      document.body.appendChild(c);
      return c;
    })();

    const t = document.createElement('div');
    t.className = `alert alert-${meta.color} shadow py-2 px-3 mb-0 d-flex align-items-start gap-2`;
    t.style.cssText = 'min-width:260px;max-width:340px;animation:fadeIn .2s;cursor:pointer';
    t.innerHTML = `
      <i class="bx ${meta.icon} mt-1 flex-shrink-0"></i>
      <div>
        <div style="font-size:.83rem;font-weight:600">${escHtml(n.title)}</div>
        ${n.body ? `<div style="font-size:.75rem;opacity:.85">${escHtml(n.body)}</div>` : ''}
      </div>`;
    if (n.link) t.addEventListener('click', () => window.location.href = n.link);
    container.appendChild(t);
    setTimeout(() => t.remove(), 5000);
  }

  // ── Carga inicial ─────────────────────────────────────────
  async function loadInitial() {
    try {
      const res  = await fetch('/api/notifications-proxy.php?action=list');
      const rows = await res.json();
      if (!Array.isArray(rows)) return;
      insertNotifications([...rows].reverse(), false); // más antiguas abajo, recientes arriba
      initialized = true;
    } catch { /* non-fatal */ }
  }

  // ── Polling incremental ───────────────────────────────────
  async function poll() {
    if (!initialized) return;
    try {
      const res  = await fetch(`/api/notifications-proxy.php?action=poll&since=${lastId}`);
      const rows = await res.json();
      if (!Array.isArray(rows) || !rows.length) return;

      insertNotifications(rows, true); // prepend

      // Toasts solo si el dropdown está cerrado
      const isOpen = elToggle?.getAttribute('aria-expanded') === 'true';
      if (!isOpen) {
        rows.slice(0, 3).forEach(toastNotif); // máximo 3 toasts a la vez
      }
    } catch { /* non-fatal */ }
  }

  // ── "Marcar todo como leído" ──────────────────────────────
  elMarkAll?.addEventListener('click', () => markRead(null, null));

  // ── Marcar como leídas al abrir el dropdown ──────────────
  elToggle?.addEventListener('shown.bs.dropdown', () => {
    const unread = [...elList.querySelectorAll('.notif-unread')];
    if (!unread.length) return;
    const ids = unread.map(el => parseInt(el.dataset.id)).filter(Boolean);
    // Esperar 1.5s para que el usuario las vea
    setTimeout(() => markRead(ids, null), 1500);
  });

  // ── Arrancar ──────────────────────────────────────────────
  loadInitial();
  setInterval(poll, NOTIF_POLL_MS);

  // ════════════════════════════════════════════════════════════

})();
