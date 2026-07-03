'use strict';


const { logger } = require('./logger');
const log = logger('Waitlist');
/**
 * Lista de espera (Fase 1.3). Reusa: `leads` (cliente), `sendWhatsAppTracked`
 * + `getWhatsAppFrom` + `toWhatsAppMx` (envío), y se dispara desde
 * scheduling-service.cancelAppointment cuando se libera un hueco.
 */

const { getTwilioClient, getWhatsAppFrom, sendWhatsAppTracked } = require('./twilio-client');
const { toWhatsAppMx } = require('./phone-utils');

/** Agrega un cliente a la lista de espera. */
async function addToWaitlist(db, tenantId, { leadId, serviceTypeId = null, doctorId = null, preferredFrom = null, preferredTo = null, note = null }) {
  const r = await db.query(
    `INSERT INTO waitlist (tenant_id, lead_id, service_type_id, doctor_id, preferred_from, preferred_to, note)
     VALUES ($1,$2,$3,$4,$5,$6,$7) RETURNING id`,
    [tenantId, leadId, serviceTypeId, doctorId, preferredFrom, preferredTo, note]
  );
  return r.rows[0].id;
}

/**
 * Al liberarse un hueco (cancelación), contacta al PRIMERO en lista que calce
 * (mismo doctor y/o servicio, o sin preferencia). Fire-and-forget: no bloquea.
 * @param {object} appt - la cita cancelada (doctor_id, service_type_id, scheduled_at)
 */
async function fillFromCancelledSlot(db, tenantId, appt) {
  try {
    // Primer candidato: coincide en servicio (si lo pidió) y doctor (si lo pidió).
    const cand = await db.query(
      `SELECT w.id, w.lead_id, l.name, l.phone
         FROM waitlist w JOIN leads l ON l.id = w.lead_id
        WHERE w.tenant_id = $1 AND w.status = 'waiting'
          AND (w.service_type_id IS NULL OR w.service_type_id = $2::uuid)
          AND (w.doctor_id       IS NULL OR w.doctor_id       = $3::uuid)
          AND (w.preferred_from  IS NULL OR $4::timestamptz >= w.preferred_from)
          AND (w.preferred_to    IS NULL OR $4::timestamptz <= w.preferred_to)
        ORDER BY w.created_at ASC
        LIMIT 1`,
      [tenantId, appt.service_type_id || null, appt.doctor_id || null, appt.scheduled_at]
    );
    const w = cand.rows[0];
    if (!w || !w.phone) return { filled: false };

    // Datos para el mensaje
    const t = (await db.query('SELECT name, settings FROM tenants WHERE id=$1', [tenantId])).rows[0] || {};
    const biz = t.settings?.businessProfile?.businessName || t.name || 'el negocio';
    const svc = appt.service_type_id
      ? (await db.query('SELECT name FROM service_types WHERE id=$1', [appt.service_type_id])).rows[0]?.name
      : null;
    const when = new Date(appt.scheduled_at).toLocaleString('es-MX', {
      timeZone: 'America/Mexico_City', weekday: 'long', day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit',
    });

    const twilio  = getTwilioClient(t.settings || {});
    const fromNum = getWhatsAppFrom(t.settings || {});
    let sent = false;
    if (twilio && fromNum) {
      const body = `🎉 *¡Se liberó un lugar en ${biz}!*\n\n`
        + `Hola ${w.name || ''}, quedó disponible una cita${svc ? ` de *${svc}*` : ''} el *${when}*.\n\n`
        + `¿La quieres? Responde *SÍ* para apartarla o llámanos. (Se asigna por orden de lista.)`;
      const r = await sendWhatsAppTracked(twilio, { from: fromNum, to: toWhatsAppMx(w.phone), body });
      sent = r.ok;
    }

    // Marcar como notificado (para no volver a contactar por el mismo hueco).
    await db.query(
      "UPDATE waitlist SET status='notified', notified_at=now() WHERE id=$1",
      [w.id]
    );
    log.info(`[waitlist] Hueco liberado → notificado ${w.name} (${w.phone}) · WhatsApp ${sent ? 'enviado' : 'no entregado'}`);
    return { filled: true, waitlistId: w.id, notified: w.name, whatsappSent: sent };
  } catch (e) {
    log.warn('[waitlist] fillFromCancelledSlot falló:', e.message);
    return { filled: false, error: e.message };
  }
}

module.exports = { addToWaitlist, fillFromCancelledSlot };
