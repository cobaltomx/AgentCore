'use strict';


const { logger } = require('../logger');
const log = logger('Scheduling');
/**
 * SchedulingService — Fase 2
 *
 * Orquesta toda la lógica de agendamiento:
 * 1. Intenta Cal.com en tiempo real
 * 2. Si falla o no está configurado → slots fijos del tenant
 * 3. Persiste cita en nuestra DB (appointments) con referencia externa
 * 4. Genera confirmación verbal para el agente
 */

const { createEaClient }                      = require('./ea-client');
const { generateFixedSlots, formatSlotDisplay } = require('./calcom-client'); // slots fijos (fallback)

/**
 * Reparte slots entre VARIOS días en vez de devolver los primeros N (que caen
 * todos el mismo día / la misma mañana). Toma de cada día su 1er hueco (mañana)
 * y su último (tarde), y va alternando día por día hasta `maxSlots`. Así el bot
 * ofrece variedad real de días y horarios. Resultado en orden cronológico.
 */
function spreadAcrossDays(slots, maxSlots) {
  if (!Array.isArray(slots) || slots.length <= maxSlots) return slots || [];

  const byDay = new Map();
  for (const s of slots) {
    const day = s.date || String(s.time).slice(0, 10);
    if (!byDay.has(day)) byDay.set(day, []);
    byDay.get(day).push(s);
  }
  const days = [...byDay.values()];   // cada día ya viene en orden cronológico
  const picked = [];

  // 1ª pasada: un hueco por día, ALTERNANDO mañana/tarde para variar el horario
  // (día 0 → primero/mañana, día 1 → último/tarde, día 2 → mañana, …)
  for (let i = 0; i < days.length && picked.length < maxSlots; i++) {
    const ds = days[i];
    picked.push(i % 2 === 0 ? ds[0] : ds[ds.length - 1]);
  }
  // 2ª pasada (si quedan cupos y hay pocos días): agregar el otro extremo del día
  for (let i = 0; i < days.length && picked.length < maxSlots; i++) {
    const ds = days[i];
    const other = i % 2 === 0 ? ds[ds.length - 1] : ds[0];
    if (other && !picked.includes(other)) picked.push(other);
  }
  return picked.sort((a, b) => new Date(a.time) - new Date(b.time));
}

class SchedulingService {
  constructor({ db }) {
    this.db = db;
  }

  /**
   * Obtener slots disponibles para un tenant
   * Estrategia: Cal.com real-time → fallback slots fijos
   *
   * @param {string} tenantId
   * @param {Object} options
   * @param {number} options.daysAhead   - cuántos días hacia adelante buscar (default 5)
   * @param {number} options.maxSlots    - máximo de slots a retornar al agente (default 6)
   * @returns {Object} { slots, source: 'calcom'|'fixed', eventTypeId }
   */
  async getAvailableSlots(tenantId, { daysAhead = 5, maxSlots = 6, doctorId = null, durationMins = null } = {}) {
    const tenant   = await this._getTenant(tenantId);
    const settings = tenant.settings || {};
    const tz       = tenant.timezone || 'America/Mexico_City';
    const slotMins = durationMins || settings.scheduling?.slotDurationMins || 30;

    // ── 1) Easy!Appointments SOLO si el tenant conecta el SUYO (settings.ea).
    //    El EA global de .env ya NO manda: la config del dashboard es la verdad.
    if (settings.ea && settings.ea.baseUrl && settings.ea.apiToken) {
      try {
        const eaClient  = createEaClient(settings);
        const now       = new Date();
        const startDate = new Date(now.getTime() + 86400_000).toLocaleDateString('sv-SE', { timeZone: tz });
        const endDate   = new Date(now.getTime() + Math.max(daysAhead, 90) * 86400_000).toLocaleDateString('sv-SE', { timeZone: tz });
        const slots     = await eaClient.getAvailableSlots({ startDate, endDate, timezone: tz });
        return { slots: spreadAcrossDays(slots, maxSlots), source: 'easyappointments', total: slots.length };
      } catch (eaErr) {
        log.warn(`[Scheduling] EA propio falló para ${tenantId}, sigo con config del dashboard:`, eaErr.message);
      }
    }

    // ── 2) AUTORITATIVO: horarios por doctor/profesional del dashboard ──
    // Respeta schedule_config (horarios + descansos) y busca lejos (agendas
    // médicas pueden estar llenas por semanas/meses).
    const providers = await this._getProviders(tenantId, doctorId);
    if (providers.length) {
      const { generateDoctorSlots } = require('./doctor-slots');

      const bookedRes = await this.db.query(
        `SELECT doctor_id, scheduled_at FROM appointments
         WHERE tenant_id = $1 AND status NOT IN ('cancelled') AND scheduled_at >= NOW()`,
        [tenantId]
      );
      const bookedBy = new Map();   // providerId → Set("YYYY-MM-DDTHH:MM")
      for (const b of bookedRes.rows) {
        const k = b.doctor_id || '_';
        if (!bookedBy.has(k)) bookedBy.set(k, new Set());
        bookedBy.get(k).add(new Date(b.scheduled_at).toISOString().slice(0, 16));
      }

      const all  = [];
      const seen = new Set();
      for (const p of providers) {
        const docSlots = generateDoctorSlots(p.schedule_config || {}, { tz, slotMins, maxDays: Math.max(daysAhead, 90) });
        const booked   = bookedBy.get(p.id) || new Set();
        for (const s of docSlots) {
          const min = new Date(s.time).toISOString().slice(0, 16);
          if (booked.has(min)) continue;
          if (!doctorId && seen.has(min)) continue;   // dedup al agregar varios doctores
          seen.add(min);
          all.push({ ...s, doctorId: p.id, doctorName: p.name, display: formatSlotDisplay(s.time, tz) });
        }
      }
      all.sort((a, b) => new Date(a.time) - new Date(b.time));
      return { slots: spreadAcrossDays(all, maxSlots), source: 'dashboard', total: all.length };
    }

    // ── 3) Slots fijos del tenant (settings.scheduling) ──
    const generated = generateFixedSlots(settings.scheduling || {}, Math.max(daysAhead, 14));
    const bookedResult = await this.db.query(
      `SELECT scheduled_at FROM appointments
       WHERE tenant_id = $1 AND status NOT IN ('cancelled') AND scheduled_at >= NOW()`,
      [tenantId]
    );
    const booked = new Set(bookedResult.rows.map(r => new Date(r.scheduled_at).toISOString().slice(0, 16)));
    const freeSlots = generated.filter(s => !booked.has(new Date(s.time).toISOString().slice(0, 16)));
    return { slots: spreadAcrossDays(freeSlots, maxSlots), source: 'fixed', total: freeSlots.length };
  }

  /** Proveedores activos del tenant: doctores (clínica) o profesionales (consultorio). */
  async _getProviders(tenantId, doctorId = null) {
    for (const table of ['doctors', 'professionals']) {
      try {
        let q = `SELECT id, name, schedule_config FROM ${table} WHERE tenant_id = $1 AND is_active = true`;
        const params = [tenantId];
        if (doctorId) { q += ` AND id = $2`; params.push(doctorId); }
        const r = await this.db.query(q, params);
        if (r.rows.length) return r.rows;
      } catch { /* tabla puede no existir en ese vertical */ }
    }
    return [];
  }

  /**
   * Crear una cita
   * Persiste en Cal.com (si disponible) + nuestra DB
   *
   * @param {Object} params
   * @param {string} params.tenantId
   * @param {string} params.conversationId
   * @param {string} params.leadId           - ID del lead (opcional)
   * @param {string} params.startTime        - ISO datetime elegido
   * @param {string} params.name             - Nombre del cliente
   * @param {string} params.phone
   * @param {string} params.email
   * @param {string} params.notes
   * @param {number} params.eventTypeId      - si viene de Cal.com
   * @returns {Object} appointment
   */
  async createAppointment({ tenantId, conversationId, leadId, startTime, name, phone, email, notes, eventTypeId,
                             serviceTypeId = null, doctorId = null, durationMins: overrideDuration = null,
                             isUrgency = false, patientName = null, patientPhone = null }) {
    const tenant   = await this._getTenant(tenantId);
    const settings = tenant.settings || {};
    const tz       = tenant.timezone || 'America/Mexico_City';

    let externalRef    = null;
    let externalSource = 'manual';
    let durationMins   = settings.scheduling?.slotDurationMins || 60;

    // ── Crear en EA SOLO si el tenant conecta el suyo (consistente con la
    //    disponibilidad: el dashboard es la fuente, no el EA global de .env) ──
    const eaClient = (settings.ea && settings.ea.baseUrl && settings.ea.apiToken)
      ? createEaClient(settings) : null;
    if (eaClient) {
      try {
        const booking = await eaClient.createBooking({
          startTime, name, phone, email, notes, timezone: tz,
        });

        externalRef    = String(booking.id);
        externalSource = 'easyappointments';
        durationMins   = eaClient.slotDurationMins;

        log.info(`[Scheduling] Cita creada en EA: id=${booking.id}`);

      } catch (eaErr) {
        log.warn(`[Scheduling] EA booking falló, guardando solo en DB:`, eaErr.message);
        // No lanzar error — nuestra DB es la fuente de verdad
      }
    }

    // ── Idempotencia: si ya existe cita para esta conversación, retornarla ──
    if (conversationId) {
      const dup = await this.db.query(
        `SELECT * FROM appointments
         WHERE conversation_id = $1 AND status != 'cancelled'
         LIMIT 1`,
        [conversationId]
      );
      if (dup.rows[0]) {
        log.info(`[Scheduling] Cita duplicada bloqueada — conversationId ${conversationId} ya tiene appointmentId ${dup.rows[0].id}`);
        return {
          ...dup.rows[0],
          displayTime:    formatSlotDisplay(dup.rows[0].scheduled_at, tz),
          externalSource: dup.rows[0].external_source,
          wasDuplicate:   true,
        };
      }
    }

    // Usar duración del servicio si se especificó
    if (overrideDuration) durationMins = overrideDuration;

    // Persistir en nuestra DB
    const result = await this.db.query(
      `INSERT INTO appointments
         (tenant_id, conversation_id, lead_id, title, scheduled_at,
          duration_mins, notes, status, external_ref, external_source,
          service_type_id, doctor_id, is_urgency, patient_name, patient_phone)
       VALUES ($1, $2, $3, $4, $5, $6, $7, 'confirmed', $8, $9, $10, $11, $12, $13, $14)
       RETURNING *`,
      [
        tenantId,
        conversationId || null,
        leadId         || null,
        `Cita: ${name}`,
        startTime,
        durationMins,
        notes         || null,
        externalRef,
        externalSource,
        serviceTypeId || null,
        doctorId      || null,
        isUrgency     || false,
        patientName   || name || null,
        patientPhone  || phone || null,
      ]
    );

    const appointment = result.rows[0];

    // Actualizar outcome de la conversación
    if (conversationId) {
      await this.db.query(
        `UPDATE conversations
         SET outcome = 'appointment_booked',
             outcome_data = $1
         WHERE id = $2`,
        [JSON.stringify({ appointmentId: appointment.id, startTime, name }), conversationId]
      );
    }

    return {
      ...appointment,
      displayTime: formatSlotDisplay(startTime, tz),
      externalSource,
    };
  }

  /**
   * Cancelar una cita por ID interno
   */
  async cancelAppointment(appointmentId, tenantId, reason = 'Cancelado por el cliente') {
    const apptResult = await this.db.query(
      'SELECT * FROM appointments WHERE id = $1 AND tenant_id = $2',
      [appointmentId, tenantId]
    );

    const appt = apptResult.rows[0];
    if (!appt) throw new Error('Cita no encontrada');

    // Cancelar en Easy!Appointments si aplica
    if (appt.external_ref && appt.external_source === 'easyappointments') {
      const tenant   = await this._getTenant(tenantId);
      const eaClient = createEaClient(tenant.settings || {});
      if (eaClient) {
        try {
          await eaClient.cancelBooking(appt.external_ref);
          log.info(`[Scheduling] Cita ${appt.external_ref} cancelada en EA`);
        } catch (err) {
          log.warn('[Scheduling] EA cancel falló:', err.message);
        }
      }
    }

    // Actualizar en DB
    await this.db.query(
      `UPDATE appointments SET status = 'cancelled', notes = CONCAT(COALESCE(notes,''), $1::text) WHERE id = $2`,
      [`\nCancelado: ${reason}`, appointmentId]
    );

    // Lista de espera: al liberarse el hueco, contactar al 1º en lista
    // (fire-and-forget, no bloquea la cancelación).
    try {
      const { fillFromCancelledSlot } = require('../waitlist-service');
      fillFromCancelledSlot(this.db, tenantId, appt).catch(() => {});
    } catch { /* módulo opcional */ }

    return { success: true, appointmentId };
  }

  /**
   * Buscar citas próximas de un contacto (por teléfono)
   */
  async findUpcomingByPhone(tenantId, phone) {
    const result = await this.db.query(
      `SELECT a.* FROM appointments a
       JOIN leads l ON l.id = a.lead_id
       WHERE a.tenant_id = $1
         AND l.phone = $2
         AND a.scheduled_at > NOW()
         AND a.status IN ('confirmed', 'pending')
       ORDER BY a.scheduled_at ASC
       LIMIT 3`,
      [tenantId, phone]
    );
    return result.rows;
  }

  // ─── Helpers privados ──────────────────────────────────────

  async _getTenant(tenantId) {
    const result = await this.db.query(
      'SELECT id, timezone, settings FROM tenants WHERE id = $1',
      [tenantId]
    );
    if (!result.rows[0]) throw new Error(`Tenant ${tenantId} no encontrado`);
    return result.rows[0];
  }
}

module.exports = SchedulingService;
