'use strict';
const { z } = require('zod');
const { toCanonicalSchedule } = require('../../services/scheduling/doctor-slots');

const dayConfigSchema = z.object({
  active:      z.boolean().optional(),
  start:       z.string().optional(),
  end:         z.string().optional(),
  break_start: z.string().nullable().optional(),
  break_end:   z.string().nullable().optional(),
});

const SPECIALTY_TYPES = ['psychology','legal','accounting','coaching','nutrition','medical','other'];

const profSchema = z.object({
  name:            z.string().min(2).max(200),
  area:            z.string().max(100).optional().nullable(),
  specialty:       z.string().max(200).optional().nullable(),
  specialty_type:  z.enum(SPECIALTY_TYPES).optional(),
  phone:           z.string().max(30).optional().nullable(),
  email:           z.string().email().optional().or(z.literal('')).or(z.null()),
  bio:             z.string().max(1000).optional().nullable(),
  license_number:  z.string().max(50).optional().nullable(),
  is_active:       z.boolean().optional(),
  schedule_config: z.record(dayConfigSchema).optional(),
  video_link:      z.string().url().optional().or(z.literal('')).or(z.null()),
  modality:        z.enum(['presencial','video','both']).optional(),
  color:           z.string().max(20).optional(),
  avatar_url:      z.string().max(500).optional().nullable(),
  sort_order:      z.number().int().optional(),
});

async function profRoutes(app) {

  // GET /api/v1/professionals
  app.get('/', { onRequest: [app.requireTenant] }, async (req) => {
    const { active_only } = req.query;
    let q = 'SELECT * FROM professionals WHERE tenant_id = $1';
    if (active_only === 'true') q += ' AND is_active = true';
    q += ' ORDER BY sort_order ASC, name ASC';
    const r = await app.db.query(q, [req.tenant.id]);
    return r.rows;
  });

  // GET /api/v1/professionals/:id
  app.get('/:id', { onRequest: [app.requireTenant] }, async (req, reply) => {
    const r = await app.db.query(
      'SELECT * FROM professionals WHERE id = $1 AND tenant_id = $2',
      [req.params.id, req.tenant.id]
    );
    if (!r.rows[0]) return reply.code(404).send({ error: 'Profesional no encontrado' });
    return r.rows[0];
  });

  // GET /api/v1/professionals/:id/availability
  app.get('/:id/availability', { onRequest: [app.requireTenant] }, async (req, reply) => {
    const { days_ahead = 14 } = req.query;
    const r = await app.db.query(
      'SELECT schedule_config FROM professionals WHERE id = $1 AND tenant_id = $2 AND is_active = true',
      [req.params.id, req.tenant.id]
    );
    if (!r.rows[0]) return reply.code(404).send({ error: 'Profesional no encontrado' });

    // Mismo generador que el bot (doctor-slots): respeta ambos formatos de
    // schedule_config y los descansos, con cálculo de zona horaria correcto.
    const { generateDoctorSlots } = require('../../services/scheduling/doctor-slots');
    const tz = 'America/Mexico_City';
    const slots = generateDoctorSlots(r.rows[0].schedule_config || {}, {
      tz, slotMins: 30, maxDays: parseInt(days_ahead) || 14,
    }).map(s => ({
      time:    s.time,
      display: new Date(s.time).toLocaleString('es-MX', {
        timeZone: tz, weekday: 'long', month: 'short',
        day: 'numeric', hour: '2-digit', minute: '2-digit',
      }),
    }));

    // Filtrar ya ocupados
    const booked = await app.db.query(
      `SELECT scheduled_at FROM sessions
       WHERE professional_id = $1 AND tenant_id = $2
         AND status NOT IN ('cancelled', 'no_show')
         AND scheduled_at >= NOW()`,
      [req.params.id, req.tenant.id]
    );
    const bookedSet = new Set(booked.rows.map(r =>
      new Date(r.scheduled_at).toISOString().slice(0, 16)
    ));

    // Retornar array directo de slots libres (sin límite artificial)
    return slots.filter(s => !bookedSet.has(new Date(s.time).toISOString().slice(0, 16)));
  });

  // POST /api/v1/professionals
  app.post('/', { onRequest: [app.requireAdmin] }, async (req, reply) => {
    const parsed = profSchema.safeParse(req.body);
    if (!parsed.success) return reply.code(400).send({ error: 'Datos inválidos', details: parsed.error.flatten() });
    const d = parsed.data;

    const initials = d.name.split(' ').map(w => w[0]).join('').slice(0, 2).toUpperCase();

    const r = await app.db.query(
      `INSERT INTO professionals
         (tenant_id, name, area, specialty, specialty_type, phone, email, bio, license_number,
          avatar_initials, avatar_url, color, is_active, schedule_config, video_link, modality, sort_order)
       VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12,$13,$14,$15,$16,$17) RETURNING *`,
      [
        req.tenant.id, d.name, d.area || null, d.specialty || null,
        d.specialty_type || 'other',
        d.phone || null, d.email || null, d.bio || null, d.license_number || null,
        initials, d.avatar_url || null, d.color || '#696cff', d.is_active ?? true,
        JSON.stringify(toCanonicalSchedule(d.schedule_config || {})),
        d.video_link || null, d.modality || 'both', d.sort_order || 0,
      ]
    );
    return reply.code(201).send(r.rows[0]);
  });

  // PATCH /api/v1/professionals/:id
  app.patch('/:id', { onRequest: [app.requireAdmin] }, async (req, reply) => {
    const parsed = profSchema.partial().safeParse(req.body);
    if (!parsed.success) return reply.code(400).send({ error: 'Datos inválidos', details: parsed.error.flatten() });
    const d = parsed.data;

    const fields = [], vals = [];
    let idx = 1;
    const map = [
      ['name', d.name], ['area', d.area], ['specialty', d.specialty],
      ['specialty_type', d.specialty_type],
      ['phone', d.phone], ['email', d.email], ['bio', d.bio],
      ['license_number', d.license_number], ['is_active', d.is_active],
      ['video_link', d.video_link], ['modality', d.modality],
      ['color', d.color], ['avatar_url', d.avatar_url], ['sort_order', d.sort_order],
    ];
    for (const [col, val] of map) {
      if (val !== undefined) { fields.push(`${col}=$${idx++}`); vals.push(val); }
    }
    if (d.schedule_config !== undefined) {
      fields.push(`schedule_config=$${idx++}`);
      vals.push(JSON.stringify(toCanonicalSchedule(d.schedule_config)));
    }
    if (!fields.length) return reply.code(400).send({ error: 'Sin campos para actualizar' });

    vals.push(req.params.id, req.tenant.id);
    const r = await app.db.query(
      `UPDATE professionals SET ${fields.join(', ')}, updated_at=now()
       WHERE id=$${idx} AND tenant_id=$${idx + 1} RETURNING *`,
      vals
    );
    if (!r.rows[0]) return reply.code(404).send({ error: 'Profesional no encontrado' });
    return r.rows[0];
  });

  // DELETE /api/v1/professionals/:id  (soft delete)
  app.delete('/:id', { onRequest: [app.requireAdmin] }, async (req, reply) => {
    const r = await app.db.query(
      'UPDATE professionals SET is_active=false, updated_at=now() WHERE id=$1 AND tenant_id=$2 RETURNING id',
      [req.params.id, req.tenant.id]
    );
    if (!r.rows[0]) return reply.code(404).send({ error: 'Profesional no encontrado' });
    return { ok: true };
  });
}

module.exports = profRoutes;
