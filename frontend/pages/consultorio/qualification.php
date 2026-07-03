<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/head.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/navbar.php';
require_once __DIR__ . '/../../includes/footer.php';

requireAuth(); if (!isAdmin()) { header('Location: /index.php'); exit; }

$rawQs    = apiGet('/qualification-questions');
$questions = array_values(array_filter((array)$rawQs, 'is_array'));

$rawProfs = apiGet('/professionals');
$profs    = array_values(array_filter((array)$rawProfs, 'is_array'));

$rawTypes = apiGet('/consultorio/session-types');
$stypes   = array_values(array_filter((array)$rawTypes, 'is_array'));

renderHead('Calificación de Leads — Consultorio');
?>
<div class="layout-wrapper layout-content-navbar"><div class="layout-container">
<?php renderSidebar('consult-qualification'); ?>
<div class="layout-page"><?php renderNavbar('Calificación de Leads'); ?>
<div class="content-wrapper"><div class="container-xxl flex-grow-1 container-p-y">

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
  <div>
    <h4 class="mb-0"><i class="bx bx-filter-alt text-primary me-2"></i>Calificación de Leads</h4>
    <small class="text-muted">Preguntas que el agente hace antes de agendar una sesión</small>
  </div>
  <button class="btn btn-primary btn-sm" onclick="openModal()">
    <i class="bx bx-plus me-1"></i>Agregar pregunta
  </button>
</div>

<!-- Info box -->
<div class="alert alert-info d-flex align-items-start gap-2 mb-4 py-2">
  <i class="bx bx-info-circle mt-1 flex-shrink-0"></i>
  <div class="small">
    El agente realiza estas preguntas en orden de <strong>prioridad</strong>. Si una respuesta
    coincide con el criterio de <strong>descalificación</strong>, el agente detiene el proceso
    y no agenda la sesión. La <strong>importancia</strong> define el peso en la puntuación final del lead.
  </div>
</div>

<!-- Tabla de preguntas -->
<div class="card">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th style="width:40px">#</th>
          <th>Pregunta</th>
          <th>Tipo de respuesta</th>
          <th>Importancia</th>
          <th>Descalifica si</th>
          <th>Aplica a</th>
          <th>Estado</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($questions as $i => $q):
          $dqLabel = match($q['disqualify_on'] ?? null) {
            'yes'  => '<span class="badge bg-label-danger">Responde Sí</span>',
            'no'   => '<span class="badge bg-label-warning">Responde No</span>',
            'any'  => '<span class="badge bg-label-dark">Cualquier respuesta</span>',
            default => '<span class="text-muted">—</span>',
          };
          $typeLabel = match($q['answer_type'] ?? 'yes_no') {
            'text'   => 'Texto libre',
            'number' => 'Número',
            'rating' => 'Calificación 1-10',
            default  => 'Sí / No',
          };
        ?>
        <tr data-id="<?= e($q['id']) ?>">
          <td class="text-muted small"><?= (int)($q['sort_order'] ?? $i + 1) ?></td>
          <td>
            <div class="fw-semibold"><?= e($q['question']) ?></div>
            <?php if (!empty($q['hint'])): ?>
            <small class="text-muted fst-italic"><?= e($q['hint']) ?></small>
            <?php endif; ?>
          </td>
          <td><small><?= $typeLabel ?></small></td>
          <td>
            <?php
              $imp = (int)($q['importance'] ?? 5);
              $stars = str_repeat('★', min($imp, 10));
              $color = $imp >= 8 ? 'text-danger' : ($imp >= 5 ? 'text-warning' : 'text-muted');
            ?>
            <span class="<?= $color ?>" title="<?= $imp ?>/10"><?= $stars ?></span>
          </td>
          <td><?= $dqLabel ?></td>
          <td>
            <?php
              $profName = '';
              $stName   = '';
              if (!empty($q['professional_id'])) {
                foreach ($profs as $pr) {
                  if ($pr['id'] === $q['professional_id']) { $profName = $pr['name']; break; }
                }
              }
              if (!empty($q['session_type_id'])) {
                foreach ($stypes as $st) {
                  if ($st['id'] === $q['session_type_id']) { $stName = $st['name']; break; }
                }
              }
            ?>
            <?php if ($profName): ?>
              <span class="badge bg-label-primary"><?= e($profName) ?></span>
            <?php elseif ($stName): ?>
              <span class="badge bg-label-info"><?= e($stName) ?></span>
            <?php else: ?>
              <span class="badge bg-label-secondary">General</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($q['is_active'] ?? true): ?>
            <span class="badge bg-label-success">Activa</span>
            <?php else: ?>
            <span class="badge bg-label-secondary">Inactiva</span>
            <?php endif; ?>
          </td>
          <td class="text-end">
            <button class="btn btn-sm btn-outline-primary me-1"
                    onclick='openModal(<?= htmlspecialchars(json_encode($q), ENT_QUOTES) ?>)'>
              <i class="bx bx-edit-alt"></i>
            </button>
            <button class="btn btn-sm btn-outline-danger"
                    onclick="deleteQ('<?= e($q['id']) ?>')">
              <i class="bx bx-trash"></i>
            </button>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($questions)): ?>
        <tr><td colspan="8" class="text-center text-muted py-5">Sin preguntas — agrega la primera</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

</div><?php renderFooter(); ?>

<!-- Modal -->
<div class="modal fade" id="modalQ" tabindex="-1">
  <div class="modal-dialog modal-dialog-scrollable" style="max-height:92vh;margin-top:4vh">
    <div class="modal-content">
      <div class="modal-header border-0 pb-0">
        <h5 class="modal-title"><i class="bx bx-filter-alt text-primary me-2"></i><span id="q-title">Agregar pregunta</span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form id="form-q">
        <div class="modal-body" style="overflow-y:auto;max-height:calc(92vh - 130px)">
          <input type="hidden" id="q-id">

          <div class="mb-3">
            <label class="form-label">Pregunta <span class="text-danger">*</span></label>
            <textarea class="form-control" id="q-question" rows="2" required
                      placeholder="¿Has recibido tratamiento psiquiátrico en los últimos 6 meses?"></textarea>
          </div>
          <div class="mb-3">
            <label class="form-label">Pista / contexto para el agente</label>
            <input type="text" class="form-control" id="q-hint"
                   placeholder="Pregunta solo si el paciente menciona medicamentos">
            <small class="text-muted">Instrucción interna — no se comparte con el paciente</small>
          </div>

          <div class="row g-3 mb-3">
            <div class="col-md-6">
              <label class="form-label">Tipo de respuesta</label>
              <select class="form-select" id="q-type">
                <option value="yes_no">Sí / No</option>
                <option value="text">Texto libre</option>
                <option value="number">Número</option>
                <option value="rating">Calificación 1-10</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Importancia <span id="q-imp-val" class="fw-semibold text-primary">5</span>/10</label>
              <input type="range" class="form-range" id="q-importance" min="1" max="10" value="5"
                     oninput="document.getElementById('q-imp-val').textContent = this.value">
              <div class="d-flex justify-content-between">
                <small class="text-muted">Baja</small><small class="text-muted">Alta</small>
              </div>
            </div>
          </div>

          <div class="row g-3 mb-3">
            <div class="col-md-6">
              <label class="form-label">Descalifica si responde</label>
              <select class="form-select" id="q-disqualify">
                <option value="">No descalifica</option>
                <option value="yes">Sí</option>
                <option value="no">No</option>
                <option value="any">Cualquier respuesta</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Orden / prioridad</label>
              <input type="number" class="form-control" id="q-order" min="1" max="100" value="10">
            </div>
          </div>

          <hr class="my-3">
          <h6 class="fw-semibold text-muted mb-2"><i class="bx bx-filter me-1"></i>Aplica a (opcional)</h6>
          <small class="text-muted d-block mb-2">Deja vacío para que aplique a todos los profesionales y tipos de sesión</small>

          <div class="row g-3 mb-2">
            <div class="col-md-6">
              <label class="form-label">Profesional específico</label>
              <select class="form-select" id="q-prof">
                <option value="">— Todos —</option>
                <?php foreach ($profs as $pr): ?>
                <option value="<?= e($pr['id']) ?>"><?= e($pr['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Tipo de sesión específico</label>
              <select class="form-select" id="q-stype">
                <option value="">— Todos —</option>
                <?php foreach ($stypes as $st): ?>
                <option value="<?= e($st['id']) ?>"><?= e($st['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Estado</label>
              <select class="form-select" id="q-active">
                <option value="true">Activa</option><option value="false">Inactiva</option>
              </select>
            </div>
          </div>

          <div id="q-error" class="alert alert-danger mt-3 d-none"></div>
        </div>
        <div class="modal-footer border-0 pt-0">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-primary">
            <span id="q-btn-text"><i class="bx bx-save me-1"></i>Guardar</span>
            <span id="q-btn-spin" class="d-none"><span class="spinner-border spinner-border-sm me-1"></span>Guardando…</span>
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function openModal(q = null) {
  document.getElementById('form-q').reset();
  document.getElementById('q-error').classList.add('d-none');
  document.getElementById('q-imp-val').textContent = '5';

  if (q) {
    document.getElementById('q-title').textContent    = 'Editar pregunta';
    document.getElementById('q-id').value             = q.id;
    document.getElementById('q-question').value       = q.question || '';
    document.getElementById('q-hint').value           = q.hint || '';
    document.getElementById('q-type').value           = q.answer_type || 'yes_no';
    document.getElementById('q-importance').value     = q.importance || 5;
    document.getElementById('q-imp-val').textContent  = q.importance || 5;
    document.getElementById('q-disqualify').value     = q.disqualify_on || '';
    document.getElementById('q-order').value          = q.sort_order || 10;
    document.getElementById('q-prof').value           = q.professional_id || '';
    document.getElementById('q-stype').value          = q.session_type_id || '';
    document.getElementById('q-active').value         = (q.is_active !== false) ? 'true' : 'false';
  } else {
    document.getElementById('q-title').textContent = 'Agregar pregunta';
    document.getElementById('q-id').value = '';
    document.getElementById('q-importance').value = '5';
    document.getElementById('q-order').value = '10';
  }
  new bootstrap.Modal(document.getElementById('modalQ')).show();
}

document.getElementById('form-q').addEventListener('submit', async e => {
  e.preventDefault();
  const id     = document.getElementById('q-id').value;
  const errDiv = document.getElementById('q-error');
  document.getElementById('q-btn-text').classList.add('d-none');
  document.getElementById('q-btn-spin').classList.remove('d-none');
  errDiv.classList.add('d-none');

  const payload = {
    id:               id || undefined,
    question:         document.getElementById('q-question').value.trim(),
    hint:             document.getElementById('q-hint').value.trim() || null,
    answer_type:      document.getElementById('q-type').value,
    importance:       parseInt(document.getElementById('q-importance').value) || 5,
    disqualify_on:    document.getElementById('q-disqualify').value || null,
    sort_order:       parseInt(document.getElementById('q-order').value) || 10,
    professional_id:  document.getElementById('q-prof').value  || null,
    session_type_id:  document.getElementById('q-stype').value || null,
    is_active:        document.getElementById('q-active').value === 'true',
  };

  try {
    const res  = await fetch('/api/qualification-save.php', {
      method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(payload)
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.message || data.error || 'Error al guardar');
    bootstrap.Modal.getInstance(document.getElementById('modalQ'))?.hide();
    window.showToast(id ? 'Pregunta actualizada' : 'Pregunta creada', 'success');
    location.reload();
  } catch(err) {
    errDiv.textContent = err.message; errDiv.classList.remove('d-none');
  } finally {
    document.getElementById('q-btn-text').classList.remove('d-none');
    document.getElementById('q-btn-spin').classList.add('d-none');
  }
});

async function deleteQ(id) {
  const ok = await window.confirmToast?.(
    '<i class="bx bx-trash me-1"></i>¿Eliminar esta pregunta de calificación?', 'Sí, eliminar'
  );
  if (!ok) return;
  const res  = await fetch('/api/qualification-save.php', {
    method: 'POST', headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({id, action: 'delete'})
  });
  const data = await res.json();
  if (!res.ok) { window.showToast('Error: ' + (data.error || ''), 'danger'); return; }
  document.querySelector(`tr[data-id="${id}"]`)?.remove();
  window.showToast('<i class="bx bx-check-circle me-1"></i>Pregunta eliminada', 'success');
}
</script>
