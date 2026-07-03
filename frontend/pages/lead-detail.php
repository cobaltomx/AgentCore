<?php
/**
 * Ficha del CONTACTO / CLIENTE — historial unificado (citas + pedidos +
 * conversaciones) enlazado por lead_id. Completa el enlace "Ver detalle" de
 * leads.php (Fase 0.3 del customer spine). Reusa la entidad `leads`.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/head.php';
require_once __DIR__ . '/../includes/sidebar.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/footer.php';

requireAuth();

$id = trim($_GET['id'] ?? '');
if ($id === '' || !isValidUuid($id)) { header('Location: /pages/leads.php'); exit; }

$data = apiGet('/leads/' . rawurlencode($id));
if (!empty($data['error']) || empty($data['lead'])) {
    renderHead('Contacto no encontrado');
    echo '<div class="container-xxl flex-grow-1 container-p-y"><div class="alert alert-warning">Contacto no encontrado. <a href="/pages/leads.php">Volver</a></div></div>';
    renderFooter(); exit;
}

$lead   = $data['lead'];
$stats  = $data['stats']  ?? [];
$appts  = $data['appointments'] ?? [];
$orders = $data['orders'] ?? [];
$convs  = $data['conversations'] ?? [];
$custom = is_array($lead['custom_data'] ?? null) ? $lead['custom_data'] : [];

// Sustantivo por giro (Paciente / Cliente / Contacto).
$ind = tenantIndustry();
$noun = in_array($ind, ['dental','consultorio','clinica','salud','medico'], true) ? 'Paciente' : 'Cliente';

$money = fn($cents) => '$' . number_format(((int)$cents) / 100, 0);
$initials = strtoupper(substr($lead['name'] ?: ($lead['phone'] ?: 'C'), 0, 1));

renderHead('Ficha de ' . $noun);
?>
<div class="layout-wrapper layout-content-navbar"><div class="layout-container">
<?php renderSidebar('leads'); ?>
<div class="layout-page"><?php renderNavbar('Ficha de ' . $noun); ?>
<div class="content-wrapper"><div class="container-xxl flex-grow-1 container-p-y">

  <a href="/pages/leads.php" class="btn btn-sm btn-outline-secondary mb-3"><i class="bx bx-arrow-back me-1"></i>Volver a <?= e($noun) ?>s</a>

  <!-- ── Encabezado del contacto ─────────────────────────────── -->
  <div class="card mb-4"><div class="card-body">
    <div class="d-flex align-items-center gap-3 flex-wrap">
      <span class="avatar-initial rounded-circle bg-label-primary" style="width:56px;height:56px;display:flex;align-items:center;justify-content:center;font-size:1.4rem"><?= e($initials) ?></span>
      <div class="flex-grow-1" style="min-width:200px">
        <h4 class="mb-0"><?= e($lead['name'] ?: 'Sin nombre') ?></h4>
        <div class="text-muted">
          <?php if (!empty($lead['phone'])): ?><i class="bx bx-phone me-1"></i><?= e($lead['phone']) ?><?php endif; ?>
          <?php if (!empty($lead['email'])): ?><span class="ms-3"><i class="bx bx-envelope me-1"></i><?= e($lead['email']) ?></span><?php endif; ?>
        </div>
        <div class="mt-1">
          <?= statusBadge($lead['status'] ?? 'new') ?>
          <?php if (!empty($custom['is_urgency'])): ?><span class="badge bg-label-danger ms-1"><i class="bx bx-error-circle me-1"></i>Urgencia</span><?php endif; ?>
          <?php if (!empty($lead['assigned_name'])): ?><span class="badge bg-label-info ms-1"><i class="bx bx-user me-1"></i><?= e($lead['assigned_name']) ?></span><?php endif; ?>
          <?php if (!empty($lead['source_channel'])): ?><span class="badge bg-label-secondary ms-1"><?= e($lead['source_channel']) ?></span><?php endif; ?>
        </div>
      </div>
      <div class="text-end">
        <small class="text-muted d-block">Cliente desde</small>
        <span class="fw-semibold"><?= e(formatDate($lead['created_at'] ?? '', 'd/m/Y')) ?></span>
      </div>
    </div>
  </div></div>

  <!-- ── KPIs del cliente ────────────────────────────────────── -->
  <div class="row g-3 mb-4">
    <?php
    $kpis = [
      ['l'=>'Visitas',        'v'=>(int)($stats['visits']??0),      'sub'=>(int)($stats['appts_total']??0).' citas','i'=>'bx-calendar-check','c'=>'success'],
      ['l'=>'Valor (LTV)',    'v'=>$money($stats['total_spent_cents']??0), 'sub'=>(int)($stats['orders_total']??0).' pedidos','i'=>'bx-coin','c'=>'primary'],
      ['l'=>'No-shows',       'v'=>(int)($stats['no_shows']??0),    'sub'=>(int)($stats['cancels']??0).' cancelaciones','i'=>'bx-user-x','c'=>((int)($stats['no_shows']??0)>0?'danger':'secondary')],
      ['l'=>'Conversaciones', 'v'=>(int)($stats['convs_total']??0), 'sub'=>'interacciones','i'=>'bx-conversation','c'=>'info'],
    ];
    foreach ($kpis as $k): ?>
    <div class="col-6 col-xl-3"><div class="card h-100"><div class="card-body py-3">
      <div class="d-flex align-items-center gap-2 mb-1">
        <span class="avatar-initial rounded bg-label-<?= $k['c'] ?> p-2"><i class="bx <?= $k['i'] ?>"></i></span>
        <div class="h4 mb-0"><?= e($k['v']) ?></div>
      </div>
      <div class="fw-semibold small"><?= e($k['l']) ?></div>
      <div class="text-muted" style="font-size:.72rem"><?= e($k['sub']) ?></div>
    </div></div></div>
    <?php endforeach; ?>
  </div>

  <div class="row g-4">
    <!-- ── Ficha / notas / preferencias ──────────────────────── -->
    <div class="col-lg-4">
      <div class="card h-100">
        <div class="card-header py-2"><h6 class="mb-0"><i class="bx bx-id-card me-1 text-primary"></i>Ficha</h6></div>
        <div class="card-body">
          <?php if (!empty($lead['notes'])): ?>
            <div class="mb-3"><small class="text-muted d-block mb-1">Notas</small><div><?= nl2br(e($lead['notes'])) ?></div></div>
          <?php endif; ?>
          <?php
            // Mostrar campos útiles de custom_data (preferencias, alergias, intención…)
            $labels = ['intent'=>'Intención','urgency_reason'=>'Motivo de urgencia','preferences'=>'Preferencias','allergies'=>'Alergias','notes'=>'Notas'];
            $shown = false;
            foreach ($labels as $key=>$lbl):
              if (empty($custom[$key])) continue; $shown = true;
              $val = is_array($custom[$key]) ? implode(', ', $custom[$key]) : $custom[$key]; ?>
            <div class="mb-2"><small class="text-muted d-block"><?= e($lbl) ?></small><div><?= e($val) ?></div></div>
          <?php endforeach; ?>
          <?php if (!$shown && empty($lead['notes'])): ?>
            <div class="text-muted small text-center py-3"><i class="bx bx-info-circle me-1"></i>Sin notas ni preferencias aún. Se irán llenando con cada interacción.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- ── Historial unificado ───────────────────────────────── -->
    <div class="col-lg-8">
      <div class="card">
        <div class="card-header py-2"><ul class="nav nav-tabs card-header-tabs" role="tablist">
          <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#t-appts">Citas (<?= count($appts) ?>)</button></li>
          <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#t-orders">Pedidos (<?= count($orders) ?>)</button></li>
          <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#t-convs">Conversaciones (<?= count($convs) ?>)</button></li>
        </ul></div>
        <div class="card-body tab-content">
          <!-- Citas -->
          <div class="tab-pane fade show active" id="t-appts">
            <?php if (!$appts): ?><div class="text-muted text-center py-4">Sin citas registradas.</div>
            <?php else: foreach ($appts as $a):
              $cs = $a['confirmation_status'] ?? 'pending';
              $sc = $a['status']==='completed'?'success':($a['status']==='no_show'?'danger':($a['status']==='cancelled'?'secondary':'warning')); ?>
            <div class="d-flex align-items-center gap-2 py-2 border-bottom">
              <i class="bx bx-calendar text-muted"></i>
              <div class="flex-grow-1">
                <div class="fw-semibold small"><?= e(formatDate($a['scheduled_at'] ?? '', 'd/m/Y H:i')) ?><?php if ($a['service']): ?> · <?= e($a['service']) ?><?php endif; ?></div>
                <?php if ($a['doctor']): ?><small class="text-muted"><?= e($a['doctor']) ?></small><?php endif; ?>
              </div>
              <span class="badge bg-label-<?= $sc ?>"><?= e($a['status']) ?></span>
            </div>
            <?php endforeach; endif; ?>
          </div>
          <!-- Pedidos -->
          <div class="tab-pane fade" id="t-orders">
            <?php if (!$orders): ?><div class="text-muted text-center py-4">Sin pedidos registrados.</div>
            <?php else: foreach ($orders as $o):
              $paid = !empty($o['paid_at']); $oc = $paid?'success':($o['status']==='cancelled'?'danger':'warning'); ?>
            <div class="d-flex align-items-center gap-2 py-2 border-bottom">
              <i class="bx bx-cart text-muted"></i>
              <div class="flex-grow-1"><div class="fw-semibold small"><?= e(formatDate($o['created_at'] ?? '', 'd/m/Y H:i')) ?></div><small class="text-muted"><?= e($o['channel'] ?? '') ?></small></div>
              <span class="fw-semibold me-2"><?= e($money($o['total_cents'] ?? 0)) ?> <?= e(strtoupper($o['currency'] ?? 'MXN')) ?></span>
              <span class="badge bg-label-<?= $oc ?>"><?= $paid?'pagado':e($o['status']) ?></span>
            </div>
            <?php endforeach; endif; ?>
          </div>
          <!-- Conversaciones -->
          <div class="tab-pane fade" id="t-convs">
            <?php if (!$convs): ?><div class="text-muted text-center py-4">Sin conversaciones registradas.</div>
            <?php else: foreach ($convs as $cv): ?>
            <div class="d-flex align-items-center gap-2 py-2 border-bottom">
              <i class="bx <?= ($cv['channel']??'')==='voice'?'bx-phone':(($cv['channel']??'')==='whatsapp'?'bxl-whatsapp':'bx-globe') ?> text-muted"></i>
              <div class="flex-grow-1" style="min-width:0">
                <div class="fw-semibold small"><?= e(formatDate($cv['started_at'] ?? '', 'd/m/Y H:i')) ?> · <?= e($cv['channel'] ?? '') ?></div>
                <?php if (!empty($cv['summary'])): ?><small class="text-muted d-block text-truncate" style="max-width:380px"><?= e($cv['summary']) ?></small><?php endif; ?>
              </div>
              <a href="/pages/conversation-detail.php?id=<?= e($cv['id']) ?>" class="btn btn-sm btn-outline-secondary py-0 px-1"><i class="bx bx-show"></i></a>
            </div>
            <?php endforeach; endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>

</div><?php renderFooter(); ?>
</div></div></div>
