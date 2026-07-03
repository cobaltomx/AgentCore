<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/head.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/navbar.php';
require_once __DIR__ . '/../../includes/footer.php';

requireAuth();
if (!isAdmin()) { header('Location: /index.php'); exit; }

$tenant   = currentTenant();
$settings = $tenant['settings'] ?? [];
$cons     = $settings['consultorio'] ?? [];
$reminders = $cons['reminders'] ?? [];
$faqs      = $cons['faqs']      ?? [];

renderHead('Configuración Consultorios');
?>
<div class="layout-wrapper layout-content-navbar"><div class="layout-container">
<?php renderSidebar('consult-settings'); ?>
<div class="layout-page"><?php renderNavbar('Configuración Consultorios'); ?>
<div class="content-wrapper"><div class="container-xxl flex-grow-1 container-p-y">

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
  <div>
    <h4 class="mb-0"><i class="bx bx-cog text-primary me-2"></i>Configuración Consultorios</h4>
    <small class="text-muted">Políticas, mensajes y comportamiento del módulo</small>
  </div>
</div>

<div class="row g-4">

  <!-- ── Columna izquierda ─────────────────────────────────────── -->
  <div class="col-12 col-lg-6">

    <!-- Notificaciones al profesional -->
    <div class="card mb-4">
      <div class="card-header py-3">
        <h6 class="mb-0"><i class="bx bx-bell text-warning me-2"></i>Notificaciones al profesional</h6>
      </div>
      <div class="card-body">
        <p class="text-muted small mb-3">
          Cuando el agente agenda una sesión, se envía una alerta de WhatsApp al profesional.
        </p>
        <div class="mb-3">
          <label class="form-label">WhatsApp de notificaciones (fallback)</label>
          <div class="input-group">
            <span class="input-group-text"><i class="bx bxl-whatsapp text-success"></i></span>
            <input type="tel" class="form-control" id="c-notify-phone"
                   value="<?= e($cons['notifyPhone'] ?? '') ?>"
                   placeholder="+52 55 1234 5678">
          </div>
          <small class="text-muted">Si el profesional no tiene teléfono configurado, se usa este número</small>
        </div>
        <button class="btn btn-warning btn-sm" onclick="saveSection('notify')">
          <i class="bx bx-save me-1"></i>Guardar
        </button>
      </div>
    </div>

    <!-- Confidencialidad -->
    <div class="card mb-4">
      <div class="card-header py-3">
        <h6 class="mb-0"><i class="bx bx-shield-quarter text-primary me-2"></i>Mensaje de confidencialidad</h6>
      </div>
      <div class="card-body">
        <p class="text-muted small mb-3">
          El agente usa este texto cuando el paciente pide compartir detalles sensibles de la sesión.
          Se antepone a cualquier respuesta sobre temas clínicos o legales.
        </p>
        <div class="mb-3">
          <textarea class="form-control" id="c-conf-msg" rows="3"
                    placeholder="Por política de confidencialidad, no puedo compartir detalles de las sesiones por este medio. Para cualquier información clínica, contacta directamente al profesional."><?= e($cons['confidentialityMessage'] ?? '') ?></textarea>
        </div>
        <button class="btn btn-primary btn-sm" onclick="saveSection('conf')">
          <i class="bx bx-save me-1"></i>Guardar
        </button>
      </div>
    </div>

    <!-- Cancelaciones -->
    <div class="card mb-4">
      <div class="card-header py-3">
        <h6 class="mb-0"><i class="bx bx-x-circle text-danger me-2"></i>Política de cancelación</h6>
      </div>
      <div class="card-body">
        <p class="text-muted small mb-3">
          Estos valores se usan como predeterminados para nuevos tipos de sesión.
          Cada tipo puede sobrescribirlos individualmente.
        </p>
        <div class="row g-3 mb-3">
          <div class="col-md-6">
            <label class="form-label">Horas mínimas de anticipación</label>
            <input type="number" class="form-control" id="c-cancel-h" min="0" max="168"
                   value="<?= (int)($cons['defaultCancellationHours'] ?? 24) ?>">
            <small class="text-muted">Para cancelar sin penalidad</small>
          </div>
          <div class="col-md-6">
            <label class="form-label">Límite de cancelaciones por mes</label>
            <input type="number" class="form-control" id="c-cancel-limit" min="0" max="10"
                   value="<?= (int)($cons['cancellationLimit'] ?? 2) ?>">
            <small class="text-muted">El agente avisa al profesional si se supera</small>
          </div>
        </div>
        <div class="mb-3">
          <label class="form-label">Mensaje de cancelación tardía</label>
          <textarea class="form-control" id="c-cancel-msg" rows="2"
                    placeholder="Lo sentimos, esta cancelación está fuera de la política de {hours}h de anticipación. El profesional ha sido notificado para revisar tu caso."><?= e($cons['lateCancellationMessage'] ?? '') ?></textarea>
          <small class="text-muted">Variable: {hours}</small>
        </div>
        <button class="btn btn-danger btn-sm" onclick="saveSection('cancel')">
          <i class="bx bx-save me-1"></i>Guardar
        </button>
      </div>
    </div>

  </div><!-- /col left -->

  <!-- ── Columna derecha ───────────────────────────────────────── -->
  <div class="col-12 col-lg-6">

    <!-- Recordatorios -->
    <div class="card mb-4">
      <div class="card-header py-3">
        <h6 class="mb-0"><i class="bx bx-alarm text-info me-2"></i>Recordatorios automáticos</h6>
      </div>
      <div class="card-body">
        <p class="text-muted small mb-3">
          Mensajes de WhatsApp automáticos antes y después de cada sesión.
        </p>
        <div class="d-flex flex-column gap-3 mb-4">
          <?php
          $reminderList = [
            ['key'=>'reminder24h',   'label'=>'Recordatorio 24h antes',     'icon'=>'bx-calendar-minus','color'=>'primary',
             'desc'=>'Envía link de video si es modalidad online'],
            ['key'=>'reminder2h',    'label'=>'Recordatorio 2h antes',      'icon'=>'bx-alarm',         'color'=>'info',
             'desc'=>'Recordatorio final con link de acceso'],
            ['key'=>'postSession',   'label'=>'Mensaje post-sesión',        'icon'=>'bx-message-check', 'color'=>'success',
             'desc'=>'Se envía 1h después de la sesión'],
            ['key'=>'followUp',      'label'=>'Seguimiento 48h después',   'icon'=>'bx-refresh',        'color'=>'warning',
             'desc'=>'Invita a agendar la próxima sesión'],
          ];
          foreach ($reminderList as $rem):
            $enabled = $reminders[$rem['key']] ?? true;
          ?>
          <div class="d-flex justify-content-between align-items-center p-3 rounded border">
            <div class="d-flex align-items-center gap-2">
              <div class="avatar avatar-sm">
                <span class="avatar-initial rounded-circle bg-label-<?= $rem['color'] ?>">
                  <i class="bx <?= $rem['icon'] ?>"></i>
                </span>
              </div>
              <div>
                <div class="fw-semibold small"><?= $rem['label'] ?></div>
                <small class="text-muted"><?= $rem['desc'] ?></small>
              </div>
            </div>
            <div class="form-check form-switch mb-0">
              <input class="form-check-input reminder-toggle" type="checkbox"
                     id="rem-<?= $rem['key'] ?>" data-key="<?= $rem['key'] ?>"
                     <?= $enabled ? 'checked' : '' ?>>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <button class="btn btn-info btn-sm" onclick="saveSection('reminders')">
          <i class="bx bx-save me-1"></i>Guardar recordatorios
        </button>
      </div>
    </div>

    <!-- FAQs -->
    <div class="card">
      <div class="card-header py-3 d-flex justify-content-between align-items-center">
        <h6 class="mb-0"><i class="bx bx-help-circle text-success me-2"></i>Preguntas frecuentes</h6>
        <button class="btn btn-outline-success btn-sm" onclick="addFAQ()">
          <i class="bx bx-plus me-1"></i>Agregar
        </button>
      </div>
      <div class="card-body">
        <p class="text-muted small mb-3">
          El agente responderá estas preguntas: tarifas, modalidades, políticas, ubicación, etc.
        </p>
        <div id="faq-list">
          <?php foreach ($faqs as $i => $faq): ?>
          <div class="faq-item border rounded p-3 mb-3">
            <div class="mb-2 d-flex justify-content-between align-items-center">
              <span class="badge bg-label-success small">FAQ #<?= $i + 1 ?></span>
              <button class="btn btn-icon btn-sm btn-outline-danger" onclick="removeFAQ(this)">
                <i class="bx bx-x"></i>
              </button>
            </div>
            <div class="mb-2">
              <label class="form-label small mb-1">Pregunta</label>
              <input type="text" class="form-control form-control-sm faq-q"
                     value="<?= e($faq['q'] ?? '') ?>"
                     placeholder="¿Cuánto dura cada sesión?">
            </div>
            <div>
              <label class="form-label small mb-1">Respuesta</label>
              <textarea class="form-control form-control-sm faq-a" rows="2"
                        placeholder="Cada sesión tiene una duración de…"><?= e($faq['a'] ?? '') ?></textarea>
            </div>
          </div>
          <?php endforeach; ?>
          <?php if (empty($faqs)): ?>
          <div id="faq-empty" class="text-center text-muted py-4">
            <i class="bx bx-help-circle bx-lg d-block mb-2 opacity-25"></i>
            Sin FAQs — agrega las preguntas frecuentes del consultorio
          </div>
          <?php endif; ?>
        </div>
        <button class="btn btn-success btn-sm mt-2" onclick="saveSection('faqs')">
          <i class="bx bx-save me-1"></i>Guardar FAQs
        </button>
      </div>
    </div>

  </div><!-- /col right -->

</div><!-- /row -->

</div><?php renderFooter(); ?>

<script>
let faqCount = <?= count($faqs) ?>;

function addFAQ() {
  document.getElementById('faq-empty')?.remove();
  const list = document.getElementById('faq-list');
  const idx  = faqCount++;
  const div  = document.createElement('div');
  div.className = 'faq-item border rounded p-3 mb-3';
  div.innerHTML = `
    <div class="mb-2 d-flex justify-content-between align-items-center">
      <span class="badge bg-label-success small">FAQ #${idx + 1}</span>
      <button class="btn btn-icon btn-sm btn-outline-danger" onclick="removeFAQ(this)">
        <i class="bx bx-x"></i>
      </button>
    </div>
    <div class="mb-2">
      <label class="form-label small mb-1">Pregunta</label>
      <input type="text" class="form-control form-control-sm faq-q" placeholder="¿Cuánto dura cada sesión?">
    </div>
    <div>
      <label class="form-label small mb-1">Respuesta</label>
      <textarea class="form-control form-control-sm faq-a" rows="2" placeholder="Cada sesión tiene una duración de…"></textarea>
    </div>`;
  list.appendChild(div);
}

function removeFAQ(btn) {
  btn.closest('.faq-item').remove();
}

function buildConsultorioPayload() {
  const remObj = {};
  document.querySelectorAll('.reminder-toggle').forEach(cb => {
    remObj[cb.dataset.key] = cb.checked;
  });

  const faqs = [];
  document.querySelectorAll('.faq-item').forEach(item => {
    const q = item.querySelector('.faq-q')?.value.trim();
    const a = item.querySelector('.faq-a')?.value.trim();
    if (q && a) faqs.push({ q, a });
  });

  return {
    consultorio: {
      notifyPhone:               document.getElementById('c-notify-phone').value.trim() || null,
      confidentialityMessage:    document.getElementById('c-conf-msg').value.trim()     || null,
      defaultCancellationHours:  parseInt(document.getElementById('c-cancel-h').value)     || 24,
      cancellationLimit:         parseInt(document.getElementById('c-cancel-limit').value) || 2,
      lateCancellationMessage:   document.getElementById('c-cancel-msg').value.trim()    || null,
      reminders: remObj,
      faqs,
    }
  };
}

async function saveSection(section) {
  const payload = buildConsultorioPayload();
  try {
    const res  = await fetch('/api/settings-save.php', {
      method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(payload)
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.message || data.error || 'Error al guardar');
    window.showToast('Configuración guardada', 'success');
  } catch(err) {
    window.showToast('Error: ' + err.message, 'danger');
  }
}
</script>
