<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/head.php';
require_once __DIR__ . '/../includes/sidebar.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/footer.php';

requireAuth();

// Filtros
$statusFilter = $_GET['status'] ?? '';
$filterSearch = trim($_GET['q'] ?? '');
$params = [];
if ($statusFilter) $params['status'] = $statusFilter;
if ($filterSearch) $params['q']      = $filterSearch;
$rawLeads = apiGet('/leads', $params);
$leads    = isset($rawLeads['error']) ? [] : array_values(array_filter((array)$rawLeads, 'is_array'));

// Lista de usuarios para asignación (solo admin)
$tenantUsers = [];
if (isAdmin()) {
    $rawUsers = apiGet('/users');
    if (is_array($rawUsers) && !isset($rawUsers['error'])) {
        $tenantUsers = array_values(array_filter(
            array_filter($rawUsers, 'is_array'),
            fn($u) => ($u['is_active'] ?? true)
        ));
    }
}

// Sustantivo por giro (Paciente/Cliente) para las tarjetas.
$ind  = tenantIndustry();
$noun = in_array($ind, ['dental','consultorio','clinica','salud','medico'], true) ? 'paciente' : 'cliente';
renderHead('Pipeline');
?>

<div class="layout-wrapper layout-content-navbar">
<div class="layout-container">
<?php renderSidebar('leads'); ?>
<div class="layout-page">
<?php renderNavbar('Pipeline'); ?>
<div class="content-wrapper">
<div class="container-xxl flex-grow-1 container-p-y">

  <!-- Header -->
  <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
      <h4 class="mb-1"><i class="bx bx-trending-up me-1 text-primary"></i>Pipeline de <?= e($noun) ?>s</h4>
      <p class="text-muted mb-0">
        Cómo avanzan tus contactos por cada etapa
        <?php $cnt = count((array)$leads); if ($cnt > 0): ?>
          · <span class="fw-semibold text-body"><?= $cnt ?> contacto<?= $cnt !== 1 ? 's' : '' ?></span>
        <?php endif; ?>
      </p>
    </div>
    <!-- Toggle de vista Tablero / Lista -->
    <div class="btn-group btn-group-sm" role="group" id="view-toggle">
      <button type="button" class="btn btn-primary" data-view="board"><i class="bx bx-grid-alt me-1"></i>Tablero</button>
      <button type="button" class="btn btn-outline-primary" data-view="list"><i class="bx bx-list-ul me-1"></i>Lista</button>
    </div>
  </div>

  <!-- Filtros rápidos + búsqueda -->
  <div class="d-flex gap-2 flex-wrap align-items-center mb-3">
    <?php
    $filters = [
      ''           => ['label' => 'Todos',       'class' => 'btn-outline-secondary'],
      'new'        => ['label' => 'Nuevos',       'class' => 'btn-outline-primary'],
      'contacted'  => ['label' => 'Contactados',  'class' => 'btn-outline-info'],
      'qualified'  => ['label' => 'Calificados',  'class' => 'btn-outline-warning'],
      'converted'  => ['label' => 'Convertidos',  'class' => 'btn-outline-success'],
      'lost'       => ['label' => 'Perdidos',     'class' => 'btn-outline-danger'],
    ];
    foreach ($filters as $val => $f):
      $active = $statusFilter === $val ? 'active' : '';
    ?>
      <a href="?status=<?= e($val) ?><?= $filterSearch ? '&q='.urlencode($filterSearch) : '' ?>"
         class="btn btn-sm <?= $f['class'] ?> <?= $active ?>">
        <?= $f['label'] ?>
      </a>
    <?php endforeach; ?>

    <!-- Búsqueda -->
    <form method="get" class="ms-auto d-flex gap-2" style="min-width:220px">
      <?php if ($statusFilter): ?><input type="hidden" name="status" value="<?= e($statusFilter) ?>"><?php endif; ?>
      <div class="input-group input-group-sm">
        <span class="input-group-text"><i class="bx bx-search"></i></span>
        <input type="text" name="q" class="form-control" placeholder="Buscar nombre o teléfono…"
               value="<?= e($filterSearch) ?>">
        <?php if ($filterSearch): ?>
          <a href="?status=<?= e($statusFilter) ?>" class="btn btn-outline-secondary" title="Limpiar">
            <i class="bx bx-x"></i>
          </a>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <!-- ── Vista TABLERO (Kanban del pipeline) ─────────────────── -->
  <?php
  $stages = [
    'new'       => ['Nuevos',       'secondary'],
    'contacted' => ['Contactados',  'info'],
    'qualified' => ['Calificados',  'primary'],
    'converted' => ['Conversión',   'success'],
    'loyal'     => ['Fidelización', 'warning'],
  ];
  $byStage = array_fill_keys(array_keys($stages), []);
  $lost = 0;
  foreach ($leads as $l) {
    $st = $l['status'] ?? 'new';
    if (isset($byStage[$st])) $byStage[$st][] = $l;
    elseif ($st === 'lost') $lost++;
  }
  ?>
  <div id="view-board">
    <div class="pipeline">
      <?php foreach ($stages as $key => $st): [$label, $color] = $st; $col = $byStage[$key]; ?>
      <div class="pipeline-col">
        <div class="pipeline-head">
          <span class="dot bg-<?= $color ?>"></span>
          <span class="fw-semibold flex-grow-1"><?= e($label) ?></span>
          <span class="badge bg-label-<?= $color ?>"><?= count($col) ?></span>
        </div>
        <div class="pipeline-list" data-status="<?= e($key) ?>">
          <?php foreach ($col as $l):
            $spent  = (int)($l['spent_cents'] ?? 0);
            $when   = formatDate($l['created_at'] ?? '', 'd/m/Y');
            $ch     = $l['source_channel'] ?? '';
            $chIcon = $ch==='voice'?'bx-phone':($ch==='whatsapp'?'bxl-whatsapp':($ch==='webchat'?'bx-globe':'bx-user'));
          ?>
          <div class="pipeline-card" data-id="<?= e($l['id']) ?>">
            <div class="d-flex align-items-center gap-2 mb-1">
              <span class="avatar-initial rounded-circle bg-label-<?= $color ?>" style="width:26px;height:26px;display:flex;align-items:center;justify-content:center;font-size:.78rem;flex-shrink:0"><?= e(strtoupper(substr($l['name'] ?: 'C', 0, 1))) ?></span>
              <span class="fw-semibold small text-truncate flex-grow-1"><?= e($l['name'] ?: 'Sin nombre') ?></span>
              <a href="/pages/lead-detail.php?id=<?= e($l['id']) ?>" class="text-muted card-open" title="Ver ficha"><i class="bx bx-link-external"></i></a>
            </div>
            <div class="d-flex align-items-center justify-content-between">
              <small class="text-muted"><i class="bx <?= $chIcon ?> me-1"></i><?= e($when) ?></small>
              <?php if ($spent > 0): ?><small class="fw-semibold text-success">$<?= number_format($spent/100, 0) ?></small><?php endif; ?>
            </div>
            <?php if ((int)($l['visits']??0) > 0 || (int)($l['convs']??0) > 0): ?>
            <div class="mt-1 d-flex gap-3">
              <?php if ((int)($l['visits']??0) > 0): ?><small class="text-muted"><i class="bx bx-calendar-check me-1"></i><?= (int)$l['visits'] ?></small><?php endif; ?>
              <?php if ((int)($l['convs']??0) > 0): ?><small class="text-muted"><i class="bx bx-conversation me-1"></i><?= (int)$l['convs'] ?></small><?php endif; ?>
            </div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php if ($lost > 0): ?><p class="text-muted small mt-2"><i class="bx bx-archive me-1"></i><?= $lost ?> contacto(s) marcados como perdidos (visibles en la Lista).</p><?php endif; ?>
  </div>

  <!-- ── Vista LISTA (tabla) ──────────────────────────────────── -->
  <div id="view-list" style="display:none">
  <!-- Tabla de leads -->
  <div class="card">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead class="table-light">
          <tr>
            <th>Nombre</th>
            <th>Teléfono</th>
            <th>Intención</th>
            <th>Estado</th>
            <th>Asignado a</th>
            <th>Canal</th>
            <th>Fecha</th>
            <th class="text-end">Acciones</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($leads)): ?>
            <tr>
              <td colspan="8" class="text-center text-muted py-5">
                <i class="bx bx-user-x bx-lg d-block mb-2 opacity-25"></i>
                No hay leads<?= $statusFilter ? " con estado \"$statusFilter\"" : '' ?> aún
              </td>
            </tr>
          <?php else: foreach ((array)$leads as $lead): ?>
            <tr data-lead-id="<?= e($lead['id']) ?>">
              <td>
                <span class="fw-semibold"><?= e($lead['name'] ?? '—') ?></span>
                <?php if ($lead['score'] ?? 0): ?>
                  <span class="badge bg-label-warning ms-1"><?= (int)$lead['score'] ?>pts</span>
                <?php endif; ?>
                <?php if ($lead['email'] ?? ''): ?>
                  <br><small class="text-muted"><?= e($lead['email']) ?></small>
                <?php endif; ?>
              </td>
              <td>
                <a href="tel:<?= e($lead['phone'] ?? '') ?>" class="text-body">
                  <?= e($lead['phone'] ?? '—') ?>
                </a>
              </td>
              <td>
                <small class="text-muted">
                  <?= e(is_array($lead['custom_data'] ?? null)
                      ? ($lead['custom_data']['intent'] ?? '—')
                      : '—') ?>
                </small>
              </td>
              <td>
                <?php if (isAdmin()): ?>
                <div class="dropdown">
                  <button class="btn btn-sm p-0 border-0 bg-transparent dropdown-toggle"
                          data-bs-toggle="dropdown">
                    <?= statusBadge($lead['status'] ?? 'new') ?>
                  </button>
                  <ul class="dropdown-menu dropdown-menu-sm">
                    <?php foreach (['new','contacted','qualified','converted','lost'] as $s): ?>
                      <li>
                        <a class="dropdown-item small status-change"
                           href="#"
                           data-id="<?= e($lead['id']) ?>"
                           data-status="<?= $s ?>">
                          <?= statusBadge($s) ?>
                        </a>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                </div>
                <?php else: ?>
                  <?= statusBadge($lead['status'] ?? 'new') ?>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($lead['assigned_name'] ?? ''): ?>
                  <span class="badge bg-label-primary">
                    <i class="bx bx-user me-1"></i><?= e($lead['assigned_name']) ?>
                  </span>
                <?php else: ?>
                  <span class="text-muted small">Sin asignar</span>
                <?php endif; ?>
              </td>
              <td><?= channelIcon($lead['source_channel'] ?? 'voice') ?></td>
              <td><small class="text-muted"><?= formatDate($lead['created_at'] ?? '', 'd/m/Y') ?></small></td>
              <td class="text-end">
                <div class="d-flex gap-1 justify-content-end">
                  <?php if (isAdmin()): ?>
                  <button class="btn btn-sm btn-icon btn-outline-secondary btn-assign-lead"
                          title="Asignar a usuario"
                          data-id="<?= e($lead['id']) ?>"
                          data-name="<?= e($lead['name'] ?? '') ?>"
                          data-assigned="<?= e($lead['assigned_to'] ?? '') ?>">
                    <i class="bx bx-user-plus"></i>
                  </button>
                  <?php endif; ?>
                  <a href="/pages/lead-detail.php?id=<?= e($lead['id']) ?>"
                     class="btn btn-sm btn-icon btn-outline-primary"
                     title="Ver detalle">
                    <i class="bx bx-show"></i>
                  </a>
                </div>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  </div><!-- /#view-list -->

</div>
<?php renderFooter(); ?>

<style>
.pipeline { display:flex; gap:.85rem; overflow-x:auto; padding-bottom:.5rem; align-items:flex-start; }
.pipeline-col { flex:0 0 250px; max-width:250px; background:var(--bs-light); border-radius:12px; padding:.6rem; }
html.dark-style .pipeline-col { background:#2b2f45; }
.pipeline-head { display:flex; align-items:center; gap:.5rem; padding:.2rem .3rem .55rem; }
.pipeline-head .dot { width:9px; height:9px; border-radius:50%; flex-shrink:0; }
.pipeline-list { min-height:60px; display:flex; flex-direction:column; gap:.5rem; }
.pipeline-card { background:var(--bs-body-bg); border:1px solid var(--bs-border-color); border-radius:10px; padding:.55rem .6rem; cursor:grab; transition:box-shadow .12s, transform .12s; }
.pipeline-card:hover { box-shadow:0 4px 12px rgba(105,108,255,.15); }
.pipeline-card.sortable-ghost { opacity:.45; }
.pipeline-card.sortable-chosen { cursor:grabbing; }
.pipeline-card .card-open { font-size:.95rem; opacity:.5; flex-shrink:0; }
.pipeline-card .card-open:hover { opacity:1; }
.pipeline-list.drag-over { outline:2px dashed var(--bs-primary); outline-offset:2px; border-radius:8px; }
</style>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
<script>
(function () {
  // ── Toggle Tablero / Lista ──────────────────────────────────
  const board = document.getElementById('view-board');
  const list  = document.getElementById('view-list');
  document.querySelectorAll('#view-toggle [data-view]').forEach(b => b.addEventListener('click', function () {
    const v = this.dataset.view;
    document.querySelectorAll('#view-toggle .btn').forEach(x => { x.classList.remove('btn-primary'); x.classList.add('btn-outline-primary'); });
    this.classList.add('btn-primary'); this.classList.remove('btn-outline-primary');
    board.style.display = v === 'board' ? '' : 'none';
    list.style.display  = v === 'list'  ? '' : 'none';
    try { localStorage.setItem('pipeline_view', v); } catch (_) {}
  }));
  try { if (localStorage.getItem('pipeline_view') === 'list') document.querySelector('#view-toggle [data-view="list"]').click(); } catch (_) {}

  // ── Drag & drop entre etapas (SortableJS) ───────────────────
  if (!window.Sortable) return;
  document.querySelectorAll('.pipeline-list').forEach(listEl => {
    new Sortable(listEl, {
      group: 'pipeline', animation: 150, draggable: '.pipeline-card',
      ghostClass: 'sortable-ghost', chosenClass: 'sortable-chosen',
      onAdd: async function (evt) {
        const card     = evt.item;
        const newStatus = evt.to.dataset.status;
        const id        = card.dataset.id;
        // Actualizar contadores de las columnas afectadas
        updateCounts();
        try {
          const res = await fetch('/api/lead-status.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id, status: newStatus }),
          });
          if (!res.ok) throw new Error('No se pudo mover');
          window.showToast?.('Contacto movido a ' + colLabel(evt.to), 'success');
        } catch (e) {
          window.showToast?.('No se pudo mover el contacto', 'error');
          evt.from.appendChild(card);  // revertir
          updateCounts();
        }
      },
    });
  });

  function colLabel(listEl) { return listEl.closest('.pipeline-col')?.querySelector('.fw-semibold')?.textContent?.trim() || ''; }
  function updateCounts() {
    document.querySelectorAll('.pipeline-col').forEach(col => {
      const n = col.querySelectorAll('.pipeline-card').length;
      const b = col.querySelector('.badge'); if (b) b.textContent = n;
    });
  }
})();
</script>

<?php if (isAdmin()): ?>
<!-- ── Modal: Asignar lead a usuario ─────────────────────────── -->
<div class="modal fade" id="modalAssignLead" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bx bx-user-plus me-2"></i>Asignar lead</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted small mb-3" id="assignLeadName"></p>
        <label class="form-label fw-semibold">Asignar a</label>
        <select class="form-select" id="assignLeadUser">
          <option value="">— Sin asignar —</option>
          <?php foreach ($tenantUsers as $u): ?>
            <option value="<?= e($u['id']) ?>"><?= e($u['name']) ?>
              <?= $u['role'] === 'admin' ? ' (admin)' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
        <div id="assignLeadError" class="text-danger small mt-2" style="display:none"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn btn-primary btn-sm" id="btnConfirmAssignLead">
          <span class="spinner-border spinner-border-sm me-1 d-none" id="spinnerAssign"></span>
          Guardar
        </button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
// ── Mapa de badges por estado ─────────────────────────────────
const STATUS_BADGES = {
  new:       '<span class="badge bg-label-primary">Nuevo</span>',
  contacted: '<span class="badge bg-label-info">Contactado</span>',
  qualified: '<span class="badge bg-label-warning">Calificado</span>',
  converted: '<span class="badge bg-label-success">Convertido</span>',
  lost:      '<span class="badge bg-label-danger">Perdido</span>',
};

// ── Cambio de estado inline (sin reload) ─────────────────────
document.querySelectorAll('.status-change').forEach(btn => {
  btn.addEventListener('click', async function(e) {
    e.preventDefault();
    const id     = this.dataset.id;
    const status = this.dataset.status;

    try {
      const res = await fetch('/api/lead-status.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({ id, status }),
      });
      if (!res.ok) throw new Error((await res.json()).error || 'Error');

      // Actualizar badge en el dropdown button sin recargar
      const row       = document.querySelector(`tr[data-lead-id="${id}"]`);
      const dropToggle = row?.querySelector('.dropdown-toggle');
      if (dropToggle) dropToggle.innerHTML = STATUS_BADGES[status] ?? status;

      window.showToast?.('Estado actualizado', 'success');
    } catch (err) {
      window.showToast?.(err.message || 'Error actualizando el estado', 'danger');
    }
  });
});

<?php if (isAdmin()): ?>
// ── Asignación de lead a usuario ─────────────────────────────
const modalEl    = document.getElementById('modalAssignLead');
const modal      = modalEl ? new bootstrap.Modal(modalEl) : null;
const selUser    = document.getElementById('assignLeadUser');
const errDiv     = document.getElementById('assignLeadError');
const spinner    = document.getElementById('spinnerAssign');
const btnConfirm = document.getElementById('btnConfirmAssignLead');
let currentLeadId = null;

document.querySelectorAll('.btn-assign-lead').forEach(btn => {
  btn.addEventListener('click', function() {
    currentLeadId = this.dataset.id;
    document.getElementById('assignLeadName').textContent = this.dataset.name || '';
    // Pre-seleccionar asignación actual
    const assigned = this.dataset.assigned || '';
    if (selUser) {
      selUser.value = assigned;
      if (!selUser.value) selUser.value = '';
    }
    if (errDiv) { errDiv.style.display = 'none'; errDiv.textContent = ''; }
    modal?.show();
  });
});

btnConfirm?.addEventListener('click', async function() {
  if (!currentLeadId) return;
  const userId   = selUser?.value || null;
  const userName = selUser?.options[selUser.selectedIndex]?.text?.replace(' (admin)','').trim() || null;

  spinner?.classList.remove('d-none');
  btnConfirm.disabled = true;
  errDiv.style.display = 'none';

  try {
    const res = await fetch('/api/assign-lead.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({ id: currentLeadId, user_id: userId || null }),
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Error al asignar');
    modal?.hide();

    // Actualizar celda "Asignado a" en la fila sin recargar
    const btn  = document.querySelector(`.btn-assign-lead[data-id="${currentLeadId}"]`);
    if (btn) {
      btn.dataset.assigned = userId || '';
      const row  = btn.closest('tr');
      // 5ª columna (índice 4) = Asignado a
      const cell = row?.querySelectorAll('td')[4];
      if (cell) {
        cell.innerHTML = userId && userName
          ? `<span class="badge bg-label-primary"><i class="bx bx-user me-1"></i>${userName}</span>`
          : `<span class="text-muted small">Sin asignar</span>`;
      }
    }
    window.showToast?.('Asignación guardada', 'success');
  } catch (err) {
    errDiv.textContent = err.message;
    errDiv.style.display = 'block';
  } finally {
    spinner?.classList.add('d-none');
    btnConfirm.disabled = false;
  }
});
<?php endif; ?>
</script>
