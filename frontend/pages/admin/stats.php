<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/head.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/navbar.php';
require_once __DIR__ . '/../../includes/footer.php';
require_once __DIR__ . '/../../includes/scope-selector.php';

requireAuth();
requireRole('superadmin');

// Scope: Global ▸ Vertical ▸ Negocio
$industry = trim($_GET['industry'] ?? '');
$tenantId = trim($_GET['tenantId'] ?? '');
if ($tenantId !== '' && !isValidUuid($tenantId)) $tenantId = '';
$verticals = array_values(array_filter((array)apiGet('/superadmin/verticals'), 'is_array'));
$tenantsAll = array_values(array_filter((array)apiGet('/superadmin/tenants'), 'is_array'));

$sp = [];
if ($tenantId !== '') $sp['tenantId'] = $tenantId;
elseif ($industry !== '') $sp['industry'] = $industry;
$stats = apiGet('/superadmin/stats', $sp);
$level = $stats['level'] ?? 'global';
// Comparativa por vertical: solo tiene sentido en el nivel global.
$verticalStats = ($level === 'global')
  ? array_values(array_filter((array)apiGet('/superadmin/vertical-stats'), 'is_array'))
  : [];

// KPI cards
$convs30   = (int)($stats['convs_30d']       ?? 0);
$convsPrev = (int)($stats['convs_prev_30d']  ?? 0);
$convsDiff = $convsPrev > 0 ? round(($convs30 - $convsPrev) / $convsPrev * 100, 1) : null;

$cards = [
  ['label'=>'Tenants totales',       'val'=>$stats['total_tenants']    ?? 0,  'icon'=>'bx-buildings',    'color'=>'primary',   'extra'=>null],
  ['label'=>'Tenants activos',       'val'=>$stats['active_tenants']   ?? 0,  'icon'=>'bx-check-circle', 'color'=>'success',   'extra'=>null],
  ['label'=>'Nuevos este mes',       'val'=>$stats['new_tenants_30d']  ?? 0,  'icon'=>'bx-user-plus',    'color'=>'info',      'extra'=>null],
  ['label'=>'Usuarios registrados',  'val'=>$stats['total_users']      ?? 0,  'icon'=>'bx-user',         'color'=>'warning',   'extra'=>null],
  ['label'=>'Agentes activos',       'val'=>$stats['active_agents']    ?? 0,  'icon'=>'bx-bot',          'color'=>'primary',   'extra'=>null],
  ['label'=>'Conversaciones (30d)',  'val'=>$convs30,                         'icon'=>'bx-conversation', 'color'=>'success',   'extra'=>$convsDiff],
];

// Datos para charts — codificados en JSON para JS
$convsByDay     = json_encode($stats['convs_by_day']     ?? []);
$tenantsByMonth = json_encode($stats['tenants_by_month'] ?? []);
$planDist       = json_encode($stats['plan_dist']        ?? []);
$topTenants     = $stats['top_tenants'] ?? [];

// Mapas de etiquetas
$planLabel  = ['starter'=>'Starter','basic'=>'Basic','pro'=>'Pro','enterprise'=>'Enterprise','trial'=>'Trial','free'=>'Free'];
$planColor  = ['starter'=>'bg-label-secondary','basic'=>'bg-label-info','pro'=>'bg-label-primary','enterprise'=>'bg-label-warning','trial'=>'bg-label-warning','free'=>'bg-label-secondary'];
$statusColor= ['active'=>'success','trial'=>'warning','suspended'=>'danger','inactive'=>'secondary'];

renderHead('Admin — Estadísticas');
?>
<style>
.stat-trend { font-size:.75rem; font-weight:600; padding:.15rem .4rem; border-radius:.4rem; margin-left:.4rem; }
.trend-up   { background:#e8f8ef; color:#28c76f; }
.trend-down { background:#fce8e8; color:#ea5455; }
html.dark-style .trend-up   { background:#1a3a2a; }
html.dark-style .trend-down { background:#3a1a1a; }
.chart-card  { height: 100%; }
.chart-wrap  { position: relative; }
.tenant-mini-bar { height: 6px; border-radius: 3px; background: #e7e7e7; overflow: hidden; }
.tenant-mini-bar span { display: block; height: 100%; border-radius: 3px; background: #696cff; }
.plan-dot { width:10px; height:10px; border-radius:50%; display:inline-block; }
</style>

<div class="layout-wrapper layout-content-navbar"><div class="layout-container">
<?php renderSidebar('sa-stats'); ?>
<div class="layout-page"><?php renderNavbar('Admin · Estadísticas globales'); ?>
<div class="content-wrapper"><div class="container-xxl flex-grow-1 container-p-y">

  <!-- Header -->
  <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <h4 class="mb-0">
      <span class="badge bg-warning text-dark me-2"><i class="bx bx-shield-alt-2"></i></span>
      Estadísticas
    </h4>
    <small class="text-muted"><i class="bx bx-time me-1"></i>Actualizado <?= date('d/m/Y H:i') ?></small>
  </div>

  <!-- ── Selector de scope (Global / Vertical / Negocio) ──────── -->
  <?php renderScopeSelector([
    'industry'=>$industry, 'tenantId'=>$tenantId, 'verticals'=>$verticals,
    'tenants'=>$tenantsAll, 'level'=>$level, 'negocios'=>$stats['total_tenants'] ?? 0,
  ]); ?>

  <!-- ── KPI Cards ──────────────────────────────────────────── -->
  <div class="row g-3 mb-4">
    <?php foreach ($cards as $c):
      $val     = is_string($c['val']) ? $c['val'] : number_format((int)$c['val']);
      $trend   = $c['extra'];
    ?>
    <div class="col-sm-6 col-xl-4">
      <div class="card h-100">
        <div class="card-body d-flex align-items-center gap-3 py-4">
          <div class="avatar avatar-lg">
            <span class="avatar-initial rounded-circle bg-label-<?= $c['color'] ?>">
              <i class="bx <?= $c['icon'] ?>" style="font-size:1.4rem"></i>
            </span>
          </div>
          <div>
            <div class="d-flex align-items-center">
              <span class="fw-bold" style="font-size:1.6rem"><?= $val ?></span>
              <?php if ($trend !== null): ?>
                <span class="stat-trend <?= $trend >= 0 ? 'trend-up' : 'trend-down' ?>">
                  <i class="bx bx-trending-<?= $trend >= 0 ? 'up' : 'down' ?>"></i>
                  <?= abs($trend) ?>%
                </span>
              <?php endif; ?>
            </div>
            <div class="text-muted small"><?= e($c['label']) ?></div>
            <?php if ($trend !== null): ?>
              <div class="text-muted" style="font-size:.68rem">vs. mes anterior</div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- ── Comparativa por vertical (solo nivel global) ────────── -->
  <?php if (!empty($verticalStats)): ?>
  <div class="card mb-4">
    <div class="card-header d-flex align-items-center gap-2 py-3">
      <i class="bx bx-collection text-primary"></i>
      <h6 class="mb-0">Comparativa por vertical</h6>
      <small class="text-muted ms-1">cómo va cada giro · clic para filtrar</small>
    </div>
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>Vertical</th><th class="text-center">Negocios</th>
            <th class="text-center">Convs. (30d)</th><th class="text-center">Leads</th>
            <th class="text-center">Citas</th><th class="text-center">Minutos</th><th></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($verticalStats as $v): $ind = $v['industry']; ?>
          <tr style="cursor:pointer" onclick="location.href='?industry=<?= e(urlencode($ind)) ?>'">
            <td class="fw-semibold"><?= e(scopeIndustryLabel($ind)) ?></td>
            <td class="text-center"><?= (int)$v['activos'] ?>/<?= (int)$v['negocios'] ?></td>
            <td class="text-center"><?= number_format((int)$v['convs_30d']) ?></td>
            <td class="text-center"><?= number_format((int)$v['leads']) ?></td>
            <td class="text-center"><?= number_format((int)$v['appointments']) ?></td>
            <td class="text-center"><?= number_format((int)$v['minutes']) ?></td>
            <td class="text-end"><i class="bx bx-chevron-right text-muted"></i></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── Charts Row ─────────────────────────────────────────── -->
  <div class="row g-4 mb-4">

    <!-- Conversaciones últimos 30 días -->
    <div class="col-xl-8">
      <div class="card chart-card">
        <div class="card-header d-flex align-items-center justify-content-between py-3">
          <h6 class="mb-0"><i class="bx bx-bar-chart-alt-2 me-2 text-primary"></i>Conversaciones — últimos 30 días</h6>
          <span class="badge bg-label-primary" id="total-convs-badge"><?= number_format($convs30) ?> total</span>
        </div>
        <div class="card-body">
          <div class="chart-wrap" style="height:220px">
            <canvas id="chartConvsDay"></canvas>
          </div>
        </div>
      </div>
    </div>

    <!-- Distribución de planes -->
    <div class="col-xl-4">
      <div class="card chart-card">
        <div class="card-header py-3">
          <h6 class="mb-0"><i class="bx bx-pie-chart-alt me-2 text-warning"></i>Distribución de planes</h6>
        </div>
        <div class="card-body d-flex flex-column align-items-center">
          <div class="chart-wrap" style="height:180px;width:180px">
            <canvas id="chartPlans"></canvas>
          </div>
          <div id="plan-legend" class="d-flex flex-wrap gap-2 mt-3 justify-content-center"></div>
        </div>
      </div>
    </div>

  </div>

  <!-- Crecimiento de tenants -->
  <div class="row g-4 mb-4">
    <div class="col-12">
      <div class="card">
        <div class="card-header d-flex align-items-center justify-content-between py-3">
          <h6 class="mb-0"><i class="bx bx-line-chart me-2 text-success"></i>Crecimiento de tenants — últimos 6 meses</h6>
        </div>
        <div class="card-body">
          <div class="chart-wrap" style="height:180px">
            <canvas id="chartTenants"></canvas>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- ── Tenant Breakdown Table ─────────────────────────────── -->
  <div class="card">
    <div class="card-header d-flex align-items-center justify-content-between py-3">
      <h6 class="mb-0"><i class="bx bx-buildings me-2 text-info"></i>Top tenants por actividad (30 días)</h6>
      <a href="/pages/admin/tenants.php" class="btn btn-sm btn-outline-secondary">
        Ver todos <i class="bx bx-right-arrow-alt ms-1"></i>
      </a>
    </div>
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>Tenant</th>
            <th>Plan</th>
            <th>Estado</th>
            <th>Usuarios</th>
            <th>Conversaciones (30d)</th>
            <th>Minutos usados</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($topTenants)): ?>
          <tr>
            <td colspan="6" class="text-center text-muted py-4">Sin datos disponibles</td>
          </tr>
          <?php else: foreach ($topTenants as $t):
            $pct    = $t['max_minutes_mo'] > 0 ? min(100, round($t['minutes_used_mo'] / $t['max_minutes_mo'] * 100)) : 0;
            $barCls = $pct >= 90 ? '#ea5455' : ($pct >= 70 ? '#ff9f43' : '#696cff');
            $stCls  = $statusColor[$t['status']] ?? 'secondary';
            $plCls  = $planColor[$t['plan']] ?? 'bg-label-secondary';
            $plLbl  = $planLabel[$t['plan']] ?? ucfirst($t['plan'] ?? '—');
          ?>
          <tr>
            <td>
              <div class="fw-semibold"><?= e($t['name']) ?></div>
            </td>
            <td><span class="badge <?= $plCls ?>"><?= e($plLbl) ?></span></td>
            <td><span class="badge bg-label-<?= $stCls ?>"><?= ucfirst(e($t['status'] ?? '—')) ?></span></td>
            <td class="text-center"><?= $t['user_count'] ?></td>
            <td>
              <span class="fw-semibold"><?= number_format((int)$t['conv_count_30d']) ?></span>
            </td>
            <td style="min-width:140px">
              <div class="d-flex align-items-center gap-2">
                <div class="tenant-mini-bar flex-grow-1">
                  <span style="width:<?= $pct ?>%;background:<?= $barCls ?>"></span>
                </div>
                <small class="text-muted text-nowrap">
                  <?= number_format((int)$t['minutes_used_mo']) ?>/<?= number_format((int)$t['max_minutes_mo']) ?>
                </small>
              </div>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div><?php renderFooter(); ?>

<script>
// ── Helpers de color ──────────────────────────────────────────
function chartTextColor()  { return Chart.defaults.color       || '#697a8d'; }
function chartGridColor()  { return Chart.defaults.borderColor || '#e7e7e7'; }

const CHART_COLORS = ['#696cff','#71dd37','#ff9f43','#03c3ec','#ea5455','#8592a3'];

// ── Datos desde PHP ───────────────────────────────────────────
const convsByDay     = <?= $convsByDay ?>;
const tenantsByMonth = <?= $tenantsByMonth ?>;
const planDist       = <?= $planDist ?>;

// ── Relleno de días vacíos (30 días) ─────────────────────────
function fillDays(data, days = 30) {
  const map = {};
  data.forEach(d => { map[d.day] = d.cnt; });
  const result = { labels: [], values: [] };
  for (let i = days - 1; i >= 0; i--) {
    const d = new Date();
    d.setDate(d.getDate() - i);
    const key = d.toISOString().slice(0, 10);
    const label = d.toLocaleDateString('es-MX', { day: 'numeric', month: 'short' });
    result.labels.push(label);
    result.values.push(map[key] || 0);
  }
  return result;
}

// ── Chart: Conversaciones por día ─────────────────────────────
(function() {
  const ctx = document.getElementById('chartConvsDay');
  if (!ctx) return;
  const { labels, values } = fillDays(convsByDay, 30);
  new Chart(ctx, {
    type: 'bar',
    data: {
      labels,
      datasets: [{
        label: 'Conversaciones',
        data: values,
        backgroundColor: 'rgba(105,108,255,.7)',
        borderColor: '#696cff',
        borderWidth: 0,
        borderRadius: 4,
        borderSkipped: false,
      }]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            label: ctx => ` ${ctx.parsed.y} conversación${ctx.parsed.y !== 1 ? 'es' : ''}`,
          }
        }
      },
      scales: {
        x: {
          grid: { display: false },
          ticks: { color: chartTextColor(), maxTicksLimit: 10 }
        },
        y: {
          beginAtZero: true,
          ticks: { stepSize: 1, precision: 0, color: chartTextColor() },
          grid: { color: chartGridColor() }
        }
      }
    }
  });
})();

// ── Chart: Tenants por mes ────────────────────────────────────
(function() {
  const ctx = document.getElementById('chartTenants');
  if (!ctx) return;
  const labels = tenantsByMonth.map(d => {
    const [y, m] = d.mo.split('-');
    return new Date(+y, +m - 1).toLocaleDateString('es-MX', { month: 'short', year: '2-digit' });
  });
  const values = tenantsByMonth.map(d => d.cnt);

  // Acumular (running total)
  const cumulative = [];
  let sum = 0;
  values.forEach(v => { sum += v; cumulative.push(sum); });

  new Chart(ctx, {
    type: 'line',
    data: {
      labels,
      datasets: [
        {
          label: 'Nuevos este mes',
          data: values,
          type: 'bar',
          backgroundColor: 'rgba(113,221,55,.5)',
          borderColor: '#71dd37',
          borderWidth: 0,
          borderRadius: 4,
          yAxisID: 'y',
        },
        {
          label: 'Total acumulado',
          data: cumulative,
          type: 'line',
          borderColor: '#696cff',
          backgroundColor: 'rgba(105,108,255,.08)',
          fill: true,
          tension: .4,
          pointRadius: 4,
          pointBackgroundColor: '#696cff',
          yAxisID: 'y2',
        }
      ]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: { legend: { labels: { color: chartTextColor(), boxWidth: 12, font: { size: 11 } } } },
      scales: {
        x: { grid: { display: false }, ticks: { color: chartTextColor() } },
        y:  { beginAtZero: true, ticks: { stepSize: 1, precision: 0, color: chartTextColor() }, grid: { color: chartGridColor() }, title: { display: true, text: 'Nuevos', color: chartTextColor(), font: { size: 10 } } },
        y2: { position: 'right', beginAtZero: true, ticks: { stepSize: 1, precision: 0, color: chartTextColor() }, grid: { drawOnChartArea: false }, title: { display: true, text: 'Acumulado', color: chartTextColor(), font: { size: 10 } } },
      }
    }
  });
})();

// ── Chart: Distribución de planes (Doughnut) ──────────────────
(function() {
  const ctx = document.getElementById('chartPlans');
  if (!ctx || !planDist.length) return;
  const labels = planDist.map(d => (d.plan || 'sin plan').charAt(0).toUpperCase() + (d.plan || '').slice(1));
  const values = planDist.map(d => d.cnt);

  new Chart(ctx, {
    type: 'doughnut',
    data: {
      labels,
      datasets: [{
        data: values,
        backgroundColor: CHART_COLORS,
        borderWidth: 2,
        hoverOffset: 6,
      }]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      cutout: '68%',
      plugins: { legend: { display: false } }
    }
  });

  // Leyenda manual
  const legend = document.getElementById('plan-legend');
  if (legend) {
    legend.innerHTML = labels.map((l, i) =>
      `<div class="d-flex align-items-center gap-1" style="font-size:.78rem">
         <span style="width:10px;height:10px;border-radius:50%;background:${CHART_COLORS[i]};display:inline-block"></span>
         <span>${l} (${values[i]})</span>
       </div>`
    ).join('');
  }
})();
</script>
