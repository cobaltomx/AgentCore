<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/head.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/navbar.php';
require_once __DIR__ . '/../../includes/footer.php';

requireAuth();
requireRole('superadmin');

$data    = apiGet('/superadmin/costs');
$rows    = is_array($data['rows'] ?? null) ? $data['rows'] : [];
$totals  = $data['totals'] ?? ['costCents'=>0,'revenueCents'=>0,'marginCents'=>0];

function money($cents) { return '$' . number_format(((int)$cents) / 100, 2); }
function marginClass($cents) { return $cents > 0 ? 'text-success' : ($cents < 0 ? 'text-danger' : 'text-muted'); }

$periodSince = isset($data['period']['since']) ? date('d/m/Y', strtotime($data['period']['since'])) : '';

renderHead('Costos y márgenes');
?>
<div class="layout-wrapper layout-content-navbar">
<div class="layout-container">
<?php renderSidebar('sa-costs'); ?>
<div class="layout-page">
<?php renderNavbar('Costos y márgenes'); ?>
<div class="content-wrapper">
<div class="container-xxl flex-grow-1 container-p-y">

  <div class="mb-4">
    <h4 class="mb-1">Costos y márgenes</h4>
    <p class="text-muted mb-0">
      Costo estimado de infraestructura vs. ingreso por tenant — mes actual<?= $periodSince ? ' (desde '.$periodSince.')' : '' ?>.
      Las tarifas se configuran en <a href="/pages/admin/plans.php">Planes y tarifas</a>.
    </p>
  </div>

  <!-- KPIs -->
  <div class="row g-3 mb-4">
    <div class="col-md-4">
      <div class="card"><div class="card-body">
        <p class="text-muted mb-1 small">Costo infra (total)</p>
        <h3 class="mb-0 text-danger"><?= money($totals['costCents']) ?></h3>
      </div></div>
    </div>
    <div class="col-md-4">
      <div class="card"><div class="card-body">
        <p class="text-muted mb-1 small">Ingreso (planes activos)</p>
        <h3 class="mb-0 text-primary"><?= money($totals['revenueCents']) ?></h3>
      </div></div>
    </div>
    <div class="col-md-4">
      <div class="card"><div class="card-body">
        <p class="text-muted mb-1 small">Margen total</p>
        <h3 class="mb-0 <?= marginClass($totals['marginCents']) ?>"><?= money($totals['marginCents']) ?></h3>
      </div></div>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><h5 class="mb-0">Desglose por tenant</h5></div>
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-hover align-middle">
          <thead>
            <tr>
              <th>Tenant</th><th>Plan</th>
              <th class="text-end">Voz (min)</th><th class="text-end">Tokens LLM</th>
              <th class="text-end">Costo infra</th><th class="text-end">Ingreso</th>
              <th class="text-end">Margen</th><th class="text-end">%</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($rows)): ?>
              <tr><td colspan="8" class="text-center text-muted py-4">Sin datos.</td></tr>
            <?php else: foreach ($rows as $r):
              $statusBadge = ($r['status'] ?? '') === 'active' ? 'bg-label-success' : (($r['status'] ?? '')==='trial' ? 'bg-label-warning' : 'bg-label-secondary');
            ?>
            <tr>
              <td>
                <span class="fw-medium"><?= e($r['name']) ?></span>
                <span class="badge <?= $statusBadge ?> ms-1"><?= e($r['status']) ?></span>
              </td>
              <td><span class="badge bg-label-primary"><?= e($r['plan']) ?></span></td>
              <td class="text-end"><?= number_format((float)($r['voiceMin'] ?? 0), 1) ?></td>
              <td class="text-end"><?= number_format((int)($r['tokens'] ?? 0)) ?></td>
              <td class="text-end text-danger"><?= money($r['totalCostCents'] ?? 0) ?></td>
              <td class="text-end"><?= money($r['revenueCents'] ?? 0) ?></td>
              <td class="text-end fw-semibold <?= marginClass($r['marginCents'] ?? 0) ?>"><?= money($r['marginCents'] ?? 0) ?></td>
              <td class="text-end <?= marginClass($r['marginCents'] ?? 0) ?>"><?= $r['marginPct'] !== null ? ((int)$r['marginPct'].'%') : '—' ?></td>
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
      <p class="text-muted small mb-0 mt-2">
        <i class="bx bx-info-circle me-1"></i>Costo estimado = minutos de voz × (Twilio + STT + TTS) + tokens × tarifa LLM, según las tarifas configuradas.
        Ingreso = precio del plan (tenants activos) + excedentes facturados.
      </p>
    </div>
  </div>

</div>
<?php renderFooter(); ?>
</div></div></div>
