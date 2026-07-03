'use strict';

/**
 * WhatsApp Message Parser — Fase 3
 *
 * Meta manda un payload bastante complejo con el webhook.
 * Este módulo lo normaliza en un formato simple y uniforme
 * que el agente puede procesar sin saber que viene de WhatsApp.
 *
 * Formato normalizado:
 * {
 *   messageId, from, timestamp, type,
 *   text,              // siempre presente (texto del mensaje o descripción)
 *   rawType,           // 'text' | 'image' | 'button' | 'list_reply' | 'audio' | 'document' | ...
 *   buttonId,          // si el usuario tocó un botón
 *   listRowId,         // si el usuario eligió de una lista
 *   mediaId,           // si el mensaje incluye media
 *   context,           // mensaje al que responde (si aplica)
 *   isButton,          // true si es respuesta a botón interactivo
 *   isList,            // true si es selección de lista
 * }
 */

/**
 * Extrae el primer mensaje de un webhook payload de Meta
 * Retorna null si no hay mensajes reales (solo status updates)
 *
 * @param {Object} body - req.body del webhook
 * @returns {Object|null} mensaje normalizado + metadata del business
 */
function parseWebhookPayload(body) {
  try {
    const entry   = body?.entry?.[0];
    const changes = entry?.changes?.[0];
    const value   = changes?.value;

    if (!value) return null;

    // Solo procesar mensajes entrantes (ignorar status updates)
    const messages = value.messages;
    if (!messages || messages.length === 0) return null;

    const message  = messages[0];
    const contacts = value.contacts?.[0];
    const metadata = value.metadata;

    const normalized = normalizeMessage(message);
    if (!normalized) return null;

    return {
      ...normalized,
      // Info del remitente
      contactName:   contacts?.profile?.name || null,
      // Info del número de negocio que recibió el mensaje
      businessPhoneId: metadata?.phone_number_id,
      displayPhone:    metadata?.display_phone_number,
    };

  } catch (err) {
    console.error('[WA Parser] Error parseando webhook:', err.message);
    return null;
  }
}

/**
 * Normaliza un mensaje individual de WhatsApp
 */
function normalizeMessage(msg) {
  if (!msg?.id || !msg?.from) return null;

  const base = {
    messageId:  msg.id,
    from:       msg.from,
    timestamp:  parseInt(msg.timestamp) * 1000, // convertir a ms
    rawType:    msg.type,
    isButton:   false,
    isList:     false,
    mediaId:    null,
    buttonId:   null,
    listRowId:  null,
    context:    msg.context || null,
    text:       '',
  };

  switch (msg.type) {

    case 'text':
      return {
        ...base,
        text: msg.text?.body || '',
      };

    case 'interactive': {
      const interactive = msg.interactive;

      if (interactive?.type === 'button_reply') {
        return {
          ...base,
          isButton:  true,
          buttonId:  interactive.button_reply?.id || '',
          text:      interactive.button_reply?.title || '',
        };
      }

      if (interactive?.type === 'list_reply') {
        return {
          ...base,
          isList:    true,
          listRowId: interactive.list_reply?.id || '',
          text:      interactive.list_reply?.title || '',
          listDescription: interactive.list_reply?.description || '',
        };
      }

      return { ...base, text: '[Respuesta interactiva]' };
    }

    case 'image':
      return {
        ...base,
        mediaId: msg.image?.id,
        text:    msg.image?.caption || '[Imagen recibida]',
        mimeType: msg.image?.mime_type,
      };

    case 'document':
      return {
        ...base,
        mediaId:  msg.document?.id,
        filename: msg.document?.filename,
        text:     msg.document?.caption || `[Documento: ${msg.document?.filename || 'archivo'}]`,
        mimeType: msg.document?.mime_type,
      };

    case 'audio':
      return {
        ...base,
        mediaId: msg.audio?.id,
        text:    '[Audio recibido — procesando transcripción]',
        mimeType: msg.audio?.mime_type,
      };

    case 'video':
      return {
        ...base,
        mediaId: msg.video?.id,
        text:    msg.video?.caption || '[Video recibido]',
      };

    case 'location':
      return {
        ...base,
        text:     `[Ubicación: ${msg.location?.name || 'sin nombre'}]`,
        location: {
          lat:     msg.location?.latitude,
          lng:     msg.location?.longitude,
          name:    msg.location?.name,
          address: msg.location?.address,
        },
      };

    case 'contacts':
      return {
        ...base,
        text: `[Contacto compartido: ${msg.contacts?.[0]?.name?.formatted_name || 'desconocido'}]`,
      };

    case 'sticker':
      return { ...base, text: '[Sticker]' };

    default:
      return { ...base, text: `[Mensaje tipo ${msg.type}]` };
  }
}

/**
 * Verifica la firma HMAC del webhook de Meta
 * Evita que cualquiera pueda mandar requests falsos
 *
 * @param {string} rawBody    - Body raw del request (string, no parseado)
 * @param {string} signature  - Header X-Hub-Signature-256
 * @param {string} appSecret  - META_APP_SECRET del .env
 */
function verifyWebhookSignature(rawBody, signature, appSecret) {
  if (!appSecret) return true; // en dev se puede desactivar

  const crypto = require('crypto');
  const expected = 'sha256=' + crypto
    .createHmac('sha256', appSecret)
    .update(rawBody, 'utf8')
    .digest('hex');

  try {
    return crypto.timingSafeEqual(
      Buffer.from(signature || ''),
      Buffer.from(expected)
    );
  } catch {
    return false;
  }
}

/**
 * Detecta si el texto del usuario es un saludo inicial
 * Meta a veces manda "hola" cuando el usuario inicia conversación
 */
function isGreeting(text) {
  const greetings = ['hola', 'hi', 'hello', 'buenas', 'buenos', 'hey', 'ola', 'buen día', 'buenas tardes', 'buenas noches'];
  const lower = text.toLowerCase().trim();
  return greetings.some(g => lower === g || lower.startsWith(g + ' ') || lower.startsWith(g + ','));
}

/**
 * Detecta si el mensaje indica intención de agendar cita
 */
function isAppointmentIntent(text) {
  const keywords = ['cita', 'agendar', 'agenda', 'reservar', 'reserva', 'appointment',
    'horario', 'disponible', 'cuando', 'cuándo', 'turno'];
  const lower = text.toLowerCase();
  return keywords.some(k => lower.includes(k));
}

module.exports = { parseWebhookPayload, normalizeMessage, verifyWebhookSignature, isGreeting, isAppointmentIntent };
