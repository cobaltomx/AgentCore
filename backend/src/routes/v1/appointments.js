'use strict';

const SchedulingService = require('../../services/scheduling/scheduling-service');
const { getTwilioClient, getWhatsAppFrom, sendWhatsAppTracked } = require('../../services/twilio-client');
const { tenantHasFeature } = require('../../services/features');
const { toWhatsAppMx } = require('../../services/phone-utils');   // fuente única
const { z } = require('zod');

const createSchema = z.object({
  scheduled_at:   z.string().datetime(),
  name:           z.string().min(2),
  phone:          z.string().min(8),
  email:          z.string().email().optional(),
  notes:          z.string().optional(),
  duration_mins:  z.number().int().min(15).max(480).default(60),
  agent_id:       z.string().uuid().optional(),
});

async function appointmentsRoutes(app) {
  const scheduling = new SchedulingService({ db: app.db });

  // GET /api/v1/appointments — listar citas del tenant
  app.get('/', { onRequest: [app.requireTenant] }, async (req) => {
    const { status, from, to, limit = 50, offset = 0 } = req.query;

    let query = `
      SELECT a.*, l.name as lead_name, l.phone as lead_phone
      FROM appointments a
      LEFT JOIN leads l ON l.id = a.lead_id
      WHERE a.tenant_id = $1`;
    const params = [req.tenant.id];
    let idx = 2;

    if (status) { query += ` AND a.status = $${idx++}`; params.push(status); }
    if (from)   { query += ` AND a.scheduled_at >= $${idx++}`; params.push(from); }
    if (to)     { query += ` AND a.scheduled_at <= $${idx++}`; params.push(to); }

    query += ` ORDER BY a.scheduled_at ASC LIMIT $${idx++} OFFSET $${idx}`;
    params.push(parseInt(limit), parseInt(offset));

    const result = await app.db.query(query, params);
    return { data: result.rows, total: result.rowCount };
  });

  // GET /api/v1/appointments/availability — slots disponibles
  app.get('/availability', { onRequest: [app.requireTenant] }, async (req) => {
    const daysAhead = parseInt(req.query.days) || 5;
    const { slots, source, total } = await scheduling.getAvailableSlots(
      req.tenant.id, { daysAhead }
    );
    return { slots, source, total };
  });

  // GET /api/v1/appointments/:id
  app.get('/:id', { onRequest: [app.requireTenant] }, async (req, reply) => {
    const result = await app.db.query(
      `SELECT a.*, l.name as lead_name, l.phone as lead_phone, l.email as lead_email
       FROM appointments a LEFT JOIN leads l ON l.id = a.lead_id
       WHERE a.id = $1 AND a.tenant_id = $2`,
      [req.params.id, req.tenant.id]
    );
    if (!result.rows[0]) return reply.code(404).send({ error: 'Cita no encontrada' });
    return result.rows[0];
  });

  // POST /api/v1/appointments/:id/deposit-link — generar link de cobro de anticipo
  app.post('/:id/deposit-link', { onRequest: [app.requireTenant] }, async (req, reply) => {
    const { DepositService } = require('../../services/deposit-service');
    const deposit = new DepositService({ db: app.db });

    // Monto opcional en pesos; si no viene, usa el del tipo de servicio
    const amountPesos = req.body?.amount != null ? Number(req.body.amount) : null;
    if (amountPesos != null && (!Number.isFinite(amountPesos) || amountPesos <= 0)) {
      return reply.code(400).send({ error: 'Monto inválido' });
    }

    try {
      const result = await deposit.createDepositLink({
        appointmentId: req.params.id,
        tenantId:      req.tenant.id,
        amountCents:   amountPesos != null ? Math.round(amountPesos * 100) : null,
        appUrl:        process.env.APP_URL,
      });
      return result;
    } catch (err) {
      app.log.warn({ err: err.message }, '[Appointments] deposit-link error');
      // Errores de negocio (monto, ya pagado, Stripe no configurado) → 400
      return reply.code(400).send({ error: err.message });
    }
  });

  // POST /api/v1/appointments — crear cita manual desde dashboard
  app.post('/', { onRequest: [app.requireTenant] }, async (req, reply) => {
    const parsed = createSchema.safeParse(req.body);
    if (!parsed.success) return reply.code(400).send({ error: 'Datos inválidos', details: parsed.error.flatten() });

    const d = parsed.data;

    // Buscar o crear lead
    let leadId = null;
    const existingLead = await app.db.query(
      'SELECT id FROM leads WHERE tenant_id = $1 AND phone = $2 LIMIT 1',
      [req.tenant.id, d.phone]
    );

    if (existingLead.rows[0]) {
      leadId = existingLead.rows[0].id;
    } else {
      const newLead = await app.db.query(
        `INSERT INTO leads (tenant_id, name, phone, email, status, source_channel)
         VALUES ($1, $2, $3, $4, 'new', 'dashboard') RETURNING id`,
        [req.tenant.id, d.name, d.phone, d.email || null]
      );
      leadId = newLead.rows[0].id;
    }

    const appointment = await scheduling.createAppointment({
      tenantId:     req.tenant.id,
      leadId,
      startTime:    d.scheduled_at,
      name:         d.name,
      phone:        d.phone,
      email:        d.email,
      notes:        d.notes,
    });

    return reply.code(201).send(appointment);
  });

  // PATCH /api/v1/appointments/:id — editar campos de una cita
  app.patch('/:id', { onRequest: [app.requireTenant] }, async (req, reply) => {
    const allowed = ['scheduled_at', 'duration_mins', 'notes', 'location', 'title'];
    const updates = [];
    const values  = [];
    let idx = 1;

    for (const [k, v] of Object.entries(req.body ?? {})) {
      if (!allowed.includes(k)) continue;
      updates.push(`${k} = $${idx}`);
      values.push(v);
      idx++;
    }

    if (!updates.length) return reply.code(400).send({ error: 'Sin campos válidos para editar' });

    values.push(req.params.id, req.tenant.id);
    const result = await app.db.query(
      `UPDATE appointments
         SET ${updates.join(', ')}, updated_at = NOW()
       WHERE id = $${idx} AND tenant_id = $${idx + 1}
       RETURNING *`,
      values
    );

    if (!result.rows[0]) return reply.code(404).send({ error: 'Cita no encontrada' });
    return result.rows[0];
  });

  // PATCH /api/v1/appointments/:id/status
  app.patch('/:id/status', { onRequest: [app.requireTenant] }, async (req, reply) => {
    const { status, reason } = req.body;
    const validStatuses = ['pending', 'confirmed', 'cancelled', 'completed', 'no_show'];
    if (!validStatuses.includes(status)) {
      return reply.code(400).send({ error: `Status inválido. Válidos: ${validStatuses.join(', ')}` });
    }

    // La cita debe existir y ser de este tenant → 404 limpio (antes daba 500
    // porque cancelAppointment lanzaba excepción para una cita ajena).
    const owned = await app.db.query(
      'SELECT id FROM appointments WHERE id = $1 AND tenant_id = $2',
      [req.params.id, req.tenant.id]
    );
    if (!owned.rows[0]) return reply.code(404).send({ error: 'Cita no encontrada' });

    if (status === 'cancelled') {
      await scheduling.cancelAppointment(req.params.id, req.tenant.id, reason);
    } else {
      await app.db.query(
        'UPDATE appointments SET status = $1 WHERE id = $2 AND tenant_id = $3',
        [status, req.params.id, req.tenant.id]
      );
    }

    const result = await app.db.query(
      'SELECT * FROM appointments WHERE id = $1 AND tenant_id = $2',
      [req.params.id, req.tenant.id]
    );
    const appt = result.rows[0];

    // Política de no-show: mantener el contador del cliente al día. Recalcular
    // (idempotente) desde las citas del lead cubre marcar Y desmarcar no-show.
    if (appt?.lead_id) {
      await app.db.query(
        `UPDATE leads SET no_show_count = (
            SELECT COUNT(*) FROM appointments a
            WHERE a.lead_id = $1 AND a.tenant_id = $2 AND a.status = 'no_show')
         WHERE id = $1 AND tenant_id = $2`,
        [appt.lead_id, req.tenant.id]
      ).catch(() => {});
    }

    return appt;
  });

  // POST /api/v1/appointments/:id/remind — recordatorio MANUAL de confirmación
  // por WhatsApp. Es la acción detrás del "Recordar por WhatsApp" del dashboard:
  // permite empujar a mano las citas sin confirmar (el worker automático sólo
  // toca las ya 'confirmed' en su ventana de tiempo).
  app.post('/:id/remind', { onRequest: [app.requireTenant] }, async (req, reply) => {
    const r = await app.db.query(
      `SELECT a.*, st.name AS service_name, st.prep_instructions,
              d.name AS doctor_name, t.settings AS tenant_settings, t.name AS tenant_name
         FROM appointments a
         JOIN tenants t ON t.id = a.tenant_id
         LEFT JOIN service_types st ON st.id = a.service_type_id
         LEFT JOIN doctors d ON d.id = a.doctor_id
        WHERE a.id = $1 AND a.tenant_id = $2`,
      [req.params.id, req.tenant.id]
    );
    const appt = r.rows[0];
    if (!appt) return reply.code(404).send({ error: 'Cita no encontrada' });
    if (!appt.patient_phone)
      return reply.code(400).send({ error: 'La cita no tiene teléfono del cliente para enviar el recordatorio.' });

    if (!(await tenantHasFeature(app.db, req.tenant.id, 'whatsapp')))
      return reply.code(403).send({ error: 'El canal de WhatsApp no está habilitado en tu plan.' });

    const settings = appt.tenant_settings || {};
    const twilio   = getTwilioClient(settings);
    const fromNum  = getWhatsAppFrom(settings);
    if (!twilio || !fromNum)
      return reply.code(400).send({ error: 'No hay un remitente de WhatsApp configurado.' });

    const tz = 'America/Mexico_City';
    const displayTime = new Date(appt.scheduled_at).toLocaleString('es-MX', {
      timeZone: tz, weekday: 'long', day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit',
    });
    const bizName = settings?.businessProfile?.businessName || appt.tenant_name || 'el equipo';
    const name    = appt.patient_name || 'estimado cliente';

    const lines = [`Hola ${name}, le recordamos su cita en ${bizName}:`];
    if (appt.service_name) lines.push(`*${appt.service_name}*${appt.doctor_name ? ` con ${appt.doctor_name}` : ''}`);
    lines.push(`📅 ${displayTime}`);
    if (appt.prep_instructions) lines.push('', `📋 ${appt.prep_instructions}`);
    lines.push('', 'Por favor confirme respondiendo *CONFIRMAR*, o *CANCELAR* si no podrá asistir.');

    const waTo = toWhatsAppMx(appt.patient_phone);
    const tmpl = process.env.TWILIO_WA_TEMPLATE_APPOINTMENT;
    const result = await sendWhatsAppTracked(twilio, tmpl
      ? { from: fromNum, to: waTo, contentSid: tmpl, contentVariables: { '1': name, '2': displayTime, '3': bizName } }
      : { from: fromNum, to: waTo, body: lines.join('\n') });

    // Renueva la ventana de confirmación y registra en el historial.
    await app.db.query(
      'UPDATE appointments SET confirmation_requested_at = NOW() WHERE id = $1 AND tenant_id = $2',
      [appt.id, req.tenant.id]
    ).catch(() => {});
    await app.db.query(
      `INSERT INTO appointment_reminders (appointment_id, tenant_id, reminder_type, channel, status, message_sid)
       VALUES ($1,$2,'manual','whatsapp',$3,$4)`,
      [appt.id, req.tenant.id, result.ok ? 'sent' : 'failed', result.sid || null]
    ).catch(() => {});

    if (!result.ok)
      return reply.code(502).send({
        ok: false, status: result.status, errorCode: result.errorCode,
        error: 'No se pudo entregar el WhatsApp (probablemente fuera de la ventana de 24 h sin plantilla aprobada).',
      });
    return { ok: true, status: result.status, sid: result.sid, to: waTo };
  });

  // GET /api/v1/appointments/stats/summary — métricas para dashboard
  app.get('/stats/summary', { onRequest: [app.requireTenant] }, async (req) => {
    const result = await app.db.query(`
      SELECT
        COUNT(*) FILTER (WHERE status IN ('confirmed','pending'))                              AS upcoming,
        COUNT(*) FILTER (WHERE status = 'completed')                                           AS completed,
        COUNT(*) FILTER (WHERE status = 'cancelled')                                           AS cancelled,
        COUNT(*) FILTER (WHERE status = 'no_show')                                             AS no_show,
        COUNT(*) FILTER (WHERE scheduled_at >= date_trunc('month', NOW()))                     AS this_month,
        COUNT(*) FILTER (WHERE scheduled_at >= date_trunc('week', NOW()))                      AS this_week
      FROM appointments WHERE tenant_id = $1`,
      [req.tenant.id]
    );
    return result.rows[0];
  });
}

module.exports = appointmentsRoutes;
