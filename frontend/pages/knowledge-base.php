<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/head.php';
require_once __DIR__ . '/../includes/sidebar.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/footer.php';

requireAuth();

$rawDocs = apiGet('/kb');
$docs    = array_values(array_filter((array)$rawDocs, 'is_array'));
$stats   = apiGet('/kb/stats');
$rawAgts = apiGet('/agents');
$agents  = array_values(array_filter((array)$rawAgts, 'is_array'));

// ¿Hay docs procesando? Para activar auto-polling en JS
$hasProcessing = !empty(array_filter($docs, fn($d) => in_array($d['status'] ?? '', ['pending','processing'])));

renderHead('Knowledge Base');
?>
<style>
  /* ── KB-specific styles ── */
  .kb-source-badge { font-size: .72rem; text-transform: uppercase; letter-spacing: .3px; }
  .chunk-card {
    border: 1px solid rgba(105,108,255,.15);
    border-radius: .5rem;
    padding: .75rem 1rem;
    font-size: .83rem;
    line-height: 1.55;
    background: rgba(105,108,255,.03);
  }
  html.dark-style .chunk-card {
    border-color: rgba(122,125,255,.15);
    background: rgba(122,125,255,.04);
  }
  .chunk-idx {
    display: inline-block;
    min-width: 22px; height: 22px;
    border-radius: 50%;
    background: rgba(105,108,255,.15);
    color: #696cff;
    text-align: center;
    font-size: .72rem;
    font-weight: 700;
    line-height: 22px;
    flex-shrink: 0;
  }
  html.dark-style .chunk-idx { background: rgba(122,125,255,.18); color: #9ea1ff; }

  .similarity-bar {
    height: 6px;
    border-radius: 3px;
    background: rgba(105,108,255,.15);
    overflow: hidden;
  }
  .similarity-fill {
    height: 100%;
    border-radius: 3px;
    background: linear-gradient(90deg, #696cff, #9155fd);
    transition: width .3s;
  }

  /* PDF drop zone */
  .drop-zone {
    border: 2px dashed rgba(105,108,255,.35);
    border-radius: .75rem;
    padding: 2rem;
    text-align: center;
    cursor: pointer;
    transition: border-color .2s, background .2s;
  }
  .drop-zone:hover, .drop-zone.drag-over {
    border-color: #696cff;
    background: rgba(105,108,255,.05);
  }
  html.dark-style .drop-zone {
    border-color: rgba(122,125,255,.3);
  }
  html.dark-style .drop-zone:hover, html.dark-style .drop-zone.drag-over {
    border-color: #7a7dff;
    background: rgba(122,125,255,.07);
  }
  .drop-zone input[type="file"] { display: none; }
  .doc-row { cursor: pointer; }
  .doc-row:hover td { background: rgba(105,108,255,.04); }
  html.dark-style .doc-row:hover td { background: rgba(122,125,255,.07); }
</style>

<div class="layout-wrapper layout-content-navbar">
<div class="layout-container">
<?php renderSidebar('knowledge'); ?>
<div class="layout-page">
<?php renderNavbar('Knowledge Base'); ?>
<div class="content-wrapper">
<div class="container-xxl flex-grow-1 container-p-y">

  <!-- Header -->
  <div class="d-flex justify-content-between align-items-center mb-4">
    <div>
      <h4 class="mb-1">Knowledge Base</h4>
      <p class="text-muted mb-0">El cerebro de memoria de tus agentes IA · búsqueda vectorial con pgvector</p>
    </div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addDocModal">
      <i class="bx bx-plus me-1"></i>Agregar documento
    </button>
  </div>

  <?php if (isAdmin() && count($docs) === 0):
    $pack = apiGet('/vertical-packs/preview');
  ?>
  <!-- ── Pack vertical (arranque rápido) ───────────────────────── -->
  <?php if (empty($pack['error'])): ?>
  <div class="card border-primary mb-4" style="border-width:2px!important" id="pack-card">
    <div class="card-body d-flex align-items-center justify-content-between flex-wrap gap-3">
      <div class="d-flex align-items-center gap-3">
        <div style="font-size:2rem">🚀</div>
        <div>
          <h6 class="mb-1">Arranque rápido: pack para <?= e($pack['label'] ?? 'tu industria') ?></h6>
          <p class="text-muted mb-0 small">
            Carga <?= count($pack['faqs'] ?? []) ?> preguntas frecuentes y un prompt base
            optimizado para tu giro. Tu bot quedará funcional en segundos.
          </p>
        </div>
      </div>
      <button class="btn btn-primary" id="btn-apply-pack">
        <i class="bx bx-download me-1"></i>Cargar pack
      </button>
    </div>
  </div>
  <?php endif; ?>
  <?php endif; ?>

  <!-- KPI Cards -->
  <div class="row g-3 mb-4">
    <?php
    $kpis = [
      ['label'=>'Documentos',    'val'=> (int)($stats['documents']    ?? 0), 'icon'=>'bx-file',      'color'=>'primary'],
      ['label'=>'Chunks',        'val'=> (int)($stats['chunks']        ?? 0), 'icon'=>'bx-grid-alt',  'color'=>'info'],
      ['label'=>'Tokens totales','val'=> number_format((int)($stats['total_tokens'] ?? 0)), 'icon'=>'bx-code-block','color'=>'success'],
      ['label'=>'Tipos de fuente','val'=> (int)($stats['source_types'] ?? 0), 'icon'=>'bx-category',  'color'=>'warning'],
    ];
    foreach ($kpis as $k): ?>
    <div class="col-6 col-md-3">
      <div class="card text-center card-kpi">
        <div class="card-body py-3">
          <div class="avatar mx-auto mb-2">
            <span class="avatar-initial rounded bg-label-<?= $k['color'] ?>">
              <i class="bx <?= $k['icon'] ?>"></i>
            </span>
          </div>
          <div class="h4 mb-0"><?= $k['val'] ?></div>
          <small class="text-muted"><?= $k['label'] ?></small>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="row g-4">

    <!-- ── Columna izquierda: lista de documentos ── -->
    <div class="col-xl-7">

      <!-- Filtros -->
      <div class="card mb-3">
        <div class="card-body py-2">
          <div class="d-flex flex-wrap gap-2 align-items-center">
            <div class="input-group input-group-sm" style="max-width:200px">
              <span class="input-group-text"><i class="bx bx-search"></i></span>
              <input type="text" id="kb-search" class="form-control" placeholder="Buscar título…">
            </div>
            <select id="kb-filter-type" class="form-select form-select-sm" style="max-width:120px">
              <option value="">Todos los tipos</option>
              <option value="text">Texto</option>
              <option value="pdf">PDF</option>
              <option value="url">URL</option>
              <option value="faq">FAQ</option>
            </select>
            <select id="kb-filter-status" class="form-select form-select-sm" style="max-width:130px">
              <option value="">Todos los estados</option>
              <option value="ready">Listo</option>
              <option value="processing">Procesando</option>
              <option value="pending">Pendiente</option>
              <option value="error">Error</option>
            </select>
            <button class="btn btn-sm btn-outline-secondary" id="btn-kb-clear">
              <i class="bx bx-x"></i>
            </button>
            <button class="btn btn-sm btn-outline-secondary ms-auto" id="reingestAllBtn" title="Re-procesar pendientes y con error">
              <i class="bx bx-refresh me-1"></i>Re-procesar
            </button>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="table-responsive">
          <table class="table table-hover mb-0" id="kb-table">
            <thead class="table-light">
              <tr>
                <th>Documento</th>
                <th>Estado</th>
                <th>Chunks</th>
                <th class="text-end">Acciones</th>
              </tr>
            </thead>
            <tbody id="kb-tbody">
              <?php if (empty($docs)): ?>
                <tr id="row-empty-kb">
                  <td colspan="4" class="text-center text-muted py-5">
                    <i class="bx bx-book-open bx-lg d-block mb-2 opacity-25"></i>
                    Sin documentos. Agrega tu primer FAQ, texto o PDF.
                  </td>
                </tr>
              <?php else: foreach ($docs as $doc):
                $statusColors = ['ready'=>'success','processing'=>'warning','pending'=>'secondary','error'=>'danger'];
                $sColor = $statusColors[$doc['status'] ?? 'pending'] ?? 'secondary';
                $typeIcons = ['text'=>'bx-file-blank','pdf'=>'bx-file-pdf','url'=>'bx-link','faq'=>'bx-help-circle'];
                $icon = $typeIcons[$doc['file_type'] ?? 'text'] ?? 'bx-file';
              ?>
                <tr class="doc-row kb-row"
                    data-id="<?= e($doc['id']) ?>"
                    data-title="<?= e(strtolower($doc['title'] ?? '')) ?>"
                    data-type="<?= e($doc['file_type'] ?? 'text') ?>"
                    data-status="<?= e($doc['status'] ?? 'pending') ?>"
                    onclick="viewDoc('<?= e($doc['id']) ?>')"
                    title="Ver documento">
                  <td>
                    <div class="d-flex align-items-center gap-2">
                      <i class="bx <?= $icon ?> text-muted fs-5"></i>
                      <div>
                        <div class="fw-semibold"><?= e($doc['title'] ?? '—') ?></div>
                        <?php if (!empty($doc['source_url'])): ?>
                          <small class="text-muted"><?= e(substr($doc['source_url'], 0, 50)) ?>…</small>
                        <?php elseif (!empty($doc['agent_id'])): ?>
                          <small class="text-muted"><i class="bx bx-bot me-1"></i>Agente específico</small>
                        <?php else: ?>
                          <small class="text-muted">Todos los agentes</small>
                        <?php endif; ?>
                      </div>
                    </div>
                  </td>
                  <td>
                    <span class="badge bg-label-<?= $sColor ?> kb-status-badge" data-doc-id="<?= e($doc['id']) ?>">
                      <?php if (in_array($doc['status'], ['processing','pending'])): ?>
                        <span class="spinner-border spinner-border-sm me-1" style="width:.55rem;height:.55rem"></span>
                      <?php endif; ?>
                      <?= ucfirst(e($doc['status'] ?? 'pending')) ?>
                    </span>
                  </td>
                  <td>
                    <span class="text-muted kb-chunk-count" data-doc-id="<?= e($doc['id']) ?>">
                      <?= (int)($doc['chunk_count'] ?? 0) ?>
                    </span>
                  </td>
                  <td class="text-end" onclick="event.stopPropagation()">
                    <div class="d-flex gap-1 justify-content-end">
                      <button class="btn btn-sm btn-icon btn-outline-warning reingest-btn"
                              data-id="<?= e($doc['id']) ?>" title="Re-procesar">
                        <i class="bx bx-refresh"></i>
                      </button>
                      <button class="btn btn-sm btn-icon btn-outline-danger delete-doc-btn"
                              data-id="<?= e($doc['id']) ?>" data-title="<?= e($doc['title'] ?? '') ?>" title="Eliminar">
                        <i class="bx bx-trash"></i>
                      </button>
                    </div>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
          <div id="kb-no-results" class="text-center text-muted py-4 d-none">
            <i class="bx bx-search-alt bx-lg d-block mb-2 opacity-25"></i>Sin documentos para los filtros seleccionados
          </div>
        </div>
      </div>

    </div><!-- /col-xl-7 -->

    <!-- ── Columna derecha: búsqueda semántica ── -->
    <div class="col-xl-5">
      <div class="card h-100">
        <div class="card-header">
          <h6 class="mb-0"><i class="bx bx-search-alt-2 text-primary me-2"></i>Probar búsqueda semántica</h6>
        </div>
        <div class="card-body">
          <p class="text-muted small mb-3">Simula cómo el agente recupera contexto de la KB para responder una pregunta.</p>
          <div class="input-group mb-3">
            <input type="text" id="searchQuery" class="form-control"
                   placeholder="Ej: ¿Cuánto cuesta una limpieza dental?"
                   onkeydown="if(event.key==='Enter') doSearch()">
            <button class="btn btn-outline-primary" id="searchBtn" onclick="doSearch()">
              <i class="bx bx-search"></i>
            </button>
          </div>
          <div class="mb-3">
            <label class="form-label small text-muted">Similitud mínima</label>
            <div class="d-flex align-items-center gap-2">
              <input type="range" class="form-range flex-grow-1" id="minSimilarity"
                     min="0.4" max="0.95" step="0.05" value="0.60">
              <span class="badge bg-label-primary" id="simLabel">60%</span>
            </div>
          </div>
          <div id="searchResults"></div>
        </div>
      </div>
    </div>

  </div><!-- /row -->

</div><!-- /container -->
<?php renderFooter(); ?>

<!-- ══ Modal: Agregar documento ══ -->
<div class="modal fade" id="addDocModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header border-0 pb-0">
        <h5 class="modal-title"><i class="bx bx-plus-circle text-primary me-2"></i>Agregar a la Knowledge Base</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">

        <!-- Tabs -->
        <ul class="nav nav-tabs mb-3" id="docTypeTabs">
          <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tab-text">
            <i class="bx bx-file-blank me-1"></i>Texto
          </a></li>
          <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-pdf">
            <i class="bx bx-file-pdf me-1"></i>PDF
          </a></li>
          <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-url">
            <i class="bx bx-link me-1"></i>URL
          </a></li>
          <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-faq">
            <i class="bx bx-help-circle me-1"></i>FAQ
          </a></li>
        </ul>

        <div class="tab-content">

          <!-- Texto -->
          <div class="tab-pane fade show active" id="tab-text">
            <div class="mb-3">
              <label class="form-label">Título <span class="text-danger">*</span></label>
              <input class="form-control" id="textTitle" placeholder="Ej: Servicios y precios">
            </div>
            <div class="mb-3">
              <label class="form-label">Contenido <span class="text-danger">*</span></label>
              <textarea class="form-control" id="textContent" rows="9"
                        placeholder="Pega aquí el texto de tu catálogo, políticas, información de servicios…&#10;&#10;El sistema dividirá el texto en fragmentos y generará embeddings para búsqueda semántica."></textarea>
              <small class="text-muted d-block mt-1"><span id="textCharCount">0</span> caracteres</small>
            </div>
          </div>

          <!-- PDF -->
          <div class="tab-pane fade" id="tab-pdf">
            <div class="mb-3">
              <label class="form-label">Título <span class="text-danger">*</span></label>
              <input class="form-control" id="pdfTitle" placeholder="Ej: Manual de servicios">
            </div>
            <div class="mb-3">
              <label class="form-label">Archivo PDF <span class="text-danger">*</span></label>
              <div class="drop-zone" id="pdfDropZone">
                <input type="file" id="pdfFile" accept=".pdf,application/pdf">
                <i class="bx bx-cloud-upload bx-lg text-primary mb-2 d-block"></i>
                <p class="mb-1 fw-semibold">Arrastra un PDF aquí</p>
                <p class="text-muted small mb-2">o haz clic para seleccionar</p>
                <button type="button" class="btn btn-sm btn-outline-primary"
                        onclick="document.getElementById('pdfFile').click()">
                  Seleccionar archivo
                </button>
              </div>
              <div id="pdfFileInfo" class="d-none mt-2">
                <div class="alert alert-info py-2 mb-0 d-flex align-items-center gap-2">
                  <i class="bx bx-file-pdf text-danger fs-5"></i>
                  <span id="pdfFileName" class="fw-semibold"></span>
                  <span class="text-muted small ms-auto" id="pdfFileSize"></span>
                  <button type="button" class="btn btn-sm btn-icon btn-outline-secondary ms-2" onclick="clearPdf()">
                    <i class="bx bx-x"></i>
                  </button>
                </div>
              </div>
            </div>
          </div>

          <!-- URL -->
          <div class="tab-pane fade" id="tab-url">
            <div class="mb-3">
              <label class="form-label">Título <span class="text-danger">*</span></label>
              <input class="form-control" id="urlTitle" placeholder="Ej: Sitio web del negocio">
            </div>
            <div class="mb-3">
              <label class="form-label">URL <span class="text-danger">*</span></label>
              <input class="form-control" id="urlContent" type="url"
                     placeholder="https://tudominio.com/servicios">
              <small class="text-muted">El sistema descargará y procesará el contenido de esa página.</small>
            </div>
          </div>

          <!-- FAQ -->
          <div class="tab-pane fade" id="tab-faq">
            <div class="mb-3">
              <label class="form-label">Título del FAQ <span class="text-danger">*</span></label>
              <input class="form-control" id="faqTitle" placeholder="Ej: Preguntas frecuentes sobre servicios">
            </div>
            <div id="faqPairs">
              <div class="faq-pair border rounded p-3 mb-2">
                <div class="d-flex justify-content-between align-items-center mb-2">
                  <span class="badge bg-label-primary small">Pregunta 1</span>
                  <button type="button" class="btn btn-sm btn-icon btn-outline-danger remove-faq-btn" style="display:none">
                    <i class="bx bx-x"></i>
                  </button>
                </div>
                <div class="mb-2">
                  <input class="form-control form-control-sm faq-question" placeholder="¿Cuánto cuesta una consulta?">
                </div>
                <textarea class="form-control form-control-sm faq-answer" rows="2"
                          placeholder="La consulta inicial cuesta $X e incluye…"></textarea>
              </div>
            </div>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="addFaqPair">
              <i class="bx bx-plus me-1"></i>Agregar pregunta
            </button>
          </div>

        </div>

        <!-- Agente específico -->
        <div class="mt-3 pt-3 border-top">
          <label class="form-label small fw-semibold">Aplicar a agente (opcional)</label>
          <select class="form-select form-select-sm" id="docAgentId">
            <option value="">Todos los agentes</option>
            <?php foreach ($agents as $ag): ?>
              <option value="<?= e($ag['id']) ?>"><?= e($ag['name']) ?> (<?= e($ag['channel']) ?>)</option>
            <?php endforeach; ?>
          </select>
          <small class="text-muted">Si no seleccionas, el documento estará disponible para todos los agentes.</small>
        </div>

        <div id="save-doc-error" class="alert alert-danger mt-3 d-none"></div>
      </div>
      <div class="modal-footer border-0 pt-0">
        <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-primary" id="saveDocBtn">
          <span id="saveDocSpinner" class="spinner-border spinner-border-sm me-1 d-none"></span>
          <span id="saveDocText">Guardar y procesar</span>
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ══ Modal: Ver / editar documento ══ -->
<div class="modal fade" id="viewDocModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header border-0 pb-0">
        <h5 class="modal-title d-flex align-items-center gap-2">
          <i class="bx bx-file-blank text-primary" id="view-doc-icon"></i>
          <span id="view-doc-title">Documento</span>
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <!-- Vista detalle -->
      <div id="view-doc-detail">
        <div class="modal-body">
          <div class="row g-3 mb-3">
            <div class="col-4">
              <small class="text-muted d-block">Tipo</small>
              <span id="view-doc-type" class="badge bg-label-secondary kb-source-badge">—</span>
            </div>
            <div class="col-4">
              <small class="text-muted d-block">Estado</small>
              <span id="view-doc-status">—</span>
            </div>
            <div class="col-4">
              <small class="text-muted d-block">Chunks</small>
              <span id="view-doc-chunks" class="fw-semibold">—</span>
            </div>
            <div class="col-12" id="view-doc-url-row" style="display:none">
              <small class="text-muted d-block">URL fuente</small>
              <a id="view-doc-url" href="#" target="_blank" class="small text-truncate d-block">—</a>
            </div>
          </div>

          <!-- Chunks del documento -->
          <div id="view-chunks-section">
            <div class="d-flex justify-content-between align-items-center mb-2">
              <small class="fw-semibold text-muted">Fragmentos (chunks)</small>
              <small class="text-muted" id="view-chunks-tokens"></small>
            </div>
            <div id="view-chunks-list" class="d-flex flex-column gap-2"></div>
            <div id="view-chunks-empty" class="text-center text-muted py-3 small d-none">
              <i class="bx bx-info-circle me-1"></i>Sin chunks — el documento puede estar procesando o haber tenido un error.
            </div>
          </div>

          <div id="view-doc-error-msg" class="alert alert-danger mt-3 d-none"></div>
        </div>
        <div class="modal-footer border-0 pt-0 justify-content-between">
          <div>
            <button class="btn btn-sm btn-outline-warning me-1" id="view-reingest-btn">
              <i class="bx bx-refresh me-1"></i>Re-procesar
            </button>
            <button class="btn btn-sm btn-outline-danger" id="view-delete-btn">
              <i class="bx bx-trash me-1"></i>Eliminar
            </button>
          </div>
          <button class="btn btn-sm btn-outline-primary" id="view-edit-btn">
            <i class="bx bx-edit me-1"></i>Editar título
          </button>
        </div>
      </div>

      <!-- Modo edición -->
      <div id="view-doc-edit" style="display:none">
        <form id="form-edit-doc">
          <div class="modal-body">
            <div class="mb-3">
              <label class="form-label">Título</label>
              <input type="text" class="form-control" id="edit-doc-title" required>
            </div>
            <div class="mb-3" id="edit-content-row">
              <label class="form-label">Contenido</label>
              <textarea class="form-control" id="edit-doc-content" rows="8"
                        placeholder="Editar contenido del documento…"></textarea>
              <small class="text-muted">Si modificas el contenido, el documento se re-procesará automáticamente.</small>
            </div>
            <div id="edit-doc-error" class="alert alert-danger d-none"></div>
          </div>
          <div class="modal-footer border-0 pt-0">
            <button type="button" class="btn btn-outline-secondary btn-sm" id="btn-cancel-doc-edit">
              <i class="bx bx-arrow-back me-1"></i>Volver
            </button>
            <button type="submit" class="btn btn-primary btn-sm">
              <span id="edit-doc-btn-text"><i class="bx bx-save me-1"></i>Guardar</span>
              <span id="edit-doc-spinner" class="d-none"><span class="spinner-border spinner-border-sm me-1"></span>Guardando…</span>
            </button>
          </div>
        </form>
      </div>

    </div>
  </div>
</div>

<script>
'use strict';

// ── Estado ────────────────────────────────────────────────────
let activeDocId = null;

const typeIcons = { text:'bx-file-blank', pdf:'bx-file-pdf', url:'bx-link', faq:'bx-help-circle' };
const statusColors = { ready:'success', processing:'warning', pending:'secondary', error:'danger' };

// ── Filtros de lista ──────────────────────────────────────────
(function() {
  const searchEl  = document.getElementById('kb-search');
  const typeEl    = document.getElementById('kb-filter-type');
  const statusEl  = document.getElementById('kb-filter-status');
  const noRes     = document.getElementById('kb-no-results');

  function applyFilters() {
    const q      = (searchEl.value || '').toLowerCase();
    const type   = typeEl.value   || '';
    const status = statusEl.value || '';
    const rows   = document.querySelectorAll('.kb-row');
    let visible  = 0;

    rows.forEach(r => {
      const match = (!q      || r.dataset.title.includes(q))
                 && (!type   || r.dataset.type   === type)
                 && (!status || r.dataset.status === status);
      r.style.display = match ? '' : 'none';
      if (match) visible++;
    });

    if (noRes) noRes.classList.toggle('d-none', visible > 0 || rows.length === 0);
  }

  [searchEl, typeEl, statusEl].forEach(el => el?.addEventListener('input', applyFilters));
  document.getElementById('btn-kb-clear').addEventListener('click', () => {
    searchEl.value = ''; typeEl.value = ''; statusEl.value = '';
    applyFilters();
  });
})();

// ── Búsqueda semántica ────────────────────────────────────────
const simSlider = document.getElementById('minSimilarity');
const simLabel  = document.getElementById('simLabel');
simSlider.addEventListener('input', () => {
  simLabel.textContent = Math.round(simSlider.value * 100) + '%';
});

async function doSearch() {
  const q = document.getElementById('searchQuery').value.trim();
  if (!q) return;

  const btn = document.getElementById('searchBtn');
  btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
  btn.disabled  = true;

  const min = simSlider.value;
  const res  = await fetch(`/api/kb-search.php?q=${encodeURIComponent(q)}&min=${encodeURIComponent(min)}`);
  const data = await res.json();

  btn.innerHTML = '<i class="bx bx-search"></i>';
  btn.disabled  = false;

  const container = document.getElementById('searchResults');

  if (!data.found || !data.chunks?.length) {
    container.innerHTML = `
      <div class="alert alert-warning py-2 mb-0">
        <i class="bx bx-info-circle me-1"></i>
        Sin resultados con similitud ≥ ${Math.round(min*100)}%. Prueba bajar el umbral o reformular la pregunta.
      </div>`;
    return;
  }

  container.innerHTML = `
    <div class="small text-muted mb-2">
      ${data.chunks.length} fragmento(s) encontrado(s) · fuentes: <strong>${[...new Set(data.chunks.map(c=>c.document_title).filter(Boolean))].join(', ')}</strong>
    </div>
    ${data.chunks.map((c, i) => {
      const sim    = Math.round((c.similarity || 0) * 100);
      const source = c.document_title || 'Documento';
      const head   = c.heading ? ` · ${c.heading}` : '';
      return `
        <div class="chunk-card mb-2">
          <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
            <small class="text-muted fw-semibold">${source}${head}</small>
            <div class="text-end flex-shrink-0" style="min-width:64px">
              <span class="badge bg-label-${sim>=75?'success':sim>=60?'warning':'secondary'} mb-1">${sim}%</span>
              <div class="similarity-bar"><div class="similarity-fill" style="width:${sim}%"></div></div>
            </div>
          </div>
          <p class="mb-0 small">${escapeHtml(c.content.substring(0, 350))}${c.content.length>350?'…':''}</p>
        </div>`;
    }).join('')}`;
}

document.getElementById('searchQuery').addEventListener('keydown', e => {
  if (e.key === 'Enter') doSearch();
});

// ── Ver documento ─────────────────────────────────────────────
async function viewDoc(id) {
  activeDocId = id;
  setDocEditMode(false);
  document.getElementById('view-doc-title').textContent = 'Cargando…';
  document.getElementById('view-chunks-list').innerHTML = '';
  document.getElementById('view-chunks-empty').classList.add('d-none');
  document.getElementById('view-doc-error-msg').classList.add('d-none');

  const modal = new bootstrap.Modal(document.getElementById('viewDocModal'));
  modal.show();

  try {
    const res  = await fetch(`/api/kb-get.php?id=${encodeURIComponent(id)}`);
    const doc  = await res.json();
    if (!res.ok) throw new Error(doc.error || 'Error al cargar');

    const icon = typeIcons[doc.file_type] || 'bx-file';
    document.getElementById('view-doc-icon').className  = `bx ${icon} text-primary fs-5`;
    document.getElementById('view-doc-title').textContent = doc.title || '—';
    document.getElementById('view-doc-type').textContent  = (doc.file_type || '').toUpperCase();
    document.getElementById('view-doc-chunks').textContent = doc.chunks?.length ?? 0;

    const sColor = statusColors[doc.status] || 'secondary';
    document.getElementById('view-doc-status').innerHTML =
      `<span class="badge bg-label-${sColor}">${(doc.status||'').charAt(0).toUpperCase()+(doc.status||'').slice(1)}</span>`;

    const urlRow = document.getElementById('view-doc-url-row');
    if (doc.source_url) {
      document.getElementById('view-doc-url').href        = doc.source_url;
      document.getElementById('view-doc-url').textContent = doc.source_url;
      urlRow.style.display = '';
    } else {
      urlRow.style.display = 'none';
    }

    // Chunks
    const chunkList  = document.getElementById('view-chunks-list');
    const chunkEmpty = document.getElementById('view-chunks-empty');
    const tokensEl   = document.getElementById('view-chunks-tokens');

    if (doc.chunks?.length) {
      const totalTokens = doc.chunks.reduce((s, c) => s + (c.token_count || 0), 0);
      tokensEl.textContent = `${doc.chunks.length} chunks · ~${totalTokens} tokens`;
      chunkList.innerHTML = doc.chunks.map(c => `
        <div class="d-flex gap-2 align-items-start chunk-card">
          <span class="chunk-idx">${c.chunk_index + 1}</span>
          <div class="flex-grow-1 min-w-0">
            ${c.heading ? `<small class="text-muted fw-semibold d-block mb-1">${escapeHtml(c.heading)}</small>` : ''}
            <span class="small">${escapeHtml(c.content.substring(0, 280))}${c.content.length>280?'…':''}</span>
            <small class="text-muted d-block mt-1">${c.token_count} tokens</small>
          </div>
        </div>`).join('');
      chunkEmpty.classList.add('d-none');
    } else {
      chunkList.innerHTML = '';
      chunkEmpty.classList.remove('d-none');
      tokensEl.textContent = '';
    }

    // Pre-rellenar form de edición
    document.getElementById('edit-doc-title').value   = doc.title || '';
    document.getElementById('edit-doc-content').value = doc.content || '';
    // Ocultar área de contenido para URL/PDF (solo se puede editar el título)
    document.getElementById('edit-content-row').style.display =
      ['url','pdf'].includes(doc.file_type) ? 'none' : '';

  } catch(err) {
    document.getElementById('view-doc-title').textContent = 'Error';
    document.getElementById('view-doc-error-msg').textContent = err.message;
    document.getElementById('view-doc-error-msg').classList.remove('d-none');
  }
}

// ── Toggle edición en view modal ──────────────────────────────
function setDocEditMode(editing) {
  document.getElementById('view-doc-detail').style.display = editing ? 'none' : '';
  document.getElementById('view-doc-edit').style.display   = editing ? '' : 'none';
  document.getElementById('edit-doc-error').classList.add('d-none');
}
document.getElementById('view-edit-btn').addEventListener('click', () => setDocEditMode(true));
document.getElementById('btn-cancel-doc-edit').addEventListener('click', () => setDocEditMode(false));

// ── Guardar edición de doc ────────────────────────────────────
document.getElementById('form-edit-doc').addEventListener('submit', async function(e) {
  e.preventDefault();
  if (!activeDocId) return;

  const body = { id: activeDocId, title: document.getElementById('edit-doc-title').value };
  const content = document.getElementById('edit-doc-content').value;
  if (content && document.getElementById('edit-content-row').style.display !== 'none') {
    body.content = content;
  }

  const errDiv = document.getElementById('edit-doc-error');
  document.getElementById('edit-doc-btn-text').classList.add('d-none');
  document.getElementById('edit-doc-spinner').classList.remove('d-none');
  errDiv.classList.add('d-none');

  try {
    const res  = await fetch('/api/kb-update.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Error al guardar');

    bootstrap.Modal.getInstance(document.getElementById('viewDocModal'))?.hide();

    // Actualizar título en la fila de la tabla
    const row = document.querySelector(`tr.kb-row[data-id="${activeDocId}"]`);
    if (row) {
      const nameEl = row.querySelector('.fw-semibold');
      if (nameEl) nameEl.textContent = body.title;
      row.dataset.title = body.title.toLowerCase();
    }
    // Si el contenido cambió, marcar como pending (se re-procesa)
    if (body.content) setDocStatusBadge(activeDocId, 'pending');

    window.showToast('<i class="bx bx-save me-1"></i>Documento actualizado', 'success');
  } catch(err) {
    errDiv.textContent = err.message;
    errDiv.classList.remove('d-none');
  } finally {
    document.getElementById('edit-doc-btn-text').classList.remove('d-none');
    document.getElementById('edit-doc-spinner').classList.add('d-none');
  }
});

// ── Re-ingestar desde view modal ──────────────────────────────
document.getElementById('view-reingest-btn').addEventListener('click', async () => {
  if (!activeDocId) return;
  await reingestDoc(activeDocId);
  bootstrap.Modal.getInstance(document.getElementById('viewDocModal'))?.hide();
});

// ── Eliminar desde view modal ─────────────────────────────────
document.getElementById('view-delete-btn').addEventListener('click', async () => {
  if (!activeDocId) return;
  const title = document.getElementById('view-doc-title').textContent;
  const ok = await confirmToast(
    `<i class="bx bx-trash me-1"></i>¿Eliminar <strong>${escapeHtml(title)}</strong> y todos sus chunks?`
  );
  if (!ok) return;
  await fetch('/api/kb-delete.php', {
    method: 'POST', headers: {'Content-Type':'application/json'},
    body: JSON.stringify({ id: activeDocId }),
  });
  bootstrap.Modal.getInstance(document.getElementById('viewDocModal'))?.hide();
  document.querySelector(`tr.kb-row[data-id="${activeDocId}"]`)?.remove();
  updateDocKpi();
  window.showToast('<i class="bx bx-check-circle me-1"></i>Documento eliminado', 'success');
});

// ── Helpers compartidos ───────────────────────────────────────
// confirmToast → window.confirmToast (definido en dashboard.js)
const confirmToast = (...args) => window.confirmToast?.(...args);

/** Construye HTML de una fila de documento para insertarla en la tabla */
function buildDocRow(doc) {
  const tIcons  = { text:'bx-file-blank', pdf:'bx-file-pdf', url:'bx-link', faq:'bx-help-circle' };
  const icon    = tIcons[doc.file_type] || 'bx-file';
  const sColor  = statusColors[doc.status] || 'secondary';
  const spinner = ['processing','pending'].includes(doc.status)
    ? '<span class="spinner-border spinner-border-sm me-1" style="width:.55rem;height:.55rem"></span>' : '';
  const label   = (doc.status || 'pending').charAt(0).toUpperCase() + (doc.status || '').slice(1);
  const src     = doc.source_url
    ? `<small class="text-muted">${doc.source_url.substring(0,50)}…</small>`
    : `<small class="text-muted">Todos los agentes</small>`;

  const tr = document.createElement('tr');
  tr.className = 'doc-row kb-row';
  tr.dataset.id     = doc.id;
  tr.dataset.title  = (doc.title || '').toLowerCase();
  tr.dataset.type   = doc.file_type || 'text';
  tr.dataset.status = doc.status || 'pending';
  tr.setAttribute('onclick', `viewDoc('${doc.id}')`);
  tr.title = 'Ver documento';
  tr.innerHTML = `
    <td>
      <div class="d-flex align-items-center gap-2">
        <i class="bx ${icon} text-muted fs-5"></i>
        <div>
          <div class="fw-semibold">${escapeHtml(doc.title || '—')}</div>
          ${src}
        </div>
      </div>
    </td>
    <td>
      <span class="badge bg-label-${sColor} kb-status-badge" data-doc-id="${doc.id}">
        ${spinner}${label}
      </span>
    </td>
    <td><span class="text-muted kb-chunk-count" data-doc-id="${doc.id}">${doc.chunk_count || 0}</span></td>
    <td class="text-end" onclick="event.stopPropagation()">
      <div class="d-flex gap-1 justify-content-end">
        <button class="btn btn-sm btn-icon btn-outline-warning reingest-single-btn"
                data-id="${doc.id}" title="Re-procesar">
          <i class="bx bx-refresh"></i>
        </button>
        <button class="btn btn-sm btn-icon btn-outline-danger delete-doc-btn"
                data-id="${doc.id}" data-title="${escapeHtml(doc.title || '')}" title="Eliminar">
          <i class="bx bx-trash"></i>
        </button>
      </div>
    </td>`;

  // Agregar listener al nuevo botón reingest
  tr.querySelector('.reingest-single-btn').addEventListener('click', function(e) {
    e.stopPropagation();
    reingestDoc(this.dataset.id);
  });
  // Agregar listener al nuevo botón delete
  tr.querySelector('.delete-doc-btn').addEventListener('click', function(e) {
    e.stopPropagation();
    deleteDocFromTable(this.dataset.id, this.dataset.title);
  });
  return tr;
}

/** Marca un badge de estado como pendiente/procesando (para reingest sin reload) */
function setDocStatusBadge(docId, status) {
  const badge = document.querySelector(`.kb-status-badge[data-doc-id="${docId}"]`);
  const sColor = statusColors[status] || 'secondary';
  const spinner = ['processing','pending'].includes(status)
    ? '<span class="spinner-border spinner-border-sm me-1" style="width:.55rem;height:.55rem"></span>' : '';
  const label = status.charAt(0).toUpperCase() + status.slice(1);
  if (badge) {
    badge.className = `badge bg-label-${sColor} kb-status-badge`;
    badge.innerHTML = `${spinner}${label}`;
    const row = badge.closest('tr');
    if (row) row.dataset.status = status;
  }
  startPolling(); // Asegura que el polling está activo
}

/** Actualiza el KPI de documentos en las cards superiores */
function updateDocKpi() {
  const count = document.querySelectorAll('tr.kb-row').length;
  const kpiEl = document.querySelector('.card-kpi .h4');
  if (kpiEl) kpiEl.textContent = count;
}

// ── Re-ingestar un doc (tabla o modal) ───────────────────────
async function reingestDoc(id) {
  await fetch('/api/kb-reingest.php', {
    method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({ id }),
  });
  setDocStatusBadge(id, 'pending');
  window.showToast('<i class="bx bx-refresh me-1"></i>Re-procesamiento iniciado', 'info');
}

// ── Eliminar desde tabla ──────────────────────────────────────
async function deleteDocFromTable(id, title) {
  const ok = await confirmToast(
    `<i class="bx bx-trash me-1"></i>¿Eliminar <strong>${escapeHtml(title)}</strong> y todos sus chunks?`
  );
  if (!ok) return;
  await fetch('/api/kb-delete.php', {
    method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({ id }),
  });
  document.querySelector(`tr.kb-row[data-id="${id}"]`)?.remove();
  updateDocKpi();
  window.showToast('<i class="bx bx-check-circle me-1"></i>Documento eliminado', 'success');
}

// Conectar botones existentes (PHP-rendered)
document.querySelectorAll('.reingest-btn').forEach(btn => {
  btn.addEventListener('click', e => { e.stopPropagation(); reingestDoc(btn.dataset.id); });
});
document.querySelectorAll('.delete-doc-btn').forEach(btn => {
  btn.addEventListener('click', e => { e.stopPropagation(); deleteDocFromTable(btn.dataset.id, btn.dataset.title); });
});

// ── Re-procesar todos ─────────────────────────────────────────
document.getElementById('reingestAllBtn').addEventListener('click', async function() {
  this.disabled = true;
  this.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Procesando…';
  await fetch('/api/kb-reingest.php', {
    method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({ all: true }),
  });
  // Marcar todos los docs no-ready como pending en el DOM
  document.querySelectorAll('.kb-status-badge').forEach(badge => {
    const row = badge.closest('tr');
    if (row && row.dataset.status !== 'ready') {
      const docId = badge.dataset.docId;
      setDocStatusBadge(docId, 'pending');
    }
  });
  window.showToast('<i class="bx bx-refresh me-1"></i>Re-procesamiento en curso…', 'info');
  this.disabled = false;
  this.innerHTML = '<i class="bx bx-refresh me-1"></i>Re-procesar';
});

// ── PDF: drag & drop ──────────────────────────────────────────
(function() {
  const zone     = document.getElementById('pdfDropZone');
  const input    = document.getElementById('pdfFile');
  const infoDiv  = document.getElementById('pdfFileInfo');
  const nameSpan = document.getElementById('pdfFileName');
  const sizeSpan = document.getElementById('pdfFileSize');

  function showFile(file) {
    if (!file || file.type !== 'application/pdf') {
      window.showToast('Solo se aceptan archivos PDF', 'danger'); return;
    }
    if (file.size > 10 * 1024 * 1024) {
      window.showToast('El PDF no puede superar 10 MB', 'danger'); return;
    }
    nameSpan.textContent = file.name;
    sizeSpan.textContent = (file.size / 1024).toFixed(0) + ' KB';
    infoDiv.classList.remove('d-none');
    zone.style.display = 'none';
    // Auto-fill título si está vacío
    if (!document.getElementById('pdfTitle').value) {
      document.getElementById('pdfTitle').value = file.name.replace(/\.pdf$/i, '');
    }
  }

  zone.addEventListener('click', () => input.click());
  input.addEventListener('change', () => input.files[0] && showFile(input.files[0]));

  zone.addEventListener('dragover', e => { e.preventDefault(); zone.classList.add('drag-over'); });
  zone.addEventListener('dragleave',  () => zone.classList.remove('drag-over'));
  zone.addEventListener('drop', e => {
    e.preventDefault();
    zone.classList.remove('drag-over');
    showFile(e.dataTransfer.files[0]);
  });

  window.clearPdf = function() {
    input.value = '';
    infoDiv.classList.add('d-none');
    zone.style.display = '';
  };
})();

// ── Contador de caracteres en textarea texto ──────────────────
document.getElementById('textContent').addEventListener('input', function() {
  document.getElementById('textCharCount').textContent = this.value.length.toLocaleString();
});

// ── FAQ: añadir/eliminar par ──────────────────────────────────
let faqCount = 1;
document.getElementById('addFaqPair').addEventListener('click', function() {
  faqCount++;
  const clone = document.querySelector('.faq-pair').cloneNode(true);
  clone.querySelector('.badge').textContent = `Pregunta ${faqCount}`;
  clone.querySelectorAll('input, textarea').forEach(i => i.value = '');
  const removeBtn = clone.querySelector('.remove-faq-btn');
  removeBtn.style.display = '';
  removeBtn.addEventListener('click', () => { clone.remove(); });
  document.getElementById('faqPairs').appendChild(clone);
});

// ── Guardar documento ─────────────────────────────────────────
document.getElementById('saveDocBtn').addEventListener('click', async function() {
  const spinner = document.getElementById('saveDocSpinner');
  const errDiv  = document.getElementById('save-doc-error');
  spinner.classList.remove('d-none');
  document.getElementById('saveDocText').textContent = 'Procesando…';
  this.disabled = true;
  errDiv.classList.add('d-none');

  try {
    const activeTab = document.querySelector('#docTypeTabs .nav-link.active')?.getAttribute('href');
    const agentId   = document.getElementById('docAgentId').value || undefined;
    let payload = { agent_id: agentId };

    if (activeTab === '#tab-text') {
      const title   = document.getElementById('textTitle').value.trim();
      const content = document.getElementById('textContent').value.trim();
      if (!title || !content) throw new Error('Completa el título y el contenido');
      payload = { ...payload, file_type: 'text', title, content };

    } else if (activeTab === '#tab-pdf') {
      const title = document.getElementById('pdfTitle').value.trim();
      const file  = document.getElementById('pdfFile').files[0];
      if (!title) throw new Error('Agrega un título');
      if (!file)  throw new Error('Selecciona un archivo PDF');

      // Convertir a base64
      const base64 = await new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload  = e => resolve(e.target.result.split(',')[1]);
        reader.onerror = () => reject(new Error('Error al leer el archivo'));
        reader.readAsDataURL(file);
      });
      payload = { ...payload, file_type: 'pdf', title, content: base64 };

    } else if (activeTab === '#tab-url') {
      const title = document.getElementById('urlTitle').value.trim();
      const url   = document.getElementById('urlContent').value.trim();
      if (!title || !url) throw new Error('Completa el título y la URL');
      if (!url.startsWith('http')) throw new Error('La URL debe comenzar con http:// o https://');
      payload = { ...payload, file_type: 'url', title, source_url: url };

    } else if (activeTab === '#tab-faq') {
      const title     = document.getElementById('faqTitle').value.trim();
      const questions = [...document.querySelectorAll('.faq-question')].map(i => i.value.trim());
      const answers   = [...document.querySelectorAll('.faq-answer')].map(i => i.value.trim());
      if (!title) throw new Error('Agrega un título al FAQ');
      const faqs = questions.map((q, i) => ({ question: q, answer: answers[i] }))
                            .filter(f => f.question && f.answer);
      if (!faqs.length) throw new Error('Agrega al menos una pregunta y respuesta');

      // Usar endpoint /kb/faq
      const res = await fetch('/api/kb-proxy.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ file_type: 'faq', title, faqs, agent_id: agentId }),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || 'Error al guardar');
      bootstrap.Modal.getInstance(document.getElementById('addDocModal'))?.hide();
      insertDocRow({ ...data, file_type:'faq', title, status: data.status || 'pending', chunk_count: 0 });
      window.showToast('<i class="bx bx-check-circle me-1"></i>FAQ guardado y procesando…', 'success');
      return;
    }

    const res  = await fetch('/api/kb-proxy.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'No se pudo guardar');

    bootstrap.Modal.getInstance(document.getElementById('addDocModal'))?.hide();
    insertDocRow({ ...data, file_type: payload.file_type, title: payload.title, status: data.status || 'pending', chunk_count: 0 });
    window.showToast('<i class="bx bx-check-circle me-1"></i>Documento recibido. Procesando…', 'success');

  } catch(err) {
    errDiv.textContent = err.message;
    errDiv.classList.remove('d-none');
  } finally {
    spinner.classList.add('d-none');
    document.getElementById('saveDocText').textContent = 'Guardar y procesar';
    this.disabled = false;
  }
});

// ── Insertar fila de doc nuevo en la tabla ────────────────────
function insertDocRow(doc) {
  const tbody  = document.getElementById('kb-tbody');
  const empty  = document.getElementById('row-empty-kb');
  if (empty) empty.remove();
  tbody.insertBefore(buildDocRow(doc), tbody.firstChild);
  updateDocKpi();
  if (['pending','processing'].includes(doc.status)) startPolling();
}

// ── Auto-polling: documentos en processing/pending ────────────
(function() {
  const POLL_INTERVAL = 4000;
  let pollTimer  = null;
  let polling    = false;

  async function pollStatus() {
    const badges = document.querySelectorAll('.kb-status-badge');
    let stillProcessing = false;

    for (const badge of badges) {
      const docId = badge.dataset.docId;
      if (!docId) continue;
      const row = badge.closest('tr');
      if (row && !['processing','pending'].includes(row.dataset.status)) continue; // ya terminó

      try {
        const r = await fetch(`/api/kb-get.php?id=${encodeURIComponent(docId)}`);
        if (!r.ok) continue;
        const doc = await r.json();

        if (doc.status === 'ready') {
          badge.className = 'badge bg-label-success kb-status-badge';
          badge.innerHTML = 'Listo';
          const countEl = document.querySelector(`.kb-chunk-count[data-doc-id="${docId}"]`);
          if (countEl) countEl.textContent = doc.chunk_count || 0;
          if (row) row.dataset.status = 'ready';
        } else if (doc.status === 'error') {
          badge.className = 'badge bg-label-danger kb-status-badge';
          badge.innerHTML = 'Error';
          if (row) row.dataset.status = 'error';
        } else {
          stillProcessing = true;
        }
      } catch(_) { stillProcessing = true; }
    }

    pollTimer = stillProcessing ? setTimeout(pollStatus, POLL_INTERVAL) : null;
    if (!stillProcessing) polling = false;
  }

  window.startPolling = function() {
    if (polling) return;
    polling   = true;
    pollTimer = setTimeout(pollStatus, 2000);
  };

  document.addEventListener('visibilitychange', () => {
    if (document.hidden && pollTimer) { clearTimeout(pollTimer); pollTimer = null; polling = false; }
    else if (!document.hidden && document.querySelectorAll('[data-status="processing"],[data-status="pending"]').length) {
      startPolling();
    }
  });

  <?php if ($hasProcessing): ?>startPolling();<?php endif; ?>
})();

// ── Helper: escape HTML ───────────────────────────────────────
function escapeHtml(text) {
  return (text || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
                     .replace(/"/g,'&quot;').replace(/'/g,'&#039;');
}

// ── Cargar pack vertical ──────────────────────────────────────
document.getElementById('btn-apply-pack')?.addEventListener('click', async function () {
  const btn = this;
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Cargando…';
  try {
    const res = await fetch('/api/vertical-pack-apply.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}',
    });
    const data = await res.json();
    if (data.ok) {
      window.showToast?.(`Pack "${data.label}" cargado: ${data.faqs_created} FAQs`, 'success');
      setTimeout(() => location.reload(), 900);
    } else {
      window.showToast?.(data.error || 'No se pudo cargar el pack', 'error');
      btn.disabled = false; btn.innerHTML = '<i class="bx bx-download me-1"></i>Cargar pack';
    }
  } catch {
    window.showToast?.('Error de red', 'error');
    btn.disabled = false; btn.innerHTML = '<i class="bx bx-download me-1"></i>Cargar pack';
  }
});
</script>
