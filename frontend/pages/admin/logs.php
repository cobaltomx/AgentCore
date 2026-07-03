<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/head.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/navbar.php';
require_once __DIR__ . '/../../includes/footer.php';

requireAuth();
requireRole('superadmin');

$filterAction = trim($_GET['action'] ?? '');
$params = ['limit' => 200];
if ($filterAction !== '') $params['action'] = $filterAction;

$data    = apiGet('/superadmin/logs', $params);
$logs    = is_array($data['logs'] ?? null) ? $data['logs'] : [];
$actions = is_array($data['actions'] ?? null) ? $data['actions'] : [];

// Etiqueta + color por acción
function actionMeta(string $a): array {
    return match($a) {
        'tenant_deleted'           => ['Tenant eliminado', 'danger',  'bx-trash'],
        'tenant_features_updated'  => ['Features actualizadas', 'info', 'bx-toggle-left'],
        'twilio_number_assigned'   => ['Número asignado', 'success', 'bx-link'],
        'twilio_number_released'   => ['Número liberado', 'warning', 'bx-unlink'],
        'twilio_number_added'      => ['Número agregado', 'primary', 'bx-phone'],
        'twilio_number_deleted'    => ['Número eliminado', 'danger', 'bx-phone-off'],
        'settings_updated'         => ['Settings actualizados', 'secondary', 'bx-cog'],
        default                    => [ucfirst(str_replace('_', ' ', $a)), 'secondary', 'bx-info-circle'],
    };
}

renderHead('Logs globales');
?>
<div class="layout-wrapper layout-content-navbar">
<div class="layout-container">
<?php renderSidebar('sa-logs'); ?>
<div class="layout-page">
<?php renderNavbar('Logs globales'); ?>
<div class="content-wrapper">
<div class="container-xxl flex-grow-1 container-p-y">

  <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
      <h4 class="mb-1"><i class="bx bx-history me-1 text-warning"></i>Logs globales</h4>
      <p class="text-muted mb-0">Auditoría de acciones en toda la plataforma (últimos 200).</p>
    </div>
    <form method="get" class="d-flex gap-2">
      <select name="action" class="form-select form-select-sm" style="min-width:220px" onchange="this.form.submit()">
        <option value="">Todas las acciones</option>
        <?php foreach ($actions as $a): [$lbl] = actionMeta($a); ?>
          <option value="<?= e($a) ?>" <?= $filterAction === $a ? 'selected' : '' ?>><?= e($lbl) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if ($filterAction !== ''): ?>
        <a href="/pages/admin/logs.php" class="btn btn-sm btn-outline-secondary">Limpiar</a>
      <?php endif; ?>
    </form>
  </div>

  <div class="card">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr><th>Fecha</th><th>Acción</th><th>Tenant</th><th>Usuario</th><th>Detalle</th></tr>
        </thead>
        <tbody>
        <?php if (empty($logs)): ?>
          <tr><td colspan="5" class="text-center text-muted py-5">
            <i class="bx bx-history d-block mb-2" style="font-size:2.2rem;opacity:.3"></i>
            Sin registros<?= $filterAction !== '' ? ' para este filtro' : '' ?>
          </td></tr>
        <?php else: foreach ($logs as $l):
          [$lbl, $color, $icon] = actionMeta($l['action'] ?? '');
          $meta = $l['metadata'] ?? null;
          $metaStr = is_array($meta) ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (string)$meta;
        ?>
          <tr>
            <td style="white-space:nowrap"><small class="text-muted"><?= !empty($l['created_at']) ? formatDate($l['created_at'], 'd/m/Y H:i:s') : '—' ?></small></td>
            <td><span class="badge bg-label-<?= $color ?>"><i class="bx <?= $icon ?> me-1"></i><?= e($lbl) ?></span></td>
            <td>
              <?php if (!empty($l['tenant_name'])): ?>
                <span class="fw-semibold small"><?= e($l['tenant_name']) ?></span>
                <small class="text-muted">@<?= e($l['tenant_slug']) ?></small>
              <?php else: ?>
                <span class="badge bg-label-secondary">Plataforma</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if (!empty($l['user_name']) || !empty($l['user_email'])): ?>
                <small><?= e($l['user_name'] ?? '') ?></small>
                <div class="text-muted" style="font-size:.72rem"><?= e($l['user_email'] ?? '') ?></div>
              <?php else: ?>
                <small class="text-muted">—</small>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($metaStr && $metaStr !== '{}' && $metaStr !== 'null'): ?>
                <code class="small text-muted" style="font-size:.72rem"><?= e(mb_strimwidth($metaStr, 0, 80, '…')) ?></code>
              <?php else: ?>
                <small class="text-muted">—</small>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>
<?php renderFooter(); ?>
</div></div></div>
