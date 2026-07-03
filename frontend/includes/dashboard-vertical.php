<?php
/**
 * Banda vertical del dashboard del tenant.
 * Renderiza un bloque industria-específico bajo "Necesita tu atención".
 * Los datos vienen de overview.vertical (backend). Si no hay, no pinta nada.
 *
 * Uso: renderDashboardVertical($dash['vertical'] ?? null);
 */
if (!function_exists('renderDashboardVertical')) {
function renderDashboardVertical(?array $v): void {
    if (!$v || empty($v['type'])) return;
    switch ($v['type']) {
        case 'salud':        renderVerticalSalud($v['salud'] ?? []); break;
        case 'inmobiliaria': renderVerticalInmobiliaria($v['inmobiliaria'] ?? []); break;
        case 'comercio':     renderVerticalComercio($v['comercio'] ?? []); break;
        case 'restaurante':  renderVerticalRestaurante($v['restaurante'] ?? []); break;
    }
}

// ── 🍽️ Restaurante (reservaciones + pedidos + menú) ────────────────
function renderVerticalRestaurante(array $s): void {
    $curr     = $s['currency'] ?? 'MXN';
    $money    = fn($cents) => '$' . number_format(((int)$cents) / 100, 0) . ' ' . e($curr);
    $resToday = (int)($s['reservations_today'] ?? 0);
    $res7d    = (int)($s['reservations_7d'] ?? 0);
    $ordToday = (int)($s['orders_today'] ?? 0);
    $ord30    = (int)($s['orders_30d'] ?? 0);
    $avg      = (int)($s['avg_ticket_cents'] ?? 0);
    $menu     = (int)($s['menu_items'] ?? 0);
    $today    = is_array($s['today_reservations'] ?? null) ? $s['today_reservations'] : [];
    $tz       = new DateTimeZone('America/Mexico_City');

    $cards = [
        ['l'=>'Reservaciones hoy','v'=>(string)$resToday,'sub'=>$res7d.' en los próx. 7 días','i'=>'bx-calendar-star','c'=>'primary'],
        ['l'=>'Pedidos a domicilio','v'=>(string)$ordToday,'sub'=>$ord30.' en 30 días','i'=>'bx-cart','c'=>'success'],
        ['l'=>'Ticket promedio','v'=>$money($avg),'sub'=>'por pedido (30 días)','i'=>'bx-receipt','c'=>'info'],
        ['l'=>'Platillos en menú','v'=>(string)$menu,'sub'=>$menu>0?'activos en la carta':'sin menú cargado','i'=>'bx-restaurant','c'=>$menu>0?'secondary':'warning'],
    ];
?>
  <div class="card border-info mb-4">
    <div class="card-header d-flex align-items-center gap-2 py-2">
      <i class="bx bx-restaurant text-info"></i>
      <h6 class="mb-0">Tu restaurante hoy</h6>
    </div>
    <div class="card-body">
      <div class="row g-3 mb-1">
        <?php foreach ($cards as $c): ?>
        <div class="col-sm-6 col-xl-3">
          <div class="d-flex align-items-center gap-2">
            <span class="avatar-initial rounded bg-label-<?= $c['c'] ?> p-2"><i class="bx <?= $c['i'] ?>"></i></span>
            <div>
              <div class="h4 mb-0"><?= e($c['v']) ?></div>
              <div class="fw-semibold small"><?= e($c['l']) ?></div>
              <div class="text-muted" style="font-size:.72rem"><?= e($c['sub']) ?></div>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <hr class="my-3">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <div class="small fw-semibold"><i class="bx bx-calendar-star me-1 text-muted"></i>Reservaciones de hoy</div>
        <a href="/pages/appointments.php" class="btn btn-sm btn-outline-primary py-0">Ver todas</a>
      </div>
      <?php if (!$today): ?>
        <div class="text-muted text-center py-3"><i class="bx bx-calendar-x me-1"></i>No hay reservaciones para hoy.</div>
      <?php else: foreach ($today as $r):
        try { $h = (new DateTime($r['scheduled_at']))->setTimezone($tz)->format('H:i'); } catch (\Throwable $e) { $h='--:--'; }
        $cs = $r['confirmation_status'] ?? 'pending';
        $cc = $cs==='confirmed' ? 'success' : ($cs==='cancelled' ? 'danger' : 'warning');
        $cl = $cs==='confirmed' ? 'confirmada' : ($cs==='cancelled' ? 'cancelada' : 'por confirmar');
        // El nº de comensales suele venir en las notas (ej. "4 personas").
        $covers = '';
        if (!empty($r['notes']) && preg_match('/(\d+)\s*(personas?|comensales?|pax)/iu', $r['notes'], $mm)) $covers = $mm[1].' pers.';
      ?>
      <div class="d-flex align-items-center gap-2 py-1 border-bottom">
        <span class="text-muted" style="width:46px"><?= $h ?></span>
        <span class="flex-grow-1 text-truncate"><?= e($r['patient_name'] ?: 'Cliente') ?></span>
        <?php if ($covers): ?><span class="badge bg-label-secondary"><i class="bx bx-group"></i> <?= e($covers) ?></span><?php endif; ?>
        <span class="badge bg-label-<?= $cc ?>"><?= $cl ?></span>
      </div>
      <?php endforeach; endif; ?>
    </div>
  </div>
<?php
}

// ── 🛒 Comercio / E-commerce ───────────────────────────────────────
function renderVerticalComercio(array $s): void {
    $curr      = $s['currency'] ?? 'MXN';
    $money     = fn($cents) => '$' . number_format(((int)$cents) / 100, 0) . ' ' . e($curr);
    $ordersTd  = (int)($s['orders_today'] ?? 0);
    $orders30  = (int)($s['orders_30d'] ?? 0);
    $avgTicket = (int)($s['avg_ticket_cents'] ?? 0);
    $pending   = (int)($s['pending_payments'] ?? 0);
    $noStock   = (int)($s['out_of_stock'] ?? 0);
    $recent    = is_array($s['recent_orders'] ?? null) ? $s['recent_orders'] : [];
    $tz        = new DateTimeZone('America/Mexico_City');

    $cards = [
        ['l'=>'Pedidos hoy','v'=>(string)$ordersTd,'sub'=>$orders30.' en 30 días','i'=>'bx-cart','c'=>'primary'],
        ['l'=>'Ticket promedio','v'=>$money($avgTicket),'sub'=>'por pedido (30 días)','i'=>'bx-receipt','c'=>'info'],
        ['l'=>'Pagos pendientes','v'=>(string)$pending,'sub'=>$pending>0?'esperan pago':'todo cobrado','i'=>'bx-time-five','c'=>$pending>0?'warning':'success'],
        ['l'=>'Sin stock','v'=>(string)$noStock,'sub'=>$noStock>0?'producto(s) agotado(s)':'inventario completo','i'=>'bx-package','c'=>$noStock>0?'danger':'success'],
    ];
?>
  <div class="card border-info mb-4">
    <div class="card-header d-flex align-items-center gap-2 py-2">
      <i class="bx bx-store text-info"></i>
      <h6 class="mb-0">Tu tienda hoy</h6>
    </div>
    <div class="card-body">
      <div class="row g-3 mb-1">
        <?php foreach ($cards as $c): ?>
        <div class="col-sm-6 col-xl-3">
          <div class="d-flex align-items-center gap-2">
            <span class="avatar-initial rounded bg-label-<?= $c['c'] ?> p-2"><i class="bx <?= $c['i'] ?>"></i></span>
            <div>
              <div class="h4 mb-0"><?= e($c['v']) ?></div>
              <div class="fw-semibold small"><?= e($c['l']) ?></div>
              <div class="text-muted" style="font-size:.72rem"><?= e($c['sub']) ?></div>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <hr class="my-3">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <div class="small fw-semibold"><i class="bx bx-cart me-1 text-muted"></i>Pedidos recientes</div>
        <a href="/pages/orders.php" class="btn btn-sm btn-outline-primary py-0">Ver pedidos</a>
      </div>
      <?php if (!$recent): ?>
        <div class="text-muted text-center py-3"><i class="bx bx-cart-alt me-1"></i>Aún no hay pedidos registrados.</div>
      <?php else: foreach ($recent as $o):
        try { $when = (new DateTime($o['created_at']))->setTimezone($tz)->format('d/m H:i'); } catch (\Throwable $e) { $when='—'; }
        $paid    = !empty($o['paid_at']);
        $st      = $o['status'] ?? '';
        if ($st === 'cancelled') { $pc='danger'; $pl='cancelado'; }
        elseif ($paid)           { $pc='success'; $pl='pagado'; }
        elseif (!empty($o['payment_url'])) { $pc='warning'; $pl='por pagar'; }
        else                     { $pc='secondary'; $pl=($st ?: 'pendiente'); }
      ?>
      <div class="d-flex align-items-center gap-2 py-1 border-bottom">
        <span class="text-muted" style="width:84px;font-size:.78rem"><?= e($when) ?></span>
        <span class="flex-grow-1 text-truncate"><?= e($o['customer_name'] ?: 'Cliente') ?></span>
        <span class="fw-semibold small me-2"><?= e($money($o['total_cents'] ?? 0)) ?></span>
        <span class="badge bg-label-<?= $pc ?>"><?= e($pl) ?></span>
      </div>
      <?php endforeach; endif; ?>
    </div>
  </div>
<?php
}

// ── 🏠 Inmobiliaria ────────────────────────────────────────────────
function renderVerticalInmobiliaria(array $s): void {
    $propsActive = (int)($s['properties_active'] ?? 0);
    $propsTotal  = (int)($s['properties_total'] ?? 0);
    $visitsToday = (int)($s['visits_today'] ?? 0);
    $visits7d    = (int)($s['visits_7d'] ?? 0);
    $leadsNew    = (int)($s['leads_new'] ?? 0);
    $upcoming    = is_array($s['upcoming_visits'] ?? null) ? $s['upcoming_visits'] : [];
    $tz          = new DateTimeZone('America/Mexico_City');

    $cards = [
        ['l'=>'Propiedades activas','v'=>$propsActive,'sub'=>$propsTotal.' en catálogo','i'=>'bx-building-house','c'=>'primary'],
        ['l'=>'Visitas hoy','v'=>$visitsToday,'sub'=>$visits7d.' en los próx. 7 días','i'=>'bx-calendar-event','c'=>'success'],
        ['l'=>'Leads sin atender','v'=>$leadsNew,'sub'=>$leadsNew>0?'requieren seguimiento':'todo atendido','i'=>'bx-user-plus','c'=>$leadsNew>0?'warning':'success'],
        ['l'=>'Visitas próx. 7 días','v'=>$visits7d,'sub'=>'recorridos agendados','i'=>'bx-walk','c'=>'info'],
    ];
?>
  <div class="card border-info mb-4">
    <div class="card-header d-flex align-items-center gap-2 py-2">
      <i class="bx bx-building-house text-info"></i>
      <h6 class="mb-0">Tu inmobiliaria hoy</h6>
    </div>
    <div class="card-body">
      <div class="row g-3 mb-1">
        <?php foreach ($cards as $c): ?>
        <div class="col-sm-6 col-xl-3">
          <div class="d-flex align-items-center gap-2">
            <span class="avatar-initial rounded bg-label-<?= $c['c'] ?> p-2"><i class="bx <?= $c['i'] ?>"></i></span>
            <div>
              <div class="h4 mb-0"><?= (int)$c['v'] ?></div>
              <div class="fw-semibold small"><?= e($c['l']) ?></div>
              <div class="text-muted" style="font-size:.72rem"><?= e($c['sub']) ?></div>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <hr class="my-3">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <div class="small fw-semibold"><i class="bx bx-walk me-1 text-muted"></i>Próximas visitas (7 días)</div>
        <div class="d-flex gap-2">
          <a href="/pages/leads.php?status=new" class="btn btn-sm btn-outline-warning py-0">Leads (<?= $leadsNew ?>)</a>
          <a href="/pages/products.php" class="btn btn-sm btn-outline-primary py-0">Catálogo</a>
        </div>
      </div>
      <?php if (!$upcoming): ?>
        <div class="text-muted text-center py-3"><i class="bx bx-calendar-x me-1"></i>No hay visitas agendadas en los próximos 7 días.</div>
      <?php else: foreach ($upcoming as $vz):
        try { $when = (new DateTime($vz['scheduled_at']))->setTimezone($tz)->format('D d/m · H:i'); } catch (\Throwable $e) { $when='—'; }
        $cs = $vz['confirmation_status'] ?? 'pending';
        $cc = $cs==='confirmed' ? 'success' : ($cs==='cancelled' ? 'danger' : 'warning');
        $cl = $cs==='confirmed' ? 'confirmada' : ($cs==='cancelled' ? 'cancelada' : 'por confirmar');
      ?>
      <div class="d-flex align-items-center gap-2 py-1 border-bottom">
        <span class="text-muted" style="width:120px;font-size:.8rem"><?= e($when) ?></span>
        <span class="flex-grow-1 text-truncate"><?= e($vz['patient_name'] ?: 'Cliente') ?></span>
        <span class="badge bg-label-<?= $cc ?>"><?= $cl ?></span>
      </div>
      <?php endforeach; endif; ?>
    </div>
  </div>
<?php
}

// ── 🦷 Salud (clínica / dental / consultorio) ──────────────────────
function renderVerticalSalud(array $s): void {
    $today    = $s['today'] ?? ['total'=>0,'confirmed'=>0,'pending'=>0];
    $idle     = (int)($s['idle_today'] ?? 0);
    $docsAct  = (int)($s['doctors_active'] ?? 0);
    $byDoctor = is_array($s['by_doctor'] ?? null) ? $s['by_doctor'] : [];
    $noShows  = (int)($s['no_shows_30d'] ?? 0);

    $cards = [
        ['l'=>'Sillón vacío hoy','v'=>$idle,'sub'=>$idle>0?'especialista(s) sin citas hoy':'todos con agenda','i'=>'bx-chair','c'=>$idle>0?'warning':'success'],
        ['l'=>'Citas hoy','v'=>(int)$today['total'],'sub'=>(int)$today['confirmed'].' confirmadas · '.(int)$today['pending'].' por confirmar','i'=>'bx-calendar','c'=>'primary'],
        ['l'=>'No-shows','v'=>$noShows,'sub'=>'no asistieron (30 días)','i'=>'bx-user-x','c'=>$noShows>0?'danger':'success'],
        ['l'=>'Especialistas activos','v'=>$docsAct,'sub'=>'en la clínica','i'=>'bx-plus-medical','c'=>'info'],
    ];
?>
  <div class="card border-info mb-4">
    <div class="card-header d-flex align-items-center gap-2 py-2">
      <i class="bx bx-plus-medical text-info"></i>
      <h6 class="mb-0">Tu clínica hoy</h6>
    </div>
    <div class="card-body">
      <div class="row g-3 mb-1">
        <?php foreach ($cards as $c): ?>
        <div class="col-sm-6 col-xl-3">
          <div class="d-flex align-items-center gap-2">
            <span class="avatar-initial rounded bg-label-<?= $c['c'] ?> p-2"><i class="bx <?= $c['i'] ?>"></i></span>
            <div>
              <div class="h4 mb-0"><?= (int)$c['v'] ?></div>
              <div class="fw-semibold small"><?= e($c['l']) ?></div>
              <div class="text-muted" style="font-size:.72rem"><?= e($c['sub']) ?></div>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <?php if ($byDoctor): ?>
      <hr class="my-3">
      <div class="small fw-semibold mb-2"><i class="bx bx-calendar-week me-1 text-muted"></i>Ocupación de hoy por especialista</div>
      <div class="row g-2">
        <?php foreach ($byDoctor as $d): $tc = (int)($d['today_count'] ?? 0); $free = $tc === 0; ?>
        <div class="col-md-6 col-xl-4">
          <div class="d-flex align-items-center gap-2 border rounded p-2 <?= $free ? 'border-warning' : '' ?>">
            <span class="avatar-initial rounded-circle bg-label-<?= $free?'warning':'primary' ?>" style="width:34px;height:34px;display:flex;align-items:center;justify-content:center">
              <i class="bx bx-user-voice"></i>
            </span>
            <div class="flex-grow-1" style="min-width:0">
              <div class="fw-semibold small text-truncate"><?= e($d['name'] ?? 'Especialista') ?></div>
              <div class="text-muted text-truncate" style="font-size:.72rem">
                <?php if (!empty($d['room'])): ?><i class="bx bx-door-open"></i> <?= e($d['room']) ?> · <?php endif; ?>
                <?= $free ? '<span class="text-warning fw-semibold">Libre hoy</span>' : ($tc.' cita(s) hoy') ?>
              </div>
            </div>
            <?php if ($free): ?>
            <a href="/pages/appointments.php?new=1" class="btn btn-sm btn-outline-warning py-0 px-1" title="Agendar"><i class="bx bx-plus"></i></a>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
<?php
}
}
