<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/head.php';
require_once __DIR__ . '/../includes/sidebar.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/footer.php';

requireAuth();

$raw       = apiGet('/agents');
// apiGet añade '_status' al array — filtramos solo los elementos que son agentes reales
$agents    = array_values(array_filter((array)$raw, 'is_array'));

// Datos del tenant frescos desde la API (la sesión PHP puede estar desactualizada)
$me        = apiGet('/auth/me');
$maxAgents = (int)($me['user']['tenant']['max_agents'] ?? $me['tenant']['max_agents'] ?? currentTenant()['max_agents'] ?? 5);
$currCount = count($agents);
$atLimit   = $currCount >= $maxAgents;

renderHead('Agentes IA');
?>

<div class="layout-wrapper layout-content-navbar">
<div class="layout-container">
<?php renderSidebar('agents'); ?>
<div class="layout-page">
<?php renderNavbar('Agentes IA'); ?>
<div class="content-wrapper">
<div class="container-xxl flex-grow-1 container-p-y">

  <!-- Header -->
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
    <div>
      <h4 class="mb-1">Agentes IA</h4>
      <p class="text-muted mb-0">
        <?= $currCount ?>/<?= $maxAgents ?> agente<?= $maxAgents !== 1 ? 's' : '' ?> en tu plan
        <?php if ($atLimit): ?>
          · <a href="/pages/billing.php" class="text-warning fw-semibold">
              <i class="bx bx-crown me-1"></i>Ampliar plan
            </a>
        <?php endif; ?>
      </p>
    </div>

    <!-- Botón nuevo agente — siempre visible, deshabilitado si al límite -->
    <?php if (!$atLimit): ?>
      <a href="/pages/agent-editor.php" class="btn btn-primary">
        <i class="bx bx-plus me-1"></i>Nuevo agente
      </a>
    <?php else: ?>
      <button class="btn btn-primary" disabled
              title="Tu plan actual permite <?= $maxAgents ?> agente(s). Amplía tu plan para crear más.">
        <i class="bx bx-plus me-1"></i>Nuevo agente
      </button>
    <?php endif; ?>
  </div>

  <!-- Grid de agentes -->
  <div class="row g-4">

    <?php if (empty($agents)): ?>
      <!-- Estado vacío -->
      <div class="col-12">
        <div class="card">
          <div class="card-body text-center py-5">
            <i class="bx bx-bot bx-lg opacity-25 d-block mb-3" style="font-size:4rem"></i>
            <h5>Aún no tienes agentes</h5>
            <p class="text-muted mb-4">
              Crea tu primer agente IA y configúralo para tu negocio en minutos.
            </p>
            <a href="/pages/agent-editor.php" class="btn btn-primary btn-lg">
              <i class="bx bx-plus me-1"></i>Crear mi primer agente
            </a>
          </div>
        </div>
      </div>

    <?php else: ?>

      <!-- Tarjetas de agentes existentes -->
      <?php foreach ($agents as $agent):
        $isActive = (bool)($agent['is_active'] ?? false);
        $channel  = $agent['channel'] ?? 'voice';
        $cfg      = $agent['config'] ?? [];
        $hasConfig = !empty($cfg['businessName']) || !empty($cfg['specialties']);

        $channelColor = match($channel) {
          'voice'    => 'primary',
          'whatsapp' => 'success',
          'webchat'  => 'info',
          default    => 'secondary',
        };
        $channelLabel = match($channel) {
          'voice'    => '📞 Voz',
          'whatsapp' => '💬 WhatsApp',
          'webchat'  => '🌐 Web Chat',
          default    => ucfirst($channel),
        };
      ?>
      <div class="col-md-6 col-xl-4">
        <div class="card h-100 <?= $isActive ? '' : 'opacity-75' ?>">
          <div class="card-body d-flex flex-column gap-3">

            <!-- Encabezado -->
            <div class="d-flex align-items-start justify-content-between">
              <div class="d-flex align-items-center gap-2">
                <div class="avatar avatar-md">
                  <span class="avatar-initial rounded-circle bg-label-<?= $isActive ? $channelColor : 'secondary' ?>"
                        style="font-size:1.3rem">
                    <?= $channel === 'voice' ? '🎙️' : ($channel === 'whatsapp' ? '💬' : '🤖') ?>
                  </span>
                </div>
                <div>
                  <h6 class="mb-0 fw-semibold"><?= e($agent['name']) ?></h6>
                  <small class="text-muted"><?= e($agent['llm_model'] ?? '') ?></small>
                </div>
              </div>
              <!-- Toggle activo/inactivo -->
              <div class="form-check form-switch mb-0 ms-2">
                <input class="form-check-input agent-toggle" type="checkbox"
                       role="switch"
                       data-id="<?= e($agent['id']) ?>"
                       <?= $isActive ? 'checked' : '' ?>
                       title="<?= $isActive ? 'Activo — clic para desactivar' : 'Inactivo — clic para activar' ?>"/>
              </div>
            </div>

            <!-- Badges de canal y teléfono -->
            <div class="d-flex align-items-center gap-2 flex-wrap">
              <span class="badge bg-label-<?= $channelColor ?>"><?= $channelLabel ?></span>
              <?php if (!empty($agent['phone_number'])): ?>
                <small class="text-muted"><i class="bx bx-phone me-1"></i><?= e($agent['phone_number']) ?></small>
              <?php endif; ?>
              <?php if ($hasConfig): ?>
                <span class="badge bg-label-success" title="Configurado con el editor estructurado">
                  <i class="bx bx-check-circle me-1"></i>Config
                </span>
              <?php endif; ?>
            </div>

            <!-- Preview del negocio o del prompt -->
            <?php if (!empty($cfg['businessName'])): ?>
              <div class="small text-muted" style="line-height:1.5">
                <strong class="text-body"><?= e($cfg['businessName']) ?></strong>
                <?php if (!empty($cfg['objective'])): ?>
                  <br><?= e(substr($cfg['objective'], 0, 90)) ?><?= strlen($cfg['objective']) > 90 ? '…' : '' ?>
                <?php endif; ?>
              </div>
            <?php elseif (!empty($agent['system_prompt'])): ?>
              <p class="small text-muted mb-0" style="line-height:1.4;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden">
                <?= e(substr($agent['system_prompt'], 0, 100)) ?>…
              </p>
            <?php endif; ?>

            <!-- Chips de especialidades -->
            <?php if (!empty($cfg['specialties'])): ?>
              <div class="d-flex flex-wrap gap-1">
                <?php foreach (array_slice($cfg['specialties'], 0, 3) as $sp): ?>
                  <span class="badge bg-label-secondary" style="font-size:.7rem"><?= e($sp['name'] ?? '') ?></span>
                <?php endforeach; ?>
                <?php if (count($cfg['specialties']) > 3): ?>
                  <span class="badge bg-label-secondary" style="font-size:.7rem">+<?= count($cfg['specialties']) - 3 ?> más</span>
                <?php endif; ?>
              </div>
            <?php endif; ?>

            <!-- Acciones — empujar al fondo -->
            <div class="d-flex gap-2 mt-auto">
              <a href="/pages/agent-editor.php?id=<?= e($agent['id']) ?>"
                 class="btn btn-primary btn-sm flex-grow-1">
                <i class="bx bx-edit-alt me-1"></i>Configurar
              </a>
              <a href="/pages/conversations.php?agent_id=<?= e($agent['id']) ?>"
                 class="btn btn-outline-secondary btn-sm" title="Ver conversaciones">
                <i class="bx bx-history"></i>
              </a>
            </div>

          </div>
        </div>
      </div>
      <?php endforeach; ?>

      <!-- Tarjeta "Agregar nuevo" — solo si no está al límite -->
      <?php if (!$atLimit): ?>
      <div class="col-md-6 col-xl-4">
        <a href="/pages/agent-editor.php" class="text-decoration-none">
          <div class="card h-100 border-2 border-dashed"
               style="border-color:#696cff !important; min-height:200px; cursor:pointer; transition:background .15s"
               onmouseenter="this.style.background='rgba(105,108,255,.04)'"
               onmouseleave="this.style.background=''">
            <div class="card-body d-flex flex-column align-items-center justify-content-center text-center gap-2 py-5">
              <div style="width:52px;height:52px;border-radius:50%;background:rgba(105,108,255,.1);display:flex;align-items:center;justify-content:center">
                <i class="bx bx-plus text-primary" style="font-size:1.6rem"></i>
              </div>
              <h6 class="text-primary mb-0">Nuevo agente</h6>
              <small class="text-muted">Elige el tipo (Voz, WhatsApp o Web)<br>y configúralo en minutos</small>
            </div>
          </div>
        </a>
      </div>
      <?php endif; ?>

    <?php endif; ?>
  </div><!-- /row -->

</div>
<?php renderFooter(); ?>

<script>
// Toggle activo/inactivo — actualiza la tarjeta sin recargar
document.querySelectorAll('.agent-toggle').forEach(toggle => {
  toggle.addEventListener('change', async function () {
    const id       = this.dataset.id;
    const active   = this.checked;
    const original = !active;

    // Feedback visual inmediato
    const card = this.closest('.card');
    if (card) {
      card.style.transition = 'opacity .2s';
      card.style.opacity    = active ? '1' : '.65';
      card.classList.toggle('opacity-75', !active);
    }

    try {
      const res = await fetch('/api/agent-save.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({ id, is_active: active }),
      });
      if (!res.ok) throw new Error('Error al actualizar');

      // Actualizar tooltip del toggle
      this.title = active
        ? 'Activo — clic para desactivar'
        : 'Inactivo — clic para activar';

      // Actualizar color del avatar de canal
      const avatarSpan = card?.querySelector('.avatar-initial');
      if (avatarSpan) {
        // Quitar clases bg-label-* y agregar la correcta
        avatarSpan.className = avatarSpan.className
          .replace(/bg-label-\w+/g, '')
          .trim();
        const channelColor = active
          ? (avatarSpan.closest('.card-body')
              ?.querySelector('.badge[class*="bg-label-"]')
              ?.className.match(/bg-label-(\w+)/)?.[1] || 'primary')
          : 'secondary';
        avatarSpan.classList.add('bg-label-' + channelColor);
      }

      window.showToast?.(active ? 'Agente activado' : 'Agente desactivado', 'success');
    } catch (err) {
      // Revertir estado visual
      this.checked = original;
      if (card) card.style.opacity = original ? '1' : '.65';
      window.showToast?.('No se pudo actualizar el agente', 'danger');
    }
  });
});
</script>
