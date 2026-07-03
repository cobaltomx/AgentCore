<?php
/**
 * Super Admin — Insights / Voz del cliente por scope: Global ▸ Vertical ▸ Negocio.
 * Agrega la señal cualitativa de conversations.analysis (intención, temas,
 * objeciones, vacíos de conocimiento, preguntas sin respuesta) + sentimiento y
 * handoffs. Última fase del scope del Super Admin.
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
$ins   = apiGet('/superadmin/insights', $sp);
$level = $ins['level'] ?? 'global';

function insPeriodLink($d, $industry, $tenantId) {
  $q = ['days' => $d];
  if ($tenantId !== '') $q['tenantId'] = $tenantId;
  elseif ($industry !== '') $q['industry'] = $industry;
  return '?' . http_build_query($q);
}

// Render de una lista "ranking" con barra proporcional al máximo.
function insRanking(array $rows, string $labelKey, string $color): string {
  if (!$rows) return '<div class="text-muted text-center py-3"><i class="bx bx-info-circle me-1"></i>Sin datos en este periodo</div>';
  $max = max(1, max(array_map(fn($r) => (int)($r['n'] ?? 0), $rows)));
  $h = '';
  foreach ($rows as $r) {
    $n = (int)($r['n'] ?? 0);
    $w = max(6, round($n / $max * 100));
    $lbl = trim((string)($r[$labelKey] ?? '—'));
    if ($lbl === '') $lbl = '—';
    $h .= '<div class="mb-2">'
        . '<div class="d-flex justify-content-between mb-1"><small class="text-truncate" style="max-width:80%">' . e(ucfirst($lbl)) . '</small><small class="fw-semibold">' . $n . '</small></div>'
        . '<div class="progress" style="height:6px"><div class="progress-bar bg-' . $color . '" style="width:' . $w . '%"></div></div>'
        . '</div>';
  }
  return $h;
}

$sent      = $ins['sentiment'] ?? ['positivo'=>0,'neutral'=>0,'negativo'=>0,'sin_dato'=>0];
$sentTotal = max(1, array_sum(array_map('intval', $sent)));
$analyzed  = (int)($ins['total_analyzed'] ?? 0);
$convs     = (int)($ins['total_convs'] ?? 0);
$kbGaps    = (int)($ins['kb_gaps'] ?? 0);
$handoffs  = $ins['handoffs'] ?? ['count'=>0,'reasons'=>[]];
$unans     = is_array($ins['unanswered'] ?? null) ? $ins['unanswered'] : [];

renderHead('Insights — Super Admin');
?>
<div class="layout-wrapper layout-content-navbar"><div class="layout-container">
<?php renderSidebar('sa-insights'); ?>
<div class="layout-page"><?php renderNavbar('Insights'); ?>
<div class="content-wrapper"><div class="container-xxl flex-grow-1 container-p-y">

  <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <div>
      <h4 class="mb-1"><i class="bx bx-bulb me-1 text-primary"></i>Insights · Voz del cliente</h4>
      <p class="text-muted mb-0">Qué piden, de qué hablan, qué los frena y qué no supo responder el bot.</p>
    </div>
    <div class="btn-group">
      <?php foreach ([7=>'7 días', 30=>'30 días', 90=>'90 días'] as $d => $lbl): ?>
        <a href="<?= e(insPeriodLink($d, $industry, $tenantId)) ?>"
           class="btn btn-sm <?= $days === $d ? 'btn-primary' : 'btn-outline-primary' ?>"><?= $lbl ?></a>
      <?php endforeach; ?>
    </div>
  </div>

  <?php renderScopeSelector([
    'industry'=>$industry, 'tenantId'=>$tenantId, 'verticals'=>$verticals,
    'tenants'=>$tenantsAll, 'level'=>$level, 'negocios'=>0, 'extra'=>['days'=>$days],
  ]); ?>

  <?php if ($convs === 0): ?>
  <div class="card"><div class="card-body text-center text-muted py-5">
    <i class="bx bx-message-square-dots mb-2" style="font-size:2.4rem;opacity:.35"></i>
    <div>No hay conversaciones en este periodo para el scope seleccionado.</div>
  </div></div>
  <?php else: ?>

  <!-- ── Resumen ─────────────────────────────────────────────── -->
  <div class="row g-3 mb-4">
    <?php
    $pos = (int)$sent['positivo']; $neg = (int)$sent['negativo'];
    $satPct = $sentTotal > 0 ? round($pos / $sentTotal * 100) : 0;
    $cards = [
      ['l'=>'Conversaciones','v'=>number_format($convs),'sub'=>$analyzed.' analizadas por IA','i'=>'bx-conversation','c'=>'primary'],
      ['l'=>'Sentimiento positivo','v'=>$satPct.'%','sub'=>$pos.' positivas · '.$neg.' negativas','i'=>'bx-smile','c'=>$satPct>=50?'success':($satPct>=25?'warning':'danger')],
      ['l'=>'Sin respuesta / vacíos','v'=>number_format(count($unans) ?: $kbGaps),'sub'=>$kbGaps.' con vacío de conocimiento','i'=>'bx-help-circle','c'=>($kbGaps>0?'warning':'success')],
      ['l'=>'Pases a humano','v'=>number_format((int)$handoffs['count']),'sub'=>'escalaciones a agente','i'=>'bx-transfer-alt','c'=>((int)$handoffs['count']>0?'info':'success')],
    ];
    foreach ($cards as $c): ?>
    <div class="col-sm-6 col-xl-3">
      <div class="card h-100"><div class="card-body py-3">
        <div class="d-flex align-items-center gap-2 mb-1">
          <span class="avatar-initial rounded bg-label-<?= $c['c'] ?> p-2"><i class="bx <?= $c['i'] ?>"></i></span>
          <div class="h4 mb-0"><?= e($c['v']) ?></div>
        </div>
        <div class="fw-semibold small"><?= e($c['l']) ?></div>
        <div class="text-muted" style="font-size:.74rem"><?= e($c['sub']) ?></div>
      </div></div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- ── Sentimiento (barra apilada) ─────────────────────────── -->
  <div class="card mb-4"><div class="card-body py-3">
    <div class="d-flex justify-content-between mb-2">
      <h6 class="mb-0"><i class="bx bx-pulse me-1 text-primary"></i>Sentimiento de las conversaciones</h6>
      <small class="text-muted"><?= (int)$sent['positivo']+(int)$sent['neutral']+(int)$sent['negativo'] ?> con sentimiento detectado</small>
    </div>
    <div class="progress" style="height:22px">
      <?php
      $segs = [
        ['positivo','success', (int)$sent['positivo']],
        ['neutral', 'secondary',(int)$sent['neutral']],
        ['negativo','danger',  (int)$sent['negativo']],
        ['sin dato','light',   (int)$sent['sin_dato']],
      ];
      foreach ($segs as [$lbl,$col,$v]): if ($v <= 0) continue; $p = round($v/$sentTotal*100); ?>
        <div class="progress-bar bg-<?= $col ?> <?= $col==='light'?'text-dark':'' ?>" style="width:<?= $p ?>%" title="<?= $lbl ?>: <?= $v ?>">
          <?= $p >= 8 ? ucfirst($lbl).' '.$p.'%' : '' ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div></div>

  <!-- ── Intenciones + Temas ─────────────────────────────────── -->
  <div class="row g-4 mb-4">
    <div class="col-lg-6"><div class="card h-100">
      <div class="card-header py-2"><h6 class="mb-0"><i class="bx bx-target-lock me-1 text-primary"></i>Qué piden (intención)</h6></div>
      <div class="card-body py-3"><?= insRanking($ins['intents'] ?? [], 'intent', 'primary') ?></div>
    </div></div>
    <div class="col-lg-6"><div class="card h-100">
      <div class="card-header py-2"><h6 class="mb-0"><i class="bx bx-hash me-1 text-info"></i>De qué hablan (temas)</h6></div>
      <div class="card-body py-3"><?= insRanking($ins['topics'] ?? [], 'topic', 'info') ?></div>
    </div></div>
  </div>

  <!-- ── Objeciones + Handoffs ───────────────────────────────── -->
  <div class="row g-4 mb-4">
    <div class="col-lg-6"><div class="card h-100">
      <div class="card-header py-2"><h6 class="mb-0"><i class="bx bx-block me-1 text-warning"></i>Qué los frena (objeciones)</h6></div>
      <div class="card-body py-3"><?= insRanking($ins['objections'] ?? [], 'objection', 'warning') ?></div>
    </div></div>
    <div class="col-lg-6"><div class="card h-100">
      <div class="card-header py-2"><h6 class="mb-0"><i class="bx bx-transfer-alt me-1 text-info"></i>Motivos de pase a humano</h6></div>
      <div class="card-body py-3"><?= insRanking($handoffs['reasons'] ?? [], 'reason', 'secondary') ?></div>
    </div></div>
  </div>

  <!-- ── Preguntas sin respuesta (lo más accionable) ─────────── -->
  <div class="card mb-4">
    <div class="card-header d-flex align-items-center justify-content-between py-2">
      <h6 class="mb-0"><i class="bx bx-error-circle me-1 text-danger"></i>Preguntas que el bot no supo responder</h6>
      <span class="badge bg-label-danger"><?= count($unans) ?></span>
    </div>
    <?php if (!$unans): ?>
      <div class="card-body text-muted text-center py-4"><i class="bx bx-check-circle text-success me-1"></i>El bot respondió todo en este periodo. 🎉</div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm mb-0 align-middle">
        <thead><tr><th>Pregunta</th><th>Negocio</th><th>Canal</th><th class="text-end">Cuándo</th></tr></thead>
        <tbody>
          <?php foreach ($unans as $u): ?>
          <tr>
            <td style="max-width:480px"><?= e($u['question'] ?? '') ?></td>
            <td><small class="text-muted"><?= e($u['tenant_name'] ?? '—') ?></small></td>
            <td><?php $ch=$u['channel']??''; ?><span class="badge bg-label-<?= $ch==='voice'?'primary':($ch==='whatsapp'?'success':'secondary') ?>"><?= e($ch ?: '—') ?></span></td>
            <td class="text-end"><small class="text-muted"><?= e(formatDate($u['created_at'] ?? '')) ?></small></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="card-footer py-2"><small class="text-muted"><i class="bx bx-bulb me-1"></i>Estas preguntas son candidatas directas para enriquecer la base de conocimiento del negocio.</small></div>
    <?php endif; ?>
  </div>

  <?php endif; ?>

</div><?php renderFooter(); ?>
</div></div></div>
