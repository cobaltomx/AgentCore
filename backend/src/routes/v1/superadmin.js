'use strict';

const bcrypt = require('bcryptjs');
const { isValidPassword, PASSWORD_POLICY_ERROR } = require('../../services/password-policy');

// Fuente única de verdad de features (catálogo + cálculo plan ∪ overrides).
const { FEATURE_CATALOG, computeEffective: effectiveFeatures } = require('../../services/features');

/**
 * Rutas exclusivas de superadmin AgentCore
 * Prefix: /api/v1/superadmin
 * Middleware: requireSuperAdmin
 */
async function superadminRoutes(app) {

  // Helper de auditoría — registra acciones del superadmin en audit_log.
  // No crítico: nunca tumba la request si falla.
  async function audit(request, tenantId, action, resourceType, metadata = {}) {
    await app.db.query(
      `INSERT INTO audit_log (tenant_id, user_id, action, resource_type, ip_address, metadata)
       VALUES ($1, $2, $3, $4, $5, $6)`,
      [tenantId || null, request.userId || null, action, resourceType,
       request.ip || null, JSON.stringify(metadata)]
    ).catch((e) => app.log?.warn({ err: e }, 'audit_log insert failed'));
  }

  // ── GET /balances — saldos de proveedores (Twilio/Deepgram/Anthropic/OpenAI)
  //    ?force=1 salta el caché de 10 min. Prioridad #1 del panel: que nunca
  //    se agote un saldo sin que el superadmin se entere.
  app.get('/balances', { onRequest: [app.requireSuperAdmin] }, async (request) => {
    const { getBalances } = require('../../services/balance-monitor');
    return getBalances({ force: request.query.force === '1' });
  });

  // GET /api/v1/superadmin/tenants — lista todos los tenants con estadísticas
  app.get('/tenants', { onRequest: [app.requireSuperAdmin] }, async (request) => {
    const result = await app.db.query(`
      SELECT
        t.id, t.slug, t.name, t.plan, t.status, t.timezone,
        t.max_agents, t.max_minutes_mo, t.minutes_used_mo,
        t.settings, t.avatar_url, t.created_at,
        COUNT(DISTINCT u.id)::int          AS user_count,
        COUNT(DISTINCT a.id)::int          AS agent_count,
        COUNT(DISTINCT c.id)::int          AS conv_count,
        MAX(c.created_at)                  AS last_activity
      FROM tenants t
      LEFT JOIN users         u ON u.tenant_id = t.id
      LEFT JOIN agents        a ON a.tenant_id = t.id
      LEFT JOIN conversations c ON c.tenant_id = t.id
      GROUP BY t.id
      ORDER BY t.created_at DESC
    `);
    return result.rows;
  });

  // GET /api/v1/superadmin/tenants/:id — detalle de un tenant
  app.get('/tenants/:id', { onRequest: [app.requireSuperAdmin] }, async (request, reply) => {
    const result = await app.db.query(
      `SELECT t.*,
              COUNT(DISTINCT u.id)::int AS user_count,
              COUNT(DISTINCT a.id)::int AS agent_count
       FROM tenants t
       LEFT JOIN users  u ON u.tenant_id = t.id
       LEFT JOIN agents a ON a.tenant_id = t.id
       WHERE t.id = $1
       GROUP BY t.id`,
      [request.params.id]
    );
    if (!result.rows[0]) return reply.code(404).send({ error: 'Tenant no encontrado' });
    return result.rows[0];
  });

  // PATCH /api/v1/superadmin/tenants/:id — cambiar plan, status, limits
  app.patch('/tenants/:id', { onRequest: [app.requireSuperAdmin] }, async (request, reply) => {
    const allowed = ['plan', 'status', 'max_agents', 'max_minutes_mo', 'name', 'avatar_url'];
    const updates = [];
    const values  = [];
    let idx = 1;

    for (const [k, v] of Object.entries(request.body ?? {})) {
      if (!allowed.includes(k)) continue;
      updates.push(`${k} = $${idx}`);
      values.push(v);
      idx++;
    }

    if (!updates.length) return reply.code(400).send({ error: 'Sin campos válidos' });

    values.push(request.params.id);
    const result = await app.db.query(
      `UPDATE tenants SET ${updates.join(', ')}, updated_at = NOW()
       WHERE id = $${idx} RETURNING id, slug, name, plan, status, max_agents, max_minutes_mo`,
      values
    );

    if (!result.rows[0]) return reply.code(404).send({ error: 'Tenant no encontrado' });
    return result.rows[0];
  });

  // GET /api/v1/superadmin/tenants/:id/users — usuarios de un tenant
  app.get('/tenants/:id/users', { onRequest: [app.requireSuperAdmin] }, async (request, reply) => {
    const result = await app.db.query(
      `SELECT id, name, email, role, is_active, avatar_url, last_login_at, created_at
       FROM users
       WHERE tenant_id = $1
       ORDER BY created_at ASC`,
      [request.params.id]
    );
    return result.rows;
  });

  // POST /api/v1/superadmin/tenants/:id/users — agregar usuario a un tenant
  app.post('/tenants/:id/users', { onRequest: [app.requireSuperAdmin] }, async (request, reply) => {
    const tenantId = request.params.id;
    const { name, email, password, role = 'user' } = request.body || {};

    if (!email || !password) return reply.code(400).send({ error: 'email y password son requeridos' });
    if (!isValidPassword(password)) return reply.code(400).send({ error: PASSWORD_POLICY_ERROR });

    const emailExists = await app.db.query(
      'SELECT id FROM users WHERE email = $1',
      [email.toLowerCase()]
    );
    if (emailExists.rows[0]) return reply.code(409).send({ error: 'El email ya está registrado' });

    const tenantExists = await app.db.query('SELECT id FROM tenants WHERE id = $1', [tenantId]);
    if (!tenantExists.rows[0]) return reply.code(404).send({ error: 'Tenant no encontrado' });

    const allowedRoles = ['user', 'admin'];
    const safeRole = allowedRoles.includes(role) ? role : 'user';

    const hash = await bcrypt.hash(password, 12);
    const result = await app.db.query(
      `INSERT INTO users (tenant_id, name, email, password_hash, role, is_active)
       VALUES ($1, $2, $3, $4, $5, true)
       RETURNING id, name, email, role, is_active, created_at`,
      [tenantId, name || email.split('@')[0], email.toLowerCase(), hash, safeRole]
    );
    return reply.code(201).send(result.rows[0]);
  });

  // PATCH /api/v1/superadmin/users/:userId — editar (activo, rol, nombre, password)
  app.patch('/users/:userId', { onRequest: [app.requireSuperAdmin] }, async (request, reply) => {
    const body    = request.body ?? {};
    const allowed = ['is_active', 'role', 'name'];
    const updates = [];
    const values  = [];
    let idx = 1;

    for (const [k, v] of Object.entries(body)) {
      if (!allowed.includes(k)) continue;
      updates.push(`${k} = $${idx++}`);
      values.push(v);
    }

    // Reset de contraseña (se hashea; nunca se guarda en claro)
    if (typeof body.password === 'string' && body.password.length) {
      if (!isValidPassword(body.password)) {
        return reply.code(400).send({ error: PASSWORD_POLICY_ERROR });
      }
      updates.push(`password_hash = $${idx++}`);
      values.push(await bcrypt.hash(body.password, 12));
    }

    if (!updates.length) return reply.code(400).send({ error: 'Sin campos válidos' });

    values.push(request.params.userId);
    const result = await app.db.query(
      `UPDATE users SET ${updates.join(', ')}, updated_at = NOW()
       WHERE id = $${idx} RETURNING id, name, email, role, is_active`,
      values
    );
    if (!result.rows[0]) return reply.code(404).send({ error: 'Usuario no encontrado' });
    return result.rows[0];
  });

  // GET /api/v1/superadmin/users?industry=&tenantId= — usuarios por scope
  app.get('/users', { onRequest: [app.requireSuperAdmin] }, async (request) => {
    const { industry = null, tenantId = null } = request.query || {};
    const ids = (await tenantsInScope({ industry, tenantId })).map((t) => t.id);
    const level = tenantId ? 'negocio' : (industry ? 'vertical' : 'global');
    if (!ids.length) return { level, scope: { industry, tenantId }, count: 0, users: [] };

    const r = await app.db.query(`
      SELECT u.id, u.name, u.email, u.role, u.is_active, u.last_login_at, u.created_at,
             u.tenant_id, t.name AS tenant_name, t.slug AS tenant_slug,
             ${INDUSTRY_SQL} AS industry
      FROM users u JOIN tenants t ON t.id = u.tenant_id
      WHERE u.tenant_id = ANY($1)
      ORDER BY t.name ASC, u.created_at ASC
    `, [ids]);
    return { level, scope: { industry, tenantId }, count: r.rows.length, users: r.rows };
  });

  // POST /api/v1/superadmin/tenants — crear nuevo tenant + usuario admin
  app.post('/tenants', { onRequest: [app.requireSuperAdmin] }, async (request, reply) => {
    const {
      name, slug, plan = 'starter', industry = '', businessName = '',
      tone = 'professional', objective = '', greeting = '',
      adminName, adminEmail, adminPassword,
      maxAgents, maxMinutes,   // si no se pasan, se toman del plan
    } = request.body || {};

    if (!name || !slug || !adminEmail || !adminPassword) {
      return reply.code(400).send({ error: 'name, slug, adminEmail y adminPassword son requeridos' });
    }
    if (!isValidPassword(adminPassword)) return reply.code(400).send({ error: PASSWORD_POLICY_ERROR });

    // Límites desde la tabla de planes (la define el Super Admin); override opcional.
    const planRow = (await app.db.query(
      'SELECT max_agents, included_minutes FROM plans WHERE key = $1', [plan]
    )).rows[0];
    const effMaxAgents  = maxAgents  ?? planRow?.max_agents       ?? 1;
    const effMaxMinutes = maxMinutes ?? planRow?.included_minutes ?? 300;

    const cleanSlug = slug.toLowerCase().trim().replace(/[^a-z0-9-]/g, '-');

    // Verificar slug único
    const existing = await app.db.query('SELECT id FROM tenants WHERE slug = $1', [cleanSlug]);
    if (existing.rows[0]) return reply.code(409).send({ error: 'El slug ya está en uso' });

    // Verificar email único
    const emailExists = await app.db.query('SELECT id FROM users WHERE email = $1', [adminEmail.toLowerCase()]);
    if (emailExists.rows[0]) return reply.code(409).send({ error: 'El email ya está registrado' });

    const settings = {
      businessProfile: { industry, businessName: businessName || name },
      tone,
      objective,
      greeting,
    };

    // Crear tenant
    const tenantResult = await app.db.query(
      `INSERT INTO tenants (name, slug, plan, status, settings, max_agents, max_minutes_mo)
       VALUES ($1, $2, $3, 'trial', $4, $5, $6)
       RETURNING *`,
      [name, cleanSlug, plan, JSON.stringify(settings), effMaxAgents, effMaxMinutes]
    );
    const tenant = tenantResult.rows[0];

    // Crear usuario admin
    const hash = await bcrypt.hash(adminPassword, 12);
    await app.db.query(
      `INSERT INTO users (tenant_id, name, email, password_hash, role, is_active)
       VALUES ($1, $2, $3, $4, 'admin', true)`,
      [tenant.id, adminName || adminEmail.split('@')[0], adminEmail.toLowerCase(), hash]
    );

    return reply.code(201).send(tenant);
  });

  // PATCH /api/v1/superadmin/tenants/:id/settings — actualizar JSONB settings
  app.patch('/tenants/:id/settings', { onRequest: [app.requireSuperAdmin] }, async (request, reply) => {
    const settingsUpdate = request.body || {};

    const result = await app.db.query(
      `UPDATE tenants
       SET settings = settings || $1::jsonb, updated_at = NOW()
       WHERE id = $2
       RETURNING id, slug, name, settings`,
      [JSON.stringify(settingsUpdate), request.params.id]
    );

    if (!result.rows[0]) return reply.code(404).send({ error: 'Tenant no encontrado' });
    return result.rows[0];
  });

  // GET /api/v1/superadmin/stats?industry=&tenantId= — métricas por scope.
  // Sin params = Global (compatible hacia atrás); industry = Vertical; tenantId = Negocio.
  app.get('/stats', { onRequest: [app.requireSuperAdmin] }, async (request) => {
    const { industry = null, tenantId = null } = request.query || {};
    const ids = (await tenantsInScope({ industry, tenantId })).map((t) => t.id);
    const level = tenantId ? 'negocio' : (industry ? 'vertical' : 'global');

    if (!ids.length) {
      return {
        level, scope: { industry, tenantId },
        total_tenants: 0, active_tenants: 0, new_tenants_30d: 0, total_users: 0,
        active_agents: 0, convs_30d: 0, convs_prev_30d: 0, total_minutes_used: 0,
        convs_by_day: [], tenants_by_month: [], top_tenants: [], plan_dist: [],
      };
    }

    const [kpis, convsByDay, tenantsByMonth, topTenants, planDist] = await Promise.all([
      app.db.query(`
        SELECT
          (SELECT COUNT(*) FROM tenants WHERE id = ANY($1))::int                         AS total_tenants,
          (SELECT COUNT(*) FROM tenants WHERE id = ANY($1) AND status = 'active')::int    AS active_tenants,
          (SELECT COUNT(*) FROM tenants WHERE id = ANY($1) AND created_at > NOW() - INTERVAL '30 days')::int AS new_tenants_30d,
          (SELECT COUNT(*) FROM users  WHERE tenant_id = ANY($1))::int                    AS total_users,
          (SELECT COUNT(*) FROM agents WHERE tenant_id = ANY($1) AND is_active = true)::int AS active_agents,
          (SELECT COUNT(*) FROM conversations WHERE tenant_id = ANY($1) AND created_at > NOW() - INTERVAL '30 days')::int AS convs_30d,
          (SELECT COUNT(*) FROM conversations WHERE tenant_id = ANY($1) AND created_at > NOW() - INTERVAL '60 days'
                                                AND created_at <= NOW() - INTERVAL '30 days')::int AS convs_prev_30d,
          (SELECT COALESCE(SUM(minutes_used_mo),0) FROM tenants WHERE id = ANY($1))::int  AS total_minutes_used
      `, [ids]),
      app.db.query(`
        SELECT TO_CHAR(DATE(created_at AT TIME ZONE 'UTC'), 'YYYY-MM-DD') AS day, COUNT(*)::int AS cnt
        FROM conversations WHERE tenant_id = ANY($1) AND created_at > NOW() - INTERVAL '30 days'
        GROUP BY day ORDER BY day`, [ids]),
      app.db.query(`
        SELECT TO_CHAR(DATE_TRUNC('month', created_at), 'YYYY-MM') AS mo, COUNT(*)::int AS cnt
        FROM tenants WHERE id = ANY($1) AND created_at > NOW() - INTERVAL '6 months'
        GROUP BY mo ORDER BY mo`, [ids]),
      app.db.query(`
        SELECT t.id, t.name, t.plan, t.status, t.minutes_used_mo, t.max_minutes_mo,
               COUNT(DISTINCT u.id)::int AS user_count,
               COUNT(DISTINCT c.id)::int AS conv_count_30d
        FROM tenants t
        LEFT JOIN users         u ON u.tenant_id = t.id
        LEFT JOIN conversations c ON c.tenant_id = t.id AND c.created_at > NOW() - INTERVAL '30 days'
        WHERE t.id = ANY($1)
        GROUP BY t.id ORDER BY conv_count_30d DESC, t.created_at DESC LIMIT 10`, [ids]),
      app.db.query(`
        SELECT plan, COUNT(*)::int AS cnt FROM tenants WHERE id = ANY($1) GROUP BY plan ORDER BY cnt DESC`, [ids]),
    ]);

    return {
      level, scope: { industry, tenantId },
      ...kpis.rows[0],
      convs_by_day:     convsByDay.rows,
      tenants_by_month: tenantsByMonth.rows,
      top_tenants:      topTenants.rows,
      plan_dist:        planDist.rows,
    };
  });

  // ── Planes del sistema ──────────────────────────────────────
  app.get('/plans', { onRequest: [app.requireSuperAdmin] }, async () => {
    const r = await app.db.query('SELECT * FROM plans ORDER BY sort_order ASC');
    return r.rows;
  });

  app.patch('/plans/:key', { onRequest: [app.requireSuperAdmin] }, async (request, reply) => {
    const allowed = ['name', 'monthly_cents', 'included_minutes', 'max_agents', 'overage_per_min_cents', 'features', 'is_active', 'sort_order'];
    const updates = [], values = [];
    let i = 1;
    for (const [k, v] of Object.entries(request.body ?? {})) {
      if (!allowed.includes(k)) continue;
      updates.push(`${k} = $${i++}`);
      values.push(k === 'features' ? JSON.stringify(v) : v);
    }
    if (!updates.length) return reply.code(400).send({ error: 'Sin campos válidos' });
    values.push(request.params.key);
    const r = await app.db.query(
      `UPDATE plans SET ${updates.join(', ')}, updated_at = NOW() WHERE key = $${i} RETURNING *`,
      values
    );
    if (!r.rows[0]) return reply.code(404).send({ error: 'Plan no encontrado' });
    return r.rows[0];
  });

  app.post('/plans', { onRequest: [app.requireSuperAdmin] }, async (request, reply) => {
    const b = request.body || {};
    const key = String(b.key || '').toLowerCase().replace(/[^a-z0-9_-]/g, '');
    if (!key || !b.name) return reply.code(400).send({ error: 'key y name requeridos' });
    try {
      const r = await app.db.query(
        `INSERT INTO plans (key,name,monthly_cents,included_minutes,max_agents,overage_per_min_cents,features,sort_order)
         VALUES ($1,$2,$3,$4,$5,$6,$7,$8) RETURNING *`,
        [key, b.name, b.monthly_cents || 0, b.included_minutes || 0, b.max_agents || 1,
         b.overage_per_min_cents || 0, JSON.stringify(b.features || []), b.sort_order || 99]
      );
      return reply.code(201).send(r.rows[0]);
    } catch (e) {
      return reply.code(409).send({ error: 'El plan ya existe o datos inválidos' });
    }
  });

  // ── Tarifas de proveedores (costo estimado de infra) ────────
  app.get('/provider-rates', { onRequest: [app.requireSuperAdmin] }, async () => {
    const r = await app.db.query('SELECT * FROM provider_rates ORDER BY provider ASC');
    return r.rows;
  });

  app.patch('/provider-rates/:provider', { onRequest: [app.requireSuperAdmin] }, async (request, reply) => {
    const { rate_cents, label, unit, is_active } = request.body || {};
    const updates = [], values = [];
    let i = 1;
    if (rate_cents !== undefined) { updates.push(`rate_cents = $${i++}`); values.push(rate_cents); }
    if (label      !== undefined) { updates.push(`label = $${i++}`);      values.push(label); }
    if (unit       !== undefined) { updates.push(`unit = $${i++}`);       values.push(unit); }
    if (is_active  !== undefined) { updates.push(`is_active = $${i++}`);  values.push(is_active); }
    if (!updates.length) return reply.code(400).send({ error: 'Sin campos válidos' });
    values.push(request.params.provider);
    const r = await app.db.query(
      `UPDATE provider_rates SET ${updates.join(', ')}, updated_at = NOW() WHERE provider = $${i} RETURNING *`,
      values
    );
    if (!r.rows[0]) return reply.code(404).send({ error: 'Tarifa no encontrada' });
    return r.rows[0];
  });

  // ── Costos y margen por tenant (mes actual) ─────────────────
  app.get('/costs', { onRequest: [app.requireSuperAdmin] }, async () => {
    const { estimateAllTenants } = require('../../services/billing/cost-estimator');
    return await estimateAllTenants(app.db);
  });

  // ── Modelo de costo + guardrail de margen mínimo ────────────
  async function buildCostModel() {
    const { loadRates } = require('../../services/billing/cost-estimator');
    const rates = await loadRates(app.db);
    const cfg = {};
    (await app.db.query('SELECT key, value FROM platform_config')).rows.forEach(r => { cfg[r.key] = r.value; });
    const tokensPerMin  = Number(cfg.tokens_per_min ?? 12700);
    const minMarginPct  = Number(cfg.min_margin_pct ?? 40);
    const usageWarnPct  = Number(cfg.usage_warn_pct ?? 80);
    const tts        = rates.cartesia_tts ?? rates.deepgram_tts ?? 0;
    const infraPerMin = (rates.twilio_voice ?? 0) + (rates.deepgram_stt ?? 0) + tts;
    const llmBlend    = ((rates.llm_input ?? 0) + (rates.llm_output ?? 0)) / 2;
    const llmPerMin   = (tokensPerMin / 1000) * llmBlend;
    const costPerMin  = infraPerMin + llmPerMin;
    return {
      tokensPerMin, minMarginPct, usageWarnPct,
      infraPerMinCents: Math.round(infraPerMin),
      llmPerMinCents:   Math.round(llmPerMin),
      costPerMinCents:  Math.round(costPerMin * 100) / 100,
    };
  }

  app.get('/cost-model', { onRequest: [app.requireSuperAdmin] }, async () => buildCostModel());

  app.patch('/cost-model', { onRequest: [app.requireSuperAdmin] }, async (request, reply) => {
    const { tokens_per_min, min_margin_pct } = request.body || {};
    if (tokens_per_min !== undefined)
      await app.db.query(`INSERT INTO platform_config (key,value) VALUES ('tokens_per_min',$1::jsonb)
                          ON CONFLICT (key) DO UPDATE SET value=$1::jsonb, updated_at=NOW()`, [String(Math.max(0, parseInt(tokens_per_min) || 0))]);
    if (min_margin_pct !== undefined)
      await app.db.query(`INSERT INTO platform_config (key,value) VALUES ('min_margin_pct',$1::jsonb)
                          ON CONFLICT (key) DO UPDATE SET value=$1::jsonb, updated_at=NOW()`, [String(Math.max(0, Math.min(100, parseInt(min_margin_pct) || 0)))]);
    if (request.body?.usage_warn_pct !== undefined)
      await app.db.query(`INSERT INTO platform_config (key,value) VALUES ('usage_warn_pct',$1::jsonb)
                          ON CONFLICT (key) DO UPDATE SET value=$1::jsonb, updated_at=NOW()`, [String(Math.max(1, Math.min(100, parseInt(request.body.usage_warn_pct) || 80)))]);
    return await buildCostModel();
  });

  // ════════════════════════════════════════════════════════════
  // FASE C — Features on/off por tenant
  // ════════════════════════════════════════════════════════════

  // GET /superadmin/tenants/:id/features — estado de features del tenant
  app.get('/tenants/:id/features', { onRequest: [app.requireSuperAdmin] }, async (request, reply) => {
    const r = await app.db.query(
      `SELECT t.plan, t.settings, COALESCE(p.features, '[]'::jsonb) AS plan_features
       FROM tenants t LEFT JOIN plans p ON p.key = t.plan
       WHERE t.id = $1`,
      [request.params.id]
    );
    if (!r.rows[0]) return reply.code(404).send({ error: 'Tenant no encontrado' });
    const row = r.rows[0];
    const planFeatures = Array.isArray(row.plan_features) ? row.plan_features : [];
    const overrides = (row.settings && row.settings.features) || {};
    return {
      planKey:      row.plan,
      catalog:      FEATURE_CATALOG,
      planFeatures,
      overrides,
      effective:    effectiveFeatures(planFeatures, overrides),
    };
  });

  // PATCH /superadmin/tenants/:id/features — set/clear overrides
  // body: { features: { voice:true, insights:false, whatsapp:null } }
  //   true=forzar ON, false=forzar OFF, null=quitar override (heredar del plan)
  app.patch('/tenants/:id/features', { onRequest: [app.requireSuperAdmin] }, async (request, reply) => {
    const incoming = request.body?.features || {};
    const cur = await app.db.query(
      'SELECT plan, settings FROM tenants WHERE id = $1', [request.params.id]
    );
    if (!cur.rows[0]) return reply.code(404).send({ error: 'Tenant no encontrado' });

    const overrides = { ...((cur.rows[0].settings && cur.rows[0].settings.features) || {}) };
    for (const [k, v] of Object.entries(incoming)) {
      if (!FEATURE_CATALOG[k]) continue;          // ignora claves desconocidas
      if (v === null) delete overrides[k];        // heredar del plan
      else overrides[k] = (v === true || v === 'true');
    }

    const r = await app.db.query(
      `UPDATE tenants
       SET settings = jsonb_set(settings, '{features}', $1::jsonb, true), updated_at = NOW()
       WHERE id = $2
       RETURNING plan`,
      [JSON.stringify(overrides), request.params.id]
    );
    await audit(request, request.params.id, 'tenant_features_updated', 'tenant', { overrides });

    const planFeatures = (await app.db.query(
      `SELECT COALESCE(features, '[]'::jsonb) AS f FROM plans WHERE key = $1`, [r.rows[0].plan]
    )).rows[0]?.f || [];
    return { overrides, effective: effectiveFeatures(planFeatures, overrides) };
  });

  // ════════════════════════════════════════════════════════════
  // FASE C — Eliminar tenant (destructivo, cascada)
  // ════════════════════════════════════════════════════════════
  // DELETE /superadmin/tenants/:id?confirm=<slug>
  app.delete('/tenants/:id', { onRequest: [app.requireSuperAdmin] }, async (request, reply) => {
    const id      = request.params.id;
    const confirm = request.query?.confirm ?? request.body?.confirm;

    const t = (await app.db.query(
      'SELECT id, slug, name FROM tenants WHERE id = $1', [id]
    )).rows[0];
    if (!t) return reply.code(404).send({ error: 'Tenant no encontrado' });

    // Doble confirmación: hay que mandar el slug exacto
    if (!confirm || confirm !== t.slug) {
      return reply.code(400).send({ error: 'Confirmación inválida: envía el slug exacto del tenant' });
    }

    const client = await app.db.connect();
    try {
      await client.query('BEGIN');
      // audit_log no tiene ON DELETE CASCADE → limpiar primero
      await client.query('DELETE FROM audit_log    WHERE tenant_id = $1', [id]);
      // twilio_numbers usa ON DELETE SET NULL, pero liberamos explícito
      await client.query(
        `UPDATE twilio_numbers SET tenant_id = NULL, status = 'available', updated_at = NOW()
         WHERE tenant_id = $1`, [id]
      ).catch(() => {}); // tabla puede no existir aún en instalaciones viejas
      // El resto de tablas (users, agents, conversations, …) cae por ON DELETE CASCADE
      await client.query('DELETE FROM tenants WHERE id = $1', [id]);
      await client.query('COMMIT');
    } catch (e) {
      await client.query('ROLLBACK').catch(() => {});
      app.log?.error({ err: e }, 'tenant delete failed');
      return reply.code(500).send({ error: 'No se pudo eliminar el tenant: ' + e.message });
    } finally {
      client.release();
    }

    await audit(request, null, 'tenant_deleted', 'tenant', { id, slug: t.slug, name: t.name });
    return { ok: true, deleted: { id, slug: t.slug, name: t.name } };
  });

  // ════════════════════════════════════════════════════════════
  // FASE C — Alertas de consumo (tenants cerca/encima del límite)
  // ════════════════════════════════════════════════════════════
  // GET /superadmin/alerts
  app.get('/alerts', { onRequest: [app.requireSuperAdmin] }, async () => {
    const cfg = (await app.db.query(
      `SELECT value FROM platform_config WHERE key = 'usage_warn_pct'`
    )).rows[0];
    const warnPct = Number(cfg?.value ?? 80);

    const r = await app.db.query(`
      SELECT id, name, slug, plan, status, minutes_used_mo, max_minutes_mo, max_agents,
             (SELECT COUNT(*)::int FROM agents a WHERE a.tenant_id = t.id AND a.is_active) AS active_agents
      FROM tenants t
      WHERE status IN ('active', 'trial')
      ORDER BY (CASE WHEN max_minutes_mo > 0
                     THEN minutes_used_mo::float / max_minutes_mo ELSE 0 END) DESC
    `);

    const alerts = [];
    for (const t of r.rows) {
      const max  = Number(t.max_minutes_mo) || 0;
      const used = Number(t.minutes_used_mo) || 0;
      const pct  = max > 0 ? Math.round((used / max) * 100) : 0;
      const issues = [];
      if (max > 0 && used >= max)              issues.push({ type: 'minutes_over',  level: 'danger' });
      else if (max > 0 && pct >= warnPct)      issues.push({ type: 'minutes_warn',  level: 'warning' });
      if (Number(t.active_agents) > Number(t.max_agents))
        issues.push({ type: 'agents_over', level: 'danger' });
      if (!issues.length) continue;
      alerts.push({
        tenantId: t.id, name: t.name, slug: t.slug, plan: t.plan, status: t.status,
        minutesUsed: used, maxMinutes: max, usagePct: pct,
        activeAgents: Number(t.active_agents), maxAgents: Number(t.max_agents),
        level: issues.some((i) => i.level === 'danger') ? 'danger' : 'warning',
        issues,
      });
    }
    return { warnPct, count: alerts.length, alerts };
  });

  // ════════════════════════════════════════════════════════════
  // FASE D — Pool de números Twilio
  // ════════════════════════════════════════════════════════════
  app.get('/numbers', { onRequest: [app.requireSuperAdmin] }, async () => {
    const r = await app.db.query(`
      SELECT n.*, t.name AS tenant_name, t.slug AS tenant_slug
      FROM twilio_numbers n LEFT JOIN tenants t ON t.id = n.tenant_id
      ORDER BY n.created_at DESC
    `);
    return r.rows;
  });

  // GET /superadmin/numbers/detected — números configurados FUERA del pool
  // (en .env, en settings.twilio de tenants, o asignados a agentes). Sirve para
  // que el superadmin VEA los números reales aunque no estén en `twilio_numbers`
  // y pueda importarlos al pool.
  app.get('/numbers/detected', { onRequest: [app.requireSuperAdmin] }, async () => {
    const pool = new Set(
      (await app.db.query('SELECT phone_number FROM twilio_numbers')).rows.map((r) => r.phone_number)
    );
    const map = new Map();   // phone → { phone, sources:[], tenants:[] }
    const add = (phone, source, tenant) => {
      if (!phone) return;
      phone = String(phone).trim();
      if (!phone || pool.has(phone)) return;
      if (!map.has(phone)) map.set(phone, { phone, sources: [], tenants: [] });
      const e = map.get(phone);
      if (!e.sources.includes(source)) e.sources.push(source);
      if (tenant && !e.tenants.some((t) => t.slug === tenant.slug && t.name === tenant.name)) e.tenants.push(tenant);
    };

    add(process.env.TWILIO_DEFAULT_NUMBER, 'Plataforma · voz (.env)');
    add(process.env.TWILIO_PHONE_NUMBER,   'Plataforma · voz (.env)');
    add(process.env.TWILIO_WHATSAPP_FROM,  'Plataforma · WhatsApp (.env)');

    for (const t of (await app.db.query(
      `SELECT name, slug, settings->'twilio'->>'phoneNumber' AS phone
       FROM tenants WHERE settings->'twilio'->>'phoneNumber' IS NOT NULL`
    )).rows) {
      add(t.phone, 'Settings del tenant', { name: t.name, slug: t.slug });
    }

    for (const a of (await app.db.query(
      `SELECT a.phone_number AS phone, a.name AS agent_name, t.name AS tenant_name, t.slug AS tenant_slug
       FROM agents a JOIN tenants t ON t.id = a.tenant_id
       WHERE a.phone_number IS NOT NULL`
    )).rows) {
      add(a.phone, `Agente: ${a.agent_name}`, { name: a.tenant_name, slug: a.tenant_slug });
    }

    return [...map.values()];
  });

  app.post('/numbers', { onRequest: [app.requireSuperAdmin] }, async (request, reply) => {
    const b = request.body || {};
    const phone = String(b.phone_number || '').trim();
    if (!/^\+?[0-9]{8,16}$/.test(phone)) {
      return reply.code(400).send({ error: 'Número inválido (formato E.164, ej. +5215512345678)' });
    }
    try {
      const r = await app.db.query(
        `INSERT INTO twilio_numbers
           (phone_number, friendly_name, account_sid, capabilities, monthly_cost_cents, notes)
         VALUES ($1,$2,$3,$4,$5,$6) RETURNING *`,
        [phone, b.friendly_name || null, b.account_sid || null,
         JSON.stringify(b.capabilities || { voice: true, sms: false, whatsapp: false }),
         parseInt(b.monthly_cost_cents) || 0, b.notes || null]
      );
      await audit(request, null, 'twilio_number_added', 'twilio_number', { phone });
      return reply.code(201).send(r.rows[0]);
    } catch (e) {
      return reply.code(409).send({ error: 'El número ya existe o datos inválidos' });
    }
  });

  // PATCH /superadmin/numbers/:id — editar metadata o asignar/liberar tenant
  app.patch('/numbers/:id', { onRequest: [app.requireSuperAdmin] }, async (request, reply) => {
    const b = request.body || {};
    const num = (await app.db.query('SELECT * FROM twilio_numbers WHERE id = $1', [request.params.id])).rows[0];
    if (!num) return reply.code(404).send({ error: 'Número no encontrado' });

    const updates = [], values = [];
    let i = 1;
    if (b.friendly_name      !== undefined) { updates.push(`friendly_name = $${i++}`);      values.push(b.friendly_name); }
    if (b.account_sid        !== undefined) { updates.push(`account_sid = $${i++}`);        values.push(b.account_sid || null); }
    if (b.capabilities       !== undefined) { updates.push(`capabilities = $${i++}::jsonb`); values.push(JSON.stringify(b.capabilities)); }
    if (b.monthly_cost_cents !== undefined) { updates.push(`monthly_cost_cents = $${i++}`); values.push(parseInt(b.monthly_cost_cents) || 0); }
    if (b.notes              !== undefined) { updates.push(`notes = $${i++}`);              values.push(b.notes || null); }

    // Asignación: tenant_id presente (uuid → asignar, null/'' → liberar)
    let assignTarget; // { id, slug, phone } para escribir en settings del tenant
    if (b.tenant_id !== undefined) {
      const tenantId = b.tenant_id || null;
      if (tenantId) {
        const tn = (await app.db.query('SELECT id, slug FROM tenants WHERE id = $1', [tenantId])).rows[0];
        if (!tn) return reply.code(404).send({ error: 'Tenant destino no encontrado' });
        assignTarget = { id: tn.id, slug: tn.slug, phone: num.phone_number };
        updates.push(`tenant_id = $${i++}`); values.push(tenantId);
        updates.push(`status = 'assigned'`);
      } else {
        updates.push(`tenant_id = NULL`);
        updates.push(`status = 'available'`);
      }
    } else if (b.status !== undefined) {
      updates.push(`status = $${i++}`); values.push(b.status);
    }

    if (!updates.length) return reply.code(400).send({ error: 'Sin campos válidos' });
    values.push(request.params.id);
    const r = await app.db.query(
      `UPDATE twilio_numbers SET ${updates.join(', ')}, updated_at = NOW() WHERE id = $${i} RETURNING *`,
      values
    );

    // Reflejar la asignación en tenants.settings.twilio (lo lee la UI y el agente)
    if (b.tenant_id !== undefined) {
      if (assignTarget) {
        // Liberar el número de cualquier OTRO tenant que lo tuviera y asignarlo aquí
        await app.db.query(
          `UPDATE tenants SET settings = jsonb_set(
              settings, '{twilio}',
              COALESCE(settings->'twilio','{}'::jsonb) || $1::jsonb, true), updated_at = NOW()
           WHERE id = $2`,
          [JSON.stringify({ phoneNumber: assignTarget.phone, accountSid: num.account_sid || null }), assignTarget.id]
        );
        await audit(request, assignTarget.id, 'twilio_number_assigned', 'twilio_number',
          { phone: num.phone_number, tenant: assignTarget.slug });
      } else if (num.tenant_id) {
        // Liberar: limpiar el número del tenant previo
        await app.db.query(
          `UPDATE tenants SET settings = jsonb_set(
              settings, '{twilio}',
              COALESCE(settings->'twilio','{}'::jsonb) - 'phoneNumber', true), updated_at = NOW()
           WHERE id = $1`,
          [num.tenant_id]
        );
        await audit(request, num.tenant_id, 'twilio_number_released', 'twilio_number', { phone: num.phone_number });
      }
    }
    return r.rows[0];
  });

  app.delete('/numbers/:id', { onRequest: [app.requireSuperAdmin] }, async (request, reply) => {
    const num = (await app.db.query('SELECT * FROM twilio_numbers WHERE id = $1', [request.params.id])).rows[0];
    if (!num) return reply.code(404).send({ error: 'Número no encontrado' });
    if (num.tenant_id && num.status === 'assigned') {
      return reply.code(409).send({ error: 'Libera el número de su tenant antes de eliminarlo' });
    }
    await app.db.query('DELETE FROM twilio_numbers WHERE id = $1', [request.params.id]);
    await audit(request, null, 'twilio_number_deleted', 'twilio_number', { phone: num.phone_number });
    return { ok: true };
  });

  // ════════════════════════════════════════════════════════════
  // FASE D — Vista de datos del tenant (impersonation read-only)
  // ════════════════════════════════════════════════════════════
  // GET /superadmin/tenants/:id/overview
  app.get('/tenants/:id/overview', { onRequest: [app.requireSuperAdmin] }, async (request, reply) => {
    const id = request.params.id;
    const tenant = (await app.db.query('SELECT * FROM tenants WHERE id = $1', [id])).rows[0];
    if (!tenant) return reply.code(404).send({ error: 'Tenant no encontrado' });

    const [counts, recentConvs, recentLeads, agents, users] = await Promise.all([
      app.db.query(`
        SELECT
          (SELECT COUNT(*)::int FROM users         WHERE tenant_id = $1) AS users,
          (SELECT COUNT(*)::int FROM agents        WHERE tenant_id = $1) AS agents,
          (SELECT COUNT(*)::int FROM conversations WHERE tenant_id = $1) AS conversations,
          (SELECT COUNT(*)::int FROM leads         WHERE tenant_id = $1) AS leads,
          (SELECT COUNT(*)::int FROM conversations WHERE tenant_id = $1
             AND created_at > NOW() - INTERVAL '30 days') AS conversations_30d
      `, [id]),
      app.db.query(`
        SELECT id, channel, status, started_at, duration_secs, created_at
        FROM conversations WHERE tenant_id = $1
        ORDER BY created_at DESC LIMIT 10`, [id]),
      app.db.query(`
        SELECT id, name, phone, status, created_at
        FROM leads WHERE tenant_id = $1
        ORDER BY created_at DESC LIMIT 10`, [id]),
      app.db.query(`
        SELECT id, name, channel, is_active, phone_number
        FROM agents WHERE tenant_id = $1 ORDER BY created_at ASC`, [id]),
      app.db.query(`
        SELECT id, name, email, role, is_active, last_login_at
        FROM users WHERE tenant_id = $1 ORDER BY created_at ASC`, [id]),
    ]);

    return {
      tenant,
      counts:        counts.rows[0],
      recentConvs:   recentConvs.rows,
      recentLeads:   recentLeads.rows,
      agents:        agents.rows,
      users:         users.rows,
    };
  });

  // GET /superadmin/tenants/:id/billing — costo/ingreso/margen del mes (1 tenant)
  app.get('/tenants/:id/billing', { onRequest: [app.requireSuperAdmin] }, async (request, reply) => {
    const { loadRates, estimateTenantCost, monthBounds } = require('../../services/billing/cost-estimator');
    const id = request.params.id;
    const t = (await app.db.query(
      `SELECT t.id, t.plan, t.status, t.minutes_used_mo, t.max_minutes_mo,
              p.name AS plan_name, p.monthly_cents, p.included_minutes, p.overage_per_min_cents
       FROM tenants t LEFT JOIN plans p ON p.key = t.plan
       WHERE t.id = $1`, [id]
    )).rows[0];
    if (!t) return reply.code(404).send({ error: 'Tenant no encontrado' });

    const { since, until } = monthBounds();
    const rates = await loadRates(app.db);
    const cost  = await estimateTenantCost(app.db, id, rates, since, until);
    const ov = (await app.db.query(
      `SELECT COALESCE(SUM(overage_amount_cents),0)::int AS cents
       FROM usage_records WHERE tenant_id = $1 AND period_start >= $2`,
      [id, since]
    )).rows[0].cents;

    const revenueCents = (t.status === 'active' ? Number(t.monthly_cents || 0) : 0) + Number(ov);
    const marginCents  = revenueCents - cost.totalCostCents;
    return {
      period: { since, until },
      plan: {
        key: t.plan, name: t.plan_name,
        monthlyCents:       Number(t.monthly_cents || 0),
        includedMinutes:    t.included_minutes,
        overagePerMinCents: t.overage_per_min_cents,
      },
      minutesUsed: t.minutes_used_mo, maxMinutes: t.max_minutes_mo,
      cost, overageCents: Number(ov), revenueCents, marginCents,
      marginPct: revenueCents > 0 ? Math.round((marginCents / revenueCents) * 100) : null,
    };
  });

  // ════════════════════════════════════════════════════════════
  // FASE D — Logs globales (audit_log de toda la plataforma)
  // ════════════════════════════════════════════════════════════
  // GET /superadmin/logs?limit=&action=&tenant_id=
  app.get('/logs', { onRequest: [app.requireSuperAdmin] }, async (request) => {
    const q       = request.query || {};
    const limit   = Math.min(parseInt(q.limit) || 100, 500);
    const where   = [];
    const values  = [];
    let i = 1;
    if (q.action)    { where.push(`l.action = $${i++}`);     values.push(q.action); }
    if (q.tenant_id) { where.push(`l.tenant_id = $${i++}`);  values.push(q.tenant_id); }
    const whereSql = where.length ? `WHERE ${where.join(' AND ')}` : '';
    values.push(limit);

    const rows = (await app.db.query(`
      SELECT l.id, l.action, l.resource_type, l.resource_id, l.ip_address,
             l.metadata, l.created_at,
             l.tenant_id, t.name AS tenant_name, t.slug AS tenant_slug,
             l.user_id, u.name AS user_name, u.email AS user_email
      FROM audit_log l
      LEFT JOIN tenants t ON t.id = l.tenant_id
      LEFT JOIN users   u ON u.id = l.user_id
      ${whereSql}
      ORDER BY l.created_at DESC
      LIMIT $${i}
    `, values)).rows;

    const actions = (await app.db.query(
      `SELECT DISTINCT action FROM audit_log ORDER BY action ASC`
    )).rows.map((r) => r.action);

    return { count: rows.length, actions, logs: rows };
  });

  // ════════════════════════════════════════════════════════════
  // VISTA POR SCOPE — Global ▸ Vertical (industria) ▸ Negocio (tenant)
  // ════════════════════════════════════════════════════════════
  // Expresión SQL canónica de la industria de un tenant (soporta ambas rutas).
  const INDUSTRY_SQL =
    `COALESCE(NULLIF(t.settings->'businessProfile'->>'industry',''), NULLIF(t.settings->>'industry',''), '')`;

  // GET /superadmin/verticals — industrias con conteo de negocios (para el selector)
  app.get('/verticals', { onRequest: [app.requireSuperAdmin] }, async () => {
    const r = await app.db.query(`
      SELECT ${INDUSTRY_SQL} AS industry,
             COUNT(*)::int                                   AS negocios,
             COUNT(*) FILTER (WHERE t.status = 'active')::int AS activos
      FROM tenants t
      GROUP BY 1
      ORDER BY negocios DESC, industry ASC
    `);
    return r.rows;
  });

  // GET /superadmin/vertical-stats — KPIs comparativos por vertical (para la
  // vista global de Estadísticas: ¿cómo va cada giro?).
  app.get('/vertical-stats', { onRequest: [app.requireSuperAdmin] }, async () => {
    const r = await app.db.query(`
      SELECT industry,
             COUNT(*)::int                              AS negocios,
             COUNT(*) FILTER (WHERE status='active')::int AS activos,
             COALESCE(SUM(minutes_used_mo),0)::int      AS minutes,
             SUM(convs30)::int                          AS convs_30d,
             SUM(leads_c)::int                          AS leads,
             SUM(appts_c)::int                          AS appointments
      FROM (
        SELECT t.id, t.status, t.minutes_used_mo,
               ${INDUSTRY_SQL} AS industry,
               (SELECT COUNT(*) FROM conversations c WHERE c.tenant_id = t.id AND c.created_at > NOW() - INTERVAL '30 days') AS convs30,
               (SELECT COUNT(*) FROM leads l        WHERE l.tenant_id = t.id) AS leads_c,
               (SELECT COUNT(*) FROM appointments a WHERE a.tenant_id = t.id) AS appts_c
        FROM tenants t
      ) x
      GROUP BY industry
      ORDER BY convs_30d DESC, negocios DESC
    `);
    return r.rows;
  });

  // GET /superadmin/reports?industry=&tenantId=&days= — métricas operativas por scope
  app.get('/reports', { onRequest: [app.requireSuperAdmin] }, async (request) => {
    const { industry = null, tenantId = null } = request.query || {};
    const days  = Math.max(1, Math.min(365, parseInt(request.query.days || '30')));
    const ids   = (await tenantsInScope({ industry, tenantId })).map((t) => t.id);
    const level = tenantId ? 'negocio' : (industry ? 'vertical' : 'global');

    const empty = {
      convs_total: 0, convs_voice: 0, convs_whatsapp: 0, avg_duration_mins: 0,
      leads_total: 0, leads_converted: 0, leads_new: 0, leads_qualified: 0,
      appts_total: 0, appts_confirmed: 0, appts_cancelled: 0,
      minutes_used: 0, minutes_max: 0, conversion_rate: 0, scheduling_rate: 0,
    };
    if (!ids.length) return { level, scope: { industry, tenantId }, days, ...empty };

    const r = await app.db.query(`
      SELECT
        (SELECT COUNT(*) FROM conversations WHERE tenant_id=ANY($1) AND created_at > NOW()-($2||' days')::interval)::int AS convs_total,
        (SELECT COUNT(*) FROM conversations WHERE tenant_id=ANY($1) AND channel='voice'    AND created_at > NOW()-($2||' days')::interval)::int AS convs_voice,
        (SELECT COUNT(*) FROM conversations WHERE tenant_id=ANY($1) AND channel='whatsapp' AND created_at > NOW()-($2||' days')::interval)::int AS convs_whatsapp,
        (SELECT COALESCE(ROUND(AVG(duration_secs)/60.0,1),0) FROM conversations WHERE tenant_id=ANY($1) AND duration_secs IS NOT NULL AND created_at > NOW()-($2||' days')::interval) AS avg_duration_mins,
        (SELECT COUNT(*) FROM leads WHERE tenant_id=ANY($1) AND created_at > NOW()-($2||' days')::interval)::int AS leads_total,
        (SELECT COUNT(*) FROM leads WHERE tenant_id=ANY($1) AND status='converted' AND created_at > NOW()-($2||' days')::interval)::int AS leads_converted,
        (SELECT COUNT(*) FROM leads WHERE tenant_id=ANY($1) AND status='new'       AND created_at > NOW()-($2||' days')::interval)::int AS leads_new,
        (SELECT COUNT(*) FROM leads WHERE tenant_id=ANY($1) AND status='qualified' AND created_at > NOW()-($2||' days')::interval)::int AS leads_qualified,
        (SELECT COUNT(*) FROM appointments WHERE tenant_id=ANY($1) AND created_at > NOW()-($2||' days')::interval)::int AS appts_total,
        (SELECT COUNT(*) FROM appointments WHERE tenant_id=ANY($1) AND status='confirmed' AND created_at > NOW()-($2||' days')::interval)::int AS appts_confirmed,
        (SELECT COUNT(*) FROM appointments WHERE tenant_id=ANY($1) AND status='cancelled' AND created_at > NOW()-($2||' days')::interval)::int AS appts_cancelled,
        (SELECT COALESCE(SUM(minutes_used_mo),0) FROM tenants WHERE id=ANY($1))::int AS minutes_used,
        (SELECT COALESCE(SUM(max_minutes_mo),0)  FROM tenants WHERE id=ANY($1))::int AS minutes_max
    `, [ids, String(days)]);

    const d = r.rows[0];
    d.conversion_rate = d.leads_total  > 0 ? Math.round(d.leads_converted / d.leads_total * 100) : 0;
    d.scheduling_rate = d.convs_total  > 0 ? Math.round(d.appts_total     / d.convs_total * 100) : 0;
    return { level, scope: { industry, tenantId }, days, ...d };
  });

  // GET /superadmin/insights?industry=&tenantId=&days=
  // "Voz del cliente": agrega la señal cualitativa que el análisis de cada
  // conversación deja en conversations.analysis (intent, topics, objections,
  // kb_gap, unanswered_question) + sentiment/outcome/handoff. Scope-aware.
  app.get('/insights', { onRequest: [app.requireSuperAdmin] }, async (request) => {
    const { industry = null, tenantId = null } = request.query || {};
    const days  = Math.max(1, Math.min(365, parseInt(request.query.days || '30')));
    const ids   = (await tenantsInScope({ industry, tenantId })).map((t) => t.id);
    const level = tenantId ? 'negocio' : (industry ? 'vertical' : 'global');

    const empty = {
      total_convs: 0, total_analyzed: 0, kb_gaps: 0,
      sentiment: { positivo: 0, neutral: 0, negativo: 0, sin_dato: 0 },
      outcomes: [], intents: [], topics: [], objections: [],
      unanswered: [], handoffs: { count: 0, reasons: [] },
    };
    if (!ids.length) return { level, scope: { industry, tenantId }, days, ...empty };

    const P = [ids, String(days)];
    const W = `tenant_id = ANY($1) AND created_at > NOW() - ($2 || ' days')::interval`;
    // Extrae con seguridad un array jsonb (objetos/null → '[]' para no romper).
    const arr = (col) => `CASE WHEN jsonb_typeof(c.analysis->'${col}')='array' THEN c.analysis->'${col}' ELSE '[]'::jsonb END`;

    const [totals, sentiment, outcomes, intents, topics, objections, unanswered, handoffs] = await Promise.all([
      app.db.query(`SELECT
          COUNT(*)::int AS total_convs,
          COUNT(*) FILTER (WHERE analysis IS NOT NULL)::int AS total_analyzed,
          COUNT(*) FILTER (WHERE (analysis->>'kb_gap')::boolean IS TRUE)::int AS kb_gaps
        FROM conversations WHERE ${W}`, P),
      app.db.query(`SELECT lower(COALESCE(NULLIF(sentiment,''),'sin_dato')) AS s, COUNT(*)::int AS n
        FROM conversations WHERE ${W} GROUP BY 1`, P),
      app.db.query(`SELECT COALESCE(NULLIF(outcome,''),'(sin dato)') AS outcome, COUNT(*)::int AS n
        FROM conversations WHERE ${W} GROUP BY 1 ORDER BY 2 DESC LIMIT 10`, P),
      app.db.query(`SELECT analysis->>'intent' AS intent, COUNT(*)::int AS n
        FROM conversations WHERE ${W} AND COALESCE(analysis->>'intent','') <> ''
        GROUP BY 1 ORDER BY 2 DESC LIMIT 10`, P),
      app.db.query(`SELECT topic, COUNT(*)::int AS n
        FROM conversations c CROSS JOIN LATERAL jsonb_array_elements_text(${arr('topics')}) AS topic
        WHERE ${W} GROUP BY 1 ORDER BY 2 DESC LIMIT 12`, P),
      app.db.query(`SELECT objection, COUNT(*)::int AS n
        FROM conversations c CROSS JOIN LATERAL jsonb_array_elements_text(${arr('objections')}) AS objection
        WHERE ${W} GROUP BY 1 ORDER BY 2 DESC LIMIT 12`, P),
      app.db.query(`SELECT c.analysis->>'unanswered_question' AS question,
              c.contact_name, c.channel, c.created_at, t.name AS tenant_name
        FROM conversations c JOIN tenants t ON t.id = c.tenant_id
        WHERE c.tenant_id = ANY($1) AND c.created_at > NOW() - ($2 || ' days')::interval
          AND COALESCE(c.analysis->>'unanswered_question','') <> ''
        ORDER BY c.created_at DESC LIMIT 20`, P),
      app.db.query(`SELECT COALESCE(NULLIF(handoff_reason,''),'(sin motivo)') AS reason, COUNT(*)::int AS n
        FROM conversations WHERE ${W} AND (needs_human IS TRUE OR COALESCE(handoff_reason,'') <> '')
        GROUP BY 1 ORDER BY 2 DESC LIMIT 10`, P),
    ]);

    const sent = { positivo: 0, neutral: 0, negativo: 0, sin_dato: 0 };
    for (const row of sentiment.rows) {
      const k = Object.prototype.hasOwnProperty.call(sent, row.s) ? row.s : 'sin_dato';
      sent[k] += row.n;
    }

    return {
      level, scope: { industry, tenantId }, days,
      total_convs:    totals.rows[0].total_convs,
      total_analyzed: totals.rows[0].total_analyzed,
      kb_gaps:        totals.rows[0].kb_gaps,
      sentiment:      sent,
      outcomes:       outcomes.rows,
      intents:        intents.rows,
      topics:         topics.rows,
      objections:     objections.rows,
      unanswered:     unanswered.rows,
      handoffs:       { count: handoffs.rows.reduce((a, r) => a + r.n, 0), reasons: handoffs.rows },
    };
  });

  // Resuelve los tenants en scope según industry/tenantId (helper interno).
  async function tenantsInScope({ industry, tenantId }) {
    if (tenantId) {
      return (await app.db.query('SELECT id, name, slug FROM tenants WHERE id = $1', [tenantId])).rows;
    }
    if (industry) {
      return (await app.db.query(
        `SELECT id, name, slug FROM tenants t WHERE ${INDUSTRY_SQL} = $1`, [industry]
      )).rows;
    }
    return (await app.db.query('SELECT id, name, slug FROM tenants')).rows;
  }

  // GET /superadmin/scope-overview?industry=&tenantId=
  // Sin params → Global; con industry → Vertical; con tenantId → un Negocio.
  app.get('/scope-overview', { onRequest: [app.requireSuperAdmin] }, async (request) => {
    const { industry = null, tenantId = null } = request.query || {};
    const tenants = await tenantsInScope({ industry, tenantId });
    const ids = tenants.map((t) => t.id);

    const level = tenantId ? 'negocio' : (industry ? 'vertical' : 'global');
    if (!ids.length) {
      return { level, scope: { industry, tenantId }, negocios: 0, kpis: {}, recent: { convs: [], leads: [], appts: [] } };
    }

    const [kpis, convs, leads, appts] = await Promise.all([
      app.db.query(`
        SELECT
          (SELECT COUNT(*)::int FROM conversations WHERE tenant_id = ANY($1))                                  AS conversations,
          (SELECT COUNT(*)::int FROM conversations WHERE tenant_id = ANY($1) AND created_at > NOW() - INTERVAL '30 days') AS conversations_30d,
          (SELECT COUNT(*)::int FROM leads         WHERE tenant_id = ANY($1))                                  AS leads,
          (SELECT COUNT(*)::int FROM appointments  WHERE tenant_id = ANY($1))                                  AS appointments,
          (SELECT COUNT(*)::int FROM agents        WHERE tenant_id = ANY($1) AND is_active)                    AS active_agents,
          (SELECT COALESCE(SUM(minutes_used_mo),0)::int FROM tenants WHERE id = ANY($1))                       AS minutes_used
      `, [ids]),
      app.db.query(`
        SELECT c.id, c.channel, c.status, c.created_at, t.name AS tenant_name, t.id AS tenant_id
        FROM conversations c JOIN tenants t ON t.id = c.tenant_id
        WHERE c.tenant_id = ANY($1) ORDER BY c.created_at DESC LIMIT 12`, [ids]),
      app.db.query(`
        SELECT l.id, l.name, l.phone, l.status, l.created_at, t.name AS tenant_name, t.id AS tenant_id
        FROM leads l JOIN tenants t ON t.id = l.tenant_id
        WHERE l.tenant_id = ANY($1) ORDER BY l.created_at DESC LIMIT 12`, [ids]),
      app.db.query(`
        SELECT a.id, a.patient_name, a.scheduled_at, a.status, t.name AS tenant_name, t.id AS tenant_id
        FROM appointments a JOIN tenants t ON t.id = a.tenant_id
        WHERE a.tenant_id = ANY($1) ORDER BY a.created_at DESC LIMIT 12`, [ids]),
    ]);

    return {
      level,
      scope: { industry, tenantId },
      negocios: ids.length,
      kpis: kpis.rows[0],
      recent: { convs: convs.rows, leads: leads.rows, appts: appts.rows },
    };
  });
}

module.exports = superadminRoutes;
