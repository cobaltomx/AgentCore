'use strict';

/**
 * Catálogo de productos — /api/v1/products
 *
 * GET    /            → listar productos del tenant
 * POST   /            → crear producto
 * PATCH  /:id         → actualizar producto
 * DELETE /:id         → eliminar (soft: is_active=false) o hard si ?hard=1
 * GET    /categories  → categorías distintas (para filtros del catálogo)
 */

const { z } = require('zod');

const productSchema = z.object({
  name:        z.string().min(1).max(160),
  description: z.string().max(2000).optional().nullable(),
  price:       z.number().nonnegative(),          // en pesos (se convierte a centavos)
  currency:    z.string().length(3).optional(),
  image_url:   z.string().max(1000).optional().nullable(),
  category:    z.string().max(80).optional().nullable(),
  sku:         z.string().max(80).optional().nullable(),
  stock:       z.number().int().nonnegative().optional().nullable(),
  is_active:   z.boolean().optional(),
  sort_order:  z.number().int().optional(),
  attributes:  z.record(z.any()).optional(),              // datos estructurados (recámaras, m², zona, etc.)
  images:      z.array(z.string().max(1000)).optional(),  // galería de fotos (URLs)
});

async function productsRoutes(app) {

  // ── GET / — listar ────────────────────────────────────────────
  app.get('/', { onRequest: [app.requireTenant] }, async (req) => {
    const { category, active } = req.query;
    let sql = `SELECT * FROM products WHERE tenant_id = $1`;
    const params = [req.tenant.id];
    let i = 2;
    if (category)      { sql += ` AND category = $${i++}`; params.push(category); }
    if (active === '1'){ sql += ` AND is_active = true`; }
    sql += ` ORDER BY sort_order ASC, created_at DESC`;
    const r = await app.db.query(sql, params);
    return { data: r.rows, total: r.rowCount };
  });

  // ── GET /categories ───────────────────────────────────────────
  app.get('/categories', { onRequest: [app.requireTenant] }, async (req) => {
    const r = await app.db.query(
      `SELECT DISTINCT category FROM products
       WHERE tenant_id=$1 AND category IS NOT NULL AND category <> ''
       ORDER BY category`,
      [req.tenant.id]
    );
    return r.rows.map(x => x.category);
  });

  // ── POST / — crear ────────────────────────────────────────────
  app.post('/', { onRequest: [app.requireAdmin] }, async (req, reply) => {
    const parsed = productSchema.safeParse(req.body);
    if (!parsed.success) {
      return reply.code(400).send({ error: 'Datos inválidos', details: parsed.error.flatten() });
    }
    const d = parsed.data;
    const r = await app.db.query(
      `INSERT INTO products
         (tenant_id, name, description, price_cents, currency, image_url, category, sku, stock, is_active, sort_order, attributes, images)
       VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12,$13) RETURNING *`,
      [req.tenant.id, d.name, d.description || null, Math.round(d.price * 100),
       d.currency || 'mxn', d.image_url || null, d.category || null, d.sku || null,
       d.stock ?? null, d.is_active ?? true, d.sort_order ?? 0,
       JSON.stringify(d.attributes || {}), JSON.stringify(d.images || [])]
    );
    return reply.code(201).send(r.rows[0]);
  });

  // ── PATCH /:id — actualizar ───────────────────────────────────
  app.patch('/:id', { onRequest: [app.requireAdmin] }, async (req, reply) => {
    const check = await app.db.query(
      'SELECT id FROM products WHERE id=$1 AND tenant_id=$2',
      [req.params.id, req.tenant.id]
    );
    if (!check.rows[0]) return reply.code(404).send({ error: 'Producto no encontrado' });

    const parsed = productSchema.partial().safeParse(req.body);
    if (!parsed.success) {
      return reply.code(400).send({ error: 'Datos inválidos', details: parsed.error.flatten() });
    }
    const d = parsed.data;

    const fields = [];
    const vals   = [];
    let i = 1;
    const set = (col, val) => { fields.push(`${col} = $${i++}`); vals.push(val); };

    if (d.name        !== undefined) set('name', d.name);
    if (d.description !== undefined) set('description', d.description || null);
    if (d.price       !== undefined) set('price_cents', Math.round(d.price * 100));
    if (d.currency    !== undefined) set('currency', d.currency);
    if (d.image_url  !== undefined) set('image_url', d.image_url || null);
    if (d.category   !== undefined) set('category', d.category || null);
    if (d.sku         !== undefined) set('sku', d.sku || null);
    if (d.stock       !== undefined) set('stock', d.stock ?? null);
    if (d.is_active  !== undefined) set('is_active', d.is_active);
    if (d.sort_order !== undefined) set('sort_order', d.sort_order);
    if (d.attributes !== undefined) set('attributes', JSON.stringify(d.attributes || {}));
    if (d.images      !== undefined) set('images', JSON.stringify(d.images || []));

    if (!fields.length) return reply.code(400).send({ error: 'Sin campos para actualizar' });

    vals.push(req.params.id, req.tenant.id);
    const r = await app.db.query(
      `UPDATE products SET ${fields.join(', ')}, updated_at=NOW()
       WHERE id=$${i++} AND tenant_id=$${i} RETURNING *`,
      vals
    );
    return r.rows[0];
  });

  // ── DELETE /:id — soft delete (o hard con ?hard=1) ────────────
  app.delete('/:id', { onRequest: [app.requireAdmin] }, async (req, reply) => {
    const hard = req.query.hard === '1';
    if (hard) {
      const r = await app.db.query(
        'DELETE FROM products WHERE id=$1 AND tenant_id=$2 RETURNING id',
        [req.params.id, req.tenant.id]
      );
      if (!r.rows[0]) return reply.code(404).send({ error: 'Producto no encontrado' });
      return { ok: true, deleted: true };
    }
    const r = await app.db.query(
      'UPDATE products SET is_active=false, updated_at=NOW() WHERE id=$1 AND tenant_id=$2 RETURNING id',
      [req.params.id, req.tenant.id]
    );
    if (!r.rows[0]) return reply.code(404).send({ error: 'Producto no encontrado' });
    return { ok: true, deactivated: true };
  });
}

module.exports = productsRoutes;
