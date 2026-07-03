<?php
function renderSidebar(string $activePage = ''): void {
    $user   = currentUser();
    $tenant = currentTenant();
    $plan   = $tenant['plan'] ?? 'starter';
    $role   = userRole();

    // ── Construcción del menú agrupado por categoría ─────────────
    // Estructura:
    //   $groups[] = [
    //     'id'    => 'operacion',         // id estable (para localStorage)
    //     'label' => 'Operación',         // header del grupo
    //     'icon'  => 'bx-pulse',          // icono del grupo (modo colapsado)
    //     'items' => [ ['id','href','icon','label', ?'badge'], ... ],
    //   ];

    $groups = [];

    // ── 1. Inicio (siempre visible, sin grupo) ──────────────────
    $rootItems = [
        ['id'=>'dashboard', 'href'=>'/index.php',         'icon'=>'bx-home-circle', 'label'=>'Dashboard'],
        ['id'=>'profile',   'href'=>'/pages/profile.php', 'icon'=>'bx-user-circle', 'label'=>'Mi perfil', '_hidden'=>true],
    ];

    // ── Secciones de TENANT (operación del propio negocio). Para el superadmin
    //    PURO se ocultan: su panel queda enfocado en plataforma (Admin AgentCore),
    //    sin duplicar "Operación". Para ver un negocio usa Operación global.
    if (!isSuperAdmin()) {

    // ── 2. Operación diaria (conversaciones, leads, citas) ──────
    $opItems = [
        ['id'=>'conversations', 'href'=>'/pages/conversations.php', 'icon'=>'bx-conversation', 'label'=>'Conversaciones'],
        ['id'=>'leads',         'href'=>'/pages/leads.php',         'icon'=>'bx-trending-up',  'label'=>'Pipeline'],
        ['id'=>'appointments',  'href'=>'/pages/appointments.php',  'icon'=>'bx-calendar',     'label'=>'Citas'],
    ];
    // Catálogo y pedidos — solo admin. Etiqueta/ícono y visibilidad se
    // individualizan según el giro vía catalogVocab() (Menú / Propiedades /
    // Catálogo; una inmobiliaria o clínica no ve "Pedidos", etc.).
    if (isAdmin()) {
        $voc = catalogVocab();
        if ($voc['show_catalog'])
            $opItems[] = ['id'=>'products', 'href'=>'/pages/products.php', 'icon'=>$voc['icon'], 'label'=>$voc['label']];
        if ($voc['show_orders'])
            $opItems[] = ['id'=>'orders',   'href'=>'/pages/orders.php',   'icon'=>'bx-receipt', 'label'=>$voc['orders_label']];
    }
    $groups[] = ['id'=>'operacion', 'label'=>'Operación', 'icon'=>'bx-pulse', 'items'=>$opItems];

    // ── 3. Agentes IA (Knowledge Base + Agentes) — admin+ ───────
    if (isAdmin()) {
        $tenant    = currentTenant();
        $isReady   = (bool)($tenant['is_ready'] ?? false);
        $simBadge  = !$isReady ? ['cls'=>'bg-warning text-dark', 'text'=>'!'] : null;
        $aiItems = [
            ['id'=>'agents',    'href'=>'/pages/agents.php',         'icon'=>'bx-bot',        'label'=>'Agentes IA'],
            ['id'=>'knowledge', 'href'=>'/pages/knowledge-base.php', 'icon'=>'bx-brain',      'label'=>'Knowledge Base'],
            ['id'=>'simulator', 'href'=>'/pages/simulator.php',      'icon'=>'bx-test-tube',  'label'=>'Simulador Bot',
             'badge' => $simBadge],
            ['id'=>'web-widget','href'=>'/pages/web-widget.php',     'icon'=>'bx-globe',      'label'=>'Chat Web'],
        ];
        $groups[] = ['id'=>'ia', 'label'=>'Inteligencia IA', 'icon'=>'bx-bot', 'items'=>$aiItems];
    }

    // ── 4. Clínica Dental ───────────────────────────────────────
    // Usar tenantIndustry() (fuente canónica: lee settings.industry legacy
    // Y settings.businessProfile.industry que escribe el onboarding) para que
    // el módulo SIEMPRE siga al giro real del tenant. El flag .enabled queda
    // como override manual opcional.
    $clinicaEnabled = isAdmin() && (
        tenantIndustry() === 'dental' ||
        !empty(tenantSettings()['clinica']['enabled'])
    );
    if ($clinicaEnabled) {
        $groups[] = [
            'id'    => 'clinica',
            'label' => 'Clínica Dental',
            'icon'  => 'bx-plus-medical',
            'iconColor' => 'text-danger',
            'items' => [
                ['id'=>'clinica-doctors',  'href'=>'/pages/clinica/doctors.php',       'icon'=>'bx-user-circle', 'label'=>'Doctores'],
                ['id'=>'clinica-services', 'href'=>'/pages/clinica/service-types.php', 'icon'=>'bx-list-check',  'label'=>'Tipos de servicio'],
                ['id'=>'clinica-settings', 'href'=>'/pages/clinica/settings.php',      'icon'=>'bx-shield-plus', 'label'=>'Config. clínica'],
            ],
        ];
    }

    // ── 5. Consultorios ─────────────────────────────────────────
    $consulEnabled = isAdmin() && (
        tenantIndustry() === 'consultorio' ||
        !empty(tenantSettings()['consultorio']['enabled'])
    );
    if ($consulEnabled) {
        $groups[] = [
            'id'    => 'consultorio',
            'label' => 'Consultorios',
            'icon'  => 'bx-briefcase',
            'iconColor' => 'text-primary',
            'items' => [
                ['id'=>'consult-professionals',  'href'=>'/pages/consultorio/professionals.php',  'icon'=>'bx-user-pin',       'label'=>'Profesionales'],
                ['id'=>'consult-session-types',  'href'=>'/pages/consultorio/session-types.php',  'icon'=>'bx-calendar-check', 'label'=>'Tipos de sesión'],
                ['id'=>'consult-qualification',  'href'=>'/pages/consultorio/qualification.php',  'icon'=>'bx-filter-alt',     'label'=>'Calificación'],
                ['id'=>'consult-sessions',       'href'=>'/pages/consultorio/sessions.php',       'icon'=>'bx-calendar',       'label'=>'Sesiones'],
                ['id'=>'consult-settings',       'href'=>'/pages/consultorio/settings.php',       'icon'=>'bx-shield-quarter', 'label'=>'Config. consultorio'],
            ],
        ];
    }

    // ── 6. Marketing — admin+ ───────────────────────────────────
    if (isAdmin()) {
        $groups[] = [
            'id'    => 'marketing',
            'label' => 'Marketing',
            'icon'  => 'bx-broadcast',
            'items' => [
                ['id'=>'campaigns',    'href'=>'/pages/campaigns.php',    'icon'=>'bx-broadcast',        'label'=>'Campañas'],
                ['id'=>'reports',      'href'=>'/pages/reports.php',      'icon'=>'bx-bar-chart-alt-2',  'label'=>'Reportes'],
                ['id'=>'value-report', 'href'=>'/pages/value-report.php', 'icon'=>'bx-trophy',           'label'=>'Reporte de Valor'],
                ['id'=>'insights',     'href'=>'/pages/insights.php',     'icon'=>'bx-bulb',             'label'=>'Voz del cliente',
                 'badge'=> isPremiumPlan() ? null : ['cls'=>'bg-label-warning', 'text'=>'PRO']],
            ],
        ];
    } else {
        // Usuarios regulares igual ven reportes
        $groups[] = [
            'id'    => 'reportes',
            'label' => 'Reportes',
            'icon'  => 'bx-bar-chart-alt-2',
            'items' => [
                ['id'=>'reports', 'href'=>'/pages/reports.php', 'icon'=>'bx-bar-chart-alt-2', 'label'=>'Reportes'],
            ],
        ];
    }

    // ── 7. Administración — admin+ ──────────────────────────────
    if (isAdmin()) {
        $adminItems = [
            ['id'=>'users',    'href'=>'/pages/users.php',    'icon'=>'bx-group',       'label'=>'Usuarios'],
            ['id'=>'billing',  'href'=>'/pages/billing.php',  'icon'=>'bx-credit-card', 'label'=>'Billing',
             'badge'=> ($plan === 'starter') ? ['cls'=>'bg-danger', 'text'=>'!'] : null],
            ['id'=>'settings', 'href'=>'/pages/settings.php', 'icon'=>'bx-cog',         'label'=>'Configuración'],
        ];
        $groups[] = ['id'=>'admin', 'label'=>'Administración', 'icon'=>'bx-cog', 'items'=>$adminItems];
    }

    } // fin secciones de tenant (ocultas para el superadmin puro)

    // ── 8. Superadmin (plataforma) ──────────────────────────────
    if (isSuperAdmin()) {
        $groups[] = [
            'id'    => 'superadmin',
            'label' => 'Admin AgentCore',
            'icon'  => 'bx-shield-alt-2',
            'iconColor' => 'text-warning',
            'items' => [
                ['id'=>'sa-operations', 'href'=>'/pages/admin/operations.php', 'icon'=>'bx-globe', 'label'=>'Operación global'],
                ['id'=>'sa-tenants', 'href'=>'/pages/admin/tenants.php', 'icon'=>'bx-buildings', 'label'=>'Tenants'],
                ['id'=>'sa-users',   'href'=>'/pages/admin/users.php',   'icon'=>'bx-group',     'label'=>'Usuarios'],
                ['id'=>'sa-plans',   'href'=>'/pages/admin/plans.php',   'icon'=>'bx-purchase-tag', 'label'=>'Planes y tarifas'],
                ['id'=>'sa-costs',   'href'=>'/pages/admin/costs.php',   'icon'=>'bx-dollar-circle', 'label'=>'Costos y márgenes'],
                ['id'=>'sa-numbers', 'href'=>'/pages/admin/numbers.php', 'icon'=>'bx-phone',     'label'=>'Números Twilio'],
                ['id'=>'sa-stats',   'href'=>'/pages/admin/stats.php',   'icon'=>'bx-stats',     'label'=>'Estadísticas'],
                ['id'=>'sa-reports', 'href'=>'/pages/admin/reports.php', 'icon'=>'bx-bar-chart-alt-2', 'label'=>'Reportes'],
                ['id'=>'sa-insights','href'=>'/pages/admin/insights.php','icon'=>'bx-bulb',      'label'=>'Insights'],
                ['id'=>'sa-logs',    'href'=>'/pages/admin/logs.php',    'icon'=>'bx-history',   'label'=>'Logs globales'],
            ],
        ];
    }

    // Determinar qué grupo contiene la página activa (para abrirlo por defecto)
    $activeGroupId = null;
    foreach ($groups as $g) {
        foreach ($g['items'] as $it) {
            if ($it['id'] === $activePage) { $activeGroupId = $g['id']; break 2; }
        }
    }
?>
<aside id="layout-menu" class="layout-menu menu-vertical menu bg-menu-theme">

  <!-- ── Logo / Brand ─────────────────────────────────────────── -->
  <div class="app-brand demo">
    <a href="/index.php" class="app-brand-link">
      <span class="app-brand-logo demo">
        <?php $tAvatar = $tenant['avatar_url'] ?? null; if ($tAvatar): ?>
          <?php $src = (strpos($tAvatar, '/uploads/') === 0) ? BACKEND_ROOT . $tAvatar : $tAvatar; ?>
          <img src="<?= e($src) ?>" alt="<?= e($tenant['name'] ?? APP_NAME) ?>" class="brand-img rounded">
        <?php else: ?>
          <svg width="32" height="32" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
            <defs>
              <linearGradient id="bg-grad" x1="0" y1="0" x2="32" y2="32" gradientUnits="userSpaceOnUse">
                <stop stop-color="#696CFF"/>
                <stop offset="1" stop-color="#9155FD"/>
              </linearGradient>
            </defs>
            <rect width="32" height="32" rx="8" fill="url(#bg-grad)"/>
            <path d="M16 7l8 4.6v9.2L16 25.4 8 20.8v-9.2L16 7z" fill="#fff" fill-opacity=".95"/>
            <circle cx="16" cy="16" r="3.4" fill="#696CFF"/>
          </svg>
        <?php endif; ?>
      </span>
      <span class="app-brand-text demo menu-text fw-bold ms-2">
        <?= e(APP_NAME) ?>
      </span>
    </a>
  </div>


  <div class="menu-inner-shadow"></div>

  <ul class="menu-inner py-1">

    <!-- ── Items raíz (sin grupo) ─────────────────────────────── -->
    <?php foreach ($rootItems as $item): ?>
    <?php if (!empty($item['_hidden'])) continue; ?>
    <li class="menu-item <?= $activePage === $item['id'] ? 'active' : '' ?>">
      <a href="<?= e($item['href']) ?>" class="menu-link">
        <i class="menu-icon tf-icons bx <?= e($item['icon']) ?>"></i>
        <div><?= e($item['label']) ?></div>
      </a>
    </li>
    <?php endforeach; ?>

    <!-- ── Grupos colapsables ──────────────────────────────────── -->
    <?php foreach ($groups as $g):
      $isOpen = ($activeGroupId === $g['id']);
      $iconColor = $g['iconColor'] ?? '';
    ?>
    <li class="menu-item menu-group <?= $isOpen ? 'open active' : '' ?>" data-group-id="<?= e($g['id']) ?>">
      <a href="javascript:void(0);" class="menu-link menu-toggle">
        <i class="menu-icon tf-icons bx <?= e($g['icon']) ?> <?= e($iconColor) ?>"></i>
        <div><?= e($g['label']) ?></div>
      </a>
      <ul class="menu-sub">
        <?php foreach ($g['items'] as $item): ?>
        <li class="menu-item <?= $activePage === $item['id'] ? 'active' : '' ?>">
          <a href="<?= e($item['href']) ?>" class="menu-link">
            <i class="menu-icon tf-icons bx <?= e($item['icon']) ?>"></i>
            <div><?= e($item['label']) ?></div>
            <?php if (!empty($item['badge'])): ?>
              <span class="badge <?= e($item['badge']['cls']) ?> rounded-pill ms-auto"><?= e($item['badge']['text']) ?></span>
            <?php endif; ?>
          </a>
        </li>
        <?php endforeach; ?>
      </ul>
    </li>
    <?php endforeach; ?>

    <!-- ── Sección Cuenta ──────────────────────────────────────── -->
    <li class="menu-header small text-uppercase mt-3">
      <span class="menu-header-text">Cuenta</span>
    </li>

    <li class="px-3 pb-1">
      <a href="/pages/profile.php" class="d-flex align-items-center gap-2 text-decoration-none"
         style="color:inherit" title="Ver mi perfil">
        <?= avatar($user, 'xs') ?>
        <div style="min-width:0;flex:1">
          <div class="fw-semibold text-truncate" style="font-size:.82rem;max-width:120px"><?= e($user['name'] ?? '') ?></div>
          <?php
            $roleBadge = match($role) {
                'superadmin' => '<span class="badge bg-warning text-dark" style="font-size:.6rem"><i class="bx bx-shield-alt-2 me-1"></i>Superadmin</span>',
                'admin'      => '<span class="badge bg-label-primary" style="font-size:.6rem"><i class="bx bx-user-check me-1"></i>Admin</span>',
                default      => '<span class="badge bg-label-secondary" style="font-size:.6rem"><i class="bx bx-user me-1"></i>Usuario</span>',
            };
            echo $roleBadge;
          ?>
        </div>
        <i class="bx bx-chevron-right text-muted" style="font-size:.85rem;opacity:.5"></i>
      </a>
    </li>

    <?php if (isAdmin() && !isSuperAdmin()): ?>
    <li class="menu-item px-3 pb-2">
      <div class="d-flex align-items-center gap-2 mb-1">
        <?= planBadge($plan) ?>
        <small class="text-muted text-truncate"><?= e($tenant['name'] ?? '') ?></small>
      </div>
      <?php
        $used = (int)($tenant['minutes_used_mo'] ?? 0);
        $max  = (int)($tenant['max_minutes_mo']  ?? 500);
        $pct  = $max > 0 ? min(100, round($used / $max * 100)) : 0;
        $barClass = $pct >= 90 ? 'bg-danger' : ($pct >= 70 ? 'bg-warning' : 'bg-primary');
      ?>
      <div class="d-flex justify-content-between mb-1">
        <small class="text-muted">Minutos</small>
        <small><?= $used ?>/<?= $max ?></small>
      </div>
      <div class="progress" style="height:4px">
        <div class="progress-bar <?= $barClass ?>" style="width:<?= $pct ?>%"></div>
      </div>
    </li>
    <?php endif; ?>

  </ul>

  <!-- Pin flotante de colapsar/fijar — DENTRO del aside como descendiente
       directo (no dentro de .app-brand, que tiene overflow:hidden).
       Estar dentro del aside es necesario para que mouseover sobre el pin
       mantenga el :hover del sidebar (y así poder fijarlo expandido). El
       bug de "no se colapsa al click" se evita con pointer-events:none
       temporal en theme.js. -->
  <a href="javascript:void(0);" class="layout-menu-pin" id="menuToggleBtn"
     title="Colapsar / fijar menú">
    <i class="bx bx-chevron-left"></i>
  </a>
</aside>
<?php
}
