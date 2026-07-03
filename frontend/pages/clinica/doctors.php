<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/head.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/navbar.php';
require_once __DIR__ . '/../../includes/footer.php';

requireAuth();
if (!isAdmin()) { header('Location: /index.php'); exit; }

$rawDoctors = apiGet('/doctors');
$doctors    = array_values(array_filter((array)$rawDoctors, 'is_array'));
// Catálogo de servicios (para asignar qué brinda cada especialista)
$rawServices = apiGet('/service-types');
$serviceTypes = array_values(array_filter((array)$rawServices, 'is_array'));

renderHead('Doctores — Clínica');
?>

<div class="layout-wrapper layout-content-navbar">
<div class="layout-container">
<?php renderSidebar('clinica-doctors'); ?>
<div class="layout-page">
<?php renderNavbar('Doctores'); ?>
<div class="content-wrapper">
<div class="container-xxl flex-grow-1 container-p-y">

  <!-- Encabezado -->
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
    <div>
      <h4 class="mb-1"><i class="bx bx-user-circle text-primary me-2"></i>Especialistas de la clínica</h4>
      <small class="text-muted">Da de alta a cada especialista con su <strong>especialidad</strong>, <strong>horario</strong>, los <strong>servicios que brinda</strong> y su <strong>consultorio</strong>.</small>
    </div>
    <button class="btn btn-primary" onclick="openDoctorModal()">
      <i class="bx bx-user-plus me-1"></i>Agregar especialista
    </button>
  </div>

  <!-- Tabla -->
  <div class="card">
    <div class="card-header py-3 d-flex justify-content-between align-items-center">
      <h6 class="mb-0">Equipo médico</h6>
      <small class="text-muted"><?= count($doctors) ?> doctor(es)</small>
    </div>
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>Especialista</th>
            <th>Especialidad</th>
            <th>Servicios que brinda</th>
            <th>Horario</th>
            <th>Contacto</th>
            <th>Estado</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($doctors)): ?>
            <tr><td colspan="7" class="text-center text-muted py-5">
              <i class="bx bx-user-x bx-lg d-block mb-2 opacity-25"></i>
              Sin doctores registrados — agrega el primero
            </td></tr>
          <?php else: foreach ($doctors as $d):
            $sc = $d['schedule_config'] ?? [];
            $dayLabels = ['mon'=>'L','tue'=>'M','wed'=>'X','thu'=>'J','fri'=>'V','sat'=>'S','sun'=>'D'];
            $activeDays = '';
            foreach ($dayLabels as $key => $label) {
              if (!empty($sc[$key]['active'])) {
                $activeDays .= '<span class="badge bg-label-primary px-1">' . $label . '</span> ';
              }
            }
            $activeDays = trim($activeDays);
          ?>
            <?php $svcNames = is_array($d['service_names'] ?? null) ? $d['service_names'] : []; ?>
            <tr data-id="<?= e($d['id']) ?>">
              <td>
                <div class="d-flex align-items-center gap-2">
                  <?= avatar($d, 'sm') ?>
                  <div>
                    <span class="fw-semibold d-block"><?= e($d['name']) ?></span>
                    <?php if (!empty($d['room'])): ?>
                      <small class="text-muted"><i class="bx bx-door-open me-1"></i><?= e($d['room']) ?></small>
                    <?php else: ?>
                      <small class="text-muted fst-italic">Sin consultorio</small>
                    <?php endif; ?>
                  </div>
                </div>
              </td>
              <td><small><?= e($d['specialty'] ?: '—') ?></small></td>
              <td>
                <?php if (empty($svcNames)): ?>
                  <small class="text-muted">—</small>
                <?php else: foreach (array_slice($svcNames, 0, 3) as $sn): ?>
                  <span class="badge bg-label-info mb-1"><?= e($sn) ?></span>
                <?php endforeach; if (count($svcNames) > 3): ?>
                  <span class="badge bg-label-secondary mb-1">+<?= count($svcNames) - 3 ?></span>
                <?php endif; endif; ?>
              </td>
              <td>
                <div class="d-flex gap-1 flex-wrap" style="max-width:120px">
                  <?= $activeDays ?: '<small class="text-muted">Sin horario</small>' ?>
                </div>
              </td>
              <td>
                <?php if (!empty($d['phone'])): ?>
                  <small class="d-block"><i class="bx bx-phone me-1 text-muted"></i><?= e($d['phone']) ?></small>
                <?php endif; ?>
                <?php if (!empty($d['email'])): ?>
                  <small class="d-block"><i class="bx bx-envelope me-1 text-muted"></i><?= e($d['email']) ?></small>
                <?php endif; ?>
                <?php if (!empty($d['license_number'])): ?>
                  <small class="d-block text-muted">Céd. <?= e($d['license_number']) ?></small>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($d['is_active'] ?? true): ?>
                  <span class="badge bg-label-success">Activo</span>
                <?php else: ?>
                  <span class="badge bg-label-secondary">Inactivo</span>
                <?php endif; ?>
              </td>
              <td class="text-end">
                <button class="btn btn-sm btn-icon btn-outline-primary me-1"
                        onclick='openDoctorModal(<?= htmlspecialchars(json_encode($d), ENT_QUOTES) ?>)'
                        title="Editar">
                  <i class="bx bx-edit-alt"></i>
                </button>
                <button class="btn btn-sm btn-icon btn-outline-danger"
                        onclick="deleteDoctor('<?= e($d['id']) ?>', '<?= e($d['name']) ?>')"
                        title="Eliminar">
                  <i class="bx bx-trash"></i>
                </button>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div><!-- /container -->
<?php renderFooter(); ?>

<!-- ════════════════════════════════════════════════════════
     Modal: Agregar / Editar doctor
════════════════════════════════════════════════════════ -->
<div class="modal fade" id="modalDoctor" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header border-0 pb-0">
        <h5 class="modal-title">
          <i class="bx bx-user-circle text-primary me-2"></i>
          <span id="modal-doctor-title">Agregar doctor</span>
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <form id="form-doctor" novalidate>
        <div class="modal-body pt-2">
          <input type="hidden" id="doc-id" name="id" value="">

          <!-- Datos personales -->
          <h6 class="fw-semibold text-muted mb-3 mt-1"><i class="bx bx-id-card me-1"></i>Datos del doctor</h6>

          <!-- Foto de avatar -->
          <div class="d-flex align-items-center gap-3 mb-3">
            <div class="avatar-upload-wrap">
              <img id="doc-avatar-preview" src="/assets/img/avatar-placeholder.svg"
                   class="avatar-preview" alt="Avatar">
              <label for="doc-avatar-file" class="avatar-edit-btn" title="Subir foto">
                <i class="bx bx-camera"></i>
              </label>
              <input type="file" id="doc-avatar-file" accept="image/*" class="d-none">
              <input type="hidden" id="doc-avatar-url">
            </div>
            <div class="small text-muted">
              <div class="fw-semibold mb-1">Foto del doctor</div>
              <div>JPG, PNG o WebP — máx. 4 MB.</div>
              <button type="button" class="btn btn-link btn-sm p-0 text-danger d-none"
                      id="doc-avatar-remove">Quitar foto</button>
            </div>
          </div>

          <div class="row g-3">
            <div class="col-12 col-md-6">
              <label class="form-label">Nombre completo <span class="text-danger">*</span></label>
              <input type="text" class="form-control" id="doc-name" name="name" required
                     placeholder="Dr. Juan Pérez">
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label">Especialidad</label>
              <input type="text" class="form-control" id="doc-specialty" name="specialty"
                     placeholder="Odontología general, Ortodoncia…">
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label">Teléfono</label>
              <input type="tel" class="form-control" id="doc-phone" name="phone"
                     placeholder="+52 55 1234 5678">
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label">Email</label>
              <input type="email" class="form-control" id="doc-email" name="email"
                     placeholder="doctor@clinica.com">
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label">Cédula profesional</label>
              <input type="text" class="form-control" id="doc-license" name="license_number"
                     placeholder="1234567">
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label">Estado</label>
              <select class="form-select" id="doc-active" name="is_active">
                <option value="true">Activo</option>
                <option value="false">Inactivo</option>
              </select>
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label"><i class="bx bx-door-open me-1"></i>Consultorio asignado</label>
              <input type="text" class="form-control" id="doc-room" name="room"
                     placeholder="Ej. Consultorio 1, Sala A">
            </div>
          </div>

          <!-- Servicios que brinda (del catálogo) -->
          <hr class="my-4">
          <div class="mb-2">
            <h6 class="fw-semibold mb-1"><i class="bx bx-list-check me-1"></i>Servicios que brinda</h6>
            <p class="text-muted small mb-2">Selecciona del catálogo qué servicios atiende este especialista. <a href="/pages/clinica/service-types.php" class="text-decoration-none">Administrar catálogo →</a></p>
          </div>
          <?php if (empty($serviceTypes)): ?>
            <div class="alert alert-info py-2 small mb-0"><i class="bx bx-info-circle me-1"></i>Aún no hay servicios en el catálogo. <a href="/pages/clinica/service-types.php" class="alert-link">Crea tipos de servicio</a> primero.</div>
          <?php else: ?>
          <div class="row g-2" id="doc-services">
            <?php foreach ($serviceTypes as $svc): if (empty($svc['is_active']) && isset($svc['is_active'])) continue; ?>
            <div class="col-12 col-md-6">
              <label class="d-flex align-items-center gap-2 border rounded p-2 mb-0" style="cursor:pointer">
                <input class="form-check-input mt-0 doc-service" type="checkbox" value="<?= e($svc['id']) ?>">
                <span><i class="bx <?= e($svc['icon'] ?? 'bx-plus-medical') ?> me-1" style="color:<?= e($svc['color'] ?? '#696cff') ?>"></i><?= e($svc['name']) ?></span>
                <small class="text-muted ms-auto"><?= (int)($svc['duration_mins'] ?? 30) ?> min</small>
              </label>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>

          <!-- Horario semanal -->
          <hr class="my-4">
          <div class="d-flex align-items-center justify-content-between mb-1 flex-wrap gap-2">
            <h6 class="fw-semibold mb-0"><i class="bx bx-time me-1"></i>Horario de atención</h6>
          </div>
          <p class="text-muted small mb-3"><i class="bx bx-info-circle me-1"></i>Define los días y horas reales en que este doctor da consulta. <strong>El bot solo ofrecerá citas dentro de estos horarios.</strong></p>

          <!-- Llenado rápido -->
          <div class="border rounded p-2 mb-3" style="background:var(--bs-light,#f8f8ff)">
            <div class="d-flex align-items-end gap-2 flex-wrap">
              <div><label class="form-label small mb-0">Desde</label><input type="time" class="form-control form-control-sm" id="qf-start" value="09:00" style="width:110px"></div>
              <div><label class="form-label small mb-0">Hasta</label><input type="time" class="form-control form-control-sm" id="qf-end" value="14:00" style="width:110px"></div>
              <div class="form-check mb-1 ms-1">
                <input class="form-check-input" type="checkbox" id="qf-break" onchange="document.getElementById('qf-break-times').classList.toggle('d-none', !this.checked)">
                <label class="form-check-label small" for="qf-break">Hora de comida</label>
              </div>
              <div id="qf-break-times" class="d-none d-flex align-items-center gap-1">
                <input type="time" class="form-control form-control-sm" id="qf-bstart" value="14:00" style="width:105px">
                <span class="text-muted">–</span>
                <input type="time" class="form-control form-control-sm" id="qf-bend" value="15:00" style="width:105px">
              </div>
              <button type="button" class="btn btn-sm btn-outline-primary" onclick="applyQuickFill()"><i class="bx bx-copy-alt me-1"></i>Aplicar a días marcados</button>
            </div>
            <small class="text-muted d-block mt-1">Marca los días abajo, ajusta las horas aquí y aplícalas a todos de un clic.</small>
          </div>

          <div class="table-responsive">
            <table class="table table-sm align-middle" id="schedule-table">
              <thead class="table-light">
                <tr>
                  <th style="width:70px">Atiende</th>
                  <th>Día</th>
                  <th style="width:120px">Desde</th>
                  <th style="width:120px">Hasta</th>
                  <th>Hora de comida <small class="text-muted fw-normal">(opcional)</small></th>
                </tr>
              </thead>
              <tbody>
                <?php
                $dayNames = [
                  'mon' => 'Lunes',
                  'tue' => 'Martes',
                  'wed' => 'Miércoles',
                  'thu' => 'Jueves',
                  'fri' => 'Viernes',
                  'sat' => 'Sábado',
                  'sun' => 'Domingo',
                ];
                foreach ($dayNames as $key => $label): ?>
                <tr id="row-<?= $key ?>" class="sched-row">
                  <td>
                    <div class="form-check form-switch mb-0">
                      <input class="form-check-input day-toggle" type="checkbox"
                             id="day-<?= $key ?>" onchange="toggleDay('<?= $key ?>')">
                    </div>
                  </td>
                  <td class="fw-semibold day-label"><?= $label ?></td>
                  <td>
                    <input type="time" class="form-control form-control-sm day-input" id="start-<?= $key ?>"
                           value="09:00" disabled onchange="renderSummary()">
                  </td>
                  <td>
                    <input type="time" class="form-control form-control-sm day-input" id="end-<?= $key ?>"
                           value="14:00" disabled onchange="renderSummary()">
                  </td>
                  <td>
                    <div class="d-flex align-items-center gap-1">
                      <div class="form-check mb-0 me-1">
                        <input class="form-check-input break-toggle day-input" type="checkbox"
                               id="break-<?= $key ?>" disabled onchange="toggleBreak('<?= $key ?>')">
                      </div>
                      <input type="time" class="form-control form-control-sm break-input" id="break-start-<?= $key ?>"
                             value="14:00" disabled style="width:105px" onchange="renderSummary()">
                      <span class="text-muted break-input">–</span>
                      <input type="time" class="form-control form-control-sm break-input" id="break-end-<?= $key ?>"
                             value="15:00" disabled style="width:105px" onchange="renderSummary()">
                    </div>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <!-- Resumen en vivo -->
          <div id="sched-summary" class="alert alert-info py-2 px-3 small mb-0"></div>

          <div id="doctor-error" class="alert alert-danger mt-3 d-none"></div>
        </div>

        <div class="modal-footer border-0 pt-0">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-primary" id="btn-save-doctor">
            <span id="btn-save-text"><i class="bx bx-save me-1"></i>Guardar</span>
            <span id="btn-save-spinner" class="d-none">
              <span class="spinner-border spinner-border-sm me-1"></span>Guardando…
            </span>
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
const DAYS = ['mon','tue','wed','thu','fri','sat','sun'];
const AVATAR_PLACEHOLDER = '/assets/img/avatar-placeholder.svg';

// ── Avatar: preview local + estado ─────────────────────────────
let pendingAvatarFile = null;

function setAvatarPreview(url) {
  const img = document.getElementById('doc-avatar-preview');
  const rem = document.getElementById('doc-avatar-remove');
  const hidden = document.getElementById('doc-avatar-url');
  if (url) {
    img.src = url.startsWith('/uploads/') ? (window.AC_BACKEND_ROOT || '') + url : url;
    hidden.value = url.startsWith('blob:') ? '' : url;
    rem.classList.remove('d-none');
  } else {
    img.src = AVATAR_PLACEHOLDER;
    hidden.value = '';
    rem.classList.add('d-none');
  }
}

document.getElementById('doc-avatar-file').addEventListener('change', e => {
  const f = e.target.files[0];
  if (!f) return;
  if (f.size > 4 * 1024 * 1024) { window.showToast('Máx 4 MB', 'danger'); return; }
  pendingAvatarFile = f;
  setAvatarPreview(URL.createObjectURL(f));
  document.getElementById('doc-avatar-remove').classList.remove('d-none');
});

document.getElementById('doc-avatar-remove').addEventListener('click', () => {
  pendingAvatarFile = null;
  setAvatarPreview(null);
});

const DAY_SHORT = { mon:'Lun', tue:'Mar', wed:'Mié', thu:'Jue', fri:'Vie', sat:'Sáb', sun:'Dom' };

// ── Toggle día activo ──────────────────────────────────────────
function toggleDay(key) {
  const active = document.getElementById('day-' + key).checked;
  document.querySelectorAll('#row-' + key + ' .day-input').forEach(inp => { inp.disabled = !active; });
  document.getElementById('row-' + key).classList.toggle('text-muted', !active);
  toggleBreak(key);            // los inputs de comida dependen también de su propio check
  renderSummary();
}

// ── Toggle hora de comida de un día ────────────────────────────
function toggleBreak(key) {
  const on = document.getElementById('day-' + key).checked && document.getElementById('break-' + key).checked;
  document.querySelectorAll('#row-' + key + ' .break-input').forEach(el => {
    if (el.tagName === 'INPUT') el.disabled = !on;
    el.style.opacity = on ? '1' : '.45';
  });
  renderSummary();
}

// ── Llenado rápido: aplica las horas de arriba a los días marcados ──
function applyQuickFill() {
  const s = document.getElementById('qf-start').value || '09:00';
  const e = document.getElementById('qf-end').value   || '14:00';
  const hasBreak = document.getElementById('qf-break').checked;
  const bs = document.getElementById('qf-bstart').value || '14:00';
  const be = document.getElementById('qf-bend').value   || '15:00';
  let applied = 0;
  DAYS.forEach(key => {
    if (!document.getElementById('day-' + key).checked) return;
    document.getElementById('start-' + key).value = s;
    document.getElementById('end-' + key).value   = e;
    document.getElementById('break-' + key).checked = hasBreak;
    document.getElementById('break-start-' + key).value = bs;
    document.getElementById('break-end-' + key).value   = be;
    toggleBreak(key);
    applied++;
  });
  if (!applied) window.showToast?.('Marca primero los días que atiende', 'warning');
  else window.showToast?.(`Horario aplicado a ${applied} día(s)`, 'success');
  renderSummary();
}

// ── Resumen legible en vivo ────────────────────────────────────
function renderSummary() {
  const box = document.getElementById('sched-summary');
  if (!box) return;
  const groups = new Map();   // agrupa días con el mismo horario
  DAYS.forEach(key => {
    if (!document.getElementById('day-' + key).checked) return;
    const s = document.getElementById('start-' + key).value;
    const e = document.getElementById('end-' + key).value;
    const hb = document.getElementById('break-' + key).checked;
    const bs = document.getElementById('break-start-' + key).value;
    const be = document.getElementById('break-end-' + key).value;
    const sig = `${s}|${e}|${hb ? bs + '-' + be : ''}`;
    if (!groups.has(sig)) groups.set(sig, { days: [], s, e, hb, bs, be });
    groups.get(sig).days.push(DAY_SHORT[key]);
  });
  if (!groups.size) {
    box.className = 'alert alert-warning py-2 px-3 small mb-0';
    box.innerHTML = '<i class="bx bx-error-circle me-1"></i>Sin días de atención — el bot no podrá agendar con este doctor.';
    return;
  }
  box.className = 'alert alert-info py-2 px-3 small mb-0';
  const parts = [...groups.values()].map(g =>
    `<strong>${g.days.join(', ')}</strong> ${g.s}–${g.e}` + (g.hb ? ` <span class="text-muted">(comida ${g.bs}–${g.be})</span>` : '')
  );
  box.innerHTML = '<i class="bx bx-calendar-check me-1"></i>Atiende: ' + parts.join(' · ');
}

// ── Abrir modal ────────────────────────────────────────────────
function openDoctorModal(doc = null) {
  const modal = new bootstrap.Modal(document.getElementById('modalDoctor'));
  const form  = document.getElementById('form-doctor');
  form.reset();
  document.getElementById('doctor-error').classList.add('d-none');

  // Reset avatar
  pendingAvatarFile = null;
  setAvatarPreview(doc?.avatar_url || null);

  // Reset horario
  document.getElementById('qf-break').checked = false;
  document.getElementById('qf-break-times').classList.add('d-none');
  DAYS.forEach(key => {
    document.getElementById('day-' + key).checked       = false;
    document.getElementById('break-' + key).checked     = false;
    document.getElementById('start-' + key).value       = '09:00';
    document.getElementById('end-' + key).value         = '14:00';
    document.getElementById('break-start-' + key).value = '14:00';
    document.getElementById('break-end-' + key).value   = '15:00';
    toggleDay(key);
  });

  // Reset consultorio + servicios
  const roomEl = document.getElementById('doc-room'); if (roomEl) roomEl.value = '';
  document.querySelectorAll('.doc-service').forEach(chk => chk.checked = false);

  if (doc) {
    document.getElementById('modal-doctor-title').textContent = 'Editar doctor';
    document.getElementById('doc-id').value       = doc.id;
    document.getElementById('doc-name').value     = doc.name       || '';
    document.getElementById('doc-specialty').value = doc.specialty || '';
    document.getElementById('doc-phone').value    = doc.phone      || '';
    document.getElementById('doc-email').value    = doc.email      || '';
    document.getElementById('doc-license').value  = doc.license_number || '';
    document.getElementById('doc-active').value   = doc.is_active ? 'true' : 'false';
    const roomEl2 = document.getElementById('doc-room'); if (roomEl2) roomEl2.value = doc.room || '';
    // Marcar los servicios que brinda
    const svcIds = doc.service_type_ids || [];
    document.querySelectorAll('.doc-service').forEach(chk => { chk.checked = svcIds.includes(chk.value); });

    // Cargar horario (formato canónico objeto; el descanso es opcional)
    const sc = doc.schedule_config || {};
    DAYS.forEach(key => {
      const day = sc[key];
      if (day && day.active) {
        document.getElementById('day-' + key).checked = true;
        document.getElementById('start-' + key).value = day.start || '09:00';
        document.getElementById('end-' + key).value   = day.end   || '14:00';
        const hasBreak = !!(day.break_start && day.break_end);
        document.getElementById('break-' + key).checked = hasBreak;
        if (hasBreak) {
          document.getElementById('break-start-' + key).value = day.break_start;
          document.getElementById('break-end-' + key).value   = day.break_end;
        }
        toggleDay(key);
      }
    });
  } else {
    document.getElementById('modal-doctor-title').textContent = 'Agregar doctor';
    document.getElementById('doc-id').value = '';
    // Por defecto activo L-V
    ['mon','tue','wed','thu','fri'].forEach(key => {
      document.getElementById('day-' + key).checked = true;
      toggleDay(key);
    });
  }

  renderSummary();
  modal.show();
}

// ── Submit del formulario ──────────────────────────────────────
document.getElementById('form-doctor').addEventListener('submit', async function(e) {
  e.preventDefault();

  const id      = document.getElementById('doc-id').value;
  const errDiv  = document.getElementById('doctor-error');
  const btnText = document.getElementById('btn-save-text');
  const btnSpin = document.getElementById('btn-save-spinner');
  errDiv.classList.add('d-none');
  btnText.classList.add('d-none');
  btnSpin.classList.remove('d-none');

  // Construir payload
  const payload = {
    id:             id || undefined,
    name:           document.getElementById('doc-name').value.trim(),
    specialty:      document.getElementById('doc-specialty').value.trim() || null,
    phone:          document.getElementById('doc-phone').value.trim()    || null,
    email:          document.getElementById('doc-email').value.trim()    || null,
    license_number: document.getElementById('doc-license').value.trim()  || null,
    is_active:      document.getElementById('doc-active').value === 'true',
    room:           document.getElementById('doc-room').value.trim()     || null,
    service_type_ids: [...document.querySelectorAll('.doc-service:checked')].map(c => c.value),
    schedule_config: {},
  };

  // Construir + validar schedule_config (la comida solo se guarda si está activada)
  const schedErrors = [];
  DAYS.forEach(key => {
    const active = document.getElementById('day-' + key).checked;
    const start  = document.getElementById('start-' + key).value || '09:00';
    const end    = document.getElementById('end-' + key).value   || '14:00';
    const hasBreak = active && document.getElementById('break-' + key).checked;
    const bs = document.getElementById('break-start-' + key).value;
    const be = document.getElementById('break-end-' + key).value;

    const cfg = { active, start, end };
    if (hasBreak) { cfg.break_start = bs; cfg.break_end = be; }
    payload.schedule_config[key] = cfg;

    if (active) {
      if (end <= start) schedErrors.push(`${DAY_SHORT[key]}: la hora "Hasta" debe ser mayor que "Desde".`);
      if (hasBreak && !(start <= bs && bs < be && be <= end))
        schedErrors.push(`${DAY_SHORT[key]}: la hora de comida debe quedar dentro del horario y bien ordenada.`);
    }
  });
  if (schedErrors.length) {
    errDiv.innerHTML = '<i class="bx bx-error-circle me-1"></i>' + schedErrors.join('<br>');
    errDiv.classList.remove('d-none');
    btnText.classList.remove('d-none');
    btnSpin.classList.add('d-none');
    return;
  }

  try {
    const res  = await fetch('/api/doctor-save.php', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify(payload),
    });
    const data = await res.json();

    if (!res.ok) throw new Error(data.message || data.error || 'Error al guardar');

    // Subir avatar (si hay) después de obtener id real
    const savedId = data.id || id;
    if (pendingAvatarFile && savedId) {
      const fd = new FormData();
      fd.append('file', pendingAvatarFile);
      fd.append('entity', 'doctor');
      fd.append('id', savedId);
      const ar = await fetch('/api/avatar-upload.php', { method:'POST', body: fd });
      const aj = await ar.json().catch(() => ({}));
      if (!ar.ok) console.warn('Avatar no subido:', aj.error);
    }

    bootstrap.Modal.getInstance(document.getElementById('modalDoctor'))?.hide();
    window.showToast(id ? 'Doctor actualizado' : 'Doctor creado correctamente', 'success');
    location.reload();

  } catch (err) {
    errDiv.textContent = err.message;
    errDiv.classList.remove('d-none');
  } finally {
    btnText.classList.remove('d-none');
    btnSpin.classList.add('d-none');
  }
});

// ── Eliminar doctor ────────────────────────────────────────────
async function deleteDoctor(id, name) {
  const ok = await window.confirmToast?.(
    `<i class="bx bx-trash me-1"></i>¿Eliminar al Dr. <strong>${name}</strong>?<br><small class="text-muted">Esta acción no se puede deshacer.</small>`,
    'Sí, eliminar'
  );
  if (!ok) return;

  try {
    const res  = await fetch('/api/doctor-save.php', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify({ id, action: 'delete' }),
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.message || data.error || 'Error');

    document.querySelector(`tr[data-id="${id}"]`)?.remove();
    window.showToast('<i class="bx bx-check-circle me-1"></i>Doctor eliminado', 'success');
  } catch (err) {
    window.showToast('Error: ' + err.message, 'danger');
  }
}
</script>
