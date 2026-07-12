<?php
require_once __DIR__ . '/includes/config.php';

if (isLoggedIn()) {
    header('Location: /index.php');
    exit;
}

$token = trim($_GET['token'] ?? '');
if (!$token) {
    header('Location: /login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="es" class="light-style" dir="ltr">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Nueva contraseña — <?= e(APP_NAME) ?></title>
  <link rel="icon" type="image/x-icon" href="/assets/img/favicon.ico"/>
  <link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/boxicons@2.1.4/css/boxicons.min.css"/>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css"/>
  <link rel="stylesheet" href="/assets/css/agentcore-theme.css"/>
  <style>
    body { font-family: 'Public Sans', sans-serif; background: #f5f5f9; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
    .auth-card { width: 100%; max-width: 420px; }
    .brand-logo { width: 46px; height: 46px; background: #696cff; border-radius: 12px; display: flex; align-items: center; justify-content: center; }
    .form-control { border-radius: 8px; border: 1.5px solid #d9d9e3; }
    .form-control:focus { border-color: #696cff; box-shadow: 0 0 0 3px rgba(105,108,255,.15); }
    .btn-primary { background: #696cff; border-color: #696cff; border-radius: 8px; font-weight: 600; }
    .btn-primary:hover { background: #5f61e6; border-color: #5f61e6; }
    .alert { border-radius: 8px; font-size: .875rem; }
    .strength-bar { height: 4px; border-radius: 2px; transition: width .3s, background .3s; }
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

      <!-- Estado: verificando token -->
      <div id="stateLoading" class="text-center py-3">
        <div class="spinner-border text-primary mb-2"></div>
        <p class="text-muted small mb-0">Verificando enlace…</p>
      </div>

      <!-- Estado: token inválido -->
      <div id="stateInvalid" class="d-none text-center">
        <div class="mb-3" style="font-size:3rem">⛔</div>
        <h5 class="fw-semibold text-danger mb-2">Enlace inválido o expirado</h5>
        <p class="text-muted small mb-4">El enlace de recuperación ya no es válido. Solicita uno nuevo.</p>
        <a href="/forgot-password.php" class="btn btn-primary w-100">Solicitar nuevo enlace</a>
      </div>

      <!-- Estado: formulario nueva contraseña -->
      <div id="stateForm" class="d-none">
        <div class="d-flex align-items-center gap-2 mb-1">
          <i class="bx bx-lock text-primary" style="font-size:1.4rem"></i>
          <h5 class="mb-0 fw-semibold">Nueva contraseña</h5>
        </div>
        <p class="text-muted small mb-4">Elige una contraseña segura de al menos 8 caracteres.</p>

        <div id="alertBox" class="d-none alert py-2 mb-3"></div>

        <form id="resetForm" novalidate>
          <div class="mb-3">
            <label class="form-label fw-semibold">Nueva contraseña</label>
            <div class="input-group">
              <input type="password" id="newPass" class="form-control" placeholder="Mín. 8 caracteres" required/>
              <button class="btn btn-outline-secondary" type="button" onclick="togglePass('newPass', this)">
                <i class="bx bx-hide"></i>
              </button>
            </div>
            <div id="pwRules" class="mt-2"></div>
          </div>
          <div class="mb-4">
            <label class="form-label fw-semibold">Confirmar contraseña</label>
            <div class="input-group">
              <input type="password" id="confirmPass" class="form-control" placeholder="Repite la contraseña" required/>
              <button class="btn btn-outline-secondary" type="button" onclick="togglePass('confirmPass', this)">
                <i class="bx bx-hide"></i>
              </button>
            </div>
          </div>
          <button class="btn btn-primary w-100" type="submit" id="submitBtn">
            <span id="btnText">Guardar contraseña</span>
            <span id="btnSpin" class="spinner-border spinner-border-sm ms-2 d-none"></span>
          </button>
        </form>
      </div>

      <!-- Estado: éxito -->
      <div id="stateSuccess" class="d-none text-center">
        <div class="mb-3" style="font-size:3rem">✅</div>
        <h5 class="fw-semibold mb-2">¡Contraseña actualizada!</h5>
        <p class="text-muted small mb-4">Ya puedes iniciar sesión con tu nueva contraseña.</p>
        <a href="/login.php" class="btn btn-primary w-100">Ir al login</a>
      </div>

    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="/assets/js/password-strength.js"></script>
<script>
const TOKEN = <?= json_encode($token) ?>;

// ── Verificar token al cargar ──────────────────────────────────────────────
(async function() {
  try {
    const res  = await fetch(`/api/auth-validate-token.php?token=${encodeURIComponent(TOKEN)}`);
    const data = await res.json();
    document.getElementById('stateLoading').classList.add('d-none');
    if (data.valid) {
      document.getElementById('stateForm').classList.remove('d-none');
      document.getElementById('newPass').focus();
    } else {
      document.getElementById('stateInvalid').classList.remove('d-none');
    }
  } catch {
    document.getElementById('stateLoading').classList.add('d-none');
    document.getElementById('stateInvalid').classList.remove('d-none');
  }
})();

// ── Checklist de fuerza (password-strength.js) ──────────────────────────────
const pwStrength = initPasswordStrength({ inputId: 'newPass', rulesContainerId: 'pwRules' });

// ── Toggle password ────────────────────────────────────────────────────────
function togglePass(id, btn) {
  const inp = document.getElementById(id);
  const icon = btn.querySelector('i');
  if (inp.type === 'password') { inp.type = 'text'; icon.className = 'bx bx-show'; }
  else { inp.type = 'password'; icon.className = 'bx bx-hide'; }
}

// ── Submit ─────────────────────────────────────────────────────────────────
document.getElementById('resetForm').addEventListener('submit', async function(e) {
  e.preventDefault();

  const pw1 = document.getElementById('newPass').value;
  const pw2 = document.getElementById('confirmPass').value;
  const btn = document.getElementById('submitBtn');
  const txt = document.getElementById('btnText');
  const spn = document.getElementById('btnSpin');

  if (!pwStrength.isValid()) { showAlert('La contraseña no cumple con los requisitos mínimos.', 'warning'); return; }
  if (pw1 !== pw2)            { showAlert('Las contraseñas no coinciden.', 'warning'); return; }

  btn.disabled = true;
  txt.textContent = 'Guardando…';
  spn.classList.remove('d-none');

  try {
    const res  = await fetch('/api/auth-reset.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ token: TOKEN, password: pw1 }),
    });
    const data = await res.json();

    if (data.ok) {
      document.getElementById('stateForm').classList.add('d-none');
      document.getElementById('stateSuccess').classList.remove('d-none');
    } else {
      showAlert(data.error || 'Error al actualizar contraseña.', 'danger');
      btn.disabled = false;
      txt.textContent = 'Guardar contraseña';
      spn.classList.add('d-none');
    }
  } catch {
    showAlert('Error de conexión. Intenta nuevamente.', 'danger');
    btn.disabled = false;
    txt.textContent = 'Guardar contraseña';
    spn.classList.add('d-none');
  }
});

function showAlert(msg, type) {
  const el = document.getElementById('alertBox');
  el.className = `alert alert-${type} d-flex align-items-center gap-2 py-2 mb-3`;
  el.innerHTML = `<i class="bx bx-error-circle flex-shrink-0"></i><span>${msg}</span>`;
}
</script>
</body>
</html>
