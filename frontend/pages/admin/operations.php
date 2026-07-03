<?php
/**
 * Super Admin — Operación por scope: Global ▸ Vertical ▸ Negocio.
 * Selector arriba; los datos se agregan según el nivel elegido.
 */
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/head.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/navbar.php';
require_once __DIR__ . '/../../includes/footer.php';
require_once __DIR__ . '/../../includes/scope-selector.php';

requireAuth();
requireRole('superadmin');

$industry = trim($_GET['industry'] ?? '');
$tenantId = trim($_GET['tenantId'] ?? '');
if ($tenantId !== '' && !isValidUuid($tenantId)) $tenantId = '';

$verticals = array_values(array_filter((array)apiGet('/superadmin/verticals'), 'is_array'));
$tenants   = array_values(array_filter((array)apiGet('/superadmin/tenants'), 'is_array'));

$params = [];
if ($tenantId !== '') $params['tenantId'] = $tenantId;
elseif ($industry !== '') $params['industry'] = $industry;
$data = apiGet('/superadmin/scope-overview', $params);

$level = $data['level'] ?? 'global';
$kpis  = $data['kpis'] ?? [];
$convs = is_array($data['recent']['convs'] ?? null) ? $data['recent']['convs'] : [];
$leads = is_array($data['recent']['leads'] ?? null) ? $data['recent']['leads'] : [];
$appts = is_array($data['recent']['appts'] ?? null) ? $data['recent']['appts'] : [];

renderHead('Operación — Super Admin');
?>
<div class="layout-wrapper layout-content-navbar"><div class="layout-container">
<?php renderSidebar('sa-operations'); ?>
<div class="layout-page"><?php renderNavbar('Operación global'); ?>
<div class="content-wrapper"><div class="container-xxl flex-grow-1 container-p-y">

  <div class="mb-3">
    <h4 class="mb-1"><i class="bx bx-pulse me-1 text-primary"></i>Operación</h4>
    <p class="text-muted mb-0">Mira la actividad de toda la plataforma, de una vertical, o de un negocio específico.</p>
  </div>

  <!-- ── Selector de scope (include reutilizable) ──────────── -->
  <?php renderScopeSelector([
    'industry'=>$industry, 'tenantId'=>$tenantId, 'verticals'=>$verticals,
    'tenants'=>$tenants, 'level'=>$level, 'negocios'=>$data['negocios'] ?? 0,
  ]); ?>

  <!-- ── KPIs del scope ────────────────────────────────────── -->
  <div class="row g-3 mb-4">
    <?php
    $cards = [
      ['l'=>'Conversaciones','v'=>$kpis['conversations']??0,'i'=>'bx-conversation','c'=>'primary'],
      ['l'=>'Convs. (30d)','v'=>$kpis['conversations_30d']??0,'i'=>'bx-calendar','c'=>'info'],
      ['l'=>'Leads','v'=>$kpis['leads']??0,'i'=>'bx-user-check','c'=>'success'],
      ['l'=>'Citas','v'=>$kpis['appointments']??0,'i'=>'bx-calendar-check','c'=>'warning'],
      ['l'=>'Agentes activos','v'=>$kpis['active_agents']??0,'i'=>'bx-bot','c'=>'secondary'],
      ['l'=>'Minutos usados','v'=>$kpis['minutes_used']??0,'i'=>'bx-time','c'=>'danger'],
    ];
    foreach ($cards as $c): ?>
    <div class="col-6 col-xl-2">
      <div class="card h-100"><div class="card-body d-flex align-items-center gap-2 py-3">
        <span class="avatar-initial rounded bg-label-<?= $c['c'] ?> p-2"><i class="bx <?= $c['i'] ?>"></i></span>
        <div><div class="h5 mb-0"><?= number_format((int)$c['v']) ?></div><div class="text-muted" style="font-size:.74rem"><?= $c['l'] ?></div></div>
      </div></div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- ── Actividad reciente (con columna Negocio salvo en nivel negocio) ── -->
  <?php $showTenantCol = ($level !== 'negocio'); ?>
  <div class="row g-4">
    <div class="col-lg-6"><div class="card h-100">
      <div class="card-header"><h6 class="mb-0"><i class="bx bx-conversation me-1"></i>Conversaciones recientes</h6></div>
      <div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <thead class="table-light"><tr><?php if($showTenantCol):?><th>Negocio</th><?php endif;?><th>Canal</th><th>Estado</th><th>Fecha</th></tr></thead><tbody>
        <?php if(empty($convs)):?><tr><td colspan="4" class="text-center text-muted py-4">Sin conversaciones</td></tr>
        <?php else: foreach($convs as $c):?><tr>
          <?php if($showTenantCol):?><td><small><?= e($c['tenant_name']??'') ?></small></td><?php endif;?>
          <td><?= channelIcon($c['channel']??'') ?> <small><?= e($c['channel']??'') ?></small></td>
          <td><?= statusBadge($c['status']??'') ?></td>
          <td><small class="text-muted"><?= !empty($c['created_at'])?formatDate($c['created_at'],'d/m H:i'):'—' ?></small></td>
        </tr><?php endforeach; endif;?>
      </tbody></table></div>
    </div></div>
    <div class="col-lg-6"><div class="card h-100">
      <div class="card-header"><h6 class="mb-0"><i class="bx bx-user-check me-1"></i>Leads recientes</h6></div>
      <div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <thead class="table-light"><tr><?php if($showTenantCol):?><th>Negocio</th><?php endif;?><th>Nombre</th><th>Estado</th><th>Fecha</th></tr></thead><tbody>
        <?php if(empty($leads)):?><tr><td colspan="4" class="text-center text-muted py-4">Sin leads</td></tr>
        <?php else: foreach($leads as $l):?><tr>
          <?php if($showTenantCol):?><td><small><?= e($l['tenant_name']??'') ?></small></td><?php endif;?>
          <td><?= e($l['name']??'—') ?></td>
          <td><?= statusBadge($l['status']??'') ?></td>
          <td><small class="text-muted"><?= !empty($l['created_at'])?formatDate($l['created_at'],'d/m H:i'):'—' ?></small></td>
        </tr><?php endforeach; endif;?>
      </tbody></table></div>
    </div></div>
    <div class="col-12"><div class="card">
      <div class="card-header"><h6 class="mb-0"><i class="bx bx-calendar-check me-1"></i>Citas recientes</h6></div>
      <div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <thead class="table-light"><tr><?php if($showTenantCol):?><th>Negocio</th><?php endif;?><th>Paciente/Cliente</th><th>Fecha de cita</th><th>Estado</th></tr></thead><tbody>
        <?php if(empty($appts)):?><tr><td colspan="4" class="text-center text-muted py-4">Sin citas</td></tr>
        <?php else: foreach($appts as $a):?><tr>
          <?php if($showTenantCol):?><td><small><?= e($a['tenant_name']??'') ?></small></td><?php endif;?>
          <td><?= e($a['patient_name']??'—') ?></td>
          <td><small><?= !empty($a['scheduled_at'])?formatDate($a['scheduled_at'],'d/m/Y H:i'):'—' ?></small></td>
          <td><?= statusBadge($a['status']??'') ?></td>
        </tr><?php endforeach; endif;?>
      </tbody></table></div>
    </div></div>
  </div>

</div><?php renderFooter(); ?>
</div></div></div>
