'use strict';
const { z } = require('zod');

const stSchema = z.object({
  name:               z.string().min(2).max(100),
  slug:               z.string().min(2).max(60).regex(/^[a-z0-9\-_]+$/),
  description:        z.string().max(1000).optional().nullable(),
  professional_ids:   z.array(z.string().uuid()).optional(),
  duration_mins:      z.number().int().min(15).max(480).default(60),
  session_count:      z.number().int().min(1).max(100).default(1),
  frequency:          z.enum(['single','weekly','biweekly','monthly']).optional(),
  modality:           z.enum(['presencial','video','both']).optional(),
  requires_deposit:   z.boolean().optional().default(false),
  deposit_percent:    z.number().int().min(0).max(100).optional(),
  deposit_fixed:      z.number().min(0).optional().nullable(),
  price_per_session:  z.number().min(0).optional().nullable(),
  cancellation_hours: z.number().int().min(0).optional(),
  voice_keywords:     z.array(z.string()).optional(),
  prep_instructions:  z.string().max(2000).optional().nullable(),
  color:              z.string().max(20).optional(),
  icon:               z.string().max(60).optional(),
  sort_order:         z.number().int().optional(),
  is_active:          z.boolean().optional(),
});

const PROFS_SUBQUERY = `
  (SELECT COALESCE(json_agg(json_build_object(
      'id', p.id, 'name', p.name, 'area', p.area, 'initials', p.avatar_initials
    ) ORDER BY p.name), '[]'::json)
   FROM professionals p
   WHERE p.id = ANY(st.professional_ids) AND p.is_active = true
  ) AS professionals_info
`;

async function consulSessionTypesRoutes(app) {

  // GET /api/v1/consultorio/session-types
  app.get('/', { onRequest: [app.requireTenant] }, async (req) => {
    const r = await app.db.query(
      `SELECT st.*, ${PROFS_SUBQUERY}
       FROM consultorio_session_types st
       WHERE st.tenant_id = $1 AND st.is_active = true
       ORDER BY st.sort_order ASC, st.name ASC`,
      [req.tenant.id]
    );
    return r.rows;
  });

  // GET /api/v1/consultorio/session-types/all
  app.get('/all', { onRequest: [app.requireAdmin] }, async (req) => {
    const r = await app.db.query(
      `SELECT st.*, ${PROFS_SUBQUERY}
       FROM consultorio_session_types st
       WHERE st.tenant_id = $1
       ORDER BY st.sort_order ASC, st.name ASC`,
      [req.tenant.id]
    );
    return r.rows;
  });

  // GET /api/v1/consultorio/session-types/:id
  app.get('/:id', { onRequest: [app.requireTenant] }, async (req, reply) => {
    const r = await app.db.query(
      `SELECT st.*, ${PROFS_SUBQUERY}
       FROM consultorio_session_types st
       WHERE st.id = $1 AND st.tenant_id = $2`,
      [req.params.id, req.tenant.id]
    );
    if (!r.rows[0]) return reply.code(404).send({ error: 'Tipo de sesión no encontrado' });
    return r.rows[0];
  });

  // POST /api/v1/consultorio/session-types
  app.post('/', { onRequest: [app.requireAdmin] }, async (req, reply) => {
    const parsed = stSchema.safeParse(req.body);
    if (!parsed.success) return reply.code(400).send({ error: 'Datos inválidos', details: parsed.error.flatten() });
    const d = parsed.data;

    const dup = await app.db.query(
      'SELECT id FROM consultorio_session_types WHERE tenant_id=$1 AND slug=$2',
      [req.tenant.id, d.slug]
    );
    if (dup.rows[0]) return reply.code(409).send({ error: `El slug "${d.slug}" ya existe` });

    const r = await app.db.query(
      `INSERT INTO consultorio_session_types
         (tenant_id, name, slug, description, professional_ids, duration_mins, session_count,
          frequency, modality, requires_deposit, deposit_percent, deposit_fixed, price_per_session,
          cancellation_hours, voice_keywords, prep_instructions, color, icon, sort_order, is_active)
       VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12,$13,$14,$15,$16,$17,$18,$19,$20)
       RETURNING *`,
      [
        req.tenant.id, d.name, d.slug, d.description || null,
        d.professional_ids || [],
        d.duration_mins, d.session_count, d.frequency || 'single',
        d.modality || 'both', d.requires_deposit, d.deposit_percent ?? 50,
        d.deposit_fixed || null, d.price_per_session || null,
        d.cancellation_hours ?? 24, d.voice_keywords || null,
        d.prep_instructions || null,
        d.color || '#696cff', d.icon || 'bx-calendar-check',
        d.sort_order || 0, d.is_active ?? true,
      ]
    );
    return reply.code(201).send(r.rows[0]);
  });

  // PATCH /api/v1/consultorio/session-types/:id
  app.patch('/:id', { onRequest: [app.requireAdmin] }, async (req, reply) => {
    const parsed = stSchema.partial().safeParse(req.body);
    if (!parsed.success) return reply.code(400).send({ error: 'Datos inválidos', details: parsed.error.flatten() });
    const d = parsed.data;

    const fields = [], vals = [];
    let idx = 1;
    const simple = [
      ['name', d.name], ['slug', d.slug], ['description', d.description],
      ['duration_mins', d.duration_mins], ['session_count', d.session_count],
      ['frequency', d.frequency], ['modality', d.modality],
      ['requires_deposit', d.requires_deposit], ['deposit_percent', d.deposit_percent],
      ['deposit_fixed', d.deposit_fixed], ['price_per_session', d.price_per_session],
      ['cancellation_hours', d.cancellation_hours],
      ['voice_keywords', d.voice_keywords], ['prep_instructions', d.prep_instructions],
      ['color', d.color], ['icon', d.icon],
      ['sort_order', d.sort_order], ['is_active', d.is_active],
    ];
    for (const [col, val] of simple) {
      if (val !== undefined) { fields.push(`${col}=$${idx++}`); vals.push(val); }
    }
    if (d.professional_ids !== undefined) {
      fields.push(`professional_ids=$${idx++}`);
      vals.push(d.professional_ids);
    }
    if (!fields.length) return reply.code(400).send({ error: 'Sin campos para actualizar' });

    vals.push(req.params.id, req.tenant.id);
    const r = await app.db.query(
      `UPDATE consultorio_session_types SET ${fields.join(', ')}
       WHERE id=$${idx} AND tenant_id=$${idx + 1} RETURNING *`,
      vals
    );
    if (!r.rows[0]) return reply.code(404).send({ error: 'No encontrado' });
    return r.rows[0];
  });

  // DELETE /api/v1/consultorio/session-types/:id
  app.delete('/:id', { onRequest: [app.requireAdmin] }, async (req, reply) => {
    const r = await app.db.query(
      'UPDATE consultorio_session_types SET is_active=false WHERE id=$1 AND tenant_id=$2 RETURNING id',
      [req.params.id, req.tenant.id]
    );
    if (!r.rows[0]) return reply.code(404).send({ error: 'No encontrado' });
    return { ok: true };
  });

  // GET /api/v1/consultorio/session-types/match?q=texto
  app.get('/match', { onRequest: [app.requireTenant] }, async (req) => {
    const q = (req.query.q || '').toLowerCase().trim();
    if (!q) return { matched: null };

    const r = await app.db.query(
      `SELECT st.*, ${PROFS_SUBQUERY}
       FROM consultorio_session_types st
       WHERE st.tenant_id = $1 AND st.is_active = true`,
      [req.tenant.id]
    );

    let best = null;
    for (const st of r.rows) {
      const kws = st.voice_keywords || [st.slug, st.name.toLowerCase()];
      if (kws.some(kw => q.includes(kw.toLowerCase()) || kw.toLowerCase().includes(q))) {
        if (!best) best = st;
      }
    }
    return { matched: best };
  });
}

module.exports = consulSessionTypesRoutes;
