<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/head.php';
require_once __DIR__ . '/../includes/sidebar.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/footer.php';

requireAuth();

$days   = (int)($_GET['days'] ?? 30);
$days   = in_array($days, [7, 14, 30, 60, 90]) ? $days : 30;

$stats    = apiGet('/reports/stats',    ['days' => $days]);
$agents   = apiGet('/reports/agents',   ['days' => $days]);
$leadsR   = apiGet('/reports/leads',    ['days' => $days]);
$timeline = apiGet('/reports/timeline', ['days' => $days]);
$users    = isAdmin() ? apiGet('/reports/users', ['days' => $days]) : [];
$users    = array_values(array_filter((array)$users, 'is_array'));

$agentRows    = array_values(array_filter((array)$agents,   'is_array'));
$leadsByStatus= array_values(array_filter((array)($leadsR['by_status'] ?? []), 'is_array'));
$leadsByDay   = array_values(array_filter((array)($leadsR['by_day']    ?? []), 'is_array'));
$timelineRows = array_values(array_filter((array)$timeline, 'is_array'));

renderHead("Reportes — últimos {$days} días");
?>
<div class="layout-wrapper layout-content-navbar"><div class="layout-container">
<?php renderSidebar('reports'); ?>
<div class="layout-page"><?php renderNavbar('Reportes'); ?>
<div class="content-wrapper"><div class="container-xxl flex-grow-1 container-p-y">

  <!-- Header + filtro de período -->
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
    <div>
      <h4 class="mb-1">Reportes</h4>
      <p class="text-muted mb-0">Últimos <?= $days ?> días · <?= e(currentTenant()['name'] ?? '') ?></p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
      <?php foreach ([7=>'7d', 14=>'14d', 30=>'30d', 60=>'60d', 90=>'90d'] as $d => $label): ?>
        <a href="?days=<?= $d ?>"
           class="btn btn-sm <?= $days === $d ? 'btn-primary' : 'btn-outline-secondary' ?>">
          <?= $label ?>
        </a>
      <?php endforeach; ?>
      <button class="btn btn-sm btn-outline-secondary" id="btn-export-csv" title="Exportar tabla de agentes a CSV">
        <i class="bx bx-download me-1"></i>CSV
      </button>
    </div>
  </div>

  <!-- ── KPI Cards ───────────────────────────────────────────── -->
  <div class="row g-3 mb-4">
    <?php
    $minsUsed = (int)($stats['minutes_used'] ?? 0);
    $minsMax  = max(1, (int)($stats['minutes_max'] ?? 500));
    $minsPct  = min(100, round($minsUsed / $minsMax * 100));
    $minsColor = $minsPct >= 90 ? 'danger' : ($minsPct >= 70 ? 'warning' : 'primary');
    $schedRate = (int)($stats['scheduling_rate'] ?? 0);

    $kpis = [
      ['label'=>'Conversaciones',      'val'=>$stats['convs_total']   ?? 0, 'icon'=>'bx-conversation',   'color'=>'primary',
       'sub'=>'Voz: '.($stats['convs_voice']??0).' · WA: '.($stats['convs_whatsapp']??0)],
      ['label'=>'Leads generados',     'val'=>$stats['leads_total']   ?? 0, 'icon'=>'bx-user-check',     'color'=>'success',
       'sub'=>'Nuevos: '.($stats['leads_new']??0).' · Calificados: '.($stats['leads_qualified']??0)],
      ['label'=>'Tasa de conversión',  'val'=>($stats['conversion_rate']??0).'%','icon'=>'bx-trending-up','color'=>'info',
       'sub'=>'Leads convertidos: '.($stats['leads_converted']??0)],
      ['label'=>'Citas agendadas',     'val'=>$stats['appts_total']   ?? 0, 'icon'=>'bx-calendar-check', 'color'=>'warning',
       'sub'=>'Confirmadas: '.($stats['appts_confirmed']??0).' · Canceladas: '.($stats['appts_cancelled']??0)],
      ['label'=>'Duración promedio',   'val'=>($stats['avg_duration_mins']??0).' min','icon'=>'bx-time', 'color'=>'secondary',
       'sub'=>'Por conversación de voz'],
      ['label'=>'Tasa de agendamiento','val'=>$schedRate.'%',                'icon'=>'bx-calendar-event','color'=>$schedRate >= 20 ? 'success' : 'info',
       'sub'=>'Citas / conversaciones totales'],
    ];
    foreach ($kpis as $k): ?>
    <div class="col-sm-6 col-xl-4">
      <div class="card h-100">
        <div class="card-body d-flex align-items-start gap-3">
          <div class="avatar avatar-md flex-shrink-0">
            <span class="avatar-initial rounded-circle bg-label-<?= $k['color'] ?>">
              <i class="bx <?= $k['icon'] ?>"></i>
            </span>
          </div>
          <div class="min-w-0">
            <div class="fw-bold" style="font-size:1.5rem"><?= e($k['val']) ?></div>
            <div class="fw-semibold text-body"><?= e($k['label']) ?></div>
            <small class="text-muted"><?= e($k['sub']) ?></small>
          </div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>

    <!-- Barra de minutos del plan -->
    <div class="col-12">
      <div class="card">
        <div class="card-body py-3">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <div class="d-flex align-items-center gap-2">
              <i class="bx bx-microphone text-<?= $minsColor ?>"></i>
              <span class="fw-semibold">Consumo de minutos — mes actual</span>
            </div>
            <div class="d-flex align-items-center gap-3">
              <small class="text-muted"><?= $minsUsed ?> / <?= $minsMax ?> min · <strong class="text-<?= $minsColor ?>"><?= $minsPct ?>%</strong></small>
              <?php if ($minsPct >= 90): ?>
                <a href="/pages/billing.php" class="btn btn-sm btn-danger">
                  <i class="bx bx-crown me-1"></i>Ampliar plan
                </a>
              <?php endif; ?>
            </div>
          </div>
          <div class="progress" style="height:8px;border-radius:4px">
            <div class="progress-bar bg-<?= $minsColor ?>" style="width:<?= $minsPct ?>%;border-radius:4px"></div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-4 mb-4">
    <!-- ── Gráfica timeline ──────────────────────────────────── -->
    <div class="col-xl-8">
      <div class="card h-100">
        <div class="card-header d-flex align-items-center justify-content-between">
          <h6 class="mb-0">Conversaciones por día</h6>
          <div class="d-flex gap-2">
            <span class="badge bg-label-primary">Voz</span>
            <span class="badge bg-label-success">WhatsApp</span>
            <span class="badge bg-label-info">Web Chat</span>
          </div>
        </div>
        <div class="card-body">
          <canvas id="chartTimeline" height="200"></canvas>
        </div>
      </div>
    </div>

    <!-- ── Leads por estado ──────────────────────────────────── -->
    <div class="col-xl-4">
      <div class="card h-100">
        <div class="card-header"><h6 class="mb-0">Leads por estado</h6></div>
        <div class="card-body d-flex flex-column align-items-center justify-content-center">
          <?php if (empty($leadsByStatus)): ?>
            <p class="text-muted text-center small">Sin datos en el período</p>
          <?php else: ?>
            <canvas id="chartLeads" height="220"></canvas>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- ── Leads por día ─────────────────────────────────────── -->
  <?php if (!empty($leadsByDay)): ?>
  <div class="card mb-4">
    <div class="card-header d-flex align-items-center justify-content-between">
      <h6 class="mb-0"><i class="bx bx-user-check me-2"></i>Nuevos leads por día</h6>
      <span class="badge bg-label-success"><?= array_sum(array_column($leadsByDay, 'total')) ?> total</span>
    </div>
    <div class="card-body">
      <canvas id="chartLeadsDay" height="100"></canvas>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── Desempeño por agente ───────────────────────────────── -->
  <?php if (!empty($agentRows)): ?>
  <div class="card mb-4">
    <div class="card-header"><h6 class="mb-0"><i class="bx bx-bot me-2"></i>Desempeño por agente</h6></div>
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0" id="agents-table">
        <thead class="table-light">
          <tr>
            <th>Agente</th>
            <th>Canal</th>
            <th class="text-center">Conversaciones</th>
            <th class="text-center">Duración prom.</th>
            <th class="text-center">Leads generados</th>
            <th class="text-center">Citas agendadas</th>
            <th>Estado</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($agentRows as $ag):
            $channelBadge = match($ag['channel'] ?? '') {
              'voice'    => '<span class="badge bg-label-primary">📞 Voz</span>',
              'whatsapp' => '<span class="badge bg-label-success">💬 WhatsApp</span>',
              'webchat'  => '<span class="badge bg-label-info">🌐 Web</span>',
              default    => '<span class="badge bg-label-secondary">'.e($ag['channel'] ?? '').'</span>',
            };
          ?>
          <tr>
            <td class="fw-semibold"><?= e($ag['name'] ?? '') ?></td>
            <td><?= $channelBadge ?></td>
            <td class="text-center fw-bold"><?= (int)($ag['conv_count'] ?? 0) ?></td>
            <td class="text-center"><?= number_format((float)($ag['avg_mins'] ?? 0), 1) ?> min</td>
            <td class="text-center"><?= (int)($ag['leads_generated'] ?? 0) ?></td>
            <td class="text-center"><?= (int)($ag['appts_scheduled'] ?? 0) ?></td>
            <td>
              <?= ($ag['is_active'] ?? false)
                ? '<span class="badge bg-label-success">Activo</span>'
                : '<span class="badge bg-label-secondary">Inactivo</span>' ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── Actividad por usuario (solo admin) ─────────────────── -->
  <?php if (isAdmin() && !empty($users)): ?>
  <div class="card mb-4">
    <div class="card-header"><h6 class="mb-0"><i class="bx bx-group me-2"></i>Actividad por usuario</h6></div>
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>Usuario</th>
            <th>Rol</th>
            <th class="text-center">Leads asignados</th>
            <th class="text-center">Convs. asignadas</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($users as $u):
            $roleBadge = match($u['role'] ?? '') {
              'admin'      => '<span class="badge bg-label-primary">Admin</span>',
              'superadmin' => '<span class="badge bg-warning text-dark">Superadmin</span>',
              default      => '<span class="badge bg-label-secondary">Usuario</span>',
            };
          ?>
          <tr>
            <td>
              <div class="fw-semibold"><?= e($u['name'] ?? '') ?></div>
              <small class="text-muted"><?= e($u['email'] ?? '') ?></small>
            </td>
            <td><?= $roleBadge ?></td>
            <td class="text-center"><?= (int)($u['leads_assigned'] ?? 0) ?></td>
            <td class="text-center"><?= (int)($u['convs_assigned'] ?? 0) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

</div><?php renderFooter(); ?>

<script>
// ── Datos del servidor ────────────────────────────────────────
const timelineData  = <?= json_encode($timelineRows) ?>;
const leadsByStatus = <?= json_encode($leadsByStatus) ?>;
const leadsByDay    = <?= json_encode($leadsByDay) ?>;

// ── Colores ───────────────────────────────────────────────────
const COLORS = {
  primary:   '#696cff',
  success:   '#71dd37',
  info:      '#03c3ec',
  warning:   '#ffab00',
  danger:    '#ff3e1d',
  secondary: '#8592a3',
};

// ── Helpers de tema para Chart.js ────────────────────────────
// theme.js ya habrá seteado Chart.defaults.color y .borderColor.
// Leemos el valor actual para aplicarlo a las opciones de cada chart.
function chartTextColor()  { return Chart.defaults.color       || '#697a8d'; }
function chartGridColor()  { return Chart.defaults.borderColor || '#e7e7e7'; }
function doughnutBorder()  {
  return document.documentElement.classList.contains('dark-style')
    ? '#232633' : '#ffffff';
}

window.AC = window.AC || {};
window.AC.charts = window.AC.charts || {};

// ── Chart: Timeline de conversaciones ─────────────────────────
(function() {
  const ctx = document.getElementById('chartTimeline');
  if (!ctx || !timelineData.length) {
    ctx?.parentElement?.insertAdjacentHTML('beforeend',
      '<p class="text-muted text-center small mt-3">Sin conversaciones en el período</p>');
    return;
  }

  const labels = timelineData.map(d => {
    const dt = new Date(d.day + 'T12:00:00');
    return dt.toLocaleDateString('es-MX', { month: 'short', day: 'numeric' });
  });

  window.AC.charts.timeline = new Chart(ctx, {
    type: 'bar',
    data: {
      labels,
      datasets: [
        {
          label: 'Voz',
          data: timelineData.map(d => d.voice || 0),
          backgroundColor: COLORS.primary + 'cc',
          borderRadius: 4,
        },
        {
          label: 'WhatsApp',
          data: timelineData.map(d => d.whatsapp || 0),
          backgroundColor: COLORS.success + 'cc',
          borderRadius: 4,
        },
        {
          label: 'Web Chat',
          data: timelineData.map(d => d.webchat || 0),
          backgroundColor: COLORS.info + 'cc',
          borderRadius: 4,
        },
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: true,
      plugins: { legend: { display: false } },
      scales: {
        x: {
          stacked: true,
          grid: { display: false },
          ticks: { color: chartTextColor() },
        },
        y: {
          stacked: true,
          beginAtZero: true,
          ticks: { stepSize: 1, color: chartTextColor() },
          grid: { color: chartGridColor() },
        }
      }
    }
  });
})();

// ── Chart: Leads por día (línea) ─────────────────────────────
(function() {
  const ctx = document.getElementById('chartLeadsDay');
  if (!ctx || !leadsByDay.length) return;

  const labels = leadsByDay.map(d => {
    const dt = new Date(d.day + 'T12:00:00');
    return dt.toLocaleDateString('es-MX', { month: 'short', day: 'numeric' });
  });
  const totals = leadsByDay.map(d => d.total || 0);

  new Chart(ctx, {
    type: 'line',
    data: {
      labels,
      datasets: [{
        label: 'Leads nuevos',
        data: totals,
        borderColor: COLORS.success,
        backgroundColor: COLORS.success + '22',
        borderWidth: 2,
        pointBackgroundColor: COLORS.success,
        pointRadius: 3,
        fill: true,
        tension: 0.4,
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: true,
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            label: ctx => `${ctx.parsed.y} lead${ctx.parsed.y !== 1 ? 's' : ''}`
          }
        }
      },
      scales: {
        x: { grid: { display: false }, ticks: { color: chartTextColor(), maxTicksLimit: 15 } },
        y: { beginAtZero: true, ticks: { stepSize: 1, precision: 0, color: chartTextColor() }, grid: { color: chartGridColor() } }
      }
    }
  });
})();

// ── Chart: Leads por estado (doughnut) ───────────────────────
(function() {
  const ctx = document.getElementById('chartLeads');
  if (!ctx || !leadsByStatus.length) return;

  const labelMap = {
    new:       'Nuevos',
    contacted: 'Contactados',
    qualified: 'Calificados',
    converted: 'Convertidos',
    lost:      'Perdidos',
  };
  const colorMap = {
    new:       COLORS.secondary,
    contacted: COLORS.info,
    qualified: COLORS.primary,
    converted: COLORS.success,
    lost:      COLORS.danger,
  };

  window.AC.charts.leads = new Chart(ctx, {
    type: 'doughnut',
    data: {
      labels: leadsByStatus.map(d => labelMap[d.status] || d.status),
      datasets: [{
        data:            leadsByStatus.map(d => d.total),
        backgroundColor: leadsByStatus.map(d => colorMap[d.status] || COLORS.secondary),
        borderWidth: 2,
        borderColor: doughnutBorder(),
      }]
    },
    options: {
      cutout: '65%',
      plugins: {
        legend: {
          position: 'bottom',
          labels: { font: { size: 11 }, color: chartTextColor() }
        }
      }
    }
  });
})();

// ── Exportar tabla de agentes a CSV ──────────────────────────
document.getElementById('btn-export-csv')?.addEventListener('click', function() {
  const table = document.getElementById('agents-table');
  if (!table) { window.showToast?.('Sin datos de agentes para exportar', 'warning'); return; }

  const rows = [];
  const headers = [...table.querySelectorAll('thead th')].map(th => th.textContent.trim());
  rows.push(headers.join(','));

  table.querySelectorAll('tbody tr').forEach(tr => {
    const cols = [...tr.querySelectorAll('td')].map(td => {
      const text = td.textContent.trim().replace(/\s+/g, ' ');
      return text.includes(',') ? `"${text}"` : text;
    });
    if (cols.length) rows.push(cols.join(','));
  });

  const csvContent = '﻿' + rows.join('\n'); // BOM para UTF-8 en Excel
  const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
  const url  = URL.createObjectURL(blob);
  const a    = document.createElement('a');
  a.href     = url;
  const today = new Date().toISOString().slice(0, 10);
  a.download = `reporte-agentes-<?= $days ?>d-${today}.csv`;
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  URL.revokeObjectURL(url);
  window.showToast?.('CSV descargado', 'success');
});
</script>
