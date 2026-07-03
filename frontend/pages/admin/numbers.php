<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/head.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/navbar.php';
require_once __DIR__ . '/../../includes/footer.php';

requireAuth();
requireRole('superadmin');

$numbers  = array_values(array_filter((array)apiGet('/superadmin/numbers'), 'is_array'));
$tenants  = array_values(array_filter((array)apiGet('/superadmin/tenants'), 'is_array'));
// Números configurados FUERA del pool (.env, settings de tenants, agentes)
$detected = array_values(array_filter((array)apiGet('/superadmin/numbers/detected'), 'is_array'));

$assignedCount  = count(array_filter($numbers, fn($n) => ($n['status'] ?? '') === 'assigned'));
$availableCount = count($numbers) - $assignedCount;

renderHead('Números Twilio');
?>
<div class="layout-wrapper layout-content-navbar">
<div class="layout-container">
<?php renderSidebar('sa-numbers'); ?>
<div class="layout-page">
<?php renderNavbar('Números Twilio'); ?>
<div class="content-wrapper">
<div class="container-xxl flex-grow-1 container-p-y">

  <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
      <h4 class="mb-1"><i class="bx bx-phone me-1 text-primary"></i>Números Twilio</h4>
      <p class="text-muted mb-0">Pool de números de la plataforma y su asignación a tenants.</p>
    </div>
    <button class="btn btn-primary" onclick="openAddNumber()"><i class="bx bx-plus me-1"></i>Agregar número</button>
  </div>

  <div class="row g-3 mb-4">
    <div class="col-sm-4">
      <div class="card"><div class="card-body py-3">
        <div class="text-muted small">Total</div><div class="h4 mb-0"><?= count($numbers) ?></div>
      </div></div>
    </div>
    <div class="col-sm-4">
      <div class="card"><div class="card-body py-3">
        <div class="text-muted small">Asignados</div><div class="h4 mb-0 text-success"><?= $assignedCount ?></div>
      </div></div>
    </div>
    <div class="col-sm-4">
      <div class="card"><div class="card-body py-3">
        <div class="text-muted small">Disponibles</div><div class="h4 mb-0 text-primary"><?= $availableCount ?></div>
      </div></div>
    </div>
  </div>

  <!-- ── Números detectados (en uso fuera del pool) ──────────── -->
  <?php if (!empty($detected)): ?>
  <div class="card border-warning mb-4">
    <div class="card-header d-flex align-items-center gap-2 py-2">
      <i class="bx bx-search-alt text-warning"></i>
      <h6 class="mb-0">Números en uso (detectados fuera del pool)</h6>
      <span class="badge bg-warning text-dark ms-1"><?= count($detected) ?></span>
    </div>
    <div class="card-body pt-2">
      <p class="text-muted small mb-3">Estos números están configurados en el <code>.env</code>, en los settings de un tenant o asignados a un agente, pero <strong>no están en el pool</strong>. Impórtalos para administrarlos desde aquí.</p>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead class="table-light"><tr><th>Número</th><th>Dónde está configurado</th><th>Negocio</th><th class="text-end">Acción</th></tr></thead>
          <tbody>
          <?php foreach ($detected as $d): ?>
            <tr>
              <td><code class="fw-semibold"><?= e($d['phone']) ?></code></td>
              <td>
                <?php foreach (($d['sources'] ?? []) as $src): ?>
                  <span class="badge bg-label-secondary me-1" style="font-size:.68rem"><?= e($src) ?></span>
                <?php endforeach; ?>
              </td>
              <td>
                <?php $tn = $d['tenants'][0] ?? null; ?>
                <?= $tn ? '<small>'.e($tn['name']).' <span class="text-muted">@'.e($tn['slug']).'</span></small>' : '<span class="text-muted small">—</span>' ?>
              </td>
              <td class="text-end">
                <button class="btn btn-xs btn-outline-primary import-num"
                  data-phone="<?= e($d['phone']) ?>"
                  data-name="<?= e($tn['name'] ?? '') ?>">
                  <i class="bx bx-import me-1"></i>Importar al pool
                </button>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="card">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>Número</th><th>Cuenta</th><th>Capacidades</th>
            <th>Asignado a</th><th>Renta</th><th class="text-end">Acciones</th>
          </tr>
        </thead>
        <tbody>
        <?php if (empty($numbers)): ?>
          <tr><td colspan="6" class="text-center text-muted py-5">
            <i class="bx bx-phone-off d-block mb-2" style="font-size:2.2rem;opacity:.3"></i>
            Sin números en el pool. Agrega el primero.
          </td></tr>
        <?php else: foreach ($numbers as $n):
          $caps = (array)($n['capabilities'] ?? []);
          $own  = !empty($n['account_sid']);
        ?>
          <tr data-id="<?= e($n['id']) ?>" data-phone="<?= e($n['phone_number']) ?>">
            <td>
              <code class="fw-semibold"><?= e($n['phone_number']) ?></code>
              <?php if (!empty($n['friendly_name'])): ?>
                <div class="small text-muted"><?= e($n['friendly_name']) ?></div>
              <?php endif; ?>
            </td>
            <td>
              <span class="badge <?= $own ? 'bg-label-warning' : 'bg-label-secondary' ?>">
                <?= $own ? 'Cuenta propia' : 'Plataforma' ?>
              </span>
            </td>
            <td>
              <?php foreach (['voice'=>'bx-microphone','sms'=>'bx-message-dots','whatsapp'=>'bxl-whatsapp'] as $cap=>$ic):
                if (!empty($caps[$cap])): ?>
                <i class="bx <?= $ic ?> text-primary" title="<?= e($cap) ?>"></i>
              <?php endif; endforeach; ?>
            </td>
            <td style="min-width:200px">
              <select class="form-select form-select-sm assign-sel" data-id="<?= e($n['id']) ?>">
                <option value="">— Disponible —</option>
                <?php foreach ($tenants as $t): ?>
                  <option value="<?= e($t['id']) ?>" <?= ($n['tenant_id'] ?? '') === $t['id'] ? 'selected' : '' ?>>
                    <?= e($t['name']) ?> (@<?= e($t['slug']) ?>)
                  </option>
                <?php endforeach; ?>
              </select>
            </td>
            <td><small class="text-muted">$<?= number_format(((int)($n['monthly_cost_cents'] ?? 0))/100, 2) ?>/mes</small></td>
            <td class="text-end">
              <button class="btn btn-xs btn-outline-danger" onclick="deleteNumber('<?= e($n['id']) ?>','<?= e($n['phone_number']) ?>')" title="Eliminar">
                <i class="bx bx-trash"></i>
              </button>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>
<?php renderFooter(); ?>
</div></div></div>

<!-- Modal: agregar número -->
<div class="modal fade" id="modalAddNumber" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bx bx-phone-incoming me-2 text-primary"></i>Agregar número</h5>
        <button class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-md-7">
            <label class="form-label">Número (E.164) <span class="text-danger">*</span></label>
            <input class="form-control font-monospace" id="an-phone" placeholder="+5215512345678"/>
          </div>
          <div class="col-md-5">
            <label class="form-label">Renta $/mes</label>
            <input type="number" step="0.01" class="form-control" id="an-cost" value="0"/>
          </div>
          <div class="col-12">
            <label class="form-label">Nombre descriptivo</label>
            <input class="form-control" id="an-name" placeholder="Ej. Línea principal CDMX"/>
          </div>
          <div class="col-12">
            <label class="form-label">Account SID (opcional — vacío = cuenta plataforma)</label>
            <input class="form-control font-monospace" id="an-sid" placeholder="ACxxxxxxxx"/>
          </div>
          <div class="col-12">
            <label class="form-label d-block">Capacidades</label>
            <div class="d-flex gap-3">
              <label class="form-check"><input class="form-check-input an-cap" type="checkbox" value="voice" checked> <span class="form-check-label">Voz</span></label>
              <label class="form-check"><input class="form-check-input an-cap" type="checkbox" value="sms"> <span class="form-check-label">SMS</span></label>
              <label class="form-check"><input class="form-check-input an-cap" type="checkbox" value="whatsapp"> <span class="form-check-label">WhatsApp</span></label>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-primary" id="addNumberBtn"><i class="bx bx-plus me-1"></i>Agregar</button>
      </div>
    </div>
  </div>
</div>

<style>.btn-xs{padding:.2rem .45rem;font-size:.75rem}</style>
<script>
function showToast(m,t='info'){ window.showToast?.(m,t); }
const addModal = () => bootstrap.Modal.getOrCreateInstance(document.getElementById('modalAddNumber'));

// Importar un número detectado al pool
document.querySelectorAll('.import-num').forEach(btn=>{
  btn.addEventListener('click', async function(){
    const phone = this.dataset.phone, name = this.dataset.name || null;
    const b=this; b.disabled=true; b.innerHTML='<span class="spinner-border spinner-border-sm me-1"></span>…';
    try{
      const res=await fetch('/api/superadmin-numbers.php',{method:'POST',headers:{'Content-Type':'application/json'},
        body:JSON.stringify({action:'create', phone_number:phone, friendly_name:name})});
      const d=await res.json();
      if(!res.ok) throw new Error(d.error||'Error');
      showToast('<i class="bx bx-import me-1"></i>Número importado al pool','success');
      setTimeout(()=>location.reload(), 600);
    }catch(err){ showToast(err.message,'danger'); b.disabled=false; b.innerHTML='<i class="bx bx-import me-1"></i>Importar al pool'; }
  });
});

function openAddNumber(){
  ['an-phone','an-name','an-sid'].forEach(id=>document.getElementById(id).value='');
  document.getElementById('an-cost').value='0';
  document.querySelectorAll('.an-cap').forEach(c=>c.checked = c.value==='voice');
  addModal().show();
}

document.getElementById('addNumberBtn').addEventListener('click', async ()=>{
  const phone = document.getElementById('an-phone').value.trim();
  if(!phone){ showToast('El número es obligatorio','warning'); return; }
  const caps = {voice:false,sms:false,whatsapp:false};
  document.querySelectorAll('.an-cap:checked').forEach(c=>caps[c.value]=true);
  const b=document.getElementById('addNumberBtn');
  b.disabled=true; b.innerHTML='<span class="spinner-border spinner-border-sm me-1"></span>…';
  try{
    const res = await fetch('/api/superadmin-numbers.php',{method:'POST',headers:{'Content-Type':'application/json'},
      body:JSON.stringify({action:'create', phone_number:phone,
        friendly_name:document.getElementById('an-name').value.trim()||null,
        account_sid:document.getElementById('an-sid').value.trim()||null,
        monthly_cost_cents: Math.round((parseFloat(document.getElementById('an-cost').value)||0)*100),
        capabilities:caps })});
    const d=await res.json();
    if(!res.ok) throw new Error(d.error||'Error');
    showToast('<i class="bx bx-check me-1"></i>Número agregado','success');
    setTimeout(()=>location.reload(), 600);
  }catch(err){ showToast(err.message,'danger'); b.disabled=false; b.innerHTML='<i class="bx bx-plus me-1"></i>Agregar'; }
});

// Asignar / liberar al cambiar el select
document.querySelectorAll('.assign-sel').forEach(sel=>{
  sel.addEventListener('change', async function(){
    const id=this.dataset.id; const tenant_id=this.value||null; const self=this; self.disabled=true;
    try{
      const res=await fetch('/api/superadmin-numbers.php',{method:'POST',headers:{'Content-Type':'application/json'},
        body:JSON.stringify({action:'update', id, tenant_id})});
      const d=await res.json();
      if(!res.ok) throw new Error(d.error||'Error');
      showToast(tenant_id?'<i class="bx bx-link me-1"></i>Número asignado':'<i class="bx bx-unlink me-1"></i>Número liberado','success');
    }catch(err){ showToast(err.message,'danger'); }
    self.disabled=false;
  });
});

const confirmToast = (...a)=>window.confirmToast?.(...a);
async function deleteNumber(id, phone){
  const ok = await confirmToast(`¿Eliminar el número <code>${phone}</code> del pool?`, 'Sí, eliminar', 'btn-danger');
  if(!ok) return;
  try{
    const res=await fetch('/api/superadmin-numbers.php',{method:'POST',headers:{'Content-Type':'application/json'},
      body:JSON.stringify({action:'delete', id})});
    const d=await res.json();
    if(!res.ok) throw new Error(d.error||'Error');
    document.querySelector(`tr[data-id="${id}"]`)?.remove();
    showToast('<i class="bx bx-trash me-1"></i>Número eliminado','success');
  }catch(err){ showToast(err.message,'danger'); }
}
</script>
