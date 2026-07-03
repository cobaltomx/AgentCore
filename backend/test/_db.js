'use strict';

/**
 * Helpers para tests de INTEGRACIÓN contra la BD real (Postgres de dev).
 * Requiere DATABASE_URL en el entorno (lo provee docker-compose).
 *
 * buildSuperAdminApp: monta Fastify con la ruta superadmin real y un Pool real,
 *   pero reemplaza requireSuperAdmin por un stub (sin JWT). Así se ejecuta el
 *   SQL de verdad sin necesidad de firmar tokens ni levantar el server.
 *
 * IMPORTANTE: todo dato creado por los tests usa marcadores desechables
 *   (slug `__test_sa_*`, teléfonos `+9999*`) y se limpia en los hooks.
 */

const Fastify = require('fastify');
const { Pool } = require('pg');

const HAS_DB = !!process.env.DATABASE_URL;

function realPool() {
  return new Pool({ connectionString: process.env.DATABASE_URL });
}

async function buildSuperAdminApp(registerFn, pool) {
  const app = Fastify({ logger: false });
  app.decorate('db', pool);
  // Stub de auth: simula un superadmin autenticado (sin JWT real)
  app.decorate('requireSuperAdmin', async (req) => {
    req.userId = null;          // user_id null es válido en audit_log
    req.role   = 'superadmin';
  });
  await app.register(registerFn);
  await app.ready();
  return app;
}

// Crea un tenant desechable y devuelve su fila. plan por defecto: growth.
async function createTestTenant(pool, { slug, plan = 'growth', status = 'active' } = {}) {
  const s = slug || `__test_sa_${Date.now()}_${Math.floor(Math.random() * 1e4)}`;
  const r = await pool.query(
    `INSERT INTO tenants (name, slug, plan, status, settings, max_agents, max_minutes_mo, minutes_used_mo)
     VALUES ($1, $2, $3, $4, '{}'::jsonb, 1, 500, 0)
     RETURNING *`,
    [`Test SA ${s}`, s, plan, status]
  );
  return r.rows[0];
}

// Borra un tenant y sus dependencias sin depender de cascadas frágiles.
async function purgeTenant(pool, id) {
  if (!id) return;
  await pool.query('DELETE FROM twilio_numbers WHERE tenant_id = $1', [id]).catch(() => {});
  await pool.query('DELETE FROM audit_log     WHERE tenant_id = $1', [id]).catch(() => {});
  await pool.query('DELETE FROM tenants        WHERE id = $1', [id]).catch(() => {});
}

// Limpia números de prueba (prefijo desechable).
async function purgeTestNumbers(pool) {
  await pool.query("DELETE FROM twilio_numbers WHERE phone_number LIKE '+9999%'").catch(() => {});
}

module.exports = { HAS_DB, realPool, buildSuperAdminApp, createTestTenant, purgeTenant, purgeTestNumbers };
