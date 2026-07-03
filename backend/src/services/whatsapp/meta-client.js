'use strict';

/**
 * Meta WhatsApp Cloud API Client — Fase 3
 *
 * Docs: https://developers.facebook.com/docs/whatsapp/cloud-api
 *
 * Tipos de mensaje soportados:
 *   text, image, document, audio, video,
 *   interactive (buttons, list), template
 *
 * Cada tenant puede tener su propio Phone Number ID y token,
 * o compartir el de la plataforma (más simple para empezar).
 *
 * Config por tenant en tenants.settings.whatsapp:
 * {
 *   phoneNumberId: "123456789",
 *   accessToken:   "EAAxxxxx",   // token de larga duración
 *   businessId:    "987654321"
 * }
 */

const axios = require('axios');

const META_API_VERSION = 'v20.0';
const META_BASE_URL    = `https://graph.facebook.com/${META_API_VERSION}`;

class MetaWhatsAppClient {
  constructor({ phoneNumberId, accessToken }) {
    this.phoneNumberId = phoneNumberId || process.env.META_PHONE_NUMBER_ID;
    this.accessToken   = accessToken   || process.env.META_WHATSAPP_TOKEN;

    this.http = axios.create({
      baseURL: META_BASE_URL,
      headers: {
        'Authorization': `Bearer ${this.accessToken}`,
        'Content-Type':  'application/json',
      },
      timeout: 10000,
    });
  }

  // ─── Mensajes de texto ──────────────────────────────────────

  /**
   * Enviar mensaje de texto simple
   * @param {string} to      - Número destino con código país: "521XXXXXXXXXX"
   * @param {string} text    - Texto del mensaje (máx 4096 chars)
   * @param {string} preview - true para preview de links
   */
  async sendText(to, text, preview = false) {
    return this._send(to, {
      type: 'text',
      text: { body: text, preview_url: preview },
    });
  }

  // ─── Mensajes con botones interactivos ─────────────────────

  /**
   * Enviar mensaje con hasta 3 botones de respuesta rápida
   * Ideal para: confirmar cita, elegir horario, sí/no
   *
   * @param {string} to
   * @param {string} body    - Texto principal
   * @param {Array}  buttons - [{ id: 'btn_1', title: 'Sí, confirmar' }, ...]
   * @param {string} header  - Texto del header (opcional)
   * @param {string} footer  - Texto del footer (opcional)
   */
  async sendButtons(to, body, buttons, { header, footer } = {}) {
    if (buttons.length > 3) throw new Error('Máximo 3 botones permitidos por Meta');

    return this._send(to, {
      type: 'interactive',
      interactive: {
        type: 'button',
        ...(header ? { header: { type: 'text', text: header } } : {}),
        body:    { text: body },
        ...(footer ? { footer: { text: footer } } : {}),
        action: {
          buttons: buttons.map((b, i) => ({
            type:  'reply',
            reply: { id: b.id || `btn_${i}`, title: b.title.substring(0, 20) },
          })),
        },
      },
    });
  }

  /**
   * Enviar lista interactiva (menú)
   * Ideal para: horarios disponibles, servicios, opciones de FAQ
   * Hasta 10 items en hasta 10 secciones
   *
   * @param {string} to
   * @param {string} body      - Texto principal
   * @param {string} btnLabel  - Texto del botón que abre la lista (máx 20 chars)
   * @param {Array}  sections  - [{ title: 'Horarios', rows: [{ id, title, description }] }]
   * @param {string} header
   * @param {string} footer
   */
  async sendList(to, body, btnLabel, sections, { header, footer } = {}) {
    return this._send(to, {
      type: 'interactive',
      interactive: {
        type: 'list',
        ...(header ? { header: { type: 'text', text: header } } : {}),
        body:   { text: body },
        ...(footer ? { footer: { text: footer } } : {}),
        action: {
          button: btnLabel.substring(0, 20),
          sections: sections.map(s => ({
            title: s.title,
            rows:  s.rows.map(r => ({
              id:          r.id.substring(0, 200),
              title:       r.title.substring(0, 24),
              description: (r.description || '').substring(0, 72),
            })),
          })),
        },
      },
    });
  }

  // ─── Mensajes con media ─────────────────────────────────────

  /**
   * Enviar imagen con caption opcional
   * @param {string} to
   * @param {string} imageUrl - URL pública de la imagen
   * @param {string} caption
   */
  async sendImage(to, imageUrl, caption = '') {
    return this._send(to, {
      type:  'image',
      image: { link: imageUrl, caption },
    });
  }

  /**
   * Enviar documento (PDF, etc.)
   * @param {string} to
   * @param {string} docUrl   - URL pública del documento
   * @param {string} filename - Nombre que verá el usuario
   * @param {string} caption
   */
  async sendDocument(to, docUrl, filename, caption = '') {
    return this._send(to, {
      type:     'document',
      document: { link: docUrl, filename, caption },
    });
  }

  /**
   * Enviar audio (confirmación de cita en audio, etc.)
   */
  async sendAudio(to, audioUrl) {
    return this._send(to, {
      type:  'audio',
      audio: { link: audioUrl },
    });
  }

  // ─── Templates (mensajes fuera de ventana 24h) ──────────────

  /**
   * Enviar template aprobado por Meta
   * Requerido para contactar usuarios >24h después de su último mensaje
   * o para campañas outbound (Fase 7)
   *
   * @param {string} to
   * @param {string} templateName  - Nombre del template aprobado en Meta
   * @param {string} languageCode  - "es_MX" | "en_US"
   * @param {Array}  components    - Variables del template
   */
  async sendTemplate(to, templateName, languageCode = 'es_MX', components = []) {
    return this._send(to, {
      type:     'template',
      template: {
        name:     templateName,
        language: { code: languageCode },
        components,
      },
    });
  }

  /**
   * Enviar confirmación de cita usando template
   * Template debe estar aprobado en Meta con nombre "appointment_confirmation"
   */
  async sendAppointmentConfirmation(to, { name, dateStr, businessName }) {
    return this.sendTemplate(to, 'appointment_confirmation', 'es_MX', [
      {
        type: 'body',
        parameters: [
          { type: 'text', text: name },
          { type: 'text', text: dateStr },
          { type: 'text', text: businessName },
        ],
      },
    ]);
  }

  // ─── Marcar como leído ──────────────────────────────────────

  /**
   * Marcar mensaje como leído (muestra los ✓✓ azules)
   * Llamar siempre al recibir un mensaje
   */
  async markAsRead(messageId) {
    try {
      await this.http.post(`/${this.phoneNumberId}/messages`, {
        messaging_product: 'whatsapp',
        status:            'read',
        message_id:        messageId,
      });
    } catch { /* no crítico */ }
  }

  // ─── Método base ────────────────────────────────────────────

  async _send(to, messagePayload) {
    try {
      const response = await this.http.post(`/${this.phoneNumberId}/messages`, {
        messaging_product: 'whatsapp',
        recipient_type:    'individual',
        to:                normalizePhone(to),
        ...messagePayload,
      });

      return {
        success:   true,
        messageId: response.data?.messages?.[0]?.id,
        to,
      };

    } catch (err) {
      const metaError = err.response?.data?.error;
      throw new MetaError(
        metaError?.message || 'Error enviando mensaje WhatsApp',
        metaError?.code,
        err
      );
    }
  }

  /**
   * Obtener URL de media desde su ID (cuando el usuario envía imagen/doc)
   */
  async getMediaUrl(mediaId) {
    const response = await this.http.get(`/${mediaId}`);
    return response.data?.url;
  }
}

// ─── Helpers ────────────────────────────────────────────────

/**
 * Normaliza número de teléfono mexicano para Meta
 * "55 1234 5678" → "5215512345678"
 * "+52 55 1234 5678" → "5215512345678"
 */
function normalizePhone(phone) {
  let digits = phone.replace(/\D/g, '');

  // Si ya tiene código de país 52 y tiene 12 dígitos → ya está bien
  if (digits.startsWith('52') && digits.length === 12) return digits;

  // Número local de 10 dígitos → agregar 521
  if (digits.length === 10) return `521${digits}`;

  // Con +52 de 12 dígitos → ya bien
  if (digits.startsWith('521') && digits.length === 13) return digits;

  return digits;
}

/**
 * Convierte slots disponibles en formato lista interactiva de WhatsApp
 * Agrupa por día para mejor UX
 */
function slotsToWhatsAppList(slots, timezone = 'America/Mexico_City') {
  // Agrupar slots por fecha
  const byDate = {};
  for (const slot of slots) {
    const dateKey = slot.date || slot.time.split('T')[0];
    if (!byDate[dateKey]) byDate[dateKey] = [];
    byDate[dateKey].push(slot);
  }

  const sections = [];
  for (const [date, daySlots] of Object.entries(byDate)) {
    const dateLabel = new Intl.DateTimeFormat('es-MX', {
      timeZone: timezone,
      weekday: 'long',
      month:   'short',
      day:     'numeric',
    }).format(new Date(date + 'T12:00:00'));

    sections.push({
      title: dateLabel.charAt(0).toUpperCase() + dateLabel.slice(1),
      rows:  daySlots.slice(0, 5).map((s, i) => {
        const timeLabel = new Intl.DateTimeFormat('es-MX', {
          timeZone: timezone,
          hour:     '2-digit',
          minute:   '2-digit',
          hour12:   true,
        }).format(new Date(s.time));

        return {
          id:    `slot_${date}_${i}`,
          title: timeLabel,
          description: s.source === 'calcom' ? 'Disponible' : '',
          _time: s.time, // metadata
        };
      }),
    });
  }

  return sections;
}

class MetaError extends Error {
  constructor(message, code, originalError) {
    super(message);
    this.name        = 'MetaError';
    this.metaCode    = code;
    this.originalError = originalError;
  }
}

function createMetaClient(tenantSettings) {
  const waCfg = tenantSettings?.whatsapp;
  if (!waCfg?.accessToken && !process.env.META_WHATSAPP_TOKEN) return null;
  return new MetaWhatsAppClient({
    phoneNumberId: waCfg?.phoneNumberId || process.env.META_PHONE_NUMBER_ID,
    accessToken:   waCfg?.accessToken   || process.env.META_WHATSAPP_TOKEN,
  });
}

module.exports = { MetaWhatsAppClient, createMetaClient, normalizePhone, slotsToWhatsAppList, MetaError };
