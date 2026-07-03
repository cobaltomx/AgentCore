'use strict';

/**
 * Easy!Appointments Client
 * Integración con el self-hosted scheduler en http://easyappointments (Docker)
 *
 * API docs: https://easyappointments.org/docs.html#rest-api
 *
 * Flujo por turno:
 *  1. getAvailableSlots({ startDate, endDate, timezone })
 *     → llama /api/v1/availabilities?selected_date=YYYY-MM-DD&service_id=N&provider_id=N
 *       una vez por día en el rango → retorna slots libres con datetime ISO en UTC
 *  2. createBooking({ startTime, name, phone, email, notes })
 *     → POST /api/v1/appointments
 *     → retorna { id, uid, start, end } del booking en EA
 *  3. cancelBooking(eaId)
 *     → DELETE /api/v1/appointments/:id
 */

class EasyAppointmentsClient {
  /**
   * @param {Object} opts
   * @param {string} opts.baseUrl            - URL base de EA, ej "http://easyappointments"
   * @param {string} opts.apiToken           - Token generado en EA → Configuración → API
   * @param {number} opts.serviceId          - ID del servicio (ej. "Consulta dental")
   * @param {number} opts.providerId         - ID del proveedor (ej. "Dr. García")
   * @param {number} opts.slotDurationMins   - Duración de cada cita en minutos (default 60)
   */
  constructor({ baseUrl, apiToken, serviceId, providerId, slotDurationMins = 60 }) {
    this.baseUrl          = (baseUrl || '').replace(/\/$/, '');
    this.apiToken         = apiToken;
    this.serviceId        = Number(serviceId) || 1;
    this.providerId       = Number(providerId) || 1;
    this.slotDurationMins = Number(slotDurationMins) || 60;
  }

  // ─── Disponibilidad ──────────────────────────────────────────

  /**
   * Obtiene slots libres en un rango de fechas.
   * EA devuelve por día → hacemos N requests en paralelo.
   *
   * @param {Object} opts
   * @param {string} opts.startDate  - "YYYY-MM-DD" (en la TZ del negocio)
   * @param {string} opts.endDate    - "YYYY-MM-DD"
   * @param {string} opts.timezone   - "America/Mexico_City"
   * @returns {Array<{time: string, display: string, dateLocal: string, timeLocal: string}>}
   */
  async getAvailableSlots({ startDate, endDate, timezone }) {
    // Generar array de fechas en el rango
    const dates = [];
    let cursor = new Date(startDate + 'T12:00:00Z'); // mediodía para evitar DST boundary
    const last = new Date(endDate   + 'T12:00:00Z');

    while (cursor <= last) {
      dates.push(cursor.toLocaleDateString('sv-SE', { timeZone: timezone })); // YYYY-MM-DD local
      cursor = new Date(cursor.getTime() + 86400_000);
    }

    // Pedir disponibilidad para cada fecha en paralelo
    const perDay = await Promise.all(
      dates.map(d => this._getTimesForDate(d).catch(() => []))
    );

    const slots = [];
    for (let i = 0; i < dates.length; i++) {
      const dateLocal = dates[i];
      for (const timeLocal of perDay[i]) {
        // Convertir "HH:MM" local → ISO UTC
        const isoUtc = this._localToUtc(`${dateLocal}T${timeLocal}:00`, timezone);
        if (!isoUtc) continue;

        slots.push({
          time:      isoUtc,        // UTC ISO — lo que guardamos en DB y usamos para booking
          dateLocal,                // "2026-05-28" — para display
          timeLocal,                // "09:00"       — para display
          display:   `${dateLocal} ${timeLocal}`,
        });
      }
    }

    return slots;
  }

  // ─── Reservar cita ────────────────────────────────────────────

  /**
   * Crea una cita en Easy!Appointments.
   *
   * @param {Object} opts
   * @param {string} opts.startTime  - ISO UTC del slot elegido (viene de getAvailableSlots)
   * @param {string} opts.name       - Nombre completo del cliente
   * @param {string} opts.phone      - Teléfono
   * @param {string} opts.email      - Email (opcional — EA lo requiere, usamos placeholder)
   * @param {string} opts.notes      - Notas / motivo de la cita
   * @param {string} opts.timezone   - TZ del negocio (para formatear fecha en EA)
   * @returns {Object} { id, start, end } — datos de EA
   */
  async createBooking({ startTime, name, phone, email, notes, timezone = 'America/Mexico_City' }) {
    const start = new Date(startTime);
    const end   = new Date(start.getTime() + this.slotDurationMins * 60_000);

    // EA espera "YYYY-MM-DD HH:MM:SS" en su hora local configurada
    const startStr = this._toEaDateTime(start, timezone);
    const endStr   = this._toEaDateTime(end,   timezone);

    // Separar nombre en first/last (EA lo requiere)
    const parts     = (name || 'Cliente').trim().split(/\s+/);
    const firstName = parts[0];
    const lastName  = parts.slice(1).join(' ') || '-';

    const body = {
      start:             startStr,
      end:               endStr,
      notes:             notes || '',
      id_services:       this.serviceId,
      id_users_provider: this.providerId,
      customer: {
        first_name: firstName,
        last_name:  lastName,
        email:      email || `${phone || 'cliente'}@agentcore.local`,
        phone:      phone || '',
        notes:      '',
      },
    };

    const res = await this._request('POST', '/index.php/api/v1/appointments', body);
    return res; // { id, start, end, hash, ... }
  }

  // ─── Cancelar cita ────────────────────────────────────────────

  /**
   * Elimina/cancela una cita en EA.
   * @param {number|string} eaId - ID interno de EA (guardado en appointments.external_ref)
   */
  async cancelBooking(eaId) {
    await this._request('DELETE', `/index.php/api/v1/appointments/${eaId}`);
  }

  // ─── Helpers privados ─────────────────────────────────────────

  async _getTimesForDate(dateStr) {
    const url = `${this.baseUrl}/index.php/api/v1/availabilities`
      + `?date=${dateStr}`
      + `&serviceId=${this.serviceId}`
      + `&providerId=${this.providerId}`;

    const res = await fetch(url, { headers: this._headers(), signal: AbortSignal.timeout(8000) });
    if (!res.ok) {
      const text = await res.text().catch(() => '');
      throw new Error(`EA availabilities ${dateStr}: ${res.status} ${text.slice(0, 80)}`);
    }
    const data = await res.json();
    // EA devuelve string[] de tiempos: ["09:00","10:00","11:00"]
    return Array.isArray(data) ? data : [];
  }

  async _request(method, path, body) {
    const opts = {
      method,
      headers: { ...this._headers(), 'Content-Type': 'application/json' },
      signal:  AbortSignal.timeout(10_000),
    };
    if (body) opts.body = JSON.stringify(body);

    const res = await fetch(`${this.baseUrl}${path}`, opts);
    if (!res.ok) {
      const text = await res.text().catch(() => '');
      throw new Error(`EA ${method} ${path}: HTTP ${res.status} — ${text.slice(0, 120)}`);
    }
    if (method === 'DELETE') return { ok: true };
    return res.json();
  }

  _headers() {
    return { 'Authorization': `Bearer ${this.apiToken}` };
  }

  /**
   * Convierte "YYYY-MM-DDTHH:MM:SS" local a ISO UTC.
   * Usa el mismo truco de offset Intl que calcom-client.js
   */
  _localToUtc(localIso, timezone) {
    try {
      // Crear fecha como si fuera UTC, luego medir el offset real
      const fake     = new Date(localIso + 'Z');
      const localH   = parseInt(fake.toLocaleString('en-US', { timeZone: timezone, hour: '2-digit', hour12: false }));
      const fakeH    = fake.getUTCHours();
      let   shiftH   = fakeH - localH;
      if (shiftH >  12) shiftH -= 24;
      if (shiftH < -12) shiftH += 24;
      return new Date(fake.getTime() + shiftH * 3_600_000).toISOString();
    } catch {
      return null;
    }
  }

  /**
   * Formatea un Date a "YYYY-MM-DD HH:MM:SS" en la TZ del negocio (para EA).
   */
  _toEaDateTime(date, timezone) {
    // EA espera hora local, no UTC
    const pad = n => String(n).padStart(2, '0');
    const parts = new Intl.DateTimeFormat('en-US', {
      timeZone: timezone,
      year: 'numeric', month: '2-digit', day: '2-digit',
      hour: '2-digit', minute: '2-digit', second: '2-digit',
      hour12: false,
    }).formatToParts(date);

    const p = Object.fromEntries(parts.map(x => [x.type, x.value]));
    return `${p.year}-${p.month}-${p.day} ${p.hour === '24' ? '00' : p.hour}:${p.minute}:${p.second}`;
  }
}

// ─── Factory ──────────────────────────────────────────────────

/**
 * Crea cliente EA desde la config del tenant (settings.ea) o variables de entorno.
 * Retorna null si no está configurado.
 */
function createEaClient(tenantSettings = {}) {
  const ea  = tenantSettings.ea || {};
  const url = ea.baseUrl    || process.env.EA_BASE_URL;
  const tok = ea.apiToken   || process.env.EA_API_TOKEN;

  if (!url || !tok) return null;

  return new EasyAppointmentsClient({
    baseUrl:          url,
    apiToken:         tok,
    serviceId:        ea.serviceId        || process.env.EA_SERVICE_ID  || 1,
    providerId:       ea.providerId       || process.env.EA_PROVIDER_ID || 1,
    slotDurationMins: ea.slotDurationMins || process.env.EA_SLOT_DURATION_MINS || 60,
  });
}

module.exports = { EasyAppointmentsClient, createEaClient };
