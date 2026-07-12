'use strict';

/**
 * Política de contraseña — fuente única de verdad en el backend.
 * DEBE reflejar exactamente las mismas reglas que
 * frontend/assets/js/password-strength.js (checklist visual) — si cambias
 * una regla, cambia ambos lados.
 */
const RULES = [
  { key: 'length',  label: 'Al menos 8 caracteres',        test: (v) => v.length >= 8 },
  { key: 'number',  label: 'Al menos 1 número',             test: (v) => /[0-9]/.test(v) },
  { key: 'lower',   label: 'Al menos 1 letra minúscula',    test: (v) => /[a-z]/.test(v) },
  { key: 'upper',   label: 'Al menos 1 letra mayúscula',    test: (v) => /[A-Z]/.test(v) },
  { key: 'special', label: 'Al menos 1 carácter especial',  test: (v) => /[^A-Za-z0-9]/.test(v) },
];

/** true/false — ¿la contraseña cumple todas las reglas? */
function isValidPassword(password) {
  const v = String(password || '');
  return v.length > 0 && RULES.every((r) => r.test(v));
}

/** Mensaje de error único y consistente para las respuestas de la API. */
const PASSWORD_POLICY_ERROR =
  'La contraseña debe tener al menos 8 caracteres, un número, una minúscula, una mayúscula y un carácter especial.';

module.exports = { isValidPassword, PASSWORD_POLICY_ERROR, RULES };
