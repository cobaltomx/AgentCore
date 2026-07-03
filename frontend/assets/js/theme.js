/**
 * AgentCore — Theme + Sidebar UX
 * - Dark / light toggle (persistido en localStorage)
 * - Sidebar collapse persistente (clase html.layout-menu-collapsed)
 * - Grupos colapsables del menú (clase .menu-group.open)
 * - Chart.js dark mode: actualiza defaults + instancias registradas en window.AC.charts
 *
 * El bootstrap en head.php ya aplica las clases ANTES del render,
 * aquí se gestionan los eventos y la sincronización del icono.
 */
(function () {
  'use strict';

  const LS_THEME    = 'agentcore-theme';
  const LS_COLLAPSE = 'agentcore-menu-collapsed';
  const LS_GROUPS   = 'agentcore-menu-groups'; // JSON { groupId: true|false }

  const html = document.documentElement;

  // Namespace global para que otras páginas puedan registrar instancias Chart.js
  window.AC = window.AC || {};
  window.AC.charts = window.AC.charts || {};

  // ── Chart.js dark mode ──────────────────────────────────────
  // Actualiza los defaults globales de Chart.js y todas las instancias
  // registradas en window.AC.charts al cambiar el tema.
  function applyChartTheme(theme) {
    if (typeof Chart === 'undefined') return;
    const isDark    = theme === 'dark';
    const textColor = isDark ? '#9298a6' : '#697a8d';
    const gridColor = isDark ? '#353947' : '#e7e7e7';
    const doughnutBorder = isDark ? '#232633' : '#ffffff';

    // Defaults para nuevas instancias
    Chart.defaults.color       = textColor;
    Chart.defaults.borderColor = gridColor;

    // Actualizar instancias ya creadas (registradas por las páginas)
    Object.values(window.AC.charts).forEach(function (chart) {
      if (!chart || typeof chart.update !== 'function') return;

      // Escalas: ticks + grid
      var scales = chart.options.scales || {};
      Object.keys(scales).forEach(function (key) {
        var scale = scales[key];
        if (!scale) return;
        if (!scale.ticks) scale.ticks = {};
        scale.ticks.color = textColor;
        if (!scale.grid) scale.grid = {};
        if (scale.grid.display !== false) scale.grid.color = gridColor;
      });

      // Leyenda
      var legend = (chart.options.plugins || {}).legend || {};
      if (!legend.labels) legend.labels = {};
      legend.labels.color = textColor;

      // Borde de segmentos (doughnut / pie): actualizar solo los que
      // corresponden al color anterior del otro modo para no pisar
      // colores de borde intencionales.
      chart.data.datasets.forEach(function (ds) {
        if (ds.borderColor === '#fff' || ds.borderColor === '#ffffff' ||
            ds.borderColor === '#232633' || ds.borderColor === '#1a1d29') {
          ds.borderColor = doughnutBorder;
        }
      });

      chart.update('none'); // sin animación al cambiar tema
    });
  }

  // ── 1. Tema claro / oscuro ──────────────────────────────────
  const themeBtn  = document.getElementById('themeToggleBtn');
  const themeIcon = document.getElementById('themeToggleIcon');

  function applyTheme(theme) {
    const t = theme === 'dark' ? 'dark' : 'light';
    html.classList.remove('light-style', 'dark-style');
    html.classList.add(t === 'dark' ? 'dark-style' : 'light-style');
    html.setAttribute('data-bs-theme', t);
    localStorage.setItem(LS_THEME, t);
    if (themeIcon) {
      themeIcon.className = 'bx bx-sm ' + (t === 'dark' ? 'bx-sun' : 'bx-moon');
    }
    applyChartTheme(t);
  }

  // Sincronizar icono al iniciar (Chart.js aún no está cargado aquí,
  // pero applyChartTheme hace guard de typeof Chart === 'undefined')
  applyTheme(localStorage.getItem(LS_THEME) || 'light');

  if (themeBtn) {
    themeBtn.addEventListener('click', () => {
      const cur = localStorage.getItem(LS_THEME) || 'light';
      applyTheme(cur === 'dark' ? 'light' : 'dark');
    });
  }

  // ── 2. Sidebar collapse (botón navbar + botón sidebar) ──────
  // Sneat tiene dos clases distintas:
  //   - html.layout-menu-collapsed → controla padding del contenido
  //   - #layout-menu.menu-collapsed → controla ancho del sidebar +
  //     ocultar texto. Usa :hover CSS nativo para expandir al pasar
  //     el cursor (no necesita .layout-menu-hover).
  // Helpers.setCollapsed() solo aplica la primera, hay que poner la
  // segunda manualmente.
  const sidebarEl = document.getElementById('layout-menu');

  // En desktop, Sneat NO escribe `layout-menu-collapsed` (lo confirmamos
  // leyendo helpers.js — solo hace `_hasClass('layout-menu-collapsed')`
  // para `isCollapsed()`). Gestionamos las dos clases nosotros mismos
  // y NO llamamos a Helpers.setCollapsed, que dispara animaciones y
  // un `setMenuHoverState(false)` que confundía el estado.
  function applyCollapse(collapsed) {
    if (collapsed) {
      html.classList.add('layout-menu-collapsed');
      if (sidebarEl) sidebarEl.classList.add('menu-collapsed');
      localStorage.setItem(LS_COLLAPSE, '1');
    } else {
      html.classList.remove('layout-menu-collapsed');
      html.classList.remove('layout-menu-hover');
      if (sidebarEl) sidebarEl.classList.remove('menu-collapsed');
      localStorage.setItem(LS_COLLAPSE, '0');
    }
  }

  // Aplicar preferencia guardada (head.php no la aplica para evitar flash
  // con cosas que requieran cargar Sneat antes)
  if (localStorage.getItem(LS_COLLAPSE) === '1') {
    html.classList.add('layout-menu-collapsed');
    if (sidebarEl) sidebarEl.classList.add('menu-collapsed');
  }

  function toggleSidebar(e) {
    if (e) { e.preventDefault(); e.stopPropagation(); }
    const willCollapse = !html.classList.contains('layout-menu-collapsed');
    applyCollapse(willCollapse);

    // Al COLAPSAR: el cursor sigue sobre el pin (que es hijo del aside),
    // así que :hover del aside está activo → CSS de Sneat lo expande de
    // nuevo. Truco: desactivar pointer-events del aside ~400ms, suficiente
    // para que el cursor "salga" del area del aside colapsado (84px) y
    // :hover quede off naturalmente. Tras eso restauramos.
    if (willCollapse && sidebarEl) {
      sidebarEl.style.pointerEvents = 'none';
      setTimeout(() => { sidebarEl.style.pointerEvents = ''; }, 400);
    }
  }

  // Event delegation en document — robusto contra re-mounts del DOM
  // y contra clicks sobre hijos (el <i> del icono dentro del <a>).
  document.addEventListener('click', function (e) {
    const trigger = e.target.closest('#menuToggleBtn, #navbarSidebarToggle');
    if (trigger) toggleSidebar(e);
  });

  // Hover sobre el sidebar colapsado: aplicar .layout-menu-hover al
  // <html> para que las reglas de Sneat que ocultan el brand y otras
  // decoraciones se desactiven (Sneat sólo escucha esta clase, no :hover
  // CSS). El ancho del sidebar lo controla el :hover sobre .menu-collapsed.
  if (sidebarEl) {
    let leaveTimer = null;
    sidebarEl.addEventListener('mouseenter', () => {
      if (!html.classList.contains('layout-menu-collapsed')) return;
      if (leaveTimer) { clearTimeout(leaveTimer); leaveTimer = null; }
      html.classList.add('layout-menu-hover');
    });
    sidebarEl.addEventListener('mouseleave', () => {
      if (!html.classList.contains('layout-menu-collapsed')) return;
      leaveTimer = setTimeout(() => {
        html.classList.remove('layout-menu-hover');
      }, 120);
    });
  }

  // ── 3. Grupos colapsables del menú ──────────────────────────
  // Sneat (Menu class) ya maneja el click sobre .menu-toggle y la animación
  // de .menu-sub. Aquí solo:
  //   1) aplicamos la preferencia guardada al cargar (clase .open),
  //   2) escuchamos cambios de .open con MutationObserver para persistir.

  function loadGroupsState() {
    try { return JSON.parse(localStorage.getItem(LS_GROUPS) || '{}'); }
    catch (_) { return {}; }
  }
  function saveGroupsState(state) {
    localStorage.setItem(LS_GROUPS, JSON.stringify(state));
  }

  const groupsState = loadGroupsState();

  document.querySelectorAll('.menu-item.menu-group').forEach(group => {
    const id = group.dataset.groupId;
    if (!id) return;

    // 1) Aplicar preferencia guardada (si existe) — sobreescribe el default
    if (Object.prototype.hasOwnProperty.call(groupsState, id)) {
      const wantOpen = !!groupsState[id];
      const isOpen   = group.classList.contains('open');
      if (wantOpen !== isOpen) group.classList.toggle('open', wantOpen);
    }

    // 2) Persistir cuando Sneat cambie la clase .open
    const obs = new MutationObserver(() => {
      const open = group.classList.contains('open');
      const s = loadGroupsState();
      if (s[id] !== open) { s[id] = open; saveGroupsState(s); }
    });
    obs.observe(group, { attributes: true, attributeFilter: ['class'] });
  });

})();
