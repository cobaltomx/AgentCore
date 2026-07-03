<?php
require_once __DIR__ . '/includes/config.php';

if (isLoggedIn()) {
    header('Location: /index.php');
    exit;
}

$tenantSlug = TENANT_SLUG;
$expired    = isset($_GET['expired']);
$forbidden  = isset($_GET['forbidden']);
?>
<!DOCTYPE html>
<html lang="es" class="light-style" dir="ltr">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Iniciar sesión — <?= e(APP_NAME) ?></title>
  <link rel="icon" type="image/x-icon" href="/assets/img/favicon.ico"/>
  <link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@300;400;500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/boxicons@2.1.4/css/boxicons.min.css"/>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css"/>
  <link rel="stylesheet" href="/assets/css/agentcore-theme.css"/>
  <style>
    *, *::before, *::after { box-sizing: border-box; }
    body {
      font-family: 'Public Sans', sans-serif;
      margin: 0; padding: 0; min-height: 100vh;
      display: flex;
      background: #f5f5f9;
    }

    /* ── Panel izquierdo — gradiente + ilustración ── */
    .auth-left {
      flex: 0 0 420px;
      background: linear-gradient(145deg, #696cff 0%, #9155fd 60%, #c026d3 100%);
      display: flex; flex-direction: column;
      align-items: center; justify-content: center;
      padding: 3rem 2.5rem;
      color: #fff;
      position: relative; overflow: hidden;
    }
    .auth-left::before {
      content: '';
      position: absolute; inset: 0;
      background: url("data:image/svg+xml,%3Csvg width='100' height='100' viewBox='0 0 100 100' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M50 10 L90 32.5 L90 67.5 L50 90 L10 67.5 L10 32.5Z' fill='none' stroke='rgba(255,255,255,0.07)' stroke-width='1.5'/%3E%3C/svg%3E") repeat;
      background-size: 80px 80px;
    }
    .auth-left .brand-mark {
      width: 72px; height: 72px;
      background: rgba(255,255,255,.18);
      border-radius: 20px;
      display: flex; align-items: center; justify-content: center;
      margin-bottom: 1.5rem;
      backdrop-filter: blur(6px);
      border: 1px solid rgba(255,255,255,.25);
      position: relative; z-index: 1;
    }
    .auth-left h1 {
      font-size: 2rem; font-weight: 700;
      margin: 0 0 .5rem; position: relative; z-index: 1;
    }
    .auth-left p {
      font-size: .95rem; opacity: .82;
      text-align: center; max-width: 300px;
      line-height: 1.6; position: relative; z-index: 1;
    }
    .auth-left .feature-list {
      list-style: none; padding: 0; margin: 2rem 0 0;
      position: relative; z-index: 1;
    }
    .auth-left .feature-list li {
      display: flex; align-items: center; gap: .6rem;
      font-size: .88rem; opacity: .88; margin-bottom: .75rem;
    }
    .auth-left .feature-list li i {
      font-size: 1.1rem; opacity: .9;
    }

    /* ── Panel derecho — formulario ── */
    .auth-right {
      flex: 1; display: flex;
      align-items: center; justify-content: center;
      padding: 2rem;
    }
    .auth-form-wrap {
      width: 100%; max-width: 420px;
    }
    .auth-form-wrap .logo-mobile {
      display: none;
      align-items: center; gap: .6rem;
      justify-content: center; margin-bottom: 2rem;
    }
    .form-control, .form-select {
      border-radius: 8px; padding: .55rem .85rem;
      border: 1.5px solid #d9d9e3;
      transition: border-color .18s, box-shadow .18s;
    }
    .form-control:focus {
      border-color: #696cff;
      box-shadow: 0 0 0 3px rgba(105,108,255,.15);
    }
    .btn-primary {
      background: #696cff; border-color: #696cff;
      border-radius: 8px; font-weight: 600;
      padding: .6rem 1.2rem;
      transition: background .18s, transform .1s;
    }
    .btn-primary:hover { background: #5f61e6; border-color: #5f61e6; }
    .btn-primary:active { transform: scale(.98); }

    .divider-line {
      display: flex; align-items: center; gap: .8rem;
      color: #b0b3c7; font-size: .82rem; margin: 1.2rem 0;
    }
    .divider-line::before, .divider-line::after {
      content: ''; flex: 1; height: 1px; background: #e7e7f5;
    }

    .alert { border-radius: 8px; font-size: .875rem; }

    /* Responsive */
    @media (max-width: 768px) {
      .auth-left { display: none; }
      .auth-form-wrap .logo-mobile { display: flex; }
    }
  </style>
</head>
<body>

<!-- ── Panel izquierdo ─────────────────────────────────────────────── -->
<div class="auth-left d-none d-md-flex">
  <div class="brand-mark">
    <svg width="38" height="38" viewBox="0 0 32 32" fill="none">
      <path d="M16 4l11 6.3v12.4L16 29 5 22.7V10.3L16 4z" fill="#fff" fill-opacity=".9"/>
      <circle cx="16" cy="16" r="4" fill="rgba(105,108,255,.8)"/>
    </svg>
  </div>
  <h1><?= e(APP_NAME) ?></h1>
  <p>Plataforma de agentes IA para automatizar ventas, citas y atención al cliente</p>

  <ul class="feature-list">
    <li><i class="bx bx-phone-call"></i> Llamadas de voz con IA natural</li>
    <li><i class="bx bx-calendar-check"></i> Agenda citas automáticamente</li>
    <li><i class="bx bx-user-check"></i> Califica y guarda leads</li>
    <li><i class="bx bx-broadcast"></i> Campañas outbound inteligentes</li>
  </ul>
</div>

<!-- ── Panel derecho ───────────────────────────────────────────────── -->
<div class="auth-right">
  <div class="auth-form-wrap">

    <!-- Logo en mobile -->
    <div class="logo-mobile">
      <div style="width:38px;height:38px;background:#696cff;border-radius:10px;display:flex;align-items:center;justify-content:center">
        <svg width="22" height="22" viewBox="0 0 32 32" fill="none">
          <path d="M16 4l11 6.3v12.4L16 29 5 22.7V10.3L16 4z" fill="#fff" fill-opacity=".9"/>
          <circle cx="16" cy="16" r="4" fill="rgba(105,108,255,.8)"/>
        </svg>
      </div>
      <span style="font-size:1.4rem;font-weight:700"><?= e(APP_NAME) ?></span>
    </div>

    <h4 class="mb-1 fw-bold">Bienvenido 👋</h4>
    <p class="text-muted mb-4" style="font-size:.9rem">
      <?php if ($tenantSlug): ?>
        Acceso para <strong><?= e(strtoupper($tenantSlug)) ?></strong> · Ingresa tus credenciales
      <?php else: ?>
        Ingresa tus credenciales para continuar
      <?php endif; ?>
    </p>

    <!-- Alertas PHP-side -->
    <?php if ($expired): ?>
    <div class="alert alert-warning d-flex align-items-center gap-2 py-2 mb-3">
      <i class="bx bx-time flex-shrink-0"></i>
      <span>Tu sesión expiró. Por favor vuelve a ingresar.</span>
    </div>
    <?php endif; ?>

    <?php if ($forbidden): ?>
    <div class="alert alert-danger d-flex align-items-center gap-2 py-2 mb-3">
      <i class="bx bx-lock flex-shrink-0"></i>
      <span>No tienes permisos para acceder a esa sección.</span>
    </div>
    <?php endif; ?>

    <!-- Alert dinámico (JS) -->
    <div id="loginAlert" class="d-none alert py-2 mb-3"></div>

    <!-- Formulario -->
    <form id="loginForm" novalidate>

      <div class="mb-3">
        <label class="form-label fw-semibold" for="email">Correo electrónico</label>
        <input type="email" id="email" name="email" class="form-control"
               placeholder="admin@tunegocio.com" autofocus autocomplete="email" required/>
      </div>

      <div class="mb-1">
        <div class="d-flex justify-content-between align-items-center">
          <label class="form-label fw-semibold mb-0" for="password">Contraseña</label>
          <a href="/forgot-password.php" class="small text-muted" style="font-size:.8rem">
            ¿Olvidaste tu contraseña?
          </a>
        </div>
      </div>
      <div class="input-group mb-4">
        <input type="password" id="password" name="password"
               class="form-control" placeholder="••••••••" autocomplete="current-password" required/>
        <button class="btn btn-outline-secondary" type="button" id="togglePass" tabindex="-1">
          <i class="bx bx-hide" id="toggleIcon"></i>
        </button>
      </div>

      <button class="btn btn-primary w-100" type="submit" id="loginBtn">
        <span id="loginBtnText">Iniciar sesión</span>
        <span id="loginSpinner" class="spinner-border spinner-border-sm ms-2 d-none"></span>
      </button>

    </form>

    <?php if (!$tenantSlug): ?>
    <div class="divider-line mt-4">o</div>
    <p class="text-center text-muted mb-0" style="font-size:.82rem">
      ¿Eres cliente de un negocio?<br/>
      Accede desde <code style="background:#f0f0f8;padding:.1rem .35rem;border-radius:4px">tunegocio.agentcore.io</code>
    </p>
    <?php endif; ?>

    <p class="text-center text-muted mt-4 mb-0" style="font-size:.78rem">
      AgentCore &copy; <?= date('Y') ?> · v<?= APP_VERSION ?>
    </p>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Toggle contraseña visible
document.getElementById('togglePass').addEventListener('click', function() {
  const pass = document.getElementById('password');
  const icon = document.getElementById('toggleIcon');
  if (pass.type === 'password') {
    pass.type = 'text';
    icon.className = 'bx bx-show';
  } else {
    pass.type = 'password';
    icon.className = 'bx bx-hide';
  }
});

// Submit AJAX
document.getElementById('loginForm').addEventListener('submit', async function(e) {
  e.preventDefault();

  const email    = document.getElementById('email').value.trim();
  const password = document.getElementById('password').value;
  const btn      = document.getElementById('loginBtn');
  const txt      = document.getElementById('loginBtnText');
  const spin     = document.getElementById('loginSpinner');
  const alert    = document.getElementById('loginAlert');

  if (!email || !password) {
    showAlert('Por favor ingresa tu correo y contraseña.', 'warning');
    return;
  }

  btn.disabled = true;
  txt.textContent = 'Verificando…';
  spin.classList.remove('d-none');
  alert.classList.add('d-none');

  try {
    const res = await fetch('/api/auth-login.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email, password }),
    });

    const data = await res.json();

    if (data.ok) {
      txt.textContent = 'Entrando…';
      // Redirigir al destino correcto
      window.location.href = data.redirect || '/index.php';
    } else {
      showAlert(data.error || 'Credenciales incorrectas.', 'danger');
      btn.disabled = false;
      txt.textContent = 'Iniciar sesión';
      spin.classList.add('d-none');
    }
  } catch {
    showAlert('Error de conexión. Intenta nuevamente.', 'danger');
    btn.disabled = false;
    txt.textContent = 'Iniciar sesión';
    spin.classList.add('d-none');
  }
});

function showAlert(msg, type) {
  const el = document.getElementById('loginAlert');
  el.className = `alert alert-${type} d-flex align-items-center gap-2 py-2 mb-3`;
  const icon = type === 'danger' ? 'bx-error-circle' : type === 'warning' ? 'bx-info-circle' : 'bx-check-circle';
  el.innerHTML = `<i class="bx ${icon} flex-shrink-0"></i><span>${msg}</span>`;
}
</script>
</body>
</html>
