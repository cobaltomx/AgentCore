<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/head.php';
require_once __DIR__ . '/../includes/sidebar.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/footer.php';

requireAuth();

$campaigns = array_values(array_filter((array)apiGet('/campaigns'), 'is_array'));
$stats     = apiGet('/campaigns/stats');
$agents    = array_values(array_filter((array)apiGet('/agents'), 'is_array'));

renderHead('Campañas Outbound');

$statusMap = [
  'draft'     => ['secondary', 'Borrador',     'bx-edit'],
  'scheduled' => ['info',      'Programada',   'bx-time'],
  'running'   => ['success',   'En ejecución', 'bx-play-circle'],
  'paused'    => ['warning',   'Pausada',      'bx-pause-circle'],
  'completed' => ['primary',   'Completada',   'bx-check-circle'],
  'cancelled' => ['danger',    'Cancelada',    'bx-x-circle'],
];
?>

<style>
  /* ── Campaign type cards ─────────────────────────────────── */
  .camp-type-card {
    border: 2px solid #e5e7eb; border-radius: .75rem;
    padding: .9rem .75rem; cursor: pointer; transition: all .15s; text-align: center;
  }
  .camp-type-card:hover { border-color: #696cff; box-shadow: 0 0 0 3px rgba(105,108,255,.12); }
  .camp-type-card.selected { border-color: #696cff; background: rgba(105,108,255,.07); }
  .camp-type-card .ct-icon { font-size: 1.75rem; line-height: 1; margin-bottom: .4rem; }
  .camp-type-card .ct-name { font-size: .82rem; font-weight: 600; }
  .camp-type-card .ct-desc { font-size: .72rem; color: #8592a3; }
  html.dark-style .camp-type-card { border-color: #3b3e5e; }
  html.dark-style .camp-type-card.selected { background: rgba(105,108,255,.15); }

  /* ── Wizard steps ────────────────────────────────────────── */
  .wizard-step { display: none; }
  .wizard-step.active { display: block; }
  .step-indicator { display: flex; gap: .5rem; margin-bottom: 1.25rem; }
  .step-dot {
    width: 28px; height: 28px; border-radius: 50%; display: flex; align-items: center;
    justify-content: center; font-size: .75rem; font-weight: 700;
    background: #e5e7eb; color: #8592a3; transition: all .2s;
  }
  .step-dot.done    { background: #28a745; color: #fff; }
  .step-dot.active  { background: #696cff; color: #fff; }
  .step-line { flex: 1; height: 2px; background: #e5e7eb; align-self: center; border-radius: 1px; }
  .step-line.done { background: #28a745; }

  /* ── Contact status chips ────────────────────────────────── */
  .contact-stat { text-align: center; }
  .contact-stat .val { font-size: 1.4rem; font-weight: 700; }

  /* ── Campaign card hover ─────────────────────────────────── */
  .campaign-card { transition: box-shadow .15s, transform .1s; cursor: default; }
  .campaign-card:hover { box-shadow: 0 4px 20px rgba(105,108,255,.12); }

  /* ── Oferta highlight ────────────────────────────────────── */
  .offer-box {
    background: linear-gradient(135deg, #fff7e6, #fffbf0);
    border: 1px solid #ffd666; border-radius: .5rem; padding: .75rem 1rem;
  }
  html.dark-style .offer-box { background: #2a2720; border-color: #6b5a1e; }
</style>

<div class="layout-wrapper layout-content-navbar">
<div class="layout-container">
<?php renderSidebar('campaigns'); ?>
<div class="layout-page">
<?php renderNavbar('Campañas Outbound'); ?>
<div class="content-wrapper">
<div class="container-xxl flex-grow-1 container-p-y">

  <!-- Header -->
  <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
      <h4 class="mb-1">Campañas Outbound</h4>
      <p class="text-muted mb-0">Agentes que llaman y escriben a tus prospectos automáticamente</p>
    </div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newCampaignModal">
      <i class="bx bx-plus me-1"></i>Nueva campaña
    </button>
  </div>

  <!-- KPIs -->
  <div class="row g-3 mb-4">
    <?php
    $kpis = [
      ['label'=>'En ejecución',     'val'=>(int)($stats['running']          ?? 0), 'icon'=>'bx-play-circle',   'color'=>'success'],
      ['label'=>'Completadas',      'val'=>(int)($stats['completed']        ?? 0), 'icon'=>'bx-check-circle',  'color'=>'info'],
      ['label'=>'Contactos totales','val'=>number_format((int)($stats['total_contacts'] ?? 0)), 'icon'=>'bx-group',  'color'=>'primary'],
      ['label'=>'Tasa conversión',  'val'=>($stats['conversion_rate'] ?? 0).'%',   'icon'=>'bx-trending-up',   'color'=>'warning'],
    ];
    foreach ($kpis as $k): ?>
    <div class="col-6 col-md-3">
      <div class="card">
        <div class="card-body d-flex align-items-center gap-3 py-3">
          <div class="avatar avatar-md">
            <span class="avatar-initial rounded-circle bg-label-<?= $k['color'] ?>">
              <i class="bx <?= $k['icon'] ?> fs-4"></i>
            </span>
          </div>
          <div>
            <div class="h4 mb-0"><?= $k['val'] ?></div>
            <small class="text-muted"><?= $k['label'] ?></small>
          </div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Lista de campañas -->
  <?php if (empty($campaigns)): ?>
  <div class="card">
    <div class="card-body text-center py-5">
      <i class="bx bx-broadcast bx-lg opacity-25 d-block mb-3"></i>
      <h5 class="text-muted">Sin campañas creadas</h5>
      <p class="text-muted mb-3">Crea tu primera campaña para que el agente contacte prospectos automáticamente.</p>
      <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newCampaignModal">
        <i class="bx bx-plus me-1"></i>Crear primera campaña
      </button>
    </div>
  </div>
  <?php else: ?>
  <div class="row g-4">
    <?php foreach ($campaigns as $camp):
      [$sc, $sl, $si] = $statusMap[$camp['status']] ?? ['secondary', '—', 'bx-circle'];
      $total     = (int)($camp['total_contacts'] ?? 0);
      $contacted = (int)($camp['contacted']      ?? 0);
      $converted = (int)($camp['converted']      ?? 0);
      $pct       = $total > 0 ? round($contacted / $total * 100) : 0;
      $convRate  = $contacted > 0 ? round($converted / $contacted * 100) : 0;
      $goalIcon  = [
        'follow_up'       => ['🔔','Seguimiento'],
        'book_appointment'=> ['📅','Agendar cita'],
        'qualify_lead'    => ['🎯','Calificar lead'],
        'reactivate'      => ['💸','Recuperar lead'],
        'promotion'       => ['🎁','Promoción'],
      ][$camp['goal']] ?? ['📢', ucfirst($camp['goal'])];
    ?>
    <div class="col-md-6 col-xl-4" data-campaign-id="<?= e($camp['id']) ?>">
      <div class="card h-100 campaign-card">
        <div class="card-body">
          <!-- Header -->
          <div class="d-flex justify-content-between align-items-start mb-3">
            <div>
              <span class="badge bg-label-<?= $sc ?> mb-1">
                <i class="bx <?= $si ?> me-1"></i><?= $sl ?>
              </span>
              <h6 class="mb-0"><?= e($camp['name']) ?></h6>
              <small class="text-muted">
                <?= channelIcon($camp['channel'] ?? 'voice') ?>
                <?= e($camp['agent_name'] ?? '—') ?>
                · <span title="Objetivo"><?= $goalIcon[0] ?> <?= $goalIcon[1] ?></span>
              </small>
            </div>
            <div class="dropdown">
              <button class="btn btn-sm btn-icon btn-outline-secondary" data-bs-toggle="dropdown">
                <i class="bx bx-dots-vertical-rounded"></i>
              </button>
              <ul class="dropdown-menu dropdown-menu-end">
                <?php if (in_array($camp['status'], ['draft','paused'])): ?>
                <li><a class="dropdown-item campaign-action" href="#"
                       data-id="<?= e($camp['id']) ?>" data-action="start">
                  <i class="bx bx-play me-2 text-success"></i>Iniciar
                </a></li>
                <?php endif; ?>
                <?php if ($camp['status'] === 'running'): ?>
                <li><a class="dropdown-item campaign-action" href="#"
                       data-id="<?= e($camp['id']) ?>" data-action="pause">
                  <i class="bx bx-pause me-2 text-warning"></i>Pausar
                </a></li>
                <?php endif; ?>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="#"
                       data-bs-toggle="modal" data-bs-target="#detailModal"
                       data-campaign-id="<?= e($camp['id']) ?>"
                       data-campaign-name="<?= e($camp['name']) ?>">
                  <i class="bx bx-list-ul me-2"></i>Ver contactos
                </a></li>
                <li><a class="dropdown-item" href="#"
                       data-bs-toggle="modal" data-bs-target="#uploadModal"
                       data-campaign-id="<?= e($camp['id']) ?>">
                  <i class="bx bx-upload me-2"></i>Subir contactos CSV
                </a></li>
                <li><a class="dropdown-item" href="#"
                       data-bs-toggle="modal" data-bs-target="#leadsImportModal"
                       data-campaign-id="<?= e($camp['id']) ?>">
                  <i class="bx bx-user-check me-2"></i>Importar desde Leads
                </a></li>
                <?php if (!in_array($camp['status'], ['completed','cancelled'])): ?>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item campaign-action text-danger" href="#"
                       data-id="<?= e($camp['id']) ?>" data-action="cancel">
                  <i class="bx bx-x me-2"></i>Cancelar
                </a></li>
                <?php endif; ?>
              </ul>
            </div>
          </div>

          <!-- Progreso -->
          <div class="mb-3">
            <div class="d-flex justify-content-between mb-1">
              <small class="text-muted">Progreso</small>
              <small class="fw-semibold"><?= $contacted ?>/<?= $total ?></small>
            </div>
            <div class="progress" style="height:7px;border-radius:4px">
              <div class="progress-bar bg-<?= $sc ?>" style="width:<?= $pct ?>%;border-radius:4px"></div>
            </div>
          </div>

          <!-- Stats fila -->
          <div class="row g-0 text-center border rounded p-2">
            <div class="col contact-stat border-end">
              <div class="val"><?= $total ?></div>
              <div class="text-muted" style="font-size:.72rem">Total</div>
            </div>
            <div class="col contact-stat border-end">
              <div class="val text-info"><?= $contacted ?></div>
              <div class="text-muted" style="font-size:.72rem">Contactados</div>
            </div>
            <div class="col contact-stat border-end">
              <div class="val text-success"><?= $converted ?></div>
              <div class="text-muted" style="font-size:.72rem">Convertidos</div>
            </div>
            <div class="col contact-stat">
              <div class="val text-warning"><?= $convRate ?>%</div>
              <div class="text-muted" style="font-size:.72rem">Conv.</div>
            </div>
          </div>

          <!-- Footer -->
          <div class="mt-2 d-flex gap-1 flex-wrap">
            <span class="badge bg-label-<?= $camp['trigger_type']==='auto'?'warning':'secondary' ?>">
              <?= $camp['trigger_type']==='auto' ? '⚡ Auto' : '👤 Manual' ?>
            </span>
            <span class="badge bg-label-secondary">
              <i class="bx bx-time-five me-1"></i><?= (int)$camp['allowed_hours_start'] ?>–<?= (int)$camp['allowed_hours_end'] ?>h
            </span>
            <span class="badge bg-label-secondary">
              <?= (int)$camp['calls_per_hour'] ?>/h
            </span>
          </div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

</div>
<?php renderFooter(); ?>

<!-- ══ Modal: Nueva campaña (wizard) ═════════════════════════ -->
<div class="modal fade" id="newCampaignModal" tabindex="-1">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header border-0 pb-1">
        <div>
          <h5 class="modal-title mb-0"><i class="bx bx-broadcast text-primary me-2"></i>Nueva campaña</h5>
          <small class="text-muted" id="wizardSubtitle">Elige el tipo de campaña</small>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" onclick="resetWizard()"></button>
      </div>
      <div class="modal-body">

        <!-- Step indicator -->
        <div class="step-indicator">
          <div class="step-dot active" id="sdot-1">1</div>
          <div class="step-line" id="sline-1"></div>
          <div class="step-dot" id="sdot-2">2</div>
          <div class="step-line" id="sline-2"></div>
          <div class="step-dot" id="sdot-3">3</div>
        </div>

        <!-- ── Paso 1: Tipo de campaña ──────────────────────── -->
        <div class="wizard-step active" id="step-1">
          <p class="text-muted small mb-3">¿Qué quieres lograr con esta campaña?</p>
          <div class="row g-3">
            <?php
            $campTypes = [
              ['id'=>'follow_up',        'icon'=>'🔔', 'name'=>'Seguimiento',           'desc'=>'Contactar leads que no han respondido',             'script'=>"Eres un agente de seguimiento de [Negocio]. Tu objetivo es contactar al prospecto {name} para darle seguimiento a su interés previo. Saluda amablemente, recuerda el contexto y pregunta en qué puedes ayudarle. Si está interesado, agenda una cita o toma sus datos."],
              ['id'=>'book_appointment', 'icon'=>'📅', 'name'=>'Agendar cita',           'desc'=>'Llamar para confirmar o agendar una cita',           'script'=>"Eres un agente de agendamiento de [Negocio]. Llamas a {name} para ofrecerle una cita con nuestros especialistas. Preséntate, explica brevemente el servicio y pregunta qué horario le viene mejor. Sé conciso y profesional."],
              ['id'=>'qualify_lead',     'icon'=>'🎯', 'name'=>'Calificar lead',         'desc'=>'Identificar qué tan listo está para comprar',        'script'=>"Eres un agente de calificación de [Negocio]. Tu objetivo es hablar con {name} para entender su necesidad, presupuesto y tiempo de decisión. Haz preguntas abiertas, escucha activamente y captura la información relevante."],
              ['id'=>'reactivate',       'icon'=>'💸', 'name'=>'Recuperar lead frío',    'desc'=>'Reactivar prospectos inactivos con una oferta',      'script'=>"Eres un agente de reactivación de [Negocio]. Llamas a {name} porque hace tiempo que no saben de ti y tienen una oferta especial para reactivar su interés: {oferta}. Sé entusiasta, menciona la oferta de entrada y pregunta si le interesa aprovecharla."],
              ['id'=>'promotion',        'icon'=>'🎁', 'name'=>'Promoción / Oferta',     'desc'=>'Comunicar una oferta o descuento a tu base',         'script'=>"Eres un agente promotor de [Negocio]. Llamas a {name} para comunicarle una promoción especial: {oferta}. Preséntate, anuncia la oferta con entusiasmo, explica los beneficios y pregunta si quiere aprovecharla."],
              ['id'=>'appointment_reminder','icon'=>'⏰','name'=>'Recordatorio de cita', 'desc'=>'Confirmar y recordar citas programadas',             'script'=>"Eres un agente de recordatorio de [Negocio]. Llamas a {name} para confirmar su cita programada. Confirma la fecha y hora, pregunta si tiene alguna duda y si necesita cancelar o reprogramar. Sé breve y amable."],
            ];
            foreach ($campTypes as $ct): ?>
            <div class="col-sm-6 col-md-4">
              <div class="camp-type-card" data-type="<?= $ct['id'] ?>"
                   data-script="<?= e($ct['script']) ?>"
                   data-has-offer="<?= in_array($ct['id'], ['reactivate','promotion']) ? '1' : '0' ?>"
                   onclick="selectCampType(this)">
                <div class="ct-icon"><?= $ct['icon'] ?></div>
                <div class="ct-name"><?= $ct['name'] ?></div>
                <div class="ct-desc"><?= $ct['desc'] ?></div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- ── Paso 2: Configuración ────────────────────────── -->
        <div class="wizard-step" id="step-2">
          <div class="row g-3">
            <div class="col-md-7">
              <label class="form-label">Nombre de la campaña *</label>
              <input class="form-control" id="campName" placeholder="Ej: Recuperación Q1 — enero 2026"/>
            </div>
            <div class="col-md-5">
              <label class="form-label">Canal *</label>
              <select class="form-select" id="campChannel" onchange="toggleWaField()">
                <option value="voice">📞 Voz (llamada)</option>
                <option value="whatsapp">💬 WhatsApp</option>
                <option value="both">📞+💬 Ambos</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Agente *</label>
              <select class="form-select" id="campAgent">
                <option value="">— Seleccionar agente —</option>
                <?php foreach ($agents as $ag): ?>
                <option value="<?= e($ag['id']) ?>"><?= e($ag['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label d-flex align-items-center">
                Llamar desde
                <span class="help-tip" data-bs-toggle="popover"
                      data-bs-title="Horario de llamadas"
                      data-bs-content="El agente <strong>no llamará antes</strong> de esta hora. Recomendado: 9 hrs para no despertar a nadie.">?</span>
              </label>
              <div class="input-group">
                <span class="input-group-text"><i class="bx bx-sun"></i></span>
                <input type="number" class="form-control" id="campHourStart" value="9" min="0" max="23"/>
                <span class="input-group-text">hrs</span>
              </div>
            </div>
            <div class="col-md-3">
              <label class="form-label d-flex align-items-center">
                Llamar hasta
                <span class="help-tip" data-bs-toggle="popover"
                      data-bs-title="Horario de llamadas"
                      data-bs-content="El agente <strong>deja de llamar</strong> después de esta hora. Recomendado: 19 hrs. Fuera del horario, la campaña pausa y retoma al día siguiente.">?</span>
              </label>
              <div class="input-group">
                <span class="input-group-text"><i class="bx bx-moon"></i></span>
                <input type="number" class="form-control" id="campHourEnd" value="19" min="1" max="24"/>
                <span class="input-group-text">hrs</span>
              </div>
            </div>
            <div class="col-md-4">
              <label class="form-label d-flex align-items-center">
                Llamadas por hora
                <span class="help-tip" data-bs-toggle="popover"
                      data-bs-title="Rate limit"
                      data-bs-content="Máximo de llamadas que el agente puede hacer en una hora. Evita saturar tu línea y parecer spam. <strong>Recomendado: 10–20/h</strong> para campañas normales.">?</span>
              </label>
              <input type="number" class="form-control" id="campRate" value="10" min="1" max="60"/>
            </div>
            <div class="col-md-4">
              <label class="form-label d-flex align-items-center">
                Máx. intentos por contacto
                <span class="help-tip" data-bs-toggle="popover"
                      data-bs-title="Reintentos"
                      data-bs-content="Si un contacto no contesta, el agente reintentará <strong>N veces</strong> (en horas distintas) antes de marcarlo como fallido y pasar al siguiente.">?</span>
              </label>
              <input type="number" class="form-control" id="campAttempts" value="2" min="1" max="5"/>
            </div>
            <div class="col-md-4">
              <label class="form-label d-flex align-items-center">
                Tipo de ejecución
                <span class="help-tip" data-bs-toggle="popover"
                      data-bs-title="Manual vs Automático"
                      data-bs-content="<strong>Manual:</strong> tú presionas Iniciar cuando quieras.<br><strong>Automático:</strong> se activa solo cuando se cumple un trigger (ej: nuevo lead sin actividad en 3 días).">?</span>
              </label>
              <select class="form-select" id="campTrigger">
                <option value="manual">👤 Manual (yo la inicio)</option>
                <option value="auto">⚡ Automático (por trigger)</option>
              </select>
            </div>

            <!-- Campo de oferta (solo para reactivación y promoción) -->
            <div class="col-12" id="offerRow" style="display:none">
              <label class="form-label">💸 Oferta / descuento a comunicar *</label>
              <div class="offer-box">
                <input class="form-control border-0 bg-transparent" id="campOffer"
                       placeholder="Ej: 20% de descuento en tu primera consulta, válido hasta el 31 de enero"/>
                <small class="text-muted mt-1 d-block">
                  El agente mencionará esta oferta de entrada en cada llamada. Sé específico: porcentaje, fecha límite, condiciones.
                </small>
              </div>
            </div>

            <!-- Template WhatsApp -->
            <div class="col-12" id="waTemplateRow" style="display:none">
              <label class="form-label">Template de WhatsApp <small class="text-muted">(nombre aprobado en Meta)</small></label>
              <input class="form-control" id="campWaTemplate" placeholder="appointment_reminder"/>
              <small class="text-muted">Obligatorio para mensajes outbound de WhatsApp (política Meta)</small>
            </div>
          </div>
        </div>

        <!-- ── Paso 3: Script y contactos ───────────────────── -->
        <div class="wizard-step" id="step-3">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label">Script del agente *</label>
              <textarea class="form-control" id="campScript" rows="6"
                        placeholder="Instrucciones de cómo debe comunicarse el agente en esta campaña..."></textarea>
              <small class="text-muted">
                Usa <code>{name}</code> para el nombre del contacto.
                <?php foreach ($campTypes as $ct): if (in_array($ct['id'], ['reactivate','promotion'])): ?>
                Usa <code>{oferta}</code> para insertar la oferta configurada.
                <?php endif; endforeach; ?>
              </small>
            </div>

            <!-- Contactos: origen -->
            <div class="col-12">
              <label class="form-label">¿Cómo agregar contactos? <span class="text-muted">(opcional — puedes hacerlo después)</span></label>
              <div class="d-flex gap-2 flex-wrap">
                <div class="form-check">
                  <input class="form-check-input" type="radio" name="contactSource" id="srcLater" value="later" checked>
                  <label class="form-check-label" for="srcLater">Después</label>
                </div>
                <div class="form-check">
                  <input class="form-check-input" type="radio" name="contactSource" id="srcLeads" value="leads">
                  <label class="form-check-label" for="srcLeads">Importar desde leads</label>
                </div>
                <div class="form-check">
                  <input class="form-check-input" type="radio" name="contactSource" id="srcCsv" value="csv">
                  <label class="form-check-label" for="srcCsv">Subir CSV</label>
                </div>
              </div>
            </div>

            <!-- Leads filter (visible cuando se elige leads) -->
            <div class="col-12" id="leadsFilterBlock" style="display:none">
              <div class="card border">
                <div class="card-body py-3">
                  <p class="section-title mb-2">Filtro de leads a importar</p>
                  <div class="row g-3">
                    <div class="col-md-6">
                      <label class="form-label form-label-sm">Estado de leads</label>
                      <select class="form-select form-select-sm" id="wizLeadsStatus" multiple>
                        <option value="new"       selected>Nuevos</option>
                        <option value="contacted" selected>Contactados</option>
                        <option value="qualified">Calificados</option>
                      </select>
                    </div>
                    <div class="col-md-6">
                      <label class="form-label form-label-sm">Sin actividad en los últimos</label>
                      <div class="input-group input-group-sm">
                        <input type="number" class="form-control" id="wizLeadsDays" value="7" min="1" max="365"/>
                        <span class="input-group-text">días</span>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- CSV upload (visible cuando se elige csv) -->
            <div class="col-12" id="csvUploadBlock" style="display:none">
              <div class="card border">
                <div class="card-body py-3">
                  <p class="section-title mb-2">Subir contactos CSV</p>
                  <div class="alert alert-secondary py-2 small mb-2">
                    <strong>Formato:</strong> columnas <code>name</code>, <code>phone</code>, <code>email</code> (email opcional).
                  </div>
                  <input type="file" class="form-control form-control-sm" id="wizCsvFile" accept=".csv,.txt"/>
                  <small class="text-muted mt-1 d-block" id="wizCsvInfo"></small>
                </div>
              </div>
            </div>

          </div>
        </div>

      </div>
      <div class="modal-footer border-0 pt-0">
        <button class="btn btn-outline-secondary me-auto" id="wizBackBtn" onclick="wizBack()" style="display:none">
          <i class="bx bx-arrow-back me-1"></i>Atrás
        </button>
        <button class="btn btn-outline-secondary" data-bs-dismiss="modal" onclick="resetWizard()">Cancelar</button>
        <button class="btn btn-primary" id="wizNextBtn" onclick="wizNext()" disabled>
          Siguiente <i class="bx bx-right-arrow-alt ms-1"></i>
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ══ Modal: Detalle de campaña ════════════════════════════ -->
<div class="modal fade" id="detailModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="detailTitle"><i class="bx bx-list-ul me-2"></i>Contactos</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <!-- Stats del detalle -->
        <div class="row g-2 mb-3" id="detailStats"></div>
        <!-- Filtro de status -->
        <div class="d-flex gap-2 mb-3 flex-wrap" id="detailFilters">
          <button class="btn btn-sm btn-primary filter-btn" data-status="">Todos</button>
          <button class="btn btn-sm btn-outline-secondary filter-btn" data-status="pending">Pendientes</button>
          <button class="btn btn-sm btn-outline-info filter-btn" data-status="calling">Llamando</button>
          <button class="btn btn-sm btn-outline-success filter-btn" data-status="contacted">Contactados</button>
          <button class="btn btn-sm btn-outline-warning filter-btn" data-status="converted">Convertidos</button>
          <button class="btn btn-sm btn-outline-danger filter-btn" data-status="failed">Fallidos</button>
        </div>
        <!-- Tabla de contactos -->
        <div id="detailBody">
          <div class="text-center py-4"><span class="spinner-border spinner-border-sm"></span> Cargando…</div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ══ Modal: Upload CSV (standalone) ══════════════════════ -->
<div class="modal fade" id="uploadModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Subir contactos CSV</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="uploadCampaignId"/>
        <div class="alert alert-info small">
          <strong>Formato:</strong> columnas <code>name</code>, <code>phone</code>, <code>email</code> (email opcional).
        </div>
        <input type="file" class="form-control" id="csvFile" accept=".csv,.txt"/>
        <small class="text-muted mt-1 d-block" id="csvInfo"></small>
      </div>
      <div class="modal-footer">
        <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-primary" id="uploadCsvBtn">
          <i class="bx bx-upload me-1"></i>Importar
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ══ Modal: Importar Leads (standalone) ══════════════════ -->
<div class="modal fade" id="leadsImportModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bx bx-user-check me-2"></i>Importar leads</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="leadsImportCampaignId"/>
        <div class="row g-3">
          <div class="col-12">
            <label class="form-label">Estado de leads a importar</label>
            <select class="form-select" id="leadsStatus" multiple>
              <option value="new"       selected>Nuevos</option>
              <option value="contacted" selected>Contactados</option>
              <option value="qualified">Calificados</option>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label">Sin actividad en los últimos N días</label>
            <div class="input-group">
              <input type="number" class="form-control" id="leadsDays" value="7" min="1" max="365"/>
              <span class="input-group-text">días</span>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-primary" id="importLeadsBtn">
          <i class="bx bx-user-check me-1"></i>Importar leads
        </button>
      </div>
    </div>
  </div>
</div>

<script>
const PROXY = '/api/campaign-proxy.php';

// ══ WIZARD ════════════════════════════════════════════════════
let wizardStep = 1;
let selectedType = null;
let selectedScript = '';
let hasOffer = false;

function selectCampType(el) {
  document.querySelectorAll('.camp-type-card').forEach(c => c.classList.remove('selected'));
  el.classList.add('selected');
  selectedType   = el.dataset.type;
  selectedScript = el.dataset.script;
  hasOffer       = el.dataset.hasOffer === '1';
  document.getElementById('wizNextBtn').disabled = false;
}

function wizNext() {
  if (wizardStep === 1) {
    if (!selectedType) return;
    goStep(2);
    // Mostrar/ocultar campo de oferta
    document.getElementById('offerRow').style.display = hasOffer ? 'block' : 'none';
    // Pre-set script
    document.getElementById('campScript').value = selectedScript;
  } else if (wizardStep === 2) {
    if (!document.getElementById('campAgent').value) {
      window.showToast?.('Selecciona un agente', 'warning'); return;
    }
    // Injecto oferta en el script si aplica
    if (hasOffer) {
      const offer = document.getElementById('campOffer').value.trim();
      if (!offer) { window.showToast?.('Ingresa la oferta o descuento a comunicar', 'warning'); return; }
      document.getElementById('campScript').value =
        document.getElementById('campScript').value.replace('{oferta}', offer);
    }
    goStep(3);
  } else if (wizardStep === 3) {
    saveCampaign();
  }
}

function wizBack() {
  if (wizardStep > 1) goStep(wizardStep - 1);
}

function goStep(n) {
  document.querySelectorAll('.wizard-step').forEach(s => s.classList.remove('active'));
  document.getElementById('step-' + n).classList.add('active');

  // Step dots
  for (let i = 1; i <= 3; i++) {
    const dot  = document.getElementById('sdot-' + i);
    const line = document.getElementById('sline-' + i);
    dot.className  = 'step-dot' + (i < n ? ' done' : i === n ? ' active' : '');
    if (line) line.className = 'step-line' + (i < n ? ' done' : '');
  }

  const subtitles = { 1: 'Elige el tipo de campaña', 2: 'Configuración', 3: 'Script y contactos' };
  document.getElementById('wizardSubtitle').textContent = subtitles[n];
  document.getElementById('wizBackBtn').style.display = n > 1 ? 'inline-flex' : 'none';
  document.getElementById('wizNextBtn').textContent = n === 3 ? '✓ Crear campaña' : 'Siguiente →';
  document.getElementById('wizNextBtn').disabled = n === 1 && !selectedType;

  // Contact source toggles
  if (n === 3) {
    document.querySelectorAll('[name=contactSource]').forEach(r => {
      r.addEventListener('change', toggleContactSource);
    });
    // Pre-select leads import for reactivation/promotion
    if (['reactivate','promotion'].includes(selectedType)) {
      document.getElementById('srcLeads').checked = true;
      document.getElementById('leadsFilterBlock').style.display = 'block';
    }
  }

  wizardStep = n;
}

function toggleContactSource() {
  const val = document.querySelector('[name=contactSource]:checked')?.value;
  document.getElementById('leadsFilterBlock').style.display = val === 'leads' ? 'block' : 'none';
  document.getElementById('csvUploadBlock').style.display   = val === 'csv'   ? 'block' : 'none';
}

function toggleWaField() {
  const ch = document.getElementById('campChannel').value;
  document.getElementById('waTemplateRow').style.display = ['whatsapp','both'].includes(ch) ? 'block' : 'none';
}

function resetWizard() {
  wizardStep = 1; selectedType = null; hasOffer = false;
  document.querySelectorAll('.camp-type-card').forEach(c => c.classList.remove('selected'));
  document.getElementById('wizNextBtn').disabled = true;
  goStep(1);
  ['campName','campScript','campOffer','campWaTemplate'].forEach(id => {
    const el = document.getElementById(id); if (el) el.value = '';
  });
  document.getElementById('campAgent').value = '';
  document.getElementById('srcLater').checked = true;
  ['leadsFilterBlock','csvUploadBlock','offerRow','waTemplateRow'].forEach(id => {
    document.getElementById(id).style.display = 'none';
  });
}

async function saveCampaign() {
  const btn = document.getElementById('wizNextBtn');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Creando…';

  const payload = {
    name:                document.getElementById('campName').value.trim(),
    channel:             document.getElementById('campChannel').value,
    agent_id:            document.getElementById('campAgent').value,
    goal:                selectedType,
    trigger_type:        document.getElementById('campTrigger').value,
    script:              document.getElementById('campScript').value.trim(),
    allowed_hours_start: parseInt(document.getElementById('campHourStart').value),
    allowed_hours_end:   parseInt(document.getElementById('campHourEnd').value),
    calls_per_hour:      parseInt(document.getElementById('campRate').value),
    max_attempts:        parseInt(document.getElementById('campAttempts').value),
    wa_template_name:    document.getElementById('campWaTemplate').value || undefined,
  };

  if (!payload.name || !payload.agent_id || !payload.script) {
    window.showToast?.('Nombre, agente y script son requeridos', 'warning');
    btn.disabled = false; btn.innerHTML = '✓ Crear campaña'; return;
  }

  const res  = await fetch(PROXY, {
    method: 'POST', headers: {'Content-Type':'application/json'},
    body: JSON.stringify({ action: 'create', ...payload }),
  });
  const data = await res.json();

  if (!res.ok) {
    window.showToast?.('Error: ' + (data.error || 'No se pudo crear'), 'danger');
    btn.disabled = false; btn.innerHTML = '✓ Crear campaña'; return;
  }

  const newId = data.id;

  // Importar contactos si el usuario eligió hacerlo ahora
  const src = document.querySelector('[name=contactSource]:checked')?.value;
  if (src === 'leads' && newId) {
    const statuses = [...document.querySelectorAll('#wizLeadsStatus option:checked')].map(o => o.value);
    const days     = parseInt(document.getElementById('wizLeadsDays').value);
    await fetch(PROXY, {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({ action:'import_leads', campaign_id: newId, lead_status: statuses, days_without_contact: days }),
    }).catch(() => {});
  } else if (src === 'csv' && newId) {
    const file = document.getElementById('wizCsvFile').files[0];
    if (file) {
      const csv = await file.text();
      await fetch(PROXY, {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ action:'upload_csv', campaign_id: newId, csv }),
      }).catch(() => {});
    }
  }

  bootstrap.Modal.getInstance(document.getElementById('newCampaignModal')).hide();
  window.showToast?.('Campaña creada correctamente', 'success');
  location.reload();
}

// ══ DETALLE DE CAMPAÑA ════════════════════════════════════════
let detailCampaignId = null;
let detailStatusFilter = '';

document.querySelectorAll('[data-bs-target="#detailModal"]').forEach(btn => {
  btn.addEventListener('click', function() {
    detailCampaignId = this.dataset.campaignId;
    document.getElementById('detailTitle').innerHTML =
      '<i class="bx bx-list-ul me-2"></i>' + (this.dataset.campaignName || 'Contactos');
    detailStatusFilter = '';
    document.querySelectorAll('.filter-btn').forEach(b => {
      b.className = b.dataset.status === '' ? 'btn btn-sm btn-primary filter-btn' : 'btn btn-sm btn-outline-secondary filter-btn';
    });
    loadContacts();
  });
});

document.querySelectorAll('.filter-btn').forEach(btn => {
  btn.addEventListener('click', function() {
    detailStatusFilter = this.dataset.status;
    document.querySelectorAll('.filter-btn').forEach(b => {
      b.className = b.dataset.status === detailStatusFilter
        ? 'btn btn-sm btn-primary filter-btn'
        : 'btn btn-sm btn-outline-secondary filter-btn';
    });
    loadContacts();
  });
});

async function loadContacts() {
  const body = document.getElementById('detailBody');
  body.innerHTML = '<div class="text-center py-4"><span class="spinner-border spinner-border-sm"></span> Cargando…</div>';

  try {
    let url = `/api/campaign-proxy.php?action=contacts&campaign_id=${detailCampaignId}`;
    if (detailStatusFilter) url += `&status=${detailStatusFilter}`;
    const res  = await fetch(url);
    const data = await res.json();

    const contacts = data.data || [];

    if (!contacts.length) {
      body.innerHTML = '<div class="text-center text-muted py-4"><i class="bx bx-user-x bx-lg opacity-25 d-block mb-2"></i>Sin contactos' + (detailStatusFilter ? ' con este filtro' : '') + '</div>';
      return;
    }

    const statusColors = { pending:'secondary', calling:'info', contacted:'success', converted:'warning', failed:'danger', skipped:'secondary' };
    const statusLabels = { pending:'Pendiente', calling:'Llamando', contacted:'Contactado', converted:'Convertido', failed:'Fallido', skipped:'Omitido' };

    body.innerHTML = `
      <div class="table-responsive">
        <table class="table table-sm align-middle">
          <thead class="table-light">
            <tr><th>Nombre</th><th>Teléfono</th><th>Estado</th><th>Intentos</th><th>Última actividad</th></tr>
          </thead>
          <tbody>
            ${contacts.map(c => `
              <tr>
                <td class="fw-semibold">${esc(c.name || '—')}</td>
                <td><code class="small">${esc(c.phone)}</code></td>
                <td><span class="badge bg-label-${statusColors[c.status]||'secondary'}">${statusLabels[c.status]||c.status}</span></td>
                <td class="text-center">${c.attempts || 0}/${c.max_attempts || '?'}</td>
                <td class="text-muted small">${c.last_attempt_at ? new Date(c.last_attempt_at).toLocaleString('es-MX',{dateStyle:'short',timeStyle:'short'}) : '—'}</td>
              </tr>`).join('')}
          </tbody>
        </table>
      </div>
      <small class="text-muted">${contacts.length} contactos mostrados</small>`;
  } catch (err) {
    body.innerHTML = '<div class="alert alert-danger">Error cargando contactos: ' + err.message + '</div>';
  }
}

// ══ ACCIONES DE CAMPAÑA — actualiza tarjeta sin reload ═══════
const STATUS_MAP = {
  running:   { cls: 'success',   label: 'En ejecución', icon: 'bx-play-circle'  },
  paused:    { cls: 'warning',   label: 'Pausada',       icon: 'bx-pause-circle' },
  cancelled: { cls: 'danger',    label: 'Cancelada',     icon: 'bx-x-circle'     },
  completed: { cls: 'primary',   label: 'Completada',    icon: 'bx-check-circle' },
  draft:     { cls: 'secondary', label: 'Borrador',      icon: 'bx-edit'         },
};

// Usa event delegation para que los botones recién insertados en el dropdown también funcionen
document.addEventListener('click', async function(e) {
  const btn = e.target.closest('.campaign-action');
  if (!btn) return;
  e.preventDefault();

  const { id, action } = btn.dataset;

  if (action === 'cancel') {
    const confirmed = await window.confirmToast?.('¿Cancelar esta campaña? Esta acción detiene todas las llamadas pendientes.', 'Sí, cancelar');
    if (!confirmed) return;
  }

  const res  = await fetch(PROXY, {
    method: 'POST', headers: {'Content-Type':'application/json'},
    body: JSON.stringify({ action, campaign_id: id }),
  });
  const data = await res.json();

  if (!res.ok) {
    window.showToast?.('Error: ' + (data.error || 'No se pudo ejecutar'), 'danger');
    return;
  }

  // Actualizar badge de estado en la tarjeta sin recargar
  const newStatus = action === 'start' ? 'running' : action === 'pause' ? 'paused' : 'cancelled';
  const sm = STATUS_MAP[newStatus];
  const card = document.querySelector(`.campaign-action[data-id="${id}"]`)?.closest('.card');
  if (card && sm) {
    // Badge
    const badge = card.querySelector('.badge[class*="bg-label-"]');
    if (badge) {
      badge.className = `badge bg-label-${sm.cls} mb-1`;
      badge.innerHTML = `<i class="bx ${sm.icon} me-1"></i>${sm.label}`;
    }
    // Barra de progreso color
    const bar = card.querySelector('.progress-bar');
    if (bar) bar.className = `progress-bar bg-${sm.cls}`;

    // Reconstruir dropdown según nuevo estado (event delegation: no necesita re-attachar listeners)
    const menu = card.querySelector('.dropdown-menu');
    if (menu) {
      const campName = esc(card.querySelector('h6')?.textContent?.trim() || '');
      let html = '';
      if (['draft','paused'].includes(newStatus)) {
        html += `<li><a class="dropdown-item campaign-action" href="#" data-id="${id}" data-action="start"><i class="bx bx-play me-2 text-success"></i>Iniciar</a></li>`;
      }
      if (newStatus === 'running') {
        html += `<li><a class="dropdown-item campaign-action" href="#" data-id="${id}" data-action="pause"><i class="bx bx-pause me-2 text-warning"></i>Pausar</a></li>`;
      }
      html += `<li><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#detailModal"
               data-campaign-id="${id}" data-campaign-name="${campName}">
          <i class="bx bx-list-ul me-2"></i>Ver contactos</a></li>
        <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#uploadModal"
               data-campaign-id="${id}">
          <i class="bx bx-upload me-2"></i>Subir contactos CSV</a></li>
        <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#leadsImportModal"
               data-campaign-id="${id}">
          <i class="bx bx-user-check me-2"></i>Importar desde Leads</a></li>`;
      if (!['completed','cancelled'].includes(newStatus)) {
        html += `<li><hr class="dropdown-divider"></li>
          <li><a class="dropdown-item campaign-action text-danger" href="#" data-id="${id}" data-action="cancel">
            <i class="bx bx-x me-2"></i>Cancelar</a></li>`;
      }
      menu.innerHTML = html;
    }
  }

  const msgs = { start: 'Campaña iniciada', pause: 'Campaña pausada', cancel: 'Campaña cancelada' };
  window.showToast?.(msgs[action] || 'Acción ejecutada', 'success');
});

// ══ CSV UPLOAD (standalone) ═══════════════════════════════════
document.querySelectorAll('[data-bs-target="#uploadModal"]').forEach(btn => {
  btn.addEventListener('click', () => {
    document.getElementById('uploadCampaignId').value = btn.dataset.campaignId;
  });
});
document.getElementById('csvFile').addEventListener('change', function() {
  document.getElementById('csvInfo').textContent = this.files[0] ? `${this.files[0].name} (${(this.files[0].size/1024).toFixed(1)} KB)` : '';
});
document.getElementById('uploadCsvBtn').addEventListener('click', async function() {
  const file = document.getElementById('csvFile').files[0];
  const id   = document.getElementById('uploadCampaignId').value;
  if (!file) { window.showToast?.('Selecciona un archivo CSV', 'warning'); return; }
  this.disabled = true;
  this.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Importando…';
  const csv = await file.text();
  const res  = await fetch(PROXY, { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ action:'upload_csv', campaign_id: id, csv }) });
  const data = await res.json();
  this.disabled = false;
  this.innerHTML = '<i class="bx bx-upload me-1"></i>Importar';
  if (res.ok) {
    bootstrap.Modal.getInstance(document.getElementById('uploadModal')).hide();
    window.showToast?.(`Importados: ${data.imported} · Omitidos: ${data.skipped}`, 'success');
    addCardContacts(id, data.imported || 0);
  } else {
    window.showToast?.('Error: ' + (data.error || 'No se pudo importar'), 'danger');
  }
});

// ══ IMPORT LEADS (standalone) ═════════════════════════════════
document.querySelectorAll('[data-bs-target="#leadsImportModal"]').forEach(btn => {
  btn.addEventListener('click', () => {
    document.getElementById('leadsImportCampaignId').value = btn.dataset.campaignId;
  });
});
document.getElementById('importLeadsBtn').addEventListener('click', async function() {
  const id       = document.getElementById('leadsImportCampaignId').value;
  const statuses = [...document.querySelectorAll('#leadsStatus option:checked')].map(o => o.value);
  const days     = parseInt(document.getElementById('leadsDays').value);
  this.disabled  = true;
  this.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Importando…';
  const res  = await fetch(PROXY, { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ action:'import_leads', campaign_id: id, lead_status: statuses, days_without_contact: days }) });
  const data = await res.json();
  this.disabled = false;
  this.innerHTML = '<i class="bx bx-user-check me-1"></i>Importar leads';
  if (res.ok) {
    bootstrap.Modal.getInstance(document.getElementById('leadsImportModal')).hide();
    window.showToast?.(`${data.imported} leads importados correctamente`, 'success');
    addCardContacts(id, data.imported || 0);
  } else {
    window.showToast?.('Error: ' + (data.error || 'No se pudo importar'), 'danger');
  }
});

// ── CSV wizard file info ──────────────────────────────────────
document.getElementById('wizCsvFile')?.addEventListener('change', function() {
  const info = document.getElementById('wizCsvInfo');
  if (info) info.textContent = this.files[0] ? `${this.files[0].name} (${(this.files[0].size/1024).toFixed(1)} KB)` : '';
});

// ── Helpers ───────────────────────────────────────────────────
function esc(s) {
  return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// Actualiza el contador "Total" en la tarjeta de campaña después de importar contactos
function addCardContacts(campaignId, delta) {
  if (!delta) return;
  const col = document.querySelector(`[data-campaign-id="${campaignId}"]`);
  if (!col) return;
  // El primer .contact-stat es "Total"
  const totalEl = col.querySelector('.contact-stat .val');
  if (totalEl) {
    const current = parseInt(totalEl.textContent) || 0;
    totalEl.textContent = current + delta;
    // Breve animación de feedback
    totalEl.style.transition = 'color .3s';
    totalEl.style.color = '#696cff';
    setTimeout(() => { totalEl.style.color = ''; }, 1200);
  }
}
</script>
