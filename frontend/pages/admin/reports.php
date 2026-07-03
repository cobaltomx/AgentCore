<?php
/**
 * Super Admin — Reportes por scope: Global ▸ Vertical ▸ Negocio + periodo.
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
$days = (int)($_GET['days'] ?? 30);
if (!in_array($days, [7, 30, 90], true)) $days = 30;

$verticals  = array_values(array_filter((array)apiGet('/superadmin/verticals'), 'is_array'));
$tenantsAll = array_values(array_filter((array)apiGet('/superadmin/tenants'), 'is_array'));

$sp = ['days' => $days];
if ($tenantId !== '') $sp['tenantId'] = $tenantId;
elseif ($industry !== '') $sp['industry'] = $industry;
$rep   = apiGet('/superadmin/reports', $sp);
$level = $rep['level'] ?? 'global';

// helper: link de periodo preservando el scope
function periodLink($d, $industry, $tenantId) {
  $q = ['days' => $d];
  if ($tenantId !== '') $q['tenantId'] = $tenantId;
  elseif ($industry !== '') $q['industry'] = $industry;
  return '?' . http_build_query($q);
}

renderHead('Reportes — Super Admin');
?>
<div class="layout-wrapper layout-content-navbar"><div class="layout-container">
<?php renderSidebar('sa-reports'); ?>
<div class="layout-page"><?php renderNavbar('Reportes'); ?>
<div class="content-wrapper"><div class="container-xxl flex-grow-1 container-p-y">

  <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <div>
      <h4 class="mb-1"><i class="bx bx-bar-chart-alt-2 me-1 text-primary"></i>Reportes</h4>
      <p class="text-muted mb-0">Métricas de desempeño por plataforma, vertical o negocio.</p>
    </div>
    <div class="btn-group">
      <?php foreach ([7=>'7 días', 30=>'30 días', 90=>'90 días'] as $d => $lbl): ?>
        <a href="<?= e(periodLink($d, $industry, $tenantId)) ?>"
           class="btn btn-sm <?= $days === $d ? 'btn-primary' : 'btn-outline-primary' ?>"><?= $lbl ?></a>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- ── Selector de scope (preserva el periodo) ─────────────── -->
  <?php renderScopeSelector([
    'industry'=>$industry, 'tenantId'=>$tenantId, 'verticals'=>$verticals,
    'tenants'=>$tenantsAll, 'level'=>$level, 'negocios'=>0, 'extra'=>['days'=>$days],
  ]); ?>

  <!-- ── KPIs principales ────────────────────────────────────── -->
  <div class="row g-3 mb-4">
    <?php
    $convT = (int)($rep['convs_total'] ?? 0);
    $cards = [
      ['l'=>'Conversaciones','v'=>$convT,'sub'=>'🎙️ '.(int)($rep['convs_voice']??0).' voz · 💬 '.(int)($rep['convs_whatsapp']??0).' WhatsApp','i'=>'bx-conversation','c'=>'primary'],
      ['l'=>'Duración promedio','v'=>number_format((float)($rep['avg_duration_mins']??0),1).' min','sub'=>'por conversación','i'=>'bx-time','c'=>'info'],
      ['l'=>'Leads','v'=>(int)($rep['leads_total']??0),'sub'=>'Conversión: '.(int)($rep['conversion_rate']??0).'%','i'=>'bx-user-check','c'=>'success'],
      ['l'=>'Citas','v'=>(int)($rep['appts_total']??0),'sub'=>'Agendamiento: '.(int)($rep['scheduling_rate']??0).'%','i'=>'bx-calendar-check','c'=>'warning'],
    ];
    foreach ($cards as $c): ?>
    <div class="col-sm-6 col-xl-3">
      <div class="card h-100"><div class="card-body py-3">
        <div class="d-flex align-items-center gap-2 mb-1">
          <span class="avatar-initial rounded bg-label-<?= $c['c'] ?> p-2"><i class="bx <?= $c['i'] ?>"></i></span>
          <div class="h4 mb-0"><?= is_int($c['v']) ? number_format($c['v']) : e($c['v']) ?></div>
        </div>
        <div class="fw-semibold small"><?= e($c['l']) ?></div>
        <div class="text-muted" style="font-size:.74rem"><?= e($c['sub']) ?></div>
      </div></div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- ── Desglose ────────────────────────────────────────────── -->
  <div class="row g-4">
    <div class="col-lg-6"><div class="card h-100">
      <div class="card-header"><h6 class="mb-0"><i class="bx bx-user-check me-1"></i>Embudo de leads</h6></div>
      <div class="card-body">
        <?php
        $lt = max(1, (int)($rep['leads_total'] ?? 0));
        $funnel = [
          ['Nuevos','leads_new','secondary'],
          ['Calificados','leads_qualified','info'],
          ['Convertidos','leads_converted','success'],
        ];
        foreach ($funnel as [$lbl,$key,$col]):
          $v = (int)($rep[$key] ?? 0); $pct = round($v/$lt*100);
        ?>
        <div class="mb-3">
          <div class="d-flex justify-content-between mb-1"><small><?= $lbl ?></small><small class="fw-semibold"><?= number_format($v) ?> (<?= $pct ?>%)</small></div>
          <div class="progress" style="height:8px"><div class="progress-bar bg-<?= $col ?>" style="width:<?= $pct ?>%"></div></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div></div>

    <div class="col-lg-6"><div class="card h-100">
      <div class="card-header"><h6 class="mb-0"><i class="bx bx-calendar-check me-1"></i>Citas y consumo</h6></div>
      <div class="card-body">
        <table class="table table-sm mb-3">
          <tbody>
            <tr><td>Citas totales</td><td class="text-end fw-semibold"><?= number_format((int)($rep['appts_total']??0)) ?></td></tr>
            <tr><td>Confirmadas</td><td class="text-end text-success"><?= number_format((int)($rep['appts_confirmed']??0)) ?></td></tr>
            <tr><td>Canceladas</td><td class="text-end text-danger"><?= number_format((int)($rep['appts_cancelled']??0)) ?></td></tr>
          </tbody>
        </table>
        <?php $mu=(int)($rep['minutes_used']??0); $mm=(int)($rep['minutes_max']??0); $mp=$mm>0?min(100,round($mu/$mm*100)):0; ?>
        <div class="d-flex justify-content-between mb-1"><small>Minutos usados (mes)</small><small class="fw-semibold"><?= number_format($mu) ?>/<?= number_format($mm) ?> (<?= $mp ?>%)</small></div>
        <div class="progress" style="height:8px"><div class="progress-bar <?= $mp>=90?'bg-danger':($mp>=70?'bg-warning':'bg-success') ?>" style="width:<?= $mp ?>%"></div></div>
      </div>
    </div></div>
  </div>

</div><?php renderFooter(); ?>
</div></div></div>
