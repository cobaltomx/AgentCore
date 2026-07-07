<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/head.php';
require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/navbar.php';
require_once __DIR__ . '/includes/footer.php';
require_once __DIR__ . '/includes/dashboard-vertical.php';

requireAuth();

// Redirigir al onboarding si admin no lo ha completado
if (isAdmin() && empty(tenantSettings()['onboarding_completed'])) {
    // Solo redirigir si tampoco hay agentes (tenant recién creado)
    $agentsCheck = apiGet('/agents', ['limit' => 1]);
    if (empty($agentsCheck['data']) && empty($agentsCheck[0])) {
        header('Location: /onboarding.php');
        exit;
    }
}

// Cargar datos del dashboard en paralelo (simulado con múltiples apiGet)
$tenant       = currentTenant();

// Estado del setup — datos frescos del API (la sesión solo se actualiza al login)
$setupSteps = $tenant['setup_steps'] ?? [];
$isReady    = (bool)($tenant['is_ready'] ?? false);
$valueReport = null;
if (isAdmin()) {
    $simStatus = apiGet('/simulator/status');
    if (empty($simStatus['error'])) {
        $setupSteps = $simStatus['setup_steps'] ?? $setupSteps;
        $isReady    = (bool)($simStatus['is_ready'] ?? $isReady);
    }
    // Teaser del Reporte de Valor (solo si ya está activo el bot)
    if ($isReady) {
        $vr = apiGet('/reports/value', ['days' => 30]);
        if (empty($vr['error'])) $valueReport = $vr;
    }
}
$tenantInfo   = apiGet('/tenants');
$leadStats    = apiGet('/leads');
$apptStats    = apiGet('/appointments/stats/summary');
$convRecent   = apiGet('/conversations', ['limit' => 5]);
$leadsRecent  = apiGet('/leads',         ['limit' => 5]);
$dash         = apiGet('/dashboard/overview');   // cockpit consolidado

// Calcular KPIs desde los datos disponibles
$totalLeads   = count($leadStats['data'] ?? $leadStats ?? []);
$upcoming     = (int)($apptStats['upcoming']   ?? 0);
$completed    = (int)($apptStats['completed']  ?? 0);
$thisMonth    = (int)($apptStats['this_month'] ?? 0);
$minutesUsed  = (int)($tenantInfo['minutes_used_mo'] ?? $tenant['minutes_used_mo'] ?? 0);
$minutesMax   = (int)($tenantInfo['max_minutes_mo']  ?? $tenant['max_minutes_mo']  ?? 500);
$minutesPct   = $minutesMax > 0 ? round($minutesUsed / $minutesMax * 100) : 0;

renderHead('Dashboard');
?>

<div class="layout-wrapper layout-content-navbar">
<div class="layout-container">

<?php renderSidebar('dashboard'); ?>

<div class="layout-page">
<?php renderNavbar('Dashboard'); ?>

<div class="content-wrapper">
<div class="container-xxl flex-grow-1 container-p-y">

  <!-- ── Barra de estado ─────────────────────────────────────── -->
  <?php
    $dMin     = $dash['minutes'] ?? ['used'=>$minutesUsed,'max'=>$minutesMax,'pct'=>$minutesPct];
    $botReady = $dash['is_ready'] ?? $isReady;
    try { $genAt = (new DateTime($dash['generated_at'] ?? 'now'))->setTimezone(new DateTimeZone('America/Mexico_City'))->format('H:i'); }
    catch (\Throwable $e) { $genAt = date('H:i'); }
    $firstName = explode(' ', currentUser()['name'] ?? 'Admin')[0];
  ?>
  <div class="card mb-4"><div class="card-body py-2 d-flex align-items-center justify-content-between flex-wrap gap-2">
    <div class="d-flex align-items-center gap-2 flex-wrap">
      <span class="fw-semibold">Hola, <?= e($firstName) ?> 👋</span>
      <span class="text-muted">·</span>
      <span class="text-muted small"><?= e($tenant['name'] ?? '') ?> · Plan <?= ucfirst(e($tenant['plan'] ?? '')) ?></span>
      <?php if ($botReady): ?>
        <span class="badge bg-label-success"><i class="bx bx-check-circle me-1"></i>Bot activo</span>
      <?php else: ?>
        <a href="/pages/simulator.php" class="badge bg-label-warning text-decoration-none"><i class="bx bx-error-circle me-1"></i>Bot no aprobado</a>
      <?php endif; ?>
    </div>
    <div class="d-flex align-items-center gap-3 small text-muted">
      <span><i class="bx bx-time me-1"></i><?= (int)$dMin['used'] ?>/<?= (int)$dMin['max'] ?> min (<?= (int)$dMin['pct'] ?>%)</span>
      <span style="opacity:.7">Actualizado <?= e($genAt) ?></span>
    </div>
  </div></div>

  <?php $att = $dash['attention'] ?? ['total'=>0,'handoffs'=>[],'unconfirmed'=>[],'new_leads'=>[],'alerts'=>[]];
        $tz = new DateTimeZone('America/Mexico_City');
        // Rango EXACTO de las citas sin confirmar (misma base de fecha que la lista
        // de citas: la fecha UTC de scheduled_at) → evita desfase de zona horaria.
        $ucDates = array_filter(array_map(fn($a) => substr($a['scheduled_at'] ?? '', 0, 10), $att['unconfirmed'] ?? []));
        $fromD = $ucDates ? min($ucDates) : (new DateTime('now', $tz))->format('Y-m-d');
        $toD   = $ucDates ? max($ucDates) : (new DateTime('now', $tz))->modify('+1 day')->format('Y-m-d');
        $linkUnconfirmed = '/pages/appointments.php?from='.$fromD.'&to='.$toD;
        $linkNewLeads    = '/pages/leads.php?status=new'; ?>
  <?php if ((int)($att['total'] ?? 0) > 0): ?>
  <!-- ── ⭐ Necesita tu atención ─────────────────────────────────── -->
  <div class="card border-warning mb-4">
    <div class="card-header d-flex align-items-center gap-2 py-2">
      <i class="bx bx-bell bx-tada text-warning"></i>
      <h6 class="mb-0">Necesita tu atención</h6>
      <span class="badge bg-danger rounded-pill ms-1" id="attention-badge"><?= (int)$att['total'] ?></span>
    </div>
    <div class="list-group list-group-flush">
      <?php if (!empty($att['handoffs'])): ?>
      <?php foreach ($att['handoffs'] as $ho):
        $hn = $ho['contact_name'] ?: ($ho['contact_phone'] ?: 'Cliente');
        // handoff_reason suele venir "Tel: … Motivo: …" → quedarnos con el motivo.
        $hr = (string)($ho['handoff_reason'] ?? '');
        if (preg_match('/Motivo:\s*(.+)$/iu', $hr, $m)) $hr = trim($m[1]);
        $hch = $ho['channel'] ?? '';
        try { $hwhen = $ho['handoff_at'] ? (new DateTime($ho['handoff_at']))->setTimezone($tz)->format('d/m H:i') : ''; } catch (\Throwable $e) { $hwhen=''; }
      ?>
      <a href="/pages/conversation-detail.php?id=<?= e($ho['id']) ?>" class="list-group-item list-group-item-action d-flex align-items-center gap-3 flex-wrap text-body text-decoration-none">
        <i class="bx bx-user-voice text-danger" style="font-size:1.4rem"></i>
        <div class="flex-grow-1" style="min-width:200px">
          <div><strong><?= e($hn) ?></strong> <span class="text-muted">pidió hablar con una persona</span><?php if ($hwhen): ?> <small class="text-muted">· <?= e($hwhen) ?></small><?php endif; ?></div>
          <?php if ($hr !== ''): ?><small class="text-muted text-truncate d-block" style="max-width:520px"><?= e($hr) ?></small><?php endif; ?>
        </div>
        <span class="btn btn-sm btn-danger">Atender <i class="bx bx-chevron-right"></i></span>
      </a>
      <?php endforeach; ?>
      <?php endif; ?>
      <?php if (!empty($att['unconfirmed'])):
        // Sólo se puede recordar por WhatsApp a quien tenga teléfono.
        $remindIds = array_values(array_filter(array_map(
          fn($a) => !empty($a['patient_phone']) ? ($a['id'] ?? null) : null, $att['unconfirmed']))); ?>
      <div class="list-group-item d-flex align-items-center gap-3 flex-wrap">
        <i class="bx bx-calendar-exclamation text-warning" style="font-size:1.4rem"></i>
        <a href="<?= e($linkUnconfirmed) ?>" class="flex-grow-1 text-body text-decoration-none" style="min-width:200px">
          <div><strong><?= count($att['unconfirmed']) ?></strong> cita(s) de hoy/mañana <span class="text-muted">sin confirmar</span></div>
          <small class="text-muted"><?php
            echo e(implode(' · ', array_map(function($a) use ($tz){
              $n = $a['patient_name'] ?: 'Cliente';
              try { $h = (new DateTime($a['scheduled_at']))->setTimezone($tz)->format('d/m H:i'); } catch (\Throwable $e) { $h=''; }
              return trim($n.' '.$h);
            }, array_slice($att['unconfirmed'],0,3))));
          ?></small>
        </a>
        <?php if ($remindIds): ?>
        <button type="button" class="btn btn-sm btn-success" id="btn-remind-all"
                data-ids='<?= e(json_encode($remindIds)) ?>'>
          <i class="bx bxl-whatsapp me-1"></i>Recordar (<?= count($remindIds) ?>)
        </button>
        <?php endif; ?>
        <a href="<?= e($linkUnconfirmed) ?>" class="btn btn-sm btn-outline-warning">Confirmar <i class="bx bx-chevron-right"></i></a>
      </div>
      <?php endif; ?>
      <?php if (!empty($att['new_leads'])): ?>
      <a href="<?= e($linkNewLeads) ?>" class="list-group-item list-group-item-action d-flex align-items-center gap-3 flex-wrap text-body text-decoration-none">
        <i class="bx bx-user-plus text-info" style="font-size:1.4rem"></i>
        <div class="flex-grow-1" style="min-width:200px">
          <div><strong><?= count($att['new_leads']) ?></strong> lead(s) nuevos <span class="text-muted">sin atender</span></div>
          <small class="text-muted"><?php
            echo e(implode(' · ', array_map(fn($l) => $l['name'] ?: ($l['phone'] ?: 'Lead'), array_slice($att['new_leads'],0,3))));
          ?></small>
        </div>
        <span class="btn btn-sm btn-outline-info">Ver leads <i class="bx bx-chevron-right"></i></span>
      </a>
      <?php endif; ?>
      <?php foreach (($att['alerts'] ?? []) as $al): $lvl = (($al['level']??'warning')==='danger')?'danger':'warning'; $alink = $al['link'] ?? '/pages/settings.php'; ?>
      <a href="<?= e($alink) ?>" class="list-group-item list-group-item-action d-flex align-items-center gap-3 flex-wrap text-body text-decoration-none">
        <i class="bx bx-error-circle text-<?= $lvl ?>" style="font-size:1.4rem"></i>
        <div class="flex-grow-1"><?= e($al['message'] ?? '') ?></div>
        <span class="btn btn-sm btn-outline-<?= $lvl ?>">Resolver <i class="bx bx-chevron-right"></i></span>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── Upsell proactivo: cerca del límite de minutos ───────────── -->
  <?php
    $minsPctHome = $minutesMax > 0 ? min(100, round($minutesUsed / $minutesMax * 100)) : 0;
    if (isAdmin() && $minsPctHome >= 75):
      $upLevel = $minsPctHome >= 90 ? 'danger' : 'warning';
  ?>
  <div class="card border-<?= $upLevel ?> mb-4">
    <div class="card-body d-flex align-items-center gap-3 flex-wrap py-3">
      <i class="bx bx-crown text-<?= $upLevel ?>" style="font-size:1.6rem"></i>
      <div class="flex-grow-1" style="min-width:220px">
        <div class="fw-semibold">
          <?php if ($minsPctHome >= 90): ?>
            Estás por agotar tus minutos de este mes (<?= $minsPctHome ?>%)
          <?php else: ?>
            Llevas <?= $minsPctHome ?>% de tus minutos del mes
          <?php endif; ?>
        </div>
        <small class="text-muted"><?= number_format($minutesUsed) ?> de <?= number_format($minutesMax) ?> min usados. Amplía tu plan para que tu asistente no deje de atender llamadas.</small>
      </div>
      <a href="/pages/billing.php" class="btn btn-<?= $upLevel === 'danger' ? 'danger' : 'outline-warning' ?>">
        <i class="bx bx-crown me-1"></i>Ampliar plan
      </a>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── Banda vertical (industria-específica) ───────────────────── -->
  <?php renderDashboardVertical($dash['vertical'] ?? null); ?>

  <!-- ── Hoy (agenda) + Valor generado ─────────────────────────── -->
  <div class="row g-4 mb-4">
    <div class="col-lg-7">
      <div class="card h-100">
        <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2 py-2">
          <h6 class="mb-0"><i class="bx bx-calendar me-1 text-primary"></i>Agenda <span id="agenda-count" class="text-muted fw-normal">· <?= count($dash['today'] ?? []) ?> cita(s)</span></h6>
          <div class="d-flex align-items-center gap-2">
            <div class="btn-group btn-group-sm" role="group" id="agenda-range">
              <button type="button" class="btn btn-primary"        data-range="today">Hoy</button>
              <button type="button" class="btn btn-outline-primary" data-range="week">Semana</button>
              <button type="button" class="btn btn-outline-primary" data-range="month">Mes</button>
            </div>
            <button class="btn btn-sm btn-primary" onclick="location.href='/pages/appointments.php?new=1'"><i class="bx bx-plus me-1"></i>Nueva</button>
          </div>
        </div>
        <div class="card-body py-2" id="agenda-body" style="max-height:340px;overflow-y:auto">
          <?php if (empty($dash['today'])): ?>
            <div class="text-muted text-center py-4"><i class="bx bx-coffee me-1"></i>Sin citas para hoy</div>
          <?php else: foreach ($dash['today'] as $a):
            try { $h = (new DateTime($a['scheduled_at']))->setTimezone($tz)->format('H:i'); } catch (\Throwable $e) { $h='--:--'; }
            $cs = $a['confirmation_status'] ?? 'pending';
            $cc = $cs==='confirmed' ? 'success' : ($cs==='cancelled' ? 'danger' : 'warning');
            $cl = $cs==='confirmed' ? 'confirmó' : ($cs==='cancelled' ? 'canceló' : 'esperando');
          ?>
          <div class="d-flex align-items-center gap-2 py-1 border-bottom">
            <span class="text-muted" style="width:46px"><?= $h ?></span>
            <span class="flex-grow-1"><?= e($a['patient_name'] ?: 'Cliente') ?></span>
            <span class="badge bg-label-<?= $cc ?>"><?= $cl ?></span>
          </div>
          <?php endforeach; endif; ?>
        </div>
      </div>
    </div>
    <div class="col-lg-5">
      <?php if ($valueReport): $vTotal=(float)($valueReport['value']['total']??0); $vCurr=$valueReport['currency']??'MXN'; $vMet=$valueReport['metrics']??[];
        $vRoi=$valueReport['roi_multiple']??null; $vPlan=(float)($valueReport['plan_monthly']??0); ?>
      <a href="/pages/value-report.php" class="text-decoration-none">
      <div class="card h-100 bg-label-primary border-0">
        <div class="card-body d-flex flex-column justify-content-center">
          <div class="text-primary small"><i class="bx bx-coin me-1"></i>Valor generado este mes</div>
          <div class="h2 mb-1 fw-bold text-primary">$<?= number_format($vTotal,0) ?> <?= e($vCurr) ?></div>
          <div class="small text-primary"><?= (int)($vMet['appointments']['value']??0) ?> citas · <?= (int)($vMet['leads']['value']??0) ?> leads · <?= (int)($vMet['after_hours']['value']??0) ?> fuera de horario</div>
          <?php if ($vRoi !== null && $vRoi > 0 && $vPlan > 0): ?>
          <div class="mt-2 pt-2 border-top border-primary border-opacity-25">
            <span class="badge bg-primary"><i class="bx bx-trending-up me-1"></i>ROI <?= rtrim(rtrim(number_format($vRoi,1),'0'),'.') ?>×</span>
            <span class="small text-primary ms-1">recuperas <?= rtrim(rtrim(number_format($vRoi,1),'0'),'.') ?> veces los $<?= number_format($vPlan,0) ?> de tu plan</span>
          </div>
          <?php endif; ?>
          <div class="mt-2"><span class="btn btn-sm btn-primary">Ver reporte <i class="bx bx-chevron-right"></i></span></div>
        </div>
      </div>
      </a>
      <?php else: ?>
      <div class="card h-100"><div class="card-body d-flex flex-column justify-content-center text-center text-muted">
        <i class="bx bx-coin mb-2" style="font-size:2rem;opacity:.3"></i>
        <div class="small">El reporte de valor se activa cuando tu bot esté aprobado en el Simulador.</div>
      </div></div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── KPIs accionables ──────────────────────────────────────── -->
  <?php $kp = $dash['kpis'] ?? []; ?>
  <div class="row g-4 mb-4">
    <?php
    $kcards = [
      ['l'=>'Tasa de confirmación','v'=>(int)($kp['confirmation_rate']??0).'%','i'=>'bx-calendar-check','c'=>'success','sub'=>'citas confirmadas (30d)'],
      ['l'=>'Conversión lead→cita','v'=>(int)($kp['lead_to_appt']??0).'%','i'=>'bx-trending-up','c'=>'primary','sub'=>((int)($kp['leads_30d']??0)).' leads (30d)'],
      ['l'=>'No-shows','v'=>(int)($kp['no_show_rate']??0).'%','i'=>'bx-user-x','c'=>'warning','sub'=>'no asistieron (30d)'],
      ['l'=>'Conversaciones','v'=>number_format((int)($kp['convs_30d']??0)),'i'=>'bx-conversation','c'=>'info','sub'=>'últimos 30 días'],
    ];
    foreach ($kcards as $c): ?>
    <div class="col-sm-6 col-xl-3">
      <div class="card h-100"><div class="card-body py-3">
        <div class="d-flex align-items-center gap-2 mb-1">
          <span class="avatar-initial rounded bg-label-<?= $c['c'] ?> p-2"><i class="bx <?= $c['i'] ?>"></i></span>
          <div class="h4 mb-0"><?= $c['v'] ?></div>
        </div>
        <div class="fw-semibold small"><?= $c['l'] ?></div>
        <div class="text-muted" style="font-size:.74rem"><?= $c['sub'] ?></div>
      </div></div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- ── Embudo de conversión (últimos 30 días) ─────────────────── -->
  <?php
    $funnel = [
      ['label'=>'Conversaciones', 'v'=>(int)($kp['convs_30d']??0),     'icon'=>'bx-conversation',     'color'=>'#03c3ec'],
      ['label'=>'Leads captados', 'v'=>(int)($kp['leads_30d']??0),     'icon'=>'bx-user-plus',        'color'=>'#696cff'],
      ['label'=>'Citas agendadas','v'=>(int)($kp['appts_30d']??0),     'icon'=>'bx-calendar-plus',    'color'=>'#71dd37'],
      ['label'=>'Citas completadas','v'=>(int)($kp['completed_30d']??0),'icon'=>'bx-check-double',     'color'=>'#ffab00'],
    ];
    $fTop = max(1, $funnel[0]['v']);
    $fAny = array_sum(array_column($funnel, 'v')) > 0;
  ?>
  <div class="card mb-4">
    <div class="card-header d-flex align-items-center justify-content-between py-2">
      <h6 class="mb-0"><i class="bx bx-filter-alt me-1 text-primary"></i>Embudo de conversión <span class="text-muted fw-normal">· últimos 30 días</span></h6>
      <a href="/pages/leads.php" class="btn btn-sm btn-outline-primary">Ver leads <i class="bx bx-chevron-right"></i></a>
    </div>
    <div class="card-body py-3">
      <?php if (!$fAny): ?>
        <div class="text-muted text-center py-3"><i class="bx bx-bar-chart-alt-2 me-1"></i>Aún no hay actividad en los últimos 30 días. Cuando tu bot empiece a atender, verás aquí el recorrido de cada cliente.</div>
      <?php else: foreach ($funnel as $i => $f):
        $w   = max(6, round($f['v'] / $fTop * 100));               // ancho mínimo visible
        $prev = $i > 0 ? $funnel[$i-1]['v'] : null;
        $conv = ($prev !== null && $prev > 0) ? round($f['v'] / $prev * 100) : null;
      ?>
      <?php if ($i > 0): ?>
        <?php if ($conv !== null && $conv > 100): ?>
        <div class="d-flex align-items-center gap-1 text-success" style="font-size:.72rem;padding-left:8px;height:18px">
          <i class="bx bx-up-arrow-alt"></i><span>más que la etapa previa — incluye clientes recurrentes</span>
        </div>
        <?php else: ?>
        <div class="d-flex align-items-center gap-1 text-muted" style="font-size:.72rem;padding-left:8px;height:18px">
          <i class="bx bx-down-arrow-alt"></i>
          <?php if ($conv !== null): ?><span><?= $conv ?>% pasa a la siguiente etapa</span><?php endif; ?>
        </div>
        <?php endif; ?>
      <?php endif; ?>
      <div class="d-flex align-items-center gap-3 mb-1">
        <div class="d-flex align-items-center gap-2" style="width:170px;flex-shrink:0">
          <i class="bx <?= $f['icon'] ?>" style="color:<?= $f['color'] ?>;font-size:1.15rem"></i>
          <span class="small fw-semibold"><?= e($f['label']) ?></span>
        </div>
        <div class="flex-grow-1">
          <div class="rounded d-flex align-items-center px-2 text-white fw-semibold"
               style="width:<?= $w ?>%;min-width:54px;height:30px;background:<?= $f['color'] ?>;transition:width .4s">
            <?= number_format($f['v']) ?>
          </div>
        </div>
        <div class="text-muted small" style="width:48px;text-align:right">
          <?= $fTop > 0 ? round($f['v'] / $fTop * 100) : 0 ?>%
        </div>
      </div>
      <?php endforeach; endif; ?>
    </div>
  </div>

  <!-- ── Agenda de citas (lo más preponderante: va arriba) ──────── -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.css"/>
  <div class="row g-4 mb-4">
    <div class="col-12">
      <div class="card">
        <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
          <h5 class="card-title mb-0"><i class="bx bx-calendar-check me-2 text-primary"></i>Agenda de citas</h5>
          <div class="d-flex gap-2">
            <button class="btn btn-sm btn-primary" onclick="location.href='/pages/appointments.php?new=1'"><i class="bx bx-plus me-1"></i>Nueva cita</button>
            <a href="/pages/appointments.php" class="btn btn-sm btn-outline-primary"><i class="bx bx-list-ul me-1"></i>Ver todas</a>
          </div>
        </div>
        <div class="card-body">
          <p class="text-muted small mb-2"><i class="bx bx-info-circle me-1"></i>Clic en un día para agendar · clic en una cita para ver el detalle.</p>
          <div id="dash-calendar" style="min-height:480px"></div>
        </div>
      </div>
    </div>
  </div>


  <?php if (isAdmin() && !$isReady): ?>
  <!-- ── Guía de Configuración Inicial ─────────────────────────────── -->
  <?php
    // Calcular pasos completados
    $wizardSteps = [
      ['key'=>'business',   'label'=>'Negocio & Agente',  'icon'=>'bx-building',     'href'=>'/pages/agents.php',         'desc'=>'Configurar tu agente IA y datos del negocio'],
      ['key'=>'knowledge',  'label'=>'Base de conocimiento','icon'=>'bx-brain',       'href'=>'/pages/knowledge-base.php', 'desc'=>'Subir documentos y FAQs para el bot'],
      ['key'=>'users',      'label'=>'Equipo',             'icon'=>'bx-group',        'href'=>'/pages/users.php',          'desc'=>'Invitar a tu equipo de trabajo'],
      ['key'=>'channel',    'label'=>'Canal activo',       'icon'=>'bx-phone',        'href'=>'/pages/settings.php',       'desc'=>'Conectar Twilio o WhatsApp'],
      ['key'=>'simulator',  'label'=>'Simulador',          'icon'=>'bx-test-tube',    'href'=>'/pages/simulator.php',      'desc'=>'Probar y aprobar las respuestas del bot'],
    ];
    $completedCount = 0;
    foreach ($wizardSteps as $s) {
      if (!empty($setupSteps[$s['key']])) $completedCount++;
    }
    $totalSteps = count($wizardSteps);
    $setupPct   = $totalSteps > 0 ? round($completedCount / $totalSteps * 100) : 0;
    // Auto-marcar el paso de negocio si ya hay agentes configurados
    $hasAgent = !empty(tenantSettings()['onboarding_completed']);
  ?>
  <div class="row mb-4" id="setup-guide">
    <div class="col-12">
      <div class="card border-primary" style="border-width:2px!important">
        <div class="card-header bg-primary text-white d-flex align-items-center justify-content-between py-2">
          <div class="d-flex align-items-center gap-2">
            <i class="bx bx-rocket bx-sm"></i>
            <h6 class="mb-0 text-white fw-semibold">Configuración inicial — Activa tu bot</h6>
          </div>
          <div class="d-flex align-items-center gap-3">
            <small class="opacity-75"><?= $completedCount ?>/<?= $totalSteps ?> pasos completados</small>
            <div class="progress" style="width:120px;height:6px;background:rgba(255,255,255,.3)">
              <div class="progress-bar bg-white" style="width:<?= $setupPct ?>%"></div>
            </div>
          </div>
        </div>
        <div class="card-body py-3">
          <div class="row g-3">
            <?php foreach ($wizardSteps as $idx => $step):
              $done = !empty($setupSteps[$step['key']]) || ($step['key'] === 'business' && $hasAgent);
            ?>
            <div class="col-md col-6">
              <a href="<?= e($step['href']) ?>"
                 class="card text-decoration-none setup-step-card <?= $done ? 'border-success bg-label-success' : '' ?>"
                 style="border:1px solid <?= $done ? '#28c76f' : 'var(--bs-border-color)' ?>;transition:all .2s"
                 title="<?= e($step['desc']) ?>">
                <div class="card-body py-2 px-3 text-center">
                  <div class="mb-1" style="position:relative;display:inline-block">
                    <i class="bx <?= e($step['icon']) ?> <?= $done ? 'text-success' : 'text-primary' ?>"
                       style="font-size:1.5rem"></i>
                    <?php if ($done): ?>
                      <i class="bx bx-check-circle text-success"
                         style="position:absolute;top:-4px;right:-10px;font-size:.75rem"></i>
                    <?php else: ?>
                      <span class="position-absolute badge rounded-pill bg-primary"
                            style="top:-6px;right:-12px;font-size:.6rem;padding:2px 5px"><?= $idx + 1 ?></span>
                    <?php endif; ?>
                  </div>
                  <div class="fw-semibold" style="font-size:.78rem">
                    <?= e($step['label']) ?>
                    <?php if ($done): ?><i class="bx bx-check text-success ms-1"></i><?php endif; ?>
                  </div>
                  <small class="text-muted d-none d-lg-block" style="font-size:.68rem"><?= e($step['desc']) ?></small>
                </div>
              </a>
            </div>
            <?php endforeach; ?>
          </div>

          <?php if ($completedCount === $totalSteps - 1 && empty($setupSteps['simulator'])): ?>
          <div class="alert alert-warning mt-3 mb-0 py-2 d-flex align-items-center gap-2">
            <i class="bx bx-info-circle flex-shrink-0"></i>
            <span class="small">
              ¡Casi listo! Solo falta aprobar el simulador para activar tu bot.
              <a href="/pages/simulator.php" class="alert-link fw-semibold">Ir al simulador →</a>
            </span>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Gráfica de conversaciones + Actividad reciente -->
  <div class="row g-4 mb-4">

    <!-- Chart -->
    <div class="col-md-8">
      <div class="card h-100">
        <div class="card-header d-flex align-items-center justify-content-between">
          <div>
            <h5 class="card-title mb-0">Conversaciones</h5>
            <small class="text-muted" id="conv-subtitle">últimos 7 días por canal</small>
          </div>
          <a href="/pages/reports.php" class="btn btn-sm btn-outline-secondary"><i class="bx bx-bar-chart-alt-2 me-1"></i>Reportes</a>
        </div>
        <div class="card-body">
          <div style="height:200px"><canvas id="convChart"></canvas></div>
        </div>
      </div>
    </div>

    <!-- Distribución por canal -->
    <div class="col-md-4">
      <div class="card h-100">
        <div class="card-header">
          <h5 class="card-title mb-0">Por canal</h5>
          <small class="text-muted">últimos 30 días</small>
        </div>
        <div class="card-body d-flex flex-column">
          <div style="height:150px;position:relative">
            <canvas id="channelChart"></canvas>
            <div id="channel-center" style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;pointer-events:none">
              <div class="h4 mb-0" id="channel-total">0</div>
              <small class="text-muted">total</small>
            </div>
          </div>
          <div id="channel-legend" class="mt-3 d-flex flex-column gap-2"></div>
        </div>
      </div>
    </div>

  </div>

  <!-- Tablas recientes -->
  <div class="row g-4">

    <!-- Conversaciones recientes -->
    <div class="col-md-7">
      <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h5 class="card-title mb-0">Conversaciones recientes</h5>
          <a href="/pages/conversations.php" class="btn btn-sm btn-outline-primary">Ver todas</a>
        </div>
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead class="table-light">
              <tr>
                <th>Contacto</th>
                <th>Canal</th>
                <th>Resultado</th>
                <th>Duración</th>
                <th>Fecha</th>
              </tr>
            </thead>
            <tbody>
              <?php
              $convs = $convRecent['data'] ?? [];
              if (empty($convs)): ?>
                <tr><td colspan="5" class="text-center text-muted py-4">Sin conversaciones aún</td></tr>
              <?php else: foreach ($convs as $c): ?>
                <tr>
                  <td>
                    <span class="fw-semibold"><?= e($c['contact_name'] ?? $c['contact_phone'] ?? '—') ?></span>
                  </td>
                  <td><?= channelIcon($c['channel'] ?? 'voice') ?> <small><?= e($c['channel'] ?? '') ?></small></td>
                  <td><?= statusBadge($c['outcome'] ?? $c['status'] ?? 'active') ?></td>
                  <td><small><?= $c['duration_secs'] ? formatMins((int)$c['duration_secs']) : '—' ?></small></td>
                  <td><small class="text-muted"><?= formatDate($c['started_at'] ?? '', 'd/m H:i') ?></small></td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Leads recientes -->
    <div class="col-md-5">
      <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h5 class="card-title mb-0">Leads recientes</h5>
          <a href="/pages/leads.php" class="btn btn-sm btn-outline-primary">Ver todos</a>
        </div>
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead class="table-light">
              <tr><th>Nombre</th><th>Estado</th><th>Fecha</th></tr>
            </thead>
            <tbody>
              <?php
              $leads = $leadsRecent ?? [];
              if (is_array($leads) && isset($leads[0])): foreach (array_slice($leads, 0, 5) as $l): ?>
                <tr>
                  <td>
                    <span class="fw-semibold d-block"><?= e($l['name'] ?? '—') ?></span>
                    <small class="text-muted"><?= e($l['phone'] ?? '') ?></small>
                  </td>
                  <td><?= statusBadge($l['status'] ?? 'new') ?></td>
                  <td><small class="text-muted"><?= formatDate($l['created_at'] ?? '', 'd/m') ?></small></td>
                </tr>
              <?php endforeach; else: ?>
                <tr><td colspan="3" class="text-center text-muted py-4">Sin leads aún</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

  </div><!-- / tablas -->

</div><!-- / container -->

<?php renderFooter(); ?>

<script>
// ── Auto-refresh del cockpit (polling cada 60s, no intrusivo) ────
// Re-consulta el overview; si cambia el total de "Necesita tu atención"
// actualiza el badge en vivo y, si AUMENTÓ, avisa con un toast + opción de
// recargar. No reemplaza el DOM (no pierde el estado del calendario ni el scroll).
(function () {
  const POLL_MS = 60000;
  const badge = document.getElementById('attention-badge');
  let lastTotal = badge ? parseInt(badge.textContent) || 0 : 0;
  let notified = false;

  async function poll() {
    try {
      const res = await fetch('/api/dashboard-overview.php', { headers: { 'Accept': 'application/json' } });
      if (!res.ok) return;
      const d = await res.json();
      const total = parseInt(d?.attention?.total ?? 0) || 0;
      if (badge && total !== lastTotal) {
        badge.textContent = total;
        badge.classList.add('bx-tada');
        setTimeout(() => badge.classList.remove('bx-tada'), 1500);
      }
      if (total > lastTotal && !notified) {
        notified = true;
        const extra = total - lastTotal;
        window.showToast?.(`Tienes ${extra} novedad(es) por atender. <a href="/index.php" class="alert-link">Actualizar</a>`, 'warning');
      }
      lastTotal = total;
    } catch (_) { /* silencioso: el dashboard sigue usable */ }
  }
  // Pausa el polling cuando la pestaña no está visible (ahorra llamadas).
  let timer = setInterval(poll, POLL_MS);
  document.addEventListener('visibilitychange', () => {
    clearInterval(timer);
    if (!document.hidden) { poll(); timer = setInterval(poll, POLL_MS); }
  });
})();

// ── "Recordar por WhatsApp" — recordatorio en lote de citas sin confirmar ──
(function () {
  const btn = document.getElementById('btn-remind-all');
  if (!btn) return;
  btn.addEventListener('click', async () => {
    let ids = [];
    try { ids = JSON.parse(btn.dataset.ids || '[]'); } catch (_) {}
    if (!ids.length) return;
    const orig = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Enviando…';
    try {
      const res = await fetch('/api/appointment-remind.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ ids }),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || 'No se pudo enviar');
      const sent = data.sent || 0, failed = data.failed || 0;
      if (sent && !failed) window.showToast?.(`Recordatorio enviado a ${sent} cliente(s) por WhatsApp`, 'success');
      else if (sent && failed) window.showToast?.(`Enviados ${sent}, ${failed} no se pudieron entregar (fuera de la ventana de 24 h)`, 'warning');
      else window.showToast?.('No se pudo entregar ningún recordatorio (fuera de la ventana de 24 h sin plantilla aprobada)', 'error');
      btn.innerHTML = '<i class="bx bx-check me-1"></i>Enviado';
    } catch (err) {
      window.showToast?.(err.message || 'Error al enviar recordatorios', 'error');
      btn.disabled = false;
      btn.innerHTML = orig;
    }
  });
})();

// ── Toggle de agenda Hoy / Semana / Mes ──────────────────────────
(function () {
  const group = document.getElementById('agenda-range');
  const bodyEl = document.getElementById('agenda-body');
  const countEl = document.getElementById('agenda-count');
  if (!group || !bodyEl) return;
  const TZ = 'America/Mexico_City';
  const todayHtml = bodyEl.innerHTML;            // render server-side de "Hoy" (reuso)

  const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
  // Fecha "hoy" en zona MX como YYYY-MM-DD
  const mxToday = () => new Intl.DateTimeFormat('en-CA', { timeZone: TZ, year:'numeric', month:'2-digit', day:'2-digit' }).format(new Date());
  function fmtTime(iso) {
    try { return new Intl.DateTimeFormat('es-MX', { timeZone: TZ, hour:'2-digit', minute:'2-digit' }).format(new Date(iso)); }
    catch { return '--:--'; }
  }
  function dayKey(iso) {
    return new Intl.DateTimeFormat('en-CA', { timeZone: TZ, year:'numeric', month:'2-digit', day:'2-digit' }).format(new Date(iso));
  }
  function dayHeader(iso) {
    return new Intl.DateTimeFormat('es-MX', { timeZone: TZ, weekday:'long', day:'numeric', month:'short' }).format(new Date(iso));
  }

  function rangeFor(kind) {
    const base = mxToday();                       // 'YYYY-MM-DD'
    const d = new Date(base + 'T12:00:00');       // mediodía evita saltos de DST
    if (kind === 'week') {
      const end = new Date(d); end.setDate(end.getDate() + 6);
      return { from: base + 'T00:00:00', to: dayKey(end) + 'T23:59:59' };
    }
    if (kind === 'month') {
      const end = new Date(d); end.setDate(end.getDate() + 29);
      return { from: base + 'T00:00:00', to: dayKey(end) + 'T23:59:59' };
    }
    return { from: base + 'T00:00:00', to: base + 'T23:59:59' };
  }

  function rowHtml(a) {
    const cs = a.confirmation_status || 'pending';
    const cc = cs === 'confirmed' ? 'success' : (cs === 'cancelled' ? 'danger' : 'warning');
    const cl = cs === 'confirmed' ? 'confirmó' : (cs === 'cancelled' ? 'canceló' : 'esperando');
    const name = esc(a.patient_name || a.lead_name || 'Cliente');
    return `<div class="d-flex align-items-center gap-2 py-1 border-bottom">
      <span class="text-muted" style="width:46px">${fmtTime(a.scheduled_at)}</span>
      <span class="flex-grow-1">${name}</span>
      <span class="badge bg-label-${cc}">${cl}</span>
    </div>`;
  }

  function render(rows) {
    if (!rows.length) { bodyEl.innerHTML = '<div class="text-muted text-center py-4"><i class="bx bx-coffee me-1"></i>Sin citas en este periodo</div>'; return; }
    let html = '', lastDay = '';
    for (const a of rows) {
      const k = dayKey(a.scheduled_at);
      if (k !== lastDay) { html += `<div class="small fw-semibold text-primary mt-2 mb-1 text-capitalize">${esc(dayHeader(a.scheduled_at))}</div>`; lastDay = k; }
      html += rowHtml(a);
    }
    bodyEl.innerHTML = html;
  }

  async function load(kind) {
    if (kind === 'today') { bodyEl.innerHTML = todayHtml; if (countEl) countEl.textContent = ''; return; }
    bodyEl.innerHTML = '<div class="text-center py-4"><span class="spinner-border spinner-border-sm text-primary"></span></div>';
    try {
      const { from, to } = rangeFor(kind);
      const res = await fetch('/api/appointments-proxy.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'list', from, to }),
      });
      const data = await res.json();
      const rows = (data.data || []).filter(a => (a.status || '') !== 'cancelled');
      render(rows);
      if (countEl) countEl.textContent = `· ${rows.length} cita(s)`;
    } catch (e) {
      bodyEl.innerHTML = '<div class="text-danger text-center py-4">No se pudo cargar la agenda</div>';
    }
  }

  group.querySelectorAll('button').forEach(b => b.addEventListener('click', () => {
    group.querySelectorAll('button').forEach(x => { x.classList.remove('btn-primary'); x.classList.add('btn-outline-primary'); });
    b.classList.add('btn-primary'); b.classList.remove('btn-outline-primary');
    load(b.dataset.range);
  }));
})();

// ── Helpers de fecha ─────────────────────────────────────────────
const DAY_LABELS = ['Dom','Lun','Mar','Mié','Jue','Vie','Sáb'];
function dayLabel(isoDate) {
  const d = new Date(isoDate + 'T00:00:00');
  if (isNaN(d)) return '';
  return DAY_LABELS[d.getDay()] + ' ' + d.getDate();   // ej. "Sáb 20"
}

// ── Instancias de Chart (se crean después de cargar datos) ───────
let convChartInst    = null;
let channelChartInst = null;

function buildConvChart(series) {
  const labels  = series.map(s => dayLabel(s.date));
  const voice   = series.map(s => s.voice   || 0);
  const whats   = series.map(s => s.whatsapp || 0);
  const webchat = series.map(s => s.webchat  || 0);

  const ctx = document.getElementById('convChart');
  if (!ctx) return;
  if (convChartInst) convChartInst.destroy();

  convChartInst = new Chart(ctx, {
    type: 'bar',
    data: {
      labels,
      datasets: [
        { label: 'Voz',      data: voice,   backgroundColor: '#696CFF', borderRadius: 4 },
        { label: 'WhatsApp', data: whats,   backgroundColor: '#28C76F', borderRadius: 4 },
        { label: 'Web Chat', data: webchat, backgroundColor: '#03C3EC', borderRadius: 4 },
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { position: 'top', labels: { boxWidth: 12, padding: 12, font: { size: 11 } } },
        tooltip: {
          callbacks: {
            title: (items) => {
              const d = new Date((series[items[0].dataIndex]?.date || '') + 'T00:00:00');
              return isNaN(d) ? '' : d.toLocaleDateString('es-MX', { weekday: 'long', day: 'numeric', month: 'short' });
            },
            footer: (items) => 'Total: ' + items.reduce((a, it) => a + (it.parsed.y || 0), 0),
          }
        }
      },
      scales: {
        x: { stacked: true, grid: { display: false } },
        y: { stacked: true, beginAtZero: true, ticks: { stepSize: 1, precision: 0 }, grid: { color: 'rgba(128,128,128,.12)' } }
      }
    }
  });

  const grand = voice.concat(whats, webchat).reduce((a, b) => a + b, 0);
  const sub = document.getElementById('conv-subtitle');
  if (sub) sub.textContent = grand > 0 ? `últimos 7 días · ${grand} conversaciones` : 'sin conversaciones en los últimos 7 días';
}

function buildChannelChart(channels) {
  const channelMap = {
    voice:    { label: 'Voz',      color: '#696CFF' },
    whatsapp: { label: 'WhatsApp', color: '#28C76F' },
    webchat:  { label: 'Web Chat', color: '#03C3EC' },
  };

  const keys    = Object.keys(channels).filter(k => channels[k] > 0);
  const labels  = keys.map(k => channelMap[k]?.label  || k);
  const data    = keys.map(k => channels[k]);
  const colors  = keys.map(k => channelMap[k]?.color  || '#aaa');
  const total   = data.reduce((a, b) => a + b, 0);

  const ctx = document.getElementById('channelChart');
  if (!ctx) return;
  if (channelChartInst) channelChartInst.destroy();

  const totalEl = document.getElementById('channel-total');
  if (totalEl) totalEl.textContent = total;

  if (total === 0) {
    // Sin datos — mostrar placeholder
    channelChartInst = new Chart(ctx, {
      type: 'doughnut',
      data: {
        labels: ['Sin datos'],
        datasets: [{ data: [1], backgroundColor: ['#e0e0e0'] }]
      },
      options: { responsive: true, maintainAspectRatio: false, cutout: '72%', plugins: { legend: { display: false }, tooltip: { enabled: false } } }
    });
    document.getElementById('channel-legend').innerHTML =
      '<small class="text-muted">Sin conversaciones aún</small>';
    return;
  }

  channelChartInst = new Chart(ctx, {
    type: 'doughnut',
    data: { labels, datasets: [{ data, backgroundColor: colors, borderWidth: 0 }] },
    options: {
      responsive: true, maintainAspectRatio: false, cutout: '72%',
      plugins: {
        legend: { display: false },
        tooltip: { callbacks: { label: (it) => `${it.label}: ${it.parsed} (${Math.round(it.parsed/total*100)}%)` } }
      }
    }
  });

  // Leyenda manual
  const legend = document.getElementById('channel-legend');
  if (legend) {
    legend.innerHTML = keys.map((k, i) => `
      <div class="d-flex align-items-center justify-content-between">
        <div class="d-flex align-items-center gap-2">
          <span style="width:10px;height:10px;border-radius:50%;background:${colors[i]};display:inline-block"></span>
          <small>${labels[i]}</small>
        </div>
        <small class="fw-semibold">${data[i]} <span class="text-muted fw-normal">(${Math.round(data[i]/total*100)}%)</span></small>
      </div>
    `).join('');
  }
}

// ── Fetch de stats en vivo ───────────────────────────────────────
fetch('/api/conversations-today.php', { credentials: 'same-origin' })
  .then(r => r.json())
  .then(d => {
    // KPI hoy
    const kpiEl  = document.getElementById('kpi-convs-today');
    const badgeEl = document.getElementById('kpi-convs-badge');
    if (kpiEl) kpiEl.textContent = d.today ?? '—';
    if (badgeEl && (d.today ?? 0) > 0) {
      badgeEl.className = 'badge bg-label-success rounded-pill';
      badgeEl.textContent = 'Hoy';
    }

    // Gráficas
    if (d.series?.length) buildConvChart(d.series);
    if (d.channels)       buildChannelChart(d.channels);
  })
  .catch(() => {
    const kpiEl = document.getElementById('kpi-convs-today');
    if (kpiEl) kpiEl.textContent = '—';
    // Fallback a datos demo si el backend no responde
    buildConvChart([
      {date:'',voice:0,whatsapp:0,webchat:0},
      {date:'',voice:0,whatsapp:0,webchat:0},
      {date:'',voice:0,whatsapp:0,webchat:0},
      {date:'',voice:0,whatsapp:0,webchat:0},
      {date:'',voice:0,whatsapp:0,webchat:0},
      {date:'',voice:0,whatsapp:0,webchat:0},
      {date:'',voice:0,whatsapp:0,webchat:0},
    ]);
    buildChannelChart({});
  });
</script>

<!-- ── Calendario del Dashboard (FullCalendar) ─────────────────── -->
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var el = document.getElementById('dash-calendar');
  if (!el || !window.FullCalendar) return;
  new FullCalendar.Calendar(el, {
    initialView:  'dayGridMonth',
    locale:       'es',
    height:       480,
    firstDay:     1,
    nowIndicator: true,
    headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,listWeek' },
    buttonText:   { today: 'Hoy', month: 'Mes', list: 'Agenda' },
    events:       '/api/appointments-events.php',
    eventClick:   function () { window.location.href = '/pages/appointments.php'; },
    dateClick:    function (info) { window.location.href = '/pages/appointments.php?new=1&date=' + info.dateStr; },
  }).render();
});
</script>
