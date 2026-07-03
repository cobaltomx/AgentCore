<?php
/**
 * Super Admin — Detalle centralizado de un tenant.
 * Hub único para administrar un cliente: Resumen · Datos · Usuarios · Features · Facturación.
 */
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/head.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/navbar.php';
require_once __DIR__ . '/../../includes/footer.php';

requireAuth();
requireRole('superadmin');

$id = $_GET['id'] ?? '';
if (!isValidUuid($id)) { header('Location: /pages/admin/tenants.php'); exit; }

$overview = apiGet("/superadmin/tenants/{$id}/overview");
if (($overview['_status'] ?? 200) === 404 || empty($overview['tenant'])) {
    header('Location: /pages/admin/tenants.php'); exit;
}
$features = apiGet("/superadmin/tenants/{$id}/features");
$billing  = apiGet("/superadmin/tenants/{$id}/billing");
$plans    = array_values(array_filter((array)apiGet('/superadmin/plans'), 'is_array'));

$tenant = $overview['tenant'];
$counts = $overview['counts'] ?? [];
$convs  = is_array($overview['recentConvs'] ?? null) ? $overview['recentConvs'] : [];
$leads  = is_array($overview['recentLeads'] ?? null) ? $overview['recentLeads'] : [];
$agents = is_array($overview['agents'] ?? null) ? $overview['agents'] : [];
$users  = is_array($overview['users'] ?? null) ? $overview['users'] : [];

$settings  = $tenant['settings'] ?? [];
$industry  = $settings['businessProfile']['industry'] ?? $settings['industry'] ?? '';
$bizName   = $settings['businessProfile']['businessName'] ?? $settings['businessName'] ?? $tenant['name'];
$tone      = $settings['tone'] ?? 'professional';
$objective = $settings['objective'] ?? '';
$greeting  = $settings['greeting'] ?? '';
$status    = $tenant['status'] ?? 'trial';

$used = (int)($tenant['minutes_used_mo'] ?? 0);
$max  = (int)($tenant['max_minutes_mo'] ?? 0);
$pct  = $max > 0 ? min(100, round($used / $max * 100)) : 0;

$industries = [
  '' => '— Sin industria —', 'clinica'=>'Clínica / Salud', 'dental'=>'Clínica Dental',
  'consultorio'=>'Consultorios / Terapia', 'inmobiliaria'=>'Inmobiliaria', 'taller'=>'Taller automotriz',
  'restaurante'=>'Restaurante', 'educacion'=>'Educación', 'ecommerce'=>'E-commerce',
  'servicios'=>'Servicios generales', 'gym'=>'Gym / Spa',
];
$toneLabels = ['professional'=>'Profesional','friendly'=>'Amigable','formal'=>'Formal','casual'=>'Casual'];

function money($cents) { return '$' . number_format(((int)$cents)/100, 2); }

renderHead("Tenant · {$bizName}");
?>
<div class="layout-wrapper layout-content-navbar"><div class="layout-container">
<?php renderSidebar('sa-tenants'); ?>
<div class="layout-page"><?php renderNavbar("Tenant · {$bizName}"); ?>
<div class="content-wrapper"><div class="container-xxl flex-grow-1 container-p-y">

  <!-- Breadcrumb -->
  <nav class="mb-3"><ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="/pages/admin/tenants.php">Tenants</a></li>
    <li class="breadcrumb-item active"><?= e($bizName) ?></li>
  </ol></nav>

  <!-- ── Header de contexto ─────────────────────────────────── -->
  <div class="card mb-3">
    <div class="card-body d-flex align-items-center gap-3 flex-wrap">
      <?= avatar($tenant, 'lg') ?>
      <div class="flex-grow-1" style="min-width:200px">
        <h4 class="mb-1"><?= e($bizName) ?>
          <?= planBadge($tenant['plan'] ?? '') ?>
          <?php $sc = match($status){'active'=>'success','trial'=>'info','suspended'=>'danger',default=>'secondary'};
                $sl = match($status){'active'=>'Activo','trial'=>'Trial','suspended'=>'Suspendido',default=>ucfirst($status)}; ?>
          <span class="badge bg-label-<?= $sc ?>" id="statusBadge"><?= $sl ?></span>
        </h4>
        <small class="text-muted">@<?= e($tenant['slug']) ?></small>
      </div>
      <div style="min-width:220px">
        <div class="d-flex justify-content-between mb-1">
          <small class="text-muted">Minutos del mes</small>
          <small class="<?= $pct>=90?'text-danger fw-semibold':'' ?>"><?= $used ?>/<?= $max ?>m (<?= $pct ?>%)</small>
        </div>
        <div class="progress" style="height:6px">
          <div class="progress-bar <?= $pct>=90?'bg-danger':($pct>=70?'bg-warning':'bg-success') ?>" style="width:<?= $pct ?>%"></div>
        </div>
      </div>
      <div class="d-flex gap-2">
        <?php if ($status !== 'suspended'): ?>
          <button class="btn btn-outline-warning btn-sm" id="statusBtn" data-next="suspended"><i class="bx bx-block me-1"></i>Suspender</button>
        <?php else: ?>
          <button class="btn btn-outline-success btn-sm" id="statusBtn" data-next="active"><i class="bx bx-check-circle me-1"></i>Activar</button>
        <?php endif; ?>
        <button class="btn btn-outline-danger btn-sm" onclick="openDelete()"><i class="bx bx-trash"></i></button>
      </div>
    </div>
  </div>

  <!-- ── Tabs ───────────────────────────────────────────────── -->
  <ul class="nav nav-pills flex-wrap mb-3" role="tablist">
    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#t-resumen"><i class="bx bx-grid-alt me-1"></i>Resumen</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#t-datos"><i class="bx bx-data me-1"></i>Datos</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#t-usuarios"><i class="bx bx-group me-1"></i>Usuarios <span class="badge bg-label-secondary"><?= count($users) ?></span></button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#t-features"><i class="bx bx-toggle-left me-1"></i>Features</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#t-facturacion"><i class="bx bx-dollar-circle me-1"></i>Facturación</button></li>
  </ul>

  <div class="tab-content">

    <!-- ═══ RESUMEN ═══ -->
    <div class="tab-pane fade show active" id="t-resumen">
      <div class="row g-3 mb-4">
        <?php
        $kpis = [
          ['l'=>'Usuarios','v'=>$counts['users']??0,'i'=>'bx-group','c'=>'info'],
          ['l'=>'Agentes','v'=>$counts['agents']??0,'i'=>'bx-bot','c'=>'primary'],
          ['l'=>'Conversaciones','v'=>$counts['conversations']??0,'i'=>'bx-conversation','c'=>'success'],
          ['l'=>'Convs. (30d)','v'=>$counts['conversations_30d']??0,'i'=>'bx-calendar','c'=>'warning'],
          ['l'=>'Leads','v'=>$counts['leads']??0,'i'=>'bx-user-check','c'=>'danger'],
        ];
        foreach ($kpis as $k): ?>
        <div class="col-6 col-xl">
          <div class="card h-100"><div class="card-body d-flex align-items-center gap-2 py-3">
            <span class="avatar-initial rounded bg-label-<?= $k['c'] ?> p-2"><i class="bx <?= $k['i'] ?>"></i></span>
            <div><div class="h5 mb-0"><?= (int)$k['v'] ?></div><div class="text-muted small"><?= $k['l'] ?></div></div>
          </div></div>
        </div>
        <?php endforeach; ?>
      </div>

      <div class="card">
        <div class="card-header"><h6 class="mb-0"><i class="bx bx-cog me-1"></i>Configuración del tenant</h6></div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-6"><label class="form-label">Nombre del tenant</label>
              <input class="form-control" id="f-name" value="<?= e($tenant['name']) ?>"></div>
            <div class="col-md-3"><label class="form-label">Plan</label>
              <select class="form-select" id="f-plan">
                <?php foreach ($plans as $p): ?><option value="<?= e($p['key']) ?>" <?= ($tenant['plan']??'')===$p['key']?'selected':'' ?>><?= e($p['name']) ?></option><?php endforeach; ?>
              </select></div>
            <div class="col-md-3"><label class="form-label">Estado</label>
              <select class="form-select" id="f-status">
                <option value="active" <?= $status==='active'?'selected':'' ?>>Activo</option>
                <option value="trial" <?= $status==='trial'?'selected':'' ?>>Trial</option>
                <option value="suspended" <?= $status==='suspended'?'selected':'' ?>>Suspendido</option>
              </select></div>
            <div class="col-md-3"><label class="form-label">Máx. agentes</label>
              <input type="number" class="form-control" id="f-agents" min="1" max="50" value="<?= (int)($tenant['max_agents']??1) ?>"></div>
            <div class="col-md-3"><label class="form-label">Máx. minutos/mes</label>
              <input type="number" class="form-control" id="f-mins" min="100" step="100" value="<?= (int)($tenant['max_minutes_mo']??300) ?>"></div>
            <div class="col-md-6"><label class="form-label">Industria</label>
              <select class="form-select" id="f-industry">
                <?php foreach ($industries as $v=>$l): ?><option value="<?= e($v) ?>" <?= $industry===$v?'selected':'' ?>><?= e($l) ?></option><?php endforeach; ?>
              </select></div>
            <div class="col-md-6"><label class="form-label">Nombre comercial</label>
              <input class="form-control" id="f-bizname" value="<?= e($bizName) ?>"></div>
            <div class="col-md-6"><label class="form-label">Tono del agente</label>
              <select class="form-select" id="f-tone">
                <?php foreach ($toneLabels as $v=>$l): ?><option value="<?= e($v) ?>" <?= $tone===$v?'selected':'' ?>><?= e($l) ?></option><?php endforeach; ?>
              </select></div>
            <div class="col-12"><label class="form-label">Objetivo del agente</label>
              <textarea class="form-control" id="f-objective" rows="2"><?= e($objective) ?></textarea></div>
            <div class="col-12"><label class="form-label">Saludo inicial</label>
              <input class="form-control" id="f-greeting" value="<?= e($greeting) ?>"></div>
          </div>
        </div>
        <div class="card-footer text-end">
          <button class="btn btn-primary" id="saveConfigBtn"><i class="bx bx-save me-1"></i>Guardar cambios</button>
        </div>
      </div>
    </div>

    <!-- ═══ DATOS (read-only) ═══ -->
    <div class="tab-pane fade" id="t-datos">
      <div class="row g-4">
        <div class="col-lg-6"><div class="card h-100">
          <div class="card-header"><h6 class="mb-0"><i class="bx bx-conversation me-1"></i>Conversaciones recientes</h6></div>
          <div class="table-responsive"><table class="table table-sm align-middle mb-0">
            <thead class="table-light"><tr><th>Canal</th><th>Estado</th><th>Duración</th><th>Fecha</th></tr></thead><tbody>
            <?php if (empty($convs)): ?><tr><td colspan="4" class="text-center text-muted py-4">Sin conversaciones</td></tr>
            <?php else: foreach ($convs as $c): ?><tr>
              <td><?= channelIcon($c['channel']??'') ?> <small><?= e($c['channel']??'') ?></small></td>
              <td><?= statusBadge($c['status']??'') ?></td>
              <td><small><?= isset($c['duration_secs'])?formatMins((int)$c['duration_secs']):'—' ?></small></td>
              <td><small class="text-muted"><?= !empty($c['created_at'])?formatDate($c['created_at'],'d/m H:i'):'—' ?></small></td>
            </tr><?php endforeach; endif; ?>
          </tbody></table></div>
        </div></div>
        <div class="col-lg-6"><div class="card h-100">
          <div class="card-header"><h6 class="mb-0"><i class="bx bx-user-check me-1"></i>Leads recientes</h6></div>
          <div class="table-responsive"><table class="table table-sm align-middle mb-0">
            <thead class="table-light"><tr><th>Nombre</th><th>Teléfono</th><th>Estado</th><th>Fecha</th></tr></thead><tbody>
            <?php if (empty($leads)): ?><tr><td colspan="4" class="text-center text-muted py-4">Sin leads</td></tr>
            <?php else: foreach ($leads as $l): ?><tr>
              <td><?= e($l['name']??'—') ?></td><td><small class="text-muted"><?= e($l['phone']??'—') ?></small></td>
              <td><?= statusBadge($l['status']??'') ?></td>
              <td><small class="text-muted"><?= !empty($l['created_at'])?formatDate($l['created_at'],'d/m H:i'):'—' ?></small></td>
            </tr><?php endforeach; endif; ?>
          </tbody></table></div>
        </div></div>
        <div class="col-12"><div class="card">
          <div class="card-header"><h6 class="mb-0"><i class="bx bx-bot me-1"></i>Agentes</h6></div>
          <div class="table-responsive"><table class="table table-sm align-middle mb-0">
            <thead class="table-light"><tr><th>Nombre</th><th>Canal</th><th>Número</th><th>Estado</th></tr></thead><tbody>
            <?php if (empty($agents)): ?><tr><td colspan="4" class="text-center text-muted py-4">Sin agentes</td></tr>
            <?php else: foreach ($agents as $a): ?><tr>
              <td><?= e($a['name']??'—') ?></td><td><?= channelIcon($a['channel']??'') ?> <small><?= e($a['channel']??'') ?></small></td>
              <td><small class="text-muted"><?= e($a['phone_number']??'—') ?></small></td>
              <td><?= !empty($a['is_active'])?'<span class="badge bg-label-success">Activo</span>':'<span class="badge bg-label-secondary">Inactivo</span>' ?></td>
            </tr><?php endforeach; endif; ?>
          </tbody></table></div>
        </div></div>
      </div>
    </div>

    <!-- ═══ USUARIOS ═══ -->
    <div class="tab-pane fade" id="t-usuarios">
      <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h6 class="mb-0">Usuarios del tenant</h6>
          <button class="btn btn-sm btn-primary" onclick="openAddUser()"><i class="bx bx-user-plus me-1"></i>Agregar usuario</button>
        </div>
        <div class="table-responsive"><table class="table table-hover align-middle mb-0" id="usersTable">
          <thead class="table-light"><tr><th>Nombre</th><th>Email</th><th>Rol</th><th>Último acceso</th><th class="text-center">Activo</th></tr></thead><tbody>
          <?php if (empty($users)): ?><tr><td colspan="5" class="text-center text-muted py-4">Sin usuarios</td></tr>
          <?php else: foreach ($users as $u): ?>
          <tr data-uid="<?= e($u['id']) ?>">
            <td><?= e($u['name']??'—') ?></td>
            <td><small class="text-muted"><?= e($u['email']??'') ?></small></td>
            <td><span class="badge bg-label-<?= ($u['role']??'')==='admin'?'primary':'secondary' ?>"><?= e($u['role']??'') ?></span></td>
            <td><small class="text-muted"><?= !empty($u['last_login_at'])?formatDate($u['last_login_at'],'d/m H:i'):'Nunca' ?></small></td>
            <td class="text-center">
              <div class="form-check form-switch d-inline-block">
                <input class="form-check-input user-toggle" type="checkbox" data-uid="<?= e($u['id']) ?>" <?= !empty($u['is_active'])?'checked':'' ?>>
              </div>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody></table></div>
      </div>
    </div>

    <!-- ═══ FEATURES ═══ -->
    <div class="tab-pane fade" id="t-features">
      <div class="card">
        <div class="card-header"><h6 class="mb-0"><i class="bx bx-toggle-left me-1"></i>Features por tenant</h6></div>
        <div class="card-body">
          <p class="text-muted small">Cada feature hereda del plan <span class="badge bg-label-secondary"><?= e($features['planKey']??'—') ?></span>. Cambia a <b>Activado/Desactivado</b> para forzar un override solo en este tenant.</p>
          <div class="row g-2" id="featList">
            <?php
            $catalog = $features['catalog'] ?? [];
            $planF   = $features['planFeatures'] ?? [];
            $ov      = $features['overrides'] ?? [];
            foreach ($catalog as $fk=>$flabel):
              $inPlan = in_array($fk, (array)$planF);
              $cur = array_key_exists($fk, (array)$ov) ? ($ov[$fk] ? 'on' : 'off') : 'inherit';
            ?>
            <div class="col-md-6"><div class="d-flex align-items-center justify-content-between border rounded p-2">
              <div><span class="fw-semibold"><?= e($flabel) ?></span> <code class="small text-muted"><?= e($fk) ?></code>
                <div class="small mt-1">Plan: <?= $inPlan?'<span class="badge bg-label-success">incluida</span>':'<span class="badge bg-label-secondary">no incluida</span>' ?></div></div>
              <select class="form-select form-select-sm feat-sel" data-key="<?= e($fk) ?>" style="max-width:150px">
                <option value="inherit" <?= $cur==='inherit'?'selected':'' ?>>Heredar (plan)</option>
                <option value="on" <?= $cur==='on'?'selected':'' ?>>Activado</option>
                <option value="off" <?= $cur==='off'?'selected':'' ?>>Desactivado</option>
              </select>
            </div></div>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="card-footer text-end"><button class="btn btn-primary" id="saveFeatBtn"><i class="bx bx-save me-1"></i>Guardar features</button></div>
      </div>
    </div>

    <!-- ═══ FACTURACIÓN ═══ -->
    <div class="tab-pane fade" id="t-facturacion">
      <?php $b = is_array($billing) ? $billing : []; $mp = $b['marginPct'] ?? null; ?>
      <div class="row g-3 mb-3">
        <div class="col-md-3"><div class="card h-100"><div class="card-body py-3">
          <div class="text-muted small">Ingreso (mes)</div><div class="h4 mb-0 text-success"><?= money($b['revenueCents']??0) ?></div>
        </div></div></div>
        <div class="col-md-3"><div class="card h-100"><div class="card-body py-3">
          <div class="text-muted small">Costo estimado</div><div class="h4 mb-0"><?= money($b['cost']['totalCostCents']??0) ?></div>
        </div></div></div>
        <div class="col-md-3"><div class="card h-100"><div class="card-body py-3">
          <div class="text-muted small">Margen</div>
          <div class="h4 mb-0 <?= ($b['marginCents']??0)<0?'text-danger':'text-success' ?>"><?= money($b['marginCents']??0) ?><?= $mp!==null?" ({$mp}%)":'' ?></div>
        </div></div></div>
        <div class="col-md-3"><div class="card h-100"><div class="card-body py-3">
          <div class="text-muted small">Plan</div><div class="h6 mb-0"><?= e($b['plan']['name']??$tenant['plan']??'—') ?></div>
          <small class="text-muted"><?= money($b['plan']['monthlyCents']??0) ?>/mes · <?= (int)($b['plan']['includedMinutes']??0) ?> min</small>
        </div></div></div>
      </div>
      <div class="card"><div class="card-body">
        <h6 class="mb-3">Desglose del mes en curso</h6>
        <table class="table table-sm">
          <tbody>
            <tr><td>Minutos de voz usados</td><td class="text-end"><?= number_format($b['cost']['voiceMin']??0,1) ?> min (<?= (int)($b['cost']['calls']??0) ?> llamadas)</td></tr>
            <tr><td>Costo de voz (Twilio+STT+TTS)</td><td class="text-end"><?= money($b['cost']['costVoiceCents']??0) ?></td></tr>
            <tr><td>Tokens LLM</td><td class="text-end"><?= number_format($b['cost']['tokens']??0) ?> → <?= money($b['cost']['costLlmCents']??0) ?></td></tr>
            <tr><td>Excedente facturado</td><td class="text-end"><?= money($b['overageCents']??0) ?></td></tr>
            <tr class="fw-semibold border-top"><td>Margen del mes</td><td class="text-end <?= ($b['marginCents']??0)<0?'text-danger':'text-success' ?>"><?= money($b['marginCents']??0) ?></td></tr>
          </tbody>
        </table>
        <a href="/pages/admin/costs.php" class="btn btn-sm btn-outline-secondary"><i class="bx bx-bar-chart-alt-2 me-1"></i>Ver costos de toda la plataforma</a>
      </div></div>
    </div>

  </div>
</div><?php renderFooter(); ?>

<!-- Modal: agregar usuario -->
<div class="modal fade" id="modalAddUser" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title"><i class="bx bx-user-plus me-2 text-primary"></i>Agregar usuario</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body"><div class="row g-3">
    <div class="col-md-6"><label class="form-label">Nombre</label><input class="form-control" id="au-name"></div>
    <div class="col-md-6"><label class="form-label">Rol</label><select class="form-select" id="au-role"><option value="user">Usuario</option><option value="admin">Admin</option></select></div>
    <div class="col-12"><label class="form-label">Email <span class="text-danger">*</span></label><input class="form-control" type="email" id="au-email"></div>
    <div class="col-12"><label class="form-label">Contraseña <span class="text-danger">*</span></label>
      <div class="input-group"><input class="form-control" type="text" id="au-pass" placeholder="Mín. 8 caracteres">
        <button class="btn btn-outline-secondary" type="button" onclick="genPass()"><i class="bx bx-dice-5"></i></button></div></div>
  </div></div>
  <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" id="addUserBtn">Crear usuario</button></div>
</div></div></div>

<!-- Modal: eliminar tenant -->
<div class="modal fade" id="modalDelete" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
  <div class="modal-header bg-label-danger"><h5 class="modal-title text-danger"><i class="bx bx-error-circle me-2"></i>Eliminar tenant</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body">
    <div class="alert alert-danger small">Acción <b>permanente</b>: se borra todo el historial del tenant. Escribe el slug <code><?= e($tenant['slug']) ?></code> para confirmar.</div>
    <input class="form-control font-monospace" id="del-confirm" placeholder="<?= e($tenant['slug']) ?>" autocomplete="off">
  </div>
  <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-danger" id="confirmDelBtn" disabled>Eliminar definitivamente</button></div>
</div></div></div>

</div></div></div>

<script>
const TID = <?= json_encode($id) ?>;
const SLUG = <?= json_encode($tenant['slug']) ?>;
function toast(m,t='info'){ window.showToast?.(m,t); }

// ── Guardar configuración ──
document.getElementById('saveConfigBtn').addEventListener('click', async function(){
  const btn=this; btn.disabled=true; btn.innerHTML='<span class="spinner-border spinner-border-sm me-1"></span>Guardando…';
  try{
    const basic = await fetch('/api/superadmin-tenant.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({
      id:TID, action:'basic', name:document.getElementById('f-name').value,
      plan:document.getElementById('f-plan').value, status:document.getElementById('f-status').value,
      max_agents:parseInt(document.getElementById('f-agents').value), max_minutes_mo:parseInt(document.getElementById('f-mins').value),
    })});
    if(!basic.ok) throw new Error('Error al guardar datos básicos');
    const set = await fetch('/api/superadmin-tenant.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({
      id:TID, action:'settings',
      businessProfile:{ industry:document.getElementById('f-industry').value, businessName:document.getElementById('f-bizname').value },
      tone:document.getElementById('f-tone').value, objective:document.getElementById('f-objective').value, greeting:document.getElementById('f-greeting').value,
    })});
    if(!set.ok) throw new Error('Error al guardar el perfil');
    toast('<i class="bx bx-check me-1"></i>Configuración guardada','success');
  }catch(e){ toast(e.message,'danger'); }
  btn.disabled=false; btn.innerHTML='<i class="bx bx-save me-1"></i>Guardar cambios';
});

// ── Suspender / activar ──
document.getElementById('statusBtn')?.addEventListener('click', async function(){
  const next=this.dataset.next;
  const ok = await (window.confirmToast?.(`¿Confirmas <b>${next==='suspended'?'suspender':'activar'}</b> este tenant?`, 'Sí', next==='suspended'?'btn-danger':'btn-success') ?? Promise.resolve(true));
  if(!ok) return;
  try{
    const r=await fetch('/api/superadmin-tenant.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:TID,action:'status',status:next})});
    if(!r.ok) throw new Error('Error');
    toast('Estado actualizado','success'); setTimeout(()=>location.reload(),700);
  }catch(e){ toast('No se pudo cambiar el estado','danger'); }
});

// ── Eliminar tenant ──
const delModal=()=>bootstrap.Modal.getOrCreateInstance(document.getElementById('modalDelete'));
function openDelete(){ document.getElementById('del-confirm').value=''; document.getElementById('confirmDelBtn').disabled=true; delModal().show(); }
document.getElementById('del-confirm').addEventListener('input', function(){ document.getElementById('confirmDelBtn').disabled = this.value.trim()!==SLUG; });
document.getElementById('confirmDelBtn').addEventListener('click', async function(){
  const btn=this; btn.disabled=true; btn.innerHTML='<span class="spinner-border spinner-border-sm me-1"></span>Eliminando…';
  try{
    const r=await fetch('/api/superadmin-delete-tenant.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:TID,confirm:SLUG})});
    const d=await r.json(); if(!r.ok) throw new Error(d.error||'Error');
    toast('Tenant eliminado','success'); setTimeout(()=>location.href='/pages/admin/tenants.php',800);
  }catch(e){ toast(e.message,'danger'); btn.disabled=false; btn.innerHTML='Eliminar definitivamente'; }
});

// ── Features ──
document.getElementById('saveFeatBtn').addEventListener('click', async function(){
  const btn=this; btn.disabled=true; btn.innerHTML='<span class="spinner-border spinner-border-sm me-1"></span>Guardando…';
  const features={}; document.querySelectorAll('.feat-sel').forEach(s=>{ features[s.dataset.key] = s.value==='inherit'?null:(s.value==='on'); });
  try{
    const r=await fetch('/api/superadmin-features.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:TID,features})});
    const d=await r.json(); if(!r.ok) throw new Error(d.error||'Error');
    toast('<i class="bx bx-toggle-right me-1"></i>Features actualizadas','success');
  }catch(e){ toast(e.message,'danger'); }
  btn.disabled=false; btn.innerHTML='<i class="bx bx-save me-1"></i>Guardar features';
});

// ── Usuarios ──
const addUserModal=()=>bootstrap.Modal.getOrCreateInstance(document.getElementById('modalAddUser'));
function openAddUser(){ ['au-name','au-email','au-pass'].forEach(i=>document.getElementById(i).value=''); document.getElementById('au-role').value='user'; addUserModal().show(); }
function genPass(){ const c='abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#$%&'; document.getElementById('au-pass').value=Array.from({length:14},()=>c[Math.floor(Math.random()*c.length)]).join(''); }
document.getElementById('addUserBtn').addEventListener('click', async function(){
  const email=document.getElementById('au-email').value.trim(), pass=document.getElementById('au-pass').value;
  if(!email||pass.length<8){ toast('Email y contraseña (mín. 8) requeridos','warning'); return; }
  const btn=this; btn.disabled=true; btn.innerHTML='<span class="spinner-border spinner-border-sm me-1"></span>…';
  try{
    const r=await fetch('/api/superadmin-user.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({
      action:'create', tenant_id:TID, name:document.getElementById('au-name').value.trim(), email, password:pass, role:document.getElementById('au-role').value })});
    const d=await r.json(); if(!r.ok) throw new Error(d.error||'Error');
    toast('Usuario creado','success'); setTimeout(()=>location.reload(),700);
  }catch(e){ toast(e.message,'danger'); btn.disabled=false; btn.innerHTML='Crear usuario'; }
});
document.querySelectorAll('.user-toggle').forEach(sw=>{
  sw.addEventListener('change', async function(){
    const uid=this.dataset.uid, active=this.checked, self=this; self.disabled=true;
    try{
      const r=await fetch('/api/superadmin-user.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'toggle',user_id:uid,is_active:active})});
      if(!r.ok) throw new Error(); toast('Usuario '+(active?'activado':'desactivado'),'success');
    }catch(e){ self.checked=!active; toast('No se pudo cambiar','danger'); }
    self.disabled=false;
  });
});
</script>
