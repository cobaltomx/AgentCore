'use strict';

async function conversationsRoutes(app) {

  // GET /api/v1/conversations — listar con paginación y filtros
  app.get('/', { onRequest: [app.requireTenant] }, async (req) => {
    const limit  = parseInt(req.query.limit)  || 20;
    const offset = parseInt(req.query.offset) || 0;
    const { channel, agent_id, assigned_to, q } = req.query;

    let where = ['c.tenant_id = $1'];
    const params = [req.tenant.id];
    let idx = 2;

    if (channel)  { where.push(`c.channel = $${idx}`);   params.push(channel);   idx++; }
    if (agent_id) { where.push(`c.agent_id = $${idx}`);  params.push(agent_id);  idx++; }

    // Filtro: solo las que necesitan atención humana (pendientes)
    if (req.query.needs_human === '1') {
      where.push(`c.needs_human = true AND c.handoff_resolved_at IS NULL`);
    }

    // Búsqueda por nombre o teléfono del contacto
    if (q) {
      where.push(`(c.contact_name ILIKE $${idx} OR c.contact_phone ILIKE $${idx})`);
      params.push(`%${q}%`);
      idx++;
    }

    // Usuarios normales ven solo sus conversaciones asignadas
    if (req.user?.role === 'user') {
      where.push(`c.assigned_to = $${idx}`);
      params.push(req.user.user_id);
      idx++;
    } else if (assigned_to) {
      where.push(`c.assigned_to = $${idx}`);
      params.push(assigned_to);
      idx++;
    }

    // Total para paginación
    const countParams = params.slice(); // sin limit/offset
    const countResult = await app.db.query(
      `SELECT COUNT(*) AS total FROM conversations c WHERE ${where.join(' AND ')}`,
      countParams
    );
    const total = parseInt(countResult.rows[0]?.total || 0);

    params.push(limit, offset);
    const r = await app.db.query(
      `SELECT c.*, a.name AS agent_name,
              u.name AS assigned_name
       FROM conversations c
       JOIN agents a ON a.id = c.agent_id
       LEFT JOIN users u ON u.id = c.assigned_to
       WHERE ${where.join(' AND ')}
       ORDER BY c.started_at DESC
       LIMIT $${idx} OFFSET $${idx+1}`,
      params
    );
    return { data: r.rows, total, limit, offset };
  });

  // GET /api/v1/conversations/:id
  app.get('/:id', { onRequest: [app.requireTenant] }, async (req, reply) => {
    const r = await app.db.query(
      `SELECT c.*, a.name AS agent_name,
              u.name AS assigned_name, u.email AS assigned_email
       FROM conversations c
       JOIN agents a ON a.id = c.agent_id
       LEFT JOIN users u ON u.id = c.assigned_to
       WHERE c.id = $1 AND c.tenant_id = $2`,
      [req.params.id, req.tenant.id]
    );
    if (!r.rows[0]) return reply.code(404).send({ error: 'No encontrada' });
    return r.rows[0];
  });

  // GET /api/v1/conversations/:id/messages
  app.get('/:id/messages', { onRequest: [app.requireTenant] }, async (req, reply) => {
    const r = await app.db.query(
      'SELECT * FROM messages WHERE conversation_id = $1 AND tenant_id = $2 ORDER BY created_at ASC',
      [req.params.id, req.tenant.id]
    );
    if (!r.rows.length) return reply.code(404).send({ error: 'Conversación no encontrada' });
    return r.rows;
  });

  // GET /api/v1/conversations/stats — KPIs y series para el dashboard
  app.get('/stats', { onRequest: [app.requireTenant] }, async (req) => {
    const tid = req.tenant.id;

    // Hoy (fecha local del servidor, suficiente para stats)
    const todayRow = await app.db.query(
      `SELECT COUNT(*) AS count
       FROM conversations
       WHERE tenant_id = $1
         AND started_at >= CURRENT_DATE`,
      [tid]
    );

    // Últimos 7 días, agrupado por fecha y canal
    const weekRows = await app.db.query(
      `SELECT DATE(started_at) AS day, channel, COUNT(*) AS count
       FROM conversations
       WHERE tenant_id = $1
         AND started_at >= CURRENT_DATE - INTERVAL '6 days'
       GROUP BY day, channel
       ORDER BY day`,
      [tid]
    );

    // Totales por canal (all time)
    const channelRows = await app.db.query(
      `SELECT channel, COUNT(*) AS count
       FROM conversations
       WHERE tenant_id = $1
       GROUP BY channel`,
      [tid]
    );

    // Construir series de 7 días (rellenar días sin datos con 0)
    const days = [];
    for (let i = 6; i >= 0; i--) {
      const d = new Date();
      d.setDate(d.getDate() - i);
      days.push(d.toISOString().slice(0, 10));
    }

    const byDay = {};
    weekRows.rows.forEach(({ day, channel, count }) => {
      const k = day instanceof Date ? day.toISOString().slice(0, 10) : String(day).slice(0, 10);
      if (!byDay[k]) byDay[k] = {};
      byDay[k][channel] = parseInt(count);
    });

    const series = days.map(d => ({
      date:     d,
      voice:    byDay[d]?.voice    || 0,
      whatsapp: byDay[d]?.whatsapp || 0,
      webchat:  byDay[d]?.webchat  || 0,
    }));

    const channels = {};
    channelRows.rows.forEach(({ channel, count }) => {
      channels[channel] = parseInt(count);
    });

    return {
      today:    parseInt(todayRow.rows[0]?.count || 0),
      series,
      channels,
    };
  });

  // PATCH /api/v1/conversations/:id/assign — asignar a un usuario
  app.patch('/:id/assign', { onRequest: [app.requireAdmin] }, async (req, reply) => {
    const { user_id } = req.body;

    if (user_id) {
      const check = await app.db.query(
        'SELECT id FROM users WHERE id=$1 AND tenant_id=$2',
        [user_id, req.tenant.id]
      );
      if (!check.rows[0]) return reply.code(400).send({ error: 'Usuario no pertenece al tenant' });
    }

    const r = await app.db.query(
      `UPDATE conversations SET assigned_to = $1
       WHERE id = $2 AND tenant_id = $3
       RETURNING id, assigned_to`,
      [user_id || null, req.params.id, req.tenant.id]
    );
    if (!r.rows[0]) return reply.code(404).send({ error: 'Conversación no encontrada' });
    return r.rows[0];
  });

  // PATCH /api/v1/conversations/:id/handoff — atender/resolver el handoff humano
  // body: { action: 'claim' | 'resolve' }  (claim = me asigno y marco atendido)
  app.patch('/:id/handoff', { onRequest: [app.requireTenant] }, async (req, reply) => {
    const action = req.body?.action || 'resolve';
    const userId = req.userId;

    const fields = ['handoff_resolved_at = NOW()', 'needs_human = false'];
    const params = [req.params.id, req.tenant.id];
    // 'claim' además se la asigna al usuario que la atiende
    if (action === 'claim') {
      fields.push(`assigned_to = $3`);
      params.push(userId);
    }

    const r = await app.db.query(
      `UPDATE conversations SET ${fields.join(', ')}
       WHERE id = $1 AND tenant_id = $2 RETURNING id, assigned_to, needs_human`,
      params
    );
    if (!r.rows[0]) return reply.code(404).send({ error: 'Conversación no encontrada' });
    return { ok: true, ...r.rows[0] };
  });
}

module.exports = conversationsRoutes;
