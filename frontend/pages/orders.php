<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/head.php';
require_once __DIR__ . '/../includes/sidebar.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/footer.php';

requireAuth();
if (!isAdmin()) { header('Location: /index.php'); exit; }

$raw    = apiGet('/orders');
$orders = array_values(array_filter((array)($raw['data'] ?? []), 'is_array'));

$statusMap = [
  'pending'   => ['bg-label-warning',   'Pendiente de pago'],
  'paid'      => ['bg-label-success',    'Pagado'],
  'fulfilled' => ['bg-label-info',       'Entregado'],
  'cancelled' => ['bg-label-danger',     'Cancelado'],
];

// KPIs rápidos
$totalPaid = 0; $countPaid = 0; $countPending = 0;
foreach ($orders as $o) {
  if (($o['status'] ?? '') === 'paid')      { $totalPaid += (int)$o['total_cents']; $countPaid++; }
  if (($o['status'] ?? '') === 'pending')   { $countPending++; }
}

renderHead('Pedidos');
?>
<div class="layout-wrapper layout-content-navbar"><div class="layout-container">
<?php renderSidebar('orders'); ?>
<div class="layout-page"><?php renderNavbar('Pedidos'); ?>
<div class="content-wrapper"><div class="container-xxl flex-grow-1 container-p-y">

  <div class="mb-4">
    <h4 class="mb-1"><i class="bx bx-receipt text-primary me-2"></i>Pedidos</h4>
    <p class="text-muted mb-0">Ventas generadas por tu bot desde el catálogo.</p>
  </div>

  <!-- KPIs -->
  <div class="row g-3 mb-4">
    <div class="col-sm-4">
      <div class="card"><div class="card-body">
        <span class="text-muted small d-block">Ingresos pagados</span>
        <h4 class="mb-0">$<?= number_format($totalPaid / 100, 2) ?> <small class="text-muted">MXN</small></h4>
      </div></div>
    </div>
    <div class="col-sm-4">
      <div class="card"><div class="card-body">
        <span class="text-muted small d-block">Pedidos pagados</span>
        <h4 class="mb-0"><?= $countPaid ?></h4>
      </div></div>
    </div>
    <div class="col-sm-4">
      <div class="card"><div class="card-body">
        <span class="text-muted small d-block">Pendientes de pago</span>
        <h4 class="mb-0"><?= $countPending ?></h4>
      </div></div>
    </div>
  </div>

  <?php if (empty($orders)): ?>
  <div class="card"><div class="card-body text-center py-5">
    <i class="bx bx-receipt d-block mb-2 text-muted" style="font-size:3rem;opacity:.4"></i>
    <h5>Aún no hay pedidos</h5>
    <p class="text-muted">Cuando un cliente compre por el bot, el pedido aparecerá aquí.</p>
  </div></div>
  <?php else: ?>
  <div class="card">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>Cliente</th>
            <th>Productos</th>
            <th>Total</th>
            <th>Canal</th>
            <th>Estado</th>
            <th>Fecha</th>
            <th class="text-end">Acción</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($orders as $o):
            [$badge, $label] = $statusMap[$o['status'] ?? 'pending'] ?? ['bg-label-secondary', $o['status'] ?? ''];
            $items = $o['items'] ?? [];
          ?>
          <tr data-id="<?= e($o['id']) ?>">
            <td>
              <span class="fw-semibold d-block"><?= e($o['customer_name'] ?? '—') ?></span>
              <?php if (!empty($o['customer_phone'])): ?>
                <small class="text-muted"><?= e($o['customer_phone']) ?></small>
              <?php endif; ?>
            </td>
            <td>
              <?php foreach ($items as $it): ?>
                <small class="d-block"><?= (int)$it['quantity'] ?> × <?= e($it['name']) ?></small>
              <?php endforeach; ?>
            </td>
            <td class="fw-semibold">$<?= number_format(($o['total_cents'] ?? 0) / 100, 2) ?></td>
            <td><small class="text-muted"><?= e($o['channel'] ?? '') ?></small></td>
            <td><span class="badge <?= $badge ?> order-badge"><?= $label ?></span></td>
            <td><small class="text-muted"><?= formatDate($o['created_at'] ?? '', 'd/m/Y H:i') ?></small></td>
            <td class="text-end">
              <?php if (($o['status'] ?? '') === 'paid'): ?>
                <button class="btn btn-sm btn-outline-info btn-order-status" data-status="fulfilled">Marcar entregado</button>
              <?php elseif (($o['status'] ?? '') === 'pending'): ?>
                <button class="btn btn-sm btn-outline-danger btn-order-status" data-status="cancelled">Cancelar</button>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

</div></div>
<?php renderFooter(); ?>
</div></div></div>

<script>
(function () {
  'use strict';
  document.querySelectorAll('.btn-order-status').forEach(btn => {
    btn.addEventListener('click', async () => {
      const row = btn.closest('tr');
      const id = row.dataset.id, status = btn.dataset.status;
      btn.disabled = true;
      try {
        const res = await fetch('/api/order-status.php', {
          method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ id, status }),
        });
        const data = await res.json();
        if (data.id) { window.showToast?.('Pedido actualizado', 'success'); setTimeout(() => location.reload(), 600); }
        else { window.showToast?.(data.error || 'Error', 'error'); btn.disabled = false; }
      } catch { window.showToast?.('Error de red', 'error'); btn.disabled = false; }
    });
  });
})();
</script>
