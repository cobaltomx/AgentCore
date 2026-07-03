'use strict';

/**
 * Tests de INTEGRACIÓN de los endpoints del Super Admin (Fase C/D) contra la
 * BD real. Ejecutan el SQL de verdad (Fastify + pg.Pool reales), saltando solo
 * el JWT. Todo dato es desechable (slug `__test_sa_*`, tel `+9999*`) y se limpia.
 *
 * Correr dentro del contenedor (tiene DATABASE_URL y deps):
 *   docker exec agentcore_backend sh -c "cd /app && npm test"
 */

const { test, before, after } = require('node:test');
const assert = require('node:assert');
const {
  HAS_DB, realPool, buildSuperAdminApp, createTestTenant, purgeTestNumbers,
} = require('./_db');
const superadminRoutes = require('../src/routes/v1/superadmin');

const SKIP = HAS_DB ? false : 'sin DATABASE_URL (correr dentro del contenedor)';

let pool, app, tenant;

before(async () => {
  if (!HAS_DB) return;
  pool = realPool();
  app  = await buildSuperAdminApp(superadminRoutes, pool);
  await purgeTestNumbers(pool);
  tenant = await createTestTenant(pool, { plan: 'growth' });
});

after(async () => {
  if (!HAS_DB) return;
  try {
    await purgeTestNumbers(pool);
    await pool.query("DELETE FROM audit_log WHERE metadata->>'phone' LIKE '+9999%'");
    await pool.query(`DELETE FROM audit_log WHERE tenant_id IN (SELECT id FROM tenants WHERE slug LIKE '__test_sa_%')`);
    await pool.query(`DELETE FROM tenants WHERE slug LIKE '__test_sa_%'`);
  } catch { /* best-effort */ }
  await app?.close();
  await pool?.end();
});

// ── Fase C: features ──────────────────────────────────────────
test('features: GET devuelve plan/effective; PATCH aplica override tri-estado y persiste', { skip: SKIP }, async () => {
  const g = await app.inject({ method: 'GET', url: `/tenants/${tenant.id}/features` });
  assert.equal(g.statusCode, 200);
  const data = g.json();
  assert.equal(data.planKey, 'growth');
  assert.ok(Array.isArray(data.effective));
  assert.ok(data.catalog && typeof data.catalog === 'object');

  // override: insights ON, webchat OFF
  const p = await app.inject({
    method: 'PATCH', url: `/tenants/${tenant.id}/features`,
    payload: { features: { insights: true, webchat: false } },
  });
  assert.equal(p.statusCode, 200);
  const eff = p.json().effective;
  assert.ok(eff.includes('insights'), 'insights forzado ON');
  assert.ok(!eff.includes('webchat'), 'webchat forzado OFF');

  // persistió en BD
  const db = await pool.query(`SELECT settings->'features' AS f FROM tenants WHERE id = $1`, [tenant.id]);
  assert.deepEqual(db.rows[0].f, { insights: true, webchat: false });

  // limpiar overrides (heredar del plan)
  const c = await app.inject({
    method: 'PATCH', url: `/tenants/${tenant.id}/features`,
    payload: { features: { insights: null, webchat: null } },
  });
  assert.deepEqual(c.json().overrides, {});
});

test('features: tenant inexistente → 404', { skip: SKIP }, async () => {
  const r = await app.inject({
    method: 'PATCH', url: `/tenants/00000000-0000-0000-0000-000000000000/features`,
    payload: { features: { voice: true } },
  });
  assert.equal(r.statusCode, 404);
});

// ── Fase C: alertas de consumo ────────────────────────────────
test('alerts: tenant a 100% de minutos aparece como danger', { skip: SKIP }, async () => {
  await pool.query('UPDATE tenants SET minutes_used_mo = 500, max_minutes_mo = 500 WHERE id = $1', [tenant.id]);
  const r = await app.inject({ method: 'GET', url: '/alerts' });
  assert.equal(r.statusCode, 200);
  const mine = r.json().alerts.find((a) => a.tenantId === tenant.id);
  assert.ok(mine, 'el tenant saturado debe estar en alertas');
  assert.equal(mine.level, 'danger');
  assert.equal(mine.usagePct, 100);
  await pool.query('UPDATE tenants SET minutes_used_mo = 0 WHERE id = $1', [tenant.id]); // reset
});

// ── Fase D: números Twilio (ciclo completo) ───────────────────
test('numbers: crear → asignar (escribe settings.twilio) → liberar → eliminar', { skip: SKIP }, async () => {
  const phone = '+9999' + String(Date.now() % 100000).padStart(5, '0'); // 9 dígitos

  const cr = await app.inject({
    method: 'POST', url: '/numbers',
    payload: { phone_number: phone, friendly_name: 'Test E2E', monthly_cost_cents: 1500 },
  });
  assert.equal(cr.statusCode, 201);
  const numId = cr.json().id;
  assert.equal(cr.json().status, 'available');

  // asignar → escribe settings.twilio del tenant
  const asg = await app.inject({ method: 'PATCH', url: `/numbers/${numId}`, payload: { tenant_id: tenant.id } });
  assert.equal(asg.json().status, 'assigned');
  const tw = await pool.query(`SELECT settings->'twilio'->>'phoneNumber' AS p FROM tenants WHERE id = $1`, [tenant.id]);
  assert.equal(tw.rows[0].p, phone, 'settings.twilio.phoneNumber escrito');

  // liberar → quita el phoneNumber
  const rel = await app.inject({ method: 'PATCH', url: `/numbers/${numId}`, payload: { tenant_id: null } });
  assert.equal(rel.json().status, 'available');
  const tw2 = await pool.query(`SELECT settings->'twilio'->>'phoneNumber' AS p FROM tenants WHERE id = $1`, [tenant.id]);
  assert.equal(tw2.rows[0].p, null, 'phoneNumber removido al liberar');

  // eliminar
  const del = await app.inject({ method: 'DELETE', url: `/numbers/${numId}` });
  assert.equal(del.json().ok, true);
  const gone = await pool.query('SELECT 1 FROM twilio_numbers WHERE id = $1', [numId]);
  assert.equal(gone.rowCount, 0);
});

test('numbers: rechaza formato de teléfono inválido', { skip: SKIP }, async () => {
  const r = await app.inject({ method: 'POST', url: '/numbers', payload: { phone_number: 'abc' } });
  assert.equal(r.statusCode, 400);
});

// ── Fase C: eliminar tenant (transacción + confirmación por slug) ──
test('DELETE tenant: 400 sin slug correcto; borra y limpia audit_log con slug correcto', { skip: SKIP }, async () => {
  const victim = await createTestTenant(pool, { plan: 'starter' });
  // sembrar audit_log (no tiene cascade) para probar que la transacción lo limpia
  await pool.query(`INSERT INTO audit_log (tenant_id, action, resource_type) VALUES ($1, 'test_seed', 'tenant')`, [victim.id]);

  // confirmación incorrecta → 400, sigue existiendo
  const bad = await app.inject({ method: 'DELETE', url: `/tenants/${victim.id}?confirm=incorrecto` });
  assert.equal(bad.statusCode, 400);
  assert.equal((await pool.query('SELECT 1 FROM tenants WHERE id = $1', [victim.id])).rowCount, 1);

  // confirmación correcta → borra tenant + audit_log
  const ok = await app.inject({ method: 'DELETE', url: `/tenants/${victim.id}?confirm=${encodeURIComponent(victim.slug)}` });
  assert.equal(ok.statusCode, 200);
  assert.equal(ok.json().ok, true);
  assert.equal((await pool.query('SELECT 1 FROM tenants   WHERE id = $1', [victim.id])).rowCount, 0);
  assert.equal((await pool.query('SELECT 1 FROM audit_log WHERE tenant_id = $1', [victim.id])).rowCount, 0, 'audit_log purgado');
});

// ── Fase D: logs globales ─────────────────────────────────────
test('logs: GET devuelve auditoría reciente con estructura esperada', { skip: SKIP }, async () => {
  const r = await app.inject({ method: 'GET', url: '/logs?limit=10' });
  assert.equal(r.statusCode, 200);
  const d = r.json();
  assert.ok(Array.isArray(d.logs));
  assert.ok(Array.isArray(d.actions));
  if (d.logs.length) {
    assert.ok('action' in d.logs[0] && 'created_at' in d.logs[0]);
  }
});
