<?php
/**
 * Super Admin — Usuarios de todos los negocios, separados por tenant.
 * Crear, activar/desactivar, cambiar rol y restablecer contraseña.
 */
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/head.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/navbar.php';
require_once __DIR__ . '/../../includes/footer.php';
require_once __DIR__ . '/../../includes/scope-selector.php';

requireAuth();
requireRole('superadmin');

$industry = trim($_GET['industry'] ?? '');
$tenantId = trim($_GET['tenantId'] ?? '');
if ($tenantId !== '' && !isValidUuid($tenantId)) $tenantId = '';

$verticals  = array_values(array_filter((array)apiGet('/superadmin/verticals'), 'is_array'));
$tenantsAll = array_values(array_filter((array)apiGet('/superadmin/tenants'), 'is_array'));

$sp = [];
if ($tenantId !== '') $sp['tenantId'] = $tenantId;
elseif ($industry !== '') $sp['industry'] = $industry;
$data  = apiGet('/superadmin/users', $sp);
$level = $data['level'] ?? 'global';
$users = is_array($data['users'] ?? null) ? $data['users'] : [];

// tenants disponibles para "crear" (filtrados por la vertical elegida)
$createTenants = array_values(array_filter($tenantsAll, function ($t) use ($industry) {
  if ($industry === '') return true;
  $ti = $t['settings']['businessProfile']['industry'] ?? $t['settings']['industry'] ?? '';
  return $ti === $industry;
}));

renderHead('Usuarios — Super Admin');
?>
<div class="layout-wrapper layout-content-navbar"><div class="layout-container">
<?php renderSidebar('sa-users'); ?>
<div class="layout-page"><?php renderNavbar('Usuarios'); ?>
<div class="content-wrapper"><div class="container-xxl flex-grow-1 container-p-y">

  <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <div>
      <h4 class="mb-1"><i class="bx bx-group me-1 text-primary"></i>Usuarios</h4>
      <p class="text-muted mb-0">Administra usuarios, roles y contraseñas de todos los negocios.</p>
    </div>
    <button class="btn btn-primary" onclick="openCreate()"><i class="bx bx-user-plus me-1"></i>Agregar usuario</button>
  </div>

  <?php renderScopeSelector([
    'industry'=>$industry, 'tenantId'=>$tenantId, 'verticals'=>$verticals,
    'tenants'=>$tenantsAll, 'level'=>$level, 'negocios'=>0,
  ]); ?>

  <div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
      <h6 class="mb-0"><?= count($users) ?> usuario(s)</h6>
      <input type="search" class="form-control form-control-sm" id="searchUser" placeholder="Buscar…" style="max-width:240px">
    </div>
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0" id="usersTable">
        <thead class="table-light">
          <tr><th>Usuario</th><th>Email</th><th>Rol</th><th>Último acceso</th><th class="text-center">Activo</th><th class="text-end">Acciones</th></tr>
        </thead>
        <tbody>
        <?php
        if (empty($users)):
          echo '<tr><td colspan="6" class="text-center text-muted py-5">Sin usuarios en este scope</td></tr>';
        else:
          $curTenant = null;
          foreach ($users as $u):
            // Separador por negocio (excepto cuando ya estás en un solo negocio)
            if ($level !== 'negocio' && $u['tenant_id'] !== $curTenant):
              $curTenant = $u['tenant_id'];
        ?>
          <tr class="table-light"><td colspan="6" class="fw-semibold py-1">
            <i class="bx bx-buildings me-1 text-muted"></i><?= e($u['tenant_name']) ?>
            <small class="text-muted">@<?= e($u['tenant_slug']) ?></small>
          </td></tr>
        <?php endif; ?>
          <tr data-search="<?= e(strtolower(($u['name']??'').' '.($u['email']??'').' '.($u['tenant_name']??''))) ?>">
            <td class="fw-semibold"><?= e($u['name'] ?? '—') ?></td>
            <td><small class="text-muted"><?= e($u['email'] ?? '') ?></small></td>
            <td><span class="badge bg-label-<?= ($u['role']??'')==='superadmin'?'warning':(($u['role']??'')==='admin'?'primary':'secondary') ?>"><?= e($u['role'] ?? '') ?></span></td>
            <td><small class="text-muted"><?= !empty($u['last_login_at']) ? formatDate($u['last_login_at'],'d/m/Y H:i') : 'Nunca' ?></small></td>
            <td class="text-center">
              <div class="form-check form-switch d-inline-block">
                <input class="form-check-input user-toggle" type="checkbox" data-uid="<?= e($u['id']) ?>" <?= !empty($u['is_active'])?'checked':'' ?>>
              </div>
            </td>
            <td class="text-end">
              <button class="btn btn-xs btn-outline-primary"
                onclick='openEdit(<?= htmlspecialchars(json_encode([
                  'id'=>$u['id'],'name'=>$u['name'],'email'=>$u['email'],'role'=>$u['role'],
                ]), ENT_QUOTES) ?>)'
                title="Editar / reset contraseña"><i class="bx bx-edit"></i></button>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div><?php renderFooter(); ?>

<!-- Modal: editar usuario / reset contraseña -->
<div class="modal fade" id="modalEditUser" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title"><i class="bx bx-edit me-2"></i>Editar usuario</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body">
    <input type="hidden" id="eu-id">
    <div class="mb-2"><label class="form-label">Email</label><input class="form-control" id="eu-email" disabled></div>
    <div class="row g-3">
      <div class="col-md-7"><label class="form-label">Nombre</label><input class="form-control" id="eu-name"></div>
      <div class="col-md-5"><label class="form-label">Rol</label>
        <select class="form-select" id="eu-role"><option value="user">Usuario</option><option value="admin">Admin</option></select></div>
      <div class="col-12">
        <label class="form-label">Nueva contraseña <small class="text-muted">(dejar vacío = sin cambio)</small></label>
        <div class="input-group">
          <input class="form-control" type="text" id="eu-pass" placeholder="Mín. 8 caracteres" autocomplete="off">
          <button class="btn btn-outline-secondary" type="button" onclick="genPass('eu-pass')"><i class="bx bx-dice-5"></i></button>
        </div>
      </div>
    </div>
  </div>
  <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" id="saveEditBtn">Guardar</button></div>
</div></div></div>

<!-- Modal: crear usuario -->
<div class="modal fade" id="modalCreateUser" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title"><i class="bx bx-user-plus me-2 text-primary"></i>Agregar usuario</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body"><div class="row g-3">
    <div class="col-12"><label class="form-label">Negocio (tenant) <span class="text-danger">*</span></label>
      <select class="form-select" id="cu-tenant">
        <?php foreach ($createTenants as $t): $bn = $t['settings']['businessProfile']['businessName'] ?? $t['name']; ?>
          <option value="<?= e($t['id']) ?>" <?= $tenantId===$t['id']?'selected':'' ?>><?= e($bn) ?> (@<?= e($t['slug']) ?>)</option>
        <?php endforeach; ?>
      </select></div>
    <div class="col-md-7"><label class="form-label">Nombre</label><input class="form-control" id="cu-name"></div>
    <div class="col-md-5"><label class="form-label">Rol</label><select class="form-select" id="cu-role"><option value="user">Usuario</option><option value="admin">Admin</option></select></div>
    <div class="col-12"><label class="form-label">Email <span class="text-danger">*</span></label><input class="form-control" type="email" id="cu-email"></div>
    <div class="col-12"><label class="form-label">Contraseña <span class="text-danger">*</span></label>
      <div class="input-group"><input class="form-control" type="text" id="cu-pass" placeholder="Mín. 8 caracteres">
        <button class="btn btn-outline-secondary" type="button" onclick="genPass('cu-pass')"><i class="bx bx-dice-5"></i></button></div></div>
  </div></div>
  <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" id="createUserBtn">Crear usuario</button></div>
</div></div></div>

</div></div></div>

<style>.btn-xs{padding:.2rem .45rem;font-size:.75rem}</style>
<script>
function toast(m,t='info'){ window.showToast?.(m,t); }
function genPass(id){ const c='abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#$%&'; document.getElementById(id).value=Array.from({length:14},()=>c[Math.floor(Math.random()*c.length)]).join(''); }

// Búsqueda
document.getElementById('searchUser').addEventListener('input', function(){
  const q=this.value.toLowerCase();
  document.querySelectorAll('#usersTable tbody tr[data-search]').forEach(r=>{ r.style.display = r.dataset.search.includes(q)?'':'none'; });
});

// Toggle activo
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

// Editar / reset password
const editModal=()=>bootstrap.Modal.getOrCreateInstance(document.getElementById('modalEditUser'));
function openEdit(u){
  document.getElementById('eu-id').value=u.id;
  document.getElementById('eu-email').value=u.email||'';
  document.getElementById('eu-name').value=u.name||'';
  document.getElementById('eu-role').value=(u.role==='admin')?'admin':'user';
  document.getElementById('eu-pass').value='';
  editModal().show();
}
document.getElementById('saveEditBtn').addEventListener('click', async function(){
  const id=document.getElementById('eu-id').value;
  const pass=document.getElementById('eu-pass').value;
  if(pass && pass.length<8){ toast('La contraseña debe tener al menos 8 caracteres','warning'); return; }
  const b=this; b.disabled=true; b.innerHTML='<span class="spinner-border spinner-border-sm me-1"></span>…';
  try{
    const payload={action:'update', user_id:id, name:document.getElementById('eu-name').value.trim(), role:document.getElementById('eu-role').value};
    if(pass) payload.password=pass;
    const r=await fetch('/api/superadmin-user.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
    const d=await r.json(); if(!r.ok) throw new Error(d.error||'Error');
    editModal().hide(); toast(pass?'Usuario actualizado · contraseña restablecida':'Usuario actualizado','success');
    setTimeout(()=>location.reload(),700);
  }catch(e){ toast(e.message,'danger'); b.disabled=false; b.innerHTML='Guardar'; }
});

// Crear
const createModal=()=>bootstrap.Modal.getOrCreateInstance(document.getElementById('modalCreateUser'));
function openCreate(){ ['cu-name','cu-email','cu-pass'].forEach(i=>document.getElementById(i).value=''); document.getElementById('cu-role').value='user'; createModal().show(); }
document.getElementById('createUserBtn').addEventListener('click', async function(){
  const tenant_id=document.getElementById('cu-tenant').value, email=document.getElementById('cu-email').value.trim(), pass=document.getElementById('cu-pass').value;
  if(!tenant_id){ toast('Selecciona un negocio','warning'); return; }
  if(!email||pass.length<8){ toast('Email y contraseña (mín. 8) requeridos','warning'); return; }
  const b=this; b.disabled=true; b.innerHTML='<span class="spinner-border spinner-border-sm me-1"></span>…';
  try{
    const r=await fetch('/api/superadmin-user.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({
      action:'create', tenant_id, name:document.getElementById('cu-name').value.trim(), email, password:pass, role:document.getElementById('cu-role').value})});
    const d=await r.json(); if(!r.ok) throw new Error(d.error||'Error');
    createModal().hide(); toast('Usuario creado','success'); setTimeout(()=>location.reload(),700);
  }catch(e){ toast(e.message,'danger'); b.disabled=false; b.innerHTML='Crear usuario'; }
});
</script>
