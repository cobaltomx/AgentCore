<?php
/**
 * Onboarding Wizard — nuevos tenants
 * Pasos: Negocio/Industria → Agente → Teléfono → Base de conocimiento → ¡Listo!
 */
require_once __DIR__ . '/includes/config.php';

requireAuth();

if (!isAdmin()) { header('Location: /index.php'); exit; }

$settings = tenantSettings();
if (!empty($settings['onboarding_completed'])) { header('Location: /index.php'); exit; }

$user     = currentUser();
$tenant   = currentTenant();
$bizName  = tenantBusinessName() ?: ($tenant['name'] ?? '');
$industry = tenantIndustry() ?: '';

$industryDefaults = [
    'clinica'      => ['agentName'=>'Sofía',  'greeting'=>'Hola, bienvenido a {biz}. Soy Sofía, ¿en qué le puedo ayudar?'],
    'dental'       => ['agentName'=>'Andrea', 'greeting'=>'Hola, gracias por llamar a {biz}. Soy Andrea. ¿Le gustaría agendar una cita?'],
    'consultorio'  => ['agentName'=>'Valeria','greeting'=>'Buen día, habla con {biz}. Soy Valeria. ¿En qué le puedo ayudar?'],
    'inmobiliaria' => ['agentName'=>'Carlos', 'greeting'=>'Hola, gracias por contactar a {biz}. Soy Carlos. ¿Busca comprar, vender o rentar?'],
    'taller'       => ['agentName'=>'Carlos', 'greeting'=>'Buenas, habla con {biz}. Soy Carlos. ¿En qué le puedo ayudar con su vehículo?'],
    'restaurante'  => ['agentName'=>'Sofía',  'greeting'=>'Hola, gracias por llamar a {biz}. Soy Sofía. ¿Le gustaría hacer una reserva?'],
    'educacion'    => ['agentName'=>'Valeria','greeting'=>'Bienvenido a {biz}. Soy Valeria. ¿En qué le puedo orientar?'],
    'ecommerce'    => ['agentName'=>'Sofía',  'greeting'=>'Hola, gracias por contactar a {biz}. Soy Sofía. ¿Le ayudo a encontrar algo?'],
    'servicios'    => ['agentName'=>'Carlos', 'greeting'=>'Hola, bienvenido a {biz}. Soy Carlos. ¿En qué le puedo ayudar?'],
    'gym'          => ['agentName'=>'Carlos', 'greeting'=>'Hola, bienvenido a {biz}. Soy Carlos. ¿Le interesa conocer nuestros planes?'],
    ''             => ['agentName'=>'Sofía',  'greeting'=>'Hola, bienvenido a {biz}. ¿En qué le puedo ayudar?'],
];
$defaults = $industryDefaults[$industry] ?? $industryDefaults[''];
// Reemplazar {biz} con el nombre real del negocio en PHP
$defaultGreeting = str_replace('{biz}', $bizName ?: 'nuestro negocio', $defaults['greeting']);

$voices = [
    // Voces mexicanas reales del catálogo de Cartesia, verificadas: el género y
    // carácter SÍ corresponden a la etiqueta.
    ['id'=>'b4b8e2af-6139-466e-a93a-30c20d2e1fc5', 'name'=>'Sofía',   'desc'=>'Femenina · Cálida (MX)',  'emoji'=>'👩'],
    ['id'=>'3797b3c0-ab71-40dc-bfa0-a8c6ff9c1e8b', 'name'=>'Andrea',  'desc'=>'Femenina · Natural (MX)', 'emoji'=>'👩‍💼'],
    ['id'=>'15d0c2e2-8d29-44c3-be23-d585d5f154a1', 'name'=>'Carlos',  'desc'=>'Masculino · Formal (MX)', 'emoji'=>'👨'],
    ['id'=>'3597a26f-80ef-4bd5-8101-9699bc764917', 'name'=>'Valeria', 'desc'=>'Femenina · Neutra (MX)',  'emoji'=>'👩‍🎤'],
];

$industries = [
    ''             => '— Selecciona tu tipo de negocio —',
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
?>
<!DOCTYPE html>
<html lang="es" dir="ltr">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Configuración inicial — <?= e(APP_NAME) ?></title>
  <link rel="icon" href="/assets/img/favicon.ico"/>
  <link href="https://fonts.googleapis.com/css2?family=Public+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;1,400&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/boxicons@2.1.4/css/boxicons.min.css"/>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css"/>
  <style>
    * { box-sizing: border-box; }
    body {
      font-family: 'Public Sans', sans-serif;
      background: #f5f5f9;
      min-height: 100vh;
    }

    /* ── Layout ────────────────────────────────────────────────────── */
    .ob-wrap {
      min-height: 100vh;
      display: flex; flex-direction: column;
      align-items: center; padding: 2.5rem 1rem 3rem;
    }

    /* ── Brand ─────────────────────────────────────────────────────── */
    .ob-brand {
      display: flex; align-items: center; gap: .65rem; margin-bottom: .5rem;
    }
    .ob-brand-icon {
      width: 42px; height: 42px;
      background: linear-gradient(135deg,#696cff,#9155fd);
      border-radius: 12px;
      display: flex; align-items: center; justify-content: center;
      box-shadow: 0 4px 12px rgba(105,108,255,.35);
    }
    .ob-brand-name { font-size: 1.4rem; font-weight: 700; color: #2d2f69; }

    /* ── Steps ─────────────────────────────────────────────────────── */
    .ob-steps {
      display: flex; align-items: center;
      width: 100%; max-width: 640px;
      margin: 1.8rem 0 2rem;
    }
    .ob-step { display: flex; flex-direction: column; align-items: center; gap: 4px; }
    .ob-step-dot {
      width: 38px; height: 38px; border-radius: 50%;
      display: flex; align-items: center; justify-content: center;
      font-size: .85rem; font-weight: 700;
      background: #fff; border: 2px solid #dde1ff; color: #b0b4e0;
      transition: all .3s;
    }
    .ob-step.active .ob-step-dot  { background: #696cff; border-color: #696cff; color: #fff; box-shadow: 0 4px 10px rgba(105,108,255,.4); }
    .ob-step.done   .ob-step-dot  { background: #71dd37; border-color: #71dd37; color: #fff; }
    .ob-step-label {
      font-size: .7rem; font-weight: 500; color: #b0b4e0; white-space: nowrap;
    }
    .ob-step.active .ob-step-label { color: #696cff; font-weight: 600; }
    .ob-step.done   .ob-step-label { color: #71dd37; }
    .ob-line { flex: 1; height: 2px; background: #dde1ff; margin: 0 4px; transition: background .3s; }
    .ob-line.done { background: #71dd37; }

    /* ── Card ──────────────────────────────────────────────────────── */
    .ob-card {
      background: #fff;
      border-radius: 20px;
      box-shadow: 0 8px 32px rgba(105,108,255,.10), 0 1px 4px rgba(0,0,0,.06);
      width: 100%; max-width: 560px;
      padding: 2.5rem 2.5rem 2rem;
    }
    .ob-step-title { font-size: 1.3rem; font-weight: 700; margin-bottom: .35rem; color: #2d2f69; }
    .ob-step-sub   { font-size: .88rem; color: #9295aa; margin-bottom: 1.75rem; }

    /* ── Steps content ─────────────────────────────────────────────── */
    .ob-content { display: none; }
    .ob-content.active { display: block; }

    /* ── Form controls ─────────────────────────────────────────────── */
    .form-label { font-weight: 600; font-size: .875rem; margin-bottom: .4rem; color: #444; }
    .form-control, .form-select {
      border: 1.5px solid #e2e3f3; border-radius: 10px;
      padding: .6rem .9rem; font-size: .9rem;
      transition: border-color .2s, box-shadow .2s;
    }
    .form-control:focus, .form-select:focus {
      border-color: #696cff;
      box-shadow: 0 0 0 3px rgba(105,108,255,.15);
      outline: none;
    }
    .form-text { font-size: .78rem; color: #aaa; margin-top: .25rem; }

    /* ── Buttons ───────────────────────────────────────────────────── */
    .btn-primary {
      background: #696cff; border: none; border-radius: 10px;
      font-weight: 600; padding: .6rem 1.6rem;
      transition: background .2s, transform .1s, box-shadow .2s;
      box-shadow: 0 4px 12px rgba(105,108,255,.3);
    }
    .btn-primary:hover   { background: #5a5de8; box-shadow: 0 6px 16px rgba(105,108,255,.4); }
    .btn-primary:active  { transform: scale(.98); }
    .btn-outline-secondary { border-radius: 10px; border: 1.5px solid #ddd; font-weight: 500; padding: .6rem 1.2rem; }
    .btn-skip {
      background: none; border: none; color: #aaa;
      font-size: .85rem; padding: .5rem .8rem;
      cursor: pointer; text-decoration: underline;
    }
    .btn-skip:hover { color: #696cff; }

    /* ── Voice cards ────────────────────────────────────────────────── */
    .voice-card {
      border: 2px solid #eee; border-radius: 14px;
      padding: .85rem 1rem; cursor: pointer;
      display: flex; align-items: center; gap: .75rem;
      transition: all .2s; background: #fafafa;
    }
    .voice-card:hover  { border-color: #c0c3ff; background: #f5f5ff; }
    .voice-card.active { border-color: #696cff; background: #f0f0ff; }
    .voice-card.active .vc-name { color: #696cff; }
    .vc-emoji { font-size: 1.6rem; line-height: 1; }
    .vc-name  { font-weight: 700; font-size: .9rem; color: #333; }
    .vc-desc  { font-size: .75rem; color: #999; }
    .vc-play  {
      margin-left: auto; border: 1px solid #d9d9e3; background: #fff;
      border-radius: 50%; width: 34px; height: 34px; cursor: pointer;
      display: flex; align-items: center; justify-content: center;
      color: #696cff; font-size: 1.1rem; flex-shrink: 0; transition: all .15s;
    }
    .vc-play:hover { background: #696cff; color: #fff; border-color: #696cff; }

    /* ── Checklist de capacidades ───────────────────────────────────── */
    .cap-grid { display: grid; grid-template-columns: 1fr 1fr; gap: .5rem; }
    @media (max-width: 575px) { .cap-grid { grid-template-columns: 1fr; } }
    .cap-item {
      display: flex; align-items: flex-start; gap: .55rem;
      border: 1.5px solid #eee; border-radius: 11px; padding: .6rem .75rem;
      cursor: pointer; transition: all .15s; background: #fafafa;
    }
    .cap-item:hover { border-color: #c0c3ff; }
    .cap-item.checked { border-color: #696cff; background: #f3f3ff; }
    .cap-item input { margin-top: .15rem; cursor: pointer; flex-shrink: 0; }
    .cap-item .cap-label { font-size: .83rem; color: #444; line-height: 1.35; }
    .cap-item.checked .cap-label { color: #4b4ea8; font-weight: 600; }

    /* ── Twilio guide ───────────────────────────────────────────────── */
    .twilio-guide {
      background: #f8f8ff; border: 1.5px solid #e0e0f5;
      border-radius: 12px; padding: 1rem 1.1rem; font-size: .84rem;
    }
    .twilio-guide ol { margin: .4rem 0 0; padding-left: 1.3rem; }
    .twilio-guide li { margin-bottom: .3rem; color: #555; }

    /* ── Done screen ────────────────────────────────────────────────── */
    .ob-done { text-align: center; }
    .ob-done-icon { font-size: 4rem; margin-bottom: 1rem; }
    .ob-done h3 { font-weight: 700; color: #2d2f69; }
    .quick-action {
      border: 1.5px solid #eee; border-radius: 14px;
      padding: 1.1rem; text-align: center; cursor: pointer;
      transition: all .2s; text-decoration: none; color: inherit;
      display: block;
    }
    .quick-action:hover { border-color: #696cff; background: #f5f5ff; color: #696cff; }
    .quick-action i { font-size: 1.8rem; display: block; margin-bottom: .4rem; }
    .quick-action .qa-label { font-weight: 600; font-size: .88rem; }
    .quick-action .qa-sub   { font-size: .75rem; color: #aaa; }

    .ob-footer { margin-top: 2rem; font-size: .78rem; color: #c0c0c0; text-align: center; }

    /* ── Animaciones ───────────────────────────────────────────────── */
    @keyframes popIn {
      from { transform: scale(0) rotate(-10deg); opacity: 0; }
      to   { transform: scale(1) rotate(0deg);  opacity: 1; }
    }
  </style>
</head>
<body>
<div class="ob-wrap">

  <!-- Brand -->
  <div class="ob-brand">
    <div class="ob-brand-icon">
      <svg width="22" height="22" viewBox="0 0 32 32" fill="none">
        <path d="M16 4l11 6.3v12.4L16 29 5 22.7V10.3L16 4z" fill="#fff" fill-opacity=".95"/>
        <circle cx="16" cy="16" r="4" fill="rgba(105,108,255,.8)"/>
      </svg>
    </div>
    <span class="ob-brand-name">AgentCore</span>
  </div>
  <p style="color:#aaa;font-size:.85rem;margin-bottom:0">Configuración inicial &middot; solo toma unos minutos</p>

  <!-- Steps progress -->
  <div class="ob-steps">
    <div class="ob-step active" id="dot-1">
      <div class="ob-step-dot">1</div>
      <div class="ob-step-label">Negocio</div>
    </div>
    <div class="ob-line" id="line-12"></div>
    <div class="ob-step" id="dot-2">
      <div class="ob-step-dot">2</div>
      <div class="ob-step-label">Tu agente</div>
    </div>
    <div class="ob-line" id="line-23"></div>
    <div class="ob-step" id="dot-3">
      <div class="ob-step-dot">3</div>
      <div class="ob-step-label">Teléfono</div>
    </div>
    <div class="ob-line" id="line-34"></div>
    <div class="ob-step" id="dot-4">
      <div class="ob-step-dot">4</div>
      <div class="ob-step-label">Conocimiento</div>
    </div>
    <div class="ob-line" id="line-45"></div>
    <div class="ob-step" id="dot-5">
      <div class="ob-step-dot"><i class="bx bx-check" style="font-size:1.1rem"></i></div>
      <div class="ob-step-label">¡Listo!</div>
    </div>
  </div>

  <!-- Card -->
  <div class="ob-card">

    <!-- ── PASO 1: Negocio ───────────────────────────────────────── -->
    <div class="ob-content active" id="step-1">
      <div style="font-size:2.2rem;margin-bottom:.5rem">👋</div>
      <div class="ob-step-title">¡Hola, <?= e(explode(' ', $user['name'] ?? 'Admin')[0]) ?>!</div>
      <p class="ob-step-sub">Vamos a dejar tu cuenta lista en minutos. Cuéntanos sobre tu negocio.</p>

      <div class="row g-3">
        <div class="col-12">
          <label class="form-label">Nombre del negocio</label>
          <input class="form-control" id="ob-bizname" value="<?= e($bizName) ?>" placeholder="Ej. Clínica Sonrisa"/>
        </div>
        <div class="col-12">
          <label class="form-label">¿A qué se dedica tu negocio?</label>
          <select class="form-select" id="ob-industry">
            <?php foreach ($industries as $val => $lbl): ?>
            <option value="<?= e($val) ?>" <?= $industry === $val ? 'selected' : '' ?>><?= e($lbl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12">
          <label class="form-label">
            ¿Qué quieres que haga tu agente?
            <span style="font-weight:400;color:#aaa;font-size:.78rem">(marca las que apliquen · editable después)</span>
          </label>
          <div id="ob-capabilities" class="cap-grid">
            <div class="text-muted small">Selecciona primero el tipo de negocio para ver sugerencias.</div>
          </div>
          <label class="form-label mt-3" style="font-size:.84rem">¿Algo más? (opcional)</label>
          <textarea class="form-control" id="ob-objective-extra" rows="2"
            placeholder="Escribe otras tareas o detalles específicos de tu negocio…"><?= e($settings['objectiveExtra'] ?? '') ?></textarea>
        </div>
      </div>

      <div id="alert-1" class="alert alert-danger mt-3 d-none py-2" style="font-size:.84rem;border-radius:10px"></div>

      <div class="d-flex justify-content-end gap-2 mt-4">
        <button class="btn btn-primary" id="btn-step1" onclick="saveStep1()">
          Continuar <i class="bx bx-chevron-right ms-1"></i>
        </button>
      </div>
    </div>

    <!-- ── PASO 2: Tu agente ──────────────────────────────────────── -->
    <div class="ob-content" id="step-2">
      <div style="font-size:2.2rem;margin-bottom:.5rem">🤖</div>
      <div class="ob-step-title">Crea tu primer agente</div>
      <p class="ob-step-sub">Elige el nombre y la voz con la que tus clientes hablarán.</p>

      <div class="row g-3 mb-3">
        <div class="col-sm-6">
          <label class="form-label">Nombre del agente</label>
          <input class="form-control" id="ob-agentname" value="<?= e($defaults['agentName']) ?>" placeholder="Ej. Sofía, Carlos…"/>
        </div>
        <div class="col-sm-6">
          <label class="form-label">Idioma</label>
          <select class="form-select" id="ob-language">
            <option value="es-MX" selected>🇲🇽 Español (México)</option>
            <option value="es-ES">🇪🇸 Español (España)</option>
            <option value="en-US">🇺🇸 English (US)</option>
          </select>
        </div>
        <div class="col-12">
          <label class="form-label">Saludo inicial</label>
          <textarea class="form-control" id="ob-greeting" rows="2"><?= e($defaultGreeting) ?></textarea>
          <div class="form-text">Es lo primero que dice el agente al contestar la llamada.</div>
        </div>
      </div>

      <label class="form-label d-block mb-2">
        Elige la voz del agente
        <span style="font-weight:400;color:#aaa;font-size:.78rem">· toca ▶ para escuchar una muestra</span>
      </label>
      <div class="row g-2 mb-1">
        <?php foreach ($voices as $i => $v): ?>
        <div class="col-6">
          <div class="voice-card <?= $i === 0 ? 'active' : '' ?>"
               onclick="selectVoice(this, '<?= e($v['id']) ?>')">
            <span class="vc-emoji"><?= $v['emoji'] ?></span>
            <div>
              <div class="vc-name"><?= e($v['name']) ?></div>
              <div class="vc-desc"><?= e($v['desc']) ?></div>
            </div>
            <button type="button" class="vc-play" title="Escuchar a <?= e($v['name']) ?>"
                    onclick="event.stopPropagation(); playVoiceDemo('<?= e($v['name']) ?>', '<?= e($v['id']) ?>', this)">
              <i class="bx bx-play"></i>
            </button>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <input type="hidden" id="ob-voice-id" value="<?= e($voices[0]['id']) ?>"/>

      <div id="alert-2" class="alert alert-danger mt-3 d-none py-2" style="font-size:.84rem;border-radius:10px"></div>

      <div class="d-flex justify-content-between align-items-center mt-4">
        <button class="btn btn-outline-secondary btn-sm" onclick="goStep(1)">
          <i class="bx bx-chevron-left me-1"></i> Atrás
        </button>
        <button class="btn btn-primary" id="btn-step2" onclick="saveStep2()">
          Continuar <i class="bx bx-chevron-right ms-1"></i>
        </button>
      </div>
    </div>

    <!-- ── PASO 3: Teléfono ───────────────────────────────────────── -->
    <div class="ob-content" id="step-3">
      <div style="font-size:2.2rem;margin-bottom:.5rem">📞</div>
      <div class="ob-step-title">Conecta tu número de teléfono <span style="font-size:.9rem;color:#aaa;font-weight:400">(opcional)</span></div>
      <p class="ob-step-sub">Esto es solo para <strong>llamadas de voz</strong> con Twilio.</p>

      <div class="alert d-flex align-items-start gap-2 mb-4" style="background:#eef4ff;border:1px solid #d6e2ff;border-radius:12px;font-size:.85rem">
        <i class="bx bx-bulb" style="color:#696cff;font-size:1.1rem;margin-top:1px"></i>
        <div>¿Aún no tienes cuenta de Twilio? <strong>No la necesitas para empezar.</strong>
          Salta este paso y tu bot funcionará igual por <strong>chat web</strong> y en el
          <strong>simulador</strong>. Puedes conectar el teléfono cuando quieras desde Configuración.</div>
      </div>

      <div class="twilio-guide mb-4">
        <div style="font-weight:600;margin-bottom:.4rem">
          <i class="bx bx-info-circle me-1" style="color:#696cff"></i>¿Cómo obtengo mis credenciales?
        </div>
        <ol>
          <li>Ve a <a href="https://console.twilio.com" target="_blank" rel="noopener">console.twilio.com</a></li>
          <li>Copia tu <strong>Account SID</strong> y <strong>Auth Token</strong> del dashboard</li>
          <li>En <em>Phone Numbers → Active numbers</em> copia tu número en formato <code>+521XXXXXXXXXX</code></li>
        </ol>
      </div>

      <div class="row g-3">
        <div class="col-12">
          <label class="form-label">Account SID</label>
          <input class="form-control font-monospace" id="ob-twilio-sid" placeholder="ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"/>
        </div>
        <div class="col-12">
          <label class="form-label">Auth Token</label>
          <div class="input-group">
            <input class="form-control font-monospace" id="ob-twilio-token" type="password" placeholder="••••••••••••••••••••••••••••••••"/>
            <button class="btn btn-outline-secondary" type="button" onclick="togglePass('ob-twilio-token',this)">
              <i class="bx bx-hide"></i>
            </button>
          </div>
        </div>
        <div class="col-12">
          <label class="form-label">Número de teléfono</label>
          <input class="form-control font-monospace" id="ob-twilio-phone" placeholder="+521XXXXXXXXXX"/>
        </div>
      </div>

      <div id="alert-3" class="alert alert-danger mt-3 d-none py-2" style="font-size:.84rem;border-radius:10px"></div>

      <div class="d-flex justify-content-between align-items-center mt-4">
        <button class="btn btn-outline-secondary btn-sm" onclick="goStep(2)">
          <i class="bx bx-chevron-left me-1"></i> Atrás
        </button>
        <div class="d-flex align-items-center gap-2">
          <button class="btn btn-outline-primary" onclick="skipToKb()">
            Omitir y continuar <i class="bx bx-chevron-right ms-1"></i>
          </button>
          <button class="btn btn-primary" id="btn-step3" onclick="saveStep3()">
            Guardar y continuar
          </button>
        </div>
      </div>
    </div>

    <!-- ── PASO 4: Base de conocimiento ──────────────────────────── -->
    <div class="ob-content" id="step-4">
      <div style="font-size:2.2rem;margin-bottom:.5rem">📚</div>
      <div class="ob-step-title">Base de conocimiento</div>
      <p class="ob-step-sub">Dale a tu agente información sobre tu negocio para que responda con precisión. Puedes agregar más documentos después.</p>

      <!-- Tipo de documento -->
      <div class="d-flex gap-2 mb-3" id="kb-type-tabs">
        <button class="btn btn-sm btn-outline-secondary active" data-kbtype="text"
                onclick="switchKbType('text',this)">
          <i class="bx bx-text me-1"></i>Texto libre
        </button>
        <button class="btn btn-sm btn-outline-secondary" data-kbtype="faq"
                onclick="switchKbType('faq',this)">
          <i class="bx bx-question-mark me-1"></i>FAQ
        </button>
        <button class="btn btn-sm btn-outline-secondary" data-kbtype="url"
                onclick="switchKbType('url',this)">
          <i class="bx bx-link me-1"></i>URL
        </button>
      </div>

      <!-- TEXT -->
      <div id="kb-panel-text">
        <div class="mb-3">
          <label class="form-label">Título del documento</label>
          <input class="form-control" id="kb-title" placeholder="Ej. Información general del negocio"/>
        </div>
        <div class="mb-1">
          <label class="form-label">Contenido</label>
          <textarea class="form-control" id="kb-content" rows="5"
                    placeholder="Describe tu negocio: servicios, horarios, precios, políticas, ubicación…"></textarea>
        </div>
      </div>

      <!-- FAQ -->
      <div id="kb-panel-faq" class="d-none">
        <div class="mb-3">
          <label class="form-label">Pregunta frecuente</label>
          <input class="form-control" id="kb-faq-q" placeholder="¿Cuáles son sus horarios de atención?"/>
        </div>
        <div class="mb-1">
          <label class="form-label">Respuesta</label>
          <textarea class="form-control" id="kb-faq-a" rows="3"
                    placeholder="Atendemos de lunes a viernes de 9am a 6pm, y sábados de 9am a 2pm."></textarea>
        </div>
      </div>

      <!-- URL -->
      <div id="kb-panel-url" class="d-none">
        <div class="mb-1">
          <label class="form-label">URL de tu sitio web</label>
          <input class="form-control" id="kb-url" type="url" placeholder="https://tunegocio.com/"/>
          <div class="form-text">Extraeremos el contenido principal de la página.</div>
        </div>
      </div>

      <div id="alert-4" class="alert alert-danger mt-3 d-none py-2" style="font-size:.84rem;border-radius:10px"></div>

      <div class="d-flex justify-content-between align-items-center mt-4">
        <button class="btn btn-outline-secondary btn-sm" onclick="goStep(3)">
          <i class="bx bx-chevron-left me-1"></i> Atrás
        </button>
        <div class="d-flex align-items-center gap-3">
          <button class="btn-skip" onclick="finishOnboarding()">Saltar por ahora</button>
          <button class="btn btn-primary" id="btn-step4" onclick="saveStep4()">
            <span>Finalizar <i class="bx bx-check ms-1"></i></span>
          </button>
        </div>
      </div>
    </div>

    <!-- ── PASO 5: Listo ──────────────────────────────────────────── -->
    <div class="ob-content" id="step-5">
      <div class="ob-done">
        <div class="ob-done-icon" style="animation: popIn .5s cubic-bezier(.34,1.56,.64,1) both">🎉</div>
        <h3>¡Todo listo!</h3>
        <p class="text-muted mb-1" style="font-size:.9rem">
          Tu agente <strong id="done-agent-name">tu agente</strong> está creado y listo.
        </p>
        <ul class="list-unstyled text-start d-inline-block mb-4 mt-2" id="done-checklist"
            style="font-size:.85rem">
          <li class="mb-1"><i class="bx bx-check-circle text-success me-1"></i> Industria configurada</li>
          <li class="mb-1" id="done-li-agent"><i class="bx bx-check-circle text-success me-1"></i> Agente IA creado</li>
          <li class="mb-1" id="done-li-twilio"><i class="bx bx-check-circle text-success me-1"></i> Número Twilio conectado</li>
          <li class="mb-1" id="done-li-kb"><i class="bx bx-check-circle text-success me-1"></i> Base de conocimiento iniciada</li>
        </ul>
        <div class="row g-3 mb-4">
          <div class="col-6">
            <a href="/pages/agents.php" class="quick-action">
              <i class="bx bx-bot" style="color:#696cff"></i>
              <div class="qa-label">Mis agentes</div>
              <div class="qa-sub">Ver y configurar</div>
            </a>
          </div>
          <div class="col-6">
            <a href="/pages/knowledge-base.php" class="quick-action">
              <i class="bx bx-book-open" style="color:#ff9f43"></i>
              <div class="qa-label">Base de conocimiento</div>
              <div class="qa-sub">Agregar más documentos</div>
            </a>
          </div>
        </div>
        <a href="/index.php" class="btn btn-primary w-100 py-2" style="font-size:1rem">
          <i class="bx bx-rocket me-1"></i> Ir al Dashboard
        </a>
      </div>
    </div>

  </div><!-- /ob-card -->

  <div class="ob-footer">
    AgentCore &copy; <?= date('Y') ?> &nbsp;&middot;&nbsp; Puedes completar esto después desde Configuración
  </div>

</div><!-- /ob-wrap -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ── Estado ────────────────────────────────────────────────────────────────
let currentStep  = 1;
let createdAgent = null; // { id, name } — resultado de saveStep2
let twilioSaved  = false;
let kbSaved      = false;
let currentKbType = 'text';

const TOTAL_STEPS = 5;
const industryDefaults = <?= json_encode($industryDefaults) ?>;

// ── Capacidades sugeridas por industria (checklist del paso 1) ─────────────
const CAPABILITIES = {
  dental: ['Agendar citas', 'Responder precios de tratamientos', 'Atender urgencias dentales',
           'Dar información de servicios', 'Capturar datos de pacientes', 'Enviar recordatorios de cita'],
  clinica: ['Agendar citas', 'Responder precios', 'Atender urgencias',
            'Dar información de servicios', 'Capturar datos de pacientes', 'Enviar recordatorios'],
  consultorio: ['Agendar sesiones', 'Responder precios', 'Calificar al paciente antes de agendar',
                'Dar información de servicios', 'Capturar datos de contacto'],
  restaurante: ['Tomar reservaciones', 'Responder dudas del menú', 'Tomar pedidos a domicilio',
                'Dar horarios y ubicación', 'Cotizar eventos privados'],
  ecommerce: ['Mostrar productos del catálogo', 'Tomar pedidos y cobrar', 'Rastrear envíos',
              'Gestionar devoluciones', 'Informar promociones', 'Capturar datos de contacto'],
  inmobiliaria: ['Mostrar propiedades', 'Agendar visitas', 'Calificar interesados (compra/renta)',
                 'Informar sobre financiamiento', 'Capturar datos de contacto'],
  taller: ['Agendar servicios', 'Cotizar reparaciones', 'Dar estatus del vehículo',
           'Responder precios', 'Capturar datos de contacto'],
  educacion: ['Informar de cursos e inscripciones', 'Agendar visitas o pruebas', 'Responder precios',
              'Capturar prospectos', 'Dar horarios'],
  gym: ['Informar planes y precios', 'Agendar visita o clase de prueba', 'Capturar prospectos',
        'Responder dudas de horarios'],
  servicios: ['Cotizar servicios', 'Agendar citas', 'Responder preguntas frecuentes',
              'Capturar datos de contacto'],
  '': ['Agendar citas', 'Responder preguntas frecuentes', 'Dar precios e información',
       'Capturar datos de contacto'],
};

// Renderiza el checklist según la industria seleccionada
function renderCapabilities(industry) {
  const caps = CAPABILITIES[industry] || CAPABILITIES[''];
  const box  = document.getElementById('ob-capabilities');
  box.innerHTML = caps.map((c, i) => `
    <label class="cap-item ${i < 3 ? 'checked' : ''}">
      <input type="checkbox" value="${c.replace(/"/g,'&quot;')}" ${i < 3 ? 'checked' : ''}
             onchange="this.closest('.cap-item').classList.toggle('checked', this.checked)">
      <span class="cap-label">${c}</span>
    </label>`).join('');
}

// Junta las capacidades marcadas + el texto libre en un solo objetivo
function collectObjective() {
  const checked = [...document.querySelectorAll('#ob-capabilities input:checked')].map(i => i.value);
  const extra   = (document.getElementById('ob-objective-extra')?.value || '').trim();
  const parts   = [];
  if (checked.length) parts.push(checked.join(', '));
  if (extra) parts.push(extra);
  return parts.join('. ');
}

// ── Demo de voz (paso 2) — usa las VOCES REALES de Cartesia ────────────────
// Si Cartesia falla, cae a la síntesis del navegador (diferenciada por tono).
let _voiceAudio = null;
const VOICE_TUNING = { // fallback navegador: tono/velocidad distintos por voz
  'Sofía':   { pitch: 1.25, rate: 1.0  },
  'Andrea':  { pitch: 1.05, rate: 1.08 },
  'Carlos':  { pitch: 0.7,  rate: 0.95 },
  'Valeria': { pitch: 1.4,  rate: 1.12 },
};

async function playVoiceDemo(name, voiceId, btn) {
  // Parar cualquier reproducción previa
  try { window.speechSynthesis.cancel(); } catch {}
  if (_voiceAudio) { _voiceAudio.pause(); _voiceAudio = null; }

  const sample = document.getElementById('ob-greeting')?.value
    || `Hola, soy ${name}, tu asistente virtual. ¿En qué te puedo ayudar?`;
  const reset = () => { if (btn) btn.innerHTML = '<i class="bx bx-play"></i>'; };
  if (btn) btn.innerHTML = '<span class="spinner-border spinner-border-sm" style="width:14px;height:14px"></span>';

  // 1) Intentar la voz REAL de Cartesia
  try {
    const res = await fetch('/api/agent-voice-preview.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ voice_id: voiceId, text: sample }),
    });
    if (res.ok && (res.headers.get('Content-Type') || '').includes('audio')) {
      const blob = await res.blob();
      _voiceAudio = new Audio(URL.createObjectURL(blob));
      if (btn) btn.innerHTML = '<i class="bx bx-volume-full"></i>';
      _voiceAudio.onended = reset;
      await _voiceAudio.play();
      return;
    }
  } catch (e) { /* cae al fallback */ }

  // 2) Fallback: síntesis del navegador (diferenciada por tono)
  try {
    const u = new SpeechSynthesisUtterance(sample);
    u.lang = 'es-MX';
    const t = VOICE_TUNING[name] || { pitch: 1.0, rate: 1.0 };
    u.pitch = t.pitch; u.rate = t.rate;
    if (btn) btn.innerHTML = '<i class="bx bx-volume-full"></i>';
    u.onend = reset;
    window.speechSynthesis.speak(u);
  } catch (e) { reset(); }
}

// ── Helpers ───────────────────────────────────────────────────────────────
function showStepAlert(step, msg) {
  const el = document.getElementById(`alert-${step}`);
  if (!el) return;
  el.classList.remove('d-none');
  el.innerHTML = `<i class="bx bx-error-circle me-1"></i>${msg}`;
}
function hideStepAlert(step) {
  const el = document.getElementById(`alert-${step}`);
  if (el) el.classList.add('d-none');
}

function setBtnLoading(id, loading, originalHTML) {
  const btn = document.getElementById(id);
  if (!btn) return;
  btn.disabled = loading;
  if (loading) {
    btn._orig = btn.innerHTML;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Guardando…';
  } else {
    btn.innerHTML = btn._orig || originalHTML || btn.innerHTML;
  }
}

async function post(url, body) {
  const r = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'same-origin',
    body: JSON.stringify(body),
  });
  return r.json();
}

// ── Navegación ────────────────────────────────────────────────────────────
function goStep(n) {
  document.getElementById(`step-${currentStep}`).classList.remove('active');
  document.getElementById(`step-${n}`).classList.add('active');
  currentStep = n;

  // Dots
  [1,2,3,4,5].forEach(i => {
    const dot = document.getElementById(`dot-${i}`);
    if (!dot) return;
    dot.classList.remove('active','done');
    if (i < n)  dot.classList.add('done');
    if (i === n) dot.classList.add('active');
    // Tick inside done dots
    if (i < n) {
      dot.querySelector('.ob-step-dot').innerHTML = '<i class="bx bx-check" style="font-size:1.1rem"></i>';
    }
  });
  // Lines
  [['line-12',1],['line-23',2],['line-34',3],['line-45',4]].forEach(([id, after]) => {
    const el = document.getElementById(id);
    if (el) el.classList.toggle('done', n > after);
  });

  if (n === 5) {
    document.getElementById('done-agent-name').textContent =
      createdAgent?.name || document.getElementById('ob-agentname')?.value.trim() || 'tu agente';
    // Update checklist skips
    if (!twilioSaved) {
      const li = document.getElementById('done-li-twilio');
      if (li) li.innerHTML = '<i class="bx bx-minus-circle text-muted me-1"></i> Telefonía (pendiente en Configuración)';
    }
    if (!kbSaved) {
      const li = document.getElementById('done-li-kb');
      if (li) li.innerHTML = '<i class="bx bx-minus-circle text-muted me-1"></i> KB pendiente (agregar en Base de conocimiento)';
    }
  }

  window.scrollTo({ top: 0, behavior: 'smooth' });
}

// ── Voz ───────────────────────────────────────────────────────────────────
function selectVoice(el, voiceId) {
  document.querySelectorAll('.voice-card').forEach(c => c.classList.remove('active'));
  el.classList.add('active');
  document.getElementById('ob-voice-id').value = voiceId;
}

// ── Toggle password ───────────────────────────────────────────────────────
function togglePass(id, btn) {
  const inp  = document.getElementById(id);
  const icon = btn.querySelector('i');
  inp.type = inp.type === 'password' ? 'text' : 'password';
  icon.className = inp.type === 'password' ? 'bx bx-hide' : 'bx bx-show';
}

// ── Auto-ajustar agente al cambiar industria ──────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  const indSel = document.getElementById('ob-industry');
  indSel.addEventListener('change', function() {
    const biz = document.getElementById('ob-bizname').value.trim() || 'nuestro negocio';
    const def = industryDefaults[this.value] || industryDefaults[''];
    document.getElementById('ob-agentname').value = def.agentName;
    document.getElementById('ob-greeting').value  = def.greeting.replace(/\{biz\}/g, biz);
    renderCapabilities(this.value);
  });
  // Render inicial del checklist según la industria ya seleccionada (si la hay)
  renderCapabilities(indSel.value);
});

// ── KB tipo tabs ──────────────────────────────────────────────────────────
function switchKbType(type, btn) {
  currentKbType = type;
  document.querySelectorAll('#kb-type-tabs button').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  ['text','faq','url'].forEach(t =>
    document.getElementById(`kb-panel-${t}`).classList.toggle('d-none', t !== type)
  );
}

// ── PASO 1: Guardar negocio ───────────────────────────────────────────────
async function saveStep1() {
  hideStepAlert(1);
  const industry = document.getElementById('ob-industry').value;
  const bizName  = document.getElementById('ob-bizname').value.trim();
  if (!industry) { showStepAlert(1, 'Selecciona el tipo de negocio'); return; }
  if (!bizName)  { showStepAlert(1, 'Ingresa el nombre del negocio'); return; }

  setBtnLoading('btn-step1', true);
  try {
    const objective = collectObjective();
    const res = await post('/api/settings-save.php', {
      businessProfile: { industry, businessName: bizName },
      ...(objective ? { objective } : {}),
    });
    if (res.error) throw new Error(res.error);
    goStep(2);
  } catch (e) {
    showStepAlert(1, e.message || 'Error al guardar. Intenta de nuevo.');
  } finally {
    setBtnLoading('btn-step1', false);
  }
}

// ── PASO 2: Crear agente ──────────────────────────────────────────────────
async function saveStep2() {
  hideStepAlert(2);
  const agentName = document.getElementById('ob-agentname').value.trim();
  if (!agentName) { showStepAlert(2, 'El agente necesita un nombre'); return; }

  setBtnLoading('btn-step2', true);
  try {
    const bizName   = document.getElementById('ob-bizname').value.trim();
    const objective = collectObjective();
    const voiceId   = document.getElementById('ob-voice-id').value;
    const greeting  = document.getElementById('ob-greeting').value.trim();
    const language  = document.getElementById('ob-language').value;

    const res = await post('/api/agent-save.php', {
      name: agentName,
      channel: 'voice',
      language,
      voice_id: voiceId,
      system_prompt: `Eres ${agentName}, asistente virtual de ${bizName || 'nuestro negocio'}. ${objective}`.trim(),
      config: { greeting: greeting || undefined },
      is_active: true,
    });
    if (res.error) throw new Error(res.error);
    createdAgent = { id: res.id ?? res.agent?.id, name: agentName };
    goStep(3);
  } catch (e) {
    showStepAlert(2, e.message || 'Error al crear el agente. Intenta de nuevo.');
  } finally {
    setBtnLoading('btn-step2', false);
  }
}

// ── PASO 3: Guardar Twilio ────────────────────────────────────────────────
function skipToKb() {
  twilioSaved = false;
  goStep(4);
}

async function saveStep3() {
  hideStepAlert(3);
  const sid   = document.getElementById('ob-twilio-sid').value.trim();
  const token = document.getElementById('ob-twilio-token').value.trim();
  const phone = document.getElementById('ob-twilio-phone').value.trim();

  if (!sid && !token && !phone) { skipToKb(); return; } // vacío = skip silencioso
  if (!sid)   { showStepAlert(3, 'Ingresa el Account SID o usa "Saltar"'); return; }
  if (!token) { showStepAlert(3, 'Ingresa el Auth Token'); return; }
  if (!phone) { showStepAlert(3, 'Ingresa el número de teléfono'); return; }

  setBtnLoading('btn-step3', true);
  try {
    const res = await post('/api/settings-save.php', {
      twilio: { accountSid: sid, authToken: token, phoneNumber: phone }
    });
    if (res.error) throw new Error(res.error);
    twilioSaved = true;
    goStep(4);
  } catch (e) {
    showStepAlert(3, e.message || 'Error al guardar telefonía.');
  } finally {
    setBtnLoading('btn-step3', false);
  }
}

// ── PASO 4: Guardar KB ────────────────────────────────────────────────────
async function saveStep4() {
  hideStepAlert(4);
  let payload = {};

  if (currentKbType === 'text') {
    const title   = document.getElementById('kb-title').value.trim();
    const content = document.getElementById('kb-content').value.trim();
    if (!title && !content) { await finishOnboarding(); return; } // vacío = skip
    if (!title)   { showStepAlert(4, 'El documento necesita un título'); return; }
    if (!content) { showStepAlert(4, 'El contenido no puede estar vacío'); return; }
    payload = { title, content, file_type: 'text' };

  } else if (currentKbType === 'faq') {
    const q = document.getElementById('kb-faq-q').value.trim();
    const a = document.getElementById('kb-faq-a').value.trim();
    if (!q && !a) { await finishOnboarding(); return; }
    if (!q) { showStepAlert(4, 'Escribe la pregunta'); return; }
    if (!a) { showStepAlert(4, 'Escribe la respuesta'); return; }
    payload = { file_type: 'faq', pairs: [{ question: q, answer: a }] };

  } else if (currentKbType === 'url') {
    const url = document.getElementById('kb-url').value.trim();
    if (!url) { await finishOnboarding(); return; }
    payload = { title: url, source_url: url, file_type: 'url' };
  }

  setBtnLoading('btn-step4', true);
  try {
    const res = await post('/api/kb-proxy.php', payload);
    if (res.error) throw new Error(res.error);
    kbSaved = true;
    await finishOnboarding();
  } catch (e) {
    showStepAlert(4, e.message || 'Error al guardar la base de conocimiento.');
    setBtnLoading('btn-step4', false);
  }
}

// ── Finalizar: marcar onboarding_completed ────────────────────────────────
async function finishOnboarding() {
  try {
    await post('/api/settings-save.php', { onboarding_completed: true });
  } catch (_) { /* best-effort */ }
  goStep(5);
}
</script>
</body>
</html>
