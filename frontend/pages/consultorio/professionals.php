<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/head.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/navbar.php';
require_once __DIR__ . '/../../includes/footer.php';
require_once __DIR__ . '/../../includes/specialty-vocab.php';

requireAuth(); if (!isAdmin()) { header('Location: /index.php'); exit; }

$rawProfs = apiGet('/professionals');
$profs    = array_values(array_filter((array)$rawProfs, 'is_array'));

renderHead('Profesionales — Consultorio');
?>
<div class="layout-wrapper layout-content-navbar"><div class="layout-container">
<?php renderSidebar('consult-professionals'); ?>
<div class="layout-page"><?php renderNavbar('Profesionales'); ?>
<div class="content-wrapper"><div class="container-xxl flex-grow-1 container-p-y">

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
  <div>
    <h4 class="mb-0"><i class="bx bx-briefcase text-primary me-2"></i>Profesionales</h4>
    <small class="text-muted">Psicólogos, abogados, contadores y más</small>
  </div>
  <button class="btn btn-primary btn-sm" onclick="openModal()">
    <i class="bx bx-plus me-1"></i>Agregar profesional
  </button>
</div>

<!-- Grid de cards -->
<div class="row g-3">
  <?php foreach ($profs as $p):
    $sc        = $p['schedule_config'] ?? [];
    $vocab     = specialtyVocab($p['specialty_type'] ?? 'other');
    $dayLabels = ['mon'=>'L','tue'=>'M','wed'=>'X','thu'=>'J','fri'=>'V','sat'=>'S','sun'=>'D'];
    $days = '';
    foreach ($dayLabels as $key => $lbl) {
      if (!empty($sc[$key]['active']))
        $days .= '<span class="badge bg-label-primary px-1">'.$lbl.'</span> ';
    }
    $modIcon = match($p['modality'] ?? 'both') {
      'video'      => '<i class="bx bx-video text-info" title="Solo video"></i>',
      'presencial' => '<i class="bx bx-building text-success" title="Solo presencial"></i>',
      default      => '<i class="bx bx-transfer text-muted" title="Presencial y video"></i>',
    };
  ?>
  <div class="col-12 col-md-6 col-xl-4" data-pro-id="<?= e($p['id']) ?>">
    <div class="card h-100">
      <div class="card-body">
        <div class="d-flex align-items-start gap-3 mb-3">
          <?= avatar(array_merge($p, ['color' => $p['color'] ?? $vocab['color']]), 'md') ?>
          <div class="flex-grow-1">
            <div class="d-flex justify-content-between">
              <div>
                <h6 class="mb-0"><?= e($p['name']) ?></h6>
                <div class="d-flex align-items-center gap-1 mt-1 flex-wrap">
                  <span class="badge <?= $vocab['badge'] ?>" style="font-size:10px">
                    <i class="bx <?= $vocab['icon'] ?> me-1"></i><?= $vocab['label'] ?>
                  </span>
                  <?php if (!empty($p['area'])): ?>
                  <small class="text-muted"><?= e($p['area']) ?></small>
                  <?php endif; ?>
                </div>
              </div>
              <div class="d-flex gap-1">
                <?= $modIcon ?>
                <?php if (!($p['is_active'] ?? true)): ?>
                  <span class="badge bg-label-secondary ms-1">Inactivo</span>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>

        <div class="row g-2 text-center mb-3">
          <?php if (!empty($p['phone'])): ?>
          <div class="col-6">
            <small class="text-muted d-block">Teléfono</small>
            <small><?= e($p['phone']) ?></small>
          </div>
          <?php endif; ?>
          <?php if (!empty($p['license_number'])): ?>
          <div class="col-6">
            <small class="text-muted d-block">Cédula</small>
            <small><?= e($p['license_number']) ?></small>
          </div>
          <?php endif; ?>
        </div>

        <?php if ($days): ?>
        <div class="mb-2">
          <small class="text-muted d-block mb-1">Horario</small>
          <div class="d-flex gap-1 flex-wrap"><?= $days ?></div>
        </div>
        <?php else: ?>
          <small class="text-muted">Sin horario configurado</small>
        <?php endif; ?>

        <?php if (!empty($p['video_link'])): ?>
        <div class="mt-2">
          <a href="<?= e($p['video_link']) ?>" target="_blank" class="badge bg-label-info text-decoration-none">
            <i class="bx bx-video me-1"></i>Link video
          </a>
        </div>
        <?php endif; ?>

        <?php if (!empty($p['bio'])): ?>
        <p class="text-muted small mt-2 mb-0 fst-italic"><?= e(substr($p['bio'], 0, 80)) ?><?= strlen($p['bio']) > 80 ? '…' : '' ?></p>
        <?php endif; ?>
      </div>
      <div class="card-footer d-flex gap-2 py-2">
        <button class="btn btn-sm btn-outline-primary flex-fill"
                onclick='openModal(<?= htmlspecialchars(json_encode($p), ENT_QUOTES) ?>)'>
          <i class="bx bx-edit-alt me-1"></i>Editar
        </button>
        <button class="btn btn-sm btn-outline-danger"
                onclick="deletePro('<?= e($p['id']) ?>', '<?= e($p['name']) ?>')">
          <i class="bx bx-trash"></i>
        </button>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
  <?php if (empty($profs)): ?>
  <div class="col-12">
    <div class="card"><div class="card-body text-center text-muted py-5">
      <i class="bx bx-briefcase bx-lg d-block mb-2 opacity-25"></i>
      Sin profesionales — agrega el primero
    </div></div>
  </div>
  <?php endif; ?>
</div>

</div><?php renderFooter(); ?>

<!-- Modal -->
<div class="modal fade" id="modalPro" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable" style="max-height:92vh;margin-top:4vh">
    <div class="modal-content">
      <div class="modal-header border-0 pb-0">
        <h5 class="modal-title"><i class="bx bx-briefcase text-primary me-2"></i><span id="pro-title">Agregar profesional</span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form id="form-pro">
        <div class="modal-body" style="overflow-y:auto;max-height:calc(92vh - 130px)">
          <input type="hidden" id="pro-id">

          <h6 class="fw-semibold text-muted mb-3"><i class="bx bx-id-card me-1"></i>Datos</h6>

          <!-- Avatar -->
          <div class="d-flex align-items-center gap-3 mb-3">
            <div class="avatar-upload-wrap">
              <img id="pro-avatar-preview" src="/assets/img/avatar-placeholder.svg"
                   class="avatar-preview" alt="Avatar">
              <label for="pro-avatar-file" class="avatar-edit-btn" title="Subir foto">
                <i class="bx bx-camera"></i>
              </label>
              <input type="file" id="pro-avatar-file" accept="image/*" class="d-none">
              <input type="hidden" id="pro-avatar-url">
            </div>
            <div class="small text-muted">
              <div class="fw-semibold mb-1">Foto del profesional</div>
              <div>JPG, PNG o WebP — máx. 4 MB.</div>
              <button type="button" class="btn btn-link btn-sm p-0 text-danger d-none"
                      id="pro-avatar-remove">Quitar foto</button>
            </div>
          </div>

          <div class="row g-3">
            <div class="col-md-6"><label class="form-label">Nombre <span class="text-danger">*</span></label>
              <input type="text" class="form-control" id="pro-name" required placeholder="Lic. Ana Torres"></div>
            <div class="col-md-6">
              <label class="form-label d-flex justify-content-between align-items-center">
                Tipo de especialidad
                <span id="pro-spec-badge" class="badge" style="font-size:10px"></span>
              </label>
              <select class="form-select" id="pro-specialty-type" onchange="onSpecialtyTypeChange()">
                <option value="psychology">🧠 Psicología / Salud mental</option>
                <option value="legal">⚖️ Derecho / Legal</option>
                <option value="accounting">📊 Contabilidad / Fiscal</option>
                <option value="coaching">🎯 Coaching / Desarrollo</option>
                <option value="nutrition">🥗 Nutrición / Bienestar</option>
                <option value="medical">🩺 Medicina / Salud</option>
                <option value="other">👤 Otro</option>
              </select>
              <small class="text-muted">Define vocabulario: paciente, cliente, contribuyente…</small>
            </div>
            <div class="col-md-6"><label class="form-label">Área</label>
              <input type="text" class="form-control" id="pro-area" placeholder="Psicología, Derecho, Contabilidad…"></div>
            <div class="col-md-6"><label class="form-label">Sub-especialidad</label>
              <input type="text" class="form-control" id="pro-specialty" placeholder="Terapia cognitivo-conductual…"></div>
            <div class="col-md-6"><label class="form-label">Modalidad</label>
              <select class="form-select" id="pro-modality">
                <option value="both">Presencial y Video</option>
                <option value="presencial">Solo Presencial</option>
                <option value="video">Solo Video</option>
              </select></div>
            <div class="col-md-6"><label class="form-label">Teléfono</label>
              <input type="tel" class="form-control" id="pro-phone" placeholder="+52 55 1234 5678"></div>
            <div class="col-md-6"><label class="form-label">Email</label>
              <input type="email" class="form-control" id="pro-email"></div>
            <div class="col-md-6"><label class="form-label">Cédula profesional</label>
              <input type="text" class="form-control" id="pro-license"></div>
            <div class="col-md-6"><label class="form-label">Estado</label>
              <select class="form-select" id="pro-active">
                <option value="true">Activo</option><option value="false">Inactivo</option>
              </select></div>
            <div class="col-12"><label class="form-label">Link de videollamada</label>
              <input type="url" class="form-control" id="pro-videolink" placeholder="https://meet.google.com/…">
              <small class="text-muted">Zoom, Google Meet, Teams, etc.</small></div>
            <div class="col-12"><label class="form-label">Bio / Presentación</label>
              <textarea class="form-control" id="pro-bio" rows="2" placeholder="Descripción breve para el agente…"></textarea></div>
          </div>

          <hr class="my-3">
          <h6 class="fw-semibold mb-1"><i class="bx bx-time me-1"></i>Horario de atención</h6>
          <p class="text-muted small mb-3"><i class="bx bx-info-circle me-1"></i>Días y horas reales en que este profesional da consulta. <strong>El bot solo ofrecerá citas dentro de estos horarios.</strong></p>

          <!-- Llenado rápido -->
          <div class="border rounded p-2 mb-3" style="background:var(--bs-light,#f8f8ff)">
            <div class="d-flex align-items-end gap-2 flex-wrap">
              <div><label class="form-label small mb-0">Desde</label><input type="time" class="form-control form-control-sm" id="pro-qf-start" value="09:00" style="width:110px"></div>
              <div><label class="form-label small mb-0">Hasta</label><input type="time" class="form-control form-control-sm" id="pro-qf-end" value="14:00" style="width:110px"></div>
              <div class="form-check mb-1 ms-1">
                <input class="form-check-input" type="checkbox" id="pro-qf-break" onchange="document.getElementById('pro-qf-break-times').classList.toggle('d-none', !this.checked)">
                <label class="form-check-label small" for="pro-qf-break">Hora de comida</label>
              </div>
              <div id="pro-qf-break-times" class="d-none d-flex align-items-center gap-1">
                <input type="time" class="form-control form-control-sm" id="pro-qf-bstart" value="14:00" style="width:105px">
                <span class="text-muted">–</span>
                <input type="time" class="form-control form-control-sm" id="pro-qf-bend" value="15:00" style="width:105px">
              </div>
              <button type="button" class="btn btn-sm btn-outline-primary" onclick="applyProQuickFill()"><i class="bx bx-copy-alt me-1"></i>Aplicar a días marcados</button>
            </div>
            <small class="text-muted d-block mt-1">Marca los días abajo, ajusta las horas aquí y aplícalas a todos de un clic.</small>
          </div>

          <div class="table-responsive">
            <table class="table table-sm align-middle">
              <thead class="table-light"><tr><th style="width:70px">Atiende</th><th>Día</th><th style="width:120px">Desde</th><th style="width:120px">Hasta</th><th>Hora de comida <small class="text-muted fw-normal">(opcional)</small></th></tr></thead>
              <tbody>
                <?php foreach (['mon'=>'Lunes','tue'=>'Martes','wed'=>'Miércoles','thu'=>'Jueves','fri'=>'Viernes','sat'=>'Sábado','sun'=>'Domingo'] as $k => $lbl): ?>
                <tr id="pro-row-<?= $k ?>" class="sched-row">
                  <td><div class="form-check form-switch mb-0"><input class="form-check-input pro-day" type="checkbox" id="pro-day-<?= $k ?>" data-key="<?= $k ?>" onchange="toggleProDay('<?= $k ?>')"></div></td>
                  <td class="fw-semibold"><?= $lbl ?></td>
                  <td><input type="time" class="form-control form-control-sm pro-inp" id="pro-start-<?= $k ?>" value="09:00" disabled onchange="renderProSummary()"></td>
                  <td><input type="time" class="form-control form-control-sm pro-inp" id="pro-end-<?= $k ?>" value="14:00" disabled onchange="renderProSummary()"></td>
                  <td>
                    <div class="d-flex align-items-center gap-1">
                      <div class="form-check mb-0 me-1"><input class="form-check-input pro-break-toggle pro-inp" type="checkbox" id="pro-break-<?= $k ?>" disabled onchange="toggleProBreak('<?= $k ?>')"></div>
                      <input type="time" class="form-control form-control-sm pro-break-inp" id="pro-bs-<?= $k ?>" value="14:00" disabled style="width:105px" onchange="renderProSummary()">
                      <span class="text-muted pro-break-inp">–</span>
                      <input type="time" class="form-control form-control-sm pro-break-inp" id="pro-be-<?= $k ?>" value="15:00" disabled style="width:105px" onchange="renderProSummary()">
                    </div>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <div id="pro-sched-summary" class="alert alert-info py-2 px-3 small mb-0"></div>
          <div id="pro-error" class="alert alert-danger mt-2 d-none"></div>
        </div>
        <div class="modal-footer border-0 pt-0">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-primary">
            <span id="pro-btn-text"><i class="bx bx-save me-1"></i>Guardar</span>
            <span id="pro-btn-spin" class="d-none"><span class="spinner-border spinner-border-sm me-1"></span>Guardando…</span>
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
const DAYS = ['mon','tue','wed','thu','fri','sat','sun'];
const SPEC_MAP = <?= specialtyMapJson() ?>;
const AVATAR_PLACEHOLDER = '/assets/img/avatar-placeholder.svg';

let pendingAvatarFile = null;
function setProAvatarPreview(url) {
  const img = document.getElementById('pro-avatar-preview');
  const rem = document.getElementById('pro-avatar-remove');
  const hidden = document.getElementById('pro-avatar-url');
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
document.getElementById('pro-avatar-file').addEventListener('change', e => {
  const f = e.target.files[0];
  if (!f) return;
  if (f.size > 4 * 1024 * 1024) { window.showToast('Máx 4 MB', 'danger'); return; }
  pendingAvatarFile = f;
  setProAvatarPreview(URL.createObjectURL(f));
});
document.getElementById('pro-avatar-remove').addEventListener('click', () => {
  pendingAvatarFile = null;
  setProAvatarPreview(null);
});

function onSpecialtyTypeChange() {
  const val  = document.getElementById('pro-specialty-type').value;
  const spec = SPEC_MAP[val] || SPEC_MAP['other'];
  const badge = document.getElementById('pro-spec-badge');
  badge.textContent = spec.emoji + ' ' + spec.label;
  badge.style.background = spec.color + '22';
  badge.style.color = spec.color;
}

const PRO_DAY_SHORT = { mon:'Lun', tue:'Mar', wed:'Mié', thu:'Jue', fri:'Vie', sat:'Sáb', sun:'Dom' };

function toggleProDay(k) {
  const on = document.getElementById('pro-day-' + k).checked;
  document.querySelectorAll('#pro-row-' + k + ' .pro-inp').forEach(i => i.disabled = !on);
  document.getElementById('pro-row-' + k).classList.toggle('text-muted', !on);
  toggleProBreak(k);
  renderProSummary();
}

function toggleProBreak(k) {
  const on = document.getElementById('pro-day-' + k).checked && document.getElementById('pro-break-' + k).checked;
  document.querySelectorAll('#pro-row-' + k + ' .pro-break-inp').forEach(el => {
    if (el.tagName === 'INPUT') el.disabled = !on;
    el.style.opacity = on ? '1' : '.45';
  });
  renderProSummary();
}

function applyProQuickFill() {
  const s = document.getElementById('pro-qf-start').value || '09:00';
  const e = document.getElementById('pro-qf-end').value   || '14:00';
  const hb = document.getElementById('pro-qf-break').checked;
  const bs = document.getElementById('pro-qf-bstart').value || '14:00';
  const be = document.getElementById('pro-qf-bend').value   || '15:00';
  let n = 0;
  DAYS.forEach(k => {
    if (!document.getElementById('pro-day-' + k).checked) return;
    document.getElementById('pro-start-' + k).value   = s;
    document.getElementById('pro-end-' + k).value     = e;
    document.getElementById('pro-break-' + k).checked = hb;
    document.getElementById('pro-bs-' + k).value      = bs;
    document.getElementById('pro-be-' + k).value      = be;
    toggleProBreak(k); n++;
  });
  if (!n) window.showToast?.('Marca primero los días que atiende', 'warning');
  else window.showToast?.(`Horario aplicado a ${n} día(s)`, 'success');
  renderProSummary();
}

function renderProSummary() {
  const box = document.getElementById('pro-sched-summary');
  if (!box) return;
  const groups = new Map();
  DAYS.forEach(k => {
    if (!document.getElementById('pro-day-' + k).checked) return;
    const s = document.getElementById('pro-start-' + k).value;
    const e = document.getElementById('pro-end-' + k).value;
    const hb = document.getElementById('pro-break-' + k).checked;
    const bs = document.getElementById('pro-bs-' + k).value;
    const be = document.getElementById('pro-be-' + k).value;
    const sig = `${s}|${e}|${hb ? bs + '-' + be : ''}`;
    if (!groups.has(sig)) groups.set(sig, { days: [], s, e, hb, bs, be });
    groups.get(sig).days.push(PRO_DAY_SHORT[k]);
  });
  if (!groups.size) {
    box.className = 'alert alert-warning py-2 px-3 small mb-0';
    box.innerHTML = '<i class="bx bx-error-circle me-1"></i>Sin días de atención — el bot no podrá agendar con este profesional.';
    return;
  }
  box.className = 'alert alert-info py-2 px-3 small mb-0';
  const parts = [...groups.values()].map(g =>
    `<strong>${g.days.join(', ')}</strong> ${g.s}–${g.e}` + (g.hb ? ` <span class="text-muted">(comida ${g.bs}–${g.be})</span>` : '')
  );
  box.innerHTML = '<i class="bx bx-calendar-check me-1"></i>Atiende: ' + parts.join(' · ');
}

function openModal(p = null) {
  document.getElementById('form-pro').reset();
  document.getElementById('pro-error').classList.add('d-none');
  pendingAvatarFile = null;
  setProAvatarPreview(p?.avatar_url || null);
  document.getElementById('pro-qf-break').checked = false;
  document.getElementById('pro-qf-break-times').classList.add('d-none');
  DAYS.forEach(k => {
    document.getElementById('pro-day-' + k).checked   = false;
    document.getElementById('pro-break-' + k).checked = false;
    document.getElementById('pro-start-' + k).value   = '09:00';
    document.getElementById('pro-end-' + k).value     = '14:00';
    document.getElementById('pro-bs-' + k).value      = '14:00';
    document.getElementById('pro-be-' + k).value      = '15:00';
    toggleProDay(k);
  });

  if (p) {
    document.getElementById('pro-title').textContent    = 'Editar profesional';
    document.getElementById('pro-id').value             = p.id;
    document.getElementById('pro-name').value           = p.name || '';
    document.getElementById('pro-specialty-type').value = p.specialty_type || 'other';
    document.getElementById('pro-area').value           = p.area || '';
    document.getElementById('pro-specialty').value      = p.specialty || '';
    document.getElementById('pro-modality').value       = p.modality || 'both';
    document.getElementById('pro-phone').value          = p.phone || '';
    document.getElementById('pro-email').value          = p.email || '';
    document.getElementById('pro-license').value        = p.license_number || '';
    document.getElementById('pro-active').value         = p.is_active ? 'true' : 'false';
    document.getElementById('pro-videolink').value      = p.video_link || '';
    document.getElementById('pro-bio').value            = p.bio || '';

    const sc = p.schedule_config || {};
    DAYS.forEach(k => {
      const d = sc[k];
      if (d?.active) {
        document.getElementById('pro-day-' + k).checked = true;
        document.getElementById('pro-start-' + k).value = d.start || '09:00';
        document.getElementById('pro-end-' + k).value   = d.end   || '14:00';
        const hb = !!(d.break_start && d.break_end);
        document.getElementById('pro-break-' + k).checked = hb;
        if (hb) {
          document.getElementById('pro-bs-' + k).value = d.break_start;
          document.getElementById('pro-be-' + k).value = d.break_end;
        }
        toggleProDay(k);
      }
    });
  } else {
    document.getElementById('pro-title').textContent    = 'Agregar profesional';
    document.getElementById('pro-id').value             = '';
    document.getElementById('pro-specialty-type').value = 'other';
    ['mon','tue','wed','thu','fri'].forEach(k => {
      document.getElementById('pro-day-' + k).checked = true;
      toggleProDay(k);
    });
  }
  onSpecialtyTypeChange(); // sync badge with current selection
  renderProSummary();
  new bootstrap.Modal(document.getElementById('modalPro')).show();
}

document.getElementById('form-pro').addEventListener('submit', async e => {
  e.preventDefault();
  const id = document.getElementById('pro-id').value;
  const errDiv = document.getElementById('pro-error');
  document.getElementById('pro-btn-text').classList.add('d-none');
  document.getElementById('pro-btn-spin').classList.remove('d-none');
  errDiv.classList.add('d-none');

  const sc = {};
  const schedErrors = [];
  DAYS.forEach(k => {
    const active = document.getElementById('pro-day-' + k).checked;
    const start  = document.getElementById('pro-start-' + k).value || '09:00';
    const end    = document.getElementById('pro-end-' + k).value   || '14:00';
    const hasBreak = active && document.getElementById('pro-break-' + k).checked;
    const bs = document.getElementById('pro-bs-' + k).value;
    const be = document.getElementById('pro-be-' + k).value;
    const cfg = { active, start, end };
    if (hasBreak) { cfg.break_start = bs; cfg.break_end = be; }
    sc[k] = cfg;
    if (active) {
      if (end <= start) schedErrors.push(`${PRO_DAY_SHORT[k]}: la hora "Hasta" debe ser mayor que "Desde".`);
      if (hasBreak && !(start <= bs && bs < be && be <= end))
        schedErrors.push(`${PRO_DAY_SHORT[k]}: la hora de comida debe quedar dentro del horario y bien ordenada.`);
    }
  });
  if (schedErrors.length) {
    errDiv.innerHTML = '<i class="bx bx-error-circle me-1"></i>' + schedErrors.join('<br>');
    errDiv.classList.remove('d-none');
    document.getElementById('pro-btn-text').classList.remove('d-none');
    document.getElementById('pro-btn-spin').classList.add('d-none');
    return;
  }

  const specType = document.getElementById('pro-specialty-type').value;
  const specColor = (SPEC_MAP[specType] || SPEC_MAP['other']).color;

  const payload = {
    id:             id || undefined,
    name:           document.getElementById('pro-name').value.trim(),
    specialty_type: specType,
    color:          specColor,
    area:           document.getElementById('pro-area').value.trim()      || null,
    specialty:      document.getElementById('pro-specialty').value.trim() || null,
    modality:       document.getElementById('pro-modality').value,
    phone:          document.getElementById('pro-phone').value.trim()     || null,
    email:          document.getElementById('pro-email').value.trim()     || null,
    license_number: document.getElementById('pro-license').value.trim()   || null,
    is_active:      document.getElementById('pro-active').value === 'true',
    video_link:     document.getElementById('pro-videolink').value.trim() || null,
    bio:            document.getElementById('pro-bio').value.trim()       || null,
    schedule_config: sc,
  };

  try {
    const res  = await fetch('/api/professional-save.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload) });
    const data = await res.json();
    if (!res.ok) throw new Error(data.message || data.error || 'Error');

    const savedId = data.id || id;
    if (pendingAvatarFile && savedId) {
      const fd = new FormData();
      fd.append('file', pendingAvatarFile);
      fd.append('entity', 'professional');
      fd.append('id', savedId);
      const ar = await fetch('/api/avatar-upload.php', { method:'POST', body: fd });
      if (!ar.ok) console.warn('Avatar no subido');
    }

    bootstrap.Modal.getInstance(document.getElementById('modalPro'))?.hide();
    window.showToast(id ? 'Profesional actualizado' : 'Profesional creado', 'success');
    location.reload();
  } catch(err) {
    errDiv.textContent = err.message; errDiv.classList.remove('d-none');
  } finally {
    document.getElementById('pro-btn-text').classList.remove('d-none');
    document.getElementById('pro-btn-spin').classList.add('d-none');
  }
});

async function deletePro(id, name) {
  const ok = await window.confirmToast?.(
    `<i class="bx bx-trash me-1"></i>¿Eliminar a <strong>${name}</strong>?`,
    'Sí, eliminar'
  );
  if (!ok) return;
  const res  = await fetch('/api/professional-save.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({id, action:'delete'}) });
  const data = await res.json();
  if (!res.ok) { window.showToast('Error: ' + (data.error||''), 'danger'); return; }
  document.querySelector(`[data-pro-id="${id}"]`)?.remove();
  window.showToast('<i class="bx bx-check-circle me-1"></i>Profesional eliminado', 'success');
}
</script>
