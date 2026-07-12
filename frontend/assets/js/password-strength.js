/**
 * Checklist de fuerza de contraseña — reglas visibles en tiempo real
 * (8+ caracteres, número, minúscula, mayúscula, carácter especial).
 * Mismas reglas que valida el backend (auth.js/users.js/superadmin.js) —
 * ver PASSWORD_RULES ahí; si cambias una regla, cambia ambos lados.
 *
 * Uso:
 *   <div id="pwRules"></div>
 *   <input id="miInputPassword" ...>
 *   <script src="/assets/js/password-strength.js"></script>
 *   <script>
 *     initPasswordStrength({ inputId: 'miInputPassword', rulesContainerId: 'pwRules' });
 *   </script>
 *
 * Devuelve un objeto con .isValid() para usarlo antes de enviar el formulario.
 */
const PASSWORD_RULES = [
  { key: 'length', label: 'Al menos 8 caracteres', test: (v) => v.length >= 8 },
  { key: 'number', label: 'Al menos 1 número', test: (v) => /[0-9]/.test(v) },
  { key: 'lower', label: 'Al menos 1 letra minúscula', test: (v) => /[a-z]/.test(v) },
  { key: 'upper', label: 'Al menos 1 letra mayúscula', test: (v) => /[A-Z]/.test(v) },
  { key: 'special', label: 'Al menos 1 carácter especial', test: (v) => /[^A-Za-z0-9]/.test(v) },
];

function initPasswordStrength({ inputId, rulesContainerId, onChange, hideWhenEmpty = false }) {
  const input = document.getElementById(inputId);
  const container = document.getElementById(rulesContainerId);
  if (!input || !container) return { isValid: () => true };

  container.innerHTML =
    '<div class="pw-rules" role="status" aria-live="polite">' +
    '<div class="pw-rules-title">Ingresa una contraseña. Debe contener:</div>' +
    '<ul class="pw-rules-list">' +
    PASSWORD_RULES.map(r =>
      `<li data-rule="${r.key}" class="pw-rule-pending"><span class="pw-rule-icon" aria-hidden="true"></span>${r.label}</li>`
    ).join('') +
    '</ul></div>';
  const box = container.querySelector('.pw-rules');

  function evaluate() {
    const val = input.value || '';
    // Campo opcional (ej. "dejar en blanco para no cambiar"): no mostrar el
    // checklist en rojo mientras esté vacío — solo al empezar a escribir.
    if (hideWhenEmpty && box) box.style.display = val.length ? '' : 'none';
    let allPass = val.length > 0;
    PASSWORD_RULES.forEach(r => {
      const pass = r.test(val);
      if (!pass) allPass = false;
      const li = container.querySelector(`li[data-rule="${r.key}"]`);
      if (li) {
        li.classList.toggle('pw-rule-pass', pass);
        li.classList.toggle('pw-rule-pending', !pass);
      }
    });
    if (hideWhenEmpty && val.length === 0) allPass = true; // vacío = válido (no se cambia)
    if (onChange) onChange(allPass);
    return allPass;
  }

  input.addEventListener('input', evaluate);
  evaluate();

  return { isValid: evaluate };
}

/** Validación pura (sin UI) — útil para checar antes de enviar el form. */
function passwordMeetsRules(value) {
  const val = value || '';
  return val.length > 0 && PASSWORD_RULES.every(r => r.test(val));
}
