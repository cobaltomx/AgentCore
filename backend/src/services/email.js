'use strict';

const { logger } = require('./logger');
const log = logger('Email');

/**
 * Envío de email transaccional (reset de contraseña, avisos).
 *
 * Provider-agnóstico y SIN dependencias nuevas: usa la API HTTP de Resend vía
 * `fetch` nativo. Si no hay `RESEND_API_KEY` configurada, degrada con elegancia
 * (no envía, no truena) — así en dev el flujo sigue funcionando devolviendo el
 * link en la respuesta.
 *
 * Config (.env):
 *   RESEND_API_KEY=re_...
 *   EMAIL_FROM="AgentCore <no-reply@tudominio.com>"
 */
const FROM = process.env.EMAIL_FROM || 'AgentCore <no-reply@agentcore.io>';

function isConfigured() {
  return !!process.env.RESEND_API_KEY;
}

async function sendEmail({ to, subject, html, text }) {
  if (!isConfigured()) {
    log.warn({ to, subject }, 'email NO enviado — sin proveedor (define RESEND_API_KEY)');
    return { sent: false, reason: 'unconfigured' };
  }
  try {
    const r = await fetch('https://api.resend.com/emails', {
      method: 'POST',
      headers: {
        Authorization: `Bearer ${process.env.RESEND_API_KEY}`,
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({
        from: FROM, to: [to], subject,
        html: html || undefined, text: text || undefined,
      }),
    });
    if (!r.ok) {
      const body = await r.text().catch(() => '');
      log.error({ status: r.status, body: body.slice(0, 200) }, 'envío de email falló');
      return { sent: false, reason: 'provider_error', status: r.status };
    }
    const d = await r.json().catch(() => ({}));
    log.info({ to, id: d.id }, 'email enviado');
    return { sent: true, id: d.id };
  } catch (e) {
    log.error({ err: e.message }, 'error enviando email');
    return { sent: false, reason: 'exception', error: e.message };
  }
}

/** Plantilla simple y limpia para correos transaccionales. */
function wrapHtml(title, bodyHtml, ctaLabel, ctaUrl) {
  return `<!doctype html><html><body style="margin:0;background:#f7f8fa;font-family:Inter,Arial,sans-serif;color:#24292f">
    <div style="max-width:480px;margin:0 auto;padding:32px 20px">
      <div style="background:#fff;border:1px solid #e6e9ef;border-radius:12px;padding:28px">
        <div style="font-weight:700;font-size:18px;color:#5f61e6;margin-bottom:16px">AgentCore</div>
        <h1 style="font-size:18px;margin:0 0 12px">${title}</h1>
        <div style="font-size:14px;line-height:1.6;color:#5c6572">${bodyHtml}</div>
        ${ctaUrl ? `<a href="${ctaUrl}" style="display:inline-block;margin-top:20px;background:#5f61e6;color:#fff;text-decoration:none;padding:11px 20px;border-radius:8px;font-weight:600;font-size:14px">${ctaLabel}</a>
        <div style="font-size:12px;color:#9295aa;margin-top:16px;word-break:break-all">O copia este enlace: ${ctaUrl}</div>` : ''}
      </div>
      <div style="text-align:center;font-size:12px;color:#9295aa;margin-top:16px">AgentCore · Este es un correo automático</div>
    </div></body></html>`;
}

module.exports = { sendEmail, isConfigured, wrapHtml };
