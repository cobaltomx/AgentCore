<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/head.php';
require_once __DIR__ . '/../includes/sidebar.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/footer.php';

requireAuth();
if (!isAdmin()) { header('Location: /index.php'); exit; }

$tenant    = apiGet('/tenants');
$widgetKey = $tenant['widget_key'] ?? '';
$isReady   = (bool)($tenant['is_ready'] ?? false);
$w         = $tenant['settings']['widget'] ?? [];

$wEnabled  = ($w['enabled'] ?? true) !== false;
$wColor    = $w['color']   ?? '#696cff';
$wName     = $w['name']    ?? ($tenant['name'] ?? 'Asistente');
$wWelcome  = $w['welcome'] ?? '¡Hola! 👋 ¿En qué puedo ayudarte?';

// URL pública del widget + API (configurables por env; ver config.php).
// Se incluye data-api para que el widget NO tenga que adivinar el backend.
$widgetSrc = WIDGET_PUBLIC_URL;
$snippet   = '<script src="' . e($widgetSrc) . '" data-key="' . e($widgetKey) . '" data-api="' . e(WIDGET_API_URL) . '"></script>';

renderHead('Chat Web');
?>
<div class="layout-wrapper layout-content-navbar"><div class="layout-container">
<?php renderSidebar('web-widget'); ?>
<div class="layout-page"><?php renderNavbar('Chat Web'); ?>
<div class="content-wrapper"><div class="container-xxl flex-grow-1 container-p-y">

  <div class="mb-4">
    <h4 class="mb-1"><i class="bx bx-globe text-primary me-2"></i>Chat Web</h4>
    <p class="text-muted mb-0">Agrega el asistente IA a tu sitio web. Mismo cerebro que WhatsApp y voz.</p>
  </div>

  <?php if (!$isReady): ?>
  <div class="alert alert-warning d-flex align-items-center gap-2">
    <i class="bx bx-time-five"></i>
    <span>El widget se mostrará en tu sitio una vez que actives tu bot en el
      <a href="/pages/simulator.php" class="alert-link">Simulador</a>.</span>
  </div>
  <?php endif; ?>

  <div class="row g-4">
    <!-- ── Snippet de integración ──────────────────────────────── -->
    <div class="col-lg-7">
      <div class="card mb-4">
        <div class="card-header"><h6 class="mb-0">1. Copia este código en tu sitio</h6></div>
        <div class="card-body">
          <p class="text-muted small">Pégalo antes de la etiqueta <code>&lt;/body&gt;</code> en cada página donde quieras el chat.</p>
          <div class="position-relative">
            <pre class="bg-light border rounded p-3 mb-0" style="white-space:pre-wrap;word-break:break-all;font-size:.8rem" id="snippet-box"><?= e($snippet) ?></pre>
            <button class="btn btn-sm btn-primary position-absolute top-0 end-0 m-2" id="btn-copy-snippet">
              <i class="bx bx-copy me-1"></i>Copiar
            </button>
          </div>
          <small class="text-muted d-block mt-2">
            <i class="bx bx-key me-1"></i>Tu clave pública del widget: <code><?= e($widgetKey) ?></code>
          </small>
        </div>
      </div>

      <!-- ── Personalización ─────────────────────────────────────── -->
      <div class="card">
        <div class="card-header"><h6 class="mb-0">2. Personaliza el widget</h6></div>
        <div class="card-body">
          <form id="widget-form">
            <div class="form-check form-switch mb-3">
              <input class="form-check-input" type="checkbox" id="w-enabled" <?= $wEnabled ? 'checked' : '' ?>>
              <label class="form-check-label" for="w-enabled">Widget activo en mi sitio</label>
            </div>
            <div class="mb-3">
              <label class="form-label">Nombre que se muestra</label>
              <input type="text" class="form-control" id="w-name" maxlength="40" value="<?= e($wName) ?>">
            </div>
            <div class="mb-3">
              <label class="form-label">Mensaje de bienvenida</label>
              <input type="text" class="form-control" id="w-welcome" maxlength="160" value="<?= e($wWelcome) ?>">
            </div>
            <div class="mb-3">
              <label class="form-label">Color principal</label>
              <div class="d-flex align-items-center gap-2">
                <input type="color" class="form-control form-control-color" id="w-color" value="<?= e($wColor) ?>">
                <code id="w-color-hex"><?= e($wColor) ?></code>
              </div>
            </div>
            <button type="submit" class="btn btn-primary"><i class="bx bx-save me-1"></i>Guardar cambios</button>
          </form>
        </div>
      </div>
    </div>

    <!-- ── Vista previa en vivo ────────────────────────────────── -->
    <div class="col-lg-5">
      <div class="card">
        <div class="card-header"><h6 class="mb-0">Vista previa</h6></div>
        <div class="card-body">
          <div id="preview" style="border:1px solid var(--bs-border-color);border-radius:12px;overflow:hidden;max-width:320px;margin:auto">
            <div id="pv-header" style="background:<?= e($wColor) ?>;color:#fff;padding:12px 14px;font-weight:600;font-size:14px">
              <span id="pv-name"><?= e($wName) ?></span>
            </div>
            <div style="background:#f7f7fb;padding:14px;min-height:140px">
              <div style="background:#fff;border:1px solid #ececf2;border-radius:13px;border-bottom-left-radius:4px;padding:9px 12px;font-size:13px;max-width:85%">
                <span id="pv-welcome"><?= e($wWelcome) ?></span>
              </div>
              <div style="display:flex;justify-content:flex-end;margin-top:8px">
                <div style="background:<?= e($wColor) ?>;color:#fff;border-radius:13px;border-bottom-right-radius:4px;padding:9px 12px;font-size:13px" id="pv-bubble-user">Hola, quiero información</div>
              </div>
            </div>
            <div style="padding:10px;border-top:1px solid #ececf2;display:flex;gap:6px">
              <div style="flex:1;border:1px solid #d9d9e3;border-radius:20px;padding:8px 12px;font-size:13px;color:#999">Escribe tu mensaje...</div>
              <div style="background:<?= e($wColor) ?>;width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff" id="pv-send">➤</div>
            </div>
          </div>
          <small class="text-muted d-block text-center mt-2">Así se verá en tu sitio (esquina inferior derecha)</small>
        </div>
      </div>
    </div>
  </div>

</div></div>
<?php renderFooter(); ?>
</div></div></div>

<script>
(function () {
  'use strict';
  // Copiar snippet
  document.getElementById('btn-copy-snippet')?.addEventListener('click', function () {
    const txt = document.getElementById('snippet-box').textContent;
    navigator.clipboard?.writeText(txt);
    window.showToast?.('Código copiado', 'success');
  });

  // Vista previa en vivo
  const color = document.getElementById('w-color');
  const name  = document.getElementById('w-name');
  const welc  = document.getElementById('w-welcome');
  function syncPreview() {
    const c = color.value;
    document.getElementById('pv-header').style.background = c;
    document.getElementById('pv-send').style.background = c;
    document.getElementById('pv-bubble-user').style.background = c;
    document.getElementById('w-color-hex').textContent = c;
    document.getElementById('pv-name').textContent = name.value || 'Asistente';
    document.getElementById('pv-welcome').textContent = welc.value || '¡Hola!';
  }
  [color, name, welc].forEach(el => el?.addEventListener('input', syncPreview));

  // Guardar
  document.getElementById('widget-form')?.addEventListener('submit', async function (e) {
    e.preventDefault();
    const payload = { widget: {
      enabled: document.getElementById('w-enabled').checked,
      name:    name.value.trim(),
      welcome: welc.value.trim(),
      color:   color.value,
    }};
    try {
      const res = await fetch('/api/settings-save.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      });
      const data = await res.json();
      if (!data.error && (data.id || data.settings)) {
        window.showToast?.('Widget actualizado', 'success');
      } else {
        window.showToast?.(data.error || 'Error al guardar', 'error');
      }
    } catch { window.showToast?.('Error de red', 'error'); }
  });
})();
</script>
