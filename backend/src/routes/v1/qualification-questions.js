'use strict';
const { z } = require('zod');

const qqSchema = z.object({
  question:         z.string().min(5).max(500),
  hint:             z.string().max(500).optional().nullable(),
  answer_type:      z.enum(['yesno','text','scale']).optional(),
  importance:       z.number().int().min(1).max(10).optional(),
  disqualify_on:    z.enum(['yes','no','any']).optional().nullable(),
  professional_id:  z.string().uuid().optional().nullable(),
  session_type_id:  z.string().uuid().optional().nullable(),
  sort_order:       z.number().int().optional(),
  is_active:        z.boolean().optional(),
});

async function qqRoutes(app) {

  // GET /api/v1/qualification-questions
  app.get('/', { onRequest: [app.requireAdmin] }, async (req) => {
    const { professional_id, session_type_id } = req.query;
    let q = `SELECT qq.*,
               p.name AS professional_name,
               st.name AS session_type_name
             FROM qualification_questions qq
             LEFT JOIN professionals p ON p.id = qq.professional_id
             LEFT JOIN consultorio_session_types st ON st.id = qq.session_type_id
             WHERE qq.tenant_id = $1 AND qq.is_active = true`;
    const vals = [req.tenant.id];
    let idx = 2;

    if (professional_id) { q += ` AND (qq.professional_id = $${idx++} OR qq.professional_id IS NULL)`; vals.push(professional_id); }
    if (session_type_id) { q += ` AND (qq.session_type_id = $${idx++} OR qq.session_type_id IS NULL)`; vals.push(session_type_id); }

    q += ' ORDER BY qq.sort_order ASC, qq.importance DESC';
    const r = await app.db.query(q, vals);
    return r.rows;
  });

  // POST /api/v1/qualification-questions
  app.post('/', { onRequest: [app.requireAdmin] }, async (req, reply) => {
    const parsed = qqSchema.safeParse(req.body);
    if (!parsed.success) return reply.code(400).send({ error: 'Datos inválidos', details: parsed.error.flatten() });
    const d = parsed.data;

    const r = await app.db.query(
      `INSERT INTO qualification_questions
         (tenant_id, professional_id, session_type_id, question, hint,
          answer_type, importance, disqualify_on, sort_order, is_active)
       VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10) RETURNING *`,
      [
        req.tenant.id, d.professional_id || null, d.session_type_id || null,
        d.question, d.hint || null, d.answer_type || 'yesno',
        d.importance || 5, d.disqualify_on || null,
        d.sort_order || 0, d.is_active ?? true,
      ]
    );
    return reply.code(201).send(r.rows[0]);
  });

  // PATCH /api/v1/qualification-questions/:id
  app.patch('/:id', { onRequest: [app.requireAdmin] }, async (req, reply) => {
    const parsed = qqSchema.partial().safeParse(req.body);
    if (!parsed.success) return reply.code(400).send({ error: 'Datos inválidos', details: parsed.error.flatten() });
    const d = parsed.data;

    const fields = [], vals = [];
    let idx = 1;
    const map = [
      ['question', d.question], ['hint', d.hint],
      ['answer_type', d.answer_type], ['importance', d.importance],
      ['disqualify_on', d.disqualify_on], ['professional_id', d.professional_id],
      ['session_type_id', d.session_type_id], ['sort_order', d.sort_order],
      ['is_active', d.is_active],
    ];
    for (const [col, val] of map) {
      if (val !== undefined) { fields.push(`${col}=$${idx++}`); vals.push(val); }
    }
    if (!fields.length) return reply.code(400).send({ error: 'Sin campos para actualizar' });

    vals.push(req.params.id, req.tenant.id);
    const r = await app.db.query(
      `UPDATE qualification_questions SET ${fields.join(', ')}
       WHERE id=$${idx} AND tenant_id=$${idx + 1} RETURNING *`,
      vals
    );
    if (!r.rows[0]) return reply.code(404).send({ error: 'No encontrada' });
    return r.rows[0];
  });

  // DELETE /api/v1/qualification-questions/:id  (soft)
  app.delete('/:id', { onRequest: [app.requireAdmin] }, async (req, reply) => {
    const r = await app.db.query(
      'UPDATE qualification_questions SET is_active=false WHERE id=$1 AND tenant_id=$2 RETURNING id',
      [req.params.id, req.tenant.id]
    );
    if (!r.rows[0]) return reply.code(404).send({ error: 'No encontrada' });
    return { ok: true };
  });
}

module.exports = qqRoutes;
