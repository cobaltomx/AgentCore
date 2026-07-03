'use strict';

/**
 * Validación de firma X-Twilio-Signature en webhooks (anti-spoofing).
 *
 * Twilio firma cada request con el Auth Token sobre la URL pública + params
 * POST ordenados. Sin esta validación, cualquiera que descubra la URL puede
 * inyectar "llamadas" falsas y quemar minutos/LLM.
 *
 * Activación: en producción SIEMPRE; en desarrollo solo si
 * TWILIO_VALIDATE_SIGNATURE=true (para no romper pruebas locales con curl).
 *
 * Multi-tenant: valida primero con el token de la plataforma; si no coincide
 * y el número destino (To) pertenece a un tenant con credenciales Twilio
 * propias, intenta con el authToken de ese tenant.
 */

const twilio = require('twilio');

const PLATFORM_TOKEN = process.env.TWILIO_AUTH_TOKEN;

function isEnabled() {
  const flag = process.env.TWILIO_VALIDATE_SIGNATURE;
  if (flag !== undefined) return flag === 'true';
  return process.env.NODE_ENV === 'production';
}

/** URL pública tal como la firmó Twilio (respeta el proxy/ngrok). */
function publicUrl(request) {
  const proto = request.headers['x-forwarded-proto'] || request.protocol || 'https';
  const host  = request.headers['x-forwarded-host'] || request.headers.host;
  return `${proto}://${host}${request.raw.url}`;
}

/** Devuelve un preHandler de Fastify que valida la firma en métodos POST. */
function twilioSignaturePreHandler(app) {
  return async function validateTwilioSignature(request, reply) {
    if (!isEnabled()) return;                       // dev: apagado por default
    if (request.method !== 'POST') return;          // <Play>/estáticos van por GET

    const signature = request.headers['x-twilio-signature'];
    const url    = publicUrl(request);
    const params = request.body || {};

    if (signature && PLATFORM_TOKEN &&
        twilio.validateRequest(PLATFORM_TOKEN, signature, url, params)) return;

    // ¿Número de un tenant con credenciales propias?
    try {
      const to = params.To || params.Called;
      if (signature && to) {
        const r = await app.db.query(
          `SELECT t.settings->'twilio'->>'authToken' AS token
             FROM agents a JOIN tenants t ON t.id = a.tenant_id
            WHERE a.phone_number = $1 OR a.whatsapp_number = $1 LIMIT 1`,
          [to]
        );
        const tenantToken = r.rows[0]?.token;
        if (tenantToken && twilio.validateRequest(tenantToken, signature, url, params)) return;
      }
    } catch (e) { app.log.warn({ err: e.message }, '[TwilioSig] lookup tenant token falló'); }

    app.log.warn({ url, hasSignature: !!signature }, '[TwilioSig] Firma inválida — request rechazado');
    return reply.code(403).send('Forbidden');
  };
}

module.exports = { twilioSignaturePreHandler, isEnabled };
