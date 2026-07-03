<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/head.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/navbar.php';
require_once __DIR__ . '/../../includes/footer.php';

requireAuth();
requireRole('superadmin');

// Fetch todos los tenants desde el endpoint superadmin
$raw     = apiGet('/superadmin/tenants');
$tenants = array_values(array_filter((array)$raw, 'is_array'));

// Fetch stats globales
$stats = apiGet('/superadmin/stats');

// Fetch alertas de consumo (Fase C)
$alertsData = apiGet('/superadmin/alerts');
$alerts     = is_array($alertsData['alerts'] ?? null) ? $alertsData['alerts'] : [];

// ── Opciones de industria ──────────────────────────────────────────────────
$industries = [
    ''             => '— Seleccionar industria —',
    'clinica'      => '🏥 Clínica / Salud',
    'dental'       => '🦷 Clínica Dental',
    'consultorio'  => '💼 Consultorios / Terapia',
    'inmobiliaria' => '🏠 Inmobiliaria',
    'taller'       => '🔧 Taller automotriz',
    'restaurante'  => '🍽️ Restaurante / Comida',
    'educacion'    => '📚 Educación / Academia',
    'ecommerce'    => '🛍️ E-commerce / Tienda',
    'servicios'    => '🛠️ Servicios generales',
    'gym'          => '💪 Gym / Spa / Bienestar',
];

$toneLabels = [
    'professional' => 'Profesional',
    'friendly'     => 'Amigable',
    'formal'       => 'Formal',
    'casual'       => 'Casual / Relajado',
];

renderHead('Admin — Tenants');
?>
<div class="layout-wrapper layout-content-navbar"><div class="layout-container">
<?php renderSidebar('sa-tenants'); ?>
<div class="layout-page"><?php renderNavbar('Admin · Tenants'); ?>
<div class="content-wrapper"><div class="container-xxl flex-grow-1 container-p-y">

  <!-- ── Header ──────────────────────────────────────────────────────── -->
  <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-3">
    <div>
      <h4 class="mb-1">
        <span class="badge bg-warning text-dark me-2"><i class="bx bx-shield-alt-2"></i></span>
        Panel Superadmin · Tenants
      </h4>
      <p class="text-muted mb-0">Gestión global de la plataforma AgentCore</p>
    </div>
    <button class="btn btn-primary" onclick="openCreateModal()">
      <i class="bx bx-plus me-1"></i> Nuevo Tenant
    </button>
  </div>

  <!-- ── Alertas de consumo (Fase C) ─────────────────────────────────── -->
  <?php if (!empty($alerts)): ?>
  <div class="card border-warning mb-4">
    <div class="card-header d-flex align-items-center gap-2 py-2">
      <i class="bx bx-bell bx-tada text-warning"></i>
      <h6 class="mb-0">Alertas de consumo</h6>
      <span class="badge bg-warning text-dark ms-1"><?= count($alerts) ?></span>
    </div>
    <div class="list-group list-group-flush">
      <?php foreach ($alerts as $al):
        $lvl  = ($al['level'] ?? 'warning') === 'danger' ? 'danger' : 'warning';
        $msgs = [];
        foreach (($al['issues'] ?? []) as $iss) {
          $msgs[] = match($iss['type'] ?? '') {
            'minutes_over' => 'Superó sus minutos incluidos',
            'minutes_warn' => 'Cerca del límite de minutos',
            'agents_over'  => 'Más agentes activos que su límite',
            default        => 'Alerta',
          };
        }
      ?>
      <div class="list-group-item d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2">
          <i class="bx bx-error-circle text-<?= $lvl ?>"></i>
          <div>
            <span class="fw-semibold"><?= e($al['name']) ?></span>
            <span class="text-muted small">@<?= e($al['slug']) ?></span>
            <span class="badge bg-label-<?= $lvl ?> ms-1"><?= implode(' · ', $msgs) ?></span>
          </div>
        </div>
        <div class="d-flex align-items-center gap-3">
          <small class="text-<?= $lvl ?> fw-semibold">
            <?= (int)$al['minutesUsed'] ?>/<?= (int)$al['maxMinutes'] ?>m
            (<?= (int)$al['usagePct'] ?>%)
          </small>
          <a href="/pages/admin/tenant-detail.php?id=<?= e($al['tenantId']) ?>"
             class="btn btn-xs btn-outline-<?= $lvl ?>">Administrar</a>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── Stats globales ──────────────────────────────────────────────── -->
  <div class="row g-3 mb-4">
    <?php
    $statCards = [
      ['icon'=>'bx-buildings',    'color'=>'primary',  'label'=>'Tenants activos',   'val'=> ($stats['active_tenants'] ?? '—') . ' / ' . ($stats['total_tenants'] ?? '—')],
      ['icon'=>'bx-user',         'color'=>'info',     'label'=>'Usuarios totales',   'val'=> $stats['total_users']      ?? '—'],
      ['icon'=>'bx-bot',          'color'=>'success',  'label'=>'Agentes activos',    'val'=> $stats['active_agents']    ?? '—'],
      ['icon'=>'bx-conversation', 'color'=>'warning',  'label'=>'Convs. (30 días)',   'val'=> $stats['convs_30d']        ?? '—'],
      ['icon'=>'bx-time',         'color'=>'danger',   'label'=>'Minutos usados',     'val'=> number_format((int)($stats['total_minutes_used'] ?? 0)) . ' m'],
    ];
    foreach ($statCards as $c): ?>
    <div class="col-sm-6 col-xl">
      <div class="card h-100">
        <div class="card-body d-flex align-items-center gap-3 py-3">
          <div class="avatar avatar-md flex-shrink-0">
            <span class="avatar-initial rounded-circle bg-label-<?= $c['color'] ?>">
              <i class="bx <?= $c['icon'] ?>"></i>
            </span>
          </div>
          <div>
            <div class="fw-bold fs-5"><?= e($c['val']) ?></div>
            <div class="text-muted small"><?= e($c['label']) ?></div>
          </div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- ── Tabla de tenants ────────────────────────────────────────────── -->
  <div class="card">
    <div class="card-header d-flex align-items-center justify-content-between gap-3 flex-wrap">
      <h6 class="mb-0">Todos los tenants</h6>
      <input type="search" class="form-control form-control-sm" id="searchTenant"
             placeholder="Buscar tenant..." style="max-width:240px"/>
    </div>
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0" id="tenantsTable">
        <thead class="table-light">
          <tr>
            <th>Negocio</th>
            <th>Industria</th>
            <th>Plan</th>
            <th>Estado</th>
            <th>Twilio</th>
            <th class="text-center">Agentes</th>
            <th class="text-center">Usuarios</th>
            <th>Minutos usados</th>
            <th>Última actividad</th>
            <th>Acciones</th>
          </tr>
        </thead>
        <tbody>
        <?php if (empty($tenants)): ?>
          <tr><td colspan="10" class="text-center text-muted py-5">
            <i class="bx bx-buildings bx-lg d-block mb-2 opacity-25" style="font-size:2.5rem"></i>
            No hay tenants registrados
          </td></tr>
        <?php else: ?>
          <?php foreach ($tenants as $t):
            $industry    = $t['settings']['businessProfile']['industry']
                        ?? $t['settings']['industry']
                        ?? '';
            $bizName     = $t['settings']['businessProfile']['businessName']
                        ?? $t['settings']['businessName']
                        ?? $t['name'];
            $twilioPhone = $t['settings']['twilio']['phoneNumber'] ?? '';
            $twilioOwn   = !empty($t['settings']['twilio']['accountSid']);
            $used       = (int)($t['minutes_used_mo'] ?? 0);
            $max        = (int)($t['max_minutes_mo']  ?? 300);
            $pct        = $max > 0 ? min(100, round($used / $max * 100)) : 0;
            $barClass   = $pct >= 90 ? 'bg-danger' : ($pct >= 70 ? 'bg-warning' : 'bg-success');
            $status     = $t['status'] ?? 'trial';
            $statusBadge = match($status) {
              'active'    => 'bg-label-success',
              'trial'     => 'bg-label-info',
              'suspended' => 'bg-label-danger',
              default     => 'bg-label-secondary',
            };
            $statusLabel = match($status) {
              'active'    => 'Activo',
              'trial'     => 'Trial',
              'suspended' => 'Suspendido',
              default     => ucfirst($status),
            };
            $industryLabel = $industries[$industry] ?? ($industry ?: '—');
            $lastActivity = !empty($t['last_activity'])
                ? (new DateTime($t['last_activity']))->format('d/m/Y')
                : '—';
            $tone      = $t['settings']['tone'] ?? 'professional';
            $objective = $t['settings']['objective'] ?? '';
            $greeting  = $t['settings']['greeting']  ?? '';
            $settingsJson = htmlspecialchars(json_encode([
              'industry'    => $industry,
              'businessName'=> $bizName,
              'tone'        => $tone,
              'objective'   => $objective,
              'greeting'    => $greeting,
            ]), ENT_QUOTES);
          ?>
          <tr data-id="<?= e($t['id']) ?>" data-name="<?= e(strtolower($t['name'] . ' ' . $bizName . ' ' . $t['slug'])) ?>">
            <td>
              <div class="d-flex align-items-center gap-2">
                <?= avatar($t, 'sm') ?>
                <div>
                  <a href="/pages/admin/tenant-detail.php?id=<?= e($t['id']) ?>" class="fw-semibold text-body text-decoration-none stretched-link-name"><?= e($bizName) ?></a>
                  <small class="text-muted d-block">@<?= e($t['slug']) ?></small>
                </div>
              </div>
            </td>
            <td><small><?= e($industryLabel) ?></small></td>
            <td><?= planBadge($t['plan'] ?? '') ?></td>
            <td>
              <div class="d-flex align-items-center gap-1">
                <span class="badge <?= $statusBadge ?>" id="status-badge-<?= e($t['id']) ?>"><?= $statusLabel ?></span>
              </div>
            </td>
            <td>
              <?php if ($twilioPhone): ?>
                <div><code style="font-size:.72rem"><?= e($twilioPhone) ?></code></div>
                <span class="badge <?= $twilioOwn ? 'bg-label-warning' : 'bg-label-secondary' ?>" style="font-size:.62rem">
                  <?= $twilioOwn ? 'Cuenta propia' : 'Plataforma' ?>
                </span>
              <?php else: ?>
                <span class="text-muted small">—</span>
              <?php endif; ?>
            </td>
            <td class="text-center"><?= (int)($t['agent_count'] ?? 0) ?>/<?= (int)($t['max_agents'] ?? 1) ?></td>
            <td class="text-center"><?= (int)($t['user_count'] ?? 0) ?></td>
            <td style="min-width:140px">
              <div class="d-flex justify-content-between mb-1">
                <small><?= $used ?>/<?= $max ?>m</small>
                <small class="<?= $pct >= 90 ? 'text-danger fw-semibold' : 'text-muted' ?>"><?= $pct ?>%</small>
              </div>
              <div class="progress" style="height:4px">
                <div class="progress-bar <?= $barClass ?>" style="width:<?= $pct ?>%"></div>
              </div>
            </td>
            <td><small class="text-muted"><?= $lastActivity ?></small></td>
            <td>
              <div class="d-flex gap-1 flex-wrap">
                <!-- Administrar: hub central del tenant -->
                <a href="/pages/admin/tenant-detail.php?id=<?= e($t['id']) ?>"
                   class="btn btn-xs btn-primary" title="Administrar tenant">
                  <i class="bx bx-cog me-1"></i>Administrar
                </a>
                <!-- Toggle activar/suspender -->
                <?php if ($status !== 'suspended'): ?>
                <button class="btn btn-xs btn-outline-warning toggle-status-btn"
                  onclick="toggleStatus('<?= e($t['id']) ?>', 'suspended')"
                  title="Suspender tenant">
                  <i class="bx bx-block"></i>
                </button>
                <?php else: ?>
                <button class="btn btn-xs btn-outline-success toggle-status-btn"
                  onclick="toggleStatus('<?= e($t['id']) ?>', 'active')"
                  title="Activar tenant">
                  <i class="bx bx-check-circle"></i>
                </button>
                <?php endif; ?>
                <!-- Eliminar tenant (Fase C) -->
                <button class="btn btn-xs btn-outline-danger delete-tenant-btn"
                  onclick="deleteTenant('<?= e($t['id']) ?>', '<?= e($t['slug']) ?>', <?= htmlspecialchars(json_encode($bizName), ENT_QUOTES) ?>)"
                  title="Eliminar tenant">
                  <i class="bx bx-trash"></i>
                </button>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div><?php renderFooter(); ?>

<!-- ─────────────────────────────────────────────────────────────────────────
     MODAL: Crear nuevo tenant
────────────────────────────────────────────────────────────────────────── -->
<div class="modal fade" id="modalCreateTenant" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bx bx-plus-circle me-2 text-primary"></i>Nuevo Tenant</h5>
        <button class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">

        <!-- Tabs -->
        <ul class="nav nav-tabs mb-4" id="createTabs">
          <li class="nav-item">
            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#ct-negocio">
              <i class="bx bx-buildings me-1"></i> Negocio
            </button>
          </li>
          <li class="nav-item">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#ct-admin">
              <i class="bx bx-user-check me-1"></i> Admin
            </button>
          </li>
          <li class="nav-item">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#ct-limites">
              <i class="bx bx-slider me-1"></i> Límites
            </button>
          </li>
        </ul>

        <div class="tab-content">

          <!-- Tab: Negocio -->
          <div class="tab-pane fade show active" id="ct-negocio">
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label">Nombre del negocio <span class="text-danger">*</span></label>
                <input class="form-control" id="cn-name" placeholder="Ej. Clínica Sonrisa" oninput="autoSlug()"/>
              </div>
              <div class="col-md-6">
                <label class="form-label">
                  Slug (subdominio)
                  <span class="help-tip" data-bs-toggle="popover"
                    data-bs-content="Identificador único del tenant. Se usará como subdominio: <b>mi-clinica</b>.agentcore.io">?</span>
                </label>
                <div class="input-group">
                  <input class="form-control font-monospace" id="cn-slug" placeholder="mi-clinica" pattern="[a-z0-9-]+"/>
                  <span class="input-group-text text-muted small">.agentcore.io</span>
                </div>
              </div>
              <div class="col-md-6">
                <label class="form-label">Industria / Nicho</label>
                <select class="form-select" id="cn-industry">
                  <?php foreach ($industries as $val => $label): ?>
                    <option value="<?= e($val) ?>"><?= e($label) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label">Nombre comercial</label>
                <input class="form-control" id="cn-bizname" placeholder="Igual al nombre si no hay diferencia"/>
              </div>
              <div class="col-md-6">
                <label class="form-label">Plan inicial</label>
                <select class="form-select" id="cn-plan">
                  <option value="trial">Trial</option>
                  <option value="starter" selected>Starter</option>
                  <option value="growth">Growth</option>
                  <option value="business">Business</option>
                  <option value="enterprise">Enterprise</option>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label">Tono del agente</label>
                <select class="form-select" id="cn-tone">
                  <?php foreach ($toneLabels as $val => $label): ?>
                    <option value="<?= e($val) ?>"><?= e($label) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-12">
                <label class="form-label">
                  Objetivo del agente
                  <span class="help-tip" data-bs-toggle="popover"
                    data-bs-content="Define qué debe lograr el agente en cada llamada. Ej: &quot;Agendar una cita dental de primera vez&quot;">?</span>
                </label>
                <textarea class="form-control" id="cn-objective" rows="2"
                          placeholder="Ej: Agendar consultas, calificar leads y responder dudas sobre precios"></textarea>
              </div>
              <div class="col-12">
                <label class="form-label">Saludo inicial del agente</label>
                <input class="form-control" id="cn-greeting"
                       placeholder="Ej: Hola, bienvenido a Clínica Sonrisa, ¿en qué le puedo ayudar?"/>
              </div>
            </div>
          </div>

          <!-- Tab: Admin -->
          <div class="tab-pane fade" id="ct-admin">
            <div class="alert alert-info d-flex gap-2 align-items-start mb-4" style="font-size:.88rem">
              <i class="bx bx-info-circle flex-shrink-0 mt-1"></i>
              <span>Se creará un usuario administrador con acceso completo al panel del tenant.</span>
            </div>
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label">Nombre del admin <span class="text-danger">*</span></label>
                <input class="form-control" id="cn-admin-name" placeholder="Ej. Juan Pérez"/>
              </div>
              <div class="col-md-6">
                <label class="form-label">Email <span class="text-danger">*</span></label>
                <input class="form-control" id="cn-admin-email" type="email" placeholder="admin@clinica.com"/>
              </div>
              <div class="col-md-6">
                <label class="form-label">Contraseña <span class="text-danger">*</span></label>
                <div class="input-group">
                  <input class="form-control" id="cn-admin-pass" type="password" placeholder="Mín. 8 caracteres"/>
                  <button class="btn btn-outline-secondary" type="button"
                    onclick="togglePass('cn-admin-pass', this)">
                    <i class="bx bx-show"></i>
                  </button>
                </div>
              </div>
              <div class="col-md-6">
                <label class="form-label">&nbsp;</label>
                <button type="button" class="btn btn-outline-secondary d-block" onclick="genPassword()">
                  <i class="bx bx-dice-5 me-1"></i> Generar contraseña
                </button>
              </div>
            </div>
          </div>

          <!-- Tab: Límites -->
          <div class="tab-pane fade" id="ct-limites">
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label">
                  Máx. agentes IA
                  <span class="help-tip" data-bs-toggle="popover"
                    data-bs-content="Número máximo de agentes que el tenant puede activar simultáneamente.">?</span>
                </label>
                <input type="number" class="form-control" id="cn-max-agents" value="1" min="1" max="50"/>
              </div>
              <div class="col-md-6">
                <label class="form-label">
                  Máx. minutos/mes
                  <span class="help-tip" data-bs-toggle="popover"
                    data-bs-content="Límite mensual de minutos de llamadas o conversaciones.">?</span>
                </label>
                <input type="number" class="form-control" id="cn-max-mins" value="300" min="100" step="100"/>
              </div>
            </div>
          </div>

        </div><!-- /tab-content -->
      </div><!-- /modal-body -->
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-primary" id="createTenantBtn">
          <i class="bx bx-buildings me-1"></i> Crear Tenant
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ─────────────────────────────────────────────────────────────────────────
     MODAL: Editar tenant (tabs Básico + Perfil + Twilio)
────────────────────────────────────────────────────────────────────────── -->
<div class="modal fade" id="modalEditTenant" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bx bx-edit me-2"></i>Editar tenant</h5>
        <button class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="edit-tenant-id"/>

        <!-- Tabs -->
        <ul class="nav nav-tabs mb-4" id="editTabs">
          <li class="nav-item">
            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#et-basico">
              <i class="bx bx-cog me-1"></i> Básico
            </button>
          </li>
          <li class="nav-item">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#et-perfil">
              <i class="bx bx-briefcase me-1"></i> Perfil de negocio
            </button>
          </li>
        </ul>

        <div class="tab-content">

          <!-- Tab: Básico -->
          <div class="tab-pane fade show active" id="et-basico">
            <!-- Logo / avatar -->
            <div class="d-flex align-items-center gap-3 mb-4">
              <div class="avatar-upload-wrap">
                <img id="tenant-avatar-preview" src="/assets/img/avatar-placeholder.svg"
                     class="avatar-preview" alt="Logo" style="width:64px;height:64px;object-fit:cover;border-radius:12px">
                <label for="tenant-avatar-file" class="avatar-edit-btn" title="Subir logo">
                  <i class="bx bx-camera"></i>
                </label>
                <input type="file" id="tenant-avatar-file" accept="image/*" class="d-none">
              </div>
              <div class="small text-muted">
                <div class="fw-semibold mb-1">Logo del tenant</div>
                <div>JPG, PNG o WebP — máx. 4 MB</div>
                <button type="button" class="btn btn-link btn-sm p-0 text-danger d-none"
                        id="tenant-avatar-remove">Quitar logo</button>
              </div>
            </div>

            <div class="row g-3">
              <div class="col-12">
                <label class="form-label">Nombre del tenant</label>
                <input class="form-control" id="edit-tenant-name"/>
              </div>
              <div class="col-md-6">
                <label class="form-label">Plan</label>
                <select class="form-select" id="edit-tenant-plan">
                  <option value="trial">Trial</option>
                  <option value="starter">Starter</option>
                  <option value="growth">Growth</option>
                  <option value="business">Business</option>
                  <option value="enterprise">Enterprise</option>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label">Estado</label>
                <select class="form-select" id="edit-tenant-status">
                  <option value="active">Activo</option>
                  <option value="trial">Trial</option>
                  <option value="suspended">Suspendido</option>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label">Máx. agentes</label>
                <input type="number" class="form-control" id="edit-tenant-max-agents" min="1" max="50"/>
              </div>
              <div class="col-md-6">
                <label class="form-label">Máx. minutos/mes</label>
                <input type="number" class="form-control" id="edit-tenant-max-mins" min="100" step="100"/>
              </div>
            </div>
          </div>

          <!-- Tab: Perfil de negocio -->
          <div class="tab-pane fade" id="et-perfil">
            <div class="alert alert-warning d-flex gap-2 align-items-start mb-4" style="font-size:.88rem">
              <i class="bx bx-lock-alt flex-shrink-0 mt-1"></i>
              <span>Solo el superadmin puede modificar el perfil de negocio. Estos datos determinan el comportamiento del agente IA para todo el tenant.</span>
            </div>
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label">Industria / Nicho</label>
                <select class="form-select" id="edit-industry">
                  <?php foreach ($industries as $val => $label): ?>
                    <option value="<?= e($val) ?>"><?= e($label) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label">Nombre comercial</label>
                <input class="form-control" id="edit-bizname" placeholder="Nombre que usará el agente"/>
              </div>
              <div class="col-md-6">
                <label class="form-label">Tono del agente</label>
                <select class="form-select" id="edit-tone">
                  <?php foreach ($toneLabels as $val => $label): ?>
                    <option value="<?= e($val) ?>"><?= e($label) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-12">
                <label class="form-label">
                  Objetivo del agente
                  <span class="help-tip" data-bs-toggle="popover"
                    data-bs-content="Define qué debe lograr el agente en cada conversación. Ej: &quot;Agendar una cita de primera vez y capturar el nombre del paciente.&quot;">?</span>
                </label>
                <textarea class="form-control" id="edit-objective" rows="3"
                          placeholder="Ej: Agendar consultas, calificar leads y responder preguntas de precio"></textarea>
              </div>
              <div class="col-12">
                <label class="form-label">Saludo inicial</label>
                <input class="form-control" id="edit-greeting"
                       placeholder="Ej: Hola, bienvenido a Clínica Sonrisa, ¿en qué le puedo ayudar?"/>
              </div>
            </div>
          </div>

        </div><!-- /tab-content -->
      </div><!-- /modal-body -->
      <div class="modal-footer d-flex justify-content-between">
        <div class="text-muted small" id="editSaveStatus"></div>
        <div class="d-flex gap-2">
          <button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button class="btn btn-primary" id="saveTenantBtn">
            <i class="bx bx-save me-1"></i> Guardar cambios
          </button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ─────────────────────────────────────────────────────────────────────────
     MODAL: Features on/off por tenant (Fase C)
────────────────────────────────────────────────────────────────────────── -->
<div class="modal fade" id="modalFeatures" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bx bx-toggle-left me-2 text-info"></i>Features · <span id="feat-tenant-name"></span></h5>
        <button class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="feat-tenant-id"/>
        <p class="text-muted small">
          Cada feature hereda del plan <span id="feat-plan-badge" class="badge bg-label-secondary">—</span> por defecto.
          Cambia a <strong>Activado</strong> / <strong>Desactivado</strong> para forzar un override solo en este tenant.
        </p>
        <div id="feat-list" class="vstack gap-2">
          <div class="text-center text-muted py-4">
            <span class="spinner-border spinner-border-sm me-1"></span> Cargando…
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-info" id="saveFeaturesBtn"><i class="bx bx-save me-1"></i> Guardar features</button>
      </div>
    </div>
  </div>
</div>

<!-- ─────────────────────────────────────────────────────────────────────────
     MODAL: Eliminar tenant (Fase C)
────────────────────────────────────────────────────────────────────────── -->
<div class="modal fade" id="modalDeleteTenant" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-label-danger">
        <h5 class="modal-title text-danger"><i class="bx bx-error-circle me-2"></i>Eliminar tenant</h5>
        <button class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="del-tenant-id"/>
        <div class="alert alert-danger d-flex gap-2 align-items-start" style="font-size:.88rem">
          <i class="bx bx-trash flex-shrink-0 mt-1"></i>
          <span>Esta acción es <strong>permanente</strong>. Se borrarán usuarios, agentes, conversaciones,
          leads y todo el historial del tenant <strong id="del-tenant-name"></strong>. Los números Twilio
          asignados se liberarán.</span>
        </div>
        <label class="form-label">Para confirmar, escribe el slug del tenant: <code id="del-tenant-slug"></code></label>
        <input class="form-control font-monospace" id="del-confirm" placeholder="slug-del-tenant" autocomplete="off"/>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-danger" id="confirmDeleteBtn" disabled>
          <i class="bx bx-trash me-1"></i> Eliminar definitivamente
        </button>
      </div>
    </div>
  </div>
</div>

<style>
.btn-xs { padding: .2rem .45rem; font-size: .75rem; }
.avatar-upload-wrap { position:relative; display:inline-block; }
.avatar-preview     { width:64px; height:64px; object-fit:cover; border-radius:12px; border:2px solid #e9ecef; }
.avatar-edit-btn    {
  position:absolute; bottom:-6px; right:-6px;
  background:#696cff; color:#fff; border-radius:50%;
  width:22px; height:22px; display:flex; align-items:center; justify-content:center;
  font-size:.7rem; cursor:pointer;
}
</style>

<script>
// ── Búsqueda en tabla ──────────────────────────────────────────────────────
document.getElementById('searchTenant').addEventListener('input', function() {
  const q = this.value.toLowerCase();
  document.querySelectorAll('#tenantsTable tbody tr[data-name]').forEach(row => {
    row.style.display = row.dataset.name.includes(q) ? '' : 'none';
  });
});

// ── Auto-slug desde nombre ─────────────────────────────────────────────────
function autoSlug() {
  const name = document.getElementById('cn-name').value;
  const slug = name.toLowerCase()
    .normalize('NFD').replace(/[̀-ͯ]/g, '')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '');
  document.getElementById('cn-slug').value = slug;
}

// ── Toggle contraseña visible ──────────────────────────────────────────────
function togglePass(inputId, btn) {
  const inp = document.getElementById(inputId);
  const shown = inp.type === 'text';
  inp.type = shown ? 'password' : 'text';
  btn.innerHTML = shown ? '<i class="bx bx-show"></i>' : '<i class="bx bx-hide"></i>';
}

// ── Generar contraseña aleatoria ───────────────────────────────────────────
function genPassword() {
  const chars = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#$%&';
  const pwd   = Array.from({ length: 14 }, () => chars[Math.floor(Math.random() * chars.length)]).join('');
  const inp   = document.getElementById('cn-admin-pass');
  inp.value = pwd;
  inp.type  = 'text';
}

// ── Avatar upload (edit modal) ─────────────────────────────────────────────
const AVATAR_PLACEHOLDER = '/assets/img/avatar-placeholder.svg';
let pendingTenantAvatar = null;

function setTenantAvatarPreview(url) {
  const img = document.getElementById('tenant-avatar-preview');
  const rem = document.getElementById('tenant-avatar-remove');
  if (url) {
    img.src = url.startsWith('/uploads/') ? (window.AC_BACKEND_ROOT || '') + url : url;
    rem.classList.remove('d-none');
  } else {
    img.src = AVATAR_PLACEHOLDER;
    rem.classList.add('d-none');
  }
}

document.getElementById('tenant-avatar-file').addEventListener('change', e => {
  const f = e.target.files[0];
  if (!f) return;
  if (f.size > 4 * 1024 * 1024) { showToast('Máx 4 MB', 'danger'); return; }
  pendingTenantAvatar = f;
  setTenantAvatarPreview(URL.createObjectURL(f));
});

document.getElementById('tenant-avatar-remove').addEventListener('click', () => {
  pendingTenantAvatar = null;
  setTenantAvatarPreview(null);
});

// ── Abrir modal CREAR ──────────────────────────────────────────────────────
function openCreateModal() {
  ['cn-name','cn-slug','cn-bizname','cn-objective','cn-greeting','cn-admin-name','cn-admin-email','cn-admin-pass']
    .forEach(id => { const el = document.getElementById(id); if (el) el.value = ''; });
  document.getElementById('cn-industry').value = '';
  document.getElementById('cn-plan').value     = 'starter';
  document.getElementById('cn-tone').value     = 'professional';
  document.getElementById('cn-max-agents').value = '1';
  document.getElementById('cn-max-mins').value   = '300';
  // activar primer tab
  bootstrap.Tab.getInstance(document.querySelector('#createTabs .nav-link.active'))?.show();
  document.querySelector('#createTabs .nav-link').click();
  new bootstrap.Modal(document.getElementById('modalCreateTenant')).show();
}

// ── Crear tenant ───────────────────────────────────────────────────────────
document.getElementById('createTenantBtn').addEventListener('click', async () => {
  const name      = document.getElementById('cn-name').value.trim();
  const slug      = document.getElementById('cn-slug').value.trim();
  const adminEmail= document.getElementById('cn-admin-email').value.trim();
  const adminPass = document.getElementById('cn-admin-pass').value;

  if (!name || !slug || !adminEmail || !adminPass) {
    showToast('Completa los campos obligatorios (nombre, slug, email y contraseña)', 'warning');
    return;
  }
  if (adminPass.length < 8) {
    showToast('La contraseña debe tener al menos 8 caracteres', 'warning');
    return;
  }

  const btn = document.getElementById('createTenantBtn');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Creando…';

  try {
    const res = await fetch('/api/superadmin-create-tenant.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        name,
        slug,
        plan:          document.getElementById('cn-plan').value,
        industry:      document.getElementById('cn-industry').value,
        businessName:  document.getElementById('cn-bizname').value.trim() || name,
        tone:          document.getElementById('cn-tone').value,
        objective:     document.getElementById('cn-objective').value.trim(),
        greeting:      document.getElementById('cn-greeting').value.trim(),
        adminName:     document.getElementById('cn-admin-name').value.trim(),
        adminEmail,
        adminPassword: adminPass,
        maxAgents:     parseInt(document.getElementById('cn-max-agents').value),
        maxMinutes:    parseInt(document.getElementById('cn-max-mins').value),
      }),
    });

    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Error al crear tenant');

    showToast(`<i class="bx bx-buildings me-1"></i>Tenant "<strong>${name}</strong>" creado exitosamente`, 'success');
    bootstrap.Modal.getInstance(document.getElementById('modalCreateTenant')).hide();
    location.reload();
  } catch (err) {
    showToast(err.message, 'danger');
  } finally {
    btn.disabled = false;
    btn.innerHTML = '<i class="bx bx-buildings me-1"></i> Crear Tenant';
  }
});

// ── Abrir modal EDITAR ─────────────────────────────────────────────────────
function editTenant(id, name, plan, status, maxAgents, maxMins, avatarUrl, settings) {
  document.getElementById('edit-tenant-id').value           = id;
  document.getElementById('edit-tenant-name').value         = name;
  document.getElementById('edit-tenant-plan').value         = plan;
  document.getElementById('edit-tenant-status').value       = status;
  document.getElementById('edit-tenant-max-agents').value   = maxAgents;
  document.getElementById('edit-tenant-max-mins').value     = maxMins;

  // Perfil de negocio
  if (settings) {
    document.getElementById('edit-industry').value  = settings.industry  || '';
    document.getElementById('edit-bizname').value   = settings.businessName || name;
    document.getElementById('edit-tone').value      = settings.tone      || 'professional';
    document.getElementById('edit-objective').value = settings.objective || '';
    document.getElementById('edit-greeting').value  = settings.greeting  || '';
  }

  pendingTenantAvatar = null;
  setTenantAvatarPreview(avatarUrl || null);
  document.getElementById('editSaveStatus').textContent = '';

  // activar primer tab
  document.querySelector('#editTabs .nav-link').click();
  new bootstrap.Modal(document.getElementById('modalEditTenant')).show();
}

// ── Guardar tenant (básico + perfil) ──────────────────────────────────────
document.getElementById('saveTenantBtn').addEventListener('click', async () => {
  const id  = document.getElementById('edit-tenant-id').value;
  const btn = document.getElementById('saveTenantBtn');
  const statusEl = document.getElementById('editSaveStatus');

  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Guardando…';
  statusEl.textContent = '';

  try {
    // 1. Guardar datos básicos
    const basicRes = await fetch('/api/superadmin-tenant.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        id,
        action:         'basic',
        name:           document.getElementById('edit-tenant-name').value,
        plan:           document.getElementById('edit-tenant-plan').value,
        status:         document.getElementById('edit-tenant-status').value,
        max_agents:     parseInt(document.getElementById('edit-tenant-max-agents').value),
        max_minutes_mo: parseInt(document.getElementById('edit-tenant-max-mins').value),
      }),
    });
    if (!basicRes.ok) throw new Error('Error al guardar datos básicos');

    // 2. Guardar perfil de negocio en settings
    const settingsRes = await fetch('/api/superadmin-tenant.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        id,
        action: 'settings',
        businessProfile: {
          industry:     document.getElementById('edit-industry').value,
          businessName: document.getElementById('edit-bizname').value,
        },
        tone:      document.getElementById('edit-tone').value,
        objective: document.getElementById('edit-objective').value,
        greeting:  document.getElementById('edit-greeting').value,
      }),
    });
    if (!settingsRes.ok) throw new Error('Error al guardar perfil de negocio');

    // 3. Subir avatar si hay pendiente
    if (pendingTenantAvatar && id) {
      const fd = new FormData();
      fd.append('file', pendingTenantAvatar);
      fd.append('entity', 'tenant');
      fd.append('id', id);
      await fetch('/api/avatar-upload.php', { method: 'POST', body: fd }).catch(() => {});
    }

    bootstrap.Modal.getInstance(document.getElementById('modalEditTenant')).hide();

    // Actualizar fila en DOM
    const row = document.querySelector(`tr[data-id="${id}"]`);
    if (row) {
      const newName   = document.getElementById('edit-tenant-name').value;
      const newPlan   = document.getElementById('edit-tenant-plan').value;
      const newStatus = document.getElementById('edit-tenant-status').value;
      const newBiz    = document.getElementById('edit-bizname').value || newName;
      const newMaxAg  = parseInt(document.getElementById('edit-tenant-max-agents').value);

      const cells = row.querySelectorAll('td');
      // Col 0: nombre comercial
      const nameEl = cells[0]?.querySelector('.fw-semibold');
      if (nameEl) nameEl.textContent = newBiz;
      // Col 2: plan badge
      if (cells[2]) cells[2].innerHTML = planBadgeJs(newPlan);
      // Col 3: status badge
      if (cells[3]) cells[3].innerHTML = `<div class="d-flex align-items-center gap-1">${statusBadgeJs(newStatus)}</div>`;
      // Col 5: max agents (format: current/max)
      if (cells[5]) {
        const parts = cells[5].textContent.split('/');
        cells[5].textContent = `${parts[0].trim()}/${newMaxAg}`;
      }
      // data-name para búsqueda
      const slug = row.querySelector('small')?.textContent?.replace('@','') || '';
      row.dataset.name = `${newName} ${newBiz} ${slug}`.toLowerCase();

      // Si el status cambia, actualizar botón toggle también
      if (newStatus !== row.dataset.currentStatus) {
        const actionsCell = cells[9];
        const toggleBtn = actionsCell?.querySelector('.toggle-status-btn');
        if (toggleBtn) {
          if (newStatus === 'suspended') {
            toggleBtn.className = 'btn btn-xs btn-outline-success toggle-status-btn';
            toggleBtn.title = 'Activar tenant';
            toggleBtn.setAttribute('onclick', `toggleStatus('${id}','active')`);
            toggleBtn.innerHTML = '<i class="bx bx-check-circle"></i>';
          } else {
            toggleBtn.className = 'btn btn-xs btn-outline-warning toggle-status-btn';
            toggleBtn.title = 'Suspender tenant';
            toggleBtn.setAttribute('onclick', `toggleStatus('${id}','suspended')`);
            toggleBtn.innerHTML = '<i class="bx bx-block"></i>';
          }
        }
      }
    }

    showToast('<i class="bx bx-save me-1"></i>Tenant actualizado correctamente', 'success');

  } catch (err) {
    showToast(err.message, 'danger');
  } finally {
    btn.disabled = false;
    btn.innerHTML = '<i class="bx bx-save me-1"></i> Guardar cambios';
  }
});

// ── Badge helpers JS (mirrors PHP planBadge / statusBadge) ────────────────
function planBadgeJs(plan) {
  const m = { trial:'bg-label-info', starter:'bg-label-secondary', growth:'bg-label-primary',
               business:'bg-label-success', enterprise:'bg-warning text-dark' };
  const l = { trial:'Trial', starter:'Starter', growth:'Growth', business:'Business', enterprise:'Enterprise' };
  return `<span class="badge ${m[plan]||'bg-label-secondary'}">${l[plan]||plan}</span>`;
}
function statusBadgeJs(status) {
  const m = { active:'bg-label-success', trial:'bg-label-info', suspended:'bg-label-danger' };
  const l = { active:'Activo', trial:'Trial', suspended:'Suspendido' };
  return `<span class="badge ${m[status]||'bg-label-secondary'}">${l[status]||status}</span>`;
}

// confirmToast → window.confirmToast (definido en dashboard.js)
const confirmToast = (...args) => window.confirmToast?.(...args);

// ── Toggle status rápido ───────────────────────────────────────────────────
async function toggleStatus(id, newStatus) {
  const isSuspend = newStatus === 'suspended';
  const ok = await confirmToast(
    `<i class="bx ${isSuspend ? 'bx-block' : 'bx-check-circle'} me-1"></i>
     ¿Confirmas <strong>${isSuspend ? 'suspender' : 'activar'}</strong> este tenant?`,
    isSuspend ? 'Sí, suspender' : 'Sí, activar',
    isSuspend ? 'btn-danger' : 'btn-success'
  );
  if (!ok) return;

  try {
    const res = await fetch('/api/superadmin-tenant.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id, action: 'status', status: newStatus }),
    });
    if (!res.ok) throw new Error();

    // DOM: actualizar badge de estado
    const row = document.querySelector(`tr[data-id="${id}"]`);
    if (row) {
      const cells = row.querySelectorAll('td');
      // Col 3: badge de estado
      if (cells[3]) cells[3].innerHTML = `<div class="d-flex align-items-center gap-1">${statusBadgeJs(newStatus)}</div>`;
      // Col 9: botón toggle
      const actionsCell = cells[9];
      if (actionsCell) {
        const toggleBtn = actionsCell.querySelector('.toggle-status-btn');
        if (toggleBtn) {
          if (isSuspend) {
            toggleBtn.className     = 'btn btn-xs btn-outline-success toggle-status-btn';
            toggleBtn.title         = 'Activar tenant';
            toggleBtn.setAttribute('onclick', `toggleStatus('${id}','active')`);
            toggleBtn.innerHTML     = '<i class="bx bx-check-circle"></i>';
          } else {
            toggleBtn.className     = 'btn btn-xs btn-outline-warning toggle-status-btn';
            toggleBtn.title         = 'Suspender tenant';
            toggleBtn.setAttribute('onclick', `toggleStatus('${id}','suspended')`);
            toggleBtn.innerHTML     = '<i class="bx bx-block"></i>';
          }
        }
      }
    }

    showToast(`<i class="bx bx-check-circle me-1"></i>Tenant ${isSuspend ? 'suspendido' : 'activado'}`, 'success');
  } catch {
    showToast('Error al cambiar el estado', 'danger');
  }
}

// ── Toast helper (proxy al global de dashboard.js) ─────────────────────────
function showToast(msg, type = 'info') { window.showToast?.(msg, type); }

// ════════════════════════════════════════════════════════════════════════
// FASE C — Features on/off por tenant
// ════════════════════════════════════════════════════════════════════════
const featModal = () => bootstrap.Modal.getOrCreateInstance(document.getElementById('modalFeatures'));

async function openFeatures(id, name) {
  document.getElementById('feat-tenant-id').value = id;
  document.getElementById('feat-tenant-name').textContent = name || '';
  document.getElementById('feat-list').innerHTML =
    '<div class="text-center text-muted py-4"><span class="spinner-border spinner-border-sm me-1"></span> Cargando…</div>';
  featModal().show();

  try {
    const res = await fetch(`/api/superadmin-features.php?id=${encodeURIComponent(id)}`);
    const d   = await res.json();
    if (!res.ok) throw new Error(d.error || 'Error al cargar features');

    document.getElementById('feat-plan-badge').textContent = d.planKey || '—';
    const planSet   = new Set(d.planFeatures || []);
    const overrides = d.overrides || {};
    const catalog   = d.catalog || {};

    const rows = Object.entries(catalog).map(([key, label]) => {
      const inPlan = planSet.has(key);
      // estado override: undefined=hereda, true=on forzado, false=off forzado
      const ov  = (key in overrides) ? overrides[key] : null;
      const sel = ov === null ? 'inherit' : (ov ? 'on' : 'off');
      const planTag = inPlan
        ? '<span class="badge bg-label-success">incluida</span>'
        : '<span class="badge bg-label-secondary">no incluida</span>';
      return `
        <div class="d-flex align-items-center justify-content-between border rounded p-2">
          <div>
            <span class="fw-semibold">${label}</span>
            <code class="small text-muted ms-1">${key}</code>
            <div class="small mt-1">Plan: ${planTag}</div>
          </div>
          <select class="form-select form-select-sm feat-sel" data-key="${key}" style="max-width:150px">
            <option value="inherit" ${sel==='inherit'?'selected':''}>Heredar (plan)</option>
            <option value="on"      ${sel==='on'?'selected':''}>Activado</option>
            <option value="off"     ${sel==='off'?'selected':''}>Desactivado</option>
          </select>
        </div>`;
    }).join('');
    document.getElementById('feat-list').innerHTML = rows || '<p class="text-muted">Sin features.</p>';
  } catch (err) {
    document.getElementById('feat-list').innerHTML =
      `<div class="alert alert-danger mb-0">${err.message}</div>`;
  }
}

document.getElementById('saveFeaturesBtn').addEventListener('click', async () => {
  const id  = document.getElementById('feat-tenant-id').value;
  const btn = document.getElementById('saveFeaturesBtn');
  const features = {};
  document.querySelectorAll('#feat-list .feat-sel').forEach(sel => {
    const k = sel.dataset.key;
    features[k] = sel.value === 'inherit' ? null : (sel.value === 'on');
  });

  btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Guardando…';
  try {
    const res = await fetch('/api/superadmin-features.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id, features }),
    });
    const d = await res.json();
    if (!res.ok) throw new Error(d.error || 'Error al guardar');
    featModal().hide();
    showToast('<i class="bx bx-toggle-right me-1"></i>Features actualizadas', 'success');
  } catch (err) {
    showToast(err.message, 'danger');
  } finally {
    btn.disabled = false; btn.innerHTML = '<i class="bx bx-save me-1"></i> Guardar features';
  }
});

// ════════════════════════════════════════════════════════════════════════
// FASE C — Eliminar tenant
// ════════════════════════════════════════════════════════════════════════
const delModal = () => bootstrap.Modal.getOrCreateInstance(document.getElementById('modalDeleteTenant'));

function deleteTenant(id, slug, name) {
  document.getElementById('del-tenant-id').value     = id;
  document.getElementById('del-tenant-name').textContent = name || slug;
  document.getElementById('del-tenant-slug').textContent = slug;
  const input = document.getElementById('del-confirm');
  const btn   = document.getElementById('confirmDeleteBtn');
  input.value = '';
  btn.disabled = true;
  input.dataset.slug = slug;
  delModal().show();
  setTimeout(() => input.focus(), 300);
}

document.getElementById('del-confirm').addEventListener('input', function() {
  document.getElementById('confirmDeleteBtn').disabled = (this.value.trim() !== this.dataset.slug);
});

document.getElementById('confirmDeleteBtn').addEventListener('click', async () => {
  const id   = document.getElementById('del-tenant-id').value;
  const slug = document.getElementById('del-confirm').value.trim();
  const btn  = document.getElementById('confirmDeleteBtn');

  btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Eliminando…';
  try {
    const res = await fetch('/api/superadmin-delete-tenant.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id, confirm: slug }),
    });
    const d = await res.json();
    if (!res.ok) throw new Error(d.error || 'Error al eliminar');
    delModal().hide();
    document.querySelector(`tr[data-id="${id}"]`)?.remove();
    showToast('<i class="bx bx-trash me-1"></i>Tenant eliminado', 'success');
  } catch (err) {
    showToast(err.message, 'danger');
    btn.disabled = false; btn.innerHTML = '<i class="bx bx-trash me-1"></i> Eliminar definitivamente';
  }
});

// Inicializar popovers del modal dinámicamente
document.querySelectorAll('.modal').forEach(m => {
  m.addEventListener('shown.bs.modal', () => {
    m.querySelectorAll('.help-tip[data-bs-toggle="popover"]').forEach(el => {
      if (!bootstrap.Popover.getInstance(el)) {
        new bootstrap.Popover(el, { trigger: 'hover focus', placement: 'top', html: true, customClass: 'help-tip-popover' });
      }
    });
  });
});
</script>
</output>
