<?php
function renderFooter(): void {
?>
  <footer class="content-footer footer bg-footer-theme">
    <div class="container-xxl d-flex flex-wrap justify-content-between py-2 flex-md-row flex-column">
      <div class="mb-2 mb-md-0">
        <a href="#" class="footer-link fw-bolder">AgentCore</a> ©<script>document.write(new Date().getFullYear())</script>
        · <a href="/legal-terms.php" target="_blank" rel="noopener" class="footer-link">Términos de Servicio</a>
      </div>
      <div class="d-none d-lg-inline-block">
        <span class="text-muted small">v<?= APP_VERSION ?></span>
      </div>
    </div>
  </footer>
  <div class="content-backdrop fade"></div>
</div><!-- / content-wrapper -->
</div><!-- / layout-page -->
</div><!-- / layout-container -->
<div class="layout-overlay layout-menu-toggle"></div>
</div><!-- / layout-wrapper -->

<!-- Bootstrap 5 -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<!-- Sneat JS (local) -->
<script src="/assets/vendor/js/helpers.js"></script>
<script src="/assets/js/sneat-config.js"></script>
<script src="/assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
<script src="/assets/vendor/js/menu.js"></script>
<script src="/assets/js/sneat-main.js"></script>
<!-- AgentCore Dashboard JS (con cache-buster por mtime → siempre la última versión) -->
<?php
  $jsDashboard = __DIR__ . '/../assets/js/dashboard.js';
  $jsTheme     = __DIR__ . '/../assets/js/theme.js';
  $cssDash     = __DIR__ . '/../assets/css/dashboard.css';
?>
<script src="/assets/js/dashboard.js?v=<?= file_exists($jsDashboard) ? filemtime($jsDashboard) : APP_VERSION ?>"></script>
<!-- Tema + Sidebar (collapse persistente, grupos, dark mode) -->
<script src="/assets/js/theme.js?v=<?= file_exists($jsTheme) ? filemtime($jsTheme) : APP_VERSION ?>"></script>
<!-- Help tips: inicializar popovers en toda la app -->
<script>
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.help-tip[data-bs-toggle="popover"]').forEach(el => {
    new bootstrap.Popover(el, {
      trigger:   'hover focus',
      placement: 'top',
      html:      true,
      customClass: 'help-tip-popover',
    });
  });
});
</script>
</body>
</html>
<?php
}

/**
 * Abre el layout principal (llamar después de renderNavbar)
 */
function openContent(): void {
    echo '<div class="layout-page"><div class="content-wrapper">';
}

function closeContent(): void {
    echo '</div><!-- / content-wrapper -->';
}
