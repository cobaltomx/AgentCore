'use strict';

/**
 * Cal.com API Client — Fase 2
 *
 * Soporta Cal.com self-hosted y Cloud.
 * Cada tenant puede tener su propia instancia Cal o compartir la plataforma.
 *
 * Config por tenant en tenants.settings:
 * {
 *   calcom: {
 *     baseUrl: "https://cal.tudominio.com",   // self-hosted URL
 *     apiKey:  "cal_live_xxxx",               // API key del tenant en Cal
 *     eventTypeId: 123,                        // tipo de evento por defecto
 *     timezone: "America/Mexico_City"
 *   }
 * }
 */

const axios = require('axios');

class CalcomClient {
  constructor({ baseUrl, apiKey }) {
    this.baseUrl = (baseUrl || process.env.CALCOM_BASE_URL || 'https://api.cal.com').replace(/\/$/, '');
    this.apiKey  = apiKey  || process.env.CALCOM_API_KEY;

    this.http = axios.create({
      baseURL: `${this.baseUrl}/v1`,
      headers: {
        'Content-Type': 'application/json',
      },
      timeout: 8000,
    });

    // Interceptor: inyectar apiKey en cada request
    this.http.interceptors.request.use(config => {
      config.params = { ...config.params, apiKey: this.apiKey };
      return config;
    });
  }

  /**
   * Obtener slots disponibles para un event type en un rango de fechas
   *
   * @param {Object} params
   * @param {number} params.eventTypeId  - ID del tipo de cita
   * @param {string} params.startDate    - ISO date "2025-07-01"
   * @param {string} params.endDate      - ISO date "2025-07-07"
   * @param {string} params.timezone     - "America/Mexico_City"
   * @returns {Array} slots [{ time: "2025-07-01T10:00:00-06:00" }, ...]
   */
  async getAvailableSlots({ eventTypeId, startDate, endDate, timezone = 'America/Mexico_City' }) {
    try {
      const response = await this.http.get('/slots', {
        params: {
          eventTypeId,
          startTime: startDate,
          endTime:   endDate,
          timeZone:  timezone,
        },
      });

      // Cal.com retorna { slots: { "2025-07-01": [{time: "..."}, ...], ... } }
      const slotsMap = response.data?.slots || {};
      const allSlots = [];

      for (const [date, daySlots] of Object.entries(slotsMap)) {
        for (const slot of daySlots) {
          allSlots.push({
            time:     slot.time,
            date,
            display:  formatSlotDisplay(slot.time, timezone),
          });
        }
      }

      return allSlots;

    } catch (err) {
      throw new CalcomError('Error obteniendo slots', err);
    }
  }

  /**
   * Crear una reserva (booking)
   *
   * @param {Object} params
   * @param {number} params.eventTypeId
   * @param {string} params.startTime   - ISO datetime del slot elegido
   * @param {string} params.name        - Nombre del cliente
   * @param {string} params.email       - Email del cliente (requerido por Cal.com)
   * @param {string} params.phone       - Teléfono (metadata)
   * @param {string} params.notes       - Notas adicionales
   * @param {string} params.timezone
   * @param {string} params.language    - "es" | "en"
   * @returns {Object} booking { id, uid, title, startTime, endTime, meetingUrl }
   */
  async createBooking({ eventTypeId, startTime, name, email, phone, notes, timezone = 'America/Mexico_City', language = 'es' }) {
    try {
      const response = await this.http.post('/bookings', {
        eventTypeId,
        start:    startTime,
        timeZone: timezone,
        language,
        responses: {
          name,
          email: email || `${phone?.replace(/\D/g, '')}@placeholder.agentcore.io`,
          notes: notes || '',
        },
        metadata: {
          source:   'agentcore-voice',
          phone:    phone || '',
          bookedBy: 'ai-agent',
        },
      });

      const booking = response.data;
      return {
        id:          booking.id,
        uid:         booking.uid,
        title:       booking.title,
        startTime:   booking.startTime,
        endTime:     booking.endTime,
        status:      booking.status,
        meetingUrl:  booking.meetingUrl || null,
        confirmed:   booking.status === 'ACCEPTED',
        display:     formatBookingDisplay(booking.startTime, timezone),
      };

    } catch (err) {
      if (err.response?.status === 409) {
        throw new CalcomError('El horario ya no está disponible. Por favor elige otro.', err);
      }
      throw new CalcomError('No se pudo crear la cita', err);
    }
  }

  /**
   * Cancelar una reserva
   *
   * @param {string} bookingUid  - UID de Cal.com
   * @param {string} reason
   */
  async cancelBooking(bookingUid, reason = 'Cancelado por el cliente') {
    try {
      await this.http.delete(`/bookings/${bookingUid}`, {
        data: { reason },
      });
      return { success: true };
    } catch (err) {
      throw new CalcomError('No se pudo cancelar la cita', err);
    }
  }

  /**
   * Obtener info de un event type (duración, título, etc.)
   */
  async getEventType(eventTypeId) {
    try {
      const response = await this.http.get(`/event-types/${eventTypeId}`);
      return response.data?.event_type || response.data;
    } catch (err) {
      throw new CalcomError('Error obteniendo event type', err);
    }
  }

  /**
   * Listar event types disponibles para este calendario
   */
  async listEventTypes() {
    try {
      const response = await this.http.get('/event-types');
      return response.data?.event_types || [];
    } catch (err) {
      throw new CalcomError('Error listando event types', err);
    }
  }
}

// ─── Fallback: slots fijos cuando no hay Cal.com configurado ────────────────

/**
 * Genera slots disponibles basado en config fija del tenant
 * 
 * tenants.settings.scheduling:
 * {
 *   workDays: [1,2,3,4,5],        // 0=dom, 1=lun, ... 6=sab
 *   startHour: 9, endHour: 18,    // 9am a 6pm
 *   slotDurationMins: 30,
 *   timezone: "America/Mexico_City",
 *   blockedDates: ["2025-12-25"]  // fechas bloqueadas
 * }
 */
function generateFixedSlots(schedulingConfig, daysAhead = 7) {
  const {
    workDays         = [1, 2, 3, 4, 5],
    startHour        = 9,
    endHour          = 18,
    slotDurationMins = 30,
    timezone         = 'America/Mexico_City',
    blockedDates     = [],
  } = schedulingConfig;

  const slots = [];
  const now   = new Date();

  for (let d = 1; d <= daysAhead; d++) {
    const dayUtc = new Date(now);
    dayUtc.setUTCDate(now.getUTCDate() + d);

    // Obtener la fecha calendario en la timezone del negocio (no en UTC)
    const dateStr = dayUtc.toLocaleDateString('sv-SE', { timeZone: timezone }); // "YYYY-MM-DD"

    // Día de la semana en la timezone del negocio
    const dowStr  = dayUtc.toLocaleDateString('en-US', { timeZone: timezone, weekday: 'short' });
    const dowMap  = { Sun: 0, Mon: 1, Tue: 2, Wed: 3, Thu: 4, Fri: 5, Sat: 6 };
    const dayOfWeek = dowMap[dowStr] ?? dayUtc.getDay();

    if (!workDays.includes(dayOfWeek)) continue;
    if (blockedDates.includes(dateStr)) continue;

    for (let hour = startHour; hour < endHour; hour++) {
      for (let min = 0; min < 60; min += slotDurationMins) {
        // Crear la hora en la timezone del negocio:
        // Paso 1 — crear Date UTC nominal al mismo clock time
        const nominalUtc = new Date(`${dateStr}T${String(hour).padStart(2,'0')}:${String(min).padStart(2,'0')}:00Z`);
        // Paso 2 — averiguar qué hora muestra esa fecha UTC en la timezone del negocio
        const localH = parseInt(nominalUtc.toLocaleString('en-US', { timeZone: timezone, hour: '2-digit', hour12: false }));
        // Paso 3 — calcular el desplazamiento y ajustar para que quede en hora local correcta
        let shift = hour - localH;
        if (shift > 12) shift -= 24;
        if (shift < -12) shift += 24;
        const slotDate = new Date(nominalUtc.getTime() + shift * 3600000);

        // No ofrecer slots en el pasado
        if (slotDate <= now) continue;

        slots.push({
          time:    slotDate.toISOString(),
          date:    dateStr,
          display: formatSlotDisplay(slotDate.toISOString(), timezone),
          fixed:   true,
        });
      }
    }
  }

  return slots;
}

// ─── Helpers ────────────────────────────────────────────────

function formatSlotDisplay(isoTime, timezone = 'America/Mexico_City') {
  try {
    return new Intl.DateTimeFormat('es-MX', {
      timeZone: timezone,
      weekday:  'long',
      month:    'long',
      day:      'numeric',
      hour:     '2-digit',
      minute:   '2-digit',
      hour12:   true,
    }).format(new Date(isoTime));
  } catch {
    return isoTime;
  }
}

function formatBookingDisplay(isoTime, timezone = 'America/Mexico_City') {
  return formatSlotDisplay(isoTime, timezone);
}

class CalcomError extends Error {
  constructor(message, originalError) {
    super(message);
    this.name = 'CalcomError';
    this.originalError = originalError;
    this.statusCode = originalError?.response?.status;
  }
}

/**
 * Factory: crea cliente Cal.com usando config del tenant
 * Si no tiene Cal configurado, retorna null (usar fallback fijo)
 */
function createCalcomClient(tenantSettings) {
  const calConfig = tenantSettings?.calcom;
  if (!calConfig?.apiKey && !process.env.CALCOM_API_KEY) return null;
  return new CalcomClient({
    baseUrl: calConfig?.baseUrl || process.env.CALCOM_BASE_URL,
    apiKey:  calConfig?.apiKey  || process.env.CALCOM_API_KEY,
  });
}

module.exports = { CalcomClient, createCalcomClient, generateFixedSlots, formatSlotDisplay, CalcomError };
