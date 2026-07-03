<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/head.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/navbar.php';
require_once __DIR__ . '/../../includes/footer.php';

requireAuth();
if (!isAdmin()) { header('Location: /index.php'); exit; }

$rawST    = apiGet('/service-types');
$services = array_values(array_filter((array)$rawST, 'is_array'));

$rawDocs  = apiGet('/doctors');
$doctors  = array_values(array_filter((array)$rawDocs, 'is_array'));

renderHead('Tipos de servicio — Clínica');
?>

<div class="layout-wrapper layout-content-navbar">
<div class="layout-container">
<?php renderSidebar('clinica-services'); ?>
<div class="layout-page">
<?php renderNavbar('Tipos de servicio'); ?>
<div class="content-wrapper">
<div class="container-xxl flex-grow-1 container-p-y">

  <!-- Encabezado -->
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
    <div>
      <h4 class="mb-0"><i class="bx bx-list-check text-primary me-2"></i>Tipos de servicio</h4>
      <small class="text-muted">Triaje y configuración de citas por procedimiento</small>
    </div>
    <button class="btn btn-primary btn-sm" onclick="openSTModal()">
      <i class="bx bx-plus me-1"></i>Agregar servicio
    </button>
  </div>

  <!-- Cards de servicios -->
  <div class="row g-3 mb-4">
    <?php foreach ($services as $svc):
      $isUrgency = $svc['is_urgency'] ?? false;
      $reqDeposit = $svc['requires_deposit'] ?? false;
      $cardBorder = $isUrgency ? 'border-danger' : 'border-0';
      $iconClass  = $isUrgency ? 'bx-error-circle text-danger' : 'bx-tooth text-primary';
    ?>
    <div class="col-12 col-md-6 col-xl-4" data-svc-id="<?= e($svc['id']) ?>">
      <div class="card h-100 <?= $cardBorder ?>">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-start mb-2">
            <div class="d-flex align-items-center gap-2">
              <i class="bx <?= $iconClass ?>" style="font-size:1.4rem"></i>
              <h6 class="mb-0"><?= e($svc['name']) ?></h6>
            </div>
            <div class="d-flex gap-1">
              <button class="btn btn-icon btn-sm btn-outline-primary"
                      onclick='openSTModal(<?= htmlspecialchars(json_encode($svc), ENT_QUOTES) ?>)'
                      title="Editar">
                <i class="bx bx-edit-alt"></i>
              </button>
              <button class="btn btn-icon btn-sm btn-outline-danger"
                      onclick="deleteST('<?= e($svc['id']) ?>', '<?= e($svc['name']) ?>')"
                      title="Eliminar">
                <i class="bx bx-trash"></i>
              </button>
            </div>
          </div>

          <?php if (!empty($svc['description'])): ?>
            <p class="text-muted small mb-2"><?= e($svc['description']) ?></p>
          <?php endif; ?>

          <div class="d-flex flex-wrap gap-1 mb-2">
            <span class="badge bg-label-info"><i class="bx bx-time me-1"></i><?= (int)($svc['duration_mins'] ?? 30) ?> min</span>
            <?php if ($isUrgency): ?>
              <span class="badge bg-label-danger"><i class="bx bx-error me-1"></i>Urgencia</span>
            <?php endif; ?>
            <?php if ($reqDeposit): ?>
              <span class="badge bg-label-warning"><i class="bx bx-credit-card me-1"></i>Anticipo $<?= number_format($svc['deposit_amount'] ?? 0) ?></span>
            <?php endif; ?>
            <?php
            $docInfo = $svc['doctors_info'] ?? [];
            if (is_string($docInfo)) $docInfo = json_decode($docInfo, true) ?? [];
            foreach ((array)$docInfo as $doc):
            ?>
              <span class="badge bg-label-secondary"><i class="bx bx-user me-1"></i><?= e($doc['name'] ?? '') ?></span>
            <?php endforeach; ?>
          </div>

          <?php if (!empty($svc['voice_keywords'])): ?>
            <div class="mt-2">
              <small class="text-muted d-block mb-1"><i class="bx bx-microphone me-1"></i>Palabras clave de voz:</small>
              <div class="d-flex flex-wrap gap-1">
                <?php foreach ((array)$svc['voice_keywords'] as $kw): ?>
                  <span class="badge bg-light text-dark border"><?= e($kw) ?></span>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endif; ?>

          <?php if (!empty($svc['prep_instructions'])): ?>
            <div class="mt-2 p-2 rounded" style="background:rgba(105,108,255,.05)">
              <small class="text-muted"><i class="bx bx-clipboard me-1"></i>Prep:</small>
              <small class="text-body"><?= e(substr($svc['prep_instructions'], 0, 80)) ?><?= strlen($svc['prep_instructions']) > 80 ? '…' : '' ?></small>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>

    <?php if (empty($services)): ?>
    <div class="col-12">
      <div class="card">
        <div class="card-body text-center text-muted py-5">
          <i class="bx bx-list-check bx-lg d-block mb-2 opacity-25"></i>
          Sin tipos de servicio — agrega el primero
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>

</div><!-- /container -->
<?php renderFooter(); ?>

<!-- ════════════════════════════════════════════════════════
     Modal: Agregar / Editar tipo de servicio
════════════════════════════════════════════════════════ -->
<div class="modal fade" id="modalST" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable" style="max-height:92vh;margin-top:4vh">
    <div class="modal-content">
      <div class="modal-header border-0 pb-0">
        <h5 class="modal-title">
          <i class="bx bx-list-check text-primary me-2"></i>
          <span id="modal-st-title">Agregar tipo de servicio</span>
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <form id="form-st" novalidate>
        <div class="modal-body pt-2" style="overflow-y:auto;max-height:calc(92vh - 130px)">
          <input type="hidden" id="st-id" name="id">

          <!-- Datos básicos -->
          <div class="row g-3">
            <div class="col-12 col-md-6">
              <label class="form-label">Nombre del servicio <span class="text-danger">*</span></label>
              <input type="text" class="form-control" id="st-name" name="name" required
                     placeholder="Limpieza dental, Urgencia…" oninput="autoSlug()">
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label">Slug (identificador interno)</label>
              <input type="text" class="form-control" id="st-slug" name="slug"
                     placeholder="limpieza-dental" pattern="[a-z0-9\-_]+">
              <small class="text-muted">Solo minúsculas, números y guiones</small>
            </div>
            <div class="col-12">
              <label class="form-label">Descripción</label>
              <input type="text" class="form-control" id="st-description" name="description"
                     placeholder="Breve descripción del procedimiento">
            </div>
            <div class="col-6 col-md-3">
              <label class="form-label">Duración (min) <span class="text-danger">*</span></label>
              <input type="number" class="form-control" id="st-duration" name="duration_mins"
                     required min="5" max="480" value="45">
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label">Doctores que realizan este servicio</label>
              <div class="border rounded p-2" id="doctor-checks" style="max-height:140px;overflow-y:auto">
                <?php if (empty($doctors)): ?>
                  <small class="text-muted">No hay doctores registrados</small>
                <?php else: foreach ($doctors as $doc): ?>
                  <div class="form-check">
                    <input class="form-check-input doctor-check" type="checkbox"
                           id="doc-check-<?= e($doc['id']) ?>"
                           value="<?= e($doc['id']) ?>">
                    <label class="form-check-label small" for="doc-check-<?= e($doc['id']) ?>">
                      <?= e($doc['name']) ?>
                      <?php if (!empty($doc['specialty'])): ?>
                        <span class="text-muted">(<?= e($doc['specialty']) ?>)</span>
                      <?php endif; ?>
                    </label>
                  </div>
                <?php endforeach; endif; ?>
              </div>
              <small class="text-muted">Selecciona uno o más doctores</small>
            </div>
            <div class="col-6 col-md-3 d-flex align-items-end">
              <div class="form-check form-switch mb-0">
                <input class="form-check-input" type="checkbox" id="st-urgency" name="is_urgency"
                       onchange="toggleDeposit()">
                <label class="form-check-label" for="st-urgency">
                  <span class="text-danger fw-semibold">Urgencia</span>
                </label>
              </div>
            </div>
            <div class="col-6 col-md-3 d-flex align-items-end">
              <div class="form-check form-switch mb-0">
                <input class="form-check-input" type="checkbox" id="st-deposit" name="requires_deposit"
                       onchange="toggleDeposit()">
                <label class="form-check-label" for="st-deposit">Requiere anticipo</label>
              </div>
            </div>

            <!-- Monto depósito (condicional) -->
            <div class="col-12 col-md-4" id="deposit-amount-row" style="display:none">
              <label class="form-label">Monto anticipo (MXN)</label>
              <div class="input-group">
                <span class="input-group-text">$</span>
                <input type="number" class="form-control" id="st-deposit-amount" name="deposit_amount"
                       min="0" step="50" value="300" placeholder="300">
              </div>
            </div>
          </div>

          <hr class="my-4">

          <!-- Palabras clave de voz -->
          <h6 class="fw-semibold text-muted mb-2"><i class="bx bx-microphone me-1"></i>Palabras clave de voz</h6>
          <p class="text-muted small mb-2">
            El asistente usará estas palabras para detectar el servicio durante la llamada.
            Separa con comas.
          </p>
          <textarea class="form-control" id="st-keywords" name="voice_keywords" rows="2"
                    placeholder="limpieza, profilaxis, limpieza dental, limpieza de dientes"></textarea>

          <hr class="my-4">

          <!-- Instrucciones -->
          <h6 class="fw-semibold text-muted mb-2"><i class="bx bx-clipboard me-1"></i>Instrucciones</h6>
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label">Preparación previa (se envía en recordatorio 24h)</label>
              <textarea class="form-control" id="st-prep" name="prep_instructions" rows="3"
                        placeholder="Ej. Cepillarse antes de la cita. No comer 2 horas antes si es anestesia."></textarea>
            </div>
            <div class="col-12">
              <label class="form-label">Instrucciones post-consulta (se envía 3h después)</label>
              <textarea class="form-control" id="st-post" name="post_instructions" rows="3"
                        placeholder="Ej. Evitar alimentos duros las primeras 24h. Tomar analgésico si hay molestias."></textarea>
            </div>
          </div>

          <div id="st-error" class="alert alert-danger mt-3 d-none"></div>
        </div>

        <div class="modal-footer border-0 pt-0">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-primary" id="btn-save-st">
            <span id="btn-st-text"><i class="bx bx-save me-1"></i>Guardar</span>
            <span id="btn-st-spinner" class="d-none">
              <span class="spinner-border spinner-border-sm me-1"></span>Guardando…
            </span>
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
// ── Helpers ────────────────────────────────────────────────────
function autoSlug() {
  const name = document.getElementById('st-name').value;
  const slug  = name.toLowerCase()
    .normalize('NFD').replace(/[̀-ͯ]/g, '')  // quitar acentos
    .replace(/[^a-z0-9\s-]/g, '')
    .trim().replace(/\s+/g, '-');
  document.getElementById('st-slug').value = slug;
}

function toggleDeposit() {
  const needsDeposit = document.getElementById('st-deposit').checked;
  document.getElementById('deposit-amount-row').style.display = needsDeposit ? '' : 'none';
}

// ── Helpers de doctores ────────────────────────────────────────
function resetDoctorChecks() {
  document.querySelectorAll('.doctor-check').forEach(cb => cb.checked = false);
}

function getSelectedDoctorIds() {
  return [...document.querySelectorAll('.doctor-check:checked')].map(cb => cb.value);
}

function setDoctorChecks(ids) {
  resetDoctorChecks();
  (ids || []).forEach(id => {
    const cb = document.getElementById('doc-check-' + id);
    if (cb) cb.checked = true;
  });
}

// ── Abrir modal ────────────────────────────────────────────────
function openSTModal(svc = null) {
  const modal = new bootstrap.Modal(document.getElementById('modalST'));
  document.getElementById('form-st').reset();
  document.getElementById('st-error').classList.add('d-none');
  document.getElementById('deposit-amount-row').style.display = 'none';
  resetDoctorChecks();

  if (svc) {
    document.getElementById('modal-st-title').textContent     = 'Editar servicio';
    document.getElementById('st-id').value                    = svc.id;
    document.getElementById('st-name').value                  = svc.name             || '';
    document.getElementById('st-slug').value                  = svc.slug             || '';
    document.getElementById('st-description').value           = svc.description      || '';
    document.getElementById('st-duration').value              = svc.duration_mins    || 45;
    document.getElementById('st-urgency').checked             = !!svc.is_urgency;
    document.getElementById('st-deposit').checked             = !!svc.requires_deposit;
    document.getElementById('st-deposit-amount').value        = svc.deposit_amount   || 300;
    document.getElementById('st-keywords').value              = (svc.voice_keywords  || []).join(', ');
    document.getElementById('st-prep').value                  = svc.prep_instructions || '';
    document.getElementById('st-post').value                  = svc.post_instructions || '';

    // Marcar los doctores asignados (doctor_ids viene como array del API)
    const ids = svc.doctor_ids || [];
    setDoctorChecks(ids);

    if (svc.requires_deposit) {
      document.getElementById('deposit-amount-row').style.display = '';
    }
  } else {
    document.getElementById('modal-st-title').textContent = 'Agregar tipo de servicio';
    document.getElementById('st-id').value      = '';
    document.getElementById('st-duration').value = 45;
  }

  modal.show();
}

// ── Submit ─────────────────────────────────────────────────────
document.getElementById('form-st').addEventListener('submit', async function(e) {
  e.preventDefault();

  const id      = document.getElementById('st-id').value;
  const errDiv  = document.getElementById('st-error');
  const btnText = document.getElementById('btn-st-text');
  const btnSpin = document.getElementById('btn-st-spinner');
  errDiv.classList.add('d-none');
  btnText.classList.add('d-none');
  btnSpin.classList.remove('d-none');

  const payload = {
    id:               id || undefined,
    name:             document.getElementById('st-name').value.trim(),
    slug:             document.getElementById('st-slug').value.trim()         || undefined,
    description:      document.getElementById('st-description').value.trim()  || null,
    duration_mins:    parseInt(document.getElementById('st-duration').value),
    doctor_ids:       getSelectedDoctorIds(),   // array de UUIDs
    is_urgency:       document.getElementById('st-urgency').checked,
    requires_deposit: document.getElementById('st-deposit').checked,
    deposit_amount:   parseFloat(document.getElementById('st-deposit-amount').value) || null,
    voice_keywords:   document.getElementById('st-keywords').value,  // proxy convertirá
    prep_instructions: document.getElementById('st-prep').value.trim()  || null,
    post_instructions: document.getElementById('st-post').value.trim()  || null,
  };

  try {
    const res  = await fetch('/api/service-type-save.php', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify(payload),
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.message || data.error || 'Error al guardar');

    bootstrap.Modal.getInstance(document.getElementById('modalST'))?.hide();
    window.showToast(id ? 'Servicio actualizado' : 'Servicio creado', 'success');
    location.reload();

  } catch (err) {
    errDiv.textContent = err.message;
    errDiv.classList.remove('d-none');
  } finally {
    btnText.classList.remove('d-none');
    btnSpin.classList.add('d-none');
  }
});

// ── Eliminar ───────────────────────────────────────────────────
async function deleteST(id, name) {
  const ok = await window.confirmToast?.(
    `<i class="bx bx-trash me-1"></i>¿Eliminar el servicio <strong>${name}</strong>?`, 'Sí, eliminar'
  );
  if (!ok) return;
  try {
    const res  = await fetch('/api/service-type-save.php', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify({ id, action: 'delete' }),
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.message || data.error || 'Error');

    document.querySelector(`[data-svc-id="${id}"]`)?.remove();
    window.showToast('<i class="bx bx-check-circle me-1"></i>Servicio eliminado', 'success');
  } catch (err) {
    window.showToast('Error: ' + err.message, 'danger');
  }
}
</script>
