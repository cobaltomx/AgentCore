<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/head.php';
require_once __DIR__ . '/../includes/sidebar.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/footer.php';

requireAuth();

$tenant   = currentTenant();
$current  = $tenant['plan'] ?? 'starter';
$status   = apiGet('/billing/status');
$plans    = array_values(array_filter((array)apiGet('/billing/plans'), 'is_array'));
$invoices = array_values(array_filter((array)apiGet('/billing/invoices'), 'is_array'));
$usage    = apiGet('/billing/usage');

$sub      = $status['subscription']  ?? null;
$currUsage= $status['currentUsage']  ?? null;

// Notificaciones de checkout
$checkoutMsg = '';
if (($_GET['checkout'] ?? '') === 'success') {
    $checkoutMsg = 'success';
} elseif (($_GET['checkout'] ?? '') === 'cancel') {
    $checkoutMsg = 'cancel';
}

renderHead('Billing');
?>
<div class="layout-wrapper layout-content-navbar">
<div class="layout-container">
<?php renderSidebar('billing'); ?>
<div class="layout-page">
<?php renderNavbar('Billing'); ?>
<div class="content-wrapper">
<div class="container-xxl flex-grow-1 container-p-y">

  <h4 class="mb-1">Billing & Suscripción</h4>
  <p class="text-muted mb-4">Gestiona tu plan, método de pago y facturas</p>

  <?php if ($checkoutMsg === 'success'): ?>
    <div class="alert alert-success alert-dismissible fade show mb-4">
      <i class="bx bx-check-circle me-2"></i>
      <strong>¡Pago exitoso!</strong> Tu plan ha sido activado. Los cambios se verán reflejados en unos momentos.
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php elseif ($checkoutMsg === 'cancel'): ?>
    <div class="alert alert-warning alert-dismissible fade show mb-4">
      <i class="bx bx-info-circle me-2"></i>
      Pago cancelado. Tu plan actual sigue activo.
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <!-- Estado actual de la suscripción -->
  <div class="row g-4 mb-4">

    <div class="col-md-8">
      <div class="card h-100">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h5 class="mb-0">Suscripción actual</h5>
          <?php if ($sub): ?>
            <button class="btn btn-sm btn-outline-secondary" id="portalBtn">
              <i class="bx bx-cog me-1"></i>Gestionar en Stripe
            </button>
          <?php endif; ?>
        </div>
        <div class="card-body">
          <?php if ($sub): ?>
            <div class="row g-3">
              <div class="col-6 col-md-3 text-center">
                <div class="h4 mb-0"><?= ucfirst(e($sub['plan'])) ?></div>
                <small class="text-muted">Plan</small>
              </div>
              <div class="col-6 col-md-3 text-center">
                <?php
                $subStatusColors = [
                  'active'   => 'success', 'trialing' => 'info',
                  'past_due' => 'warning', 'canceled' => 'danger',
                ];
                $sc = $subStatusColors[$sub['status']] ?? 'secondary';
                ?>
                <div class="h5 mb-0">
                  <span class="badge bg-label-<?= $sc ?> fs-6"><?= ucfirst(e($sub['status'])) ?></span>
                </div>
                <small class="text-muted">Estado</small>
              </div>
              <div class="col-6 col-md-3 text-center">
                <div class="h5 mb-0"><?= $sub['current_period_end'] ? formatDate($sub['current_period_end'], 'd/m/Y') : '—' ?></div>
                <small class="text-muted">Próxima factura</small>
              </div>
              <div class="col-6 col-md-3 text-center">
                <?php if ($sub['trial_end'] && strtotime($sub['trial_end']) > time()): ?>
                  <div class="h5 mb-0 text-info"><?= formatDate($sub['trial_end'], 'd/m/Y') ?></div>
                  <small class="text-muted">Fin de prueba</small>
                <?php else: ?>
                  <div class="h5 mb-0 text-success"><i class="bx bx-check-circle"></i></div>
                  <small class="text-muted">Activo</small>
                <?php endif; ?>
              </div>
            </div>

          <?php else: ?>
            <div class="text-center py-3">
              <i class="bx bx-credit-card bx-lg opacity-25 d-block mb-2"></i>
              <p class="text-muted mb-3">Sin suscripción activa. Elige un plan para comenzar.</p>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Uso del mes -->
    <div class="col-md-4">
      <div class="card h-100">
        <div class="card-header"><h5 class="mb-0">Uso este mes</h5></div>
        <div class="card-body">
          <?php
          $used    = (int)($tenant['minutes_used_mo'] ?? 0);
          $max     = (int)($tenant['max_minutes_mo']  ?? 500);
          $pct     = $max > 0 ? round($used / $max * 100) : 0;
          $barColor = $pct >= 100 ? 'bg-danger' : ($pct >= 80 ? 'bg-warning' : 'bg-success');
          $overage  = max(0, $used - $max);
          ?>
          <div class="d-flex justify-content-between mb-1">
            <span class="small">Minutos de llamada</span>
            <span class="small fw-bold"><?= $used ?>/<?= $max ?></span>
          </div>
          <div class="progress mb-2" style="height:8px">
            <div class="progress-bar <?= $barColor ?>" style="width:<?= min(100,$pct) ?>%"></div>
          </div>
          <?php if ($overage > 0): ?>
            <div class="alert alert-warning py-2 small mb-0">
              <i class="bx bx-error me-1"></i>
              <strong><?= $overage ?> min excedente</strong> — se cobrarán en tu próxima factura
            </div>
          <?php elseif ($pct >= 80): ?>
            <p class="text-warning small mb-0">
              <i class="bx bx-info-circle me-1"></i>Estás al <?= $pct ?>% de tu límite
            </p>
          <?php else: ?>
            <p class="text-muted small mb-0"><?= $pct ?>% utilizado</p>
          <?php endif; ?>
        </div>
      </div>
    </div>

  </div>

  <!-- Planes -->
  <h5 class="mb-3">Planes disponibles</h5>
  <div class="row g-4 mb-4">
    <?php foreach ((array)$plans as $plan): ?>
    <?php
    $isCurrent = ($plan['key'] === $current);
    $configured = $plan['configured'] ?? false;
    ?>
    <div class="col-md-4">
      <div class="card h-100 <?= $isCurrent ? 'border-primary border-2' : '' ?>">
        <?php if ($isCurrent): ?>
          <div class="card-header bg-primary text-white text-center py-2">
            <small class="fw-bold">✓ PLAN ACTUAL</small>
          </div>
        <?php endif; ?>
        <div class="card-body d-flex flex-column">
          <h5 class="mb-1"><?= e($plan['name']) ?></h5>
          <div class="my-3">
            <span class="h2 fw-bold">$<?= number_format($plan['monthlyMXN'] / 100) ?></span>
            <span class="text-muted">/mes MXN</span>
          </div>
          <ul class="list-unstyled mb-4 flex-grow-1">
            <li class="mb-2"><i class="bx bx-check text-success me-2"></i><?= $plan['maxAgents'] ?> agente(s) IA</li>
            <li class="mb-2"><i class="bx bx-check text-success me-2"></i><?= number_format($plan['includedMinutes']) ?> min/mes incluidos</li>
            <li class="mb-2"><i class="bx bx-check text-success me-2"></i>+$<?= $plan['overagePerMin'] ?>¢/min excedente</li>
            <li class="mb-2"><i class="bx bx-check text-success me-2"></i>WhatsApp + Voz + Dashboard</li>
            <li class="mb-2"><i class="bx bx-check text-success me-2"></i>Knowledge Base RAG</li>
          </ul>

          <?php if ($isCurrent): ?>
            <button class="btn btn-outline-secondary w-100" disabled>Plan actual</button>
          <?php elseif (!$configured): ?>
            <button class="btn btn-outline-secondary w-100" disabled>
              <i class="bx bx-lock me-1"></i>Configura Stripe primero
            </button>
          <?php else: ?>
            <button class="btn btn-primary w-100 checkout-btn"
                    data-plan="<?= e($plan['key']) ?>">
              <?= $current === 'starter' && $plan['key'] !== 'starter' ? 'Actualizar a ' : 'Cambiar a ' ?>
              <?= e($plan['name']) ?>
            </button>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Facturas -->
  <?php if (!empty($invoices) && !isset($invoices['error'])): ?>
  <div class="card mb-4">
    <div class="card-header"><h5 class="mb-0">Historial de facturas</h5></div>
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead class="table-light">
          <tr><th>Período</th><th>Monto</th><th>Estado</th><th>Fecha</th><th></th></tr>
        </thead>
        <tbody>
          <?php foreach ((array)$invoices as $inv): ?>
          <tr>
            <td><small><?= formatDate($inv['period_start'] ?? '', 'd/m/Y') ?> – <?= formatDate($inv['period_end'] ?? '', 'd/m/Y') ?></small></td>
            <td><strong>$<?= number_format(($inv['amount_cents'] ?? 0) / 100, 2) ?> <?= strtoupper(e($inv['currency'] ?? 'mxn')) ?></strong></td>
            <td><?= statusBadge($inv['status'] ?? 'draft') ?></td>
            <td><small class="text-muted"><?= formatDate($inv['created_at'] ?? '', 'd/m/Y') ?></small></td>
            <td>
              <?php if ($inv['pdf_url'] ?? ''): ?>
                <a href="<?= e($inv['pdf_url']) ?>" target="_blank" class="btn btn-sm btn-outline-secondary">
                  <i class="bx bx-download me-1"></i>PDF
                </a>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <!-- Info de configuración si no hay Stripe -->
  <?php if (!($plans[0]['configured'] ?? false)): ?>
  <div class="alert alert-info">
    <h6><i class="bx bx-info-circle me-2"></i>Configuración de Stripe requerida</h6>
    <p class="mb-2 small">Para activar el sistema de pagos, sigue estos pasos:</p>
    <ol class="small mb-0">
      <li>Crea una cuenta en <a href="https://stripe.com" target="_blank">stripe.com</a></li>
      <li>Crea los productos/precios para cada plan en el dashboard de Stripe</li>
      <li>Agrega las variables al <code>.env</code>: <code>STRIPE_SECRET_KEY</code>, <code>STRIPE_PRICE_STARTER</code>, <code>STRIPE_PRICE_GROWTH</code>, <code>STRIPE_PRICE_BUSINESS</code>, <code>STRIPE_PRICE_METER</code></li>
      <li>Configura el webhook en Stripe → <code>/webhooks/stripe</code></li>
      <li>Agrega <code>STRIPE_WEBHOOK_SECRET</code> al <code>.env</code></li>
    </ol>
  </div>
  <?php endif; ?>

</div>
<?php renderFooter(); ?>

<script>
// Checkout
document.querySelectorAll('.checkout-btn').forEach(btn => {
  btn.addEventListener('click', async function() {
    const plan = this.dataset.plan;
    this.disabled = true;
    this.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Redirigiendo...';

    const res  = await fetch('/api/billing-checkout.php', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify({ plan }),
    });

    const data = await res.json();
    if (data.url) {
      window.location.href = data.url;
    } else {
      window.showToast?.('Error: ' + (data.error || 'No se pudo iniciar el pago'), 'danger');
      this.disabled = false;
      this.innerHTML = 'Actualizar plan';
    }
  });
});

// Portal de Stripe
document.getElementById('portalBtn')?.addEventListener('click', async function() {
  this.disabled = true;
  this.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>';

  const res  = await fetch('/api/billing-portal.php', { method: 'POST' });
  const data = await res.json();

  if (data.url) {
    window.location.href = data.url;
  } else {
    window.showToast?.('Error: ' + (data.error || 'No se pudo abrir el portal'), 'danger');
    this.disabled = false;
    this.innerHTML = '<i class="bx bx-cog me-1"></i>Gestionar en Stripe';
  }
});
</script>
