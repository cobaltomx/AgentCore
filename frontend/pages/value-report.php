<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/head.php';
require_once __DIR__ . '/../includes/sidebar.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/footer.php';

requireAuth();

// El reporte muestra datos financieros del negocio → solo admins
if (!isAdmin()) {
    header('Location: /index.php');
    exit;
}

$days = (int)($_GET['days'] ?? 30);
$days = in_array($days, [7, 14, 30, 60, 90]) ? $days : 30;

$report = apiGet('/reports/value', ['days' => $days]);

$currency = $report['currency'] ?? 'MXN';
$metrics  = $report['metrics']  ?? [];
$value    = $report['value']    ?? [];
$config   = $report['config']   ?? [];

// Helper de formato de dinero
function money(float $n, string $cur = 'MXN'): string {
    return '$' . number_format($n, 0) . ' ' . $cur;
}
// Helper de badge de cambio %
function changeBadge(int $pct): string {
    if ($pct > 0)  return '<span class="badge bg-label-success"><i class="bx bx-up-arrow-alt"></i>' . $pct . '%</span>';
    if ($pct < 0)  return '<span class="badge bg-label-danger"><i class="bx bx-down-arrow-alt"></i>' . abs($pct) . '%</span>';
    return '<span class="badge bg-label-secondary">0%</span>';
}

$valueTotal = (float)($value['total'] ?? 0);
$tenantName = currentTenant()['name'] ?? '';

renderHead("Reporte de Valor — {$days} días");
?>
<div class="layout-wrapper layout-content-navbar"><div class="layout-container">
<?php renderSidebar('value-report'); ?>
<div class="layout-page"><?php renderNavbar('Reporte de Valor'); ?>
<div class="content-wrapper"><div class="container-xxl flex-grow-1 container-p-y">

  <!-- Header + filtro de período -->
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
    <div>
      <h4 class="mb-1"><i class="bx bx-trophy text-warning me-2"></i>Reporte de Valor</h4>
      <p class="text-muted mb-0">Lo que tu agente IA generó · últimos <?= $days ?> días · <?= e($tenantName) ?></p>
    </div>
    <div class="d-flex gap-2 flex-wrap align-items-center">
      <?php foreach ([7=>'7d', 30=>'30d', 90=>'90d'] as $d => $label): ?>
        <a href="?days=<?= $d ?>"
           class="btn btn-sm <?= $days === $d ? 'btn-primary' : 'btn-outline-secondary' ?>"><?= $label ?></a>
      <?php endforeach; ?>
      <button class="btn btn-sm btn-outline-secondary" id="btn-config-value" title="Ajustar valores de cálculo">
        <i class="bx bx-cog me-1"></i>Ajustar
      </button>
    </div>
  </div>

  <!-- ── Banner de valor total ─────────────────────────────────── -->
  <div class="row mb-4">
    <div class="col-12">
      <div class="card border-0" style="background:linear-gradient(135deg,#696cff 0%,#9155fd 100%)">
        <div class="card-body d-flex align-items-center justify-content-between flex-wrap gap-3 py-4">
          <div class="text-white">
            <div class="opacity-75 mb-1" style="font-size:.9rem">Valor estimado generado por tu bot</div>
            <div class="display-5 fw-bold mb-0"><?= money($valueTotal, $currency) ?></div>
            <div class="opacity-75 mt-1" style="font-size:.85rem">
              <i class="bx bx-info-circle"></i> Estimación conservadora basada en tu actividad y configuración
            </div>
          </div>
          <div class="text-white text-end">
            <div style="font-size:3.5rem;line-height:1">🚀</div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- ── Desglose del valor ────────────────────────────────────── -->
  <div class="row g-3 mb-4">
    <?php
    $valueCards = [
      ['label'=>'Citas agendadas',        'amount'=>$value['appointments'] ?? 0, 'icon'=>'bx-calendar-check', 'color'=>'success',
       'desc'=>($metrics['appointments']['value'] ?? 0).' citas × ticket promedio'],
      ['label'=>'Pipeline de leads',      'amount'=>$value['leads'] ?? 0,        'icon'=>'bx-user-plus',     'color'=>'info',
       'desc'=>($metrics['leads']['value'] ?? 0).' leads capturados'],
      ['label'=>'Atención fuera de horario','amount'=>$value['after_hours'] ?? 0,'icon'=>'bx-moon',          'color'=>'warning',
       'desc'=>($metrics['after_hours']['value'] ?? 0).' contactos que se habrían perdido'],
      ['label'=>'Tiempo de personal ahorrado','amount'=>$value['time_saved'] ?? 0,'icon'=>'bx-time-five',    'color'=>'primary',
       'desc'=>($metrics['hours_saved']['value'] ?? 0).' horas liberadas a tu equipo'],
    ];
    foreach ($valueCards as $vc): ?>
    <div class="col-sm-6 col-xl-3">
      <div class="card h-100">
        <div class="card-body">
          <div class="d-flex align-items-start justify-content-between mb-2">
            <div class="avatar">
              <span class="avatar-initial rounded bg-label-<?= $vc['color'] ?>">
                <i class="bx <?= $vc['icon'] ?> bx-sm"></i>
              </span>
            </div>
          </div>
          <h4 class="mb-1"><?= money((float)$vc['amount'], $currency) ?></h4>
          <div class="fw-semibold mb-1" style="font-size:.85rem"><?= e($vc['label']) ?></div>
          <small class="text-muted"><?= e($vc['desc']) ?></small>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- ── Reducción de no-shows ─────────────────────────────────── -->
  <?php
    $confirmations = (int)($metrics['confirmations']['value'] ?? 0);
    $earlyCancels  = (int)($metrics['early_cancels']['value'] ?? 0);
    $noShowValue   = (float)($value['no_show_recovery'] ?? 0);
    $totalConfirm  = $confirmations + $earlyCancels;
  ?>
  <div class="row mb-4">
    <div class="col-12">
      <div class="card border-start border-success border-3">
        <div class="card-body d-flex align-items-center justify-content-between flex-wrap gap-3 py-3">
          <div class="d-flex align-items-center gap-3">
            <div class="avatar avatar-md">
              <span class="avatar-initial rounded bg-label-success">
                <i class="bx bx-calendar-check" style="font-size:1.4rem"></i>
              </span>
            </div>
            <div>
              <div class="fw-semibold mb-1">Reducción de no-shows</div>
              <small class="text-muted">
                El bot pide confirmación por WhatsApp antes de cada cita. Las que se
                cancelan a tiempo liberan el horario para otro paciente.
              </small>
            </div>
          </div>
          <div class="d-flex gap-4 align-items-center">
            <div class="text-center">
              <div class="h4 mb-0 text-success"><?= $confirmations ?></div>
              <small class="text-muted">confirmadas</small>
            </div>
            <div class="text-center">
              <div class="h4 mb-0 text-warning"><?= $earlyCancels ?></div>
              <small class="text-muted">canceladas a tiempo</small>
            </div>
            <div class="text-center">
              <div class="h4 mb-0 text-primary"><?= money($noShowValue, $currency) ?></div>
              <small class="text-muted">horarios recuperados</small>
            </div>
          </div>
        </div>
        <?php if ($totalConfirm === 0): ?>
        <div class="card-footer bg-label-secondary py-2">
          <small class="text-muted">
            <i class="bx bx-info-circle"></i>
            Aún no hay confirmaciones registradas en este período. Aparecerán
            automáticamente cuando los pacientes respondan a los recordatorios de cita.
          </small>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ── Actividad del bot (con comparativa) ───────────────────── -->
  <div class="row g-3">
    <div class="col-12">
      <div class="card">
        <div class="card-header d-flex align-items-center justify-content-between">
          <h5 class="card-title mb-0">Actividad del agente</h5>
          <small class="text-muted">vs. <?= $days ?> días previos</small>
        </div>
        <div class="card-body">
          <div class="row g-4">
            <?php
            $activity = [
              ['k'=>'convs',        'label'=>'Conversaciones atendidas', 'icon'=>'bx-conversation', 'suffix'=>''],
              ['k'=>'after_hours',  'label'=>'Fuera de horario',         'icon'=>'bx-moon',         'suffix'=>''],
              ['k'=>'leads',        'label'=>'Leads capturados',         'icon'=>'bx-user-plus',    'suffix'=>''],
              ['k'=>'appointments', 'label'=>'Citas agendadas',          'icon'=>'bx-calendar-check','suffix'=>''],
              ['k'=>'hours_saved',  'label'=>'Horas ahorradas',          'icon'=>'bx-time-five',    'suffix'=>'h'],
            ];
            foreach ($activity as $act):
              $m = $metrics[$act['k']] ?? ['value'=>0, 'change'=>0];
            ?>
            <div class="col-6 col-md-4 col-xl">
              <div class="d-flex align-items-center gap-2 mb-1">
                <i class="bx <?= $act['icon'] ?> text-muted"></i>
                <small class="text-muted"><?= e($act['label']) ?></small>
              </div>
              <div class="d-flex align-items-baseline gap-2">
                <h3 class="mb-0"><?= e($m['value']) ?><?= $act['suffix'] ?></h3>
                <?= changeBadge((int)($m['change'] ?? 0)) ?>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>
  </div>

  <p class="text-muted small mt-3 mb-0">
    <i class="bx bx-info-circle"></i>
    Cálculo: citas × $<?= number_format((float)($config['avgTicket'] ?? 0)) ?> (ticket promedio) ·
    leads × $<?= number_format((float)($config['valPerLead'] ?? 0)) ?> ·
    horas × $<?= number_format((float)($config['staffHourlyCost'] ?? 0)) ?>/h.
    Ajusta estos valores con el botón «Ajustar» para reflejar tu negocio.
  </p>

</div></div>

<!-- ── Modal: ajustar valores ────────────────────────────────────── -->
<div class="modal fade" id="configValueModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Ajustar valores de cálculo</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form id="value-config-form">
        <div class="modal-body">
          <p class="text-muted small">Estos valores personalizan la estimación de ROI según tu negocio.</p>
          <div class="mb-3">
            <label class="form-label">Ticket promedio por cita/venta (<?= $currency ?>)</label>
            <input type="number" min="0" step="1" class="form-control" name="avgTicket"
                   value="<?= (int)($config['avgTicket'] ?? 800) ?>">
          </div>
          <div class="mb-3">
            <label class="form-label">Valor estimado por lead capturado (<?= $currency ?>)</label>
            <input type="number" min="0" step="1" class="form-control" name="valuePerLead"
                   value="<?= (int)($config['valPerLead'] ?? 150) ?>">
          </div>
          <div class="mb-1">
            <label class="form-label">Costo por hora de personal (<?= $currency ?>)</label>
            <input type="number" min="0" step="1" class="form-control" name="staffHourlyCost"
                   value="<?= (int)($config['staffHourlyCost'] ?? 80) ?>">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-primary">Guardar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php renderFooter(); ?>
</div></div></div>

<script>
(function () {
  'use strict';
  const btn = document.getElementById('btn-config-value');
  const modalEl = document.getElementById('configValueModal');
  const modal = modalEl ? new bootstrap.Modal(modalEl) : null;
  btn?.addEventListener('click', () => modal?.show());

  document.getElementById('value-config-form')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(e.target);
    const payload = {
      value: {
        avgTicket:       parseInt(fd.get('avgTicket')) || 0,
        valuePerLead:    parseInt(fd.get('valuePerLead')) || 0,
        staffHourlyCost: parseInt(fd.get('staffHourlyCost')) || 0,
        currency:        '<?= e($currency) ?>',
      },
    };
    try {
      const res = await fetch('/api/settings-save.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      });
      const data = await res.json();
      if (!data.error && (data.id || data.settings)) {
        window.showToast?.('Valores actualizados', 'success');
        setTimeout(() => location.reload(), 600);
      } else {
        window.showToast?.(data.error || 'Error al guardar', 'error');
      }
    } catch {
      window.showToast?.('Error de red', 'error');
    }
  });
})();
</script>
