'use strict';

async function leadsRoutes(app) {

  // GET /api/v1/leads
  app.get('/', { onRequest: [app.requireTenant] }, async (req) => {
    const { status, assigned_to, q, limit = 100, offset = 0 } = req.query;

    let where = ['l.tenant_id = $1'];
    const params = [req.tenant.id];
    let idx = 2;

    if (status) { where.push(`l.status = $${idx}`); params.push(status); idx++; }

    // Búsqueda por nombre o teléfono
    if (q) {
      where.push(`(l.name ILIKE $${idx} OR l.phone ILIKE $${idx})`);
      params.push(`%${q}%`);
      idx++;
    }

    // Usuarios normales ven solo sus leads asignados
    if (req.user?.role === 'user') {
      where.push(`l.assigned_to = $${idx}`);
      params.push(req.user.user_id);
      idx++;
    } else if (assigned_to) {
      where.push(`l.assigned_to = $${idx}`);
      params.push(assigned_to);
      idx++;
    }

    params.push(parseInt(limit), parseInt(offset));
    const r = await app.db.query(`
      SELECT l.*,
             u.name  AS assigned_name,
             u.email AS assigned_email,
             (SELECT COALESCE(SUM(o.total_cents),0) FROM orders o
                WHERE o.lead_id = l.id AND o.paid_at IS NOT NULL)::bigint AS spent_cents,
             (SELECT COUNT(*) FROM appointments a
                WHERE a.lead_id = l.id AND a.status='completed')::int AS visits,
             (SELECT COUNT(*) FROM conversations c WHERE c.lead_id = l.id)::int AS convs
      FROM leads l
      LEFT JOIN users u ON u.id = l.assigned_to
      WHERE ${where.join(' AND ')}
      ORDER BY l.created_at DESC
      LIMIT $${idx} OFFSET $${idx+1}
    `, params);

    return r.rows;
  });

  // GET /api/v1/leads/:id — ficha del contacto/cliente con historial unificado
  // (citas + pedidos + conversaciones enlazados por lead_id). Stats calculadas
  // EN VIVO desde las tablas enlazadas (los agregados persistidos llegan en 0.5).
  app.get('/:id', { onRequest: [app.requireTenant] }, async (req, reply) => {
    const tid = req.tenant.id, id = req.params.id;
    const lr = await app.db.query(
      `SELECT l.*, u.name AS assigned_name FROM leads l
       LEFT JOIN users u ON u.id = l.assigned_to
       WHERE l.id = $1 AND l.tenant_id = $2`, [id, tid]);
    const lead = lr.rows[0];
    if (!lead) return reply.code(404).send({ error: 'Contacto no encontrado' });

    const [appts, orders, convs] = await Promise.all([
      app.db.query(`
        SELECT a.id, a.scheduled_at, a.status, a.confirmation_status, a.notes,
               st.name AS service, d.name AS doctor
        FROM appointments a
        LEFT JOIN service_types st ON st.id = a.service_type_id
        LEFT JOIN doctors d ON d.id = a.doctor_id
        WHERE a.lead_id = $1 AND a.tenant_id = $2
        ORDER BY a.scheduled_at DESC LIMIT 50`, [id, tid]),
      app.db.query(`
        SELECT id, created_at, status, total_cents, currency, paid_at, channel
        FROM orders WHERE lead_id = $1 AND tenant_id = $2
        ORDER BY created_at DESC LIMIT 50`, [id, tid]),
      app.db.query(`
        SELECT id, channel, status, outcome, started_at, duration_secs, summary, sentiment
        FROM conversations WHERE lead_id = $1 AND tenant_id = $2
        ORDER BY started_at DESC NULLS LAST LIMIT 50`, [id, tid]),
    ]);

    const completed = appts.rows.filter(a => a.status === 'completed');
    const stats = {
      visits:            completed.length,
      appts_total:       appts.rows.length,
      no_shows:          appts.rows.filter(a => a.status === 'no_show').length,
      cancels:           appts.rows.filter(a => a.status === 'cancelled').length,
      orders_total:      orders.rows.length,
      total_spent_cents: orders.rows.filter(o => o.paid_at).reduce((s, o) => s + Number(o.total_cents || 0), 0),
      convs_total:       convs.rows.length,
      last_visit_at:     completed[0]?.scheduled_at || null,
    };
    return { lead, stats, appointments: appts.rows, orders: orders.rows, conversations: convs.rows };
  });

  // PATCH /api/v1/leads/:id/status
  app.patch('/:id/status', { onRequest: [app.requireTenant] }, async (req, reply) => {
    const { status } = req.body;
    const valid = ['new', 'contacted', 'qualified', 'converted', 'loyal', 'lost'];
    if (!valid.includes(status)) return reply.code(400).send({ error: 'Status inválido' });
    const r = await app.db.query(
      'UPDATE leads SET status = $1 WHERE id = $2 AND tenant_id = $3 RETURNING *',
      [status, req.params.id, req.tenant.id]
    );
    if (!r.rows[0]) return reply.code(404).send({ error: 'Lead no encontrado' });
    return r.rows[0];
  });

  // PATCH /api/v1/leads/:id/assign — asignar a un usuario
  app.patch('/:id/assign', { onRequest: [app.requireAdmin] }, async (req, reply) => {
    const { user_id } = req.body;

    // Verificar que el usuario pertenece al tenant
    if (user_id) {
      const check = await app.db.query(
        'SELECT id FROM users WHERE id=$1 AND tenant_id=$2',
        [user_id, req.tenant.id]
      );
      if (!check.rows[0]) return reply.code(400).send({ error: 'Usuario no pertenece al tenant' });
    }

    const r = await app.db.query(
      `UPDATE leads SET assigned_to = $1 WHERE id = $2 AND tenant_id = $3
       RETURNING id, name, status, assigned_to`,
      [user_id || null, req.params.id, req.tenant.id]
    );
    if (!r.rows[0]) return reply.code(404).send({ error: 'Lead no encontrado' });
    return r.rows[0];
  });
}

module.exports = leadsRoutes;
