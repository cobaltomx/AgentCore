<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/head.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/navbar.php';
require_once __DIR__ . '/../../includes/footer.php';
require_once __DIR__ . '/../../includes/specialty-vocab.php';

requireAuth(); if (!isAdmin()) { header('Location: /index.php'); exit; }

$rawTypes = apiGet('/consultorio/session-types');
$types    = array_values(array_filter((array)$rawTypes, 'is_array'));

$rawProfs = apiGet('/professionals');
$profs    = array_values(array_filter((array)$rawProfs, 'is_array'));

// Índice de profesionales por ID para lookups
$profMap = [];
foreach ($profs as $pr) { $profMap[$pr['id']] = $pr; }

renderHead('Tipos de Sesión — Consultorio');
?>
<div class="layout-wrapper layout-content-navbar"><div class="layout-container">
<?php renderSidebar('consult-session-types'); ?>
<div class="layout-page"><?php renderNavbar('Tipos de Sesión'); ?>
<div class="content-wrapper"><div class="container-xxl flex-grow-1 container-p-y">

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
  <div>
    <h4 class="mb-0"><i class="bx bx-calendar-check text-primary me-2"></i>Tipos de Sesión</h4>
    <small class="text-muted">Consultoría, terapia, asesoría y más</small>
  </div>
  <button class="btn btn-primary btn-sm" onclick="openModal()">
    <i class="bx bx-plus me-1"></i>Agregar tipo
  </button>
</div>

<!-- Tabla -->
<div class="card">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>Nombre</th>
          <th>Profesionales</th>
          <th>Sesiones</th>
          <th>Duración</th>
          <th>Precio</th>
          <th>Modalidad</th>
          <th>Estado</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($types as $t):
          $profsInfo = $t['professionals_info'] ?? [];
          if (is_string($profsInfo)) $profsInfo = json_decode($profsInfo, true) ?? [];
          $freq = match($t['frequency'] ?? 'weekly') {
            'biweekly' => 'Quincenal', 'monthly' => 'Mensual', default => 'Semanal'
          };
          $mod = match($t['modality'] ?? 'both') {
            'video' => '<i class="bx bx-video text-info" title="Video"></i>',
            'presencial' => '<i class="bx bx-building text-success" title="Presencial"></i>',
            default => '<i class="bx bx-transfer text-muted" title="Ambas"></i>'
          };
        ?>
        <tr data-id="<?= e($t['id']) ?>">
          <td>
            <div class="fw-semibold"><?= e($t['name']) ?></div>
            <?php if (!empty($t['description'])): ?>
            <small class="text-muted"><?= e(substr($t['description'], 0, 50)) ?>…</small>
            <?php endif; ?>
          </td>
          <td>
            <?php foreach ((array)$profsInfo as $pr):
              $prId    = $pr['id'] ?? '';
              $prFull  = $profMap[$prId] ?? [];
              $pVocab  = specialtyVocab($prFull['specialty_type'] ?? 'other');
            ?>
            <span class="badge me-1" style="background:<?= $pVocab['color'] ?>22;color:<?= $pVocab['color'] ?>;border:1px solid <?= $pVocab['color'] ?>44;font-size:10px">
              <i class="bx <?= $pVocab['icon'] ?> me-1"></i><?= e($pr['name'] ?? '') ?>
            </span>
            <?php endforeach; ?>
            <?php if (empty($profsInfo)): ?>
            <span class="text-muted small">—</span>
            <?php endif; ?>
          </td>
          <td>
            <span class="fw-semibold"><?= (int)($t['session_count'] ?? 1) ?></span>
            <small class="text-muted ms-1"><?= $freq ?></small>
          </td>
          <td><?= (int)($t['duration_mins'] ?? 50) ?> min</td>
          <td>
            <?php if (!empty($t['price_per_session'])): ?>
            <span>$<?= number_format((float)$t['price_per_session'], 0) ?></span>
            <?php else: ?>
            <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
          <td><?= $mod ?></td>
          <td>
            <?php if ($t['is_active'] ?? true): ?>
            <span class="badge bg-label-success">Activo</span>
            <?php else: ?>
            <span class="badge bg-label-secondary">Inactivo</span>
            <?php endif; ?>
          </td>
          <td class="text-end">
            <button class="btn btn-sm btn-outline-primary me-1"
                    onclick='openModal(<?= htmlspecialchars(json_encode($t), ENT_QUOTES) ?>)'>
              <i class="bx bx-edit-alt"></i>
            </button>
            <button class="btn btn-sm btn-outline-danger"
                    onclick="deleteSt('<?= e($t['id']) ?>', '<?= e($t['name']) ?>')">
              <i class="bx bx-trash"></i>
            </button>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($types)): ?>
        <tr><td colspan="8" class="text-center text-muted py-5">Sin tipos de sesión — agrega el primero</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

</div><?php renderFooter(); ?>

<!-- Modal -->
<div class="modal fade" id="modalSt" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable" style="max-height:92vh;margin-top:4vh">
    <div class="modal-content">
      <div class="modal-header border-0 pb-0">
        <h5 class="modal-title"><i class="bx bx-calendar-check text-primary me-2"></i><span id="st-title">Agregar tipo de sesión</span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form id="form-st">
        <div class="modal-body" style="overflow-y:auto;max-height:calc(92vh - 130px)">
          <input type="hidden" id="st-id">

          <!-- Info básica -->
          <h6 class="fw-semibold text-muted mb-3"><i class="bx bx-info-circle me-1"></i>Información general</h6>
          <div class="row g-3 mb-3">
            <div class="col-md-6"><label class="form-label">Nombre <span class="text-danger">*</span></label>
              <input type="text" class="form-control" id="st-name" required placeholder="Terapia individual, Asesoría legal…"></div>
            <div class="col-md-6"><label class="form-label">Estado</label>
              <select class="form-select" id="st-active">
                <option value="true">Activo</option><option value="false">Inactivo</option>
              </select></div>
            <div class="col-12"><label class="form-label">Descripción</label>
              <textarea class="form-control" id="st-desc" rows="2" placeholder="Descripción breve para el agente…"></textarea></div>
          </div>

          <!-- Profesionales -->
          <h6 class="fw-semibold text-muted mb-2"><i class="bx bx-briefcase me-1"></i>Profesionales</h6>
          <div class="mb-3">
            <?php if (empty($profs)): ?>
            <div class="alert alert-warning py-2 small mb-0">No hay profesionales registrados aún.</div>
            <?php else: ?>
            <div class="border rounded p-2" id="prof-checks" style="max-height:140px;overflow-y:auto">
              <?php foreach ($profs as $pr):
                $pv = specialtyVocab($pr['specialty_type'] ?? 'other');
              ?>
              <div class="form-check d-flex align-items-center gap-2">
                <input class="form-check-input prof-check" type="checkbox"
                       id="prof-check-<?= e($pr['id']) ?>" value="<?= e($pr['id']) ?>">
                <label class="form-check-label small flex-grow-1" for="prof-check-<?= e($pr['id']) ?>">
                  <?= e($pr['name']) ?>
                </label>
                <span class="badge" style="background:<?= $pv['color'] ?>22;color:<?= $pv['color'] ?>;font-size:9px">
                  <?= $pv['emoji'] ?> <?= $pv['label'] ?>
                </span>
              </div>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
          </div>

          <hr class="my-3">

          <!-- Sesiones y duración -->
          <h6 class="fw-semibold text-muted mb-3"><i class="bx bx-time me-1"></i>Estructura de la serie</h6>
          <div class="row g-3 mb-3">
            <div class="col-md-4"><label class="form-label">Número de sesiones</label>
              <input type="number" class="form-control" id="st-count" min="1" max="52" value="1" placeholder="1">
              <small class="text-muted">Por paquete/serie</small></div>
            <div class="col-md-4"><label class="form-label">Frecuencia</label>
              <select class="form-select" id="st-frequency">
                <option value="weekly">Semanal</option>
                <option value="biweekly">Quincenal</option>
                <option value="monthly">Mensual</option>
              </select></div>
            <div class="col-md-4"><label class="form-label">Duración (minutos)</label>
              <input type="number" class="form-control" id="st-duration" min="15" max="240" step="5" value="50"></div>
            <div class="col-md-6"><label class="form-label">Modalidad</label>
              <select class="form-select" id="st-modality">
                <option value="both">Presencial y Video</option>
                <option value="presencial">Solo Presencial</option>
                <option value="video">Solo Video</option>
              </select></div>
            <div class="col-md-6"><label class="form-label">Horas cancelación anticipada</label>
              <input type="number" class="form-control" id="st-cancel-h" min="0" max="168" value="24">
              <small class="text-muted">Horas mínimas para cancelar sin penalidad</small></div>
          </div>

          <hr class="my-3">

          <!-- Precios -->
          <h6 class="fw-semibold text-muted mb-3"><i class="bx bx-money me-1"></i>Precio y depósito</h6>
          <div class="row g-3 mb-3">
            <div class="col-md-6"><label class="form-label">Precio por sesión ($)</label>
              <input type="number" class="form-control" id="st-price" min="0" step="0.01" placeholder="0.00"></div>
            <div class="col-md-6"><label class="form-label">Requiere depósito</label>
              <select class="form-select" id="st-deposit" onchange="toggleDeposit()">
                <option value="false">No</option><option value="true">Sí</option>
              </select></div>
            <div class="col-md-6 deposit-field d-none"><label class="form-label">Porcentaje de depósito (%)</label>
              <input type="number" class="form-control" id="st-deposit-pct" min="0" max="100" placeholder="30"></div>
            <div class="col-md-6 deposit-field d-none"><label class="form-label">Depósito fijo ($)</label>
              <input type="number" class="form-control" id="st-deposit-fixed" min="0" step="0.01" placeholder="0.00">
              <small class="text-muted">Si se llena, prevalece sobre el porcentaje</small></div>
          </div>

          <hr class="my-3">

          <!-- Palabras clave -->
          <h6 class="fw-semibold text-muted mb-2"><i class="bx bx-microphone me-1"></i>Palabras clave de voz</h6>
          <div class="mb-1">
            <input type="text" class="form-control" id="st-keywords" placeholder="terapia, sesión, consulta, asesoría">
            <small class="text-muted">Separadas por coma — el agente detecta estas palabras para ofrecer este tipo</small>
          </div>

          <div id="st-error" class="alert alert-danger mt-3 d-none"></div>
        </div>
        <div class="modal-footer border-0 pt-0">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-primary">
            <span id="st-btn-text"><i class="bx bx-save me-1"></i>Guardar</span>
            <span id="st-btn-spin" class="d-none"><span class="spinner-border spinner-border-sm me-1"></span>Guardando…</span>
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function toggleDeposit() {
  const show = document.getElementById('st-deposit').value === 'true';
  document.querySelectorAll('.deposit-field').forEach(el => el.classList.toggle('d-none', !show));
}

function resetProfChecks() {
  document.querySelectorAll('.prof-check').forEach(cb => cb.checked = false);
}
function getSelectedProfIds() {
  return [...document.querySelectorAll('.prof-check:checked')].map(cb => cb.value);
}
function setProfChecks(ids) {
  resetProfChecks();
  (ids || []).forEach(id => {
    const cb = document.getElementById('prof-check-' + id);
    if (cb) cb.checked = true;
  });
}

function openModal(t = null) {
  document.getElementById('form-st').reset();
  document.getElementById('st-error').classList.add('d-none');
  document.querySelectorAll('.deposit-field').forEach(el => el.classList.add('d-none'));
  resetProfChecks();

  if (t) {
    document.getElementById('st-title').textContent    = 'Editar tipo de sesión';
    document.getElementById('st-id').value             = t.id;
    document.getElementById('st-name').value           = t.name || '';
    document.getElementById('st-active').value         = t.is_active ? 'true' : 'false';
    document.getElementById('st-desc').value           = t.description || '';
    document.getElementById('st-count').value          = t.session_count || 1;
    document.getElementById('st-frequency').value      = t.frequency || 'weekly';
    document.getElementById('st-duration').value       = t.duration_mins || 50;
    document.getElementById('st-modality').value       = t.modality || 'both';
    document.getElementById('st-cancel-h').value       = t.cancellation_hours ?? 24;
    document.getElementById('st-price').value          = t.price_per_session || '';
    document.getElementById('st-deposit').value        = t.requires_deposit ? 'true' : 'false';
    document.getElementById('st-deposit-pct').value   = t.deposit_percent || '';
    document.getElementById('st-deposit-fixed').value = t.deposit_fixed || '';
    const kw = Array.isArray(t.voice_keywords) ? t.voice_keywords.join(', ') : (t.voice_keywords || '');
    document.getElementById('st-keywords').value       = kw;
    setProfChecks(t.professional_ids || []);
    toggleDeposit();
  } else {
    document.getElementById('st-title').textContent = 'Agregar tipo de sesión';
    document.getElementById('st-id').value = '';
    document.getElementById('st-count').value    = '1';
    document.getElementById('st-duration').value = '50';
    document.getElementById('st-cancel-h').value = '24';
  }
  new bootstrap.Modal(document.getElementById('modalSt')).show();
}

document.getElementById('form-st').addEventListener('submit', async e => {
  e.preventDefault();
  const id     = document.getElementById('st-id').value;
  const errDiv = document.getElementById('st-error');
  document.getElementById('st-btn-text').classList.add('d-none');
  document.getElementById('st-btn-spin').classList.remove('d-none');
  errDiv.classList.add('d-none');

  const payload = {
    id:                 id || undefined,
    name:               document.getElementById('st-name').value.trim(),
    is_active:          document.getElementById('st-active').value === 'true',
    description:        document.getElementById('st-desc').value.trim() || null,
    professional_ids:   getSelectedProfIds(),
    session_count:      parseInt(document.getElementById('st-count').value)    || 1,
    frequency:          document.getElementById('st-frequency').value,
    duration_mins:      parseInt(document.getElementById('st-duration').value) || 50,
    modality:           document.getElementById('st-modality').value,
    cancellation_hours: parseInt(document.getElementById('st-cancel-h').value) ?? 24,
    price_per_session:  parseFloat(document.getElementById('st-price').value)  || null,
    requires_deposit:   document.getElementById('st-deposit').value === 'true',
    deposit_percent:    parseInt(document.getElementById('st-deposit-pct').value)   || null,
    deposit_fixed:      parseFloat(document.getElementById('st-deposit-fixed').value) || null,
    voice_keywords:     document.getElementById('st-keywords').value,
  };

  try {
    const res  = await fetch('/api/consultorio-session-type-save.php', {
      method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(payload)
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.message || data.error || 'Error al guardar');
    bootstrap.Modal.getInstance(document.getElementById('modalSt'))?.hide();
    window.showToast(id ? 'Tipo actualizado' : 'Tipo creado', 'success');
    location.reload();
  } catch(err) {
    errDiv.textContent = err.message; errDiv.classList.remove('d-none');
  } finally {
    document.getElementById('st-btn-text').classList.remove('d-none');
    document.getElementById('st-btn-spin').classList.add('d-none');
  }
});

async function deleteSt(id, name) {
  const ok = await window.confirmToast?.(
    `<i class="bx bx-trash me-1"></i>¿Eliminar el tipo <strong>${name}</strong>?`, 'Sí, eliminar'
  );
  if (!ok) return;
  const res  = await fetch('/api/consultorio-session-type-save.php', {
    method: 'POST', headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({id, action: 'delete'})
  });
  const data = await res.json();
  if (!res.ok) { window.showToast('Error: ' + (data.error || ''), 'danger'); return; }
  document.querySelector(`tr[data-id="${id}"]`)?.remove();
  window.showToast('<i class="bx bx-check-circle me-1"></i>Tipo eliminado', 'success');
}
</script>
