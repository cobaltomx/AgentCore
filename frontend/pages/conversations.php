<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/head.php';
require_once __DIR__ . '/../includes/sidebar.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/footer.php';

requireAuth();

$limit  = 20;
$page   = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

// Filtros opcionales
$filterChannel = $_GET['channel'] ?? '';
$filterAgent   = $_GET['agent_id'] ?? '';
$filterSearch  = trim($_GET['q'] ?? '');
$params = ['limit' => $limit, 'offset' => $offset];
if ($filterChannel) $params['channel']  = $filterChannel;
if ($filterAgent)   $params['agent_id'] = $filterAgent;
if ($filterSearch)  $params['q']        = $filterSearch;

$convs  = apiGet('/conversations', $params);
$data   = array_values(array_filter((array)($convs['data'] ?? []), 'is_array'));
$total  = (int)($convs['total'] ?? count($data));

// Usuarios para asignación (solo admin)
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

// Agentes para filtro
$agents = apiGet('/agents');
$agents = is_array($agents) ? array_values(array_filter($agents, 'is_array')) : [];

renderHead('Conversaciones');
?>
<div class="layout-wrapper layout-content-navbar">
<div class="layout-container">
<?php renderSidebar('conversations'); ?>
<div class="layout-page">
<?php renderNavbar('Conversaciones'); ?>
<div class="content-wrapper">
<div class="container-xxl flex-grow-1 container-p-y">

  <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-3">
    <div>
      <h4 class="mb-1">Conversaciones</h4>
      <p class="text-muted mb-0">
        Historial completo de interacciones de los agentes
        <?php if ($total > 0): ?>
          · <span class="fw-semibold text-body"><?= $total ?> registro<?= $total !== 1 ? 's' : '' ?></span>
        <?php endif; ?>
      </p>
    </div>
  </div>

  <?php
    // ── Atención humana: conversaciones donde el bot transfirió ──────────
    $handoffsRaw = apiGet('/conversations', ['needs_human' => 1, 'limit' => 20]);
    $handoffs    = array_values(array_filter((array)($handoffsRaw['data'] ?? []), 'is_array'));
  ?>
  <?php if (!empty($handoffs)): ?>
  <div class="card border-warning mb-4" style="border-width:2px!important">
    <div class="card-header bg-label-warning d-flex align-items-center gap-2 py-2">
      <i class="bx bx-bell bx-tada"></i>
      <h6 class="mb-0 fw-semibold"><?= count($handoffs) ?> cliente<?= count($handoffs) !== 1 ? 's' : '' ?> necesita<?= count($handoffs) === 1 ? '' : 'n' ?> atención humana</h6>
    </div>
    <div class="list-group list-group-flush">
      <?php foreach ($handoffs as $h): ?>
      <div class="list-group-item d-flex align-items-start justify-content-between gap-3 py-3" data-handoff-id="<?= e($h['id']) ?>">
        <div class="flex-grow-1">
          <div class="d-flex align-items-center gap-2 mb-1">
            <span class="badge bg-label-<?= ($h['channel'] ?? '')==='whatsapp'?'success':(($h['channel'] ?? '')==='voice'?'danger':'info') ?>"><?= e($h['channel'] ?? '') ?></span>
            <span class="fw-semibold"><?= e($h['contact_name'] ?? $h['contact_phone'] ?? 'Cliente') ?></span>
            <small class="text-muted"><?= formatDate($h['handoff_at'] ?? $h['started_at'] ?? '', 'd/m H:i') ?></small>
          </div>
          <p class="mb-0 small text-muted"><i class="bx bx-info-circle me-1"></i><?= e($h['handoff_reason'] ?? 'Solicitó hablar con una persona') ?></p>
        </div>
        <div class="d-flex gap-2 flex-shrink-0">
          <a href="/pages/conversation-detail.php?id=<?= e($h['id']) ?>" class="btn btn-sm btn-outline-secondary">Ver chat</a>
          <button class="btn btn-sm btn-warning btn-claim-handoff" data-id="<?= e($h['id']) ?>">
            <i class="bx bx-user-check me-1"></i>Atender yo
          </button>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Barra de filtros + búsqueda -->
  <div class="d-flex gap-2 flex-wrap align-items-center mb-3">
    <!-- Canal -->
    <?php foreach ([''=>'Todos','voice'=>'Voz','whatsapp'=>'WhatsApp','webchat'=>'Web'] as $ch=>$lbl): ?>
      <a href="?channel=<?= e($ch) ?><?= $filterAgent ? '&agent_id='.e($filterAgent) : '' ?><?= $filterSearch ? '&q='.urlencode($filterSearch) : '' ?>"
         class="btn btn-sm <?= $filterChannel===$ch ? 'btn-primary' : 'btn-outline-secondary' ?>">
        <?= $lbl ?>
      </a>
    <?php endforeach; ?>

    <!-- Agente -->
    <?php if (!empty($agents) && isAdmin()): ?>
      <select class="form-select form-select-sm" style="width:auto"
              onchange="window.location='?channel=<?= e($filterChannel) ?>&agent_id='+this.value+'<?= $filterSearch ? '&q='.urlencode($filterSearch) : '' ?>'">
        <option value="">Todos los agentes</option>
        <?php foreach ($agents as $ag): ?>
          <option value="<?= e($ag['id']) ?>" <?= $filterAgent===$ag['id'] ? 'selected' : '' ?>>
            <?= e($ag['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    <?php endif; ?>

    <!-- Búsqueda -->
    <form method="get" class="ms-auto d-flex gap-2" style="min-width:220px">
      <?php if ($filterChannel): ?><input type="hidden" name="channel" value="<?= e($filterChannel) ?>"><?php endif; ?>
      <?php if ($filterAgent):   ?><input type="hidden" name="agent_id" value="<?= e($filterAgent) ?>"><?php endif; ?>
      <div class="input-group input-group-sm">
        <span class="input-group-text"><i class="bx bx-search"></i></span>
        <input type="text" name="q" class="form-control" placeholder="Buscar contacto…"
               value="<?= e($filterSearch) ?>">
        <?php if ($filterSearch): ?>
          <a href="?channel=<?= e($filterChannel) ?>&agent_id=<?= e($filterAgent) ?>"
             class="btn btn-outline-secondary" title="Limpiar búsqueda">
            <i class="bx bx-x"></i>
          </a>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <div class="card">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead class="table-light">
          <tr>
            <th>Contacto</th>
            <th>Agente</th>
            <th>Canal</th>
            <th>Estado</th>
            <th>Resultado</th>
            <th>Asignado a</th>
            <th>Duración</th>
            <th>Fecha</th>
            <th class="text-end">Acciones</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($data)): ?>
            <tr><td colspan="9" class="text-center text-muted py-5">
              <i class="bx bx-conversation bx-lg d-block mb-2 opacity-25"></i>
              Sin conversaciones aún
            </td></tr>
          <?php else: foreach ($data as $c): ?>
            <tr>
              <td>
                <span class="fw-semibold d-block"><?= e($c['contact_name'] ?? '—') ?></span>
                <small class="text-muted"><?= e($c['contact_phone'] ?? '') ?></small>
              </td>
              <td><small><?= e($c['agent_name'] ?? '—') ?></small></td>
              <td>
                <?= channelIcon($c['channel'] ?? '') ?>
                <small class="text-muted"><?= e($c['channel'] ?? '') ?></small>
              </td>
              <td><?= statusBadge($c['status'] ?? 'active') ?></td>
              <td>
                <?php if ($c['outcome'] ?? ''): ?>
                  <span class="badge bg-label-info">
                    <?= e(str_replace('_', ' ', $c['outcome'])) ?>
                  </span>
                <?php else: ?>
                  <span class="text-muted">—</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($c['assigned_name'] ?? ''): ?>
                  <span class="badge bg-label-primary">
                    <i class="bx bx-user me-1"></i><?= e($c['assigned_name']) ?>
                  </span>
                <?php else: ?>
                  <span class="text-muted small">Sin asignar</span>
                <?php endif; ?>
              </td>
              <td>
                <small><?= $c['duration_secs'] ? formatMins((int)$c['duration_secs']) : '—' ?></small>
              </td>
              <td>
                <small class="text-muted"><?= formatDate($c['started_at'] ?? '', 'd/m/Y H:i') ?></small>
              </td>
              <td class="text-end">
                <div class="d-flex gap-1 justify-content-end">
                  <?php if (isAdmin()): ?>
                  <button class="btn btn-sm btn-icon btn-outline-secondary btn-assign-conv"
                          title="Asignar a usuario"
                          data-id="<?= e($c['id']) ?>"
                          data-name="<?= e($c['contact_name'] ?? '') ?>"
                          data-assigned="<?= e($c['assigned_to'] ?? '') ?>">
                    <i class="bx bx-user-plus"></i>
                  </button>
                  <?php endif; ?>
                  <a href="/pages/conversation-detail.php?id=<?= e($c['id']) ?>"
                     class="btn btn-sm btn-icon btn-outline-primary" title="Ver mensajes">
                    <i class="bx bx-show"></i>
                  </a>
                </div>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Paginación -->
    <?php
    $hasPrev = $page > 1;
    $hasNext = count($data) === $limit;
    $qs = array_filter(['channel'=>$filterChannel,'agent_id'=>$filterAgent,'q'=>$filterSearch]);
    $qsStr = $qs ? '&'.http_build_query($qs) : '';
    ?>
    <?php if ($hasPrev || $hasNext): ?>
    <div class="card-footer d-flex justify-content-between align-items-center py-2">
      <small class="text-muted">Página <?= $page ?> · mostrando <?= count($data) ?></small>
      <div class="d-flex gap-2">
        <?php if ($hasPrev): ?>
          <a href="?page=<?= $page-1 ?><?= $qsStr ?>"
             class="btn btn-sm btn-outline-secondary">
            <i class="bx bx-chevron-left me-1"></i>Anterior
          </a>
        <?php endif; ?>
        <?php if ($hasNext): ?>
          <a href="?page=<?= $page+1 ?><?= $qsStr ?>"
             class="btn btn-sm btn-outline-secondary">
            Siguiente<i class="bx bx-chevron-right ms-1"></i>
          </a>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

</div>
<?php renderFooter(); ?>

<?php if (isAdmin()): ?>
<!-- ── Modal: Asignar conversación a usuario ──────────────────── -->
<div class="modal fade" id="modalAssignConv" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bx bx-user-plus me-2"></i>Asignar conversación</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted small mb-3" id="assignConvName"></p>
        <label class="form-label fw-semibold">Asignar a</label>
        <select class="form-select" id="assignConvUser">
          <option value="">— Sin asignar —</option>
          <?php foreach ($tenantUsers as $u): ?>
            <option value="<?= e($u['id']) ?>"><?= e($u['name']) ?>
              <?= $u['role'] === 'admin' ? ' (admin)' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
        <div id="assignConvError" class="text-danger small mt-2" style="display:none"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn btn-primary btn-sm" id="btnConfirmAssignConv">
          <span class="spinner-border spinner-border-sm me-1 d-none" id="spinnerAssignConv"></span>
          Guardar
        </button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
<?php if (isAdmin()): ?>
// ── Asignación de conversación a usuario ─────────────────────
const modalEl    = document.getElementById('modalAssignConv');
const modal      = modalEl ? new bootstrap.Modal(modalEl) : null;
const selUser    = document.getElementById('assignConvUser');
const errDiv     = document.getElementById('assignConvError');
const spinner    = document.getElementById('spinnerAssignConv');
const btnConfirm = document.getElementById('btnConfirmAssignConv');
let currentConvId = null;

document.querySelectorAll('.btn-assign-conv').forEach(btn => {
  btn.addEventListener('click', function() {
    currentConvId = this.dataset.id;
    document.getElementById('assignConvName').textContent = this.dataset.name || 'Contacto sin nombre';
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
  if (!currentConvId) return;
  const userId   = selUser?.value || null;
  const userName = selUser?.options[selUser.selectedIndex]?.text?.replace(' (admin)','').trim() || null;

  spinner?.classList.remove('d-none');
  btnConfirm.disabled = true;
  errDiv.style.display = 'none';

  try {
    const res = await fetch('/api/assign-conv.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({ id: currentConvId, user_id: userId || null }),
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Error al asignar');
    modal?.hide();

    // Actualizar celda "Asignado a" en la fila correspondiente sin recargar
    const btn = document.querySelector(`.btn-assign-conv[data-id="${currentConvId}"]`);
    if (btn) {
      btn.dataset.assigned = userId || '';
      const row   = btn.closest('tr');
      // 6ª columna (índice 5) = Asignado a
      const cell  = row?.querySelectorAll('td')[5];
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

// ── Atención humana: "Atender yo" (claim) ──────────────────────
document.querySelectorAll('.btn-claim-handoff').forEach(btn => {
  btn.addEventListener('click', async () => {
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
    try {
      const res = await fetch('/api/conversation-handoff.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: btn.dataset.id, action: 'claim' }),
      });
      const data = await res.json();
      if (data.ok) {
        window.showToast?.('Conversación asignada a ti', 'success');
        // Quitar de la lista de pendientes y abrir el chat
        document.querySelector(`[data-handoff-id="${btn.dataset.id}"]`)?.remove();
        setTimeout(() => { window.location.href = '/pages/conversation-detail.php?id=' + btn.dataset.id; }, 700);
      } else {
        window.showToast?.(data.error || 'Error', 'error');
        btn.disabled = false; btn.innerHTML = '<i class="bx bx-user-check me-1"></i>Atender yo';
      }
    } catch {
      window.showToast?.('Error de red', 'error');
      btn.disabled = false; btn.innerHTML = '<i class="bx bx-user-check me-1"></i>Atender yo';
    }
  });
});
</script>
