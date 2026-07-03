<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/head.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/navbar.php';
require_once __DIR__ . '/../../includes/footer.php';
require_once __DIR__ . '/../../includes/specialty-vocab.php';

requireAuth(); if (!isAdmin()) { header('Location: /index.php'); exit; }

// ── Datos PHP para los 3 tabs ──────────────────────────────────────────────
$rawProfs = apiGet('/professionals');
$profs    = array_values(array_filter((array)$rawProfs, 'is_array'));

$rawTypes = apiGet('/consultorio/session-types');
$stypes   = array_values(array_filter((array)$rawTypes, 'is_array'));

// Tab 1: filtros y series
$filterProf   = $_GET['professional_id'] ?? '';
$filterStatus = $_GET['status']          ?? '';
$filterFrom   = $_GET['from']            ?? date('Y-m-01');
$filterTo     = $_GET['to']              ?? date('Y-m-t');

$seriesParams = http_build_query(array_filter([
    'professional_id' => $filterProf,
    'from'            => $filterFrom,
    'to'              => $filterTo,
]));
$rawSeries = apiGet('/sessions/series/list' . ($seriesParams ? "?$seriesParams" : ''));
$series    = array_values(array_filter((array)$rawSeries, 'is_array'));

$rawStats = apiGet('/sessions/stats/summary' . ($filterProf ? "?professional_id=$filterProf" : ''));
$stats    = is_array($rawStats) ? $rawStats : [];

$statusLabels = [
    'pending_professional' => ['Pend. profesional', 'bg-label-warning'],
    'pending_patient'      => ['Pend. paciente',    'bg-label-info'],
    'confirmed'            => ['Confirmada',         'bg-label-success'],
    'completed'            => ['Completada',         'bg-success text-white'],
    'cancelled'            => ['Cancelada',          'bg-label-secondary'],
    'no_show'              => ['No se presentó',     'bg-label-danger'],
];

// Tab activo según hash → PHP no puede, lo maneja JS
$activeTab = $_GET['tab'] ?? 'series';

renderHead('Sesiones — Consultorio');
?>
<style>
/* ── Calendario ────────────────────────────────────────────── */
.cal-table { font-size: 12px; border-collapse: collapse; table-layout: fixed; min-width: 600px; }
.cal-table th, .cal-table td { border: 1px solid #e4e6ea; padding: 0; }
.cal-table th { background: #f8f9fa; padding: 6px 4px; text-align: center; }
.cal-time  { width: 52px; text-align: right; padding-right: 6px !important; color: #adb5bd; font-size: 11px; vertical-align: top; padding-top: 4px !important; }
.cal-cell-free  { background: #fff; cursor: pointer; transition: background .15s; }
.cal-cell-free:hover  { background: #e8f4fd !important; }
.cal-cell-break { background: #fff8f0; }
.cal-cell-off   { background: #f8f9fa; }
.cal-cell-session { vertical-align: top; padding: 3px 4px !important; }
.cal-session-inner { border-radius: 4px; padding: 2px 4px; height: 100%; overflow: hidden; }
.day-btn.active { box-shadow: 0 0 0 2px var(--bs-primary); }
/* ── Disponibilidad ────────────────────────────────────────── */
.avail-slot { display:inline-block; font-size:11px; padding:2px 6px; border-radius:4px;
              margin:2px; background:#e8f4fd; color:#0d6efd; cursor:pointer; border:1px solid #b6d4fe; }
.avail-slot:hover { background:#0d6efd; color:#fff; }
</style>

<div class="layout-wrapper layout-content-navbar"><div class="layout-container">
<?php renderSidebar('consult-sessions'); ?>
<div class="layout-page"><?php renderNavbar('Sesiones'); ?>
<div class="content-wrapper"><div class="container-xxl flex-grow-1 container-p-y">

<!-- Header -->
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
  <div>
    <h4 class="mb-0"><i class="bx bx-calendar text-primary me-2"></i>Sesiones</h4>
    <small class="text-muted">Agenda, disponibilidad y gestión de citas</small>
  </div>
  <button class="btn btn-primary btn-sm" onclick="openBookingModal()">
    <i class="bx bx-plus me-1"></i>Agendar sesión
  </button>
</div>

<!-- Stats cards -->
<div class="row g-2 mb-3">
  <?php
  $statDef = [
    'pending_professional' => ['Pend. profesional','bx-time-five',  'warning'],
    'pending_patient'      => ['Pend. paciente',   'bx-user-check', 'info'],
    'confirmed'            => ['Confirmadas',       'bx-check-circle','success'],
    'completed'            => ['Completadas',       'bx-badge-check','primary'],
    'cancelled'            => ['Canceladas',        'bx-x-circle',   'secondary'],
    'no_show'              => ['No se presentó',   'bx-ghost',       'danger'],
  ];
  foreach ($statDef as $key => [$label, $icon, $color]):
    $count = (int)($stats[$key] ?? 0);
  ?>
  <div class="col-6 col-md-4 col-lg-2">
    <div class="card text-center">
      <div class="card-body py-2 px-1">
        <i class="bx <?= $icon ?> bx-sm text-<?= $color ?>"></i>
        <h6 class="mb-0 mt-1"><?= $count ?></h6>
        <small class="text-muted" style="font-size:10px"><?= $label ?></small>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- ── Nav tabs ───────────────────────────────────────────────────────── -->
<ul class="nav nav-tabs mb-3" id="sessionTabs">
  <li class="nav-item">
    <a class="nav-link active" id="tab-series-btn" data-bs-toggle="tab" href="#tab-series">
      <i class="bx bx-list-ul me-1"></i>Series
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link" id="tab-semana-btn" data-bs-toggle="tab" href="#tab-semana">
      <i class="bx bx-calendar-week me-1"></i>Semana
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link" id="tab-avail-btn" data-bs-toggle="tab" href="#tab-avail">
      <i class="bx bx-time me-1"></i>Disponibilidad
    </a>
  </li>
</ul>

<div class="tab-content">

<!-- ═══════════════════════════════════════════════════════════
     TAB 1 — SERIES
     ═══════════════════════════════════════════════════════════ -->
<div class="tab-pane fade show active" id="tab-series">

  <!-- Filtros -->
  <div class="card mb-3">
    <div class="card-body py-2">
      <form method="GET" class="row g-2 align-items-end">
        <input type="hidden" name="tab" value="series">
        <div class="col-md-3">
          <label class="form-label small mb-1">Profesional</label>
          <select class="form-select form-select-sm" name="professional_id">
            <option value="">Todos</option>
            <?php foreach ($profs as $pr): ?>
            <option value="<?= e($pr['id']) ?>" <?= $filterProf === $pr['id'] ? 'selected' : '' ?>><?= e($pr['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label small mb-1">Desde</label>
          <input type="date" class="form-control form-control-sm" name="from" value="<?= e($filterFrom) ?>">
        </div>
        <div class="col-md-2">
          <label class="form-label small mb-1">Hasta</label>
          <input type="date" class="form-control form-control-sm" name="to" value="<?= e($filterTo) ?>">
        </div>
        <div class="col-md-auto">
          <button class="btn btn-primary btn-sm" type="submit"><i class="bx bx-filter me-1"></i>Filtrar</button>
          <a href="?" class="btn btn-outline-secondary btn-sm ms-1">Limpiar</a>
        </div>
      </form>
    </div>
  </div>

  <!-- Series -->
  <?php foreach ($series as $ser):
    $proName   = $ser['professional_name'] ?? '—';
    $typeName  = $ser['session_type_name'] ?? '—';
    $patient   = $ser['patient_name']      ?? '—';
    $phone     = $ser['patient_phone']     ?? '';
    $total     = (int)($ser['total_sessions'] ?? 1);
    $conf      = (int)($ser['sessions_confirmed_count'] ?? 0);
    $serStatus = $ser['status'] ?? 'pending_professional';
    [$sl, $sc] = $statusLabels[$serStatus] ?? ['Desconocido','bg-label-secondary'];
  ?>
  <div class="card mb-2">
    <div class="card-header d-flex align-items-center justify-content-between py-2 flex-wrap gap-2">
      <div class="d-flex align-items-center gap-3">
        <div>
          <span class="fw-semibold"><?= e($patient) ?></span>
          <?php if ($phone): ?><small class="text-muted ms-2"><?= e($phone) ?></small><?php endif; ?>
        </div>
        <span class="badge <?= $sc ?>"><?= $sl ?></span>
      </div>
      <div class="d-flex align-items-center gap-2 text-muted small">
        <span><i class="bx bx-briefcase me-1"></i><?= e($proName) ?></span>·
        <span><?= e($typeName) ?></span>·
        <span><?= $conf ?>/<?= $total ?> confirmadas</span>
      </div>
      <div class="d-flex gap-1">
        <?php if ($serStatus === 'pending_professional'): ?>
        <button class="btn btn-sm btn-success" onclick="confirmSeries('<?= e($ser['id']) ?>')">
          <i class="bx bx-check-double me-1"></i>Confirmar
        </button>
        <?php endif; ?>
        <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse"
                data-bs-target="#series-<?= e($ser['id']) ?>">
          <i class="bx bx-chevron-down"></i>
        </button>
      </div>
    </div>
    <div class="collapse" id="series-<?= e($ser['id']) ?>">
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead class="table-light">
            <tr><th>#</th><th>Fecha</th><th>Modalidad</th><th>Estado</th><th></th></tr>
          </thead>
          <tbody class="series-body" data-series="<?= e($ser['id']) ?>">
            <tr><td colspan="5" class="text-center text-muted py-3">
              <span class="spinner-border spinner-border-sm me-2"></span>Cargando…
            </td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
  <?php if (empty($series)): ?>
  <div class="card"><div class="card-body text-center text-muted py-5">
    <i class="bx bx-calendar bx-lg d-block mb-2 opacity-25"></i>
    Sin series en este período
  </div></div>
  <?php endif; ?>
</div><!-- /tab-series -->


<!-- ═══════════════════════════════════════════════════════════
     TAB 2 — SEMANA (CALENDARIO)
     ═══════════════════════════════════════════════════════════ -->
<div class="tab-pane fade" id="tab-semana">
  <div class="card">
    <div class="card-header py-2">
      <!-- Week navigation -->
      <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2">
          <button class="btn btn-sm btn-outline-secondary" onclick="changeWeek(-1)">
            <i class="bx bx-chevron-left"></i>
          </button>
          <span id="week-label" class="fw-semibold small"></span>
          <button class="btn btn-sm btn-outline-secondary" onclick="changeWeek(1)">
            <i class="bx bx-chevron-right"></i>
          </button>
          <button class="btn btn-sm btn-outline-primary" onclick="goToday()">Hoy</button>
        </div>
        <!-- Day tabs -->
        <div id="cal-day-tabs" class="d-flex flex-wrap gap-1"></div>
      </div>
    </div>
    <div class="card-body p-0">
      <!-- Legend -->
      <div class="d-flex gap-3 p-2 border-bottom flex-wrap" style="font-size:11px">
        <span><span style="display:inline-block;width:12px;height:12px;background:#e8f4fd;border:1px solid #b6d4fe;border-radius:2px;vertical-align:middle"></span> Libre</span>
        <span><span style="display:inline-block;width:12px;height:12px;background:#f8f9fa;border:1px solid #dee2e6;border-radius:2px;vertical-align:middle"></span> Fuera de horario</span>
        <span><span style="display:inline-block;width:12px;height:12px;background:#fff8f0;border:1px solid #ffe4bc;border-radius:2px;vertical-align:middle"></span> Descanso</span>
        <span class="text-muted">Clic en slot libre para agendar</span>
      </div>
      <!-- Calendar grid -->
      <div style="overflow-x:auto">
        <div id="cal-loading" class="text-center text-muted py-5 d-none">
          <span class="spinner-border spinner-border-sm me-2"></span>Cargando…
        </div>
        <div id="cal-grid"></div>
      </div>
    </div>
  </div>
</div><!-- /tab-semana -->


<!-- ═══════════════════════════════════════════════════════════
     TAB 3 — DISPONIBILIDAD
     ═══════════════════════════════════════════════════════════ -->
<div class="tab-pane fade" id="tab-avail">
  <div class="card">
    <div class="card-header py-2 d-flex align-items-center justify-content-between">
      <h6 class="mb-0">Próximos huecos disponibles</h6>
      <div class="d-flex align-items-center gap-2">
        <label class="small text-muted mb-0">Días:</label>
        <select class="form-select form-select-sm" id="avail-days" style="width:80px" onchange="loadAvailability()">
          <option value="7">7</option>
          <option value="14" selected>14</option>
          <option value="30">30</option>
        </select>
        <button class="btn btn-sm btn-outline-primary" onclick="loadAvailability()">
          <i class="bx bx-refresh me-1"></i>Actualizar
        </button>
      </div>
    </div>
    <div class="card-body">
      <div id="avail-loading" class="text-center text-muted py-4 d-none">
        <span class="spinner-border spinner-border-sm me-2"></span>Cargando disponibilidad…
      </div>
      <div id="avail-grid"></div>
    </div>
  </div>
</div><!-- /tab-avail -->

</div><!-- /tab-content -->
</div><?php renderFooter(); ?>


<!-- ═══════════════════════════════════════════════════════════
     MODAL: AGENDAR SESIÓN (crear serie)
     ═══════════════════════════════════════════════════════════ -->
<div class="modal fade" id="modalBook" tabindex="-1">
  <div class="modal-dialog modal-dialog-scrollable" style="max-height:92vh;margin-top:4vh">
    <div class="modal-content">
      <div class="modal-header border-0 pb-0">
        <h5 class="modal-title"><i class="bx bx-calendar-plus text-primary me-2"></i>Agendar sesión</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form id="form-book">
        <div class="modal-body" style="overflow-y:auto;max-height:calc(92vh - 130px)">

          <h6 class="fw-semibold text-muted mb-2 d-flex align-items-center gap-2">
            <i class="bx bx-user me-1"></i>
            <span id="book-person-label">Paciente</span>
            <span id="book-spec-badge" class="badge ms-1" style="font-size:10px;display:none"></span>
          </h6>
          <div class="row g-3 mb-3">
            <div class="col-md-6">
              <label class="form-label"><span id="book-person-name-label">Nombre del paciente</span> <span class="text-danger">*</span></label>
              <input type="text" class="form-control" id="book-patient-name" required></div>
            <div class="col-md-6"><label class="form-label">Teléfono</label>
              <input type="tel" class="form-control" id="book-patient-phone" placeholder="+52 55 …"></div>
            <div class="col-12"><label class="form-label">Email</label>
              <input type="email" class="form-control" id="book-patient-email"></div>
          </div>

          <hr class="my-3">
          <h6 class="fw-semibold text-muted mb-2"><i class="bx bx-briefcase me-1"></i>Sesión</h6>
          <div class="row g-3 mb-3">
            <div class="col-md-6"><label class="form-label">Profesional <span class="text-danger">*</span></label>
              <select class="form-select" id="book-prof" onchange="onBookProfChange()" required>
                <option value="">— Selecciona —</option>
                <?php foreach ($profs as $pr): ?>
                <option value="<?= e($pr['id']) ?>"><?= e($pr['name']) ?></option>
                <?php endforeach; ?>
              </select></div>
            <div class="col-md-6"><label class="form-label">Tipo de sesión</label>
              <select class="form-select" id="book-stype" onchange="onBookStypeChange()">
                <option value="">— Selecciona —</option>
                <?php foreach ($stypes as $st): ?>
                <option value="<?= e($st['id']) ?>"
                        data-count="<?= (int)($st['session_count'] ?? 1) ?>"
                        data-freq="<?= e($st['frequency'] ?? 'weekly') ?>"
                        data-duration="<?= (int)($st['duration_mins'] ?? 60) ?>"
                        data-modality="<?= e($st['modality'] ?? 'both') ?>"
                        data-profs='<?= htmlspecialchars(json_encode(array_column(
                            is_array($st['professional_ids'] ?? null) ? ($st['professional_ids']) : [], null
                        )), ENT_QUOTES) ?>'>
                  <?= e($st['name']) ?>
                </option>
                <?php endforeach; ?>
              </select></div>
            <div class="col-md-6"><label class="form-label">Primera sesión <span class="text-danger">*</span></label>
              <input type="datetime-local" class="form-control" id="book-datetime" required></div>
            <div class="col-md-6"><label class="form-label">Duración (minutos)</label>
              <input type="number" class="form-control" id="book-duration" min="15" max="480" step="5" value="60"></div>
            <div class="col-md-4"><label class="form-label">Número de sesiones</label>
              <input type="number" class="form-control" id="book-count" min="1" max="52" value="1"></div>
            <div class="col-md-4"><label class="form-label">Frecuencia</label>
              <select class="form-select" id="book-freq">
                <option value="single">Una sola vez</option>
                <option value="weekly" selected>Semanal</option>
                <option value="biweekly">Quincenal</option>
                <option value="monthly">Mensual</option>
              </select></div>
            <div class="col-md-4"><label class="form-label">Modalidad <span class="text-danger">*</span></label>
              <select class="form-select" id="book-modality" required>
                <option value="presencial">Presencial</option>
                <option value="video">Video</option>
              </select></div>
          </div>

          <div class="mb-2">
            <label class="form-label">Notas internas</label>
            <textarea class="form-control" id="book-notes" rows="2" placeholder="Solo logística (no datos sensibles)"></textarea>
          </div>

          <div id="book-error" class="alert alert-danger mt-2 d-none"></div>
        </div>
        <div class="modal-footer border-0 pt-0">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-primary">
            <span id="book-btn-text"><i class="bx bx-calendar-plus me-1"></i>Agendar</span>
            <span id="book-btn-spin" class="d-none"><span class="spinner-border spinner-border-sm me-1"></span>Agendando…</span>
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- MODAL: cambiar estado de sesión individual -->
<div class="modal fade" id="modalStatus" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header border-0">
        <h6 class="modal-title">Actualizar estado</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="status-session-id">
        <select class="form-select" id="status-select">
          <?php foreach ($statusLabels as $k => [$label, ]): ?>
          <option value="<?= $k ?>"><?= $label ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="modal-footer border-0 pt-0">
        <button class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-sm btn-primary" onclick="saveStatus()">Guardar</button>
      </div>
    </div>
  </div>
</div>


<!-- ════════════════════════════════════════════════════════════
     JAVASCRIPT
     ════════════════════════════════════════════════════════════ -->
<script>
// ── Datos del servidor ──────────────────────────────────────────────────────
const PROFS  = <?= json_encode(array_values(array_filter($profs, fn($p) => $p['is_active'] ?? true))) ?>;
const STYPES = <?= json_encode($stypes) ?>;
const STATUS_LABELS = <?= json_encode(array_map(fn($v) => $v[0], $statusLabels)) ?>;
const STATUS_BADGES = <?= json_encode(array_map(fn($v) => $v[1], $statusLabels)) ?>;
const SPEC_MAP = <?= specialtyMapJson() ?>;
const TZ = 'America/Mexico_City';
const CAL_START_H = 8;
const CAL_END_H   = 20;
const SLOT_PX     = 38;   // px por slot de 30 min

// Índice de profesionales por ID
const PROF_MAP = Object.fromEntries(PROFS.map(p => [p.id, p]));

// Devuelve vocab de especialidad para un professional_id
function profVocab(profId) {
  const p = PROF_MAP[profId];
  return SPEC_MAP[p?.specialty_type] || SPEC_MAP['other'];
}

// ════════════════════════════════════════════════════════════
// ── TAB 1: SERIES ──────────────────────────────────────────
// ════════════════════════════════════════════════════════════

// Cargar sesiones al expandir una serie
document.querySelectorAll('[data-bs-toggle="collapse"]').forEach(btn => {
  btn.addEventListener('click', async () => {
    const seriesId = btn.closest('.card').querySelector('.series-body')?.dataset.series;
    if (!seriesId) return;
    const tbody = document.querySelector(`.series-body[data-series="${seriesId}"]`);
    if (!tbody || tbody.dataset.loaded) return;
    tbody.dataset.loaded = '1';

    const res  = await fetch(`/api/proxy.php?path=/sessions/series/${seriesId}`);
    const data = await res.json();
    const sessions = data.sessions || [];

    if (!sessions.length) {
      tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-3">Sin sesiones</td></tr>';
      return;
    }
    tbody.innerHTML = sessions.map((s, i) => {
      const dt  = s.scheduled_at ? new Date(s.scheduled_at) : null;
      const dtStr = dt ? dt.toLocaleString('es-MX', {dateStyle:'medium', timeStyle:'short', timeZone: TZ}) : '—';
      const mod = s.modality === 'video' ? '<i class="bx bx-video text-info"></i>'
                : '<i class="bx bx-building text-success"></i>';
      const badge = STATUS_BADGES[s.status] || 'bg-label-secondary';
      const label = STATUS_LABELS[s.status] || s.status;
      return `<tr>
        <td class="ps-3 text-muted small">${i + 1}</td>
        <td>${dtStr}</td>
        <td>${mod}</td>
        <td><span class="badge ${badge}">${label}</span></td>
        <td><button class="btn btn-xs btn-outline-secondary me-1" onclick="openStatusModal('${s.id}','${s.status}')">
          <i class="bx bx-edit-alt"></i></button></td>
      </tr>`;
    }).join('');
  });
});

function openStatusModal(id, status) {
  document.getElementById('status-session-id').value = id;
  document.getElementById('status-select').value     = status;
  new bootstrap.Modal(document.getElementById('modalStatus')).show();
}

async function saveStatus() {
  const id     = document.getElementById('status-session-id').value;
  const status = document.getElementById('status-select').value;
  const res    = await fetch('/api/session-save.php', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({id, status})
  });
  const data = await res.json();
  if (!res.ok) { window.showToast('Error: ' + (data.error||''), 'danger'); return; }
  bootstrap.Modal.getInstance(document.getElementById('modalStatus'))?.hide();
  window.showToast('Estado actualizado', 'success');
  location.reload();
}

async function confirmSeries(id) {
  const ok = await window.confirmToast?.(
    '<i class="bx bx-check-double me-1"></i>¿Confirmar <strong>todas</strong> las sesiones de esta serie?',
    'Sí, confirmar todas', 'btn-success'
  );
  if (!ok) return;
  const res  = await fetch('/api/session-save.php', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({id, action:'confirm-series'})
  });
  const data = await res.json();
  if (!res.ok) { window.showToast('Error: '+(data.error||''), 'danger'); return; }
  window.showToast('<i class="bx bx-check-double me-1"></i>Serie confirmada', 'success');
  location.reload();
}


// ════════════════════════════════════════════════════════════
// ── TAB 2: CALENDARIO ──────────────────────────────────────
// ════════════════════════════════════════════════════════════

let calSelectedDay  = new Date();
let calWeekStart    = getMonday(new Date());
let calSessions     = [];
let calInitialized  = false;

document.getElementById('tab-semana-btn').addEventListener('shown.bs.tab', async () => {
  if (!calInitialized) { calInitialized = true; await refreshCalendar(); }
});

function getMonday(d) {
  const day = d.getDay();
  const diff = day === 0 ? -6 : 1 - day;
  const mon = new Date(d);
  mon.setDate(d.getDate() + diff);
  mon.setHours(0,0,0,0);
  return mon;
}
function addDays(d, n) { const r = new Date(d); r.setDate(r.getDate() + n); return r; }
function toDateStr(d)  { return d.toISOString().substring(0,10); }

// Obtiene hora:minuto en timezone Mexico
function getMXHM(isoStr) {
  const d = new Date(isoStr);
  const s = d.toLocaleString('en-US', {timeZone: TZ, hour:'numeric', minute:'2-digit', hour12:false});
  const [hStr, mStr] = s.split(':');
  return { h: parseInt(hStr), m: parseInt(mStr) };
}
function getMXDate(isoStr) {
  const d = new Date(isoStr);
  return d.toLocaleDateString('en-CA', {timeZone: TZ}); // YYYY-MM-DD
}
function getMXTimeStr(isoStr) {
  return new Date(isoStr).toLocaleTimeString('es-MX', {timeZone: TZ, hour:'2-digit', minute:'2-digit'});
}
function slotIdx(h, m) { return (h - CAL_START_H) * 2 + (m >= 30 ? 1 : 0); }

function isWorkingHour(prof, date, h, m) {
  const dayName = ['sun','mon','tue','wed','thu','fri','sat'][date.getDay()];
  const cfg = prof.schedule_config?.[dayName];
  if (!cfg?.active) return false;
  const [sh,sm] = (cfg.start||'09:00').split(':').map(Number);
  const [eh,em] = (cfg.end  ||'18:00').split(':').map(Number);
  const min = h*60+m;
  return min >= sh*60+sm && min < eh*60+em;
}
function isBreakHour(prof, date, h, m) {
  const dayName = ['sun','mon','tue','wed','thu','fri','sat'][date.getDay()];
  const cfg = prof.schedule_config?.[dayName];
  if (!cfg?.break_start || !cfg?.break_end) return false;
  const [bsh,bsm] = cfg.break_start.split(':').map(Number);
  const [beh,bem] = cfg.break_end.split(':').map(Number);
  const min = h*60+m;
  return min >= bsh*60+bsm && min < beh*60+bem;
}

async function loadWeekSessions() {
  const from = toDateStr(calWeekStart) + 'T00:00:00';
  const to   = toDateStr(addDays(calWeekStart,6)) + 'T23:59:59';
  try {
    const res = await fetch(`/api/proxy.php?path=/sessions&from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}&limit=300`);
    const data = await res.json();
    calSessions = Array.isArray(data) ? data : [];
  } catch(e) { calSessions = []; }
}

async function refreshCalendar() {
  document.getElementById('cal-loading').classList.remove('d-none');
  document.getElementById('cal-grid').innerHTML = '';
  await loadWeekSessions();
  document.getElementById('cal-loading').classList.add('d-none');
  renderWeekLabel();
  renderDayTabs();
  renderCalendar(calSelectedDay);
}

function renderWeekLabel() {
  const sun = addDays(calWeekStart, 6);
  const opts = { day:'numeric', month:'short', timeZone: TZ };
  const from = calWeekStart.toLocaleDateString('es-MX', opts);
  const to   = sun.toLocaleDateString('es-MX', opts);
  document.getElementById('week-label').textContent = `${from} — ${to}`;
}

function renderDayTabs() {
  const DAY_LABELS = ['Dom','Lun','Mar','Mié','Jue','Vie','Sáb'];
  const container  = document.getElementById('cal-day-tabs');
  container.innerHTML = '';
  for (let i = 0; i < 7; i++) {
    const day = addDays(calWeekStart, i);
    const dayStr = toDateStr(day);
    const todayStr = toDateStr(new Date());
    const selStr   = toDateStr(calSelectedDay);
    const isToday  = dayStr === todayStr;
    const isSel    = dayStr === selStr;
    const btn = document.createElement('button');
    btn.className = `btn btn-sm day-btn ${isSel ? 'btn-primary active' : isToday ? 'btn-outline-primary' : 'btn-outline-secondary'}`;
    btn.innerHTML = `${DAY_LABELS[day.getDay()]} <strong>${day.getDate()}</strong>`;
    btn.onclick = () => { calSelectedDay = day; renderDayTabs(); renderCalendar(day); };
    container.appendChild(btn);
  }
}

async function changeWeek(dir) {
  calWeekStart = addDays(calWeekStart, dir * 7);
  calSelectedDay = dir > 0 ? new Date(calWeekStart) : addDays(calWeekStart, 6);
  await refreshCalendar();
}
function goToday() {
  calSelectedDay = new Date();
  calWeekStart   = getMonday(calSelectedDay);
  refreshCalendar();
}

function renderCalendar(day) {
  const dayStr     = toDateStr(day);
  const totalSlots = (CAL_END_H - CAL_START_H) * 2;
  const grid       = document.getElementById('cal-grid');

  if (!PROFS.length) {
    grid.innerHTML = '<div class="alert alert-info m-3">No hay profesionales activos.</div>';
    return;
  }

  // Pre-agrupar sesiones activas del día por profesional
  const sessionsByProf = {};
  for (const p of PROFS) {
    sessionsByProf[p.id] = calSessions.filter(s =>
      s.professional_id === p.id &&
      getMXDate(s.scheduled_at) === dayStr &&
      !['cancelled','no_show'].includes(s.status)
    );
  }

  // Para cada profesional, marcar slots de CONTINUACIÓN (j>0) cubiertos por rowspan.
  // El slot de inicio (j=0) NO se marca — se renderiza con el <td rowspan=N>.
  const consumed = {}; // profId -> Set<slotIdx>  (solo slots de continuación)
  for (const p of PROFS) consumed[p.id] = new Set();

  for (const p of PROFS) {
    for (const s of sessionsByProf[p.id]) {
      const { h, m } = getMXHM(s.scheduled_at);
      const startSlot = slotIdx(h, m);
      const slots = Math.max(1, Math.ceil((s.duration_mins || 60) / 30));
      for (let j = 1; j < slots; j++) {   // j empieza en 1, no 0
        if (startSlot + j < totalSlots) consumed[p.id].add(startSlot + j);
      }
    }
  }

  // Construir tabla
  let html = `<table class="cal-table w-100"><thead><tr>`;
  html += `<th class="cal-time" style="width:52px"></th>`;
  for (const p of PROFS) {
    const color = p.color || '#696cff';
    const avatarHtml = p.avatar_url
      ? `<img src="${(p.avatar_url.indexOf('/uploads/')===0 ? (window.AC_BACKEND_ROOT||'') : '') + p.avatar_url}" alt="${p.name}" style="width:22px;height:22px;border-radius:50%;object-fit:cover;flex-shrink:0">`
      : `<span class="avatar-initial rounded-circle" style="width:22px;height:22px;font-size:10px;flex-shrink:0;background:${color}22;color:${color}">${p.avatar_initials||'?'}</span>`;
    html += `<th style="min-width:130px">
      <div class="d-flex align-items-center justify-content-center gap-1 py-1">
        ${avatarHtml}
        <span style="font-size:11px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100px">${p.name}</span>
      </div>
    </th>`;
  }
  html += '</tr></thead><tbody>';

  for (let i = 0; i < totalSlots; i++) {
    const h = CAL_START_H + Math.floor(i / 2);
    const m = (i % 2) * 30;
    const timeStr = `${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')}`;
    const showLabel = m === 0;

    html += `<tr style="height:${SLOT_PX}px">`;
    html += `<td class="cal-time">${showLabel ? timeStr : '<span style="border-top:1px solid #eee;display:block;margin:0 4px"></span>'}</td>`;

    for (const p of PROFS) {
      if (consumed[p.id].has(i)) {
        // Este slot está cubierto por el rowspan de una sesión anterior.
        // NO generar ningún <td> (el rowspan lo cubre).
        continue;
      }

      // ¿Hay sesión que empieza aquí?
      const session = sessionsByProf[p.id].find(s => {
        const { h: sh, m: sm } = getMXHM(s.scheduled_at);
        return slotIdx(sh, sm) === i;
      });

      if (session) {
        const slotsUsed = Math.max(1, Math.ceil((session.duration_mins || 60) / 30));
        const color   = p.color || '#696cff';
        const badge   = STATUS_BADGES[session.status] || 'bg-label-secondary';
        const label   = STATUS_LABELS[session.status] || session.status;
        const startStr= getMXTimeStr(session.scheduled_at);
        const vocab = profVocab(p.id);
        const personLabel = vocab.person.charAt(0).toUpperCase() + vocab.person.slice(1);
        html += `<td rowspan="${slotsUsed}" class="cal-cell-session"
                     style="background:${color}12;border-left:3px solid ${color}" >
          <div class="cal-session-inner">
            <div style="font-weight:600;font-size:11px;line-height:1.3;color:${color}">${session.patient_name||personLabel}</div>
            <div style="font-size:10px;color:#777">${startStr} · ${session.duration_mins||60}min</div>
            <span class="badge ${badge}" style="font-size:9px">${label}</span>
          </div>
        </td>`;
      } else if (!isWorkingHour(p, day, h, m)) {
        html += `<td class="cal-cell-off" title="Fuera de horario"></td>`;
      } else if (isBreakHour(p, day, h, m)) {
        html += `<td class="cal-cell-break" title="Descanso"><span style="font-size:14px;display:flex;align-items:center;justify-content:center;height:100%;color:#f0ad4e;opacity:.5">☕</span></td>`;
      } else {
        // Slot libre — clickeable
        const dtLocal = `${dayStr}T${timeStr}`;
        html += `<td class="cal-cell-free"
                     onclick="openBookingModal('${p.id}', '${dtLocal}')"
                     title="Agendar con ${p.name} a las ${timeStr}">
        </td>`;
      }
    }
    html += '</tr>';
  }

  html += '</tbody></table>';
  grid.innerHTML = html;
}


// ════════════════════════════════════════════════════════════
// ── TAB 3: DISPONIBILIDAD ──────────────────────────────────
// ════════════════════════════════════════════════════════════

let availInitialized = false;

document.getElementById('tab-avail-btn').addEventListener('shown.bs.tab', () => {
  if (!availInitialized) { availInitialized = true; loadAvailability(); }
});

async function loadAvailability() {
  const days    = document.getElementById('avail-days').value;
  const grid    = document.getElementById('avail-grid');
  const loading = document.getElementById('avail-loading');

  loading.classList.remove('d-none');
  grid.innerHTML = '';

  // Cargar disponibilidad de todos los profesionales en paralelo
  const results = await Promise.all(
    PROFS.map(p =>
      fetch(`/api/proxy.php?path=/professionals/${p.id}/availability&days_ahead=${days}`)
        .then(r => r.json())
        .then(slots => ({ prof: p, slots: Array.isArray(slots) ? slots : [] }))
        .catch(() => ({ prof: p, slots: [] }))
    )
  );

  loading.classList.add('d-none');

  if (!results.length) {
    grid.innerHTML = '<div class="alert alert-info">No hay profesionales activos.</div>';
    return;
  }

  // Agrupar slots por día para cada profesional
  let html = '';
  for (const { prof, slots } of results) {
    const color = prof.color || '#696cff';
    // Agrupar por fecha
    const byDay = {};
    for (const slot of slots) {
      const dateStr = getMXDate(slot.time);
      if (!byDay[dateStr]) byDay[dateStr] = [];
      byDay[dateStr].push(slot);
    }

    const pVocab = profVocab(prof.id);
    const pPersons = pVocab.persons.charAt(0).toUpperCase() + pVocab.persons.slice(1);
    const profAvatarHtml = prof.avatar_url
      ? `<img src="${(prof.avatar_url.indexOf('/uploads/')===0 ? (window.AC_BACKEND_ROOT||'') : '') + prof.avatar_url}" alt="${prof.name}" style="width:28px;height:28px;border-radius:50%;object-fit:cover">`
      : `<span class="avatar-initial rounded-circle" style="width:28px;height:28px;font-size:12px;background:${color}22;color:${color}">${prof.avatar_initials||'?'}</span>`;
    html += `<div class="mb-4">
      <div class="d-flex align-items-center gap-2 mb-2">
        ${profAvatarHtml}
        <div>
          <div class="fw-semibold d-flex align-items-center gap-2">
            ${prof.name}
            <span class="badge" style="background:${pVocab.color}22;color:${pVocab.color};font-size:9px">${pVocab.emoji} ${pVocab.label}</span>
          </div>
          <small class="text-muted">${prof.area||''} · ${pVocab.sessions} con ${pVocab.persons}</small>
        </div>
        <span class="badge bg-label-success ms-auto">${slots.length} slot${slots.length !== 1 ? 's' : ''} disponibles</span>
      </div>`;

    if (!slots.length) {
      html += `<div class="text-muted small ps-4 mb-2">Sin disponibilidad en los próximos ${days} días</div>`;
    } else {
      const dayEntries = Object.entries(byDay).sort(([a],[b]) => a.localeCompare(b));
      html += '<div class="ps-4">';
      for (const [dateStr, daySlots] of dayEntries) {
        const dayLabel = new Date(dateStr + 'T12:00:00').toLocaleDateString('es-MX', {
          weekday:'short', day:'numeric', month:'short'
        });
        html += `<div class="mb-1"><span class="text-muted small fw-semibold me-2" style="min-width:90px;display:inline-block">${dayLabel}</span>`;
        for (const slot of daySlots) {
          const { h, m } = getMXHM(slot.time);
          const dtLocal = `${dateStr}T${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')}`;
          html += `<span class="avail-slot" onclick="openBookingModal('${prof.id}','${dtLocal}')">${getMXTimeStr(slot.time)}</span>`;
        }
        html += '</div>';
      }
      html += '</div>';
    }
    html += '</div><hr class="my-2">';
  }
  grid.innerHTML = html || '<div class="text-muted">Sin datos</div>';
}


// ════════════════════════════════════════════════════════════
// ── MODAL DE AGENDAMIENTO ───────────────────────────────────
// ════════════════════════════════════════════════════════════

function openBookingModal(profId = null, dtLocal = null) {
  document.getElementById('form-book').reset();
  document.getElementById('book-error').classList.add('d-none');

  if (profId) {
    document.getElementById('book-prof').value = profId;
    onBookProfChange(); // filtrar tipos
  }
  if (dtLocal) {
    document.getElementById('book-datetime').value = dtLocal;
  }
  new bootstrap.Modal(document.getElementById('modalBook')).show();
}

function onBookProfChange() {
  const profId = document.getElementById('book-prof').value;

  // Actualizar vocabulario de persona
  const vocab   = profVocab(profId);
  const Person  = vocab.person.charAt(0).toUpperCase() + vocab.person.slice(1);
  document.getElementById('book-person-label').textContent = Person;
  document.getElementById('book-person-name-label').textContent = `Nombre del ${vocab.person}`;
  const badge = document.getElementById('book-spec-badge');
  if (profId) {
    badge.textContent = vocab.emoji + ' ' + vocab.label;
    badge.style.background = vocab.color + '22';
    badge.style.color = vocab.color;
    badge.style.display = '';
  } else {
    badge.style.display = 'none';
  }

  // Filtrar tipos de sesión — mostrar todos (o podría filtrarse por professional_ids)
  const stSel = document.getElementById('book-stype');
  Array.from(stSel.options).forEach(opt => { opt.hidden = false; });
}

function onBookStypeChange() {
  const stSel  = document.getElementById('book-stype');
  const opt    = stSel.options[stSel.selectedIndex];
  if (!opt.value) return;
  document.getElementById('book-count').value    = opt.dataset.count    || 1;
  document.getElementById('book-freq').value     = opt.dataset.freq     || 'weekly';
  document.getElementById('book-duration').value = opt.dataset.duration || 60;
  if (opt.dataset.modality && opt.dataset.modality !== 'both') {
    document.getElementById('book-modality').value = opt.dataset.modality;
  }
}

document.getElementById('form-book').addEventListener('submit', async e => {
  e.preventDefault();
  const errDiv = document.getElementById('book-error');
  document.getElementById('book-btn-text').classList.add('d-none');
  document.getElementById('book-btn-spin').classList.remove('d-none');
  errDiv.classList.add('d-none');

  // Convertir datetime-local (hora local México) a UTC ISO
  const dtLocal = document.getElementById('book-datetime').value; // "2024-06-02T10:30"
  let firstSessionAt;
  try {
    // Asume UTC-6 (México estándar); para producción usar offset real
    firstSessionAt = new Date(dtLocal + ':00-06:00').toISOString();
  } catch(e2) {
    firstSessionAt = new Date(dtLocal).toISOString();
  }

  const payload = {
    action:           'create-series',
    professional_id:  document.getElementById('book-prof').value      || null,
    session_type_id:  document.getElementById('book-stype').value     || null,
    patient_name:     document.getElementById('book-patient-name').value.trim(),
    patient_phone:    document.getElementById('book-patient-phone').value.trim() || null,
    patient_email:    document.getElementById('book-patient-email').value.trim() || null,
    first_session_at: firstSessionAt,
    duration_mins:    parseInt(document.getElementById('book-duration').value) || 60,
    total_sessions:   parseInt(document.getElementById('book-count').value)    || 1,
    frequency:        document.getElementById('book-freq').value,
    modality:         document.getElementById('book-modality').value,
    notes:            document.getElementById('book-notes').value.trim() || null,
  };

  try {
    const res  = await fetch('/api/session-save.php', {
      method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(payload)
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.message || data.error || 'Error al agendar');
    bootstrap.Modal.getInstance(document.getElementById('modalBook'))?.hide();
    window.showToast('Sesión agendada correctamente', 'success');
    calInitialized = false; availInitialized = false;
    location.reload();
  } catch(err) {
    errDiv.textContent = err.message; errDiv.classList.remove('d-none');
  } finally {
    document.getElementById('book-btn-text').classList.remove('d-none');
    document.getElementById('book-btn-spin').classList.add('d-none');
  }
});
</script>
