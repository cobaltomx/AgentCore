<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/head.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/navbar.php';
require_once __DIR__ . '/../../includes/footer.php';

requireAuth();
if (!isAdmin()) { header('Location: /index.php'); exit; }

// Leer settings actuales del tenant
$tenant   = currentTenant();
$settings = $tenant['settings']                ?? [];
$clinica  = $settings['clinica']               ?? [];
$reminders = $clinica['reminders']             ?? [];
$faqs      = $clinica['faqs']                  ?? [];

renderHead('Configuración Clínica');
?>

<div class="layout-wrapper layout-content-navbar">
<div class="layout-container">
<?php renderSidebar('clinica-settings'); ?>
<div class="layout-page">
<?php renderNavbar('Configuración Clínica'); ?>
<div class="content-wrapper">
<div class="container-xxl flex-grow-1 container-p-y">

  <!-- Encabezado -->
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
    <div>
      <h4 class="mb-0"><i class="bx bx-cog text-primary me-2"></i>Configuración Clínica</h4>
      <small class="text-muted">Urgencias, recordatorios automáticos y preguntas frecuentes</small>
    </div>
  </div>

  <div class="row g-4">

    <!-- ── Columna izquierda ───────────────────────────────── -->
    <div class="col-12 col-lg-6">

      <!-- Urgencias -->
      <div class="card mb-4">
        <div class="card-header py-3">
          <h6 class="mb-0"><i class="bx bx-error-circle text-danger me-2"></i>Número de guardia (Urgencias)</h6>
        </div>
        <div class="card-body">
          <p class="text-muted small mb-3">
            Si el asistente detecta una urgencia dental, transferirá la llamada a este número.
            También se enviará una alerta de WhatsApp.
          </p>
          <div class="mb-3">
            <label class="form-label">Número de guardia</label>
            <div class="input-group">
              <span class="input-group-text"><i class="bx bx-phone"></i></span>
              <input type="tel" class="form-control" id="urgency-phone"
                     value="<?= e($clinica['urgencyPhone'] ?? '') ?>"
                     placeholder="+52 55 1234 5678">
            </div>
            <small class="text-muted">Formato internacional: +52 55 1234 5678</small>
          </div>
          <div class="mb-3">
            <label class="form-label">Mensaje de alerta WhatsApp</label>
            <textarea class="form-control" id="urgency-msg" rows="3"
                      placeholder="🚨 URGENCIA DENTAL — {patient_name} requiere atención inmediata. Tel: {patient_phone}"><?= e($clinica['urgencyMessage'] ?? '') ?></textarea>
            <small class="text-muted">Variables disponibles: {patient_name}, {patient_phone}, {tenant_name}</small>
          </div>
          <button class="btn btn-danger btn-sm" onclick="saveSection('urgency')">
            <i class="bx bx-save me-1"></i>Guardar
          </button>
        </div>
      </div>

      <!-- Recordatorios automáticos -->
      <div class="card mb-4">
        <div class="card-header py-3">
          <h6 class="mb-0"><i class="bx bx-bell text-warning me-2"></i>Recordatorios automáticos</h6>
        </div>
        <div class="card-body">
          <p class="text-muted small mb-3">
            El sistema envía WhatsApp automáticamente. Activa o desactiva según necesites.
          </p>

          <div class="d-flex flex-column gap-3 mb-4">
            <?php
            $reminderList = [
              ['key' => 'reminder24h', 'label' => 'Recordatorio 24h antes',  'icon' => 'bx-calendar-minus', 'color' => 'primary',
               'desc' => 'Incluye instrucciones de preparación'],
              ['key' => 'reminder2h',  'label' => 'Recordatorio 2h antes',   'icon' => 'bx-alarm',          'color' => 'info',
               'desc' => 'Recordatorio de confirmación'],
              ['key' => 'postConsulta','label' => 'Instrucciones post-consulta','icon' => 'bx-first-aid',   'color' => 'success',
               'desc' => 'Se envía 3h después de la cita'],
              ['key' => 'reactivacion','label' => 'Reactivación semestral',  'icon' => 'bx-refresh',        'color' => 'warning',
               'desc' => 'Pacientes sin cita en 6+ meses'],
            ];
            foreach ($reminderList as $rem):
              $enabled = $reminders[$rem['key']] ?? true; // activo por defecto
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

          <button class="btn btn-warning btn-sm" onclick="saveSection('reminders')">
            <i class="bx bx-save me-1"></i>Guardar recordatorios
          </button>
        </div>
      </div>

    </div><!-- /col left -->

    <!-- ── Columna derecha ─────────────────────────────────── -->
    <div class="col-12 col-lg-6">

      <!-- FAQs -->
      <div class="card">
        <div class="card-header py-3 d-flex justify-content-between align-items-center">
          <h6 class="mb-0"><i class="bx bx-help-circle text-info me-2"></i>Preguntas frecuentes (FAQs)</h6>
          <button class="btn btn-outline-primary btn-sm" onclick="addFAQ()">
            <i class="bx bx-plus me-1"></i>Agregar
          </button>
        </div>
        <div class="card-body">
          <p class="text-muted small mb-3">
            El asistente responderá estas preguntas cuando el paciente pregunte por horarios,
            ubicación, seguros, precios, etc.
          </p>

          <div id="faq-list">
            <?php foreach ($faqs as $i => $faq): ?>
            <div class="faq-item border rounded p-3 mb-3" data-idx="<?= $i ?>">
              <div class="mb-2 d-flex justify-content-between align-items-center">
                <span class="badge bg-label-info small">FAQ #<?= $i + 1 ?></span>
                <button class="btn btn-icon btn-sm btn-outline-danger" onclick="removeFAQ(this)">
                  <i class="bx bx-x"></i>
                </button>
              </div>
              <div class="mb-2">
                <label class="form-label small mb-1">Pregunta</label>
                <input type="text" class="form-control form-control-sm faq-q"
                       value="<?= e($faq['q'] ?? '') ?>"
                       placeholder="¿Cuál es el horario de atención?">
              </div>
              <div>
                <label class="form-label small mb-1">Respuesta</label>
                <textarea class="form-control form-control-sm faq-a" rows="2"
                          placeholder="Atendemos de lunes a viernes…"><?= e($faq['a'] ?? '') ?></textarea>
              </div>
            </div>
            <?php endforeach; ?>

            <?php if (empty($faqs)): ?>
            <div id="faq-empty" class="text-center text-muted py-4">
              <i class="bx bx-help-circle bx-lg d-block mb-2 opacity-25"></i>
              Sin FAQs — agrega las preguntas frecuentes de tu clínica
            </div>
            <?php endif; ?>
          </div>

          <button class="btn btn-info btn-sm mt-2" onclick="saveSection('faqs')">
            <i class="bx bx-save me-1"></i>Guardar FAQs
          </button>
        </div>
      </div>

    </div><!-- /col right -->

  </div><!-- /row -->

</div><!-- /container -->
<?php renderFooter(); ?>

<script>
let faqCount = <?= count($faqs) ?>;

// ── Agregar FAQ ────────────────────────────────────────────────
function addFAQ() {
  document.getElementById('faq-empty')?.remove();
  const list = document.getElementById('faq-list');
  const idx  = faqCount++;
  const div  = document.createElement('div');
  div.className  = 'faq-item border rounded p-3 mb-3';
  div.dataset.idx = idx;
  div.innerHTML = `
    <div class="mb-2 d-flex justify-content-between align-items-center">
      <span class="badge bg-label-info small">FAQ #${idx + 1}</span>
      <button class="btn btn-icon btn-sm btn-outline-danger" onclick="removeFAQ(this)">
        <i class="bx bx-x"></i>
      </button>
    </div>
    <div class="mb-2">
      <label class="form-label small mb-1">Pregunta</label>
      <input type="text" class="form-control form-control-sm faq-q" placeholder="¿Cuál es el horario de atención?">
    </div>
    <div>
      <label class="form-label small mb-1">Respuesta</label>
      <textarea class="form-control form-control-sm faq-a" rows="2" placeholder="Atendemos de lunes a viernes…"></textarea>
    </div>`;
  list.appendChild(div);
}

// ── Eliminar FAQ ───────────────────────────────────────────────
function removeFAQ(btn) {
  btn.closest('.faq-item').remove();
}

// ── Construir el objeto clinica completo desde la página ───────
// Siempre enviamos TODO el bloque clinica para evitar
// pérdida de sub-claves con el merge superficial del backend.
function buildClinicaPayload() {
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
    clinica: {
      urgencyPhone:   document.getElementById('urgency-phone').value.trim(),
      urgencyMessage: document.getElementById('urgency-msg').value.trim(),
      reminders:      remObj,
      faqs,
    }
  };
}

// ── Guardar sección (siempre guarda el bloque completo) ────────
async function saveSection(section) {
  // Construir payload completo para no perder sub-claves
  const payload = buildClinicaPayload();

  try {
    const res  = await fetch('/api/settings-save.php', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify(payload),
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.message || data.error || 'Error al guardar');

    window.showToast('Configuración guardada correctamente', 'success');
  } catch (err) {
    window.showToast('Error: ' + err.message, 'danger');
  }
}
</script>
