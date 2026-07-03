<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/head.php';
require_once __DIR__ . '/../includes/sidebar.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/footer.php';

requireAuth();
requireRole('admin', 'superadmin');

$raw   = apiGet('/users');
$users = array_values(array_filter((array)$raw, 'is_array'));
$me    = currentUser();

renderHead('Usuarios');
?>
<div class="layout-wrapper layout-content-navbar"><div class="layout-container">
<?php renderSidebar('users'); ?>
<div class="layout-page"><?php renderNavbar('Usuarios'); ?>
<div class="content-wrapper"><div class="container-xxl flex-grow-1 container-p-y">

  <!-- Header -->
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
    <div>
      <h4 class="mb-1">Usuarios del equipo</h4>
      <p class="text-muted mb-0"><?= count($users) ?> usuario<?= count($users) !== 1 ? 's' : '' ?> en esta cuenta</p>
    </div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalNewUser">
      <i class="bx bx-user-plus me-1"></i>Agregar usuario
    </button>
  </div>

  <!-- Tabla de usuarios -->
  <div class="card">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>Usuario</th>
            <th>Rol</th>
            <th>Estado</th>
            <th>Último acceso</th>
            <th>Creado</th>
            <th class="text-end">Acciones</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($users)): ?>
            <tr><td colspan="6" class="text-center text-muted py-5">
              <i class="bx bx-user-x bx-lg opacity-25 d-block mb-2"></i>
              No hay usuarios todavía
            </td></tr>
          <?php else: ?>
          <?php foreach ($users as $u):
            $isMe    = ($u['id'] ?? '') === ($me['id'] ?? '');
            $active  = (bool)($u['is_active'] ?? true);
            $role    = $u['role'] ?? 'user';
            $lastLogin = !empty($u['last_login_at'])
              ? (new DateTime($u['last_login_at']))->format('d/m/Y H:i')
              : 'Nunca';
            $created = !empty($u['created_at'])
              ? (new DateTime($u['created_at']))->format('d/m/Y')
              : '—';

            $roleBadge = match($role) {
              'superadmin' => '<span class="badge bg-warning text-dark"><i class="bx bx-shield-alt-2 me-1"></i>Superadmin</span>',
              'admin'      => '<span class="badge bg-label-primary"><i class="bx bx-user-check me-1"></i>Admin</span>',
              default      => '<span class="badge bg-label-secondary"><i class="bx bx-user me-1"></i>Usuario</span>',
            };
          ?>
          <tr class="user-row <?= !$active ? 'opacity-50' : '' ?>" data-id="<?= e($u['id']) ?>">
            <td>
              <div class="d-flex align-items-center gap-3">
                <?= avatar($u, 'sm') ?>
                <div>
                  <div class="fw-semibold">
                    <?= e($u['name'] ?? '') ?>
                    <?php if ($isMe): ?>
                      <span class="badge bg-label-info ms-1" style="font-size:.65rem">Tú</span>
                    <?php endif; ?>
                  </div>
                  <small class="text-muted"><?= e($u['email'] ?? '') ?></small>
                </div>
              </div>
            </td>
            <td><?= $roleBadge ?></td>
            <td>
              <?php if ($active): ?>
                <span class="badge bg-label-success"><i class="bx bx-check-circle me-1"></i>Activo</span>
              <?php else: ?>
                <span class="badge bg-label-danger"><i class="bx bx-x-circle me-1"></i>Inactivo</span>
              <?php endif; ?>
            </td>
            <td><small class="text-muted"><?= e($lastLogin) ?></small></td>
            <td><small class="text-muted"><?= e($created) ?></small></td>
            <td class="text-end">
              <div class="d-flex gap-1 justify-content-end">
                <?php if (!$isMe): ?>
                  <button class="btn btn-sm btn-outline-primary"
                          onclick="openEditModal(<?= htmlspecialchars(json_encode([
                            'id'         => $u['id'],
                            'name'       => $u['name'],
                            'email'      => $u['email'],
                            'role'       => $role,
                            'is_active'  => $active,
                            'avatar_url' => $u['avatar_url'] ?? null,
                          ]), ENT_QUOTES) ?>)"
                          title="Editar">
                    <i class="bx bx-edit"></i>
                  </button>
                  <?php if ($active): ?>
                    <button class="btn btn-sm btn-outline-danger"
                            onclick="deactivateUser('<?= e($u['id']) ?>', '<?= e($u['name']) ?>')"
                            title="Desactivar">
                      <i class="bx bx-user-x"></i>
                    </button>
                  <?php else: ?>
                    <button class="btn btn-sm btn-outline-success"
                            onclick="reactivateUser('<?= e($u['id']) ?>')"
                            title="Reactivar">
                      <i class="bx bx-user-check"></i>
                    </button>
                  <?php endif; ?>
                <?php endif; ?>
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

<!-- ── Modal: Nuevo usuario ──────────────────────────────────── -->
<div class="modal fade" id="modalNewUser" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bx bx-user-plus me-2"></i>Agregar usuario</h5>
        <button class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-12">
            <label class="form-label">Nombre completo <span class="text-danger">*</span></label>
            <input class="form-control" id="new-name" placeholder="Juan Pérez" autocomplete="off"/>
          </div>
          <div class="col-12">
            <label class="form-label">Correo electrónico <span class="text-danger">*</span></label>
            <input type="email" class="form-control" id="new-email" placeholder="juan@empresa.com" autocomplete="off"/>
          </div>
          <div class="col-md-6">
            <label class="form-label">Rol <span class="text-danger">*</span></label>
            <select class="form-select" id="new-role">
              <option value="user">Usuario — acceso limitado</option>
              <option value="admin">Admin — acceso completo</option>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label">Contraseña temporal <span class="text-danger">*</span></label>
            <div class="input-group">
              <input type="text" class="form-control" id="new-password" autocomplete="off"/>
              <button class="btn btn-outline-secondary" type="button" onclick="generatePassword()">
                <i class="bx bx-refresh"></i>
              </button>
            </div>
            <small class="text-muted">Mínimo 8 caracteres · Comparte con el usuario</small>
          </div>
          <!-- Alerta contraseña generada -->
          <div class="col-12 d-none" id="pw-alert">
            <div class="alert alert-success py-2 mb-0">
              <i class="bx bx-copy me-1"></i>
              Contraseña: <strong id="pw-display"></strong>
              <button class="btn btn-xs btn-outline-success ms-2" onclick="copyPassword()">Copiar</button>
            </div>
          </div>
          <!-- Zona de error -->
          <div class="col-12 d-none" id="new-user-error">
            <div class="alert alert-danger py-2 mb-0">
              <i class="bx bx-error-circle me-1"></i>
              <span id="new-user-error-msg"></span>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-primary" id="btnCreateUser">
          <i class="bx bx-user-plus me-1"></i>Crear usuario
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ── Modal: Editar usuario ─────────────────────────────────── -->
<div class="modal fade" id="modalEditUser" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bx bx-edit me-2"></i>Editar usuario</h5>
        <button class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="edit-id"/>
        <div class="d-flex align-items-center gap-3 mb-3">
          <div class="avatar-upload-wrap">
            <img id="user-avatar-preview" src="/assets/img/avatar-placeholder.svg"
                 class="avatar-preview" alt="Avatar">
            <label for="user-avatar-file" class="avatar-edit-btn" title="Subir foto">
              <i class="bx bx-camera"></i>
            </label>
            <input type="file" id="user-avatar-file" accept="image/*" class="d-none">
          </div>
          <div class="small text-muted">
            <div class="fw-semibold mb-1">Foto de perfil</div>
            <div>JPG, PNG o WebP — máx. 4 MB.</div>
            <button type="button" class="btn btn-link btn-sm p-0 text-danger d-none"
                    id="user-avatar-remove">Quitar foto</button>
          </div>
        </div>
        <div class="row g-3">
          <div class="col-12">
            <label class="form-label">Nombre completo</label>
            <input class="form-control" id="edit-name"/>
          </div>
          <div class="col-12">
            <label class="form-label">Correo electrónico</label>
            <input class="form-control" id="edit-email" readonly class="text-muted bg-light"/>
          </div>
          <div class="col-12">
            <label class="form-label">Rol</label>
            <select class="form-select" id="edit-role">
              <option value="user">Usuario — acceso limitado</option>
              <option value="admin">Admin — acceso completo</option>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label">Nueva contraseña <small class="text-muted">(dejar en blanco para no cambiar)</small></label>
            <div class="input-group">
              <input type="text" class="form-control" id="edit-password" placeholder="••••••••" autocomplete="off"/>
              <button class="btn btn-outline-secondary" type="button" onclick="generateEditPassword()">
                <i class="bx bx-refresh"></i>
              </button>
            </div>
          </div>
          <!-- Zona de error -->
          <div class="col-12 d-none" id="edit-user-error">
            <div class="alert alert-danger py-2 mb-0">
              <i class="bx bx-error-circle me-1"></i>
              <span id="edit-user-error-msg"></span>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-primary" id="btnUpdateUser">
          <i class="bx bx-save me-1"></i>Guardar cambios
        </button>
      </div>
    </div>
  </div>
</div>

<style>
  .btn-xs { padding: .2rem .45rem; font-size: .75rem; }
</style>

<script>
// ── Avatar ────────────────────────────────────────────────────
const AVATAR_PLACEHOLDER = '/assets/img/avatar-placeholder.svg';
let pendingUserAvatar = null;

function setUserAvatarPreview(url) {
  const img = document.getElementById('user-avatar-preview');
  const rem = document.getElementById('user-avatar-remove');
  if (url) {
    img.src = url.startsWith('/uploads/') ? (window.AC_BACKEND_ROOT || '') + url : url;
    rem.classList.remove('d-none');
  } else {
    img.src = AVATAR_PLACEHOLDER;
    rem.classList.add('d-none');
  }
}
document.getElementById('user-avatar-file').addEventListener('change', e => {
  const f = e.target.files[0];
  if (!f) return;
  if (f.size > 4 * 1024 * 1024) { window.showToast?.('La imagen no puede superar 4 MB', 'warning'); return; }
  pendingUserAvatar = f;
  setUserAvatarPreview(URL.createObjectURL(f));
});
document.getElementById('user-avatar-remove').addEventListener('click', () => {
  pendingUserAvatar = null;
  setUserAvatarPreview(null);
});

// ── Helpers ───────────────────────────────────────────────────
function randomPassword(length = 12) {
  const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789!@#$';
  return Array.from({ length }, () => chars[Math.floor(Math.random() * chars.length)]).join('');
}
function showModalError(zoneId, msgId, msg) {
  document.getElementById(msgId).textContent = msg;
  document.getElementById(zoneId).classList.remove('d-none');
}
function hideModalError(zoneId) {
  document.getElementById(zoneId).classList.add('d-none');
}
async function apiCall(payload) {
  const res  = await fetch('/api/user-save.php', {
    method: 'POST', credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(data.error || `Error ${res.status}`);
  return data;
}

// Badge helpers (mirrors PHP)
function roleBadgeHtml(role) {
  if (role === 'admin')
    return '<span class="badge bg-label-primary"><i class="bx bx-user-check me-1"></i>Admin</span>';
  return '<span class="badge bg-label-secondary"><i class="bx bx-user me-1"></i>Usuario</span>';
}
function activeBadgeHtml(active) {
  return active
    ? '<span class="badge bg-label-success"><i class="bx bx-check-circle me-1"></i>Activo</span>'
    : '<span class="badge bg-label-danger"><i class="bx bx-x-circle me-1"></i>Inactivo</span>';
}

// ── Contraseña ────────────────────────────────────────────────
function generatePassword() {
  const pw = randomPassword();
  document.getElementById('new-password').value     = pw;
  document.getElementById('pw-display').textContent = pw;
  document.getElementById('pw-alert').classList.remove('d-none');
}
function generateEditPassword() {
  document.getElementById('edit-password').value = randomPassword();
}
function copyPassword() {
  const pw = document.getElementById('new-password').value;
  navigator.clipboard.writeText(pw).then(() => {
    window.showToast?.('<i class="bx bx-copy me-1"></i>Contraseña copiada al portapapeles', 'success');
  });
}

// ── Limpiar modal al abrir ────────────────────────────────────
document.getElementById('modalNewUser').addEventListener('show.bs.modal', () => {
  ['new-name','new-email'].forEach(id => document.getElementById(id).value = '');
  document.getElementById('new-role').value = 'user';
  document.getElementById('pw-alert').classList.add('d-none');
  hideModalError('new-user-error');
  generatePassword();
});
document.getElementById('modalEditUser').addEventListener('show.bs.modal', () => {
  hideModalError('edit-user-error');
});

// ── Crear usuario ─────────────────────────────────────────────
document.getElementById('btnCreateUser').addEventListener('click', async () => {
  hideModalError('new-user-error');

  const name     = document.getElementById('new-name').value.trim();
  const email    = document.getElementById('new-email').value.trim();
  const role     = document.getElementById('new-role').value;
  const password = document.getElementById('new-password').value.trim();

  if (!name)               { showModalError('new-user-error','new-user-error-msg','El nombre es obligatorio'); return; }
  if (!email)              { showModalError('new-user-error','new-user-error-msg','El correo es obligatorio'); return; }
  if (!password)           { showModalError('new-user-error','new-user-error-msg','La contraseña es obligatoria'); return; }
  if (password.length < 8) { showModalError('new-user-error','new-user-error-msg','La contraseña debe tener al menos 8 caracteres'); return; }

  const btn = document.getElementById('btnCreateUser');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Creando...';

  try {
    const u = await apiCall({ name, email, role, password });
    bootstrap.Modal.getInstance(document.getElementById('modalNewUser')).hide();

    // Insertar fila en la tabla sin recargar
    const tbody = document.querySelector('table tbody');
    // Quitar el "empty state" si estaba
    const emptyRow = tbody.querySelector('td[colspan]')?.closest('tr');
    if (emptyRow) emptyRow.remove();

    const initials = name.charAt(0).toUpperCase();
    const tr = document.createElement('tr');
    tr.className = 'user-row';
    tr.dataset.id = u.id || '';
    tr.innerHTML = `
      <td>
        <div class="d-flex align-items-center gap-3">
          <div class="avatar avatar-sm"><div class="avatar-initial rounded-circle bg-label-primary">${initials}</div></div>
          <div>
            <div class="fw-semibold">${name}</div>
            <small class="text-muted">${email}</small>
          </div>
        </div>
      </td>
      <td>${roleBadgeHtml(role)}</td>
      <td>${activeBadgeHtml(true)}</td>
      <td><small class="text-muted">Nunca</small></td>
      <td><small class="text-muted">${new Date().toLocaleDateString('es-MX',{day:'2-digit',month:'2-digit',year:'numeric'})}</small></td>
      <td class="text-end">
        <div class="d-flex gap-1 justify-content-end" id="btns-${u.id || ''}">
          <button class="btn btn-sm btn-outline-primary"
                  onclick="openEditModal(${JSON.stringify({id:u.id,name,email,role,is_active:true,avatar_url:null}).replace(/"/g,'&quot;')})"
                  title="Editar"><i class="bx bx-edit"></i></button>
          <button class="btn btn-sm btn-outline-danger"
                  onclick="deactivateUser('${u.id}','${name.replace(/'/g,"\\'")}')"
                  title="Desactivar"><i class="bx bx-user-x"></i></button>
        </div>
      </td>`;
    tbody.insertBefore(tr, tbody.firstChild);

    // Actualizar contador en header
    const countEl = document.querySelector('p.text-muted.mb-0');
    if (countEl) {
      const n = document.querySelectorAll('tr.user-row').length;
      countEl.textContent = `${n} usuario${n !== 1 ? 's' : ''} en esta cuenta`;
    }
    window.showToast?.(`<i class="bx bx-user-plus me-1"></i>Usuario <strong>${name}</strong> creado`, 'success');
  } catch (err) {
    showModalError('new-user-error', 'new-user-error-msg', err.message);
  } finally {
    btn.disabled = false;
    btn.innerHTML = '<i class="bx bx-user-plus me-1"></i>Crear usuario';
  }
});

// ── Abrir modal editar ────────────────────────────────────────
function openEditModal(user) {
  document.getElementById('edit-id').value       = user.id;
  document.getElementById('edit-name').value     = user.name;
  document.getElementById('edit-email').value    = user.email;
  document.getElementById('edit-role').value     = user.role;
  document.getElementById('edit-password').value = '';
  pendingUserAvatar = null;
  setUserAvatarPreview(user.avatar_url || null);
  new bootstrap.Modal(document.getElementById('modalEditUser')).show();
}

// ── Guardar edición ───────────────────────────────────────────
document.getElementById('btnUpdateUser').addEventListener('click', async () => {
  hideModalError('edit-user-error');

  const id       = document.getElementById('edit-id').value;
  const name     = document.getElementById('edit-name').value.trim();
  const role     = document.getElementById('edit-role').value;
  const password = document.getElementById('edit-password').value.trim();

  if (!name) { showModalError('edit-user-error','edit-user-error-msg','El nombre es obligatorio'); return; }

  const payload = { id, name, role };
  if (password) payload.password = password;

  const btn = document.getElementById('btnUpdateUser');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Guardando...';

  try {
    await apiCall(payload);

    // Subir avatar si hay uno pendiente
    if (pendingUserAvatar && id) {
      const fd = new FormData();
      fd.append('file', pendingUserAvatar);
      fd.append('entity', 'user');
      fd.append('id', id);
      await fetch('/api/avatar-upload.php', { method:'POST', body: fd }).catch(()=>{});
    }

    bootstrap.Modal.getInstance(document.getElementById('modalEditUser')).hide();

    // Actualizar fila en DOM
    const row = document.querySelector(`tr.user-row[data-id="${id}"]`);
    if (row) {
      const cells = row.querySelectorAll('td');
      // Celda nombre: actualizar solo el texto del fw-semibold
      const nameEl = cells[0]?.querySelector('.fw-semibold');
      if (nameEl) {
        // Preservar el badge "Tú" si existe
        const tuBadge = nameEl.querySelector('.badge');
        nameEl.textContent = name;
        if (tuBadge) nameEl.appendChild(tuBadge);
      }
      // Celda rol
      if (cells[1]) cells[1].innerHTML = roleBadgeHtml(role);
    }

    window.showToast?.(`<i class="bx bx-save me-1"></i>Usuario <strong>${name}</strong> actualizado`, 'success');
  } catch (err) {
    showModalError('edit-user-error', 'edit-user-error-msg', err.message);
  } finally {
    btn.disabled = false;
    btn.innerHTML = '<i class="bx bx-save me-1"></i>Guardar cambios';
  }
});

// ── Desactivar / reactivar usuario ───────────────────────────
async function deactivateUser(id, name) {
  const confirmed = await window.confirmToast?.(
    `<i class="bx bx-user-x me-1"></i>¿Desactivar a <strong>${name}</strong>?
     <small class="d-block text-muted">No podrá iniciar sesión hasta que lo reactives.</small>`,
    'Sí, desactivar'
  );
  if (!confirmed) return;

  try {
    await apiCall({ id, action: 'delete' });

    // DOM: marcar fila como inactiva
    const row = document.querySelector(`tr.user-row[data-id="${id}"]`);
    if (row) {
      row.classList.add('opacity-50');
      const cells = row.querySelectorAll('td');
      if (cells[2]) cells[2].innerHTML = activeBadgeHtml(false);
      // Botón: cambiar desactivar por reactivar
      const btns = cells[5]?.querySelector('.d-flex');
      if (btns) {
        const deactBtn = btns.querySelector('.btn-outline-danger');
        if (deactBtn) {
          deactBtn.className = 'btn btn-sm btn-outline-success';
          deactBtn.title = 'Reactivar';
          deactBtn.setAttribute('onclick', `reactivateUser('${id}')`);
          deactBtn.innerHTML = '<i class="bx bx-user-check"></i>';
        }
      }
    }
    window.showToast?.(`<i class="bx bx-user-x me-1"></i>Usuario <strong>${name}</strong> desactivado`, 'warning');
  } catch (err) {
    window.showToast?.('Error al desactivar: ' + err.message, 'danger');
  }
}

async function reactivateUser(id) {
  try {
    await apiCall({ id, is_active: true });

    // DOM: marcar fila como activa
    const row = document.querySelector(`tr.user-row[data-id="${id}"]`);
    if (row) {
      row.classList.remove('opacity-50');
      const cells = row.querySelectorAll('td');
      if (cells[2]) cells[2].innerHTML = activeBadgeHtml(true);
      const btns = cells[5]?.querySelector('.d-flex');
      if (btns) {
        const reactBtn = btns.querySelector('.btn-outline-success');
        if (reactBtn) {
          // Recuperar el nombre del usuario desde la celda nombre
          const userName = cells[0]?.querySelector('.fw-semibold')?.textContent?.trim() || '';
          reactBtn.className = 'btn btn-sm btn-outline-danger';
          reactBtn.title = 'Desactivar';
          reactBtn.setAttribute('onclick', `deactivateUser('${id}','${userName.replace(/'/g,"\\'")}')`);
          reactBtn.innerHTML = '<i class="bx bx-user-x"></i>';
        }
      }
    }
    window.showToast?.('<i class="bx bx-user-check me-1"></i>Usuario reactivado', 'success');
  } catch (err) {
    window.showToast?.('Error al reactivar: ' + err.message, 'danger');
  }
}
</script>
