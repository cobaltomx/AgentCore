<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/head.php';
require_once __DIR__ . '/../includes/sidebar.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/footer.php';

requireAuth();
if (!isAdmin()) { header('Location: /index.php'); exit; }

$raw      = apiGet('/products');
$products = array_values(array_filter((array)($raw['data'] ?? []), 'is_array'));
$voc      = catalogVocab();   // vocabulario por giro (Menú / Propiedades / Catálogo)

renderHead($voc['title']);
?>
<div class="layout-wrapper layout-content-navbar"><div class="layout-container">
<?php renderSidebar('products'); ?>
<div class="layout-page"><?php renderNavbar($voc['title']); ?>
<div class="content-wrapper"><div class="container-xxl flex-grow-1 container-p-y">

  <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
    <div>
      <h4 class="mb-1"><i class="bx <?= e($voc['icon']) ?> text-primary me-2"></i><?= e($voc['title']) ?></h4>
      <p class="text-muted mb-0"><?= e($voc['subtitle']) ?></p>
    </div>
    <button class="btn btn-primary" id="btn-new-product">
      <i class="bx bx-plus me-1"></i><?= e($voc['new_word']) ?> <?= e($voc['singular']) ?>
    </button>
  </div>

  <?php if (empty($products)): ?>
  <div class="card"><div class="card-body text-center py-5">
    <i class="bx <?= e($voc['icon']) ?> d-block mb-2 text-muted" style="font-size:3rem;opacity:.4"></i>
    <h5>Tu <?= e(mb_strtolower($voc['label'])) ?> está vacío</h5>
    <p class="text-muted">Agrega <?= e($voc['singular']) ?>s para que tu bot pueda mostrarlos en las conversaciones.</p>
    <button class="btn btn-primary" id="btn-new-product-empty"><i class="bx bx-plus me-1"></i>Agregar el primero</button>
  </div></div>
  <?php else: ?>
  <div class="row g-3" id="product-grid">
    <?php foreach ($products as $p):
      $price = number_format(($p['price_cents'] ?? 0) / 100, 2);
      $cur   = strtoupper($p['currency'] ?? 'MXN');
      $active = (bool)($p['is_active'] ?? true);
    ?>
    <div class="col-sm-6 col-lg-4 col-xl-3 product-card" data-json="<?= htmlspecialchars(json_encode($p), ENT_QUOTES) ?>">
      <div class="card h-100 <?= $active ? '' : 'opacity-50' ?>">
        <div class="ratio ratio-16x9 bg-light rounded-top" style="overflow:hidden">
          <?php if (!empty($p['image_url'])): ?>
            <img src="<?= e($p['image_url']) ?>" alt="<?= e($p['name']) ?>" style="object-fit:cover">
          <?php else: ?>
            <div class="d-flex align-items-center justify-content-center text-muted"><i class="bx bx-image" style="font-size:2rem"></i></div>
          <?php endif; ?>
        </div>
        <div class="card-body p-3">
          <div class="d-flex justify-content-between align-items-start mb-1">
            <span class="fw-semibold" style="font-size:.92rem"><?= e($p['name']) ?></span>
            <?php if (!$active): ?><span class="badge bg-label-secondary">Inactivo</span><?php endif; ?>
          </div>
          <?php if (!empty($p['category'])): ?>
            <span class="badge bg-label-primary mb-2"><?= e($p['category']) ?></span>
          <?php endif; ?>
          <div class="h5 mb-1">$<?= $price ?> <small class="text-muted" style="font-size:.7rem"><?= e($cur) ?></small></div>
          <?php if ($voc['show_stock']): ?>
            <?php if (isset($p['stock']) && $p['stock'] !== null): ?>
              <small class="text-muted">Stock: <?= (int)$p['stock'] ?></small>
            <?php else: ?>
              <small class="text-muted">Stock ilimitado</small>
            <?php endif; ?>
          <?php endif; ?>
        </div>
        <div class="card-footer d-flex gap-2 p-2">
          <button class="btn btn-sm btn-outline-primary flex-grow-1 btn-edit-product"><i class="bx bx-edit"></i> Editar</button>
          <button class="btn btn-sm btn-outline-danger btn-del-product" title="Eliminar"><i class="bx bx-trash"></i></button>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

</div></div>

<!-- ── Modal crear/editar producto ──────────────────────────────── -->
<div class="modal fade" id="modalProduct" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="pm-title"><?= e($voc['new_word']) ?> <?= e($voc['singular']) ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form id="product-form">
        <div class="modal-body">
          <input type="hidden" id="pm-id">
          <div class="mb-3">
            <label class="form-label">Nombre *</label>
            <input type="text" class="form-control" id="pm-name" maxlength="160" required>
          </div>
          <div class="row g-2 mb-3">
            <div class="col-7">
              <label class="form-label">Precio (MXN) *</label>
              <div class="input-group">
                <span class="input-group-text">$</span>
                <input type="number" class="form-control" id="pm-price" min="0" step="0.01" required>
              </div>
            </div>
            <div class="col-5" <?= $voc['show_stock'] ? '' : 'style="display:none"' ?>>
              <label class="form-label">Stock</label>
              <input type="number" class="form-control" id="pm-stock" min="0" placeholder="Ilimitado">
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">Categoría</label>
            <input type="text" class="form-control" id="pm-category" maxlength="80" placeholder="<?= e($voc['category_ph']) ?>">
          </div>
          <div class="mb-3">
            <label class="form-label">Descripción</label>
            <textarea class="form-control" id="pm-description" rows="2" maxlength="2000"></textarea>
          </div>

          <!-- ── Datos de propiedad: SOLO inmobiliaria. Para otros giros
               los inputs siguen en el DOM (ocultos) para no romper el JS. ── -->
          <div id="pm-property-section" <?= $voc['show_property'] ? '' : 'style="display:none"' ?>>
          <hr>
          <p class="section-title text-muted small fw-semibold mb-2"><i class="bx bx-building-house me-1"></i>DATOS DE LA PROPIEDAD</p>
          <div class="row g-2 mb-2">
            <div class="col-md-6">
              <label class="form-label">Operación</label>
              <select class="form-select" id="pm-operation">
                <option value="">—</option><option value="renta">Renta</option><option value="venta">Venta</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Tipo</label>
              <select class="form-select" id="pm-ptype">
                <option value="">—</option><option value="departamento">Departamento</option><option value="casa">Casa</option>
                <option value="terreno">Terreno</option><option value="bodega">Bodega</option><option value="oficina">Oficina</option>
              </select>
            </div>
          </div>
          <div class="row g-2 mb-2">
            <div class="col-4"><label class="form-label">Recámaras</label><input type="number" min="0" class="form-control" id="pm-bedrooms"></div>
            <div class="col-4"><label class="form-label">Baños</label><input type="number" min="0" class="form-control" id="pm-bathrooms"></div>
            <div class="col-4"><label class="form-label">m²</label><input type="number" min="0" class="form-control" id="pm-area"></div>
          </div>
          <div class="row g-2 mb-2">
            <div class="col-md-6"><label class="form-label">Estac.</label><input type="number" min="0" class="form-control" id="pm-parking"></div>
            <div class="col-md-6"><label class="form-label">Zona / Colonia</label><input type="text" class="form-control" id="pm-zone" maxlength="120" placeholder="Condesa, CDMX"></div>
          </div>
          <div class="mb-3">
            <label class="form-label">Amenidades <small class="text-muted">(separadas por coma)</small></label>
            <input type="text" class="form-control" id="pm-amenities" placeholder="amueblado, alberca, balcón">
          </div>
          </div><!-- /#pm-property-section -->

          <!-- ── Galería de fotos ──────────────────────────────── -->
          <hr>
          <p class="section-title text-muted small fw-semibold mb-2"><i class="bx bx-images me-1"></i>FOTOS <small class="text-muted">(la 1ª es la portada)</small></p>
          <div id="pm-gallery" class="d-flex flex-wrap gap-2 mb-2"></div>
          <div class="d-flex gap-2 align-items-center mb-3">
            <label class="btn btn-outline-primary btn-sm mb-0">
              <i class="bx bx-upload me-1"></i>Subir fotos
              <input type="file" id="pm-photo-input" accept="image/*" multiple hidden>
            </label>
            <span class="text-muted small" id="pm-upload-status"></span>
          </div>

          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" id="pm-active" checked>
            <label class="form-check-label" for="pm-active"><?= e($voc['singular_cap']) ?> activo (visible para el bot)</label>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-primary" id="pm-submit">Guardar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php renderFooter(); ?>
</div></div></div>

<script>
(function () {
  'use strict';
  const VOC = { singular: '<?= e($voc['singular']) ?>', cap: '<?= e($voc['singular_cap']) ?>', newWord: '<?= e($voc['new_word']) ?>' };
  const modalEl = document.getElementById('modalProduct');
  const modal = new bootstrap.Modal(modalEl);
  const f = id => document.getElementById(id);
  const UPLOADS_BASE = '<?= e(BACKEND_ROOT) ?>';
  let pmImages = [];   // galería de la propiedad en edición

  const imgSrc = u => (u && u.indexOf('/uploads/') === 0) ? (UPLOADS_BASE + u) : u;

  function renderGallery() {
    const g = f('pm-gallery');
    g.innerHTML = pmImages.map((u, i) => `
      <div style="position:relative;width:90px;height:68px">
        <img src="${imgSrc(u)}" style="width:90px;height:68px;object-fit:cover;border-radius:6px;border:2px solid ${i===0?'#696cff':'#e5e7eb'}">
        ${i===0?'<span style="position:absolute;bottom:2px;left:2px;background:#696cff;color:#fff;font-size:.6rem;padding:0 4px;border-radius:3px">portada</span>':`<button type="button" title="Hacer portada" onclick="window.__pmCover(${i})" style="position:absolute;bottom:2px;left:2px;background:rgba(0,0,0,.5);color:#fff;border:0;font-size:.6rem;padding:0 4px;border-radius:3px;cursor:pointer">portada</button>`}
        <button type="button" title="Quitar" onclick="window.__pmDel(${i})" style="position:absolute;top:-6px;right:-6px;background:#ff3e1d;color:#fff;border:0;width:18px;height:18px;border-radius:50%;font-size:.7rem;cursor:pointer;line-height:1">×</button>
      </div>`).join('') || '<span class="text-muted small">Sin fotos aún.</span>';
  }
  window.__pmDel   = i => { pmImages.splice(i,1); renderGallery(); };
  window.__pmCover = i => { const [u]=pmImages.splice(i,1); pmImages.unshift(u); renderGallery(); };

  function openModal(p) {
    const a = (p && p.attributes) || {};
    f('pm-id').value          = p?.id || '';
    f('pm-name').value        = p?.name || '';
    f('pm-price').value       = p ? (p.price_cents / 100) : '';
    f('pm-stock').value       = (p && p.stock != null) ? p.stock : '';
    f('pm-category').value    = p?.category || '';
    f('pm-description').value = p?.description || '';
    f('pm-active').checked     = p ? !!p.is_active : true;
    f('pm-operation').value   = a.operation || '';
    f('pm-ptype').value       = a.propertyType || '';
    f('pm-bedrooms').value    = a.bedrooms ?? '';
    f('pm-bathrooms').value   = a.bathrooms ?? '';
    f('pm-area').value        = a.areaM2 ?? '';
    f('pm-parking').value     = a.parking ?? '';
    f('pm-zone').value        = a.zone || '';
    f('pm-amenities').value   = Array.isArray(a.amenities) ? a.amenities.join(', ') : '';
    pmImages = Array.isArray(p?.images) ? [...p.images] : (p?.image_url ? [p.image_url] : []);
    renderGallery();
    f('pm-upload-status').textContent = '';
    f('pm-title').textContent = (p ? 'Editar ' : (VOC.newWord + ' ')) + VOC.singular;
    modal.show();
    setTimeout(() => f('pm-name').focus(), 300);
  }

  // Subir fotos
  f('pm-photo-input')?.addEventListener('change', async (e) => {
    const files = [...e.target.files]; e.target.value = '';
    if (!files.length) return;
    const st = f('pm-upload-status');
    for (let k = 0; k < files.length; k++) {
      st.textContent = `Subiendo ${k+1}/${files.length}…`;
      const fd = new FormData(); fd.append('file', files[k]);
      try {
        const res = await fetch('/api/property-photo-upload.php', { method:'POST', body: fd });
        const data = await res.json();
        if (data.url) { pmImages.push(data.url); renderGallery(); }
        else window.showToast?.(data.error || 'Error al subir foto', 'error');
      } catch { window.showToast?.('Error de red al subir', 'error'); }
    }
    st.textContent = '';
  });

  document.getElementById('btn-new-product')?.addEventListener('click', () => openModal(null));
  document.getElementById('btn-new-product-empty')?.addEventListener('click', () => openModal(null));

  document.querySelectorAll('.btn-edit-product').forEach(btn => {
    btn.addEventListener('click', () => {
      const card = btn.closest('.product-card');
      openModal(JSON.parse(card.dataset.json));
    });
  });

  document.querySelectorAll('.btn-del-product').forEach(btn => {
    btn.addEventListener('click', async () => {
      const card = btn.closest('.product-card');
      const p = JSON.parse(card.dataset.json);
      const ok = await (window.confirmToast?.(`¿Eliminar <strong>${p.name}</strong>?`, 'Eliminar') ?? Promise.resolve(confirm('¿Eliminar?')));
      if (!ok) return;
      const res = await fetch('/api/product-save.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'delete', id: p.id, hard: true }),
      });
      const data = await res.json();
      if (data.ok) { window.showToast?.(VOC.cap + ' eliminado', 'success'); card.remove(); }
      else window.showToast?.(data.error || 'Error', 'error');
    });
  });

  document.getElementById('product-form')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const id = f('pm-id').value;
    const num = v => { const n = parseInt(v, 10); return Number.isFinite(n) ? n : null; };
    const amen = f('pm-amenities').value.split(',').map(s => s.trim()).filter(Boolean);
    const priceVal = parseFloat(f('pm-price').value) || 0;
    const op = f('pm-operation').value;
    const attributes = {
      operation:    op || null,
      propertyType: f('pm-ptype').value || null,
      bedrooms:     num(f('pm-bedrooms').value),
      bathrooms:    num(f('pm-bathrooms').value),
      areaM2:       num(f('pm-area').value),
      parking:      num(f('pm-parking').value),
      zone:         f('pm-zone').value.trim() || null,
      amenities:    amen,
      priceLabel:   op === 'renta' ? ('$' + priceVal.toLocaleString('es-MX') + '/mes') : ('$' + priceVal.toLocaleString('es-MX')),
    };
    const payload = {
      action:      id ? 'update' : 'create',
      id,
      name:        f('pm-name').value.trim(),
      price:       f('pm-price').value,
      stock:       f('pm-stock').value,
      category:    f('pm-category').value.trim(),
      description: f('pm-description').value.trim(),
      image_url:   pmImages[0] || null,
      images:      pmImages,
      attributes,
      is_active:   f('pm-active').checked,
    };
    const btn = f('pm-submit');
    btn.disabled = true; btn.textContent = 'Guardando…';
    try {
      const res = await fetch('/api/product-save.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload),
      });
      const data = await res.json();
      if (data.id) { window.showToast?.(VOC.cap + ' guardado', 'success'); setTimeout(() => location.reload(), 600); }
      else { window.showToast?.(data.error || 'Error al guardar', 'error'); btn.disabled = false; btn.textContent = 'Guardar'; }
    } catch { window.showToast?.('Error de red', 'error'); btn.disabled = false; btn.textContent = 'Guardar'; }
  });
})();
</script>
