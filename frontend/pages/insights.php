<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/head.php';
require_once __DIR__ . '/../includes/sidebar.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/footer.php';

requireAuth();
if (!isAdmin()) { header('Location: /index.php'); exit; }

$days = (int)($_GET['days'] ?? 30);
$days = in_array($days, [7, 30, 90]) ? $days : 30;

// ── Gate premium: Voz del cliente requiere plan Growth+ ──────────
$hasFeature = tenantHasFeature('insights');
if (!$hasFeature) {
    renderHead('Voz del cliente');
    ?>
    <div class="layout-wrapper layout-content-navbar"><div class="layout-container">
    <?php renderSidebar('insights'); ?>
    <div class="layout-page"><?php renderNavbar('Voz del cliente'); ?>
    <div class="content-wrapper"><div class="container-xxl flex-grow-1 container-p-y">

      <div class="row justify-content-center">
        <div class="col-lg-9">
          <!-- Hero del upsell -->
          <div class="card border-0 mb-4" style="background:linear-gradient(135deg,#696cff 0%,#9155fd 100%)">
            <div class="card-body text-white text-center py-5">
              <div style="font-size:3rem">💡</div>
              <span class="badge bg-white text-primary mb-2"><i class="bx bx-crown me-1"></i>Función Premium</span>
              <h3 class="text-white fw-bold mb-2">Voz del cliente</h3>
              <p class="opacity-75 mb-0 mx-auto" style="max-width:520px">
                Convierte cada conversación en información de negocio accionable.
                Disponible en los planes <strong>Growth</strong> y superiores.
              </p>
            </div>
          </div>

          <!-- Ventajas -->
          <div class="row g-3 mb-4">
            <?php
            $benefits = [
              ['icon'=>'bx-error-circle', 'color'=>'warning', 'title'=>'Preguntas sin responder',
               'desc'=>'Descubre qué te preguntan tus clientes que el bot no supo contestar, y mejora tu Knowledge Base para vender más.'],
              ['icon'=>'bx-message-alt-x', 'color'=>'danger', 'title'=>'Objeciones de venta',
               'desc'=>'Identifica los motivos por los que tus clientes dudan o no compran — precio, horarios, competencia.'],
              ['icon'=>'bx-smile', 'color'=>'success', 'title'=>'Sentimiento del cliente',
               'desc'=>'Mide la satisfacción real en cada conversación y detecta a tiempo si algo está fallando.'],
              ['icon'=>'bx-trending-up', 'color'=>'primary', 'title'=>'Temas más frecuentes',
               'desc'=>'Entiende qué le interesa a tu mercado para enfocar tu oferta y tus campañas.'],
            ];
            foreach ($benefits as $b): ?>
            <div class="col-md-6">
              <div class="card h-100">
                <div class="card-body d-flex gap-3">
                  <div class="avatar flex-shrink-0">
                    <span class="avatar-initial rounded bg-label-<?= $b['color'] ?>"><i class="bx <?= $b['icon'] ?> bx-sm"></i></span>
                  </div>
                  <div>
                    <h6 class="mb-1"><?= e($b['title']) ?></h6>
                    <small class="text-muted"><?= e($b['desc']) ?></small>
                  </div>
                </div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>

          <!-- CTA -->
          <div class="card">
            <div class="card-body d-flex align-items-center justify-content-between flex-wrap gap-3">
              <div>
                <h6 class="mb-1">¿Listo para conocer la voz de tus clientes?</h6>
                <small class="text-muted">Tu plan actual: <?= planBadge(currentTenant()['plan'] ?? 'starter') ?></small>
              </div>
              <a href="/pages/billing.php" class="btn btn-primary">
                <i class="bx bx-up-arrow-circle me-1"></i>Mejorar mi plan
              </a>
            </div>
          </div>
        </div>
      </div>

    </div></div>
    <?php renderFooter(); ?>
    </div></div></div>
    <?php
    exit;
}

$insights = apiGet('/reports/insights', ['days' => $days]);

$coverage   = $insights['coverage']   ?? ['total' => 0, 'analyzed' => 0];
$sentiment  = array_values(array_filter((array)($insights['sentiment']  ?? []), 'is_array'));
$kbGaps     = array_values(array_filter((array)($insights['kb_gaps']    ?? []), 'is_array'));
$topics     = array_values(array_filter((array)($insights['topics']     ?? []), 'is_array'));
$objections = array_values(array_filter((array)($insights['objections'] ?? []), 'is_array'));

// Mapear sentimiento → color/total
$sentMap = ['positivo' => 0, 'neutral' => 0, 'negativo' => 0];
foreach ($sentiment as $s) { $sentMap[$s['sentiment'] ?? 'neutral'] = (int)($s['total'] ?? 0); }
$sentTotal = array_sum($sentMap) ?: 1;

$pendingCount = max(0, (int)$coverage['total'] - (int)$coverage['analyzed']);

renderHead("Voz del cliente — {$days} días");
?>
<div class="layout-wrapper layout-content-navbar"><div class="layout-container">
<?php renderSidebar('insights'); ?>
<div class="layout-page"><?php renderNavbar('Voz del cliente'); ?>
<div class="content-wrapper"><div class="container-xxl flex-grow-1 container-p-y">

  <!-- Header -->
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
    <div>
      <h4 class="mb-1"><i class="bx bx-bulb text-warning me-2"></i>Voz del cliente</h4>
      <p class="text-muted mb-0">Qué dicen tus clientes · últimos <?= $days ?> días · <?= e(currentTenant()['name'] ?? '') ?></p>
    </div>
    <div class="d-flex gap-2 flex-wrap align-items-center">
      <?php foreach ([7=>'7d', 30=>'30d', 90=>'90d'] as $d => $label): ?>
        <a href="?days=<?= $d ?>" class="btn btn-sm <?= $days === $d ? 'btn-primary' : 'btn-outline-secondary' ?>"><?= $label ?></a>
      <?php endforeach; ?>
      <?php if ($pendingCount > 0): ?>
      <button class="btn btn-sm btn-outline-primary" id="btn-analyze" title="Analizar conversaciones pendientes">
        <i class="bx bx-brain me-1"></i>Analizar <?= $pendingCount ?> pendientes
      </button>
      <?php endif; ?>
    </div>
  </div>

  <?php if ((int)$coverage['analyzed'] === 0): ?>
  <!-- Estado vacío -->
  <div class="card">
    <div class="card-body text-center py-5">
      <i class="bx bx-bulb d-block mb-2 text-warning" style="font-size:3rem;opacity:.5"></i>
      <h5>Aún no hay conversaciones analizadas</h5>
      <p class="text-muted">El análisis se genera automáticamente al cerrar cada conversación.
        <?php if ($pendingCount > 0): ?><br>Tienes <?= $pendingCount ?> conversaciones listas para analizar ahora.<?php endif; ?>
      </p>
      <?php if ($pendingCount > 0): ?>
      <button class="btn btn-primary" id="btn-analyze-empty"><i class="bx bx-brain me-1"></i>Analizar ahora</button>
      <?php endif; ?>
    </div>
  </div>
  <?php else: ?>

  <div class="row g-3 mb-3">
    <!-- ── Sentimiento ──────────────────────────────────────────── -->
    <div class="col-md-4">
      <div class="card h-100">
        <div class="card-header"><h6 class="mb-0">Sentimiento de clientes</h6></div>
        <div class="card-body">
          <?php
          $sentDisplay = [
            'positivo' => ['😊', 'success', 'Positivo'],
            'neutral'  => ['😐', 'secondary', 'Neutral'],
            'negativo' => ['😞', 'danger', 'Negativo'],
          ];
          foreach ($sentDisplay as $key => [$emoji, $color, $label]):
            $val = $sentMap[$key];
            $pct = round($val / $sentTotal * 100);
          ?>
          <div class="mb-3">
            <div class="d-flex justify-content-between mb-1">
              <span><?= $emoji ?> <?= $label ?></span>
              <span class="fw-semibold"><?= $val ?> <small class="text-muted">(<?= $pct ?>%)</small></span>
            </div>
            <div class="progress" style="height:6px">
              <div class="progress-bar bg-<?= $color ?>" style="width:<?= $pct ?>%"></div>
            </div>
          </div>
          <?php endforeach; ?>
          <small class="text-muted"><?= (int)$coverage['analyzed'] ?> de <?= (int)$coverage['total'] ?> conversaciones analizadas</small>
        </div>
      </div>
    </div>

    <!-- ── Temas más frecuentes ─────────────────────────────────── -->
    <div class="col-md-8">
      <div class="card h-100">
        <div class="card-header"><h6 class="mb-0">Temas más mencionados</h6></div>
        <div class="card-body">
          <?php if (empty($topics)): ?>
            <p class="text-muted small mb-0">Sin temas detectados aún.</p>
          <?php else:
            $maxTopic = max(array_map(fn($t) => (int)$t['total'], $topics)) ?: 1;
            foreach ($topics as $t):
              $pct = round((int)$t['total'] / $maxTopic * 100);
          ?>
          <div class="mb-2">
            <div class="d-flex justify-content-between mb-1">
              <span class="text-capitalize" style="font-size:.85rem"><?= e($t['topic']) ?></span>
              <span class="badge bg-label-primary"><?= (int)$t['total'] ?></span>
            </div>
            <div class="progress" style="height:4px">
              <div class="progress-bar bg-primary" style="width:<?= $pct ?>%"></div>
            </div>
          </div>
          <?php endforeach; endif; ?>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-3">
    <!-- ── Gaps de conocimiento (lo más accionable) ─────────────── -->
    <div class="col-md-7">
      <div class="card h-100 border-start border-warning border-3">
        <div class="card-header d-flex align-items-center gap-2">
          <i class="bx bx-error-circle text-warning"></i>
          <h6 class="mb-0">Preguntas que el bot no supo responder</h6>
        </div>
        <div class="card-body">
          <?php if (empty($kbGaps)): ?>
            <div class="text-center py-4 text-muted">
              <i class="bx bx-check-circle text-success d-block mb-1" style="font-size:1.8rem"></i>
              <span class="small">El bot respondió todo. Sin gaps de conocimiento. 🎉</span>
            </div>
          <?php else: ?>
            <p class="text-muted small mb-3">Agrega estas respuestas a tu Knowledge Base para que el bot mejore:</p>
            <div class="list-group list-group-flush">
              <?php foreach ($kbGaps as $g): ?>
              <div class="list-group-item px-0 py-2 d-flex justify-content-between align-items-start gap-2">
                <span style="font-size:.85rem"><i class="bx bx-help-circle text-warning me-1"></i><?= e($g['question']) ?></span>
                <small class="text-muted text-nowrap"><?= formatDate($g['created_at'] ?? '', 'd/m') ?></small>
              </div>
              <?php endforeach; ?>
            </div>
            <a href="/pages/knowledge-base.php" class="btn btn-sm btn-outline-warning mt-3">
              <i class="bx bx-brain me-1"></i>Mejorar Knowledge Base
            </a>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- ── Objeciones ───────────────────────────────────────────── -->
    <div class="col-md-5">
      <div class="card h-100">
        <div class="card-header"><h6 class="mb-0"><i class="bx bx-message-alt-x text-danger me-1"></i>Objeciones de venta</h6></div>
        <div class="card-body">
          <?php if (empty($objections)): ?>
            <p class="text-muted small mb-0">Sin objeciones registradas.</p>
          <?php else: foreach ($objections as $o): ?>
          <div class="d-flex justify-content-between align-items-center mb-2">
            <span style="font-size:.85rem">💬 <?= e($o['objection']) ?></span>
            <span class="badge bg-label-danger"><?= (int)$o['total'] ?></span>
          </div>
          <?php endforeach; endif; ?>
        </div>
      </div>
    </div>
  </div>

  <?php endif; ?>

</div></div>
<?php renderFooter(); ?>
</div></div></div>

<script>
(function () {
  'use strict';
  async function runAnalysis(btn) {
    const original = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Analizando…';
    try {
      const res = await fetch('/api/insights-proxy.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'analyze', limit: 15 }),
      });
      const data = await res.json();
      if (typeof data.analyzed === 'number') {
        window.showToast?.(`${data.analyzed} conversaciones analizadas`, 'success');
        setTimeout(() => location.reload(), 800);
      } else {
        window.showToast?.(data.error || 'Error al analizar', 'error');
        btn.disabled = false; btn.innerHTML = original;
      }
    } catch {
      window.showToast?.('Error de red', 'error');
      btn.disabled = false; btn.innerHTML = original;
    }
  }
  document.getElementById('btn-analyze')?.addEventListener('click', e => runAnalysis(e.currentTarget));
  document.getElementById('btn-analyze-empty')?.addEventListener('click', e => runAnalysis(e.currentTarget));
})();
</script>
