<?php
function renderNavbar(string $pageTitle = ''): void {
    $user      = currentUser();
    $name      = $user['name'] ?? '';
    $initials  = strtoupper(substr($name ?: 'U', 0, 1));
    $avatarUrl = $user['avatar_url'] ?? null;
    $avatarSrc = $avatarUrl
      ? ((strpos($avatarUrl, '/uploads/') === 0) ? BACKEND_ROOT . $avatarUrl : $avatarUrl)
      : null;

    // ── Datos de la cédula del usuario ────────────────────────────
    $role     = userRole();
    $isSuper  = ($role === 'superadmin');
    $roleLbl  = ['superadmin'=>'Super Admin','admin'=>'Administrador','owner'=>'Propietario','agent'=>'Agente','user'=>'Usuario'][$role] ?? ucfirst($role);
    $email    = $user['email'] ?? '';
    $tenant   = currentTenant() ?? [];
    $bizName  = tenantBusinessName();
    $ind      = tenantIndustry();
    $indLbl   = $ind !== '' ? ucfirst($ind) : '';
    $plan     = $tenant['plan']   ?? 'starter';
    $tStatus  = $tenant['status'] ?? '';
    $minUsed  = (int)($tenant['minutes_used_mo'] ?? 0);
    $minMax   = (int)($tenant['max_minutes_mo']  ?? 0);
    $minPct   = $minMax > 0 ? min(100, round($minUsed / $minMax * 100)) : 0;

    // Vigencia: viene de la suscripción (no está en sesión). Se cachea 120s
    // para no llamar al backend en cada carga de página (el navbar va en todas).
    $sub = null;
    if (!$isSuper) {
        $now = time();
        if (!empty($_SESSION['cedula_billing']) && ($now - ($_SESSION['cedula_billing_ts'] ?? 0)) < 120) {
            $sub = $_SESSION['cedula_billing'];
        } else {
            $bs = apiGet('/billing/status');
            if (empty($bs['error'])) {
                $sub = $bs['subscription'] ?? [];
                $_SESSION['cedula_billing']    = $sub;
                $_SESSION['cedula_billing_ts'] = $now;
            }
        }
    }
    // Estado + fecha de vigencia (si existe)
    $subStatusMap = ['trialing'=>['warning','Prueba'],'active'=>['success','Activo'],'past_due'=>['danger','Pago pendiente'],'canceled'=>['secondary','Cancelado'],'incomplete'=>['warning','Incompleto']];
    if (!empty($sub) && !empty($sub['status'])) {
        [$vigColor,$vigLabel] = $subStatusMap[$sub['status']] ?? ['secondary', ucfirst($sub['status'])];
        $trialAct = !empty($sub['trial_end']) && strtotime($sub['trial_end']) > time();
        $vigDate  = $trialAct ? $sub['trial_end'] : ($sub['current_period_end'] ?? null);
        $vigVerb  = $trialAct ? 'Prueba hasta' : 'Vence';
    } else {
        // Sin suscripción → estado del tenant (trial/active), sin fecha
        $tStatusMap = ['trial'=>['warning','Prueba'],'active'=>['success','Activo'],'suspended'=>['danger','Suspendido']];
        [$vigColor,$vigLabel] = $tStatusMap[$tStatus] ?? ['secondary', $tStatus ?: '—'];
        $vigDate = null; $vigVerb = '';
    }
?>
<nav class="layout-navbar container-xxl navbar navbar-expand-xl navbar-detached align-items-center bg-navbar-theme"
     id="layout-navbar">

  <!-- Botón colapsar sidebar -->
  <div class="navbar-nav align-items-xl-center me-3 me-xl-0">
    <a class="nav-item nav-link px-0 me-xl-2" href="javascript:void(0)" id="navbarSidebarToggle"
       title="Colapsar / expandir menú" aria-label="Colapsar o expandir menú lateral" role="button">
      <i class="bx bx-menu bx-sm" aria-hidden="true"></i>
    </a>
  </div>

  <div class="navbar-nav-right d-flex align-items-center" id="navbar-collapse">

    <!-- Título de página -->
    <div class="navbar-nav align-items-center me-auto">
      <span class="fw-semibold text-muted"><?= e($pageTitle) ?></span>
    </div>

    <ul class="navbar-nav flex-row align-items-center ms-auto">

      <!-- ── Toggle Dark / Light ──────────────────────────────── -->
      <li class="nav-item me-2 me-xl-1">
        <a class="nav-link" href="javascript:void(0);" id="themeToggleBtn"
           title="Cambiar tema claro/oscuro" data-bs-toggle="tooltip"
           aria-label="Cambiar entre tema claro y oscuro" role="button">
          <i class="bx bx-sm" id="themeToggleIcon" aria-hidden="true"></i>
        </a>
      </li>

      <!-- ── Notificaciones ────────────────────────────────────── -->
      <li class="nav-item dropdown-notifications navbar-dropdown dropdown me-2 me-xl-1">
        <a class="nav-link dropdown-toggle hide-arrow" href="javascript:void(0);"
           id="notifToggle"
           data-bs-toggle="dropdown" data-bs-auto-close="outside"
           aria-expanded="false" aria-label="Notificaciones" role="button">
          <i class="bx bx-bell bx-sm" aria-hidden="true"></i>
          <span class="badge bg-danger rounded-pill badge-notifications d-none" id="notif-badge" aria-live="polite">0</span>
        </a>

        <ul class="dropdown-menu dropdown-menu-end py-0" style="min-width:340px;max-width:380px" id="notif-dropdown">

          <!-- Cabecera -->
          <li class="dropdown-menu-header border-bottom">
            <div class="dropdown-header d-flex align-items-center py-3 px-3">
              <h6 class="text-body mb-0 me-auto fw-semibold">Notificaciones</h6>
              <button class="btn btn-sm btn-link text-muted p-0 ms-2" id="btn-mark-all-read"
                      title="Marcar todas como leídas" style="font-size:.78rem;display:none">
                <i class="bx bx-check-double me-1"></i>Todo leído
              </button>
            </div>
          </li>

          <!-- Lista (scroll) -->
          <li>
            <ul class="list-group list-group-flush" id="notif-list"
                style="max-height:360px;overflow-y:auto;border-radius:0">
              <li class="list-group-item text-center text-muted py-4 small" id="notif-empty">
                <i class="bx bx-bell-off d-block mb-1 opacity-50" style="font-size:1.4rem"></i>
                Sin notificaciones nuevas
              </li>
            </ul>
          </li>

          <!-- Pie -->
          <li class="dropdown-menu-footer border-top p-2 text-center">
            <a href="/pages/conversations.php" class="btn btn-sm btn-outline-primary w-100"
               style="font-size:.78rem">
              Ver todas las conversaciones
            </a>
          </li>

        </ul>
      </li>

      <!-- ── Perfil ──────────────────────────────────────────── -->
      <li class="nav-item navbar-dropdown dropdown-user dropdown">
        <a class="nav-link dropdown-toggle hide-arrow" href="javascript:void(0);"
           data-bs-toggle="dropdown" aria-label="Menú de mi cuenta" role="button" aria-expanded="false">
          <div class="avatar avatar-online">
            <?php if ($avatarSrc): ?>
              <img src="<?= e($avatarSrc) ?>" alt="<?= e($name) ?>" class="rounded-circle">
            <?php else: ?>
              <span class="avatar-initial rounded-circle bg-label-primary">
                <?= e($initials) ?>
              </span>
            <?php endif; ?>
          </div>
        </a>
        <ul class="dropdown-menu dropdown-menu-end" style="min-width:300px">
          <!-- ── Cédula del usuario ──────────────────────────────── -->
          <li class="px-3 py-2">
            <div class="d-flex align-items-center">
              <div class="flex-shrink-0 me-3">
                <div class="avatar avatar-online">
                  <?php if ($avatarSrc): ?>
                    <img src="<?= e($avatarSrc) ?>" alt="<?= e($name) ?>" class="rounded-circle">
                  <?php else: ?>
                    <span class="avatar-initial rounded-circle bg-label-primary"><?= e($initials) ?></span>
                  <?php endif; ?>
                </div>
              </div>
              <div class="flex-grow-1" style="min-width:0">
                <span class="fw-semibold d-block text-truncate"><?= e($name ?: 'Usuario') ?></span>
                <?php if ($email): ?><small class="text-muted d-block text-truncate"><?= e($email) ?></small><?php endif; ?>
                <span class="badge bg-label-<?= $isSuper ? 'danger' : 'primary' ?> mt-1"><i class="bx bx-user-circle me-1"></i><?= e($roleLbl) ?></span>
              </div>
            </div>
          </li>

          <?php if (!$isSuper): ?>
          <li><hr class="dropdown-divider my-1"></li>
          <li class="px-3 py-1">
            <?php if ($bizName): ?>
            <div class="d-flex justify-content-between align-items-center mb-1">
              <small class="text-muted">Negocio</small>
              <small class="fw-semibold text-truncate ms-2" style="max-width:170px"><?= e($bizName) ?><?php if ($indLbl): ?> <span class="text-muted">· <?= e($indLbl) ?></span><?php endif; ?></small>
            </div>
            <?php endif; ?>
            <div class="d-flex justify-content-between align-items-center mb-1">
              <small class="text-muted">Plan</small>
              <span><?= planBadge($plan) ?></span>
            </div>
            <div class="d-flex justify-content-between align-items-center mb-1">
              <small class="text-muted">Vigencia</small>
              <small><span class="badge bg-label-<?= $vigColor ?>"><?= e($vigLabel) ?></span><?php if (!empty($vigDate)): ?> <span class="text-muted"><?= e($vigVerb) ?> <?= e(formatDate($vigDate, 'd/m/Y')) ?></span><?php endif; ?></small>
            </div>
            <?php if ($minMax > 0): ?>
            <div class="d-flex justify-content-between align-items-center mb-1">
              <small class="text-muted">Minutos del mes</small>
              <small class="fw-semibold"><?= number_format($minUsed) ?> / <?= number_format($minMax) ?></small>
            </div>
            <div class="progress" style="height:5px">
              <div class="progress-bar <?= $minPct>=90?'bg-danger':($minPct>=70?'bg-warning':'bg-primary') ?>" style="width:<?= $minPct ?>%"></div>
            </div>
            <?php endif; ?>
          </li>
          <?php endif; ?>

          <li><hr class="dropdown-divider my-1"></li>
          <li><a class="dropdown-item" href="/pages/profile.php"><i class="bx bx-user me-2"></i>Mi perfil</a></li>
          <?php if ($isSuper): ?>
          <li><a class="dropdown-item" href="/pages/admin/operations.php"><i class="bx bx-shield-alt-2 me-2"></i>Panel AgentCore</a></li>
          <?php else: ?>
          <li><a class="dropdown-item" href="/pages/billing.php"><i class="bx bx-credit-card me-2"></i>Plan y facturación</a></li>
          <?php endif; ?>
          <li><a class="dropdown-item" href="/pages/settings.php"><i class="bx bx-cog me-2"></i>Configuración</a></li>
          <li><hr class="dropdown-divider my-1"></li>
          <li>
            <a class="dropdown-item" href="/logout.php">
              <i class="bx bx-power-off me-2 text-danger"></i><span class="text-danger">Cerrar sesión</span>
            </a>
          </li>
        </ul>
      </li>

    </ul>
  </div>
</nav>
<script>
// Accesibilidad: destino del skip-link ("Saltar al contenido principal")
document.addEventListener('DOMContentLoaded', function () {
  var main = document.querySelector('.content-wrapper');
  if (main && !document.getElementById('main-content')) {
    main.id = 'main-content';
    main.setAttribute('role', 'main');
    main.setAttribute('tabindex', '-1');
  }
});
</script>
<?php
}
