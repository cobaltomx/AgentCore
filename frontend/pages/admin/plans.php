<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/head.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/navbar.php';
require_once __DIR__ . '/../../includes/footer.php';

requireAuth();
requireRole('superadmin');

$plans     = array_values(array_filter((array)apiGet('/superadmin/plans'), 'is_array'));
$rates     = array_values(array_filter((array)apiGet('/superadmin/provider-rates'), 'is_array'));
$costModel = apiGet('/superadmin/cost-model');

$ALL_FEATURES = ['voice'=>'Voz','webchat'=>'Chat web','whatsapp'=>'WhatsApp','rag'=>'RAG (KB)','outbound'=>'Outbound','insights'=>'Insights','priority'=>'Soporte prioritario'];

renderHead('Planes y tarifas');
?>
<div class="layout-wrapper layout-content-navbar">
<div class="layout-container">
<?php renderSidebar('sa-plans'); ?>
<div class="layout-page">
<?php renderNavbar('Planes y tarifas'); ?>
<div class="content-wrapper">
<div class="container-xxl flex-grow-1 container-p-y">

  <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
      <h4 class="mb-1">Planes y tarifas</h4>
      <p class="text-muted mb-0">Configura los planes de venta y las tarifas estimadas de infraestructura (para el cálculo de costo/margen).</p>
    </div>
  </div>

  <!-- ── MODELO DE COSTO + GUARDRAIL ────────────────────────── -->
  <div class="card mb-4 border-warning">
    <div class="card-body">
      <div class="row g-3 align-items-end">
        <div class="col-md-3">
          <div class="text-muted small">Costo estimado por minuto</div>
          <div class="h4 mb-0" id="cm-cost">$<?= number_format(($costModel['costPerMinCents'] ?? 0)/100, 2) ?></div>
          <small class="text-muted">infra $<?= number_format(($costModel['infraPerMinCents'] ?? 0)/100,2) ?> + LLM $<?= number_format(($costModel['llmPerMinCents'] ?? 0)/100,2) ?></small>
        </div>
        <div class="col-md-3">
          <label class="form-label small mb-0">Tokens LLM / min</label>
          <input type="number" class="form-control form-control-sm" id="cm-tokens" value="<?= (int)($costModel['tokensPerMin'] ?? 12700) ?>">
          <small class="text-muted">baja al optimizar (caching)</small>
        </div>
        <div class="col-md-3">
          <label class="form-label small mb-0">Margen mínimo (guardrail)</label>
          <div class="input-group input-group-sm">
            <input type="number" class="form-control" id="cm-minmargin" value="<?= (int)($costModel['minMarginPct'] ?? 40) ?>">
            <span class="input-group-text">%</span>
          </div>
        </div>
        <div class="col-md-3">
          <button class="btn btn-sm btn-outline-warning w-100" id="cm-save">Guardar modelo</button>
        </div>
      </div>
      <p class="text-muted small mb-0 mt-2"><i class="bx bx-info-circle me-1"></i>El margen de cada plan se calcula a <strong>uso pleno de minutos incluidos</strong> con este costo. Si cae bajo el mínimo, se marca en rojo.</p>
    </div>
  </div>

  <!-- ── PLANES ─────────────────────────────────────────────── -->
  <div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
      <h5 class="mb-0"><i class="bx bx-purchase-tag me-1"></i>Planes del sistema</h5>
    </div>
    <div class="card-body">
      <div id="plans-list" class="row g-3">
        <?php foreach ($plans as $p):
          $feat = is_array($p['features'] ?? null) ? $p['features'] : [];
        ?>
        <div class="col-md-6 col-xl-4">
          <div class="border rounded p-3 h-100 plan-card" data-key="<?= e($p['key']) ?>">
            <div class="d-flex justify-content-between align-items-center mb-2">
              <input class="form-control form-control-sm fw-bold w-auto pl-name" value="<?= e($p['name']) ?>" style="max-width:160px">
              <span class="badge bg-label-secondary"><?= e($p['key']) ?></span>
            </div>
            <div class="row g-2 mb-2">
              <div class="col-6"><label class="form-label small mb-0">Precio/mes (MXN)</label>
                <input type="number" class="form-control form-control-sm pl-price" value="<?= (int)($p['monthly_cents']/100) ?>"></div>
              <div class="col-6"><label class="form-label small mb-0">Minutos incl.</label>
                <input type="number" class="form-control form-control-sm pl-min" value="<?= (int)$p['included_minutes'] ?>"></div>
              <div class="col-6"><label class="form-label small mb-0">Agentes</label>
                <input type="number" class="form-control form-control-sm pl-agents" value="<?= (int)$p['max_agents'] ?>"></div>
              <div class="col-6"><label class="form-label small mb-0">Excedente ¢/min</label>
                <input type="number" class="form-control form-control-sm pl-over" value="<?= (int)$p['overage_per_min_cents'] ?>"></div>
            </div>
            <label class="form-label small mb-1">Features incluidas</label>
            <div class="d-flex flex-wrap gap-1 mb-2 pl-feats">
              <?php foreach ($ALL_FEATURES as $fk=>$flabel): ?>
                <label class="badge <?= in_array($fk,$feat)?'bg-primary':'bg-label-secondary' ?>" style="cursor:pointer;font-weight:500">
                  <input type="checkbox" class="d-none feat-chk" value="<?= e($fk) ?>" <?= in_array($fk,$feat)?'checked':'' ?>>
                  <?= e($flabel) ?>
                </label>
              <?php endforeach; ?>
            </div>
            <div class="d-flex justify-content-between align-items-center mb-2 px-1 py-1 rounded pl-margin-box" style="background:var(--bs-light,#f8f8ff)">
              <span class="small text-muted">Margen a uso pleno</span>
              <span class="fw-bold small pl-margin">—</span>
            </div>
            <button class="btn btn-sm btn-primary w-100 pl-save">Guardar plan</button>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- ── TARIFAS DE PROVEEDORES ─────────────────────────────── -->
  <div class="card">
    <div class="card-header">
      <h5 class="mb-0"><i class="bx bx-server me-1"></i>Tarifas de infraestructura (estimado)</h5>
      <small class="text-muted">Costo estimado por proveedor — se usa para calcular costo y margen por tenant. Edita y guarda.</small>
    </div>
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-sm align-middle">
          <thead><tr><th>Proveedor</th><th>Unidad</th><th style="width:160px">Tarifa (¢ MXN)</th><th style="width:120px"></th></tr></thead>
          <tbody>
            <?php foreach ($rates as $r): ?>
            <tr class="rate-row" data-provider="<?= e($r['provider']) ?>">
              <td><?= e($r['label']) ?> <small class="text-muted">(<?= e($r['provider']) ?>)</small></td>
              <td><span class="badge bg-label-info"><?= e($r['unit']) ?></span></td>
              <td><input type="number" step="0.01" class="form-control form-control-sm rt-rate" value="<?= e($r['rate_cents']) ?>"></td>
              <td><button class="btn btn-sm btn-outline-primary rt-save">Guardar</button></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div>
<?php renderFooter(); ?>
</div></div></div>

<script>
(function(){
  'use strict';
  const CM = <?= json_encode($costModel ?: []) ?>;
  // Tarifa LLM por 1k tokens derivada (para recalcular si cambian los tokens/min)
  const llmPer1k = (CM.tokensPerMin>0) ? (CM.llmPerMinCents / (CM.tokensPerMin/1000)) : 0;
  let costPerMin = Number(CM.costPerMinCents)||0;      // ¢ MXN por minuto
  let minMargin  = Number(CM.minMarginPct)||0;

  function recalcCost(){
    const tok = parseInt(document.getElementById('cm-tokens').value)||0;
    minMargin = parseInt(document.getElementById('cm-minmargin').value)||0;
    costPerMin = (CM.infraPerMinCents||0) + (tok/1000)*llmPer1k;
    document.getElementById('cm-cost').textContent = '$'+(costPerMin/100).toFixed(2);
    document.querySelectorAll('.plan-card').forEach(renderMargin);
  }

  function renderMargin(c){
    const price = (parseFloat(c.querySelector('.pl-price').value)||0)*100;   // ¢
    const min   = parseInt(c.querySelector('.pl-min').value)||0;
    const span  = c.querySelector('.pl-margin');
    const box   = c.querySelector('.pl-margin-box');
    if (price<=0){ span.textContent='— (custom)'; span.className='fw-bold small text-muted'; box.style.background=''; return; }
    const cost   = min*costPerMin;
    const margin = price-cost;
    const pct    = Math.round(margin/price*100);
    const low    = pct < minMargin;
    span.textContent = '$'+(margin/100).toFixed(0)+' ('+pct+'%)'+(low?' ⚠':'');
    span.className = 'fw-bold small ' + (margin<0?'text-danger':(low?'text-warning':'text-success'));
    box.style.background = low ? 'rgba(255,62,29,.08)' : 'rgba(113,221,55,.08)';
    c.dataset.lowMargin = low ? '1' : '';
  }

  document.getElementById('cm-tokens')?.addEventListener('input', recalcCost);
  document.getElementById('cm-minmargin')?.addEventListener('input', recalcCost);
  document.getElementById('cm-save')?.addEventListener('click', async function(){
    const btn=this, orig=btn.textContent; btn.disabled=true; btn.textContent='Guardando…';
    try{
      const res=await fetch('/api/plans-save.php',{method:'POST',headers:{'Content-Type':'application/json'},
        body:JSON.stringify({type:'cost-model', tokens_per_min:parseInt(document.getElementById('cm-tokens').value)||0, min_margin_pct:parseInt(document.getElementById('cm-minmargin').value)||0})});
      const d=await res.json();
      if(d.costPerMinCents!==undefined){ window.showToast?.('Modelo guardado','success'); }
      else window.showToast?.(d.error||'Error','error');
    }catch{ window.showToast?.('Error de red','error'); }
    btn.disabled=false; btn.textContent=orig;
  });

  // Toggle visual de features
  document.querySelectorAll('.feat-chk').forEach(chk=>{
    chk.closest('label').addEventListener('click', ()=>{
      setTimeout(()=>{ chk.closest('label').className = 'badge ' + (chk.checked?'bg-primary':'bg-label-secondary'); }, 0);
    });
  });
  // Recalcular margen al editar precio/minutos
  document.querySelectorAll('.plan-card').forEach(c=>{
    c.querySelector('.pl-price').addEventListener('input', ()=>renderMargin(c));
    c.querySelector('.pl-min').addEventListener('input', ()=>renderMargin(c));
    renderMargin(c);
  });

  async function save(payload, btn){
    const orig = btn.textContent; btn.disabled = true; btn.textContent = 'Guardando…';
    try {
      const res = await fetch('/api/plans-save.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload) });
      const data = await res.json();
      if (data.key || data.provider) window.showToast?.('Guardado', 'success');
      else window.showToast?.(data.error || 'Error', 'error');
    } catch { window.showToast?.('Error de red', 'error'); }
    btn.disabled = false; btn.textContent = orig;
  }

  document.querySelectorAll('.pl-save').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      const c = btn.closest('.plan-card');
      if (c.dataset.lowMargin === '1') {
        const ok = confirm('⚠️ Este plan queda con margen POR DEBAJO del mínimo ('+minMargin+'%) a uso pleno. ¿Guardar de todas formas?');
        if (!ok) return;
      }
      const feats = [...c.querySelectorAll('.feat-chk:checked')].map(x=>x.value);
      save({ type:'plan', key: c.dataset.key,
        name: c.querySelector('.pl-name').value.trim(),
        monthly_cents: Math.round((parseFloat(c.querySelector('.pl-price').value)||0)*100),
        included_minutes: parseInt(c.querySelector('.pl-min').value)||0,
        max_agents: parseInt(c.querySelector('.pl-agents').value)||1,
        overage_per_min_cents: parseInt(c.querySelector('.pl-over').value)||0,
        features: feats,
      }, btn);
    });
  });

  document.querySelectorAll('.rt-save').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      const row = btn.closest('.rate-row');
      save({ type:'rate', provider: row.dataset.provider,
        rate_cents: parseFloat(row.querySelector('.rt-rate').value)||0 }, btn);
    });
  });
})();
</script>
