'use strict';
const { z } = require('zod');

const stSchema = z.object({
  name:              z.string().min(2).max(100),
  slug:              z.string().min(2).max(60).regex(/^[a-z0-9\-_]+$/),
  description:       z.string().max(500).optional().nullable(),
  duration_mins:     z.number().int().min(5).max(480).default(30),
  is_urgency:        z.boolean().optional().default(false),
  requires_deposit:  z.boolean().optional().default(false),
  deposit_amount:    z.number().min(0).optional().nullable(),
  prep_instructions: z.string().max(2000).optional().nullable(),
  post_instructions: z.string().max(2000).optional().nullable(),
  voice_keywords:    z.array(z.string()).optional(),
  // Multi-doctor: array de UUIDs
  doctor_ids:        z.array(z.string().uuid()).optional(),
  // Compat backward: default_doctor_id (primer elemento de doctor_ids)
  default_doctor_id: z.string().uuid().optional().nullable(),
  color:             z.string().max(20).optional(),
  icon:              z.string().max(60).optional(),
  sort_order:        z.number().int().optional(),
  is_active:         z.boolean().optional(),
});

// Subquery que devuelve info de todos los doctores asignados
const DOCTORS_SUBQUERY = `
  (SELECT COALESCE(json_agg(json_build_object(
    'id',        d.id,
    'name',      d.name,
    'specialty', d.specialty,
    'initials',  d.avatar_initials
  ) ORDER BY d.name), '[]'::json)
   FROM doctors d
   WHERE d.id = ANY(st.doctor_ids)
     AND d.is_active = true
  ) AS doctors_info
`;

async function serviceTypesRoutes(app) {

  // GET /api/v1/service-types
  app.get('/', { onRequest: [app.requireTenant] }, async (req) => {
    const r = await app.db.query(
      `SELECT st.*, ${DOCTORS_SUBQUERY}
       FROM service_types st
       WHERE st.tenant_id = $1 AND st.is_active = true
       ORDER BY st.sort_order ASC, st.name ASC`,
      [req.tenant.id]
    );
    return r.rows;
  });

  // GET /api/v1/service-types/all (incluye inactivos, solo admin)
  app.get('/all', { onRequest: [app.requireAdmin] }, async (req) => {
    const r = await app.db.query(
      `SELECT st.*, ${DOCTORS_SUBQUERY}
       FROM service_types st
       WHERE st.tenant_id = $1
       ORDER BY st.sort_order ASC, st.name ASC`,
      [req.tenant.id]
    );
    return r.rows;
  });

  // GET /api/v1/service-types/:id
  app.get('/:id', { onRequest: [app.requireTenant] }, async (req, reply) => {
    const r = await app.db.query(
      `SELECT st.*, ${DOCTORS_SUBQUERY}
       FROM service_types st
       WHERE st.id = $1 AND st.tenant_id = $2`,
      [req.params.id, req.tenant.id]
    );
    if (!r.rows[0]) return reply.code(404).send({ error: 'Tipo de servicio no encontrado' });
    return r.rows[0];
  });

  // POST /api/v1/service-types
  app.post('/', { onRequest: [app.requireAdmin] }, async (req, reply) => {
    const parsed = stSchema.safeParse(req.body);
    if (!parsed.success) return reply.code(400).send({ error: 'Datos inválidos', details: parsed.error.flatten() });
    const d = parsed.data;

    // Verificar slug único por tenant
    const dup = await app.db.query(
      'SELECT id FROM service_types WHERE tenant_id=$1 AND slug=$2',
      [req.tenant.id, d.slug]
    );
    if (dup.rows[0]) return reply.code(409).send({ error: `El slug "${d.slug}" ya existe` });

    // Normalizar doctor_ids
    const doctorIds     = d.doctor_ids || (d.default_doctor_id ? [d.default_doctor_id] : []);
    const defaultDoctor = doctorIds[0] || null;

    const r = await app.db.query(
      `INSERT INTO service_types
         (tenant_id, name, slug, description, duration_mins, is_urgency, requires_deposit,
          deposit_amount, prep_instructions, post_instructions, voice_keywords,
          doctor_ids, default_doctor_id, color, icon, sort_order, is_active)
       VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12,$13,$14,$15,$16,$17)
       RETURNING *`,
      [
        req.tenant.id, d.name, d.slug, d.description || null,
        d.duration_mins, d.is_urgency, d.requires_deposit,
        d.deposit_amount ?? 0, d.prep_instructions || null, d.post_instructions || null,
        d.voice_keywords || null,
        doctorIds, defaultDoctor,
        d.color || '#696cff', d.icon || 'bx-tooth', d.sort_order || 0, d.is_active ?? true,
      ]
    );
    return reply.code(201).send(r.rows[0]);
  });

  // PATCH /api/v1/service-types/:id
  app.patch('/:id', { onRequest: [app.requireAdmin] }, async (req, reply) => {
    const parsed = stSchema.partial().safeParse(req.body);
    if (!parsed.success) return reply.code(400).send({ error: 'Datos inválidos', details: parsed.error.flatten() });
    const d = parsed.data;

    const fields = [];
    const vals   = [];
    let   idx    = 1;

    const simple = [
      ['name',              d.name],
      ['slug',              d.slug],
      ['description',       d.description],
      ['duration_mins',     d.duration_mins],
      ['is_urgency',        d.is_urgency],
      ['requires_deposit',  d.requires_deposit],
      ['deposit_amount',    d.deposit_amount],
      ['prep_instructions', d.prep_instructions],
      ['post_instructions', d.post_instructions],
      ['voice_keywords',    d.voice_keywords],
      ['color',             d.color],
      ['icon',              d.icon],
      ['sort_order',        d.sort_order],
      ['is_active',         d.is_active],
    ];

    for (const [col, val] of simple) {
      if (val !== undefined) { fields.push(`${col}=$${idx++}`); vals.push(val); }
    }

    // doctor_ids: si viene, actualizar también default_doctor_id
    if (d.doctor_ids !== undefined) {
      fields.push(`doctor_ids=$${idx++}`);
      vals.push(d.doctor_ids);
      fields.push(`default_doctor_id=$${idx++}`);
      vals.push(d.doctor_ids[0] || null);
    } else if (d.default_doctor_id !== undefined) {
      fields.push(`default_doctor_id=$${idx++}`);
      vals.push(d.default_doctor_id);
    }

    if (!fields.length) return reply.code(400).send({ error: 'Sin campos para actualizar' });

    vals.push(req.params.id, req.tenant.id);
    const r = await app.db.query(
      `UPDATE service_types SET ${fields.join(', ')} WHERE id=$${idx} AND tenant_id=$${idx+1} RETURNING *`,
      vals
    );
    if (!r.rows[0]) return reply.code(404).send({ error: 'No encontrado' });
    return r.rows[0];
  });

  // DELETE /api/v1/service-types/:id  (soft delete)
  app.delete('/:id', { onRequest: [app.requireAdmin] }, async (req, reply) => {
    const r = await app.db.query(
      'UPDATE service_types SET is_active=false WHERE id=$1 AND tenant_id=$2 RETURNING id',
      [req.params.id, req.tenant.id]
    );
    if (!r.rows[0]) return reply.code(404).send({ error: 'No encontrado' });
    return { ok: true };
  });

  // GET /api/v1/service-types/match?q=texto — detectar tipo por voz
  app.get('/match', { onRequest: [app.requireTenant] }, async (req) => {
    const q = (req.query.q || '').toLowerCase().trim();
    if (!q) return { matched: null };

    const r = await app.db.query(
      `SELECT st.*, ${DOCTORS_SUBQUERY}
       FROM service_types st
       WHERE st.tenant_id = $1 AND st.is_active = true`,
      [req.tenant.id]
    );

    let best = null;
    for (const st of r.rows) {
      const keywords = st.voice_keywords || [st.slug, st.name.toLowerCase()];
      if (keywords.some(kw => q.includes(kw.toLowerCase()) || kw.toLowerCase().includes(q))) {
        if (!best || (st.is_urgency && !best.is_urgency)) best = st;
      }
    }
    return { matched: best };
  });
}

module.exports = serviceTypesRoutes;
