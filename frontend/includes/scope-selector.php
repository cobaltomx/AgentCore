<?php
/**
 * Selector de scope reutilizable: Global ▸ Vertical ▸ Negocio.
 * Recarga la MISMA página por GET con ?industry= y ?tenantId=.
 * Uso:
 *   require_once .../includes/scope-selector.php;
 *   renderScopeSelector(['industry'=>$i,'tenantId'=>$t,'verticals'=>$v,'tenants'=>$ts,'level'=>$lvl,'negocios'=>$n]);
 */

if (!function_exists('scopeIndustryLabel')) {
  function scopeIndustryLabel($k) {
    static $m = [
      'clinica'=>'Clínica / Salud','dental'=>'Clínica Dental','consultorio'=>'Consultorios',
      'inmobiliaria'=>'Inmobiliaria','taller'=>'Taller automotriz','restaurante'=>'Restaurante',
      'educacion'=>'Educación','ecommerce'=>'E-commerce','servicios'=>'Servicios','gym'=>'Gym / Spa',
    ];
    return $k === '' ? 'Sin industria' : ($m[$k] ?? ucfirst($k));
  }
}
if (!function_exists('scopeTenantIndustry')) {
  function scopeTenantIndustry($t) {
    return $t['settings']['businessProfile']['industry'] ?? $t['settings']['industry'] ?? '';
  }
}

function renderScopeSelector(array $o): void {
  $industry  = $o['industry']  ?? '';
  $tenantId  = $o['tenantId']  ?? '';
  $verticals = is_array($o['verticals'] ?? null) ? $o['verticals'] : [];
  $tenants   = is_array($o['tenants'] ?? null) ? $o['tenants'] : [];
  $level     = $o['level'] ?? 'global';
  $negocios  = (int)($o['negocios'] ?? 0);
  $extra     = is_array($o['extra'] ?? null) ? $o['extra'] : [];  // hidden inputs extra (ej. days)
  $badge     = $level === 'negocio' ? 'success' : ($level === 'vertical' ? 'info' : 'primary');
?>
  <div class="card mb-4"><div class="card-body py-3">
    <form method="get" class="row g-2 align-items-end" id="scopeForm">
      <?php foreach ($extra as $ek => $ev): ?>
        <input type="hidden" name="<?= e($ek) ?>" value="<?= e($ev) ?>">
      <?php endforeach; ?>
      <div class="col-md-4">
        <label class="form-label small mb-1">Vertical (giro)</label>
        <select name="industry" class="form-select" id="sel-industry" onchange="scopeVerticalChange()">
          <option value="">— Todas las verticales (global) —</option>
          <?php foreach ($verticals as $v): $k = $v['industry']; ?>
            <option value="<?= e($k) ?>" <?= $industry === $k ? 'selected' : '' ?>>
              <?= e(scopeIndustryLabel($k)) ?> (<?= (int)$v['negocios'] ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-5">
        <label class="form-label small mb-1">Negocio (cliente)</label>
        <select name="tenantId" class="form-select" id="sel-tenant" onchange="document.getElementById('scopeForm').submit()">
          <option value="">— Todos los negocios de la vertical —</option>
          <?php foreach ($tenants as $t):
            $ti = scopeTenantIndustry($t);
            if ($industry !== '' && $ti !== $industry) continue;
            $bn = $t['settings']['businessProfile']['businessName'] ?? $t['name'];
          ?>
            <option value="<?= e($t['id']) ?>" <?= $tenantId === $t['id'] ? 'selected' : '' ?>>
              <?= e($bn) ?> (@<?= e($t['slug']) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <span class="badge bg-label-<?= $badge ?> w-100 py-2" style="font-size:.85rem">
          Nivel: <strong><?= ucfirst($level) ?></strong> · <?= $negocios ?> negocio(s)
        </span>
      </div>
    </form>
    <?php if ($level === 'negocio' && $tenantId): ?>
      <div class="mt-2">
        <a href="/pages/admin/tenant-detail.php?id=<?= e($tenantId) ?>" class="btn btn-sm btn-outline-primary">
          <i class="bx bx-cog me-1"></i>Administrar este negocio
        </a>
      </div>
    <?php endif; ?>
  </div></div>
  <script>
  function scopeVerticalChange(){ document.getElementById('sel-tenant').value=''; document.getElementById('scopeForm').submit(); }
  </script>
<?php
}
