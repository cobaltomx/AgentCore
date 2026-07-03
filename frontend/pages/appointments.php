<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/head.php';
require_once __DIR__ . '/../includes/sidebar.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/footer.php';

requireAuth();

$stats = apiGet('/appointments/stats/summary');
$appts = apiGet('/appointments', ['limit' => 200]);
$data  = array_values(array_filter((array)($appts['data'] ?? $appts), 'is_array'));

renderHead('Citas');
?>

<!-- FullCalendar CSS (solo esta página) -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.css"/>
<style>
  /* ── FullCalendar → Sneat ── */
  .fc { font-family: inherit; }
  .fc-toolbar-title { font-size: 1.1rem; font-weight: 600; }
  .fc-button-primary {
    background-color: #696cff !important;
    border-color: #696cff !important;
    text-transform: capitalize;
  }
  .fc-button-primary:not(:disabled):hover { background-color: #5f61e6 !important; border-color: #5f61e6 !important; }
  .fc-button-active { background-color: #5f61e6 !important; }
  .fc-daygrid-event { border-radius: 4px; font-size: .78rem; padding: 1px 4px; cursor: pointer; }
  .fc-timegrid-event { border-radius: 4px; font-size: .78rem; }
  .fc-event-title { font-weight: 500; }
  .fc-day-today { background: rgba(105,108,255,.04) !important; }
  #calendar-container { min-height: 620px; }
  .fc-event-dragging { opacity: .7; }

  /* Badge de fuente */
  .badge-ea     { background: rgba(105,108,255,.15); color: #696cff; }
  .badge-manual { background: rgba(133,146,163,.15); color: #8592a3; }

  /* Highlight de búsqueda */
  .appt-row.hidden { display: none; }
  mark.hl { background: rgba(105,108,255,.2); color: inherit; border-radius: 2px; padding: 0 1px; }

  /* Edit mode en modal detalle */
  #det-view, #det-edit { transition: opacity .15s; }

  /* Resaltado citas de hoy */
  .appt-row.is-today td:first-child::before {
    content: 'HOY';
    display: inline-block;
    font-size: .6rem;
    font-weight: 700;
    background: #696cff;
    color: #fff;
    border-radius: 3px;
    padding: 0 4px;
    margin-right: 6px;
    vertical-align: middle;
  }
</style>

<div class="layout-wrapper layout-content-navbar">
<div class="layout-container">
<?php renderSidebar('appointments'); ?>
<div class="layout-page">
<?php renderNavbar('Citas'); ?>
<div class="content-wrapper">
<div class="container-xxl flex-grow-1 container-p-y">

  <!-- Encabezado + acciones -->
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
    <div>
      <h4 class="mb-0">Citas</h4>
      <small class="text-muted">Agendadas por el asistente o manualmente</small>
    </div>
    <div class="d-flex gap-2">
      <div class="btn-group" role="group">
        <button id="btn-lista" type="button" class="btn btn-primary btn-sm active"
                onclick="switchView('lista')">
          <i class="bx bx-list-ul me-1"></i>Lista
        </button>
        <button id="btn-calendario" type="button" class="btn btn-outline-primary btn-sm"
                onclick="switchView('calendario')">
          <i class="bx bx-calendar me-1"></i>Calendario
        </button>
      </div>
      <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalNuevaCita">
        <i class="bx bx-plus me-1"></i>Nueva cita
      </button>
    </div>
  </div>

  <!-- KPI cards -->
  <div class="row g-3 mb-4">
    <?php
    $sts = [
      ['label' => 'Próximas',    'val' => $stats['upcoming']   ?? 0, 'color' => 'primary',   'icon' => 'bx-calendar-check'],
      ['label' => 'Esta semana', 'val' => $stats['this_week']  ?? 0, 'color' => 'info',      'icon' => 'bx-calendar-week'],
      ['label' => 'Este mes',    'val' => $stats['this_month'] ?? 0, 'color' => 'success',   'icon' => 'bx-calendar'],
      ['label' => 'Completadas', 'val' => $stats['completed']  ?? 0, 'color' => 'secondary', 'icon' => 'bx-check-circle'],
    ];
    foreach ($sts as $s): ?>
    <div class="col-6 col-md-3">
      <div class="card text-center h-100 card-kpi">
        <div class="card-body py-3">
          <i class="bx <?= $s['icon'] ?> text-<?= $s['color'] ?> mb-1" style="font-size:1.5rem"></i>
          <div class="h3 mb-0 text-<?= $s['color'] ?>"><?= (int)$s['val'] ?></div>
          <small class="text-muted"><?= $s['label'] ?></small>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- ══════════════ VISTA LISTA ══════════════ -->
  <div id="view-lista">

    <!-- Barra de filtros -->
    <div class="card mb-3">
      <div class="card-body py-2">
        <div class="d-flex flex-wrap gap-2 align-items-center appt-filter-bar">
          <!-- Búsqueda -->
          <div class="input-group input-group-sm" style="max-width:220px">
            <span class="input-group-text"><i class="bx bx-search"></i></span>
            <input type="text" id="search-appt" class="form-control" placeholder="Nombre o teléfono…">
          </div>
          <!-- Estado -->
          <select id="filter-status" class="form-select form-select-sm" style="max-width:150px">
            <option value="">Todos los estados</option>
            <option value="pending">Pendiente</option>
            <option value="confirmed">Confirmada</option>
            <option value="completed">Completada</option>
            <option value="cancelled">Cancelada</option>
            <option value="no_show">No asistió</option>
          </select>
          <!-- Fecha desde -->
          <div class="d-flex align-items-center gap-1">
            <label class="text-muted small mb-0">Desde</label>
            <input type="date" id="filter-from" class="form-control form-control-sm" style="max-width:140px">
          </div>
          <!-- Fecha hasta -->
          <div class="d-flex align-items-center gap-1">
            <label class="text-muted small mb-0">Hasta</label>
            <input type="date" id="filter-to" class="form-control form-control-sm" style="max-width:140px">
          </div>
          <!-- Limpiar -->
          <button class="btn btn-sm btn-outline-secondary" id="btn-clear-filters" title="Limpiar filtros">
            <i class="bx bx-x"></i> Limpiar
          </button>
          <!-- Contador -->
          <span class="ms-auto text-muted small" id="appt-count"></span>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" id="appts-table">
          <thead class="table-light">
            <tr>
              <th>Cliente</th>
              <th>Fecha y hora</th>
              <th>Duración</th>
              <th>Estado</th>
              <th>Cobro</th>
              <th>Fuente</th>
              <th></th>
            </tr>
          </thead>
          <tbody id="appts-tbody">
            <?php if (empty($data)): ?>
              <tr id="row-empty"><td colspan="7" class="text-center text-muted py-5">
                <i class="bx bx-calendar-x bx-lg d-block mb-2 opacity-25"></i>
                Sin citas aún — el asistente las creará automáticamente
              </td></tr>
            <?php else: foreach ($data as $a):
              $statusMap = [
                'confirmed' => ['bg-label-success',   'Confirmada'],
                'pending'   => ['bg-label-warning',   'Pendiente'],
                'cancelled' => ['bg-label-danger',    'Cancelada'],
                'completed' => ['bg-label-info',      'Completada'],
                'no_show'   => ['bg-label-secondary', 'No asistió'],
              ];
              [$sBadge, $sLabel] = $statusMap[$a['status'] ?? 'pending'] ?? ['bg-label-secondary', ucfirst($a['status'] ?? '')];
              $isEa = ($a['external_source'] ?? '') === 'easyappointments';
              $dateStr = !empty($a['scheduled_at']) ? (new DateTime($a['scheduled_at']))->format('Y-m-d') : '';
              $isToday = $dateStr === date('Y-m-d');
            ?>
              <tr class="appt-row <?= $isToday ? 'is-today' : '' ?>"
                  data-id="<?= e($a['id']) ?>"
                  data-name="<?= e(strtolower($a['lead_name'] ?? $a['title'] ?? '')) ?>"
                  data-phone="<?= e($a['lead_phone'] ?? '') ?>"
                  data-status="<?= e($a['status'] ?? 'pending') ?>"
                  data-date="<?= e($dateStr) ?>">
                <td>
                  <div class="fw-semibold appt-name"><?= e($a['lead_name'] ?? $a['title'] ?? '—') ?></div>
                  <small class="text-muted appt-phone">
                    <?php if (!empty($a['lead_phone'])): ?>
                      <i class="bx bx-phone me-1"></i><?= e($a['lead_phone']) ?>
                    <?php endif; ?>
                  </small>
                </td>
                <td>
                  <span class="fw-semibold"><?= formatDate($a['scheduled_at'] ?? '', 'd/m/Y') ?></span>
                  <small class="text-muted d-block"><?= formatDate($a['scheduled_at'] ?? '', 'H:i') ?></small>
                </td>
                <td><small class="text-muted"><?= (int)($a['duration_mins'] ?? 60) ?> min</small></td>
                <td><span class="badge <?= $sBadge ?>"><?= $sLabel ?></span></td>
                <td>
                  <?php
                    $depStatus = $a['deposit_status'] ?? 'none';
                    $depAmount = (float)($a['deposit_amount'] ?? 0);
                    $depLink   = $a['deposit_payment_link'] ?? '';
                  ?>
                  <?php if ($depStatus === 'paid'): ?>
                    <span class="badge bg-label-success"><i class="bx bx-check-circle me-1"></i>Pagado</span>
                  <?php elseif ($depStatus === 'pending'): ?>
                    <a href="<?= e($depLink) ?>" target="_blank" rel="noopener"
                       class="badge bg-label-warning text-decoration-none" title="Link de pago activo">
                      <i class="bx bx-time me-1"></i>$<?= number_format($depAmount, 0) ?> pendiente
                    </a>
                  <?php else: ?>
                    <button class="btn btn-sm btn-outline-primary py-0 px-2 btn-cobrar"
                            data-id="<?= e($a['id']) ?>"
                            data-amount="<?= $depAmount > 0 ? (int)$depAmount : '' ?>"
                            data-name="<?= e($a['lead_name'] ?? $a['title'] ?? '') ?>"
                            title="Generar link de cobro de anticipo">
                      <i class="bx bx-credit-card me-1"></i>Cobrar
                    </button>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($isEa): ?>
                    <span class="badge badge-ea"><i class="bx bx-calendar-check me-1"></i>Easy!Appt</span>
                  <?php else: ?>
                    <span class="badge badge-manual">Manual</span>
                  <?php endif; ?>
                </td>
                <td>
                  <button class="btn btn-sm btn-icon btn-outline-secondary" title="Ver / editar"
                          onclick="openApptDetail(<?= htmlspecialchars(json_encode($a), ENT_QUOTES) ?>)">
                    <i class="bx bx-show"></i>
                  </button>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
        <!-- No-results row (inyectado por JS) -->
        <div id="no-results" class="text-center text-muted py-5 d-none">
          <i class="bx bx-search-alt bx-lg d-block mb-2 opacity-25"></i>
          Sin resultados para los filtros aplicados
        </div>
      </div>
    </div>

  </div><!-- /view-lista -->

  <!-- ══════════════ VISTA CALENDARIO ══════════════ -->
  <div id="view-calendario" style="display:none">
    <div class="card">
      <div class="card-body p-3">
        <div id="calendar-container"></div>
      </div>
    </div>
    <div class="d-flex gap-3 mt-2 flex-wrap align-items-center">
      <small><span class="badge" style="background:#696cff">■</span> Confirmada</small>
      <small><span class="badge" style="background:#ffab00">■</span> Pendiente</small>
      <small><span class="badge" style="background:#71dd37">■</span> Completada</small>
      <small><span class="badge" style="background:#ff3e1d">■</span> Cancelada</small>
      <small><span class="badge" style="background:#8592a3">■</span> No asistió</small>
      <small class="ms-auto appt-dragging-hint"><i class="bx bx-move me-1"></i>Arrastra un evento para reagendar</small>
    </div>
  </div><!-- /view-calendario -->

</div><!-- /container -->
<?php renderFooter(); ?>

<!-- ══ Modal detalle / editar cita ══ -->
<div class="modal fade" id="modalCitaDetalle" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header border-0 pb-0">
        <h5 class="modal-title">
          <i class="bx bx-calendar-event text-primary me-2"></i>
          <span id="det-title">Detalle de cita</span>
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <!-- ── Vista detalle ── -->
      <div id="det-view">
        <div class="modal-body pt-2">
          <div class="row g-3">
            <div class="col-6">
              <small class="text-muted d-block">Fecha y hora</small>
              <span id="det-datetime" class="fw-semibold">—</span>
            </div>
            <div class="col-6">
              <small class="text-muted d-block">Duración</small>
              <span id="det-duration" class="fw-semibold">—</span>
            </div>
            <div class="col-6">
              <small class="text-muted d-block">Teléfono</small>
              <span id="det-phone">—</span>
            </div>
            <div class="col-6">
              <small class="text-muted d-block">Estado</small>
              <span id="det-status">—</span>
            </div>
            <div class="col-12" id="det-location-row" style="display:none">
              <small class="text-muted d-block">Ubicación</small>
              <span id="det-location">—</span>
            </div>
            <div class="col-12">
              <small class="text-muted d-block">Notas</small>
              <p id="det-notes" class="mb-0 fst-italic text-muted" style="font-size:.875rem">—</p>
            </div>
          </div>
        </div>
        <div class="modal-footer border-0 pt-0 justify-content-between flex-wrap gap-2">
          <div class="d-flex gap-2 flex-wrap">
            <button type="button" class="btn btn-outline-danger btn-sm" id="btn-cancel-appt">
              <i class="bx bx-x me-1"></i>Cancelar
            </button>
            <button type="button" class="btn btn-outline-success btn-sm" id="btn-complete-appt">
              <i class="bx bx-check me-1"></i>Completada
            </button>
            <button type="button" class="btn btn-outline-secondary btn-sm" id="btn-noshow-appt" title="El cliente no se presentó">
              <i class="bx bx-user-x me-1"></i>No asistió
            </button>
            <button type="button" class="btn btn-success btn-sm d-none" id="btn-confirm-appt">
              <i class="bx bx-calendar-check me-1"></i>Confirmar
            </button>
          </div>
          <button type="button" class="btn btn-outline-primary btn-sm" id="btn-edit-mode">
            <i class="bx bx-edit me-1"></i>Editar
          </button>
        </div>
      </div><!-- /det-view -->

      <!-- ── Modo edición ── -->
      <div id="det-edit" style="display:none">
        <form id="form-edit-cita">
          <div class="modal-body pt-2">
            <div class="row g-3">
              <div class="col-8">
                <label class="form-label">Fecha y hora</label>
                <input type="datetime-local" class="form-control" name="scheduled_at" id="edit-scheduled_at" required>
              </div>
              <div class="col-4">
                <label class="form-label">Duración</label>
                <select class="form-select" name="duration_mins" id="edit-duration">
                  <option value="15">15 min</option>
                  <option value="30">30 min</option>
                  <option value="45">45 min</option>
                  <option value="60" selected>60 min</option>
                  <option value="90">90 min</option>
                  <option value="120">2 horas</option>
                  <option value="180">3 horas</option>
                </select>
              </div>
              <div class="col-12">
                <label class="form-label">Ubicación</label>
                <input type="text" class="form-control" name="location" id="edit-location"
                       placeholder="Ej. Consultorio 3, Sala de juntas…">
              </div>
              <div class="col-12">
                <label class="form-label">Notas</label>
                <textarea class="form-control" name="notes" id="edit-notes" rows="3"
                          placeholder="Motivo, instrucciones previas…"></textarea>
              </div>
            </div>
            <div id="edit-error" class="alert alert-danger mt-3 d-none"></div>
          </div>
          <div class="modal-footer border-0 pt-0">
            <button type="button" class="btn btn-outline-secondary btn-sm" id="btn-cancel-edit">
              <i class="bx bx-arrow-back me-1"></i>Volver
            </button>
            <button type="submit" class="btn btn-primary btn-sm" id="btn-save-edit">
              <span id="edit-btn-text"><i class="bx bx-save me-1"></i>Guardar cambios</span>
              <span id="edit-btn-spinner" class="d-none">
                <span class="spinner-border spinner-border-sm me-1"></span>Guardando…
              </span>
            </button>
          </div>
        </form>
      </div><!-- /det-edit -->

    </div>
  </div>
</div>

<!-- ══ Modal nueva cita manual ══ -->
<div class="modal fade" id="modalNuevaCita" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header border-0 pb-0">
        <h5 class="modal-title">
          <i class="bx bx-plus-circle text-primary me-2"></i>Nueva cita
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form id="form-nueva-cita">
        <div class="modal-body pt-2">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label">Nombre del cliente <span class="text-danger">*</span></label>
              <input type="text" class="form-control" name="name" required placeholder="Ej. María García">
            </div>
            <div class="col-6">
              <label class="form-label">Teléfono <span class="text-danger">*</span></label>
              <input type="tel" class="form-control" name="phone" required placeholder="+52 55 1234 5678">
            </div>
            <div class="col-6">
              <label class="form-label">Email (opcional)</label>
              <input type="email" class="form-control" name="email" placeholder="cliente@email.com">
            </div>
            <div class="col-8">
              <label class="form-label">Fecha y hora <span class="text-danger">*</span></label>
              <input type="datetime-local" class="form-control" name="scheduled_at" required>
            </div>
            <div class="col-4">
              <label class="form-label">Duración</label>
              <select class="form-select" name="duration_mins">
                <option value="15">15 min</option>
                <option value="30">30 min</option>
                <option value="60" selected>60 min</option>
                <option value="90">90 min</option>
                <option value="120">2 horas</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label">Ubicación</label>
              <input type="text" class="form-control" name="location" placeholder="Ej. Consultorio, oficina…">
            </div>
            <div class="col-12">
              <label class="form-label">Notas</label>
              <textarea class="form-control" name="notes" rows="2" placeholder="Motivo de la consulta…"></textarea>
            </div>
          </div>
          <div id="nueva-cita-error" class="alert alert-danger mt-3 d-none"></div>
        </div>
        <div class="modal-footer border-0 pt-0">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-primary" id="btn-crear-cita">
            <span id="btn-crear-text"><i class="bx bx-save me-1"></i>Crear cita</span>
            <span id="btn-crear-spinner" class="d-none">
              <span class="spinner-border spinner-border-sm me-1"></span>Guardando…
            </span>
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ── Modal: Cobro de anticipo ──────────────────────────────────── -->
<div class="modal fade" id="modalCobro" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bx bx-credit-card text-primary me-2"></i>Cobrar anticipo</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <!-- Paso 1: monto -->
      <div id="cobro-step-amount">
        <div class="modal-body">
          <p class="text-muted small mb-3">
            Cliente: <span id="cobro-name" class="fw-semibold text-body">—</span>
          </p>
          <label class="form-label">Monto del anticipo (MXN)</label>
          <div class="input-group">
            <span class="input-group-text">$</span>
            <input type="number" min="10" step="1" class="form-control" id="cobro-amount" placeholder="300">
          </div>
          <small class="text-muted">Se generará un link de pago seguro de Stripe para enviar al cliente.</small>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="button" class="btn btn-primary" id="cobro-generate">
            <i class="bx bx-link me-1"></i>Generar link
          </button>
        </div>
      </div>

      <!-- Paso 2: link generado -->
      <div id="cobro-step-link" class="d-none">
        <div class="modal-body text-center">
          <div style="font-size:2.5rem">✅</div>
          <h6 class="fw-semibold mb-1">Link de pago generado</h6>
          <p class="text-muted small mb-3">Compártelo con tu cliente para que pague el anticipo.</p>
          <div class="input-group mb-3">
            <input type="text" class="form-control" id="cobro-link-url" readonly>
            <button class="btn btn-outline-primary" id="cobro-copy" title="Copiar"><i class="bx bx-copy"></i></button>
          </div>
          <a href="#" target="_blank" rel="noopener" class="btn btn-success w-100" id="cobro-whatsapp">
            <i class="bx bxl-whatsapp me-1"></i>Enviar por WhatsApp
          </a>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary w-100" data-bs-dismiss="modal">Cerrar</button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- FullCalendar JS -->
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>
<script>
'use strict';

// ── Estado global ─────────────────────────────────────────────
let fcCalendar   = null;
let activeApptId = null;
let activeApptData = null; // referencia a la cita activa completa

const statusLabels = {
  confirmed: '<span class="badge bg-label-success">Confirmada</span>',
  pending:   '<span class="badge bg-label-warning">Pendiente</span>',
  cancelled: '<span class="badge bg-label-danger">Cancelada</span>',
  completed: '<span class="badge bg-label-info">Completada</span>',
  no_show:   '<span class="badge bg-label-secondary">No asistió</span>',
};

const STATUS_LABELS_TEXT = {
  confirmed: 'Confirmada',
  pending:   'Pendiente',
  cancelled: 'Cancelada',
  completed: 'Completada',
  no_show:   'No asistió',
};

// ── Helpers ───────────────────────────────────────────────────
function fmtDT(dt) {
  if (!dt) return '—';
  const d = dt instanceof Date ? dt : new Date(dt);
  return d.toLocaleDateString('es-MX', {
    weekday: 'long', day: 'numeric', month: 'long',
    year: 'numeric', hour: '2-digit', minute: '2-digit',
  });
}

// Convertir JS Date → "YYYY-MM-DDTHH:MM" para datetime-local
function toLocalISO(dt) {
  if (!dt) return '';
  const d = dt instanceof Date ? dt : new Date(dt);
  const pad = n => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

// ── Toggle lista / calendario ─────────────────────────────────
function switchView(view) {
  const isCal = view === 'calendario';
  document.getElementById('view-lista').style.display      = isCal ? 'none' : '';
  document.getElementById('view-calendario').style.display = isCal ? '' : 'none';

  const btnL = document.getElementById('btn-lista');
  const btnC = document.getElementById('btn-calendario');
  btnL.classList.toggle('btn-primary',         !isCal);
  btnL.classList.toggle('btn-outline-primary',  isCal);
  btnL.classList.toggle('active',              !isCal);
  btnC.classList.toggle('btn-primary',          isCal);
  btnC.classList.toggle('btn-outline-primary',  !isCal);
  btnC.classList.toggle('active',               isCal);

  sessionStorage.setItem('appts-view', view);
  if (isCal) initCalendar();
}

// ── FullCalendar ──────────────────────────────────────────────
function initCalendar() {
  if (fcCalendar) { fcCalendar.render(); return; }

  const el = document.getElementById('calendar-container');
  fcCalendar = new FullCalendar.Calendar(el, {
    initialView:  'dayGridMonth',
    locale:       'es',
    height:       620,
    firstDay:     1,
    nowIndicator: true,
    editable:     true,          // habilita drag & drop
    headerToolbar: {
      left:   'prev,next today',
      center: 'title',
      right:  'dayGridMonth,timeGridWeek,listWeek',
    },
    buttonText: { today: 'Hoy', month: 'Mes', week: 'Semana', list: 'Lista' },
    events: '/api/appointments-events.php',

    // Click en evento → modal detalle
    eventClick(info) {
      const p = info.event.extendedProps;
      openCalendarEvent({
        id:       info.event.id,
        title:    info.event.title,
        start:    info.event.start,
        phone:    p.phone,
        status:   p.status,
        notes:    p.notes,
        location: p.location,
        duration: p.duration,
        source:   p.source,
      });
    },

    // Drag & drop → reagendar (directo, sin confirm() — con opción de deshacer)
    eventDrop(info) {
      const newStart   = info.event.start.toISOString();
      const prevStart  = info.oldEvent?.start?.toISOString();

      fetch('/api/appointments-proxy.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'edit', id: info.event.id, scheduled_at: newStart }),
      })
      .then(r => r.json())
      .then(data => {
        if (data.error) {
          info.revert();
          window.showToast('Error al reagendar', 'danger');
        } else {
          // Toast con botón "Deshacer"
          const container = document.getElementById('toast-container') || (() => {
            const c = document.createElement('div');
            c.id = 'toast-container';
            c.style.cssText = 'position:fixed;top:1rem;right:1rem;z-index:9999;display:flex;flex-direction:column;gap:.5rem';
            document.body.appendChild(c);
            return c;
          })();
          const t = document.createElement('div');
          t.className = 'alert alert-success shadow py-2 px-3 mb-0 d-flex align-items-center gap-3';
          t.style.minWidth = '280px';
          t.innerHTML = `<span><i class="bx bx-check me-2"></i>Cita reagendada</span>
            <button class="btn btn-sm btn-outline-success ms-auto py-0 px-2" style="font-size:.75rem">Deshacer</button>`;
          container.appendChild(t);
          const undoBtn = t.querySelector('button');
          let undone = false;
          undoBtn.addEventListener('click', async () => {
            if (undone || !prevStart) return;
            undone = true;
            await fetch('/api/appointments-proxy.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ action: 'edit', id: info.event.id, scheduled_at: prevStart }),
            });
            info.revert();
            t.remove();
            window.showToast('Reagendado deshecho', 'success');
          });
          setTimeout(() => t.remove(), 5000);
        }
      })
      .catch(() => { info.revert(); window.showToast('Error de red', 'danger'); });
    },

    eventTimeFormat:  { hour: '2-digit', minute: '2-digit', hour12: false },
    eventDidMount(info) {
      info.el.title = `${info.event.title} — ${info.event.extendedProps.phone || 'Sin teléfono'}`;
    },
  });

  fcCalendar.render();
}

// ── Modal detalle desde tabla ─────────────────────────────────
function openApptDetail(appt) {
  activeApptId   = appt.id;
  activeApptData = appt;
  showDetView(appt.lead_name || appt.title || 'Cita', {
    start:    appt.scheduled_at ? new Date(appt.scheduled_at) : null,
    phone:    appt.lead_phone || '',
    status:   appt.status || 'pending',
    notes:    appt.notes  || '',
    location: appt.location || '',
    duration: appt.duration_mins || 60,
  });
}

// ── Modal detalle desde FullCalendar ─────────────────────────
function openCalendarEvent(ev) {
  activeApptId   = ev.id;
  activeApptData = ev;
  showDetView(ev.title, {
    start:    ev.start,
    phone:    ev.phone    || '',
    status:   ev.status   || 'pending',
    notes:    ev.notes    || '',
    location: ev.location || '',
    duration: ev.duration || 60,
  });
}

function showDetView(title, fields) {
  // Volver al modo lectura si estábamos en edición
  setEditMode(false);

  document.getElementById('det-title').textContent    = title;
  document.getElementById('det-datetime').textContent = fmtDT(fields.start);
  document.getElementById('det-phone').textContent    = fields.phone || '—';
  document.getElementById('det-status').innerHTML     = statusLabels[fields.status] || fields.status;
  document.getElementById('det-duration').textContent = fields.duration + ' min';
  document.getElementById('det-notes').textContent    = fields.notes || '(sin notas)';

  const locRow = document.getElementById('det-location-row');
  if (fields.location) {
    document.getElementById('det-location').textContent = fields.location;
    locRow.style.display = '';
  } else {
    locRow.style.display = 'none';
  }

  // Prellenar form de edición
  document.getElementById('edit-scheduled_at').value = toLocalISO(fields.start);
  document.getElementById('edit-duration').value     = fields.duration;
  document.getElementById('edit-notes').value        = fields.notes;
  document.getElementById('edit-location').value     = fields.location;

  // Botones según estado
  const isClosed  = ['cancelled', 'completed', 'no_show'].includes(fields.status);
  const isPending = fields.status === 'pending';
  document.getElementById('btn-cancel-appt').disabled   = isClosed;
  document.getElementById('btn-complete-appt').disabled  = isClosed;
  document.getElementById('btn-noshow-appt').disabled    = isClosed;
  document.getElementById('btn-edit-mode').disabled      = isClosed;

  const btnConfirm = document.getElementById('btn-confirm-appt');
  btnConfirm.classList.toggle('d-none', !isPending);

  new bootstrap.Modal(document.getElementById('modalCitaDetalle')).show();
}

// ── Toggle modo edición / lectura ─────────────────────────────
function setEditMode(editing) {
  document.getElementById('det-view').style.display  = editing ? 'none' : '';
  document.getElementById('det-edit').style.display  = editing ? '' : 'none';
  document.getElementById('edit-error').classList.add('d-none');
}
document.getElementById('btn-edit-mode').addEventListener('click', () => setEditMode(true));
document.getElementById('btn-cancel-edit').addEventListener('click', () => setEditMode(false));

// ── Guardar edición ───────────────────────────────────────────
document.getElementById('form-edit-cita').addEventListener('submit', async function(e) {
  e.preventDefault();
  if (!activeApptId) return;

  const fd   = new FormData(this);
  const body = { action: 'edit', id: activeApptId };
  for (const [k, v] of fd.entries()) {
    if (v !== '') body[k] = k === 'duration_mins' ? parseInt(v) : v;
  }
  // Convertir datetime-local → ISO UTC
  if (body.scheduled_at) body.scheduled_at = new Date(body.scheduled_at).toISOString();

  const errDiv = document.getElementById('edit-error');
  document.getElementById('edit-btn-text').classList.add('d-none');
  document.getElementById('edit-btn-spinner').classList.remove('d-none');
  errDiv.classList.add('d-none');

  try {
    const res  = await fetch('/api/appointments-proxy.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Error al guardar');

    bootstrap.Modal.getInstance(document.getElementById('modalCitaDetalle'))?.hide();
    window.showToast('Cita actualizada', 'success');
    if (fcCalendar) fcCalendar.refetchEvents();

    // Actualizar fecha/hora en la fila de la tabla sin recargar
    const row = document.querySelector(`tr.appt-row[data-id="${activeApptId}"]`);
    if (row && data.scheduled_at) {
      const dt = new Date(data.scheduled_at);
      const dateCell = row.querySelectorAll('td')[1];
      if (dateCell) {
        const fmt = dt.toLocaleDateString('es-MX', { day:'2-digit', month:'2-digit', year:'numeric' });
        const hm  = dt.toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit' });
        dateCell.innerHTML = `<span class="fw-semibold">${fmt}</span><small class="text-muted d-block">${hm}</small>`;
      }
      if (data.duration_mins) {
        const durCell = row.querySelectorAll('td')[2];
        if (durCell) durCell.innerHTML = `<small class="text-muted">${data.duration_mins} min</small>`;
      }
      row.dataset.date = data.scheduled_at.slice(0, 10);
    }
  } catch(err) {
    errDiv.textContent = err.message;
    errDiv.classList.remove('d-none');
  } finally {
    document.getElementById('edit-btn-text').classList.remove('d-none');
    document.getElementById('edit-btn-spinner').classList.add('d-none');
  }
});

// ── Cambiar estado — actualiza fila inline ────────────────────
const STATUS_BADGE_MAP = {
  confirmed: 'bg-label-success',
  pending:   'bg-label-warning',
  cancelled: 'bg-label-danger',
  completed: 'bg-label-info',
  no_show:   'bg-label-secondary',
};

async function updateApptStatus(newStatus) {
  if (!activeApptId) return;
  try {
    const res  = await fetch('/api/appointments-proxy.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id: activeApptId, status: newStatus }),
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Error al actualizar');

    bootstrap.Modal.getInstance(document.getElementById('modalCitaDetalle'))?.hide();
    if (fcCalendar) fcCalendar.refetchEvents();

    // Actualizar badge de estado en la fila de la tabla
    const row = document.querySelector(`tr.appt-row[data-id="${activeApptId}"]`);
    if (row) {
      row.dataset.status = newStatus;
      const badgeCell = row.querySelectorAll('td')[3]; // columna Estado
      if (badgeCell) {
        const cls   = STATUS_BADGE_MAP[newStatus] || 'bg-label-secondary';
        const label = STATUS_LABELS_TEXT[newStatus] || newStatus;
        badgeCell.innerHTML = `<span class="badge ${cls}">${label}</span>`;
      }
    }

    const msgs = {
      cancelled: 'Cita cancelada',
      completed: 'Marcada como completada',
      confirmed: 'Cita confirmada',
      no_show:   'Marcada como no asistió',
    };
    window.showToast(msgs[newStatus] || 'Estado actualizado', 'success');
  } catch(err) {
    window.showToast('Error: ' + err.message, 'danger');
  }
}

document.getElementById('btn-cancel-appt').addEventListener('click', () => updateApptStatus('cancelled'));
document.getElementById('btn-complete-appt').addEventListener('click', () => updateApptStatus('completed'));
document.getElementById('btn-confirm-appt').addEventListener('click', () => updateApptStatus('confirmed'));
document.getElementById('btn-noshow-appt').addEventListener('click', () => updateApptStatus('no_show'));

// ── Nueva cita ────────────────────────────────────────────────
document.getElementById('form-nueva-cita').addEventListener('submit', async function(e) {
  e.preventDefault();
  const fd   = new FormData(this);
  const body = Object.fromEntries(fd.entries());
  body.scheduled_at = new Date(body.scheduled_at).toISOString();
  body.duration_mins = parseInt(body.duration_mins);

  const btnText    = document.getElementById('btn-crear-text');
  const btnSpinner = document.getElementById('btn-crear-spinner');
  const errDiv     = document.getElementById('nueva-cita-error');
  btnText.classList.add('d-none');
  btnSpinner.classList.remove('d-none');
  errDiv.classList.add('d-none');

  try {
    const res  = await fetch('/api/appointments-proxy.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'create', ...body }),
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'No se pudo crear la cita');

    bootstrap.Modal.getInstance(document.getElementById('modalNuevaCita'))?.hide();
    document.getElementById('form-nueva-cita').reset();
    window.showToast('Cita creada correctamente', 'success');
    if (fcCalendar) fcCalendar.refetchEvents();

    // Insertar nueva fila al inicio del tbody
    const tbody = document.getElementById('appts-tbody');
    const emptyRow = document.getElementById('row-empty');
    if (emptyRow) emptyRow.remove();

    const appt  = data;
    const dt    = appt.scheduled_at ? new Date(appt.scheduled_at) : null;
    const dateS = dt ? dt.toLocaleDateString('es-MX', { day:'2-digit', month:'2-digit', year:'numeric' }) : '—';
    const timeS = dt ? dt.toLocaleTimeString('es-MX', { hour:'2-digit', minute:'2-digit' }) : '';
    const dateISO = dt ? dt.toISOString().slice(0, 10) : '';
    const isToday = dateISO === new Date().toISOString().slice(0, 10);
    const name  = body.name || '—';
    const phone = body.phone || '';
    const dur   = body.duration_mins || 60;

    const tr = document.createElement('tr');
    tr.className = `appt-row${isToday ? ' is-today' : ''}`;
    tr.dataset.id     = appt.id || '';
    tr.dataset.name   = name.toLowerCase();
    tr.dataset.phone  = phone;
    tr.dataset.status = 'pending';
    tr.dataset.date   = dateISO;
    tr.innerHTML = `
      <td>
        <div class="fw-semibold appt-name">${name}</div>
        <small class="text-muted appt-phone">${phone ? '<i class="bx bx-phone me-1"></i>' + phone : ''}</small>
      </td>
      <td>
        <span class="fw-semibold">${dateS}</span>
        <small class="text-muted d-block">${timeS}</small>
      </td>
      <td><small class="text-muted">${dur} min</small></td>
      <td><span class="badge bg-label-warning">Pendiente</span></td>
      <td><span class="badge badge-manual">Manual</span></td>
      <td>
        <button class="btn btn-sm btn-icon btn-outline-secondary" title="Ver / editar"
                onclick="openApptDetail(${JSON.stringify(appt).replace(/"/g,'&quot;')})">
          <i class="bx bx-show"></i>
        </button>
      </td>`;
    tbody.insertBefore(tr, tbody.firstChild);

    // Re-aplicar filtros para que el nuevo row sea visible/contado
    document.querySelector('#appt-count') && document.querySelector('.appt-filter-bar input')?.dispatchEvent(new Event('input'));
  } catch(err) {
    errDiv.textContent = err.message;
    errDiv.classList.remove('d-none');
  } finally {
    btnText.classList.remove('d-none');
    btnSpinner.classList.add('d-none');
  }
});

// ── Filtros de lista ──────────────────────────────────────────
(function() {
  const searchInput  = document.getElementById('search-appt');
  const statusSelect = document.getElementById('filter-status');
  const fromInput    = document.getElementById('filter-from');
  const toInput      = document.getElementById('filter-to');
  const countEl      = document.getElementById('appt-count');
  const noResults    = document.getElementById('no-results');

  function applyFilters() {
    const q      = (searchInput.value  || '').toLowerCase().trim();
    const status = statusSelect.value || '';
    const from   = fromInput.value || '';
    const to     = toInput.value   || '';

    const rows = document.querySelectorAll('.appt-row');
    let visible = 0;

    rows.forEach(row => {
      const name   = row.dataset.name  || '';
      const phone  = row.dataset.phone || '';
      const rSt    = row.dataset.status || '';
      const rDate  = row.dataset.date  || '';

      const matchQ   = !q      || name.includes(q)    || phone.includes(q);
      const matchSt  = !status || rSt === status;
      const matchFrom= !from   || rDate >= from;
      const matchTo  = !to     || rDate <= to;

      const show = matchQ && matchSt && matchFrom && matchTo;
      row.classList.toggle('hidden', !show);
      if (show) visible++;
    });

    if (countEl) countEl.textContent = visible + ' cita(s)';
    if (noResults) noResults.classList.toggle('d-none', visible > 0 || rows.length === 0);
  }

  [searchInput, statusSelect, fromInput, toInput].forEach(el => {
    if (el) el.addEventListener('input', applyFilters);
  });

  document.getElementById('btn-clear-filters').addEventListener('click', () => {
    searchInput.value  = '';
    statusSelect.value = '';
    fromInput.value    = '';
    toInput.value      = '';
    applyFilters();
  });

  // Inicializar contador
  applyFilters();
})();

// ── Persistir vista ───────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  // Citas abre en CALENDARIO por defecto (el manejo de citas es el corazón del
  // sistema); respeta la última vista elegida por el usuario si la cambió.
  const saved = sessionStorage.getItem('appts-view') || 'calendario';
  if (saved === 'calendario') switchView('calendario');

  const qp = new URLSearchParams(location.search);

  // Deep-link con filtros (desde "Necesita tu atención" del Dashboard) → vista LISTA
  const fStatus = qp.get('status'), fFrom = qp.get('from'), fTo = qp.get('to');
  if (fStatus || fFrom || fTo) {
    switchView('lista');
    const sEl  = document.getElementById('filter-status');
    const frEl = document.getElementById('filter-from');
    const toEl = document.getElementById('filter-to');
    if (fStatus && sEl)  sEl.value  = fStatus;
    if (fFrom && frEl)   frEl.value = fFrom;
    if (fTo && toEl)     toEl.value = fTo;
    (sEl || frEl || toEl)?.dispatchEvent(new Event('input'));   // dispara applyFilters()
  }

  // Apertura directa para CREAR cita (viene del Dashboard: ?new=1&date=YYYY-MM-DD)
  if (qp.get('new') === '1') {
    const d = qp.get('date');
    if (d) {
      const inp = document.querySelector('#form-nueva-cita [name="scheduled_at"]');
      if (inp) inp.value = d + 'T10:00';   // hora por defecto 10:00
    }
    bootstrap.Modal.getOrCreateInstance(document.getElementById('modalNuevaCita')).show();
  }
});

// ── Cobro de anticipos ────────────────────────────────────────
(function () {
  'use strict';
  const modalEl = document.getElementById('modalCobro');
  if (!modalEl) return;
  const modal = new bootstrap.Modal(modalEl);
  let currentApptId = null;
  let currentPhone  = '';

  const stepAmount = document.getElementById('cobro-step-amount');
  const stepLink   = document.getElementById('cobro-step-link');
  const elAmount   = document.getElementById('cobro-amount');
  const elName     = document.getElementById('cobro-name');
  const elGenerate = document.getElementById('cobro-generate');
  const elLinkUrl  = document.getElementById('cobro-link-url');
  const elCopy     = document.getElementById('cobro-copy');
  const elWhatsapp = document.getElementById('cobro-whatsapp');

  // Abrir modal desde cualquier botón "Cobrar"
  document.querySelectorAll('.btn-cobrar').forEach(btn => {
    btn.addEventListener('click', () => {
      currentApptId = btn.dataset.id;
      currentPhone  = btn.closest('tr')?.dataset.phone || '';
      elName.textContent = btn.dataset.name || '—';
      elAmount.value     = btn.dataset.amount || '';
      // reset a paso 1
      stepLink.classList.add('d-none');
      stepAmount.classList.remove('d-none');
      modal.show();
      setTimeout(() => elAmount.focus(), 300);
    });
  });

  elGenerate?.addEventListener('click', async () => {
    const amount = parseFloat(elAmount.value);
    if (!amount || amount < 10) {
      window.showToast?.('Ingresa un monto de al menos $10 MXN', 'error');
      return;
    }
    elGenerate.disabled = true;
    elGenerate.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Generando…';
    try {
      const res = await fetch('/api/appointments-proxy.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'deposit_link', id: currentApptId, amount }),
      });
      const data = await res.json();
      if (data.url) {
        elLinkUrl.value = data.url;
        // Configurar botón de WhatsApp
        const msg = `Hola 👋 Aquí está el link para pagar el anticipo de tu cita: ${data.url}`;
        const phone = currentPhone.replace(/[^0-9]/g, '');
        elWhatsapp.href = phone
          ? `https://wa.me/${phone}?text=${encodeURIComponent(msg)}`
          : `https://wa.me/?text=${encodeURIComponent(msg)}`;
        stepAmount.classList.add('d-none');
        stepLink.classList.remove('d-none');
      } else {
        window.showToast?.(data.error || 'No se pudo generar el link', 'error');
      }
    } catch {
      window.showToast?.('Error de red', 'error');
    } finally {
      elGenerate.disabled = false;
      elGenerate.innerHTML = '<i class="bx bx-link me-1"></i>Generar link';
    }
  });

  elCopy?.addEventListener('click', () => {
    elLinkUrl.select();
    navigator.clipboard?.writeText(elLinkUrl.value);
    window.showToast?.('Link copiado', 'success');
  });
})();
</script>
