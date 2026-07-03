<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/head.php';
require_once __DIR__ . '/../includes/sidebar.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/footer.php';

requireAuth();

$user   = currentUser();
$tenant = currentTenant();
$role   = userRole();

$roleLabelMap = [
    'superadmin' => 'Superadmin',
    'admin'      => 'Administrador',
    'user'       => 'Usuario',
];
$roleLabel = $roleLabelMap[$role] ?? ucfirst($role);
$roleColor = match($role) {
    'superadmin' => 'warning',
    'admin'      => 'primary',
    default      => 'secondary',
};

$lastLogin = !empty($user['last_login_at'])
    ? (new DateTime($user['last_login_at']))->format('d/m/Y H:i')
    : 'Primera sesión';

$memberSince = !empty($user['created_at'])
    ? (new DateTime($user['created_at']))->format('d/m/Y')
    : '—';

renderHead('Mi perfil');
?>
<div class="layout-wrapper layout-content-navbar"><div class="layout-container">
<?php renderSidebar('profile'); ?>
<div class="layout-page"><?php renderNavbar('Mi perfil'); ?>
<div class="content-wrapper"><div class="container-xxl flex-grow-1 container-p-y">

  <!-- Toast global -->
  <div id="profileToast" style="position:fixed;top:1.2rem;right:1.2rem;z-index:9999;min-width:300px;display:none"></div>

  <div class="row g-4">

    <!-- ── Columna izquierda — Avatar + info de cuenta ──────────────── -->
    <div class="col-xl-4">

      <!-- Card: Avatar y datos rápidos -->
      <div class="card mb-4">
        <div class="card-body text-center pt-4 pb-3">

          <!-- Avatar upload -->
          <div class="d-flex justify-content-center mb-3">
            <div class="avatar-upload-wrap" style="position:relative;display:inline-block">
              <?php
              $avatarUrl = $user['avatar_url'] ?? null;
              if ($avatarUrl && strpos($avatarUrl, '/uploads/') === 0) {
                  $avatarUrl = BACKEND_ROOT . $avatarUrl;
              }
              ?>
              <img id="profileAvatarImg"
                   src="<?= $avatarUrl ? e($avatarUrl) : '/assets/img/avatar-placeholder.svg' ?>"
                   alt="<?= e($user['name'] ?? '') ?>"
                   class="rounded-circle"
                   style="width:100px;height:100px;object-fit:cover;border:3px solid #e9e9f0"/>
              <label for="avatarFileInput"
                     class="btn btn-sm btn-primary"
                     style="position:absolute;bottom:0;right:0;border-radius:50%;width:30px;height:30px;padding:0;display:flex;align-items:center;justify-content:center;cursor:pointer"
                     title="Cambiar foto">
                <i class="bx bx-camera" style="font-size:.9rem"></i>
              </label>
              <input type="file" id="avatarFileInput" accept="image/*" class="d-none"/>
            </div>
          </div>

          <h5 class="mb-0 fw-bold" id="profileNameDisplay"><?= e($user['name'] ?? '') ?></h5>
          <div class="text-muted small mb-2"><?= e($user['email'] ?? '') ?></div>

          <span class="badge bg-label-<?= $roleColor ?> mb-3">
            <?php if ($role === 'superadmin'): ?>
              <i class="bx bx-shield-alt-2 me-1"></i>
            <?php elseif ($role === 'admin'): ?>
              <i class="bx bx-user-check me-1"></i>
            <?php else: ?>
              <i class="bx bx-user me-1"></i>
            <?php endif; ?>
            <?= e($roleLabel) ?>
          </span>

          <hr class="my-2"/>

          <!-- Info rápida -->
          <div class="text-start px-2">
            <div class="d-flex justify-content-between py-2 border-bottom">
              <small class="text-muted">Tenant</small>
              <small class="fw-semibold"><?= e($tenant['name'] ?? '—') ?></small>
            </div>
            <div class="d-flex justify-content-between py-2 border-bottom">
              <small class="text-muted">Plan</small>
              <small><?= planBadge($tenant['plan'] ?? '') ?></small>
            </div>
            <div class="d-flex justify-content-between py-2 border-bottom">
              <small class="text-muted">Último acceso</small>
              <small class="fw-semibold"><?= e($lastLogin) ?></small>
            </div>
            <div class="d-flex justify-content-between py-2">
              <small class="text-muted">Miembro desde</small>
              <small class="fw-semibold"><?= e($memberSince) ?></small>
            </div>
          </div>

        </div>
      </div>

      <!-- Card: Uso de minutos (si admin) -->
      <?php if (isAdmin()):
        $used    = (int)($tenant['minutes_used_mo'] ?? 0);
        $max     = (int)($tenant['max_minutes_mo']  ?? 300);
        $pct     = $max > 0 ? min(100, round($used / $max * 100)) : 0;
        $barCls  = $pct >= 90 ? 'bg-danger' : ($pct >= 70 ? 'bg-warning' : 'bg-success');
      ?>
      <div class="card">
        <div class="card-body">
          <div class="d-flex align-items-center gap-2 mb-3">
            <div class="avatar avatar-sm">
              <span class="avatar-initial rounded-circle bg-label-info">
                <i class="bx bx-time"></i>
              </span>
            </div>
            <div>
              <div class="fw-semibold small">Consumo del mes</div>
              <div class="text-muted" style="font-size:.75rem">Minutos de llamadas</div>
            </div>
          </div>
          <div class="d-flex justify-content-between mb-1">
            <small class="text-muted"><?= $used ?> / <?= $max ?> min</small>
            <small class="fw-semibold <?= $pct >= 90 ? 'text-danger' : '' ?>"><?= $pct ?>%</small>
          </div>
          <div class="progress mb-2" style="height:6px">
            <div class="progress-bar <?= $barCls ?>" style="width:<?= $pct ?>%"></div>
          </div>
          <?php if ($pct >= 90): ?>
            <div class="alert alert-danger py-1 mb-0" style="font-size:.78rem">
              <i class="bx bx-error me-1"></i> Cerca del límite. Considera actualizar tu plan.
            </div>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

    </div><!-- /col-xl-4 -->

    <!-- ── Columna derecha — Formularios ────────────────────────────── -->
    <div class="col-xl-8">

      <!-- Card: Información personal -->
      <div class="card mb-4">
        <div class="card-header">
          <h6 class="mb-0"><i class="bx bx-user me-2 text-primary"></i>Información personal</h6>
        </div>
        <div class="card-body">
          <form id="profileInfoForm">
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label fw-semibold">Nombre completo</label>
                <input class="form-control" id="pi-name" value="<?= e($user['name'] ?? '') ?>"
                       placeholder="Tu nombre" required/>
              </div>
              <div class="col-md-6">
                <label class="form-label fw-semibold">Correo electrónico</label>
                <div class="input-group">
                  <input class="form-control" value="<?= e($user['email'] ?? '') ?>"
                         disabled readonly/>
                  <span class="input-group-text" title="El correo no se puede cambiar desde aquí">
                    <i class="bx bx-lock text-muted"></i>
                  </span>
                </div>
                <div class="form-text">El correo solo puede ser cambiado por el administrador.</div>
              </div>
              <div class="col-md-6">
                <label class="form-label fw-semibold">Rol</label>
                <input class="form-control bg-light" value="<?= e($roleLabel) ?>" disabled readonly/>
              </div>
              <div class="col-md-6">
                <label class="form-label fw-semibold">Tenant</label>
                <input class="form-control bg-light" value="<?= e($tenant['name'] ?? '—') ?>" disabled readonly/>
              </div>
            </div>
            <div class="d-flex justify-content-end mt-3">
              <button type="submit" class="btn btn-primary" id="saveInfoBtn">
                <i class="bx bx-save me-1"></i> Guardar nombre
              </button>
            </div>
          </form>
        </div>
      </div>

      <!-- Card: Cambiar contraseña -->
      <div class="card mb-4">
        <div class="card-header">
          <h6 class="mb-0"><i class="bx bx-lock-alt me-2 text-warning"></i>Seguridad — Cambiar contraseña</h6>
        </div>
        <div class="card-body">
          <form id="profilePassForm" autocomplete="off">
            <div class="row g-3">
              <div class="col-12">
                <label class="form-label fw-semibold">Contraseña actual</label>
                <div class="input-group">
                  <input type="password" class="form-control" id="pp-current"
                         placeholder="Tu contraseña actual" autocomplete="current-password"/>
                  <button class="btn btn-outline-secondary" type="button"
                    onclick="togglePass('pp-current', this)"><i class="bx bx-hide"></i></button>
                </div>
              </div>
              <div class="col-md-6">
                <label class="form-label fw-semibold">Nueva contraseña</label>
                <div class="input-group">
                  <input type="password" class="form-control" id="pp-new"
                         placeholder="Mín. 8 caracteres" autocomplete="new-password"
                         oninput="checkStrength()"/>
                  <button class="btn btn-outline-secondary" type="button"
                    onclick="togglePass('pp-new', this)"><i class="bx bx-hide"></i></button>
                </div>
                <div class="mt-1">
                  <div class="progress" style="height:3px">
                    <div id="strengthBar" class="progress-bar" style="width:0%;transition:width .3s,background .3s"></div>
                  </div>
                  <small id="strengthLabel" class="text-muted"></small>
                </div>
              </div>
              <div class="col-md-6">
                <label class="form-label fw-semibold">Confirmar nueva contraseña</label>
                <div class="input-group">
                  <input type="password" class="form-control" id="pp-confirm"
                         placeholder="Repite la contraseña" autocomplete="new-password"/>
                  <button class="btn btn-outline-secondary" type="button"
                    onclick="togglePass('pp-confirm', this)"><i class="bx bx-hide"></i></button>
                </div>
              </div>
            </div>
            <div class="d-flex justify-content-end mt-3">
              <button type="submit" class="btn btn-warning" id="savePassBtn">
                <i class="bx bx-lock me-1"></i> Cambiar contraseña
              </button>
            </div>
          </form>
        </div>
      </div>

      <!-- Card: Sesiones activas / info de sesión -->
      <div class="card">
        <div class="card-header">
          <h6 class="mb-0"><i class="bx bx-devices me-2 text-info"></i>Sesión actual</h6>
        </div>
        <div class="card-body">
          <div class="d-flex align-items-center gap-3">
            <div class="avatar avatar-md">
              <span class="avatar-initial rounded-circle bg-label-info">
                <i class="bx bx-desktop"></i>
              </span>
            </div>
            <div class="flex-grow-1">
              <div class="fw-semibold">Navegador actual</div>
              <div class="text-muted small" id="browserInfo">Detectando…</div>
            </div>
            <a href="/logout.php" class="btn btn-sm btn-outline-danger">
              <i class="bx bx-log-out me-1"></i> Cerrar sesión
            </a>
          </div>
        </div>
      </div>

    </div><!-- /col-xl-8 -->
  </div>

</div><?php renderFooter(); ?>

<script>
// ── Detectar navegador ────────────────────────────────────────────────────
(function() {
  const ua = navigator.userAgent;
  let browser = 'Navegador desconocido';
  if (ua.includes('Chrome') && !ua.includes('Edg')) browser = 'Google Chrome';
  else if (ua.includes('Firefox')) browser = 'Mozilla Firefox';
  else if (ua.includes('Safari') && !ua.includes('Chrome')) browser = 'Safari';
  else if (ua.includes('Edg')) browser = 'Microsoft Edge';
  const os = ua.includes('Windows') ? 'Windows' : ua.includes('Mac') ? 'macOS' : ua.includes('Linux') ? 'Linux' : 'SO desconocido';
  document.getElementById('browserInfo').textContent = `${browser} · ${os}`;
})();

// ── Toggle contraseña ─────────────────────────────────────────────────────
function togglePass(id, btn) {
  const inp = document.getElementById(id);
  const icon = btn.querySelector('i');
  if (inp.type === 'password') { inp.type = 'text'; icon.className = 'bx bx-show'; }
  else { inp.type = 'password'; icon.className = 'bx bx-hide'; }
}

// ── Strength meter ─────────────────────────────────────────────────────────
function checkStrength() {
  const pw  = document.getElementById('pp-new').value;
  const bar = document.getElementById('strengthBar');
  const lbl = document.getElementById('strengthLabel');
  let score = 0;
  if (pw.length >= 8)          score++;
  if (/[A-Z]/.test(pw))        score++;
  if (/[0-9]/.test(pw))        score++;
  if (/[^A-Za-z0-9]/.test(pw)) score++;
  const levels = [
    { w:'0%',   cls:'',           text:'' },
    { w:'25%',  cls:'bg-danger',  text:'Muy débil' },
    { w:'50%',  cls:'bg-warning', text:'Débil' },
    { w:'75%',  cls:'bg-info',    text:'Aceptable' },
    { w:'100%', cls:'bg-success', text:'Fuerte ✓' },
  ];
  const lvl = levels[Math.min(score, 4)];
  bar.style.width = lvl.w;
  bar.className = `progress-bar ${lvl.cls}`;
  lbl.textContent = lvl.text;
}

// ── Avatar upload ─────────────────────────────────────────────────────────
document.getElementById('avatarFileInput').addEventListener('change', async function(e) {
  const file = e.target.files[0];
  if (!file) return;
  if (file.size > 4 * 1024 * 1024) { showToast('La imagen no debe superar 4 MB', 'danger'); return; }

  const preview = document.getElementById('profileAvatarImg');
  preview.style.opacity = '.5';

  // Preview local inmediato
  const objUrl = URL.createObjectURL(file);
  preview.src = objUrl;

  try {
    // 1. Subir imagen al backend
    const fd = new FormData();
    fd.append('file', file);
    fd.append('entity', 'user');
    fd.append('id', <?= json_encode($user['id'] ?? '') ?>);

    const uploadRes  = await fetch('/api/avatar-upload.php', { method: 'POST', body: fd });
    const uploadData = await uploadRes.json();

    if (!uploadRes.ok || !uploadData.avatar_url) throw new Error(uploadData.error || 'Error al subir imagen');

    // 2. Actualizar avatar_url en el perfil
    const patchRes = await fetch('/api/profile-save.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ avatar_url: uploadData.avatar_url }),
    });
    if (!patchRes.ok) throw new Error('Error al guardar avatar');

    preview.style.opacity = '1';
    showToast('Foto de perfil actualizada', 'success');

  } catch (err) {
    preview.style.opacity = '1';
    showToast(err.message, 'danger');
  }
});

// ── Guardar nombre ─────────────────────────────────────────────────────────
document.getElementById('profileInfoForm').addEventListener('submit', async function(e) {
  e.preventDefault();
  const name = document.getElementById('pi-name').value.trim();
  if (!name || name.length < 2) { showToast('El nombre debe tener al menos 2 caracteres', 'warning'); return; }

  const btn = document.getElementById('saveInfoBtn');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Guardando…';

  try {
    const res  = await fetch('/api/profile-save.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ name }),
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Error al guardar');

    document.getElementById('profileNameDisplay').textContent = name;
    showToast('Nombre actualizado correctamente', 'success');
  } catch (err) {
    showToast(err.message, 'danger');
  } finally {
    btn.disabled = false;
    btn.innerHTML = '<i class="bx bx-save me-1"></i> Guardar nombre';
  }
});

// ── Cambiar contraseña ─────────────────────────────────────────────────────
document.getElementById('profilePassForm').addEventListener('submit', async function(e) {
  e.preventDefault();
  const current = document.getElementById('pp-current').value;
  const newPw   = document.getElementById('pp-new').value;
  const confirm = document.getElementById('pp-confirm').value;

  if (!current)         { showToast('Ingresa tu contraseña actual', 'warning');          return; }
  if (newPw.length < 8) { showToast('La nueva contraseña debe tener al menos 8 caracteres', 'warning'); return; }
  if (newPw !== confirm) { showToast('Las contraseñas no coinciden', 'warning');          return; }
  if (newPw === current) { showToast('La nueva contraseña debe ser diferente a la actual', 'warning'); return; }

  const btn = document.getElementById('savePassBtn');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Cambiando…';

  try {
    const res  = await fetch('/api/profile-save.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ current_password: current, new_password: newPw }),
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Error al cambiar contraseña');

    // Limpiar campos
    ['pp-current', 'pp-new', 'pp-confirm'].forEach(id => { document.getElementById(id).value = ''; });
    document.getElementById('strengthBar').style.width = '0%';
    document.getElementById('strengthLabel').textContent = '';
    showToast('Contraseña cambiada correctamente', 'success');
  } catch (err) {
    showToast(err.message, 'danger');
  } finally {
    btn.disabled = false;
    btn.innerHTML = '<i class="bx bx-lock me-1"></i> Cambiar contraseña';
  }
});

// ── Toast helper ───────────────────────────────────────────────────────────
function showToast(msg, type = 'info') {
  const wrap = document.getElementById('profileToast');
  const icon = type === 'success' ? 'bx-check-circle' : type === 'danger' ? 'bx-error-circle' : 'bx-info-circle';
  wrap.innerHTML = `
    <div class="alert alert-${type} alert-dismissible d-flex align-items-center gap-2 shadow mb-0" role="alert">
      <i class="bx ${icon} flex-shrink-0"></i>
      <span>${msg}</span>
      <button type="button" class="btn-close ms-auto" onclick="this.closest('.alert').parentElement.style.display='none'"></button>
    </div>`;
  wrap.style.display = 'block';
  setTimeout(() => { wrap.style.display = 'none'; }, 4500);
}
</script>
</output>
