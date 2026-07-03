<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/head.php';
require_once __DIR__ . '/../includes/sidebar.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/footer.php';

requireAuth();

$id = trim($_GET['id'] ?? '');
if (!$id) { header('Location: /pages/conversations.php'); exit; }

$conv = apiGet("/conversations/{$id}");
if (isset($conv['error']) || ($conv['_status'] ?? 200) === 404) {
    header('Location: /pages/conversations.php'); exit;
}

$msgs     = apiGet("/conversations/{$id}/messages");
$messages = array_values(array_filter((array)$msgs, 'is_array'));

// Separar mensajes por tipo
$chatMsgs  = [];  // user + assistant
$toolCalls = [];  // tool calls indexados por posición

foreach ($messages as $i => $m) {
    $role = $m['role'] ?? '';
    if ($role === 'tool' || (!empty($m['tool_name']) && $role === 'assistant')) {
        $toolCalls[$i] = $m;
    } else {
        $chatMsgs[$i] = $m;
    }
}

// Datos recolectados
$collected = $conv['collected_data'] ?? [];
$outcomeData = $conv['outcome_data'] ?? [];

// Estadísticas
$userTurns   = count(array_filter($messages, fn($m) => ($m['role']??'') === 'user'));
$agentTurns  = count(array_filter($messages, fn($m) => ($m['role']??'') === 'assistant'));
$toolCount   = count(array_filter($messages, fn($m) => !empty($m['tool_name'])));
$totalTokens = (int)array_sum(array_column($messages, 'tokens_used'));
$latencies   = array_filter(array_column($messages, 'latency_ms'));
$avgLatency  = $latencies ? round(array_sum($latencies) / count($latencies) / 1000, 1) : 0;

// Costo estimado (claude-sonnet-4: ~$3/1M input, $15/1M output — promedio ~$9/1M)
$model = $conv['agent_llm_model'] ?? 'claude-sonnet-4-20250514';
$costPer1M = str_contains($model, 'mini') ? 0.15 : (str_contains($model, 'sonnet') ? 9.0 : 3.0);
$estimatedCost = number_format($totalTokens / 1_000_000 * $costPer1M, 4);

// Tool icons
$toolIcons = [
    'save_lead'             => ['bx-user-check',     'success', 'Guardar lead'],
    'schedule_appointment'  => ['bx-calendar-check', 'primary', 'Agendar cita'],
    'check_availability'    => ['bx-calendar',       'info',    'Ver disponibilidad'],
    'transfer_to_human'     => ['bx-phone-call',     'warning', 'Transferir'],
    'triage_service'        => ['bx-first-aid',      'danger',  'Triaje dental'],
    'qualify_lead'          => ['bx-target-lock',    'warning', 'Calificar lead'],
    'book_session_series'   => ['bx-calendar-plus',  'primary', 'Serie de citas'],
    'end_call'              => ['bx-phone-off',       'secondary','Terminar llamada'],
];

$pageTitle = 'Conversación — ' . e($conv['contact_phone'] ?? $id);
renderHead($pageTitle);
?>

<style>
  /* ── Layout ─────────────────────────────────────────────── */
  .conv-grid { display: grid; grid-template-columns: 1fr 340px; gap: 1.25rem; align-items: start; }
  @media (max-width: 1100px) { .conv-grid { grid-template-columns: 1fr; } }

  /* ── Chat transcript ─────────────────────────────────────── */
  #chatBox {
    max-height: 72vh; overflow-y: auto;
    padding: 1.25rem; display: flex; flex-direction: column; gap: .75rem;
    scroll-behavior: smooth;
  }

  .msg-bubble {
    display: flex; gap: .6rem; align-items: flex-end;
    max-width: 82%;
  }
  .msg-bubble.agent { align-self: flex-start; }
  .msg-bubble.user  { align-self: flex-end; flex-direction: row-reverse; }

  .bubble-body {
    padding: .65rem .9rem; border-radius: 1rem; font-size: .875rem; line-height: 1.5;
    position: relative;
  }
  .msg-bubble.agent .bubble-body {
    background: #f0f0ff; border-bottom-left-radius: .25rem;
    color: #3a3b5e;
  }
  .msg-bubble.user .bubble-body {
    background: #696cff; color: #fff; border-bottom-right-radius: .25rem;
  }
  html.dark-style .msg-bubble.agent .bubble-body { background: #2a2b40; color: #d0d3e8; }

  .bubble-meta { font-size: .7rem; color: #8592a3; margin-top: .25rem; }
  .msg-bubble.user .bubble-meta { text-align: right; }

  /* ── Tool call cards ─────────────────────────────────────── */
  .tool-call-card {
    align-self: center; width: 100%; max-width: 420px;
    border-radius: .75rem; padding: .6rem .9rem;
    border: 1px solid #e5e7eb; background: #fafafa;
    font-size: .8rem;
  }
  html.dark-style .tool-call-card { background: #232333; border-color: #3b3e5e; }
  .tool-call-card .tc-header { display: flex; align-items: center; gap: .5rem; cursor: pointer; }
  .tool-call-card .tc-body { margin-top: .5rem; display: none; }
  .tool-call-card .tc-body.show { display: block; }
  .tool-call-card pre {
    margin: 0; padding: .5rem .75rem; border-radius: .4rem;
    font-size: .72rem; background: #f0f0ff; max-height: 120px; overflow-y: auto;
    white-space: pre-wrap; word-break: break-all;
  }
  html.dark-style .tool-call-card pre { background: #1e1e2e; color: #cdd6f4; }

  /* ── Info panel ──────────────────────────────────────────── */
  .info-panel { position: sticky; top: 80px; }
  .info-section { margin-bottom: 1rem; }
  .info-section-title {
    font-size: .68rem; font-weight: 700; letter-spacing: .08em;
    text-transform: uppercase; color: #8592a3; margin-bottom: .5rem;
  }
  .info-row { display: flex; justify-content: space-between; align-items: center; padding: .3rem 0; border-bottom: 1px solid #f0f0f0; font-size: .82rem; }
  html.dark-style .info-row { border-color: #2a2b40; }
  .info-row:last-child { border: none; }
  .info-row .label { color: #8592a3; }

  /* ── Collected data chips ────────────────────────────────── */
  .collected-chip {
    background: #f0f0ff; border-radius: .4rem;
    padding: .3rem .6rem; font-size: .78rem; margin-bottom: .3rem;
    display: flex; justify-content: space-between; gap: .5rem;
  }
  html.dark-style .collected-chip { background: #2a2b40; }
  .collected-chip .ck { color: #8592a3; font-size: .72rem; }
  .collected-chip .cv { font-weight: 500; word-break: break-all; }

  /* ── Recording player ────────────────────────────────────── */
  .recording-player { background: #f8f9ff; border-radius: .5rem; padding: .75rem; }
  html.dark-style .recording-player { background: #232333; }
  .recording-player audio { width: 100%; height: 36px; }

  /* ── Outcome banner ──────────────────────────────────────── */
  .outcome-banner {
    border-radius: .5rem; padding: .6rem 1rem;
    display: flex; align-items: center; gap: .6rem; font-size: .85rem; font-weight: 500;
  }
</style>

<div class="layout-wrapper layout-content-navbar">
<div class="layout-container">
<?php renderSidebar('conversations'); ?>
<div class="layout-page">
<?php renderNavbar('Conversación'); ?>
<div class="content-wrapper">
<div class="container-xxl flex-grow-1 container-p-y">

  <!-- Breadcrumb -->
  <div class="d-flex align-items-center gap-3 mb-4 flex-wrap">
    <a href="/pages/conversations.php" class="btn btn-sm btn-outline-secondary">
      <i class="bx bx-arrow-back me-1"></i>Conversaciones
    </a>
    <div class="me-auto">
      <h5 class="mb-0"><?= e($conv['contact_name'] ?? $conv['contact_phone'] ?? 'Sin nombre') ?></h5>
      <small class="text-muted">
        <?= channelIcon($conv['channel'] ?? '') ?>
        <?= e($conv['contact_phone'] ?? '—') ?> &middot;
        <?= formatDate($conv['started_at'] ?? '', 'd/m/Y H:i') ?>
      </small>
    </div>
    <?php
    $outcomeColors = [
      'appointment_scheduled' => 'success',
      'lead_saved'            => 'info',
      'transferred'           => 'warning',
      'completed'             => 'primary',
      'no_answer'             => 'secondary',
      'failed'                => 'danger',
    ];
    if ($conv['outcome'] ?? ''):
      $oc = $outcomeColors[$conv['outcome']] ?? 'secondary';
    ?>
    <span class="badge bg-label-<?= $oc ?> fs-6 px-3 py-2">
      <?= e(str_replace('_', ' ', $conv['outcome'])) ?>
    </span>
    <?php endif; ?>
  </div>

  <div class="conv-grid">

    <!-- ── TRANSCRIPCIÓN ──────────────────────────────────── -->
    <div>
      <!-- KPIs rápidos -->
      <div class="row g-2 mb-3">
        <?php $kpis = [
          ['val'=>$userTurns,         'label'=>'Turnos usuario',    'icon'=>'bx-user',       'c'=>'secondary'],
          ['val'=>$agentTurns,        'label'=>'Resp. agente',      'icon'=>'bx-bot',        'c'=>'primary'],
          ['val'=>$toolCount,         'label'=>'Herramientas',      'icon'=>'bx-wrench',     'c'=>'warning'],
          ['val'=>$avgLatency.'s',    'label'=>'Latencia prom.',    'icon'=>'bx-time-five',  'c'=>'info'],
        ]; foreach ($kpis as $k): ?>
        <div class="col-6 col-sm-3">
          <div class="card py-2">
            <div class="card-body p-2 d-flex align-items-center gap-2">
              <span class="avatar avatar-sm"><span class="avatar-initial rounded bg-label-<?= $k['c'] ?>">
                <i class="bx <?= $k['icon'] ?> small"></i>
              </span></span>
              <div>
                <div class="fw-bold small"><?= $k['val'] ?></div>
                <div style="font-size:.68rem" class="text-muted"><?= $k['label'] ?></div>
              </div>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- Chat -->
      <div class="card">
        <div class="card-header py-2 d-flex align-items-center justify-content-between">
          <h6 class="mb-0"><i class="bx bx-message-detail me-2"></i>Transcripción</h6>
          <small class="text-muted">
            <?= count($messages) ?> mensajes
            <?php if ($toolCount): ?> · <?= $toolCount ?> tool calls<?php endif; ?>
          </small>
        </div>
        <div id="chatBox">
          <?php if (empty($messages)): ?>
            <div class="text-center text-muted py-5">
              <i class="bx bx-conversation bx-lg d-block mb-2 opacity-25"></i>
              Sin mensajes registrados
            </div>
          <?php else:
            // Mezclar mensajes y tool calls en orden cronológico
            $allItems = $messages;
            foreach ($allItems as $i => $m):
              $role    = $m['role'] ?? '';
              $content = trim($m['content'] ?? '');
              $time    = formatDate($m['created_at'] ?? '', 'H:i:s');
              $latMs   = (int)($m['latency_ms'] ?? 0);
              $toolName = $m['tool_name'] ?? '';
          ?>

          <?php if ($role === 'user' && $content): ?>
            <!-- Usuario -->
            <div class="msg-bubble user">
              <div>
                <div class="bubble-body"><?= nl2br(e($content)) ?></div>
                <div class="bubble-meta"><?= $time ?></div>
              </div>
              <div class="avatar avatar-sm flex-shrink-0">
                <span class="avatar-initial rounded-circle bg-label-secondary" style="font-size:.7rem">U</span>
              </div>
            </div>

          <?php elseif ($role === 'assistant' && !$toolName && $content): ?>
            <!-- Agente -->
            <div class="msg-bubble agent">
              <div class="avatar avatar-sm flex-shrink-0">
                <span class="avatar-initial rounded-circle bg-label-primary" style="font-size:.7rem">A</span>
              </div>
              <div>
                <div class="bubble-body"><?= nl2br(e($content)) ?></div>
                <div class="bubble-meta">
                  Agente · <?= $time ?>
                  <?php if ($latMs): ?> · <span title="Latencia IA"><?= round($latMs/1000,1) ?>s</span><?php endif; ?>
                  <?php if ($m['tokens_used'] ?? 0): ?> · <?= number_format($m['tokens_used']) ?> tok<?php endif; ?>
                </div>
              </div>
            </div>

          <?php elseif ($toolName || $role === 'tool'): ?>
            <!-- Tool call -->
            <?php
            [$tIcon, $tColor, $tLabel] = $toolIcons[$toolName] ?? ['bx-code-block', 'secondary', $toolName];
            $inputJson  = is_string($m['tool_input']  ?? '') ? $m['tool_input']  : json_encode($m['tool_input']  ?? null, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $outputJson = is_string($m['tool_output'] ?? '') ? $m['tool_output'] : json_encode($m['tool_output'] ?? null, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $outputData = json_decode($outputJson, true);
            $outputSuccess = ($outputData['success'] ?? true) !== false;
            $tcId = 'tc-' . $i;
            ?>
            <div class="tool-call-card" style="margin: 0 auto">
              <div class="tc-header" onclick="document.getElementById('<?= $tcId ?>').classList.toggle('show')">
                <span class="avatar avatar-xs">
                  <span class="avatar-initial rounded bg-label-<?= $tColor ?>">
                    <i class="bx <?= $tIcon ?>" style="font-size:.75rem"></i>
                  </span>
                </span>
                <span class="fw-semibold text-<?= $tColor ?>"><?= e($tLabel) ?></span>
                <?php if (!$outputSuccess): ?>
                  <span class="badge bg-label-danger ms-1" style="font-size:.65rem">Error</span>
                <?php else: ?>
                  <span class="badge bg-label-success ms-1" style="font-size:.65rem">OK</span>
                <?php endif; ?>
                <i class="bx bx-chevron-down ms-auto text-muted"></i>
                <small class="text-muted ms-1"><?= $time ?></small>
              </div>
              <div class="tc-body" id="<?= $tcId ?>">
                <?php if ($inputJson && $inputJson !== 'null'): ?>
                <div class="mt-2">
                  <div class="text-muted mb-1" style="font-size:.68rem;font-weight:700;letter-spacing:.05em">INPUT</div>
                  <pre><?= e($inputJson) ?></pre>
                </div>
                <?php endif; ?>
                <?php if ($outputJson && $outputJson !== 'null'): ?>
                <div class="mt-2">
                  <div class="text-muted mb-1" style="font-size:.68rem;font-weight:700;letter-spacing:.05em">OUTPUT</div>
                  <pre><?= e($outputJson) ?></pre>
                </div>
                <?php endif; ?>
              </div>
            </div>

          <?php endif; ?>
          <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div><!-- /transcript col -->

    <!-- ── PANEL LATERAL ─────────────────────────────────── -->
    <div class="info-panel">

      <!-- Grabación -->
      <?php if (!empty($conv['recording_url'])): ?>
      <div class="card mb-3">
        <div class="card-body p-3">
          <div class="info-section-title"><i class="bx bx-microphone me-1"></i>Grabación</div>
          <div class="recording-player">
            <audio controls preload="none">
              <source src="<?= e($conv['recording_url']) ?>" type="audio/mpeg">
              Tu navegador no soporta audio.
            </audio>
          </div>
          <a href="<?= e($conv['recording_url']) ?>" class="btn btn-xs btn-outline-secondary mt-2 w-100"
             download target="_blank">
            <i class="bx bx-download me-1"></i>Descargar grabación
          </a>
        </div>
      </div>
      <?php endif; ?>

      <!-- Info de la conversación -->
      <div class="card mb-3">
        <div class="card-body p-3">
          <div class="info-section-title"><i class="bx bx-info-circle me-1"></i>Detalle</div>
          <div class="info-row">
            <span class="label">Agente</span>
            <span class="fw-semibold"><?= e($conv['agent_name'] ?? '—') ?></span>
          </div>
          <div class="info-row">
            <span class="label">Canal</span>
            <span><?= channelIcon($conv['channel'] ?? '') ?> <?= ucfirst(e($conv['channel'] ?? '—')) ?></span>
          </div>
          <div class="info-row">
            <span class="label">Duración</span>
            <span><?= $conv['duration_secs'] ? formatMins((int)$conv['duration_secs']) : '—' ?></span>
          </div>
          <div class="info-row">
            <span class="label">Estado</span>
            <?= statusBadge($conv['status'] ?? '') ?>
          </div>
          <?php if ($conv['outcome'] ?? ''): ?>
          <div class="info-row">
            <span class="label">Resultado</span>
            <span class="badge bg-label-<?= $outcomeColors[$conv['outcome']] ?? 'secondary' ?>">
              <?= e(str_replace('_', ' ', $conv['outcome'])) ?>
            </span>
          </div>
          <?php endif; ?>
          <?php if ($conv['assigned_name'] ?? ''): ?>
          <div class="info-row">
            <span class="label">Asignado a</span>
            <span class="badge bg-label-primary"><?= e($conv['assigned_name']) ?></span>
          </div>
          <?php endif; ?>
          <div class="info-row">
            <span class="label">Inicio</span>
            <small><?= formatDate($conv['started_at'] ?? '', 'd/m/Y H:i') ?></small>
          </div>
          <?php if ($conv['ended_at'] ?? ''): ?>
          <div class="info-row">
            <span class="label">Fin</span>
            <small><?= formatDate($conv['ended_at'], 'd/m/Y H:i') ?></small>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Datos recolectados -->
      <?php
      $displayCollected = array_filter(array_merge($collected, $outcomeData), fn($v) => $v !== null && $v !== '' && !is_array($v));
      $labelMap = [
        'name'=>'Nombre','phone'=>'Teléfono','email'=>'Email','intent'=>'Intención',
        'notes'=>'Notas','service'=>'Servicio','specialty'=>'Especialidad',
        'appointmentId'=>'ID Cita','startTime'=>'Cita agendada',
      ];
      if (!empty($displayCollected)):
      ?>
      <div class="card mb-3">
        <div class="card-body p-3">
          <div class="info-section-title"><i class="bx bx-data me-1"></i>Datos recolectados</div>
          <?php foreach ($displayCollected as $k => $v): if ($k === '_status') continue; ?>
          <div class="collected-chip">
            <span class="ck"><?= e($labelMap[$k] ?? $k) ?></span>
            <span class="cv"><?= e(is_string($v) && strlen($v) > 60 ? substr($v, 0, 60).'…' : $v) ?></span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- Uso y costo -->
      <div class="card mb-3">
        <div class="card-body p-3">
          <div class="info-section-title"><i class="bx bx-chip me-1"></i>Uso de IA</div>
          <div class="info-row">
            <span class="label">Tokens totales</span>
            <span class="fw-semibold"><?= number_format($totalTokens) ?></span>
          </div>
          <div class="info-row">
            <span class="label">Costo estimado</span>
            <span class="fw-semibold text-info">~$<?= $estimatedCost ?> USD</span>
          </div>
          <div class="info-row">
            <span class="label">Latencia prom.</span>
            <span><?= $avgLatency ?>s</span>
          </div>
          <div class="info-row">
            <span class="label">Tool calls</span>
            <span><?= $toolCount ?></span>
          </div>
          <?php if ($model): ?>
          <div class="info-row">
            <span class="label">Modelo</span>
            <small class="text-muted font-monospace"><?= e(str_replace('claude-','cl-',str_replace('gpt-','',$model))) ?></small>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Tool calls resumen -->
      <?php
      $usedTools = [];
      foreach ($messages as $m) {
        if ($tn = $m['tool_name'] ?? '') {
          $usedTools[$tn] = ($usedTools[$tn] ?? 0) + 1;
        }
      }
      if (!empty($usedTools)):
      ?>
      <div class="card">
        <div class="card-body p-3">
          <div class="info-section-title"><i class="bx bx-wrench me-1"></i>Herramientas usadas</div>
          <?php foreach ($usedTools as $tn => $count):
            [$tIcon, $tColor, $tLabel] = $toolIcons[$tn] ?? ['bx-code-block','secondary',$tn];
          ?>
          <div class="d-flex align-items-center gap-2 mb-2">
            <span class="avatar avatar-xs">
              <span class="avatar-initial rounded bg-label-<?= $tColor ?>">
                <i class="bx <?= $tIcon ?>" style="font-size:.7rem"></i>
              </span>
            </span>
            <span class="small flex-grow-1"><?= e($tLabel) ?></span>
            <?php if ($count > 1): ?>
              <span class="badge bg-label-secondary"><?= $count ?>x</span>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

    </div><!-- /info panel -->
  </div><!-- /conv-grid -->

</div>
<?php renderFooter(); ?>
<script>
  // Auto-scroll transcript al final
  const box = document.getElementById('chatBox');
  if (box) box.scrollTop = box.scrollHeight;
</script>
