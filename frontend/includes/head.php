<?php
/**
 * Header HTML — Sneat Bootstrap 5
 * @param string $title     Título de la página
 * @param string $pageClass Clase extra para <body>
 */
function renderHead(string $title = 'Dashboard', string $pageClass = ''): void {
    $appName = APP_NAME;
    $tenant  = currentTenant();
    $tenantName = $tenant['name'] ?? $appName;
?>
<!DOCTYPE html>
<html lang="es" class="light-style layout-menu-fixed" dir="ltr"
      data-theme="theme-default" data-assets-path="/assets/"
      data-template="vertical-menu-template-free">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no, minimum-scale=1.0, maximum-scale=1.0"/>
  <title><?= e($title) ?> — <?= e($tenantName) ?></title>
  <meta name="description" content="<?= e($appName) ?> Dashboard"/>

  <!-- Bootstrap tema (claro/oscuro) — leer ANTES del CSS para evitar flash.
       El collapse del sidebar lo aplica Sneat al inicializar (vía
       sneat-main.js que lee localStorage). -->
  <script>
    window.AC_BACKEND_ROOT = <?= json_encode(BACKEND_ROOT) ?>;
    (function() {
      try {
        var t = localStorage.getItem('agentcore-theme') || 'light';
        var html = document.documentElement;
        html.classList.remove('light-style','dark-style');
        html.classList.add(t === 'dark' ? 'dark-style' : 'light-style');
        html.setAttribute('data-bs-theme', t);
      } catch (e) {}
    })();
  </script>

  <!-- Favicon -->
  <link rel="icon" type="image/x-icon" href="/assets/img/favicon.ico"/>

  <!-- Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
  <link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@300;400;500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>

  <!-- Sneat core CSS (local) -->
  <link rel="stylesheet" href="/assets/vendor/css/core.css"/>
  <link rel="stylesheet" href="/assets/vendor/css/theme-default.css"/>

  <!-- Icons (Boxicons local) -->
  <link rel="stylesheet" href="/assets/vendor/fonts/boxicons.css"/>

  <!-- Perfect Scrollbar CSS -->
  <link rel="stylesheet" href="/assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css"/>

  <!-- AgentCore overrides (cache-buster por mtime para forzar reload tras cambios) -->
  <?php $cssDashPath = __DIR__ . '/../assets/css/dashboard.css'; ?>
  <link rel="stylesheet" href="/assets/css/dashboard.css?v=<?= file_exists($cssDashPath) ? filemtime($cssDashPath) : APP_VERSION ?>"/>
  <!-- Tema minimalista AgentCore (tipografía Inter, contraste AA, foco visible) -->
  <?php $cssThemePath = __DIR__ . '/../assets/css/agentcore-theme.css'; ?>
  <link rel="stylesheet" href="/assets/css/agentcore-theme.css?v=<?= file_exists($cssThemePath) ? filemtime($cssThemePath) : APP_VERSION ?>"/>
</head>
<body class="<?= e($pageClass) ?>">
<a class="skip-link" href="#main-content">Saltar al contenido principal</a>
<?php
}
