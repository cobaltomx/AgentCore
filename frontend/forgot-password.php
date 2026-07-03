<?php
require_once __DIR__ . '/includes/config.php';

if (isLoggedIn()) {
    header('Location: /index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="es" class="light-style" dir="ltr">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Recuperar contraseña — <?= e(APP_NAME) ?></title>
  <link rel="icon" type="image/x-icon" href="/assets/img/favicon.ico"/>
  <link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/boxicons@2.1.4/css/boxicons.min.css"/>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css"/>
  <style>
    body { font-family: 'Public Sans', sans-serif; background: #f5f5f9; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
    .auth-card { width: 100%; max-width: 420px; }
    .brand-logo { width: 46px; height: 46px; background: #696cff; border-radius: 12px; display: flex; align-items: center; justify-content: center; }
    .form-control { border-radius: 8px; border: 1.5px solid #d9d9e3; }
    .form-control:focus { border-color: #696cff; box-shadow: 0 0 0 3px rgba(105,108,255,.15); }
    .btn-primary { background: #696cff; border-color: #696cff; border-radius: 8px; font-weight: 600; }
    .btn-primary:hover { background: #5f61e6; border-color: #5f61e6; }
    .alert { border-radius: 8px; font-size: .875rem; }

    /* Dev: reset link box */
    .reset-link-box {
      background: #f0f9ff; border: 1.5px dashed #60a5fa;
      border-radius: 10px; padding: 1rem;
    }
    .reset-link-box code {
      word-break: break-all; font-size: .78rem;
      color: #1d4ed8;
    }
  </style>
</head>
<body>

<div class="auth-card p-3">

  <div class="d-flex align-items-center justify-content-center gap-2 mb-4">
    <div class="brand-logo">
      <svg width="24" height="24" viewBox="0 0 32 32" fill="none">
        <path d="M16 4l11 6.3v12.4L16 29 5 22.7V10.3L16 4z" fill="#fff" fill-opacity=".9"/>
        <circle cx="16" cy="16" r="4" fill="rgba(105,108,255,.7)"/>
      </svg>
    </div>
    <span class="h5 fw-bold mb-0"><?= e(APP_NAME) ?></span>
  </div>

  <div class="card shadow-sm">
    <div class="card-body p-4">

      <!-- Estado: formulario -->
      <div id="stateForm">
        <div class="d-flex align-items-center gap-2 mb-1">
          <i class="bx bx-lock-open text-primary" style="font-size:1.4rem"></i>
          <h5 class="mb-0 fw-semibold">¿Olvidaste tu contraseña?</h5>
        </div>
        <p class="text-muted small mb-4">Ingresa tu correo y te enviaremos un enlace para restablecerla.</p>

        <div id="alertBox" class="d-none alert py-2 mb-3"></div>

        <form id="forgotForm" novalidate>
          <div class="mb-3">
            <label class="form-label fw-semibold" for="emailInput">Correo electrónico</label>
            <input type="email" id="emailInput" class="form-control" placeholder="tu@correo.com" autofocus required/>
          </div>
          <button class="btn btn-primary w-100" type="submit" id="submitBtn">
            <span id="btnText">Enviar enlace</span>
            <span id="btnSpin" class="spinner-border spinner-border-sm ms-2 d-none"></span>
          </button>
        </form>
      </div>

      <!-- Estado: link enviado (dev mode) -->
      <div id="stateSuccess" class="d-none text-center">
        <div class="mb-3" style="font-size:3rem">🔑</div>
        <h5 class="fw-semibold mb-2">Enlace generado</h5>
        <p class="text-muted small mb-3">En producción este enlace se enviaría al correo registrado.</p>

        <div id="devLinkBox" class="reset-link-box text-start mb-3 d-none">
          <div class="d-flex align-items-center gap-1 mb-2">
            <span class="badge bg-warning text-dark">DEV</span>
            <small class="text-muted">Copia este enlace para restablecer la contraseña</small>
          </div>
          <code id="resetLinkText"></code>
          <button class="btn btn-sm btn-outline-primary mt-2 w-100" onclick="copyLink()">
            <i class="bx bx-copy me-1"></i> Copiar enlace
          </button>
        </div>

        <a href="/login.php" class="btn btn-outline-secondary w-100">Volver al login</a>
      </div>

    </div>
  </div>

  <div class="text-center mt-3">
    <a href="/login.php" class="text-muted small">
      <i class="bx bx-arrow-back me-1"></i> Volver al inicio de sesión
    </a>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
let currentResetLink = '';

document.getElementById('forgotForm').addEventListener('submit', async function(e) {
  e.preventDefault();

  const email  = document.getElementById('emailInput').value.trim();
  const btn    = document.getElementById('submitBtn');
  const txt    = document.getElementById('btnText');
  const spin   = document.getElementById('btnSpin');

  if (!email) return;

  btn.disabled = true;
  txt.textContent = 'Enviando…';
  spin.classList.remove('d-none');
  document.getElementById('alertBox').classList.add('d-none');

  try {
    const res  = await fetch('/api/auth-forgot.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email }),
    });
    const data = await res.json();

    // Siempre mostramos el estado de éxito (no revelar si email existe)
    document.getElementById('stateForm').classList.add('d-none');
    document.getElementById('stateSuccess').classList.remove('d-none');

    // En dev mode, mostrar el link
    if (data.reset_link) {
      currentResetLink = data.reset_link;
      document.getElementById('resetLinkText').textContent = data.reset_link;
      document.getElementById('devLinkBox').classList.remove('d-none');
    }

  } catch {
    showAlert('Error de conexión. Intenta nuevamente.', 'danger');
    btn.disabled = false;
    txt.textContent = 'Enviar enlace';
    spin.classList.add('d-none');
  }
});

function copyLink() {
  navigator.clipboard?.writeText(currentResetLink).then(() => {
    const btn = event.target.closest('button');
    btn.innerHTML = '<i class="bx bx-check me-1"></i> ¡Copiado!';
    setTimeout(() => { btn.innerHTML = '<i class="bx bx-copy me-1"></i> Copiar enlace'; }, 2000);
  });
}

function showAlert(msg, type) {
  const el = document.getElementById('alertBox');
  el.className = `alert alert-${type} d-flex align-items-center gap-2 py-2 mb-3`;
  el.innerHTML = `<i class="bx bx-error-circle flex-shrink-0"></i><span>${msg}</span>`;
}
</script>
</body>
</html>
