<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/head.php';
require_once __DIR__ . '/../includes/sidebar.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/footer.php';

requireAuth();
requireRole('admin', 'superadmin');  // Solo admins

$tenantInfo = apiGet('/tenants');
$settings   = $tenantInfo['settings'] ?? [];
$bizProfile = $settings['businessProfile'] ?? [];
$twilioCfg  = $settings['twilio'] ?? [];
$delivery   = $settings['delivery'] ?? [];
// El giro del tenant (solo-lectura para el admin; lo fija el superadmin).
$tenantIndustryId = $bizProfile['industry'] ?? $settings['industry'] ?? '';
// Verticales que hacen reparto a domicilio → muestran "zona de entrega".
$deliveryVerticals = ['restaurante','comida','ecommerce','comercio','tienda','retail'];
$isDeliveryVertical = in_array($tenantIndustryId, $deliveryVerticals, true);
// Agente de voz activo (para mostrar/sincronizar número)
$agentsRaw   = apiGet('/agents');
$allAgents   = array_values(array_filter((array)$agentsRaw, 'is_array'));
$voiceAgents = array_values(array_filter($allAgents, fn($a) => ($a['channel'] ?? '') === 'voice'));
$activeVoice = array_values(array_filter($voiceAgents, fn($a) => (bool)($a['is_active'] ?? false)));
// Usar el primero activo, o el primero si no hay ninguno activo
$primaryAgent = $activeVoice[0] ?? $voiceAgents[0] ?? null;

renderHead('Configuración');
?>
<div class="layout-wrapper layout-content-navbar"><div class="layout-container">
<?php renderSidebar('settings'); ?>
<div class="layout-page"><?php renderNavbar('Configuración'); ?>
<div class="content-wrapper"><div class="container-xxl flex-grow-1 container-p-y">

  <h4 class="mb-4">Configuración</h4>

  <!-- Tabs -->
  <ul class="nav nav-tabs mb-4" role="tablist" id="settingsTabs">
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-bizprofile">
      <i class="bx bx-buildings me-1"></i>Perfil de negocio</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-telephony">
      <i class="bx bx-phone me-1"></i>Telefonía</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-general">General</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-scheduling">Horarios</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-whatsapp">WhatsApp</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-calcom">Cal.com</a></li>
  </ul>

  <div class="tab-content">

    <!-- ── Perfil de negocio ──────────────────────────────────── -->
    <div class="tab-pane fade" id="tab-bizprofile">
      <div class="card mb-4">
        <div class="card-header d-flex align-items-center gap-2">
          <i class="bx bx-buildings text-primary"></i>
          <h6 class="mb-0">Tipo de industria</h6>
        </div>
        <div class="card-body">
          <?php
          // Catálogo de giros (para MOSTRAR el actual). Incluye aliases para
          // que dental/consultorio/comida mapeen a su tarjeta correcta.
          $industryMeta = [
            'clinica'      => ['icon'=>'🏥', 'label'=>'Clínica / Dental',      'desc'=>'Consultas, especialidades, citas médicas'],
            'dental'       => ['icon'=>'🦷', 'label'=>'Clínica Dental',        'desc'=>'Consultas, especialidades, citas médicas'],
            'consultorio'  => ['icon'=>'🩺', 'label'=>'Consultorios',          'desc'=>'Sesiones, terapias, citas'],
            'inmobiliaria' => ['icon'=>'🏠', 'label'=>'Inmobiliaria',          'desc'=>'Ventas, rentas, propiedades'],
            'taller'       => ['icon'=>'🔧', 'label'=>'Taller / Automotriz',   'desc'=>'Diagnósticos, reparaciones, servicios'],
            'restaurante'  => ['icon'=>'🍽️', 'label'=>'Restaurante / Food',    'desc'=>'Reservas, pedidos, delivery'],
            'comida'       => ['icon'=>'🍽️', 'label'=>'Restaurante / Food',    'desc'=>'Reservas, pedidos, delivery'],
            'educacion'    => ['icon'=>'📚', 'label'=>'Educación',             'desc'=>'Cursos, inscripciones, tutorías'],
            'ecommerce'    => ['icon'=>'🛍️', 'label'=>'Tienda / E-commerce',   'desc'=>'Ventas, soporte, devoluciones'],
            'comercio'     => ['icon'=>'🛒', 'label'=>'Comercio',              'desc'=>'Ventas, pedidos, soporte'],
            'servicios'    => ['icon'=>'💼', 'label'=>'Servicios Profesionales','desc'=>'Consultoría, contabilidad, legal'],
            'gym'          => ['icon'=>'💪', 'label'=>'Gym / Spa / Wellness',  'desc'=>'Membresías, clases, citas'],
          ];
          $cur = $industryMeta[$tenantIndustryId] ?? null;
          ?>
          <?php if ($cur): ?>
          <div class="d-flex align-items-center gap-3 p-3 border rounded bg-light">
            <div style="font-size:2.2rem"><?= $cur['icon'] ?></div>
            <div class="flex-grow-1">
              <div class="fw-semibold"><?= e($cur['label']) ?> <i class="bx bx-lock-alt text-muted ms-1" title="Configurado por tu proveedor"></i></div>
              <div class="text-muted small"><?= e($cur['desc']) ?></div>
            </div>
            <span class="badge bg-label-primary"><i class="bx bx-check me-1"></i>Activo</span>
          </div>
          <?php else: ?>
          <div class="alert alert-warning mb-0"><i class="bx bx-info-circle me-1"></i>Tu giro aún no está configurado. Contáctanos para activarlo.</div>
          <?php endif; ?>
          <p class="text-muted small mb-0 mt-3">
            <i class="bx bx-lock-alt me-1"></i>El giro de tu negocio lo configura tu proveedor (AgentCore) porque define tus reportes, el agente y las herramientas. Si necesitas cambiarlo, contáctanos.
          </p>
        </div>
      </div>

      <div class="card">
        <div class="card-header d-flex align-items-center gap-2">
          <i class="bx bx-id-card text-primary"></i>
          <h6 class="mb-0">Datos del negocio</h6>
        </div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Nombre comercial</label>
              <input class="form-control" id="biz-name"
                     value="<?= e($bizProfile['businessName'] ?? $tenantInfo['name'] ?? '') ?>"
                     placeholder="Clínica Dental Sonrisa"/>
            </div>
            <div class="col-md-6">
              <label class="form-label">Teléfono de contacto</label>
              <input class="form-control" id="biz-phone"
                     value="<?= e($bizProfile['phone'] ?? '') ?>"
                     placeholder="+52 55 1234 5678"/>
            </div>
            <div class="col-md-8">
              <label class="form-label">Dirección</label>
              <input class="form-control" id="biz-address"
                     value="<?= e($bizProfile['address'] ?? '') ?>"
                     placeholder="Av. Principal #123, Col. Centro, Ciudad"/>
            </div>
            <div class="col-md-4">
              <label class="form-label">Ciudad</label>
              <input class="form-control" id="biz-city"
                     value="<?= e($bizProfile['city'] ?? '') ?>"
                     placeholder="Ciudad de México"/>
            </div>
            <div class="col-md-4">
              <label class="form-label d-flex align-items-center gap-1">Moneda <span class="help-tip" data-bs-toggle="popover" data-bs-title="Moneda del negocio" data-bs-content="En esta moneda el asistente dirá los precios en las llamadas (ej. pesos o dólares). Cámbiala si operas en otra moneda.">?</span></label>
              <?php $curr = $bizProfile['currency'] ?? 'MXN'; ?>
              <select class="form-select" id="biz-currency">
                <?php foreach (['MXN'=>'Pesos mexicanos (MXN)','USD'=>'Dólares (USD)','EUR'=>'Euros (EUR)'] as $cv=>$cl): ?>
                  <option value="<?= $cv ?>" <?= $curr===$cv?'selected':'' ?>><?= e($cl) ?></option>
                <?php endforeach; ?>
              </select>
              <small class="text-muted">El bot usa esta moneda al decir los precios.</small>
            </div>
            <div class="col-md-8">
              <label class="form-label">Sitio web (opcional)</label>
              <input class="form-control" id="biz-website"
                     value="<?= e($bizProfile['website'] ?? '') ?>"
                     placeholder="https://tunegocio.com"/>
            </div>
            <div class="col-md-6">
              <label class="form-label">Email de contacto (opcional)</label>
              <input type="email" class="form-control" id="biz-email"
                     value="<?= e($bizProfile['email'] ?? '') ?>"
                     placeholder="contacto@tunegocio.com"/>
            </div>
            <div class="col-12">
              <label class="form-label">Descripción corta del negocio</label>
              <textarea class="form-control" id="biz-description" rows="2"
                        placeholder="Breve descripción de tu negocio que usarán los agentes IA..."><?= e($bizProfile['description'] ?? '') ?></textarea>
              <small class="text-muted">Los agentes IA usan esta descripción para presentar el negocio.</small>
            </div>
            <div class="col-12">
              <?php $recognize = ($settings['recognizeReturningCallers'] ?? false) === true; ?>
              <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" id="biz-recognize" <?= $recognize ? 'checked' : '' ?>>
                <label class="form-check-label" for="biz-recognize">Reconocer llamantes recurrentes por su número</label>
              </div>
              <small class="text-muted">Si se activa, el bot saluda por nombre y recuerda al cliente por su Caller ID. Déjalo apagado si un mismo número puede ser usado por distintas personas (recomendado para inmobiliarias).</small>
            </div>
            <div class="col-12">
              <button class="btn btn-primary" id="saveBizProfileBtn">
                <i class="bx bx-save me-1"></i>Guardar perfil de negocio
              </button>
            </div>
          </div>
        </div>
      </div>

      <?php if ($isDeliveryVertical):
        $delLat = isset($delivery['originLat']) ? (float)$delivery['originLat'] : null;
        $delLng = isset($delivery['originLng']) ? (float)$delivery['originLng'] : null;
        $delRad = isset($delivery['radiusKm']) ? (float)$delivery['radiusKm'] : 5;
      ?>
      <!-- ── Sucursal y zona de entrega (delivery) ─────────────── -->
      <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
      <div class="card mt-4">
        <div class="card-header d-flex align-items-center gap-2">
          <i class="bx bx-map text-primary"></i>
          <h6 class="mb-0">Sucursal y zona de entrega</h6>
        </div>
        <div class="card-body">
          <p class="text-muted small mb-3">
            Ubica tu sucursal en el mapa: <strong>arrastra el pin</strong> al punto exacto. El bot mide
            el reparto desde ahí y valida que la dirección del cliente esté dentro de tu radio.
          </p>
          <div class="row g-3">
            <div class="col-md-8">
              <label class="form-label">Dirección de la sucursal</label>
              <div class="input-group">
                <input class="form-control" id="del-address"
                       value="<?= e($delivery['originAddress'] ?? '') ?>"
                       placeholder="Plaza Zielo, Zibata, Querétaro"/>
                <button class="btn btn-outline-primary" type="button" id="del-search" title="Buscar en el mapa">
                  <i class="bx bx-search-alt"></i>
                </button>
              </div>
              <small class="text-muted">Escribe y pulsa buscar para centrar el mapa; luego ajusta el pin.</small>
            </div>
            <div class="col-md-4">
              <label class="form-label d-flex align-items-center gap-1">Radio de entrega (km) <span class="help-tip" data-bs-toggle="popover" data-bs-title="Radio de entrega" data-bs-content="Distancia máxima desde tu sucursal a la que haces entregas. El asistente valida la dirección del cliente contra este radio antes de aceptar un pedido a domicilio.">?</span></label>
              <input type="number" min="0" step="0.5" class="form-control" id="del-radius"
                     value="<?= e($delivery['radiusKm'] ?? '') ?>" placeholder="10"/>
            </div>
            <div class="col-12">
              <div id="del-map" style="height:320px;border-radius:8px;overflow:hidden;border:1px solid #e5e7eb"></div>
              <input type="hidden" id="del-lat" value="<?= $delLat !== null ? e($delLat) : '' ?>"/>
              <input type="hidden" id="del-lng" value="<?= $delLng !== null ? e($delLng) : '' ?>"/>
            </div>
            <div class="col-12">
              <div class="small text-muted mb-2" id="del-coords">
                <?php if ($delLat !== null && $delLng !== null): ?>
                  <i class="bx bx-map-pin text-success me-1"></i>Pin en <?= e(number_format($delLat,5)) ?>, <?= e(number_format($delLng,5)) ?>
                <?php else: ?>
                  <i class="bx bx-info-circle me-1"></i>Aún sin ubicar. Arrastra el pin o busca tu dirección.
                <?php endif; ?>
              </div>
              <button class="btn btn-primary" id="saveDeliveryBtn">
                <i class="bx bx-save me-1"></i>Guardar sucursal y zona
              </button>
            </div>
          </div>
        </div>
      </div>
      <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
      <script>
      (function () {
        const elMap = document.getElementById('del-map');
        if (!elMap || !window.L) return;
        const startLat = <?= $delLat !== null ? $delLat : 20.5888 ?>;   // default: Querétaro centro
        const startLng = <?= $delLng !== null ? $delLng : -100.3899 ?>;
        const hasPin   = <?= ($delLat !== null && $delLng !== null) ? 'true' : 'false' ?>;
        const latEl = document.getElementById('del-lat');
        const lngEl = document.getElementById('del-lng');
        const radEl = document.getElementById('del-radius');
        const coordsEl = document.getElementById('del-coords');

        const map = L.map('del-map').setView([startLat, startLng], hasPin ? 15 : 13);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
          maxZoom: 19, attribution: '© OpenStreetMap'
        }).addTo(map);

        const marker = L.marker([startLat, startLng], { draggable: true }).addTo(map);
        const radiusM = () => (parseFloat(radEl.value) || <?= $delRad ?>) * 1000;
        const circle = L.circle([startLat, startLng], { radius: radiusM(), color: '#696cff', fillColor: '#696cff', fillOpacity: 0.12 }).addTo(map);

        function setPin(lat, lng, recenter) {
          marker.setLatLng([lat, lng]);
          circle.setLatLng([lat, lng]);
          latEl.value = lat.toFixed(6);
          lngEl.value = lng.toFixed(6);
          coordsEl.innerHTML = '<i class="bx bx-map-pin text-success me-1"></i>Pin en ' + lat.toFixed(5) + ', ' + lng.toFixed(5);
          if (recenter) map.panTo([lat, lng]);
        }
        if (hasPin) { latEl.value = startLat; lngEl.value = startLng; }

        marker.on('dragend', () => { const p = marker.getLatLng(); setPin(p.lat, p.lng, false); });
        map.on('click', (e) => setPin(e.latlng.lat, e.latlng.lng, false));
        radEl.addEventListener('input', () => circle.setRadius(radiusM()));

        // Buscar la dirección escrita y mover el pin (Nominatim, gratis).
        document.getElementById('del-search')?.addEventListener('click', async () => {
          const q = document.getElementById('del-address').value.trim();
          if (!q) { window.showToast?.('Escribe una dirección para buscar', 'warning'); return; }
          try {
            const r = await fetch('https://nominatim.openstreetmap.org/search?format=json&limit=1&countrycodes=mx&q=' + encodeURIComponent(q));
            const j = await r.json();
            if (j && j[0]) { map.setView([+j[0].lat, +j[0].lon], 16); setPin(+j[0].lat, +j[0].lon, false); }
            else window.showToast?.('No encontré esa dirección; ubica el pin manualmente', 'warning');
          } catch { window.showToast?.('No se pudo buscar; ubica el pin manualmente', 'warning'); }
        });

        // Asegurar render correcto si el tab estaba oculto al cargar.
        setTimeout(() => map.invalidateSize(), 300);
        document.querySelector('[href="#tab-bizprofile"]')?.addEventListener('shown.bs.tab', () => map.invalidateSize());
      })();
      </script>
      <?php endif; ?>
    </div><!-- /tab-bizprofile -->

    <!-- ── Telefonía (Twilio) ─────────────────────────────────── -->
    <div class="tab-pane fade" id="tab-telephony">

      <?php
      $hasCredentials = !empty($twilioCfg['accountSid']) && !empty($twilioCfg['authToken']);
      $hasPhone       = !empty($twilioCfg['phoneNumber'] ?? $primaryAgent['phone_number'] ?? '');
      $webhookUrl     = rtrim(BACKEND_ROOT, '/') . '/webhooks/twilio/voice';
      ?>

      <!-- Estado de conexión -->
      <div class="card mb-4">
        <div class="card-body py-3">
          <div class="d-flex align-items-center gap-3 flex-wrap">
            <div id="twilio-status-badge">
              <?php if ($hasCredentials): ?>
                <span class="badge bg-label-warning fs-6 px-3 py-2">
                  <i class="bx bx-time-five me-1"></i>Credenciales guardadas — sin verificar
                </span>
              <?php else: ?>
                <span class="badge bg-label-secondary fs-6 px-3 py-2">
                  <i class="bx bx-x-circle me-1"></i>Sin credenciales propias
                </span>
              <?php endif; ?>
            </div>
            <button class="btn btn-outline-primary btn-sm" id="verifyBtn" onclick="verifyTwilio()">
              <i class="bx bx-shield-check me-1"></i>Verificar conexión
            </button>
            <div id="verify-result" class="small"></div>
          </div>
        </div>
      </div>

      <!-- Webhook URL -->
      <div class="card mb-4">
        <div class="card-header d-flex align-items-center gap-2">
          <i class="bx bx-link text-primary"></i>
          <h6 class="mb-0">URL del Webhook de voz</h6>
        </div>
        <div class="card-body">
          <p class="text-muted small mb-2">
            Configura esta URL en <strong>Twilio Console → Phone Numbers → tu número → Voice Webhook → HTTP POST</strong>.
            Todas las llamadas entrantes serán procesadas por el agente IA.
          </p>
          <div class="d-flex align-items-center gap-2">
            <code class="flex-grow-1 p-2 rounded border small" style="word-break:break-all;background:#f8f9ff"
                  id="webhook-url-text"><?= e($webhookUrl) ?></code>
            <button class="btn btn-outline-secondary btn-sm px-3" onclick="copyWebhook()" title="Copiar">
              <i class="bx bx-copy" id="webhook-copy-icon"></i>
            </button>
          </div>
        </div>
      </div>

      <!-- Número + credenciales -->
      <div class="card mb-4">
        <div class="card-header d-flex align-items-center gap-2">
          <i class="bx bx-phone-call text-primary"></i>
          <h6 class="mb-0">Número de teléfono Twilio</h6>
        </div>
        <div class="card-body">
          <?php if ($primaryAgent): ?>
            <div class="alert alert-info mb-3 py-2 small">
              <i class="bx bx-info-circle me-1"></i>
              Línea asignada al agente <strong><?= e($primaryAgent['name'] ?? '') ?></strong>.
              Las llamadas entrantes se rutean automáticamente a ese agente.
            </div>
          <?php else: ?>
            <div class="alert alert-warning mb-3 py-2 small">
              <i class="bx bx-error me-1"></i>
              No tienes un agente de voz activo.
              <a href="/pages/agent-editor.php" class="alert-link">Crea uno aquí</a>.
            </div>
          <?php endif; ?>

          <div class="row g-3">
            <div class="col-md-5">
              <label class="form-label fw-semibold">Número Twilio</label>
              <div class="input-group">
                <span class="input-group-text"><i class="bx bx-phone"></i></span>
                <input class="form-control font-monospace" id="twilio-phone"
                       value="<?= e($twilioCfg['phoneNumber'] ?? $primaryAgent['phone_number'] ?? '') ?>"
                       placeholder="+15551234567"/>
              </div>
              <small class="text-muted">Formato E.164: +52XXXXXXXXXX (México) · +1XXXXXXXXXX (EE.UU.)</small>
            </div>
            <?php if ($primaryAgent): ?>
            <div class="col-md-4 d-flex align-items-end">
              <div class="p-3 rounded border w-100">
                <small class="text-muted d-block">Agente de voz asociado</small>
                <div class="fw-semibold"><?= e($primaryAgent['name'] ?? '') ?></div>
                <small class="text-muted">
                  <?= ($primaryAgent['is_active'] ?? false) ? '🟢 Activo' : '🔴 Inactivo' ?>
                  · <?= e($primaryAgent['llm_model'] ?? '') ?>
                </small>
              </div>
            </div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Credenciales propias -->
      <div class="card">
        <div class="card-header d-flex align-items-center justify-content-between">
          <div class="d-flex align-items-center gap-2">
            <i class="bx bx-key text-warning"></i>
            <h6 class="mb-0">
              Credenciales propias de Twilio
              <span class="badge bg-label-secondary ms-1">Opcional</span>
            </h6>
          </div>
          <button class="btn btn-sm btn-outline-secondary" type="button"
                  data-bs-toggle="collapse" data-bs-target="#collapseCredentials">
            <i class="bx bx-chevron-down"></i>
            <?= $hasCredentials ? 'Ver / editar' : 'Configurar' ?>
          </button>
        </div>
        <div class="collapse <?= $hasCredentials ? '' : 'show' ?>" id="collapseCredentials">
          <div class="card-body">
            <div class="alert alert-secondary py-2 small mb-3">
              <i class="bx bx-info-circle me-1"></i>
              Si dejas vacíos, se usan las credenciales compartidas de la plataforma AgentCore.
              Rellena solo si tienes tu propia cuenta Twilio y quieres facturación separada.
            </div>
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label">Account SID</label>
                <input class="form-control font-monospace" id="twilio-sid"
                       value="<?= e($twilioCfg['accountSid'] ?? '') ?>"
                       placeholder="ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
                       autocomplete="off" spellcheck="false"/>
                <small class="text-muted">Empieza con "AC" + 32 chars. Disponible en twilio.com/console</small>
              </div>
              <div class="col-md-6">
                <label class="form-label">Auth Token</label>
                <div class="input-group">
                  <input type="password" class="form-control font-monospace" id="twilio-token"
                         value="<?= $hasCredentials ? str_repeat('•', 32) : '' ?>"
                         data-saved="<?= $hasCredentials ? '1' : '0' ?>"
                         placeholder="••••••••••••••••••••••••••••••••"
                         autocomplete="off" spellcheck="false"
                         onfocus="if(this.dataset.saved==='1' && this.value.startsWith('•')) this.value=''; this.dataset.saved='0'"/>
                  <button class="btn btn-outline-secondary" type="button"
                          onclick="const i=this.previousElementSibling; i.type=i.type==='password'?'text':'password'">
                    <i class="bx bx-show"></i>
                  </button>
                </div>
                <?php if ($hasCredentials): ?>
                  <small class="text-success"><i class="bx bx-check me-1"></i>Token guardado. Haz clic para reemplazar.</small>
                <?php else: ?>
                  <small class="text-muted">Disponible en twilio.com/console junto al Account SID</small>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
        <div class="card-footer d-flex gap-2 flex-wrap">
          <button class="btn btn-primary" id="saveTwilioBtn">
            <i class="bx bx-save me-1"></i>Guardar configuración de telefonía
          </button>
          <button class="btn btn-outline-info" id="verifyBtn2" onclick="verifyTwilio()">
            <i class="bx bx-shield-check me-1"></i>Verificar antes de guardar
          </button>
        </div>
      </div>

      <!-- Agentes de voz en esta cuenta -->
      <?php if (!empty($voiceAgents)): ?>
      <div class="card mt-4">
        <div class="card-header">
          <h6 class="mb-0"><i class="bx bx-bot me-2"></i>Agentes de voz en esta cuenta</h6>
        </div>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead class="table-light">
              <tr><th>Agente</th><th>Número asignado</th><th>Webhook</th><th>Estado</th><th></th></tr>
            </thead>
            <tbody>
              <?php foreach ($voiceAgents as $va): ?>
              <tr>
                <td class="fw-semibold"><?= e($va['name'] ?? '') ?></td>
                <td>
                  <?php if (!empty($va['phone_number'])): ?>
                    <code class="small"><?= e($va['phone_number']) ?></code>
                  <?php else: ?>
                    <span class="text-muted small">Sin número</span>
                  <?php endif; ?>
                </td>
                <td>
                  <code class="small text-muted" style="font-size:.7rem"><?= e($webhookUrl) ?></code>
                </td>
                <td><?= ($va['is_active'] ?? false) ? '<span class="badge bg-label-success">Activo</span>' : '<span class="badge bg-label-secondary">Inactivo</span>' ?></td>
                <td>
                  <a href="/pages/agent-editor.php?id=<?= e($va['id']) ?>"
                     class="btn btn-sm btn-outline-primary">
                    <i class="bx bx-edit"></i>
                  </a>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>

    </div><!-- /tab-telephony -->

    <!-- General -->
    <div class="tab-pane fade" id="tab-general">
      <div class="card">
        <div class="card-body">
          <h6 class="mb-3">Información del negocio</h6>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Nombre del negocio</label>
              <input class="form-control" value="<?= e($tenantInfo['name'] ?? '') ?>" readonly/>
              <small class="text-muted">Contacta soporte para cambiar el nombre</small>
            </div>
            <div class="col-md-6">
              <label class="form-label">Plan actual</label>
              <div class="mt-1"><?= planBadge($tenantInfo['plan'] ?? '') ?></div>
            </div>
            <div class="col-md-6">
              <label class="form-label d-flex align-items-center gap-1">Zona horaria <span class="help-tip" data-bs-toggle="popover" data-bs-title="Zona horaria" data-bs-content="Todas las citas y horarios se muestran y agendan en esta zona horaria. Debe coincidir con la de tu negocio.">?</span></label>
              <select class="form-select" id="timezone">
                <?php
                $tzs = ['America/Mexico_City'=>'Ciudad de México (CST/CDT)',
                        'America/Monterrey' => 'Monterrey (CST/CDT)',
                        'America/Tijuana'   => 'Tijuana (PST/PDT)',
                        'America/Cancun'    => 'Cancún (EST)'];
                $currentTz = $settings['timezone'] ?? 'America/Mexico_City';
                foreach ($tzs as $val => $label): ?>
                  <option value="<?= e($val) ?>" <?= $val === $currentTz ? 'selected' : '' ?>>
                    <?= e($label) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
        </div>
      </div>

      <div class="card mt-3">
        <div class="card-body">
          <h6 class="mb-1"><i class="bx bx-globe me-1"></i>Dominio público</h6>
          <p class="text-muted small mb-3">
            Dominio fijo para los enlaces que ven tus clientes (fichas de propiedades, links que el bot envía por WhatsApp).
            Déjalo vacío para usar el predeterminado del sistema.
          </p>
          <div class="row g-2 align-items-end">
            <div class="col-md-8">
              <label class="form-label d-flex align-items-center gap-1">URL base pública <span class="help-tip" data-bs-toggle="popover" data-bs-title="URL base pública" data-bs-content="El dominio con el que se arman los enlaces que ve el cliente (fichas, imágenes, links de WhatsApp). Déjalo vacío si usas el dominio por defecto de la plataforma.">?</span></label>
              <input class="form-control" id="public-domain"
                     placeholder="https://propiedades.miinmobiliaria.com"
                     value="<?= e($settings['publicDomain'] ?? '') ?>"/>
              <small class="text-muted">Debe apuntar al backend (donde se sirven las cédulas <code>/p/…</code>).</small>
            </div>
            <div class="col-md-4">
              <button class="btn btn-primary w-100" id="savePublicDomainBtn">Guardar dominio</button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Horarios de atención -->
    <div class="tab-pane fade" id="tab-scheduling">
      <div class="card">
        <div class="card-header"><h6 class="mb-0"><i class="bx bx-time me-1"></i>Horarios de atención</h6></div>
        <div class="card-body">
          <div class="alert alert-info py-2 px-3 small d-flex gap-2 align-items-start">
            <i class="bx bx-info-circle mt-1"></i>
            <span>Estos son los días y horas en que tu negocio agenda citas. <strong>El bot solo ofrecerá horarios dentro de este rango.</strong>
            Si manejas doctores o profesionales con horario propio (Clínica / Consultorio), el horario de cada uno manda sobre éste.</span>
          </div>
          <?php $sched = $settings['scheduling'] ?? []; ?>
          <div class="row g-3">
            <div class="col-md-3">
              <label class="form-label d-flex align-items-center gap-1">Hora inicio <span class="help-tip" data-bs-toggle="popover" data-bs-title="Hora de inicio" data-bs-content="A partir de esta hora el asistente puede agendar citas. Fuera de este rango, ofrece horarios del día siguiente.">?</span></label>
              <input type="number" class="form-control" id="startHour" min="0" max="23"
                     value="<?= (int)($sched['startHour'] ?? 9) ?>"/>
            </div>
            <div class="col-md-3">
              <label class="form-label d-flex align-items-center gap-1">Hora fin <span class="help-tip" data-bs-toggle="popover" data-bs-title="Hora de cierre" data-bs-content="Hora hasta la que el asistente agenda citas. No ofrecerá horarios después de esta hora.">?</span></label>
              <input type="number" class="form-control" id="endHour" min="1" max="24"
                     value="<?= (int)($sched['endHour'] ?? 18) ?>"/>
            </div>
            <div class="col-md-3">
              <label class="form-label">Duración de cita (min)</label>
              <select class="form-select" id="slotDuration">
                <?php foreach ([15,20,30,45,60,90,120] as $m): ?>
                  <option value="<?= $m ?>" <?= ($sched['slotDurationMins'] ?? 30) == $m ? 'selected' : '' ?>>
                    <?= $m ?> min
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label">Días laborables</label>
              <div class="d-flex gap-2 flex-wrap">
                <?php
                $days = [0=>'Dom',1=>'Lun',2=>'Mar',3=>'Mié',4=>'Jue',5=>'Vie',6=>'Sáb'];
                $workDays = $sched['workDays'] ?? [1,2,3,4,5];
                foreach ($days as $num => $label): ?>
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="day<?= $num ?>"
                           value="<?= $num ?>" <?= in_array($num, $workDays) ? 'checked' : '' ?>/>
                    <label class="form-check-label" for="day<?= $num ?>"><?= $label ?></label>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
            <div class="col-12">
              <button class="btn btn-primary" id="saveSchedulingBtn">Guardar horarios</button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- WhatsApp -->
    <div class="tab-pane fade" id="tab-whatsapp">
      <div class="card">
        <div class="card-header"><h6 class="mb-0">Meta WhatsApp Cloud API</h6></div>
        <div class="card-body">
          <?php $wa = $settings['whatsapp'] ?? []; ?>
          <div class="alert alert-info mb-3">
            <i class="bx bx-info-circle me-2"></i>
            Ver <code>docs/setup-fase3.md</code> para obtener estas credenciales.
          </div>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Phone Number ID</label>
              <input class="form-control" id="waPhoneId" value="<?= e($wa['phoneNumberId'] ?? '') ?>"
                     placeholder="1234567890"/>
            </div>
            <div class="col-md-6">
              <label class="form-label">Access Token</label>
              <input type="password" class="form-control" id="waToken"
                     value="<?= e($wa['accessToken'] ?? '') ?>" placeholder="EAAxxxxx"/>
            </div>
            <div class="col-md-6">
              <label class="form-label">Business Account ID</label>
              <input class="form-control" id="waBusinessId" value="<?= e($wa['businessId'] ?? '') ?>"/>
            </div>
            <div class="col-12">
              <button class="btn btn-success" id="saveWaBtn">
                <i class="bxl-whatsapp me-1"></i>Guardar config WhatsApp
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Cal.com -->
    <div class="tab-pane fade" id="tab-calcom">
      <div class="card">
        <div class="card-header"><h6 class="mb-0">Cal.com (Agendamiento)</h6></div>
        <div class="card-body">
          <?php $cal = $settings['calcom'] ?? []; ?>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">URL de Cal.com</label>
              <input class="form-control" id="calBaseUrl" value="<?= e($cal['baseUrl'] ?? '') ?>"
                     placeholder="https://cal.tudominio.com"/>
            </div>
            <div class="col-md-6">
              <label class="form-label">API Key</label>
              <input type="password" class="form-control" id="calApiKey"
                     value="<?= e($cal['apiKey'] ?? '') ?>" placeholder="cal_live_xxxxx"/>
            </div>
            <div class="col-md-4">
              <label class="form-label">Event Type ID</label>
              <input type="number" class="form-control" id="calEventTypeId"
                     value="<?= (int)($cal['eventTypeId'] ?? 0) ?>"/>
              <small class="text-muted">El número en la URL de tu tipo de evento</small>
            </div>
            <div class="col-12">
              <button class="btn btn-primary" id="saveCalBtn">Guardar config Cal.com</button>
            </div>
          </div>
        </div>
      </div>
    </div>

  </div><!-- / tab-content -->

</div><?php renderFooter(); ?>

<script>
// ── Telefonía ─────────────────────────────────────────────────
function getTwilioPayload() {
  const phoneNumber = document.getElementById('twilio-phone').value.trim();
  const accountSid  = document.getElementById('twilio-sid')?.value.trim() || '';
  const tokenInput  = document.getElementById('twilio-token');
  const authToken   = tokenInput?.value.trim() || '';
  // Si el token sigue con puntos (no lo tocó el usuario), no lo enviamos para no sobreescribir
  const tokenChanged = tokenInput?.dataset.saved !== '1' && !authToken.startsWith('•');
  return { phoneNumber, accountSid, authToken: tokenChanged ? authToken : '', tokenChanged };
}

document.getElementById('saveTwilioBtn')?.addEventListener('click', async () => {
  const { phoneNumber, accountSid, authToken, tokenChanged } = getTwilioPayload();

  if (!phoneNumber) { showToast('Ingresa el número de teléfono', 'danger'); return; }
  if (!/^\+\d{10,15}$/.test(phoneNumber)) {
    showToast('Formato inválido. Usa E.164: +52XXXXXXXXXX', 'danger'); return;
  }

  const btn = document.getElementById('saveTwilioBtn');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Guardando…';

  try {
    const twilio = { phoneNumber };
    if (accountSid)              twilio.accountSid = accountSid;
    if (tokenChanged && authToken) twilio.authToken = authToken;

    const r1 = await fetch('/api/settings-save.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ twilio }),
    });
    if (!r1.ok) { const d = await r1.json(); throw new Error(d.error || 'Error guardando'); }

    // Sincronizar número al agente de voz principal
    const agentId = <?= json_encode($primaryAgent['id'] ?? null) ?>;
    if (agentId) {
      await fetch('/api/agent-save.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: agentId, phone_number: phoneNumber }),
      }).catch(() => {});
    }

    window.showToast?.('Configuración de telefonía guardada', 'success');
    // Mark token as saved so next submit doesn't resend dots
    const tokenInput = document.getElementById('twilio-token');
    if (tokenInput && !tokenInput.value.startsWith('•')) {
      tokenInput.value = '•'.repeat(32);
      tokenInput.dataset.saved = '1';
    }
  } catch (err) {
    window.showToast?.('Error: ' + err.message, 'danger');
  } finally {
    btn.disabled = false;
    btn.innerHTML = '<i class="bx bx-save me-1"></i>Guardar configuración de telefonía';
  }
});

// ── Verificar Twilio ──────────────────────────────────────────
async function verifyTwilio() {
  const { phoneNumber, accountSid, authToken, tokenChanged } = getTwilioPayload();

  const [b1, b2] = [document.getElementById('verifyBtn'), document.getElementById('verifyBtn2')];
  const setLoading = v => {
    [b1, b2].forEach(b => { if(b) { b.disabled = v; if(v) b.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Verificando…'; } });
  };
  const resetBtns = () => {
    if(b1) { b1.disabled = false; b1.innerHTML = '<i class="bx bx-shield-check me-1"></i>Verificar conexión'; }
    if(b2) { b2.disabled = false; b2.innerHTML = '<i class="bx bx-shield-check me-1"></i>Verificar antes de guardar'; }
  };

  if (!accountSid) { showToast('Ingresa el Account SID para verificar', 'warning'); return; }

  setLoading(true);
  const resultEl = document.getElementById('verify-result');
  const statusEl = document.getElementById('twilio-status-badge');

  try {
    const body = { accountSid };
    if (tokenChanged && authToken) body.authToken = authToken;
    if (phoneNumber) body.phoneNumber = phoneNumber;

    const res  = await fetch('/api/settings-twilio-verify.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    });
    const data = await res.json();

    if (data.ok) {
      statusEl.innerHTML = `<span class="badge bg-label-success fs-6 px-3 py-2"><i class="bx bx-check-circle me-1"></i>Conectado: ${data.accountName}</span>`;
      resultEl.innerHTML = `<span class="text-success"><i class="bx bx-check me-1"></i>${data.message}</span>`;
    } else {
      statusEl.innerHTML = `<span class="badge bg-label-danger fs-6 px-3 py-2"><i class="bx bx-x-circle me-1"></i>Credenciales inválidas</span>`;
      resultEl.innerHTML = `<span class="text-danger"><i class="bx bx-x me-1"></i>${data.error}</span>`;
    }
  } catch (err) {
    resultEl.innerHTML = `<span class="text-danger">Error: ${err.message}</span>`;
  } finally {
    resetBtns();
  }
}

// ── Copy webhook URL ──────────────────────────────────────────
function copyWebhook() {
  const url = document.getElementById('webhook-url-text').textContent.trim();
  navigator.clipboard.writeText(url).then(() => {
    const icon = document.getElementById('webhook-copy-icon');
    icon.className = 'bx bx-check text-success';
    setTimeout(() => { icon.className = 'bx bx-copy'; }, 2000);
  });
}

// ── Proxy local → global showToast (global defined in dashboard.js) ──
function showToast(msg, type = 'info') {
  window.showToast?.(msg, type);
}

// Guardar perfil de negocio. El GIRO/industria NO se envía: lo gobierna el
// superadmin y el backend lo preserva (el admin no puede cambiarlo aquí).
document.getElementById('saveBizProfileBtn')?.addEventListener('click', async function() {
  await saveSettings({
    businessProfile: {
      businessName: document.getElementById('biz-name').value,
      phone:        document.getElementById('biz-phone').value,
      address:      document.getElementById('biz-address').value,
      city:         document.getElementById('biz-city').value,
      currency:     document.getElementById('biz-currency').value,
      website:      document.getElementById('biz-website').value,
      email:        document.getElementById('biz-email').value,
      description:  document.getElementById('biz-description').value,
    },
    recognizeReturningCallers: document.getElementById('biz-recognize')?.checked === true,
  }, this);
});

// Guardar sucursal + zona de entrega. El PIN del mapa es la fuente de verdad:
// se envían sus coordenadas; el backend las usa tal cual (sin re-geocodificar).
document.getElementById('saveDeliveryBtn')?.addEventListener('click', async function() {
  const address = document.getElementById('del-address').value.trim();
  const radius  = parseFloat(document.getElementById('del-radius').value);
  const lat = parseFloat(document.getElementById('del-lat')?.value);
  const lng = parseFloat(document.getElementById('del-lng')?.value);
  if (!(radius > 0)) { showToast('Indica un radio de entrega válido (km)', 'warning'); return; }
  if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
    showToast('Ubica tu sucursal en el mapa (arrastra el pin)', 'warning'); return;
  }
  const payload = { delivery: { originAddress: address, radiusKm: radius, originLat: lat, originLng: lng } };
  const ok = await saveSettings(payload, this);
  if (ok) showToast('<i class="bx bx-check me-1"></i>Sucursal y zona guardadas', 'success');
});

// Guardar scheduling
document.getElementById('saveSchedulingBtn')?.addEventListener('click', async function() {
  const days = [...document.querySelectorAll('[id^="day"]:checked')].map(c => parseInt(c.value));
  const payload = {
    scheduling: {
      startHour: parseInt(document.getElementById('startHour').value),
      endHour:   parseInt(document.getElementById('endHour').value),
      slotDurationMins: parseInt(document.getElementById('slotDuration').value),
      workDays:  days,
      timezone:  document.getElementById('timezone')?.value || 'America/Mexico_City',
      blockedDates: [],
    }
  };
  await saveSettings(payload, this);
});

// Guardar dominio público
document.getElementById('savePublicDomainBtn')?.addEventListener('click', async function() {
  let v = document.getElementById('public-domain').value.trim().replace(/\/+$/, '');
  if (v && !/^https?:\/\//i.test(v)) v = 'https://' + v;   // anteponer https si falta
  await saveSettings({ publicDomain: v }, this);
});

// Guardar WhatsApp
document.getElementById('saveWaBtn')?.addEventListener('click', async function() {
  await saveSettings({
    whatsapp: {
      phoneNumberId: document.getElementById('waPhoneId').value,
      accessToken:   document.getElementById('waToken').value,
      businessId:    document.getElementById('waBusinessId').value,
    }
  }, this);
});

// Guardar Cal.com
document.getElementById('saveCalBtn')?.addEventListener('click', async function() {
  await saveSettings({
    calcom: {
      baseUrl:     document.getElementById('calBaseUrl').value,
      apiKey:      document.getElementById('calApiKey').value,
      eventTypeId: parseInt(document.getElementById('calEventTypeId').value),
    }
  }, this);
});

async function saveSettings(partial, btnEl) {
  if (btnEl) {
    btnEl.disabled = true;
    btnEl._origLabel = btnEl.innerHTML;
    btnEl.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Guardando…';
  }
  let ok = false;
  try {
    const res = await fetch('/api/settings-save.php', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify(partial),
    });
    if (res.ok) {
      ok = true;
      showToast('<i class="bx bx-check-circle me-1"></i>Configuración guardada correctamente', 'success');
    } else {
      const d = await res.json().catch(() => ({}));
      showToast('Error: ' + (d.error || 'No se pudo guardar'), 'danger');
    }
  } catch (err) {
    showToast('Error de red: ' + err.message, 'danger');
  } finally {
    if (btnEl) {
      btnEl.disabled = false;
      btnEl.innerHTML = btnEl._origLabel;
    }
  }
  return ok;
}

// ── Tab persistence ───────────────────────────────────────────
(function () {
  const TAB_KEY = 'settings_active_tab';
  const tabs = document.querySelectorAll('#settingsTabs [data-bs-toggle="tab"]');

  // Restore saved tab (or default to first)
  const saved = sessionStorage.getItem(TAB_KEY) || '#tab-bizprofile';
  const target = document.querySelector(`#settingsTabs [href="${saved}"]`);
  if (target) {
    // Remove active from first tab (set in HTML) then activate the saved one
    tabs.forEach(t => { t.classList.remove('active'); });
    document.querySelectorAll('.tab-pane').forEach(p => { p.classList.remove('show', 'active'); });
    target.classList.add('active');
    const pane = document.querySelector(saved);
    if (pane) pane.classList.add('show', 'active');
  } else {
    // No saved tab — activate first
    tabs[0]?.classList.add('active');
    document.querySelector('.tab-pane')?.classList.add('show', 'active');
  }

  // Save tab on switch
  tabs.forEach(t => t.addEventListener('shown.bs.tab', e => {
    sessionStorage.setItem(TAB_KEY, e.target.getAttribute('href'));
  }));
})();
</script>
