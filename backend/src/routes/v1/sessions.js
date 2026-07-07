'use strict';
const { z } = require('zod');

const sessionSchema = z.object({
  professional_id:  z.string().uuid().optional().nullable(),
  session_type_id:  z.string().uuid().optional().nullable(),
  conversation_id:  z.string().uuid().optional().nullable(),
  lead_id:          z.string().uuid().optional().nullable(),
  series_id:        z.string().uuid().optional().nullable(),
  patient_name:     z.string().max(200).optional().nullable(),
  patient_phone:    z.string().max(30).optional().nullable(),
  patient_email:    z.string().email().optional().or(z.literal('')).or(z.null()),
  session_number:   z.number().int().optional(),
  scheduled_at:     z.string().datetime(),
  duration_mins:    z.number().int().min(15).max(480).optional(),
  status:           z.enum(['pending_professional','pending_patient','confirmed','completed','cancelled','no_show']).optional(),
  modality:         z.enum(['presencial','video']).optional(),
  video_link:       z.string().url().optional().or(z.literal('')).or(z.null()),
  notes:            z.string().max(1000).optional().nullable(),
});

const seriesSchema = z.object({
  professional_id:  z.string().uuid().optional().nullable(),
  session_type_id:  z.string().uuid().optional().nullable(),
  conversation_id:  z.string().uuid().optional().nullable(),
  lead_id:          z.string().uuid().optional().nullable(),
  patient_name:     z.string().max(200),
  patient_phone:    z.string().max(30).optional().nullable(),
  patient_email:    z.string().email().optional().or(z.literal('')).or(z.null()),
  total_sessions:   z.number().int().min(1).max(100),
  frequency:        z.enum(['single','weekly','biweekly','monthly']),
  modality:         z.enum(['presencial','video']),
  first_session_at: z.string().datetime(),   // primera sesión, las demás se calculan
  duration_mins:    z.number().int().min(15).max(480).optional(),
  notes:            z.string().max(1000).optional().nullable(),
});

async function sessionsRoutes(app) {

  // ── Sesiones individuales ──────────────────────────────────────

  // GET /api/v1/sessions
  app.get('/', { onRequest: [app.requireTenant] }, async (req) => {
    const { professional_id, status, from, to, limit = 50 } = req.query;
    let q = `SELECT s.*,
               p.name AS professional_name, p.avatar_initials AS professional_initials,
               st.name AS session_type_name, st.modality AS default_modality
             FROM sessions s
             LEFT JOIN professionals p ON p.id = s.professional_id
             LEFT JOIN consultorio_session_types st ON st.id = s.session_type_id
             WHERE s.tenant_id = $1`;
    const vals = [req.tenant.id];
    let idx = 2;

    if (professional_id) { q += ` AND s.professional_id = $${idx++}`; vals.push(professional_id); }
    if (status)          { q += ` AND s.status = $${idx++}`;           vals.push(status); }
    if (from)            { q += ` AND s.scheduled_at >= $${idx++}`;    vals.push(from); }
    if (to)              { q += ` AND s.scheduled_at <= $${idx++}`;    vals.push(to); }

    q += ` ORDER BY s.scheduled_at ASC LIMIT $${idx}`;
    vals.push(parseInt(limit));

    const r = await app.db.query(q, vals);
    return r.rows;
  });

  // GET /api/v1/sessions/:id
  app.get('/:id', { onRequest: [app.requireTenant] }, async (req, reply) => {
    const r = await app.db.query(
      `SELECT s.*,
         p.name AS professional_name, p.video_link AS professional_video_link,
         st.name AS session_type_name, st.cancellation_hours
       FROM sessions s
       LEFT JOIN professionals p ON p.id = s.professional_id
       LEFT JOIN consultorio_session_types st ON st.id = s.session_type_id
       WHERE s.id = $1 AND s.tenant_id = $2`,
      [req.params.id, req.tenant.id]
    );
    if (!r.rows[0]) return reply.code(404).send({ error: 'Sesión no encontrada' });
    return r.rows[0];
  });

  // GET /api/v1/sessions/stats
  app.get('/stats/summary', { onRequest: [app.requireTenant] }, async (req) => {
    const { professional_id } = req.query;
    const conds = ['tenant_id = $1'];
    const vals  = [req.tenant.id];
    if (professional_id) { conds.push(`professional_id = $${vals.length + 1}`); vals.push(professional_id); }
    const r = await app.db.query(
      `SELECT
         COUNT(*) FILTER (WHERE status = 'pending_professional') AS pending_professional,
         COUNT(*) FILTER (WHERE status = 'pending_patient')      AS pending_patient,
         COUNT(*) FILTER (WHERE status = 'confirmed')            AS confirmed,
         COUNT(*) FILTER (WHERE status = 'completed')            AS completed,
         COUNT(*) FILTER (WHERE status = 'cancelled')            AS cancelled,
         COUNT(*) FILTER (WHERE status = 'no_show')              AS no_show
       FROM sessions WHERE ${conds.join(' AND ')}`,
      vals
    );
    return r.rows[0];
  });

  // PATCH /api/v1/sessions/:id  (actualizar estado, confirmar, etc.)
  app.patch('/:id', { onRequest: [app.requireTenant] }, async (req, reply) => {
    const parsed = sessionSchema.partial().safeParse(req.body);
    if (!parsed.success) return reply.code(400).send({ error: 'Datos inválidos', details: parsed.error.flatten() });
    const d = parsed.data;

    const fields = [], vals = [];
    let idx = 1;
    const map = [
      ['status', d.status], ['modality', d.modality], ['video_link', d.video_link],
      ['scheduled_at', d.scheduled_at], ['notes', d.notes],
      ['deposit_status', d.deposit_status],
    ];
    for (const [col, val] of map) {
      if (val !== undefined) { fields.push(`${col}=$${idx++}`); vals.push(val); }
    }

    // Timestamps automáticos según estado
    if (d.status === 'confirmed' && !d.professional_confirmed_at) {
      const s = await app.db.query('SELECT status FROM sessions WHERE id=$1 AND tenant_id=$2', [req.params.id, req.tenant.id]);
      if (s.rows[0]?.status === 'pending_professional') {
        fields.push(`professional_confirmed_at=$${idx++}`); vals.push(new Date().toISOString());
        // Al cambiar a pending_patient, marcar profesional confirmado
        fields.push(`status=$${idx++}`); vals.push('pending_patient');
      } else if (s.rows[0]?.status === 'pending_patient') {
        fields.push(`patient_confirmed_at=$${idx++}`); vals.push(new Date().toISOString());
        // overwrite status to confirmed
        const si = fields.findIndex(f => f.startsWith('status'));
        if (si >= 0) { vals[si] = 'confirmed'; } else { fields.push(`status=$${idx++}`); vals.push('confirmed'); }
      }
    }

    if (!fields.length) return reply.code(400).send({ error: 'Sin campos para actualizar' });

    vals.push(req.params.id, req.tenant.id);
    const r = await app.db.query(
      `UPDATE sessions SET ${fields.join(', ')}, updated_at=now()
       WHERE id=$${idx} AND tenant_id=$${idx + 1} RETURNING *`,
      vals
    );
    if (!r.rows[0]) return reply.code(404).send({ error: 'Sesión no encontrada' });
    return r.rows[0];
  });

  // ── Series de sesiones ─────────────────────────────────────────

  // GET /api/v1/sessions/series
  app.get('/series/list', { onRequest: [app.requireTenant] }, async (req) => {
    const { professional_id, from, to } = req.query;
    const conds = ['ss.tenant_id = $1'];
    const vals  = [req.tenant.id];
    if (professional_id) { conds.push(`ss.professional_id = $${vals.length + 1}`); vals.push(professional_id); }
    if (from) { conds.push(`ss.created_at >= $${vals.length + 1}`); vals.push(from); }
    if (to)   { conds.push(`ss.created_at <= $${vals.length + 1}::date + interval '1 day'`); vals.push(to); }

    const r = await app.db.query(
      `SELECT ss.*,
         p.name AS professional_name,
         st.name AS session_type_name,
         COUNT(s.id) AS sessions_total,
         COUNT(s.id) FILTER (WHERE s.status = 'confirmed') AS sessions_confirmed_count,
         MIN(s.scheduled_at) AS first_session_at
       FROM session_series ss
       LEFT JOIN professionals p ON p.id = ss.professional_id
       LEFT JOIN consultorio_session_types st ON st.id = ss.session_type_id
       LEFT JOIN sessions s ON s.series_id = ss.id
       WHERE ${conds.join(' AND ')}
       GROUP BY ss.id, p.name, st.name
       ORDER BY ss.created_at DESC`,
      vals
    );
    return r.rows;
  });

  // GET /api/v1/sessions/series/:id  — detalle de una serie con sus sesiones
  app.get('/series/:id', { onRequest: [app.requireTenant] }, async (req, reply) => {
    const series = await app.db.query(
      `SELECT ss.*, p.name AS professional_name, st.name AS session_type_name
       FROM session_series ss
       LEFT JOIN professionals p ON p.id = ss.professional_id
       LEFT JOIN consultorio_session_types st ON st.id = ss.session_type_id
       WHERE ss.id = $1 AND ss.tenant_id = $2`,
      [req.params.id, req.tenant.id]
    );
    if (!series.rows[0]) return reply.code(404).send({ error: 'Serie no encontrada' });

    const sessions = await app.db.query(
      'SELECT * FROM sessions WHERE series_id = $1 ORDER BY session_number ASC',
      [req.params.id]
    );

    return { ...series.rows[0], sessions: sessions.rows };
  });

  // POST /api/v1/sessions/series  — crear serie + generar sesiones
  app.post('/series', { onRequest: [app.requireAdmin] }, async (req, reply) => {
    const parsed = seriesSchema.safeParse(req.body);
    if (!parsed.success) return reply.code(400).send({ error: 'Datos inválidos', details: parsed.error.flatten() });
    const d = parsed.data;

    // Obtener duración del tipo de sesión si no se especificó
    let durationMins = d.duration_mins || 60;
    if (d.session_type_id) {
      const st = await app.db.query('SELECT duration_mins FROM consultorio_session_types WHERE id=$1', [d.session_type_id]);
      if (st.rows[0]) durationMins = st.rows[0].duration_mins;
    }

    // Crear la serie
    const seriesResult = await app.db.query(
      `INSERT INTO session_series
         (tenant_id, professional_id, session_type_id, conversation_id, lead_id,
          patient_name, patient_phone, patient_email, total_sessions, frequency, modality, notes)
       VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12) RETURNING *`,
      [
        req.tenant.id, d.professional_id || null, d.session_type_id || null,
        d.conversation_id || null, d.lead_id || null,
        d.patient_name, d.patient_phone || null, d.patient_email || null,
        d.total_sessions, d.frequency, d.modality, d.notes || null,
      ]
    );
    const series = seriesResult.rows[0];

    // Calcular y crear las N sesiones
    const freqDays = { single: 0, weekly: 7, biweekly: 14, monthly: 30 };
    const gap = freqDays[d.frequency] || 7;
    const firstDate = new Date(d.first_session_at);

    const sessionInserts = [];
    for (let i = 0; i < d.total_sessions; i++) {
      const sessionDate = new Date(firstDate.getTime() + i * gap * 86400_000);
      sessionInserts.push({
        scheduledAt: sessionDate.toISOString(),
        sessionNum: i + 1,
      });
    }

    const sessions = [];
    for (const si of sessionInserts) {
      const r = await app.db.query(
        `INSERT INTO sessions
           (tenant_id, series_id, professional_id, session_type_id, conversation_id, lead_id,
            patient_name, patient_phone, patient_email, session_number,
            scheduled_at, duration_mins, status, modality)
         VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12,'pending_professional',$13)
         RETURNING *`,
        [
          req.tenant.id, series.id, d.professional_id || null, d.session_type_id || null,
          d.conversation_id || null, d.lead_id || null,
          d.patient_name, d.patient_phone || null, d.patient_email || null,
          si.sessionNum, si.scheduledAt, durationMins, d.modality,
        ]
      );
      sessions.push(r.rows[0]);
    }

    return reply.code(201).send({ series, sessions });
  });

  // PATCH /api/v1/sessions/series/:id/confirm-all  — profesional confirma todos los horarios
  app.patch('/series/:id/confirm-all', { onRequest: [app.requireAdmin] }, async (req, reply) => {
    await app.db.query(
      `UPDATE sessions
       SET status = 'pending_patient', professional_confirmed_at = now(), updated_at = now()
       WHERE series_id = $1 AND status = 'pending_professional'`,
      [req.params.id]
    );
    await app.db.query(
      `UPDATE session_series SET status = 'pending_patient', updated_at = now() WHERE id = $1`,
      [req.params.id]
    );
    return { ok: true, message: 'Horarios confirmados — pendiente confirmación del paciente' };
  });
}

module.exports = sessionsRoutes;
