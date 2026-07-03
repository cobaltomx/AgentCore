'use strict';

/**
 * Twilio client factory — soporta credenciales por tenant
 *
 * Si el tenant tiene sus propias credenciales (accountSid + authToken)
 * en settings.twilio, se usa su cuenta. Si no, se usa la cuenta de plataforma.
 *
 * Uso:
 *   const twilio = getTwilioClient(tenantSettings);
 *   await twilio.calls.create({ ... });
 */

const twilio = require('twilio');

// Credenciales de plataforma (AgentCore)
const PLATFORM_SID   = process.env.TWILIO_ACCOUNT_SID;
const PLATFORM_TOKEN = process.env.TWILIO_AUTH_TOKEN;
// El número de plataforma puede venir como TWILIO_PHONE_NUMBER o TWILIO_DEFAULT_NUMBER.
const PLATFORM_FROM  = process.env.TWILIO_PHONE_NUMBER || process.env.TWILIO_DEFAULT_NUMBER;

/**
 * Devuelve un cliente Twilio para el tenant dado.
 * tenantSettings = tenant.settings (JSONB desde DB)
 */
function getTwilioClient(tenantSettings = {}) {
  const tw = tenantSettings?.twilio ?? {};

  const sid   = tw.accountSid  || PLATFORM_SID;
  const token = tw.authToken   || PLATFORM_TOKEN;

  if (!sid || !token) {
    throw new Error('Twilio no configurado. Verifica TWILIO_ACCOUNT_SID y TWILIO_AUTH_TOKEN.');
  }

  return twilio(sid, token);
}

/**
 * Número origen (callerId) para llamadas outbound de un tenant.
 * Usa el número configurado del tenant; si no, el de plataforma.
 */
function getTwilioFrom(tenantSettings = {}) {
  return tenantSettings?.twilio?.phoneNumber || PLATFORM_FROM;
}

/**
 * Remitente para mensajes de WhatsApp. WhatsApp requiere un sender habilitado
 * (sandbox o número aprobado), que puede diferir del número de voz.
 * Prioridad: whatsapp del tenant → TWILIO_WHATSAPP_FROM → número del tenant →
 * número de plataforma.
 */
function getWhatsAppFrom(tenantSettings = {}) {
  const tw = tenantSettings?.twilio ?? {};
  return tw.whatsappNumber
      || process.env.TWILIO_WHATSAPP_FROM
      || tw.phoneNumber
      || PLATFORM_FROM
      || null;
}

/**
 * Valida que un tenant pueda hacer llamadas (tiene número configurado).
 */
function tenantHasTwilio(tenantSettings = {}) {
  const tw = tenantSettings?.twilio ?? {};
  // Tiene número y (credenciales propias O las de plataforma están disponibles)
  return !!(tw.phoneNumber && (PLATFORM_SID || tw.accountSid));
}

/**
 * Envía un WhatsApp y RASTREA su entrega para no "mentirle" al cliente.
 *
 * `messages.create()` solo encola: devuelve 'queued' aunque el mensaje vaya a
 * rebotar (p.ej. error 63016 = fuera de la ventana de 24h, típico cuando el
 * cliente llamó por teléfono y nunca abrió chat de WhatsApp). Por eso, tras
 * crear, consultamos el estado unas veces (~`pollMs`) para detectar rebotes
 * rápidos antes de que el bot afirme el envío.
 *
 * @returns {Promise<{ok:boolean,status:string,errorCode:?number,sid:?string}>}
 *   ok=true  → aceptado/encaminado (queued/sending/sent/delivered/read)
 *   ok=false → rebotó (failed/undelivered) o falló al crear
 */
async function sendWhatsAppTracked(client, { from, to, body, mediaUrl, contentSid, contentVariables }, { pollMs = 1500 } = {}) {
  const params = { from: `whatsapp:${from}`, to: `whatsapp:${to}` };
  if (contentSid) {
    // Mensaje de PLANTILLA aprobada (Content API): única forma de iniciar contacto
    // fuera de la ventana de 24h sin que rebote con 63016. Se activa cuando el
    // tenant/plataforma tiene un contentSid aprobado por Meta (ver runbook).
    params.contentSid = contentSid;
    if (contentVariables) params.contentVariables =
      typeof contentVariables === 'string' ? contentVariables : JSON.stringify(contentVariables);
  } else {
    params.body = body;                                   // freeform (solo dentro de la ventana)
    if (mediaUrl) params.mediaUrl = Array.isArray(mediaUrl) ? mediaUrl : [mediaUrl];
  }
  if (process.env.APP_URL) params.statusCallback = `${process.env.APP_URL}/webhooks/twilio/status`;

  let msg;
  try {
    msg = await client.messages.create(params);
  } catch (e) {
    return { ok: false, status: 'create_error', errorCode: e.code || null, sid: null };
  }

  const FAIL = new Set(['failed', 'undelivered']);
  const DONE = new Set(['delivered', 'read', 'sent']);
  let status = msg.status, errorCode = msg.errorCode || null;
  const deadline = Date.now() + pollMs;
  while (!FAIL.has(status) && !DONE.has(status) && Date.now() < deadline) {
    await new Promise(r => setTimeout(r, 600));
    try {
      const f = await client.messages(msg.sid).fetch();
      status = f.status; errorCode = f.errorCode || null;
    } catch { break; }
  }
  return { ok: !FAIL.has(status), status, errorCode, sid: msg.sid };
}

module.exports = { getTwilioClient, getTwilioFrom, getWhatsAppFrom, tenantHasTwilio, sendWhatsAppTracked };
