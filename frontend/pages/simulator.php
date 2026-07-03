<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/head.php';
require_once __DIR__ . '/../includes/sidebar.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/footer.php';

requireAuth();

// Solo admins pueden acceder al simulador
if (!isAdmin()) {
    header('Location: /index.php');
    exit;
}

// Estado actual del simulador
$simStatus   = apiGet('/simulator/status');
$isReady     = (bool)($simStatus['is_ready']        ?? false);
$canApprove  = (bool)($simStatus['can_approve']      ?? false);
$testedCount = (int)($simStatus['scenarios_tested']  ?? 0);
$minScenarios = (int)($simStatus['min_scenarios']    ?? 3);

renderHead('Simulador de Bot');
?>

<div class="layout-wrapper layout-content-navbar">
<div class="layout-container">

<?php renderSidebar('simulator'); ?>

<div class="layout-page">
<?php renderNavbar('Simulador de Bot'); ?>

<div class="content-wrapper">
<div class="container-xxl flex-grow-1 container-p-y">

  <!-- Header -->
  <div class="row mb-4">
    <div class="col-12">
      <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
        <div>
          <h4 class="fw-bold mb-1">
            <i class="bx bx-bot text-primary me-2"></i>Simulador de Respuestas del Bot
          </h4>
          <p class="text-muted mb-0">
            Prueba cómo responde tu agente IA antes de activarlo con clientes reales.
            <?php if ($isReady): ?>
              <span class="badge bg-success ms-2"><i class="bx bx-check-circle me-1"></i>Bot Activo</span>
            <?php else: ?>
              <span class="badge bg-warning text-dark ms-2"><i class="bx bx-time-five me-1"></i>Pendiente de aprobación</span>
            <?php endif; ?>
          </p>
        </div>
        <div class="d-flex gap-2 align-items-center">
          <span class="text-muted small" id="tested-counter">
            <i class="bx bx-check-circle text-success me-1"></i>
            <span id="tested-num"><?= $testedCount ?></span>/<?= $minScenarios ?> escenarios probados
          </span>
          <?php if (!$isReady): ?>
          <button class="btn btn-success btn-sm" id="btn-approve"
                  <?= $canApprove ? '' : 'disabled' ?>
                  title="<?= $canApprove ? 'Aprobar bot y activar webhooks' : 'Prueba al menos ' . $minScenarios . ' escenarios primero' ?>">
            <i class="bx bx-rocket me-1"></i>Aprobar y Activar
          </button>
          <?php else: ?>
          <span class="badge bg-success py-2 px-3">
            <i class="bx bx-rocket me-1"></i>Bot Activado
          </span>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- Layout principal: 3 columnas -->
  <div class="row g-3" id="sim-layout">

    <!-- ── Columna izquierda: Escenarios ──────────────────────────────── -->
    <div class="col-lg-3 col-md-4">

      <!-- Escenarios sugeridos -->
      <div class="card mb-3">
        <div class="card-header py-2 border-bottom">
          <h6 class="mb-0 fw-semibold"><i class="bx bx-list-check me-1 text-primary"></i>Escenarios</h6>
          <small class="text-muted" id="industry-label"></small>
        </div>
        <div class="card-body p-0">
          <div id="scenarios-list" class="list-group list-group-flush" style="max-height:400px;overflow-y:auto">
            <div class="text-center py-4 text-muted">
              <div class="spinner-border spinner-border-sm" role="status"></div>
            </div>
          </div>
        </div>
      </div>

      <!-- Chat libre -->
      <div class="card mb-3">
        <div class="card-body py-2 px-3">
          <button class="btn btn-outline-primary btn-sm w-100" id="btn-free-chat">
            <i class="bx bx-chat me-1"></i>Chat Libre
          </button>
        </div>
      </div>

      <!-- Ajustar KB / Config -->
      <div class="card">
        <div class="card-body py-2 px-3">
          <p class="small text-muted mb-2">¿Las respuestas no son correctas?</p>
          <a href="/pages/knowledge-base.php" class="btn btn-outline-secondary btn-sm w-100 mb-2">
            <i class="bx bx-brain me-1"></i>Ajustar Knowledge Base
          </a>
          <a href="/pages/agents.php" class="btn btn-outline-secondary btn-sm w-100">
            <i class="bx bx-bot me-1"></i>Editar Agente
          </a>
        </div>
      </div>

    </div>

    <!-- ── Columna central: Chat ───────────────────────────────────────── -->
    <div class="col-lg-6 col-md-8">
      <div class="card h-100" style="min-height:500px">
        <!-- Header del chat -->
        <div class="card-header d-flex align-items-center gap-2 py-2 border-bottom">
          <div class="avatar avatar-sm">
            <span class="avatar-initial rounded-circle bg-label-primary">
              <i class="bx bx-bot" style="font-size:.9rem"></i>
            </span>
          </div>
          <div class="flex-grow-1">
            <div class="fw-semibold" style="font-size:.85rem" id="chat-title">Selecciona un escenario</div>
            <div class="text-muted" style="font-size:.72rem" id="chat-subtitle">o escribe en chat libre</div>
          </div>
          <button class="btn btn-sm btn-outline-secondary" id="btn-reset-chat" title="Nueva conversación">
            <i class="bx bx-refresh"></i>
          </button>
        </div>

        <!-- Mensajes -->
        <div class="card-body p-3 overflow-auto" id="chat-messages"
             style="flex:1;min-height:340px;max-height:420px;display:flex;flex-direction:column;gap:.75rem">
          <!-- Estado inicial -->
          <div class="text-center text-muted py-5" id="chat-empty">
            <i class="bx bx-message-dots d-block mb-2" style="font-size:2.5rem;opacity:.3"></i>
            <p class="mb-0 small">Selecciona un escenario de la izquierda<br>o usa el chat libre para probar tu bot</p>
          </div>
        </div>

        <!-- Indicador de escritura -->
        <div class="px-3 pb-1 d-none" id="typing-indicator">
          <div class="d-flex align-items-center gap-2">
            <div class="avatar avatar-xs">
              <span class="avatar-initial rounded-circle bg-label-primary" style="font-size:.6rem">
                <i class="bx bx-bot"></i>
              </span>
            </div>
            <div class="typing-dots">
              <span></span><span></span><span></span>
            </div>
          </div>
        </div>

        <!-- Input -->
        <div class="card-footer border-top pt-2 pb-2">
          <div class="d-flex gap-2 align-items-end">
            <textarea id="chat-input" class="form-control" rows="2"
                      placeholder="Escribe un mensaje como si fueras un cliente..."
                      style="resize:none;font-size:.875rem" disabled></textarea>
            <button class="btn btn-primary flex-shrink-0" id="btn-send" disabled>
              <i class="bx bx-send"></i>
            </button>
          </div>
          <div class="d-flex justify-content-between mt-1">
            <small class="text-muted" id="session-id-label"></small>
            <small class="text-muted" id="token-info"></small>
          </div>
        </div>
      </div>
    </div>

    <!-- ── Columna derecha: Seguridad ─────────────────────────────────── -->
    <div class="col-lg-3 d-none d-lg-block">

      <!-- Panel de seguridad -->
      <div class="card mb-3">
        <div class="card-header py-2 border-bottom d-flex align-items-center gap-2">
          <i class="bx bx-shield-alt-2 text-success"></i>
          <h6 class="mb-0 fw-semibold" style="font-size:.83rem">Zona de Protección</h6>
          <span class="badge bg-success ms-auto" id="security-status-badge">Activa</span>
        </div>
        <div class="card-body py-2 px-3">
          <p class="small text-muted mb-2">
            El sistema analiza cada mensaje en busca de intentos de inyección o extracción de datos sensibles.
          </p>
          <div class="d-flex justify-content-between align-items-center mb-1">
            <small class="fw-semibold">Intentos bloqueados</small>
            <span class="badge bg-label-danger" id="blocked-count">0</span>
          </div>
          <div class="progress mb-3" style="height:3px">
            <div class="progress-bar bg-success" id="security-bar" style="width:100%"></div>
          </div>

          <!-- Lista de capas -->
          <div class="small">
            <div class="d-flex align-items-center gap-2 mb-1">
              <i class="bx bx-check-circle text-success"></i>
              <span>Pre-filtro de inyección</span>
            </div>
            <div class="d-flex align-items-center gap-2 mb-1">
              <i class="bx bx-check-circle text-success"></i>
              <span>Meta-prompt inviolable</span>
            </div>
            <div class="d-flex align-items-center gap-2 mb-1">
              <i class="bx bx-check-circle text-success"></i>
              <span>Post-filtro de respuesta</span>
            </div>
            <div class="d-flex align-items-center gap-2">
              <i class="bx bx-check-circle text-success"></i>
              <span>Audit log en tiempo real</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Eventos de seguridad -->
      <div class="card">
        <div class="card-header py-2 border-bottom">
          <h6 class="mb-0 fw-semibold" style="font-size:.83rem">
            <i class="bx bx-shield-x text-danger me-1"></i>Intentos detectados
          </h6>
        </div>
        <div class="card-body p-0">
          <div id="security-events-list" style="max-height:300px;overflow-y:auto">
            <div class="text-center py-3 text-muted small" id="no-events">
              <i class="bx bx-shield-check d-block mb-1" style="font-size:1.5rem;opacity:.4"></i>
              Sin intentos detectados
            </div>
          </div>
        </div>
      </div>

    </div>

  </div><!-- /row -->

</div><!-- /container -->
<?php renderFooter(); ?>
</div><!-- /content-wrapper -->
</div><!-- /layout-page -->
</div><!-- /layout-container -->
</div><!-- /layout-wrapper -->

<!-- ── Estilos del simulador ───────────────────────────────────────────────── -->
<style>
/* Typing indicator */
.typing-dots { display:flex; align-items:center; gap:3px; padding:6px 10px; background:var(--bs-light); border-radius:12px; width:fit-content; }
.typing-dots span { width:6px; height:6px; border-radius:50%; background:#696cff; animation:typing-bounce 1.2s infinite; }
.typing-dots span:nth-child(2) { animation-delay:.2s; }
.typing-dots span:nth-child(3) { animation-delay:.4s; }
@keyframes typing-bounce { 0%,80%,100%{transform:translateY(0)} 40%{transform:translateY(-6px)} }

/* Burbujas de chat */
.chat-bubble { max-width:80%; padding:.6rem .9rem; border-radius:14px; font-size:.875rem; line-height:1.5; word-break:break-word; }
.chat-bubble.user { background:#696cff; color:#fff; border-bottom-right-radius:3px; margin-left:auto; }
.chat-bubble.bot  { background:var(--bs-light); color:var(--bs-body-color); border-bottom-left-radius:3px; }
.chat-bubble.blocked { background:#ffe5e5; color:#c0392b; border:1px solid #f5c6cb; }

/* Escenario activo */
.scenario-item.active { background:rgba(105,108,255,.08) !important; border-left:3px solid #696cff; }
.scenario-item { cursor:pointer; transition:background .15s; }
.scenario-item:hover { background:rgba(105,108,255,.05); }

/* Security event */
.sec-event { padding:.5rem .75rem; border-bottom:1px solid var(--bs-border-color); font-size:.75rem; }
.sec-event:last-child { border-bottom:none; }
.sec-event .severity-high   { color:#ea5455; }
.sec-event .severity-medium { color:#ff9f43; }
.sec-event .severity-low    { color:#28c76f; }

/* Overlay aprobación */
#approval-overlay { position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9999;display:flex;align-items:center;justify-content:center; }
#approval-card { background:#fff;border-radius:16px;padding:2rem;max-width:460px;width:90%;text-align:center;animation:fadeIn .3s; }
html.dark-style #approval-card { background:#2f3349; }

/* ── Menú visual (tarjetas con foto) ── */
.menu-panel { width:100%; max-width:560px; background:linear-gradient(180deg,#fff, #fbfbfe); border:1px solid var(--bs-border-color); border-radius:16px; padding:.85rem 1rem 1rem; box-shadow:0 4px 16px rgba(0,0,0,.05); }
html.dark-style .menu-panel { background:#2b2f45; }
.menu-head { font-weight:700; font-size:.95rem; color:#696cff; margin-bottom:.25rem; }
.menu-cat { font-size:.72rem; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:var(--bs-secondary-color); margin:.6rem 0 .4rem; }
.menu-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(150px,1fr)); gap:.6rem; }
.menu-card { border:1px solid var(--bs-border-color); border-radius:12px; overflow:hidden; background:var(--bs-body-bg); display:flex; flex-direction:column; transition:transform .12s, box-shadow .12s; }
.menu-card:hover { transform:translateY(-2px); box-shadow:0 6px 16px rgba(105,108,255,.18); }
.menu-img { aspect-ratio:4/3; background:#f1f1f6; overflow:hidden; }
.menu-img img { width:100%; height:100%; object-fit:cover; display:block; }
.menu-img-ph { width:100%; height:100%; display:flex; align-items:center; justify-content:center; font-size:2.2rem; background:linear-gradient(135deg,#eceefe,#f6e9fb); }
.menu-body { padding:.5rem .6rem .6rem; display:flex; flex-direction:column; flex-grow:1; }
.menu-name { font-weight:600; font-size:.82rem; line-height:1.2; }
.menu-desc { font-size:.7rem; color:var(--bs-secondary-color); margin-top:.15rem; line-height:1.3; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
.menu-foot { margin-top:auto; padding-top:.45rem; display:flex; align-items:center; justify-content:space-between; gap:.4rem; }
.menu-price { font-weight:700; font-size:.82rem; color:var(--bs-body-color); }
.menu-add { border:none; background:#696cff; color:#fff; width:26px; height:26px; border-radius:8px; display:flex; align-items:center; justify-content:center; cursor:pointer; flex-shrink:0; transition:background .12s; }
.menu-add:hover { background:#5a5de8; }
</style>

<!-- ── JavaScript del simulador ───────────────────────────────────────────── -->
<script>
(function () {
  'use strict';

  // ── Estado ──────────────────────────────────────────────────────────────
  const state = {
    sessionId:       null,
    scenario:        'free',
    history:         [],
    blockedCount:    0,
    scenariosTested: new Set(),
    minScenarios:    <?= $minScenarios ?>,
    isReady:         <?= $isReady ? 'true' : 'false' ?>,
    canApprove:      <?= $canApprove ? 'true' : 'false' ?>,
  };

  // ── DOM refs ─────────────────────────────────────────────────────────────
  const elMessages       = document.getElementById('chat-messages');
  const elInput          = document.getElementById('chat-input');
  const elSend           = document.getElementById('btn-send');
  const elTyping         = document.getElementById('typing-indicator');
  const elEmpty          = document.getElementById('chat-empty');
  const elChatTitle      = document.getElementById('chat-title');
  const elChatSubtitle   = document.getElementById('chat-subtitle');
  const elSessionLabel   = document.getElementById('session-id-label');
  const elTokenInfo      = document.getElementById('token-info');
  const elBlockedCount   = document.getElementById('blocked-count');
  const elSecBar         = document.getElementById('security-bar');
  const elSecList        = document.getElementById('security-events-list');
  const elNoEvents       = document.getElementById('no-events');
  const elTestedNum      = document.getElementById('tested-num');
  const elBtnApprove     = document.getElementById('btn-approve');
  const elBtnReset       = document.getElementById('btn-reset-chat');
  const elBtnFreeChat    = document.getElementById('btn-free-chat');
  const elScenariosList  = document.getElementById('scenarios-list');
  const elIndustryLabel  = document.getElementById('industry-label');

  // ── Inicialización ───────────────────────────────────────────────────────
  newSession();
  loadScenarios();
  loadSecurityEvents();

  // ── Nueva sesión ─────────────────────────────────────────────────────────
  function newSession() {
    state.sessionId = 'sim_' + Date.now() + '_' + Math.random().toString(36).substr(2, 6);
    state.history   = [];
    elSessionLabel.textContent = 'Sesión: ' + state.sessionId.slice(0, 16) + '…';
  }

  // ── Cargar escenarios ────────────────────────────────────────────────────
  async function loadScenarios() {
    try {
      const res  = await fetch('/api/simulator-proxy.php?action=scenarios');
      const data = await res.json();
      const scenarios = data.scenarios || [];
      const industry  = data.industry  || 'general';

      elIndustryLabel.textContent = 'Industria: ' + industry;
      elScenariosList.innerHTML   = '';

      scenarios.forEach(sc => {
        const li = document.createElement('a');
        li.href = 'javascript:void(0)';
        li.className = 'list-group-item list-group-item-action scenario-item px-3 py-2';
        li.dataset.key     = sc.key;
        li.dataset.message = sc.message;
        li.innerHTML = `
          <div class="d-flex align-items-start gap-2">
            <i class="bx ${sc.icon || 'bx-chat'} text-primary mt-1 flex-shrink-0"></i>
            <div>
              <div class="fw-semibold" style="font-size:.82rem">${escHtml(sc.label)}</div>
              <small class="text-muted">${escHtml(sc.category || '')}</small>
            </div>
          </div>`;
        li.addEventListener('click', () => startScenario(sc));
        elScenariosList.appendChild(li);
      });
    } catch (err) {
      elScenariosList.innerHTML = '<div class="text-center py-3 text-muted small">Error al cargar escenarios</div>';
    }
  }

  // ── Iniciar escenario ────────────────────────────────────────────────────
  function startScenario(sc) {
    // Marcar activo en UI
    elScenariosList.querySelectorAll('.scenario-item').forEach(el => el.classList.remove('active'));
    elScenariosList.querySelector(`[data-key="${sc.key}"]`)?.classList.add('active');

    // Resetear chat para este escenario
    clearMessages();
    state.scenario = sc.key;
    elChatTitle.textContent    = sc.label;
    elChatSubtitle.textContent = sc.category || 'Escenario sugerido';
    elInput.disabled  = false;
    elSend.disabled   = false;
    elInput.focus();

    // Enviar automáticamente el mensaje del escenario
    sendMessage(sc.message);
  }

  // ── Chat libre ───────────────────────────────────────────────────────────
  elBtnFreeChat?.addEventListener('click', () => {
    elScenariosList.querySelectorAll('.scenario-item').forEach(el => el.classList.remove('active'));
    clearMessages();
    state.scenario = 'free';
    elChatTitle.textContent    = 'Chat Libre';
    elChatSubtitle.textContent = 'Escribe cualquier mensaje de prueba';
    elInput.disabled  = false;
    elSend.disabled   = false;
    elInput.focus();
  });

  // ── Reset chat ───────────────────────────────────────────────────────────
  elBtnReset?.addEventListener('click', () => {
    newSession();
    clearMessages();
    elChatTitle.textContent    = 'Selecciona un escenario';
    elChatSubtitle.textContent = 'o escribe en chat libre';
    elInput.disabled = true;
    elSend.disabled  = true;
    elScenariosList.querySelectorAll('.scenario-item').forEach(el => el.classList.remove('active'));
  });

  // ── Input: Enter para enviar ─────────────────────────────────────────────
  elInput?.addEventListener('keydown', e => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      if (!elSend.disabled) elSend.click();
    }
  });
  elSend?.addEventListener('click', () => {
    const msg = elInput.value.trim();
    if (!msg) return;
    elInput.value = '';
    sendMessage(msg);
  });

  // ── Enviar mensaje al simulador ──────────────────────────────────────────
  async function sendMessage(msg) {
    elEmpty?.remove();
    appendBubble('user', msg);
    state.history.push({ role: 'user', content: msg });

    elInput.disabled  = true;
    elSend.disabled   = true;
    elTyping.classList.remove('d-none');
    scrollBottom();

    try {
      const res  = await fetch('/api/simulator-proxy.php?action=chat', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({
          message:    msg,
          session_id: state.sessionId,
          scenario:   state.scenario,
          history:    state.history.slice(-10),
        }),
      });
      const data = await res.json();

      elTyping.classList.add('d-none');

      if (data.blocked) {
        appendBubble('bot', data.response, true);
        state.blockedCount++;
        updateSecurityUI(data.security_event);
        loadSecurityEvents();
      } else {
        appendBubble('bot', data.response);
        state.history.push({ role: 'assistant', content: data.response });
        if (Array.isArray(data.menu) && data.menu.length) appendMenu(data.menu);
      }

      // Actualizar token info
      if (data.meta) {
        elTokenInfo.textContent = `${data.meta.model?.split('-')[0] || 'LLM'} · ${data.meta.tokens || 0} tokens · ${data.meta.latency_ms || 0}ms`;
      }

      // Contar escenario como probado
      if (state.scenario !== 'free') {
        state.scenariosTested.add(state.scenario);
        updateTestedCounter();
        // Marcar visualmente en lista
        elScenariosList.querySelector(`[data-key="${state.scenario}"]`)
          ?.querySelector('i.bx')?.classList.replace('text-primary', 'text-success');
      }

    } catch (err) {
      elTyping.classList.add('d-none');
      appendBubble('bot', 'Error al conectar con el simulador. Intenta de nuevo.', true);
    } finally {
      elInput.disabled = false;
      elSend.disabled  = false;
      elInput.focus();
      scrollBottom();
    }
  }

  // ── Burbuja de chat ──────────────────────────────────────────────────────
  function appendBubble(role, text, blocked = false) {
    const wrap = document.createElement('div');
    wrap.className = 'd-flex ' + (role === 'user' ? 'justify-content-end' : 'justify-content-start');

    const bubble = document.createElement('div');
    bubble.className = 'chat-bubble ' + role + (blocked ? ' blocked' : '');
    bubble.textContent = text;

    if (blocked) {
      const icon = document.createElement('div');
      icon.className = 'small mb-1 fw-semibold';
      icon.innerHTML = '<i class="bx bx-shield-x me-1"></i>Mensaje bloqueado por seguridad';
      bubble.prepend(icon);
    }

    wrap.appendChild(bubble);
    // El indicador de escritura es hermano de #chat-messages, no hijo → appendChild
    elMessages.appendChild(wrap);
    scrollBottom();
  }

  // ── Menú visual (tarjetas con foto) ──────────────────────────────────────
  function escHtml(s) {
    return String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
  }
  function appendMenu(items) {
    const wrap = document.createElement('div');
    wrap.className = 'd-flex justify-content-start w-100';

    // Agrupar por categoría preservando orden
    const groups = {};
    items.forEach(it => { const c = it.category || 'Menú'; (groups[c] = groups[c] || []).push(it); });

    let html = '<div class="menu-head"><i class="bx bx-restaurant me-1"></i>Nuestro menú</div>';
    for (const [cat, list] of Object.entries(groups)) {
      html += `<div class="menu-cat">${escHtml(cat)}</div><div class="menu-grid">`;
      for (const it of list) {
        const price = '$' + ((it.price_cents || 0) / 100).toLocaleString('es-MX') + ' ' + escHtml(it.currency || 'MXN');
        const img = it.image_url
          ? `<img src="${escHtml(it.image_url)}" loading="lazy" alt="${escHtml(it.name)}"
                  onerror="this.outerHTML='<div class=&quot;menu-img-ph&quot;>🍽️</div>'">`
          : '<div class="menu-img-ph">🍽️</div>';
        html += `<div class="menu-card">
          <div class="menu-img">${img}</div>
          <div class="menu-body">
            <div class="menu-name">${escHtml(it.name)}</div>
            ${it.description ? `<div class="menu-desc">${escHtml(it.description)}</div>` : ''}
            <div class="menu-foot">
              <span class="menu-price">${price}</span>
              <button class="menu-add" type="button" title="Agregar"><i class="bx bx-plus"></i></button>
            </div>
          </div>
        </div>`;
      }
      html += '</div>';
    }

    const panel = document.createElement('div');
    panel.className = 'menu-panel';
    panel.innerHTML = html;
    wrap.appendChild(panel);
    elMessages.appendChild(wrap);
    // Los botones "+" son demostrativos en el simulador (probador de prompt).
    // El carrito real funciona en el chat web (widget). Damos feedback claro.
    let hintShown = false;
    panel.querySelectorAll('.menu-add').forEach(b => b.addEventListener('click', function () {
      const o = this.innerHTML; this.innerHTML = '<i class="bx bx-check"></i>';
      setTimeout(() => { this.innerHTML = o; }, 900);
      if (!hintShown) { hintShown = true;
        appendBubble('bot', '🛒 En el chat web real, este botón agrega el platillo al carrito y arma el pedido. Aquí es solo vista previa del diseño.');
      }
    }));
    scrollBottom();
  }

  function clearMessages() {
    Array.from(elMessages.children).forEach(el => {
      if (el.id !== 'typing-indicator' && el.id !== 'chat-empty') el.remove();
    });
    state.history = [];
    newSession();
    elTokenInfo.textContent = '';
  }

  function scrollBottom() {
    setTimeout(() => { elMessages.scrollTop = elMessages.scrollHeight; }, 50);
  }

  // ── UI de seguridad ──────────────────────────────────────────────────────
  function updateSecurityUI(event) {
    elBlockedCount.textContent = state.blockedCount;
    const pct = Math.max(10, 100 - (state.blockedCount * 10));
    elSecBar.style.width = pct + '%';
    elSecBar.className   = 'progress-bar ' + (pct > 70 ? 'bg-success' : pct > 40 ? 'bg-warning' : 'bg-danger');
  }

  async function loadSecurityEvents() {
    try {
      const res  = await fetch('/api/simulator-proxy.php?action=security_events&session_id=' + encodeURIComponent(state.sessionId));
      const rows = await res.json();
      if (!Array.isArray(rows) || !rows.length) return;

      elNoEvents?.remove();
      elSecList.innerHTML = '';
      rows.forEach(ev => {
        const div = document.createElement('div');
        div.className = 'sec-event';
        div.innerHTML = `
          <div class="d-flex justify-content-between mb-1">
            <span class="severity-${ev.severity} fw-semibold">${escHtml(ev.severity.toUpperCase())}</span>
            <span class="text-muted">${relativeTime(ev.created_at)}</span>
          </div>
          <div class="text-truncate" title="${escHtml(ev.message)}">${escHtml(ev.message.substring(0, 60))}</div>
          <div class="text-muted mt-1">${(ev.patterns || []).map(p => `<span class="badge bg-label-danger me-1" style="font-size:.65rem">${escHtml(p)}</span>`).join('')}</div>`;
        elSecList.appendChild(div);
      });
    } catch {}
  }

  // ── Contador de escenarios probados ─────────────────────────────────────
  function updateTestedCounter() {
    const count = state.scenariosTested.size;
    elTestedNum.textContent = count;
    if (count >= state.minScenarios && !state.isReady) {
      if (elBtnApprove) {
        elBtnApprove.disabled = false;
        elBtnApprove.classList.add('pulse-glow');
      }
      state.canApprove = true;
    }
  }

  // ── Botón Aprobar ────────────────────────────────────────────────────────
  elBtnApprove?.addEventListener('click', async () => {
    if (!state.canApprove) return;

    const ok = await window.confirmToast?.(
      '<strong><i class="bx bx-rocket me-1"></i>¿Activar el bot?</strong><br>' +
      '<small>Los webhooks de Twilio y WhatsApp se activarán y tu bot comenzará a atender clientes reales.</small>',
      'Sí, Activar',
      'btn-success'
    );
    if (!ok) return;

    elBtnApprove.disabled = true;
    elBtnApprove.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Activando…';

    try {
      const res  = await fetch('/api/simulator-proxy.php?action=approve', {
        method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}',
      });
      const data = await res.json();

      if (data.ok) {
        showApprovalSuccess();
      } else {
        window.showToast?.(data.error || 'Error al aprobar', 'error');
        elBtnApprove.disabled = false;
        elBtnApprove.innerHTML = '<i class="bx bx-rocket me-1"></i>Aprobar y Activar';
      }
    } catch {
      window.showToast?.('Error de red', 'error');
      elBtnApprove.disabled = false;
      elBtnApprove.innerHTML = '<i class="bx bx-rocket me-1"></i>Aprobar y Activar';
    }
  });

  function showApprovalSuccess() {
    const overlay = document.createElement('div');
    overlay.id = 'approval-overlay';
    overlay.innerHTML = `
      <div id="approval-card">
        <div class="mb-3" style="font-size:3.5rem">🚀</div>
        <h4 class="fw-bold text-success mb-2">¡Bot Activado!</h4>
        <p class="text-muted mb-4">Tu agente IA está listo y comenzará a atender clientes reales a través de tus canales configurados.</p>
        <div class="d-flex gap-2 justify-content-center">
          <a href="/pages/conversations.php" class="btn btn-primary">
            <i class="bx bx-conversation me-1"></i>Ver Conversaciones
          </a>
          <a href="/index.php" class="btn btn-outline-secondary">
            <i class="bx bx-home me-1"></i>Dashboard
          </a>
        </div>
      </div>`;
    document.body.appendChild(overlay);
  }

  // ── Helpers ──────────────────────────────────────────────────────────────
  function escHtml(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }
  function relativeTime(iso) {
    const d = Math.floor((Date.now() - new Date(iso)) / 1000);
    if (d < 60)   return 'hace un momento';
    if (d < 3600) return `hace ${Math.floor(d/60)}m`;
    return new Date(iso).toLocaleDateString('es-MX', { hour:'2-digit', minute:'2-digit' });
  }

})();
</script>

<style>
@keyframes pulse-glow {
  0%,100% { box-shadow: 0 0 0 0 rgba(40,199,111,.4); }
  50%      { box-shadow: 0 0 0 8px rgba(40,199,111,0); }
}
.pulse-glow { animation: pulse-glow 2s infinite; }
</style>
