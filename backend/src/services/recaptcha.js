'use strict';

const { logger } = require('./logger');
const log = logger('Recaptcha');

/**
 * Verificación de Google reCAPTCHA v3 (invisible, basado en score).
 * Protege /auth/login, /auth/forgot-password y /auth/reset-password de bots.
 *
 * Degradación elegante: sin RECAPTCHA_SECRET_KEY configurada, verify()
 * siempre pasa (skipped:true) — no bloquea el login en dev.
 */
const MIN_SCORE = parseFloat(process.env.RECAPTCHA_MIN_SCORE) || 0.5;

function isConfigured() {
  return !!process.env.RECAPTCHA_SECRET_KEY;
}

/**
 * @param {string} token   - el token que manda grecaptcha.execute() en el navegador
 * @param {string} action  - la acción esperada ('login', 'forgot_password', 'reset_password')
 * @returns {Promise<{ok:boolean, skipped?:boolean, score?:number, reason?:string}>}
 */
async function verifyRecaptcha(token, action) {
  if (!isConfigured()) return { ok: true, skipped: true };
  if (!token) return { ok: false, reason: 'missing_token' };

  try {
    const params = new URLSearchParams({ secret: process.env.RECAPTCHA_SECRET_KEY, response: token });
    const r = await fetch('https://www.google.com/recaptcha/api/siteverify', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: params.toString(),
    });
    const d = await r.json();

    if (!d.success) {
      log.warn({ errors: d['error-codes'] }, 'reCAPTCHA rechazado por Google');
      return { ok: false, reason: 'invalid_token' };
    }
    if (action && d.action && d.action !== action) {
      log.warn({ expected: action, got: d.action }, 'reCAPTCHA: acción no coincide');
      return { ok: false, reason: 'action_mismatch' };
    }
    if (typeof d.score === 'number' && d.score < MIN_SCORE) {
      log.warn({ score: d.score, min: MIN_SCORE }, 'reCAPTCHA: score bajo (posible bot)');
      return { ok: false, score: d.score, reason: 'low_score' };
    }
    return { ok: true, score: d.score };
  } catch (e) {
    // Ante fallo de red/proveedor, fail-open (no bloquear el login por
    // Google caído) pero dejar registro para monitoreo.
    log.error({ err: e.message }, 'verificación de reCAPTCHA falló, fail-open');
    return { ok: true, skipped: true, reason: 'provider_error' };
  }
}

module.exports = { isConfigured, verifyRecaptcha, MIN_SCORE };
