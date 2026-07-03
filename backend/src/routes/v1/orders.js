'use strict';

/**
 * Pedidos del catálogo — /api/v1/orders
 *
 * GET   /         → listar pedidos del tenant (con sus líneas)
 * PATCH /:id      → actualizar estado (fulfilled / cancelled)
 */

async function ordersRoutes(app) {

  // ── GET / — listar pedidos + líneas ───────────────────────────
  app.get('/', { onRequest: [app.requireTenant] }, async (req) => {
    const { status, limit = 100 } = req.query;
    let sql = `SELECT * FROM orders WHERE tenant_id = $1`;
    const params = [req.tenant.id];
    if (status) { sql += ` AND status = $2`; params.push(status); }
    sql += ` ORDER BY created_at DESC LIMIT ${Math.min(parseInt(limit) || 100, 200)}`;
    const orders = await app.db.query(sql, params);

    if (!orders.rows.length) return { data: [], total: 0 };

    // Cargar líneas de todos los pedidos en una query
    const ids = orders.rows.map(o => o.id);
    const items = await app.db.query(
      `SELECT * FROM order_items WHERE order_id = ANY($1::uuid[]) ORDER BY id ASC`,
      [ids]
    );
    const byOrder = {};
    for (const it of items.rows) (byOrder[it.order_id] ||= []).push(it);

    const data = orders.rows.map(o => ({ ...o, items: byOrder[o.id] || [] }));
    return { data, total: data.length };
  });

  // ── PATCH /:id — actualizar estado ────────────────────────────
  app.patch('/:id', { onRequest: [app.requireAdmin] }, async (req, reply) => {
    const { status } = req.body || {};
    const allowed = ['pending', 'paid', 'fulfilled', 'cancelled'];
    if (!allowed.includes(status)) {
      return reply.code(400).send({ error: 'Estado inválido' });
    }
    const r = await app.db.query(
      `UPDATE orders SET status=$1, updated_at=NOW()
       WHERE id=$2 AND tenant_id=$3 RETURNING *`,
      [status, req.params.id, req.tenant.id]
    );
    if (!r.rows[0]) return reply.code(404).send({ error: 'Pedido no encontrado' });
    return r.rows[0];
  });
}

module.exports = ordersRoutes;
