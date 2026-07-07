<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/head.php';
require_once __DIR__ . '/../includes/sidebar.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/footer.php';

requireAuth();

$agentId = trim($_GET['id'] ?? '');
$isNew   = empty($agentId);
$agent   = [];
$cfg     = [];
$kbDocs  = [];

if (!$isNew) {
    $agent = apiGet("/agents/{$agentId}");
    if (isset($agent['error'])) { header('Location: /pages/agents.php'); exit; }
    $cfg = $agent['config'] ?? [];

    // Load KB docs for assignment tab
    $kbRes  = apiGet('/kb');
    // Solo elementos array (los documentos); descarta el '_status' int que añade apiGet.
    $kbDocs = (is_array($kbRes) && !isset($kbRes['error']))
        ? array_values(array_filter($kbRes, 'is_array'))
        : [];
}

// Para agente nuevo: pre-poblar desde configuración del tenant
$tenantCfg = [];
if ($isNew) {
    $ts = tenantSettings();
    $tenantCfg = [
        'businessName' => tenantBusinessName(),
        'industry'     => tenantIndustry(),
        'tone'         => $ts['tone']      ?? 'professional',
        'objective'    => $ts['objective'] ?? '',
        'greeting'     => $ts['greeting']  ?? '',
    ];
    $cfg = $tenantCfg; // para valores de selects / inputs
}

$schedule   = $cfg['schedule'] ?? [];
$schedHz    = $schedule['hours'] ?? [];
$schedTz    = $schedule['timezone'] ?? 'America/Mexico_City';
$outsideMsg = $schedule['outsideHoursMsg'] ?? '';

$pageTitle = $isNew ? 'Nuevo agente' : 'Editar agente — ' . ($agent['name'] ?? '');
renderHead($pageTitle);

// Webhook URL (Twilio hits the backend directly)
$webhookUrl = rtrim(BACKEND_ROOT, '/') . '/webhooks/twilio/voice';
?>

<style>
  /* ── Tab nav ─────────────────────────────────────────────── */
  .nav-pills .nav-link { color: #8592a3; border-radius: .375rem; font-size: .875rem; }
  .nav-pills .nav-link.active { background: #696cff; color: #fff; }
  .nav-pills .nav-link i { font-size: 1.05rem; }

  html.dark-style .nav-pills .nav-link { color: #a1acbe; }

  /* ── Item cards (FAQs, specialties) ──────────────────────── */
  .item-card {
    background: #f8f8ff; border: 1px solid #e5e5ff;
    border-radius: .5rem; padding: 1rem; position: relative;
  }
  html.dark-style .item-card { background: #2a2b3d; border-color: #3b3e5e; }
  .item-card .btn-remove { position: absolute; top: .5rem; right: .5rem; }

  /* ── Prompt preview ──────────────────────────────────────── */
  #prompt-preview {
    background: #1e1e2e; color: #cdd6f4;
    font-family: 'Courier New', monospace; font-size: .8rem;
    line-height: 1.6; border-radius: .5rem; padding: 1.25rem;
    white-space: pre-wrap; max-height: 380px; overflow-y: auto;
  }

  /* ── Section title ───────────────────────────────────────── */
  .section-title {
    font-size: .7rem; font-weight: 700;
    letter-spacing: .08em; text-transform: uppercase;
    color: #8592a3; margin-bottom: .75rem;
  }

  /* ── Voice cards ─────────────────────────────────────────── */
  .voice-card {
    border: 2px solid #e5e7eb; border-radius: .75rem;
    padding: 1rem .75rem; cursor: pointer; transition: all .15s;
    text-align: center; position: relative;
  }
  .voice-card:hover { border-color: #696cff; box-shadow: 0 0 0 3px rgba(105,108,255,.15); }
  .voice-card.selected { border-color: #696cff; background: rgba(105,108,255,.07); }
  .voice-card .voice-avatar { font-size: 2rem; line-height: 1; margin-bottom: .4rem; }
  .voice-card .voice-name { font-weight: 600; font-size: .88rem; }
  .voice-card .voice-desc { font-size: .75rem; color: #8592a3; }
  .voice-card .btn-preview {
    position: absolute; top: .4rem; right: .4rem;
    width: 1.75rem; height: 1.75rem; padding: 0;
    display: flex; align-items: center; justify-content: center;
    font-size: .85rem;
  }
  html.dark-style .voice-card { border-color: #3b3e5e; }
  html.dark-style .voice-card.selected { background: rgba(105,108,255,.15); }

  /* ── Schedule builder ────────────────────────────────────── */
  .sched-row { display: grid; grid-template-columns: 1.5rem 5rem 1fr 1fr; gap: .5rem 1rem; align-items: center; margin-bottom: .5rem; }
  .sched-day-label { font-size: .83rem; font-weight: 500; }

  /* ── KB assign list ──────────────────────────────────────── */
  .kb-doc-row { display: flex; align-items: center; gap: .75rem; padding: .6rem .75rem; border-radius: .5rem; border: 1px solid #e5e7eb; margin-bottom: .4rem; transition: background .1s; }
  .kb-doc-row:hover { background: #f8f9ff; }
  html.dark-style .kb-doc-row { border-color: #3b3e5e; }
  html.dark-style .kb-doc-row:hover { background: #2a2b3d; }
  .kb-doc-row .kb-title { flex: 1; font-size: .875rem; font-weight: 500; }
  .kb-doc-row .kb-meta  { font-size: .75rem; color: #8592a3; }

  /* ── Simulator chat ──────────────────────────────────────── */
  #sim-messages {
    height: 340px; overflow-y: auto; display: flex; flex-direction: column; gap: .5rem; padding: .75rem;
    background: #f8f9ff; border-radius: .5rem; margin-bottom: .75rem;
  }
  html.dark-style #sim-messages { background: #232333; }
  .sim-msg { max-width: 78%; padding: .55rem .85rem; border-radius: 1rem; font-size: .875rem; line-height: 1.5; }
  .sim-msg.user { background: #696cff; color: #fff; align-self: flex-end; border-bottom-right-radius: .3rem; }
  .sim-msg.agent { background: #fff; border: 1px solid #e5e7eb; align-self: flex-start; border-bottom-left-radius: .3rem; }
  html.dark-style .sim-msg.agent { background: #2a2b3d; border-color: #3b3e5e; color: #d0d3e8; }

  /* ── Webhook URL ─────────────────────────────────────────── */
  .webhook-url-box { background: #f8f9ff; border: 1px dashed #c5c6d8; border-radius: .5rem; padding: .65rem 1rem; font-family: monospace; font-size: .82rem; word-break: break-all; }
  html.dark-style .webhook-url-box { background: #232333; border-color: #3b3e5e; color: #a1acbe; }

  /* ── is_active toggle badge ──────────────────────────────── */
  .active-toggle-wrap { display: flex; align-items: center; gap: .5rem; }

  /* ── Type picker (nuevo agente) ──────────────────────────── */
  .type-card {
    border: 2px solid #e5e7eb; border-radius: .9rem;
    padding: 1.5rem 1.35rem; cursor: pointer; height: 100%;
    transition: all .15s; display: flex; flex-direction: column;
  }
  .type-card:hover { border-color: #696cff; box-shadow: 0 .35rem 1.1rem rgba(105,108,255,.18); transform: translateY(-2px); }
  .type-card.selected { border-color: #696cff; background: rgba(105,108,255,.05); }
  .type-card .tc-icon { font-size: 2.4rem; line-height: 1; }
  .type-card .tc-name { font-weight: 700; font-size: 1.05rem; margin: .6rem 0 .15rem; }
  .type-card .tc-tagline { font-size: .82rem; color: #8592a3; margin-bottom: .9rem; min-height: 2.4em; }
  .type-card .tc-can { list-style: none; padding: 0; margin: 0 0 1rem; }
  .type-card .tc-can li { font-size: .82rem; padding: .15rem 0; display: flex; gap: .45rem; align-items: flex-start; }
  .type-card .tc-can li i { color: #71dd37; font-size: 1rem; flex-shrink: 0; margin-top: .05rem; }
  .type-card .tc-needs { margin-top: auto; font-size: .76rem; }
  html.dark-style .type-card { border-color: #3b3e5e; }
  html.dark-style .type-card.selected { background: rgba(105,108,255,.14); }

  /* ── Channel scope banner (pestaña Canal) ────────────────── */
  .chan-section.dim { opacity: .42; }
  .chan-na { font-size: .74rem; }
</style>

<div class="layout-wrapper layout-content-navbar">
<div class="layout-container">
<?php renderSidebar('agents'); ?>
<div class="layout-page">
<?php renderNavbar($pageTitle); ?>
<div class="content-wrapper">
<div class="container-xxl flex-grow-1 container-p-y">

  <!-- Breadcrumb + header ──────────────────────────────────── -->
  <div class="d-flex align-items-center gap-3 mb-4 flex-wrap">
    <a href="/pages/agents.php" class="btn btn-sm btn-outline-secondary">
      <i class="bx bx-arrow-back me-1"></i>Agentes
    </a>
    <h4 class="mb-0 me-auto"><?= e($isNew ? 'Nuevo agente' : 'Editar: ' . ($agent['name'] ?? '')) ?></h4>

    <?php if (!$isNew): ?>
    <!-- is_active toggle -->
    <div class="active-toggle-wrap">
      <span class="text-muted small">Estado:</span>
      <div class="form-check form-switch mb-0" title="Activar / desactivar agente">
        <input class="form-check-input" type="checkbox" id="toggleActive"
               <?= ($agent['is_active'] ?? false) ? 'checked' : '' ?>
               onchange="toggleActive(this.checked)">
        <label class="form-check-label small" for="toggleActive" id="activeLabel">
          <?= ($agent['is_active'] ?? false) ? 'Activo' : 'Inactivo' ?>
        </label>
      </div>
    </div>

    <?php if (!$isNew): ?>
    <!-- Chat Simulator -->
    <button class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#modalSimulator">
      <i class="bx bx-bot me-1"></i>Probar agente
    </button>
    <?php endif; ?>
    <?php endif; ?>

    <!-- Plantillas: solo superadmin -->
    <?php if (isSuperAdmin()): ?>
    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalPerfiles">
      <i class="bx bx-layer me-1"></i>Cargar plantilla
    </button>
    <?php endif; ?>
  </div>

  <?php if ($isNew): ?>
  <!-- ══ PASO 0 — Elegir tipo de agente ═══════════════════════ -->
  <div id="type-picker" class="card mb-4">
    <div class="card-body p-4">
      <div class="text-center mb-4">
        <h5 class="mb-1">¿Qué tipo de agente quieres crear?</h5>
        <p class="text-muted mb-0">Elige el canal por donde atenderá a tus clientes. Podrás ajustar todo después.</p>
      </div>
      <div class="row g-3" id="type-cards">
        <!-- Inyectado por JS desde CHANNEL_TYPES -->
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="row g-4" id="editor-body" <?= $isNew ? 'style="display:none"' : '' ?>>

    <!-- ── Tab nav (izquierda) ─────────────────────────────── -->
    <div class="col-md-3">
      <div class="card">
        <div class="card-body p-3">
          <div class="nav flex-column nav-pills gap-1" id="editorTabs" role="tablist">
            <button class="nav-link text-start active d-flex align-items-center gap-2"
                    data-bs-toggle="pill" data-bs-target="#pane-identity" type="button">
              <i class="bx bx-id-card"></i> Identidad
            </button>
            <button class="nav-link text-start d-flex align-items-center gap-2"
                    data-bs-toggle="pill" data-bs-target="#pane-voice" type="button">
              <i class="bx bx-microphone"></i> Voz
            </button>
            <button class="nav-link text-start d-flex align-items-center gap-2"
                    data-bs-toggle="pill" data-bs-target="#pane-specialties" type="button">
              <i class="bx bx-list-check"></i> Especialidades
              <span class="badge bg-label-primary ms-auto" id="badge-specialties"><?= count($cfg['specialties'] ?? []) ?></span>
            </button>
            <button class="nav-link text-start d-flex align-items-center gap-2"
                    data-bs-toggle="pill" data-bs-target="#pane-faqs" type="button">
              <i class="bx bx-help-circle"></i> FAQs
              <span class="badge bg-label-primary ms-auto" id="badge-faqs"><?= count($cfg['faqs'] ?? []) ?></span>
            </button>
            <button class="nav-link text-start d-flex align-items-center gap-2"
                    data-bs-toggle="pill" data-bs-target="#pane-schedule" type="button">
              <i class="bx bx-time-five"></i> Horarios
            </button>
            <button class="nav-link text-start d-flex align-items-center gap-2"
                    data-bs-toggle="pill" data-bs-target="#pane-channel" type="button">
              <i class="bx bx-phone"></i> Canal
            </button>
            <?php if (!$isNew): ?>
            <button class="nav-link text-start d-flex align-items-center gap-2"
                    data-bs-toggle="pill" data-bs-target="#pane-kb" type="button">
              <i class="bx bx-book-open"></i> Base de conocimiento
              <span class="badge bg-label-primary ms-auto" id="badge-kb">0</span>
            </button>
            <?php endif; ?>
            <button class="nav-link text-start d-flex align-items-center gap-2"
                    data-bs-toggle="pill" data-bs-target="#pane-advanced" type="button">
              <i class="bx bx-cog"></i> Avanzado
            </button>
            <button class="nav-link text-start d-flex align-items-center gap-2"
                    data-bs-toggle="pill" data-bs-target="#pane-preview" type="button"
                    onclick="refreshPreview()">
              <i class="bx bx-show"></i> Vista previa
            </button>
          </div>
        </div>
      </div>

      <!-- Guardar -->
      <div class="mt-3 d-grid gap-2">
        <button class="btn btn-primary" id="btnSave" onclick="saveAgent()">
          <span id="saveText"><i class="bx bx-save me-1"></i>Guardar cambios</span>
          <span id="saveSpinner" class="d-none">
            <span class="spinner-border spinner-border-sm me-1"></span>Guardando…
          </span>
        </button>
        <?php if (!$isNew): ?>
        <a href="/pages/conversations.php?agent_id=<?= e($agentId) ?>"
           class="btn btn-outline-secondary btn-sm">
          <i class="bx bx-history me-1"></i>Ver conversaciones
        </a>
        <?php endif; ?>
      </div>

      <div id="saveAlert" class="alert mt-3 d-none"></div>
    </div>

    <!-- ── Paneles ──────────────────────────────────────────── -->
    <div class="col-md-9">
      <div class="tab-content" id="editorTabContent">

        <!-- ══ IDENTIDAD ══════════════════════════════════════ -->
        <div class="tab-pane fade show active" id="pane-identity">
          <div class="card">
            <div class="card-header py-3">
              <p class="section-title mb-0"><i class="bx bx-id-card me-1"></i>Identidad del agente</p>
            </div>
            <div class="card-body">
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label">Nombre del agente *</label>
                  <input type="text" class="form-control" id="f-name"
                         value="<?= e($agent['name'] ?? '') ?>"
                         placeholder="Ej: Andrea, Recepcionista IA">
                  <small class="text-muted">El usuario lo escuchará en la conversación.</small>
                </div>
                <div class="col-md-6">
                  <label class="form-label">Nombre del negocio *</label>
                  <input type="text" class="form-control" id="f-business"
                         value="<?= e($cfg['businessName'] ?? '') ?>"
                         placeholder="Ej: Clínica Dental Rodríguez">
                </div>
                <div class="col-md-6">
                  <label class="form-label d-flex align-items-center gap-1">Tono de voz <span class="help-tip" data-bs-toggle="popover" data-bs-title="Tono de voz" data-bs-content="Define la personalidad con la que responde el agente: profesional, cálido, formal o casual. Afecta cómo saluda y conversa con tus clientes.">?</span></label>
                  <select class="form-select" id="f-tone">
                    <?php foreach (['professional'=>'Profesional y amable','friendly'=>'Cálido y cercano','formal'=>'Formal y cortés','casual'=>'Relajado y amigable'] as $val=>$label): ?>
                      <option value="<?= $val ?>" <?= ($cfg['tone']??'professional')===$val?'selected':'' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-6">
                  <label class="form-label">Canal principal</label>
                  <select class="form-select" id="f-channel" onchange="updateChannelUI()">
                    <option value="voice"    <?= ($agent['channel']??'voice')==='voice'    ?'selected':'' ?>>📞 Voz (Twilio)</option>
                    <option value="webchat"  <?= ($agent['channel']??'')==='webchat'  ?'selected':'' ?>>🌐 Chat Web</option>
                    <option value="whatsapp" <?= ($agent['channel']??'')==='whatsapp' ?'selected':'' ?>>💬 WhatsApp</option>
                    <option value="sms"      <?= ($agent['channel']??'')==='sms'      ?'selected':'' ?>>📱 SMS</option>
                  </select>
                  <small class="text-muted">Define dónde atiende. Mira su alcance en la pestaña <strong>Canal</strong>.</small>
                </div>
                <div class="col-12">
                  <label class="form-label d-flex align-items-center gap-1">Objetivo del agente <span class="help-tip" data-bs-toggle="popover" data-bs-title="Objetivo del agente" data-bs-content="La meta principal que persigue el agente en cada conversación (ej. agendar citas, calificar prospectos, tomar pedidos). Guía sus respuestas y hacia dónde lleva la charla.">?</span></label>
                  <input type="text" class="form-control" id="f-objective"
                         value="<?= e($cfg['objective']??'') ?>"
                         placeholder="Ej: Agendar citas odontológicas y resolver dudas sobre servicios y precios">
                </div>
                <div class="col-12">
                  <label class="form-label">Saludo inicial</label>
                  <input type="text" class="form-control" id="f-greeting"
                         value="<?= e($cfg['greeting']??'') ?>"
                         placeholder="Ej: Buenas tardes, habla Andrea de la Clínica Dental. ¿En qué le puedo ayudar?">
                  <small class="text-muted">Exactamente lo que el agente dirá al contestar la llamada.</small>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- ══ VOZ ════════════════════════════════════════════ -->
        <div class="tab-pane fade" id="pane-voice">
          <div class="card">
            <div class="card-header py-3">
              <p class="section-title mb-0"><i class="bx bx-microphone me-1"></i>Configuración de voz (Cartesia TTS)</p>
            </div>
            <div class="card-body">
              <!-- Audio element for preview -->
              <audio id="voicePreviewAudio" class="d-none"></audio>

              <div class="row g-3 mb-4">
                <div class="col-md-6">
                  <label class="form-label">Idioma / variante</label>
                  <select class="form-select" id="f-language">
                    <?php
                    $langs = [
                      'es-MX' => '🇲🇽 Español (México)',
                      'es-ES' => '🇪🇸 Español (España)',
                      'es-419'=> '🌎 Español (Latinoamérica)',
                      'en-US' => '🇺🇸 English (US)',
                    ];
                    $curLang = $agent['language'] ?? 'es-MX';
                    foreach ($langs as $v=>$l): ?>
                      <option value="<?= $v ?>" <?= $curLang===$v?'selected':'' ?>><?= $l ?></option>
                    <?php endforeach; ?>
                  </select>
                  <small class="text-muted">Afecta el idioma de transcripción (Deepgram) y síntesis (Cartesia).</small>
                </div>
                <div class="col-md-6 d-flex align-items-end">
                  <div class="alert alert-info mb-0 py-2 px-3 w-100" style="font-size:.8rem">
                    <i class="bx bx-info-circle me-1"></i>
                    Haz clic en <i class="bx bx-play-circle"></i> en cada voz para escuchar una muestra antes de seleccionar.
                  </div>
                </div>
              </div>

              <p class="section-title">Selecciona la voz del agente</p>
              <div class="row g-3" id="voice-cards-container">
                <!-- JS renderiza las tarjetas -->
              </div>
              <input type="hidden" id="f-voice-id" value="<?= e($agent['voice_id'] ?? '') ?>">
            </div>
          </div>
        </div>

        <!-- ══ ESPECIALIDADES ═════════════════════════════════ -->
        <div class="tab-pane fade" id="pane-specialties">
          <div class="card">
            <div class="card-header py-3 d-flex justify-content-between align-items-center">
              <p class="section-title mb-0"><i class="bx bx-list-check me-1"></i>Especialidades / Servicios</p>
              <button class="btn btn-sm btn-primary" onclick="addSpecialty()">
                <i class="bx bx-plus me-1"></i>Agregar
              </button>
            </div>
            <div class="card-body">
              <p class="text-muted small mb-3">
                Define los servicios disponibles. El agente preguntará cuál necesita el usuario antes de consultar disponibilidad.
              </p>
              <div id="specialties-list" class="d-flex flex-column gap-3"></div>
              <div id="specialties-empty" class="text-center text-muted py-4 <?= !empty($cfg['specialties'])?'d-none':'' ?>">
                <i class="bx bx-list-check bx-lg opacity-25 d-block mb-2"></i>
                Sin especialidades — el agente agendará sin preguntar el tipo de servicio.
              </div>
            </div>
          </div>
        </div>

        <!-- ══ FAQS ═══════════════════════════════════════════ -->
        <div class="tab-pane fade" id="pane-faqs">
          <div class="card">
            <div class="card-header py-3 d-flex justify-content-between align-items-center">
              <p class="section-title mb-0"><i class="bx bx-help-circle me-1"></i>Preguntas frecuentes</p>
              <button class="btn btn-sm btn-primary" onclick="addFaq()">
                <i class="bx bx-plus me-1"></i>Agregar pregunta
              </button>
            </div>
            <div class="card-body">
              <p class="text-muted small mb-3">
                El agente responderá estas preguntas con la información exacta que indiques, sin improvisar.
              </p>
              <div id="faqs-list" class="d-flex flex-column gap-3"></div>
              <div id="faqs-empty" class="text-center text-muted py-4 <?= !empty($cfg['faqs'])?'d-none':'' ?>">
                <i class="bx bx-help-circle bx-lg opacity-25 d-block mb-2"></i>
                Sin FAQs — el agente responderá con conocimiento general.
              </div>
            </div>
          </div>
        </div>

        <!-- ══ HORARIOS ════════════════════════════════════════ -->
        <div class="tab-pane fade" id="pane-schedule">
          <div class="card">
            <div class="card-header py-3">
              <p class="section-title mb-0"><i class="bx bx-time-five me-1"></i>Horarios de atención</p>
            </div>
            <div class="card-body">
              <div class="row g-3 mb-4">
                <div class="col-md-5">
                  <label class="form-label">Zona horaria</label>
                  <select class="form-select" id="f-timezone">
                    <?php
                    $tzones = [
                      'America/Mexico_City'  => '🇲🇽 Ciudad de México (CST/CDT)',
                      'America/Monterrey'    => '🇲🇽 Monterrey (CST/CDT)',
                      'America/Tijuana'      => '🇲🇽 Tijuana (PST/PDT)',
                      'America/Hermosillo'   => '🇲🇽 Hermosillo (MST)',
                      'America/New_York'     => '🇺🇸 New York (EST/EDT)',
                      'America/Chicago'      => '🇺🇸 Chicago (CST/CDT)',
                      'America/Los_Angeles'  => '🇺🇸 Los Angeles (PST/PDT)',
                      'America/Bogota'       => '🇨🇴 Bogotá (COT)',
                      'America/Lima'         => '🇵🇪 Lima (PET)',
                      'America/Santiago'     => '🇨🇱 Santiago (CLT)',
                      'America/Buenos_Aires' => '🇦🇷 Buenos Aires (ART)',
                      'Europe/Madrid'        => '🇪🇸 Madrid (CET/CEST)',
                    ];
                    foreach ($tzones as $v=>$l): ?>
                      <option value="<?= $v ?>" <?= $schedTz===$v?'selected':'' ?>><?= $l ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-7">
                  <label class="form-label d-flex align-items-center">
                    Mensaje fuera de horario
                    <span class="help-tip" data-bs-toggle="popover"
                          data-bs-title="Fuera de horario"
                          data-bs-content="Lo que el agente dice cuando recibe una llamada fuera del horario configurado. Si está vacío, el agente responde normalmente sin mencionar horarios.">?</span>
                  </label>
                  <input type="text" class="form-control" id="f-outside-msg"
                         value="<?= e($outsideMsg) ?>"
                         placeholder="Ej: Nuestro horario es de lunes a viernes de 9 a 19 hrs. Te llamamos mañana.">
                  <small class="text-muted">Lo que el agente dice cuando recibe una llamada fuera del horario configurado.</small>
                </div>
              </div>

              <p class="section-title">Días y horarios</p>
              <div id="schedule-builder">
                <!-- JS renderiza los días -->
              </div>
              <small class="text-muted d-block mt-2">
                <i class="bx bx-info-circle me-1"></i>
                Deja un día deshabilitado para que el agente rechace llamadas ese día.
              </small>
            </div>
          </div>
        </div>

        <!-- ══ CANAL ══════════════════════════════════════════ -->
        <div class="tab-pane fade" id="pane-channel">
          <div class="card">
            <div class="card-header py-3">
              <p class="section-title mb-0"><i class="bx bx-phone me-1"></i>Configuración de canal</p>
            </div>
            <div class="card-body">
              <!-- Banner de alcance del canal seleccionado (dinámico) -->
              <div id="channel-scope" class="alert d-flex gap-3 align-items-start mb-4"></div>

              <div class="row g-4">

                <!-- Sección Twilio -->
                <div class="col-12 chan-section" id="sect-voice">
                  <p class="section-title">
                    <i class="bx bxl-twilio me-1"></i>Voz (Twilio)
                    <span class="chan-na text-muted ms-2 d-none">— no aplica a este canal</span>
                  </p>
                  <div class="row g-3">
                    <div class="col-md-5">
                      <label class="form-label">Número de teléfono Twilio</label>
                      <input type="text" class="form-control" id="f-phone"
                             value="<?= e($agent['phone_number'] ?? '') ?>"
                             placeholder="+18148903040">
                      <small class="text-muted">Número Twilio asignado a este agente. Formato E.164.</small>
                    </div>
                    <div class="col-md-7">
                      <label class="form-label">URL del Webhook (configura en Twilio Console)</label>
                      <div class="d-flex gap-2 align-items-center">
                        <div class="webhook-url-box flex-grow-1" id="webhook-url-display">
                          <?= e($webhookUrl) ?>
                        </div>
                        <button class="btn btn-outline-secondary btn-sm px-3 flex-shrink-0"
                                onclick="copyWebhook()" title="Copiar URL">
                          <i class="bx bx-copy" id="copyIcon"></i>
                        </button>
                      </div>
                      <small class="text-muted">En Twilio → Phone Numbers → tu número → Voice → Webhook → HTTP POST.</small>
                    </div>
                  </div>
                </div>

                <!-- Sección WhatsApp -->
                <div class="col-12 chan-section" id="sect-whatsapp">
                  <p class="section-title">
                    <i class="bx bxl-whatsapp me-1" style="color:#25d366"></i>WhatsApp Business
                    <span class="chan-na text-muted ms-2 d-none">— no aplica a este canal</span>
                  </p>
                  <div class="row g-3">
                    <div class="col-md-5">
                      <label class="form-label">Número WhatsApp</label>
                      <input type="text" class="form-control" id="f-whatsapp"
                             value="<?= e($agent['whatsapp_number'] ?? '') ?>"
                             placeholder="+521234567890">
                      <small class="text-muted">Número Business asignado en Meta. Formato E.164.</small>
                    </div>
                    <div class="col-md-7 d-flex align-items-end">
                      <div class="alert alert-warning mb-0 py-2 px-3 w-100" style="font-size:.8rem">
                        <i class="bx bx-info-circle me-1"></i>
                        Requiere cuenta <strong>Meta Business</strong> aprobada. Configura el webhook en Meta Developer Portal.
                        <a href="/manual.html#whatsapp" target="_blank" class="alert-link">Ver guía</a>.
                      </div>
                    </div>
                  </div>
                </div>

                <!-- Sección Chat Web -->
                <div class="col-12 chan-section" id="sect-webchat">
                  <p class="section-title">
                    <i class="bx bx-globe me-1 text-info"></i>Chat Web (widget)
                    <span class="chan-na text-muted ms-2 d-none">— no aplica a este canal</span>
                  </p>
                  <div class="alert alert-info mb-0 py-2 px-3" style="font-size:.82rem">
                    <i class="bx bx-info-circle me-1"></i>
                    Sin número ni proveedor externo. Cuando guardes el agente, copia el código del widget desde
                    <a href="/pages/web-widget.php" target="_blank" class="alert-link">Chat Web</a> y pégalo en tu sitio.
                  </div>
                </div>

                <!-- Modelo de IA (movido aquí desde Avanzado) -->
                <div class="col-12">
                  <p class="section-title"><i class="bx bx-chip me-1"></i>Modelo de inteligencia artificial</p>
                  <div class="row g-3">
                    <div class="col-md-5">
                      <?php $curModel = $agent['llm_model'] ?? 'claude-haiku-4-5-20251001'; ?>
                      <label class="form-label">Motor LLM</label>
                      <select class="form-select" id="f-model">
                        <option value="claude-haiku-4-5-20251001" <?= $curModel==='claude-haiku-4-5-20251001' ?'selected':'' ?>>Claude Haiku 4.5 (rápido — recomendado)</option>
                        <option value="claude-sonnet-4-6"         <?= $curModel==='claude-sonnet-4-6' ?'selected':'' ?>>Claude Sonnet 4.6 (avanzado)</option>
                        <option value="gpt-4o-mini"               <?= $curModel==='gpt-4o-mini' ?'selected':'' ?>>GPT-4o mini (requiere cuota OpenAI)</option>
                        <option value="gpt-4o"                    <?= $curModel==='gpt-4o' ?'selected':'' ?>>GPT-4o (requiere cuota OpenAI)</option>
                      </select>
                    </div>
                    <div class="col-md-7 d-flex align-items-end">
                      <small class="text-muted">Haiku 4.5 da la menor latencia para voz. Sonnet 4.6 razona mejor en ventas/negociación. El sistema sube a Sonnet automáticamente en consultas complejas.</small>
                    </div>
                  </div>
                </div>

              </div>
            </div>
          </div>
        </div>

        <!-- ══ BASE DE CONOCIMIENTO ══════════════════════════ -->
        <?php if (!$isNew): ?>
        <div class="tab-pane fade" id="pane-kb">
          <div class="card">
            <div class="card-header py-3 d-flex justify-content-between align-items-center">
              <p class="section-title mb-0"><i class="bx bx-book-open me-1"></i>Base de conocimiento asignada</p>
              <a href="/pages/knowledge-base.php" class="btn btn-sm btn-outline-primary" target="_blank">
                <i class="bx bx-plus me-1"></i>Agregar documento
              </a>
            </div>
            <div class="card-body">
              <p class="text-muted small mb-3">
                Los documentos asignados a este agente se buscan primero en cada conversación (RAG). Los documentos sin agente asignado están disponibles para todos los agentes del tenant.
              </p>

              <?php if (empty($kbDocs)): ?>
              <div class="text-center text-muted py-4">
                <i class="bx bx-book-open bx-lg opacity-25 d-block mb-2"></i>
                No hay documentos en la base de conocimiento.
                <a href="/pages/knowledge-base.php">Crea uno aquí.</a>
              </div>
              <?php else: ?>
              <div id="kb-docs-list">
                <?php foreach ($kbDocs as $doc):
                  $isAssigned = ($doc['agent_id'] ?? null) === $agentId;
                  $statusClass = $doc['status'] === 'ready' ? 'bg-label-success' : ($doc['status'] === 'processing' ? 'bg-label-warning' : 'bg-label-secondary');
                  $typeIcon = ['text'=>'bx-file-blank','pdf'=>'bxs-file-pdf','url'=>'bx-link','faq'=>'bx-help-circle'][$doc['file_type']] ?? 'bx-file';
                ?>
                <div class="kb-doc-row" id="kb-row-<?= e($doc['id']) ?>">
                  <i class="bx <?= $typeIcon ?> text-muted"></i>
                  <div class="kb-title"><?= e($doc['title']) ?></div>
                  <div class="kb-meta">
                    <span class="badge <?= $statusClass ?>"><?= e($doc['status']) ?></span>
                    <?php if ($doc['chunk_count'] ?? 0): ?>
                      <span class="ms-1"><?= $doc['chunk_count'] ?> chunks</span>
                    <?php endif; ?>
                  </div>
                  <div class="form-check form-switch mb-0 ms-auto" title="Asignar a este agente">
                    <input class="form-check-input kb-assign-toggle" type="checkbox"
                           data-doc-id="<?= e($doc['id']) ?>"
                           <?= $isAssigned ? 'checked' : '' ?>
                           onchange="toggleKbAssign(this)">
                  </div>
                </div>
                <?php endforeach; ?>
              </div>
              <script>
              // Count assigned docs for badge
              document.addEventListener('DOMContentLoaded', () => {
                const count = document.querySelectorAll('.kb-assign-toggle:checked').length;
                document.getElementById('badge-kb').textContent = count;
              });
              </script>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <?php endif; ?>

        <!-- ══ AVANZADO ════════════════════════════════════════ -->
        <div class="tab-pane fade" id="pane-advanced">
          <div class="card">
            <div class="card-header py-3">
              <p class="section-title mb-0"><i class="bx bx-cog me-1"></i>Configuración avanzada</p>
            </div>
            <div class="card-body">
              <div class="row g-3">
                <div class="col-12">
                  <label class="form-label">Instrucciones extra (texto libre)</label>
                  <textarea class="form-control" id="f-extra" rows="5"
                            placeholder="Reglas específicas, restricciones, manejo de casos edge, frases prohibidas..."><?= e($cfg['extraInstructions']??'') ?></textarea>
                  <small class="text-muted">Se añade al final del prompt generado. Usa esto para casos edge y reglas específicas.</small>
                </div>
                <div class="col-12">
                  <div class="alert alert-info d-flex gap-2 mb-0">
                    <i class="bx bx-info-circle mt-1 flex-shrink-0"></i>
                    <div>
                      El <strong>system prompt final</strong> se genera automáticamente a partir de los campos de Identidad, Especialidades y FAQs.
                      Usa la pestaña <strong>Vista previa</strong> para verlo antes de guardar.
                      Prueba el comportamiento completo con el botón <strong>Probar agente</strong>.
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- ══ VISTA PREVIA ════════════════════════════════════ -->
        <div class="tab-pane fade" id="pane-preview">
          <div class="card">
            <div class="card-header py-3 d-flex justify-content-between align-items-center">
              <p class="section-title mb-0"><i class="bx bx-show me-1"></i>Vista previa del prompt generado</p>
              <button class="btn btn-sm btn-outline-secondary" onclick="refreshPreview()">
                <i class="bx bx-refresh me-1"></i>Actualizar
              </button>
            </div>
            <div class="card-body">
              <p class="text-muted small mb-3">
                Así recibe el agente sus instrucciones. En llamadas reales se añade contexto de conversación, historial y resultados de RAG.
              </p>
              <pre id="prompt-preview">— Haz clic en "Actualizar" para generar la vista previa —</pre>
            </div>
          </div>
        </div>

      </div><!-- /tab-content -->
    </div><!-- /col-md-9 -->
  </div><!-- /row -->

</div>
<?php renderFooter(); ?>

<!-- ══ Modal: Chat Simulator ════════════════════════════════ -->
<?php if (!$isNew): ?>
<div class="modal fade" id="modalSimulator" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" style="max-width:520px">
    <div class="modal-content">
      <div class="modal-header border-0 pb-1">
        <div>
          <h5 class="modal-title mb-0"><i class="bx bx-bot text-primary me-2"></i>Probar agente</h5>
          <small class="text-muted">Chatea con el agente como si fueras un cliente. Sin llamada real.</small>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" onclick="clearSimulator()"></button>
      </div>
      <div class="modal-body pt-2">
        <div id="sim-messages">
          <div class="sim-msg agent">¡Hola! Soy tu agente. Escribe algo para empezar la conversación.</div>
        </div>
        <div class="d-flex gap-2">
          <input type="text" class="form-control" id="sim-input"
                 placeholder="Escribe un mensaje…" onkeydown="if(event.key==='Enter')sendSim()">
          <button class="btn btn-primary px-3" onclick="sendSim()" id="sim-btn">
            <i class="bx bx-send"></i>
          </button>
        </div>
        <div class="d-flex justify-content-between mt-2">
          <small class="text-muted" id="sim-status"></small>
          <button class="btn btn-link btn-sm text-muted p-0" onclick="clearSimulator()">
            <i class="bx bx-trash me-1"></i>Limpiar
          </button>
        </div>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ══ Modal: Plantillas ═════════════════════════════════════ -->
<div class="modal fade" id="modalPerfiles" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header border-0 pb-1">
        <div>
          <h5 class="modal-title mb-0"><i class="bx bx-layer text-primary me-2"></i>Plantillas de negocio</h5>
          <small class="text-muted">Elige una para pre-configurar el agente. Podrás personalizar todo después.</small>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body pt-2">
        <div class="row g-3" id="perfiles-grid"></div>
      </div>
    </div>
  </div>
</div>

<!-- ─── JS ──────────────────────────────────────────────────── -->
<script>
const AGENT_ID   = <?= json_encode($agentId) ?>;
const IS_NEW     = <?= $isNew ? 'true' : 'false' ?>;
const WEBHOOK_URL = <?= json_encode($webhookUrl) ?>;

let specialties = <?= json_encode(array_values($cfg['specialties'] ?? [])) ?>;
let faqs        = <?= json_encode(array_values($cfg['faqs']        ?? [])) ?>;

// Industria heredada del tenant (no editable por agente)
const TENANT_INDUSTRY = <?= json_encode(tenantIndustry()) ?>;

// ── Saludo dinámico: se regenera con nombre+negocio mientras no se edite a mano ──
let greetingManual = false;
function makeGreeting(name, business) {
  const n = name || 'tu asistente';
  const b = business || 'nuestro negocio';
  return `Hola, gracias por comunicarte con ${b}. Soy ${n}, ¿en qué te puedo ayudar?`;
}
function syncGreeting() {
  if (greetingManual) return; // el usuario ya lo personalizó → no lo pisamos
  const name     = document.getElementById('f-name')?.value.trim() || '';
  const business = document.getElementById('f-business')?.value.trim() || '';
  document.getElementById('f-greeting').value = makeGreeting(name, business);
  if (typeof refreshPreview === 'function') refreshPreview();
}
document.addEventListener('DOMContentLoaded', () => {
  const g = document.getElementById('f-greeting');
  // Si ya hay un saludo guardado distinto del autogenerado, respétalo
  if (g && g.value.trim()) {
    const auto = makeGreeting(
      document.getElementById('f-name')?.value.trim() || '',
      document.getElementById('f-business')?.value.trim() || ''
    );
    if (g.value.trim() !== auto) greetingManual = true;
  }
  ['f-name','f-business'].forEach(id =>
    document.getElementById(id)?.addEventListener('input', syncGreeting));
  // Si el usuario edita el saludo a mano, deja de autogenerarse
  g?.addEventListener('input', () => { greetingManual = true; });
});

// ── Tipos de agente / alcance por canal ───────────────────────
const CHANNEL_TYPES = {
  voice: {
    icon: '📞', name: 'Agente de Voz', color: 'primary',
    tagline: 'Contesta llamadas telefónicas con voz natural.',
    can: ['Responde llamadas 24/7', 'Agenda y confirma citas',
          'Resuelve precios y dudas', 'Captura datos del cliente'],
    needs: 'Requiere un número de teléfono Twilio',
    needsIcon: 'bx-phone', needsCls: 'text-warning',
  },
  whatsapp: {
    icon: '💬', name: 'Agente de WhatsApp', color: 'success',
    tagline: 'Atiende y vende por chat de WhatsApp Business.',
    can: ['Responde mensajes al instante', 'Agenda citas y manda recordatorios',
          'Comparte catálogo y toma pedidos', 'Cobra con link de pago'],
    needs: 'Requiere WhatsApp Business (Meta) aprobado',
    needsIcon: 'bxl-whatsapp', needsCls: 'text-warning',
  },
  webchat: {
    icon: '🌐', name: 'Chat Web', color: 'info',
    tagline: 'Widget de chat embebido en tu sitio web.',
    can: ['Atiende visitantes de tu web', 'Captura leads con su contacto',
          'Responde preguntas frecuentes', 'Deriva a un humano si hace falta'],
    needs: 'Sin requisitos — copia y pega el código',
    needsIcon: 'bx-code-alt', needsCls: 'text-success',
  },
};

// SMS no se promociona en el selector pero se soporta al editar.
function channelMeta(ch) {
  return CHANNEL_TYPES[ch] || {
    icon: '📱', name: 'SMS', color: 'secondary',
    tagline: 'Mensajes de texto SMS.',
    can: ['Responde SMS', 'Confirma citas por texto'],
    needs: 'Requiere número Twilio con SMS habilitado',
    needsIcon: 'bx-message', needsCls: 'text-warning',
  };
}

// Paso 0 — tarjetas de tipo (solo agente nuevo)
function renderTypeCards() {
  const wrap = document.getElementById('type-cards');
  if (!wrap) return;
  wrap.innerHTML = ['voice', 'whatsapp', 'webchat'].map(ch => {
    const m = CHANNEL_TYPES[ch];
    return `
      <div class="col-md-4">
        <div class="type-card" onclick="pickAgentType('${ch}')">
          <div class="tc-icon">${m.icon}</div>
          <div class="tc-name">${m.name}</div>
          <div class="tc-tagline">${m.tagline}</div>
          <ul class="tc-can">
            ${m.can.map(c => `<li><i class="bx bx-check"></i><span>${c}</span></li>`).join('')}
          </ul>
          <div class="tc-needs ${m.needsCls}"><i class="bx ${m.needsIcon} me-1"></i>${m.needs}</div>
        </div>
      </div>`;
  }).join('');
}

// Elige el tipo → fija canal, oculta picker, revela editor
function pickAgentType(ch) {
  const sel = document.getElementById('f-channel');
  if (sel) sel.value = ch;
  document.getElementById('type-picker')?.remove();
  const body = document.getElementById('editor-body');
  if (body) body.style.display = '';
  updateChannelUI();
  setTimeout(() => document.getElementById('f-name')?.focus(), 50);
}

// Banner de alcance + resalta la sección de canal relevante
function updateChannelUI() {
  const ch = document.getElementById('f-channel')?.value || 'voice';
  const m  = channelMeta(ch);
  const scope = document.getElementById('channel-scope');
  if (scope) {
    scope.className = `alert alert-${m.color} d-flex gap-3 align-items-start mb-4`;
    scope.innerHTML = `
      <div style="font-size:1.8rem;line-height:1">${m.icon}</div>
      <div>
        <div class="fw-semibold mb-2">${m.name} — ${m.tagline}</div>
        <div class="d-flex flex-wrap mb-1">
          ${m.can.map(c => `<span class="me-3 mb-1" style="font-size:.82rem"><i class="bx bx-check text-success"></i> ${c}</span>`).join('')}
        </div>
        <div class="${m.needsCls}" style="font-size:.8rem"><i class="bx ${m.needsIcon} me-1"></i>${m.needs}</div>
      </div>`;
  }
  const map = { voice: 'sect-voice', whatsapp: 'sect-whatsapp', webchat: 'sect-webchat' };
  ['sect-voice', 'sect-whatsapp', 'sect-webchat'].forEach(id => {
    const el = document.getElementById(id);
    if (!el) return;
    const active = map[ch] === id;
    el.classList.toggle('dim', !active);
    el.querySelector('.chan-na')?.classList.toggle('d-none', active);
  });
}

document.addEventListener('DOMContentLoaded', () => {
  if (IS_NEW) renderTypeCards();
  updateChannelUI();
});

// ── Schedule state ────────────────────────────────────────────
const DAYS = [
  { key:'mon', label:'Lunes'     },
  { key:'tue', label:'Martes'    },
  { key:'wed', label:'Miércoles' },
  { key:'thu', label:'Jueves'    },
  { key:'fri', label:'Viernes'   },
  { key:'sat', label:'Sábado'    },
  { key:'sun', label:'Domingo'   },
];

const DEFAULT_HOURS = { enabled: true, open: '09:00', close: '18:00' };
const WEEKEND_OFF   = { enabled: false, open: '09:00', close: '14:00' };

let scheduleData = <?= json_encode($schedHz ?: (object)[]) ?>;

// Ensure all days have defaults
DAYS.forEach(d => {
  if (!scheduleData[d.key]) {
    scheduleData[d.key] = d.key === 'sat' || d.key === 'sun' ? { ...WEEKEND_OFF } : { ...DEFAULT_HOURS };
  }
});

// ── Voice cards ───────────────────────────────────────────────
// Voces mexicanas reales de Cartesia, verificadas (género/carácter correctos).
const VOICES = [
  {
    id:    'b4b8e2af-6139-466e-a93a-30c20d2e1fc5',
    key:   'female_warm',
    name:  'Sofía',
    avatar:'👩',
    desc:  'Femenina · cálida (MX)',
    badge: 'Recomendada',
    badgeCls: 'bg-label-success',
  },
  {
    id:    '3797b3c0-ab71-40dc-bfa0-a8c6ff9c1e8b',
    key:   'female_natural',
    name:  'Andrea',
    avatar:'👩‍💼',
    desc:  'Femenina · natural (MX)',
    badge: null,
  },
  {
    id:    '15d0c2e2-8d29-44c3-be23-d585d5f154a1',
    key:   'male_formal',
    name:  'Carlos',
    avatar:'👨‍💼',
    desc:  'Masculino · formal (MX)',
    badge: null,
  },
  {
    id:    '3597a26f-80ef-4bd5-8101-9699bc764917',
    key:   'female_neutral',
    name:  'Valeria',
    avatar:'👩‍💻',
    desc:  'Femenina · neutra (MX)',
    badge: null,
  },
];

function renderVoiceCards() {
  const selectedId = document.getElementById('f-voice-id').value;
  const container  = document.getElementById('voice-cards-container');
  container.innerHTML = VOICES.map(v => `
    <div class="col-sm-6 col-md-3">
      <div class="voice-card ${v.id === selectedId ? 'selected' : ''}"
           id="vc-${v.key}" onclick="selectVoice('${v.id}', '${v.key}')">
        <button class="btn btn-outline-secondary btn-preview" type="button"
                onclick="event.stopPropagation(); previewVoice('${v.id}', '${v.key}', this)"
                title="Escuchar muestra">
          <i class="bx bx-play" id="picon-${v.key}"></i>
        </button>
        <div class="voice-avatar">${v.avatar}</div>
        <div class="voice-name">${v.name}</div>
        <div class="voice-desc">${v.desc}</div>
        ${v.badge ? `<span class="badge ${v.badgeCls} mt-2">${v.badge}</span>` : ''}
      </div>
    </div>
  `).join('');
}

function selectVoice(voiceId, key) {
  document.getElementById('f-voice-id').value = voiceId;
  document.querySelectorAll('.voice-card').forEach(c => c.classList.remove('selected'));
  document.getElementById('vc-' + key)?.classList.add('selected');
}

let previewPlaying = null;

async function previewVoice(voiceId, key, btn) {
  if (previewPlaying === key) {
    const audio = document.getElementById('voicePreviewAudio');
    audio.pause();
    previewPlaying = null;
    document.querySelectorAll('.bx-stop').forEach(i => { i.className = 'bx bx-play'; });
    return;
  }

  const icon = document.getElementById('picon-' + key);
  icon.className = 'bx bx-loader-alt bx-spin';

  try {
    const res = await fetch('/api/agent-voice-preview.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ voice_id: voiceId }),
    });
    if (!res.ok) throw new Error('Error al sintetizar');

    const blob = await res.blob();
    const url  = URL.createObjectURL(blob);
    const audio = document.getElementById('voicePreviewAudio');
    audio.src = url;

    document.querySelectorAll('[id^="picon-"]').forEach(i => { i.className = 'bx bx-play'; });
    icon.className = 'bx bx-stop';
    previewPlaying = key;

    audio.play();
    audio.onended = () => {
      icon.className = 'bx bx-play';
      previewPlaying = null;
    };
  } catch (err) {
    icon.className = 'bx bx-play';
    console.error('Voice preview error:', err);
  }
}

// ── Schedule builder ──────────────────────────────────────────
function renderSchedule() {
  const el = document.getElementById('schedule-builder');
  el.innerHTML = DAYS.map(d => {
    const h = scheduleData[d.key] || DEFAULT_HOURS;
    return `
      <div class="sched-row mb-2">
        <div>
          <input type="checkbox" class="form-check-input"
                 id="sched-${d.key}-en"
                 ${h.enabled ? 'checked' : ''}
                 onchange="scheduleData['${d.key}'].enabled = this.checked; toggleSchedRow('${d.key}')">
        </div>
        <label class="sched-day-label" for="sched-${d.key}-en">${d.label}</label>
        <div id="sched-${d.key}-times" class="d-flex gap-2 align-items-center" ${h.enabled ? '' : 'style="opacity:.35;pointer-events:none"'}>
          <input type="time" class="form-control form-control-sm" style="width:7rem"
                 value="${h.open}" id="sched-${d.key}-open"
                 onchange="scheduleData['${d.key}'].open = this.value">
          <span class="text-muted small">a</span>
          <input type="time" class="form-control form-control-sm" style="width:7rem"
                 value="${h.close}" id="sched-${d.key}-close"
                 onchange="scheduleData['${d.key}'].close = this.value">
        </div>
        <span class="badge ${h.enabled ? 'bg-label-success' : 'bg-label-secondary'} align-self-center" id="sched-${d.key}-badge">
          ${h.enabled ? 'Abierto' : 'Cerrado'}
        </span>
      </div>`;
  }).join('');
}

function toggleSchedRow(key) {
  const enabled = scheduleData[key].enabled;
  const times   = document.getElementById('sched-' + key + '-times');
  const badge   = document.getElementById('sched-' + key + '-badge');
  times.style.opacity = enabled ? '1' : '.35';
  times.style.pointerEvents = enabled ? '' : 'none';
  badge.textContent = enabled ? 'Abierto' : 'Cerrado';
  badge.className = 'badge ' + (enabled ? 'bg-label-success' : 'bg-label-secondary') + ' align-self-center';
}

function collectSchedule() {
  return {
    timezone: document.getElementById('f-timezone').value,
    hours: scheduleData,
    outsideHoursMsg: document.getElementById('f-outside-msg').value.trim(),
  };
}

// ── KB assign ─────────────────────────────────────────────────
async function toggleKbAssign(checkbox) {
  const docId  = checkbox.dataset.docId;
  const assign = checkbox.checked ? AGENT_ID : null;

  try {
    const res  = await fetch('/api/kb-assign.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ doc_id: docId, agent_id: assign }),
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Error');

    // Update badge
    const count = document.querySelectorAll('.kb-assign-toggle:checked').length;
    const badge = document.getElementById('badge-kb');
    if (badge) badge.textContent = count;
  } catch (err) {
    checkbox.checked = !checkbox.checked; // revert
    console.error('KB assign error:', err);
    showAlert('Error al asignar documento: ' + err.message, 'danger');
  }
}

// ── is_active toggle ──────────────────────────────────────────
async function toggleActive(isActive) {
  try {
    const res  = await fetch('/api/agent-save.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id: AGENT_ID, is_active: isActive }),
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Error');
    document.getElementById('activeLabel').textContent = isActive ? 'Activo' : 'Inactivo';
  } catch (err) {
    // revert
    document.getElementById('toggleActive').checked = !isActive;
    showAlert('Error: ' + err.message, 'danger');
  }
}

// ── Webhook copy ──────────────────────────────────────────────
function copyWebhook() {
  navigator.clipboard.writeText(WEBHOOK_URL).then(() => {
    const icon = document.getElementById('copyIcon');
    icon.className = 'bx bx-check text-success';
    setTimeout(() => { icon.className = 'bx bx-copy'; }, 2000);
  });
}

// ══════════════════════════════════════════════════════════════
//  ESPECIALIDADES
// ══════════════════════════════════════════════════════════════
function renderSpecialties() {
  const list  = document.getElementById('specialties-list');
  const empty = document.getElementById('specialties-empty');
  list.innerHTML = '';
  specialties.forEach((s, i) => {
    list.insertAdjacentHTML('beforeend', `
      <div class="item-card" data-index="${i}">
        <button class="btn btn-sm btn-icon btn-outline-danger btn-remove"
                onclick="removeSpecialty(${i})" title="Eliminar">
          <i class="bx bx-x"></i>
        </button>
        <div class="row g-2">
          <div class="col-md-4">
            <label class="form-label form-label-sm">Servicio / Especialidad</label>
            <input type="text" class="form-control form-control-sm"
                   value="${e(s.name)}" placeholder="Ej: Ortodoncia"
                   oninput="specialties[${i}].name = this.value; updateBadges()">
          </div>
          <div class="col-md-4">
            <label class="form-label form-label-sm">Especialista (opcional)</label>
            <input type="text" class="form-control form-control-sm"
                   value="${e(s.specialist||'')}" placeholder="Ej: Dr. García"
                   oninput="specialties[${i}].specialist = this.value">
          </div>
          <div class="col-md-4">
            <label class="form-label form-label-sm">Descripción breve</label>
            <input type="text" class="form-control form-control-sm"
                   value="${e(s.description||'')}" placeholder="Ej: brackets, alineadores"
                   oninput="specialties[${i}].description = this.value">
          </div>
        </div>
      </div>`);
  });
  empty.classList.toggle('d-none', specialties.length > 0);
}
function addSpecialty()     { specialties.push({ name:'', specialist:'', description:'' }); renderSpecialties(); updateBadges(); }
function removeSpecialty(i) { specialties.splice(i, 1); renderSpecialties(); updateBadges(); }

// ══════════════════════════════════════════════════════════════
//  FAQs
// ══════════════════════════════════════════════════════════════
function renderFaqs() {
  const list  = document.getElementById('faqs-list');
  const empty = document.getElementById('faqs-empty');
  list.innerHTML = '';
  faqs.forEach((faq, i) => {
    list.insertAdjacentHTML('beforeend', `
      <div class="item-card" data-index="${i}">
        <button class="btn btn-sm btn-icon btn-outline-danger btn-remove"
                onclick="removeFaq(${i})" title="Eliminar">
          <i class="bx bx-x"></i>
        </button>
        <div class="row g-2">
          <div class="col-12">
            <label class="form-label form-label-sm">Pregunta</label>
            <input type="text" class="form-control form-control-sm"
                   value="${e(faq.q)}" placeholder="Ej: ¿Cuánto cuesta una limpieza dental?"
                   oninput="faqs[${i}].q = this.value; updateBadges()">
          </div>
          <div class="col-12">
            <label class="form-label form-label-sm">Respuesta exacta</label>
            <textarea class="form-control form-control-sm" rows="2"
                      placeholder="Ej: Nuestras limpiezas tienen un costo de $500 e incluyen..."
                      oninput="faqs[${i}].a = this.value">${e(faq.a)}</textarea>
          </div>
        </div>
      </div>`);
  });
  empty.classList.toggle('d-none', faqs.length > 0);
}
function addFaq()     { faqs.push({ q:'', a:'' }); renderFaqs(); updateBadges(); }
function removeFaq(i) { faqs.splice(i, 1); renderFaqs(); updateBadges(); }

function updateBadges() {
  document.getElementById('badge-specialties').textContent = specialties.length;
  document.getElementById('badge-faqs').textContent        = faqs.length;
}

// ══════════════════════════════════════════════════════════════
//  VISTA PREVIA
// ══════════════════════════════════════════════════════════════
function refreshPreview() {
  const name      = document.getElementById('f-name').value.trim();
  const business  = document.getElementById('f-business').value.trim();
  const tone      = document.getElementById('f-tone').value;
  const objective = document.getElementById('f-objective').value.trim();
  const greeting  = document.getElementById('f-greeting').value.trim();
  const extra     = document.getElementById('f-extra').value.trim();
  const toneMap   = { professional:'profesional y amable', friendly:'cálido, cercano y empático', formal:'formal y cortés', casual:'relajado y amigable' };

  let prompt = `Eres ${name||'[Nombre]'}, el asistente virtual de ${business||'[Negocio]'}. Tu trato debe ser ${toneMap[tone]||'amable'}.`;
  if (objective) prompt += `\n\nOBJETIVO PRINCIPAL: ${objective}`;
  if (greeting)  prompt += `\n\nSALUDO: Cuando el usuario inicie la llamada, saluda diciendo exactamente: "${greeting}"`;

  const activeSpecs = specialties.filter(s => s.name.trim());
  if (activeSpecs.length) {
    prompt += '\n\nESPECIALIDADES DISPONIBLES:';
    activeSpecs.forEach(s => {
      prompt += `\n• ${s.name}`;
      if (s.specialist)  prompt += ` — atendido por ${s.specialist}`;
      if (s.description) prompt += ` (${s.description})`;
    });
    prompt += '\n\nCuando el usuario quiera agendar una cita, primero pregunta qué tipo de servicio necesita, luego consulta disponibilidad con check_availability.';
  }

  const activeFaqs = faqs.filter(f => f.q.trim());
  if (activeFaqs.length) {
    prompt += '\n\nINFORMACIÓN FRECUENTE (responde con estos datos exactos):';
    activeFaqs.forEach(f => { prompt += `\nP: ${f.q}\nR: ${f.a}\n`; });
  }

  if (extra) prompt += `\n\nINSTRUCCIONES ADICIONALES:\n${extra}`;

  document.getElementById('prompt-preview').textContent = prompt;
}

// ══════════════════════════════════════════════════════════════
//  CHAT SIMULATOR
// ══════════════════════════════════════════════════════════════
let simHistory = [];

function appendSimMsg(role, text) {
  const box = document.getElementById('sim-messages');
  const div = document.createElement('div');
  div.className = 'sim-msg ' + role;
  div.textContent = text;
  box.appendChild(div);
  box.scrollTop = box.scrollHeight;
}

async function sendSim() {
  const input   = document.getElementById('sim-input');
  const message = input.value.trim();
  if (!message) return;

  appendSimMsg('user', message);
  input.value = '';
  simHistory.push({ role: 'user', content: message });

  const btn = document.getElementById('sim-btn');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
  document.getElementById('sim-status').textContent = 'El agente está pensando…';

  try {
    const res  = await fetch('/api/agent-simulate.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ agent_id: AGENT_ID, message, history: simHistory.slice(0, -1) }),
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Error');

    appendSimMsg('agent', data.response);
    simHistory.push({ role: 'assistant', content: data.response });
    document.getElementById('sim-status').textContent = `Modelo: ${data.model || 'AI'} · ${data.tokensUsed || '?'} tokens`;
  } catch (err) {
    appendSimMsg('agent', '⚠️ Error: ' + err.message);
    document.getElementById('sim-status').textContent = '';
  } finally {
    btn.disabled = false;
    btn.innerHTML = '<i class="bx bx-send"></i>';
  }
}

function clearSimulator() {
  simHistory = [];
  const box = document.getElementById('sim-messages');
  if (box) {
    box.innerHTML = '<div class="sim-msg agent">¡Hola! Soy tu agente. Escribe algo para empezar la conversación.</div>';
  }
  const input = document.getElementById('sim-input');
  if (input) input.value = '';
  const status = document.getElementById('sim-status');
  if (status) status.textContent = '';
}

// ══════════════════════════════════════════════════════════════
//  GUARDAR
// ══════════════════════════════════════════════════════════════
function collectConfig() {
  // La industria se hereda del tenant (definida al crear la cuenta), no se edita por agente.
  const industry = TENANT_INDUSTRY;
  return {
    businessName:      document.getElementById('f-business').value.trim(),
    tone:              document.getElementById('f-tone').value,
    industry:          industry || undefined,
    enableTriage:      industry === 'dental',
    objective:         document.getElementById('f-objective').value.trim(),
    greeting:          document.getElementById('f-greeting').value.trim(),
    specialties:       specialties.filter(s => s.name.trim()),
    faqs:              faqs.filter(f => f.q.trim() && f.a.trim()),
    extraInstructions: document.getElementById('f-extra').value.trim(),
    schedule:          collectSchedule(),
  };
}

async function saveAgent() {
  const name = document.getElementById('f-name').value.trim();
  if (!name) { showAlert('El nombre del agente es requerido.', 'danger'); return; }

  const cfg = collectConfig();
  if (!cfg.businessName) { showAlert('El nombre del negocio es requerido.', 'danger'); return; }

  refreshPreview();
  const systemPrompt = document.getElementById('prompt-preview').textContent;

  const payload = {
    id:              AGENT_ID || undefined,
    name,
    channel:         document.getElementById('f-channel').value,
    llm_model:       document.getElementById('f-model').value,
    language:        document.getElementById('f-language').value,
    voice_id:        document.getElementById('f-voice-id').value || undefined,
    phone_number:    document.getElementById('f-phone').value.trim() || undefined,
    whatsapp_number: document.getElementById('f-whatsapp').value.trim() || undefined,
    system_prompt:   systemPrompt,
    config:          cfg,
  };

  document.getElementById('saveText').classList.add('d-none');
  document.getElementById('saveSpinner').classList.remove('d-none');
  document.getElementById('btnSave').disabled = true;

  try {
    const res  = await fetch('/api/agent-save.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Error al guardar');
    showAlert('¡Agente guardado correctamente!', 'success');
    if (IS_NEW && data.id) {
      setTimeout(() => { window.location.href = '/pages/agent-editor.php?id=' + data.id; }, 800);
    }
  } catch (err) {
    showAlert(err.message, 'danger');
  } finally {
    document.getElementById('saveText').classList.remove('d-none');
    document.getElementById('saveSpinner').classList.add('d-none');
    document.getElementById('btnSave').disabled = false;
  }
}

// ── Helpers ───────────────────────────────────────────────────
function e(str) {
  return String(str ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
    .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}
function showAlert(msg, type) {
  const el = document.getElementById('saveAlert');
  el.className = 'alert alert-' + type + ' mt-3';
  el.textContent = msg;
  el.classList.remove('d-none');
  setTimeout(() => el.classList.add('d-none'), 4500);
}

// ══════════════════════════════════════════════════════════════
//  PLANTILLAS
// ══════════════════════════════════════════════════════════════
const PERFILES = [
  { id:'clinica',     icon:'🏥', nombre:'Clínica / Consultorio',   desc:'Dentistas, médicos, psicólogos', color:'primary',
    cfg: { tone:'professional', objective:'Agendar citas con los especialistas y resolver dudas sobre servicios y precios', greeting:'Buenas tardes, habla [Nombre] de [Clínica]. ¿En qué le puedo ayudar?',
      specialties:[{name:'Consulta general',specialist:'',description:'revisión y diagnóstico'},{name:'Limpieza dental',specialist:'',description:'profilaxis y blanqueamiento'},{name:'Ortodoncia',specialist:'',description:'brackets y alineadores'},{name:'Urgencias',specialist:'',description:'atención el mismo día'}],
      faqs:[{q:'¿Cuáles son sus horarios?',a:'Atendemos de lunes a viernes de 9:00 a 19:00 horas y sábados de 9:00 a 14:00.'},{q:'¿Aceptan seguro médico?',a:'Sí, trabajamos con los principales seguros. Confirma el tuyo al momento de tu cita.'},{q:'¿Cómo cancelo una cita?',a:'Puedes cancelar llamando con al menos 24 horas de anticipación sin costo.'},{q:'¿Atienden niños?',a:'Sí, contamos con atención pediátrica para pacientes desde 3 años.'}] } },
  { id:'inmobiliaria',icon:'🏠', nombre:'Inmobiliaria',            desc:'Venta, renta y administración', color:'success',
    cfg: { tone:'professional', objective:'Captar interesados, agendar visitas y resolver dudas sobre disponibilidad y precios', greeting:'Buen día, habla [Nombre] de [Inmobiliaria]. ¿Busca comprar, vender o rentar?',
      specialties:[{name:'Compra de inmueble',specialist:'',description:'casas, departamentos, terrenos'},{name:'Renta de inmueble',specialist:'',description:'residencial y comercial'},{name:'Venta de mi propiedad',specialist:'',description:'valuación y promoción'},{name:'Locales comerciales',specialist:'',description:'oficinas y naves'}],
      faqs:[{q:'¿Cobran comisión al comprador?',a:'No, nuestra comisión la paga el vendedor. Para el comprador el servicio es sin costo.'},{q:'¿Qué documentos necesito?',a:'Identificación oficial y comprobante de ingresos. Te orientamos en cada paso.'},{q:'¿Tienen propiedades en preventa?',a:'Sí, con precios preferenciales. ¿Te interesa alguna zona?'},{q:'¿Hacen visitas los fines de semana?',a:'Sí, agendamos de lunes a domingo con previa cita.'}] } },
  { id:'taller',      icon:'🔧', nombre:'Taller / Automotriz',     desc:'Mecánica, eléctrico, llantas', color:'warning',
    cfg: { tone:'friendly', objective:'Agendar servicios automotrices, dar información de precios y tiempos de entrega', greeting:'Hola, bienvenido a [Taller]. ¿En qué le ayudamos con su vehículo?',
      specialties:[{name:'Mantenimiento',specialist:'',description:'afinación, aceite, frenos'},{name:'Diagnóstico computarizado',specialist:'',description:'scanner y fallas eléctricas'},{name:'Hojalatería y pintura',specialist:'',description:'golpes, rayones, restauración'},{name:'Llantas y rines',specialist:'',description:'venta, balanceo y alineación'}],
      faqs:[{q:'¿Cuánto tarda una afinación?',a:'2 a 3 horas. Con cita aseguramos que salgas el mismo día.'},{q:'¿Tienen servicio a domicilio?',a:'Sí, asistencia vial en un radio de 15 km.'},{q:'¿Dan garantía?',a:'Sí, 3 meses o 5,000 km en todos nuestros trabajos.'},{q:'¿Trabajan todas las marcas?',a:'Sí, atendemos todas las marcas nacionales e importadas.'}] } },
  { id:'estetica',    icon:'💅', nombre:'Estética / Spa',          desc:'Belleza, uñas, spa, depilación', color:'info',
    cfg: { tone:'friendly', objective:'Agendar citas de belleza, informar sobre servicios y promociones', greeting:'¡Hola! Bienvenida a [Estética], soy [Nombre]. ¿Cómo puedo ayudarte?',
      specialties:[{name:'Corte y peinado',specialist:'',description:'corte, tinte, tratamientos'},{name:'Uñas',specialist:'',description:'manicure, pedicure, gel, acrílico'},{name:'Depilación',specialist:'',description:'cera, láser, hilo'},{name:'Masajes y spa',specialist:'',description:'relajación, descontracturante'}],
      faqs:[{q:'¿Necesito cita previa?',a:'Recomendamos agendar, aunque también atendemos sin cita según disponibilidad.'},{q:'¿Tienen estacionamiento?',a:'Sí, gratuito para clientes.'},{q:'¿Tienen promociones?',a:'Sí, martes 20% en coloración y miércoles en uñas.'},{q:'¿Puedo ir con mis hijos?',a:'Claro, también ofrecemos corte para niños.'}] } },
  { id:'restaurante', icon:'🍽️',nombre:'Restaurante / Cafetería',  desc:'Reservaciones, menú, delivery', color:'danger',
    cfg: { tone:'friendly', objective:'Tomar reservaciones, informar sobre el menú y opciones de entrega a domicilio', greeting:'¡Buenas tardes! Habla [Nombre] de [Restaurante]. ¿Desea hacer una reservación?',
      specialties:[{name:'Reservación de mesa',specialist:'',description:'para comer en el restaurante'},{name:'Orden para llevar',specialist:'',description:'pick up sin espera'},{name:'Entrega a domicilio',specialist:'',description:'delivery en la zona'},{name:'Eventos y grupos',specialist:'',description:'cumpleaños, empresariales, bodas'}],
      faqs:[{q:'¿Cuáles son sus horarios?',a:'Martes a domingo de 13:00 a 22:00. Lunes cerrado.'},{q:'¿Hacen entregas a domicilio?',a:'Sí, pedido mínimo $250 en radio 5 km. Tiempo estimado 40-60 min.'},{q:'¿Tienen opciones vegetarianas?',a:'Sí, y sin gluten. Pide el menú completo al llegar.'},{q:'¿Se puede apartar lugar sin pagar?',a:'Sí, reservamos sin depósito hasta 15 min de retraso.'}] } },
  { id:'servicios',   icon:'💼', nombre:'Servicios Profesionales', desc:'Abogados, contadores, consultores', color:'secondary',
    cfg: { tone:'formal', objective:'Agendar consultas iniciales y capturar datos del prospecto', greeting:'Buenos días, le comunica [Nombre] de [Despacho]. ¿En qué le puedo orientar?',
      specialties:[{name:'Consulta inicial',specialist:'',description:'diagnóstico gratuito 30 min'},{name:'Asesoría fiscal',specialist:'',description:'declaraciones, contabilidad'},{name:'Asesoría legal',specialist:'',description:'contratos, amparo, civil, laboral'},{name:'Consultoría empresarial',specialist:'',description:'estrategia, procesos, finanzas'}],
      faqs:[{q:'¿Cuánto cuesta la primera consulta?',a:'La consulta de diagnóstico inicial es gratuita (30 minutos).'},{q:'¿Manejan confidencialidad?',a:'Absolutamente, protegida por secreto profesional.'},{q:'¿Trabajan con empresas y personas?',a:'Sí, personas físicas y empresas de todos los tamaños.'},{q:'¿Tienen oficinas o es en línea?',a:'Contamos con oficina física y consultas en línea por videollamada.'}] } },
  { id:'tienda',      icon:'🛍️',nombre:'Tienda / Retail',         desc:'Catálogo, disponibilidad, pedidos', color:'primary',
    cfg: { tone:'friendly', objective:'Responder dudas sobre productos, disponibilidad y envíos; capturar datos para seguimiento', greeting:'¡Hola! Bienvenido a [Tienda], soy [Nombre]. ¿En qué le puedo ayudar?',
      specialties:[{name:'Consulta de disponibilidad',specialist:'',description:'saber si hay stock'},{name:'Cotización',specialist:'',description:'precios y descuentos por volumen'},{name:'Pedido y apartado',specialist:'',description:'guardar un producto'},{name:'Garantía y cambios',specialist:'',description:'devoluciones y posventa'}],
      faqs:[{q:'¿Hacen envíos?',a:'Sí, a toda la república. 3 a 5 días hábiles.'},{q:'¿Cuáles son sus horarios?',a:'Lunes a sábado 9:00-20:00, domingos 10:00-16:00.'},{q:'¿Aceptan devoluciones?',a:'Sí, 30 días con ticket de compra.'},{q:'¿Tienen catálogo en línea?',a:'Sí, en nuestra página web y redes sociales.'}] } },
  { id:'gimnasio',    icon:'🏋️',nombre:'Gimnasio / Fitness',      desc:'Membresías, clases, entrenadores', color:'success',
    cfg: { tone:'friendly', objective:'Informar sobre membresías y clases, agendar pruebas gratuitas y capturar prospectos', greeting:'¡Hola! Soy [Nombre] de [Gimnasio]. ¿Quieres info sobre membresías o clases?',
      specialties:[{name:'Membresía general',specialist:'',description:'acceso a todas las instalaciones'},{name:'Clases grupales',specialist:'',description:'spinning, zumba, yoga, box'},{name:'Entrenamiento personal',specialist:'',description:'plan individualizado con coach'},{name:'Clase de prueba gratuita',specialist:'',description:'sin compromiso'}],
      faqs:[{q:'¿Cuánto cuesta la membresía?',a:'Desde $699 mensuales con acceso ilimitado.'},{q:'¿Puedo ir a una clase de prueba?',a:'Sí, la primera clase es gratis y sin compromiso. ¿Cuándo quieres venir?'},{q:'¿Tienen regaderas y lockers?',a:'Sí, vestidores completos con regaderas y lockers.'},{q:'¿Qué horarios tienen las clases?',a:'Matutino 6:00-9:00 y vespertino 17:00-20:00, lunes a sábado.'}] } },
];

function renderPerfiles() {
  document.getElementById('perfiles-grid').innerHTML = PERFILES.map(p => `
    <div class="col-sm-6 col-lg-3">
      <div class="card h-100 border cursor-pointer"
           onclick="cargarPerfil('${p.id}')"
           style="cursor:pointer;transition:box-shadow .15s,transform .15s"
           onmouseenter="this.style.boxShadow='0 4px 20px rgba(105,108,255,.2)';this.style.transform='translateY(-2px)'"
           onmouseleave="this.style.boxShadow='';this.style.transform=''">
        <div class="card-body text-center py-4">
          <div style="font-size:2.5rem;line-height:1;margin-bottom:.75rem">${p.icon}</div>
          <h6 class="mb-1">${p.nombre}</h6>
          <small class="text-muted">${p.desc}</small>
        </div>
      </div>
    </div>`).join('');
}

function cargarPerfil(id) {
  const perfil = PERFILES.find(p => p.id === id);
  if (!perfil) return;
  const cfg = perfil.cfg;
  if (cfg.tone)      document.getElementById('f-tone').value      = cfg.tone;
  if (cfg.objective) document.getElementById('f-objective').value = cfg.objective;
  if (cfg.greeting)  document.getElementById('f-greeting').value  = cfg.greeting;
  specialties = cfg.specialties ? [...cfg.specialties] : [];
  faqs        = cfg.faqs        ? [...cfg.faqs]        : [];
  renderSpecialties();
  renderFaqs();
  updateBadges();
  bootstrap.Modal.getInstance(document.getElementById('modalPerfiles'))?.hide();
  document.querySelector('[data-bs-target="#pane-identity"]').click();
  showAlert(`Plantilla "${perfil.nombre}" cargada. Personaliza los campos y guarda.`, 'success');
}

// ── Init ──────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  renderSpecialties();
  renderFaqs();
  renderVoiceCards();
  renderSchedule();
  renderPerfiles();
});
</script>
