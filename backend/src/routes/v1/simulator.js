'use strict';

/**
 * Simulator API — /api/v1/simulator
 *
 * GET  /scenarios        → escenarios sugeridos por industria del tenant
 * POST /chat             → chat con el LLM real (con capa de seguridad)
 * POST /approve          → aprobar bot y activar webhooks (is_ready = true)
 * GET  /status           → estado actual del simulador (sesiones, aprobación)
 * GET  /security-events  → eventos de seguridad de la sesión actual
 */

const { v4: uuidv4 } = require('uuid');
const { chat } = require('../../services/llm-router');
const {
  analyzeMessage,
  buildSecureSystemPrompt,
  checkResponseLeak,
  getScenariosForIndustry,
} = require('../../services/simulator-guard');

const SIMULATOR_BLOCKED_RESPONSE =
  'Lo siento, solo puedo ayudarte con consultas relacionadas al negocio. ¿En qué más puedo asistirte?';

const MIN_SCENARIOS_FOR_APPROVAL = 3;

async function simulatorRoutes(app) {

  // ── GET /scenarios ──────────────────────────────────────────────────────
  app.get('/scenarios', { onRequest: [app.requireTenant] }, async (request, reply) => {
    try {
      const settings = request.tenant.settings || {};
      // La industria puede vivir en settings.industry (legacy) o en
      // settings.businessProfile.industry (onboarding). Se revisan ambas.
      const industry = settings.industry
        || settings.businessProfile?.industry
        || settings.business_type
        || 'general';
      const scenarios = getScenariosForIndustry(industry);
      return reply.send({ scenarios, industry });
    } catch (err) {
      app.log.error({ err }, '[Simulator] GET /scenarios error');
      return reply.code(500).send({ error: 'Error al obtener escenarios' });
    }
  });

  // ── GET /status ─────────────────────────────────────────────────────────
  app.get('/status', { onRequest: [app.requireTenant] }, async (request, reply) => {
    try {
      const tenantId = request.tenant.id;

      // Estado del tenant + auto-detección de pasos en una sola query
      const [tenantRes, sessRes, detectRes] = await Promise.all([
        app.db.query(
          `SELECT is_ready, setup_steps, settings FROM tenants WHERE id = $1`,
          [tenantId]
        ),
        app.db.query(
          `SELECT COUNT(DISTINCT session_id) AS sessions,
                  COUNT(DISTINCT scenario)   AS scenarios_tested
           FROM simulator_logs
           WHERE tenant_id = $1 AND role = 'user'`,
          [tenantId]
        ),
        app.db.query(
          `SELECT
             EXISTS(SELECT 1 FROM agents WHERE tenant_id=$1 AND is_active=true)                          AS has_agent,
             EXISTS(SELECT 1 FROM kb_documents WHERE tenant_id=$1)                                       AS has_kb,
             (SELECT COUNT(*) FROM users WHERE tenant_id=$1 AND is_active=true) > 1                      AS has_team,
             EXISTS(SELECT 1 FROM agents WHERE tenant_id=$1 AND is_active=true AND phone_number IS NOT NULL) AS has_phone`,
          [tenantId]
        ),
      ]);

      const row      = tenantRes.rows[0] || {};
      const simStats = sessRes.rows[0]   || { sessions: 0, scenarios_tested: 0 };
      const detect   = detectRes.rows[0] || {};
      const settings = row.settings || {};
      const saved    = row.setup_steps || {};

      // Canal activo: número de voz en algún agente O WhatsApp configurado
      const hasWhatsApp = !!(settings.whatsapp?.phoneNumberId);
      const hasChannel  = detect.has_phone || hasWhatsApp;

      // Auto-detección — un paso completado nunca se "des-completa" (|| saved)
      const steps = {
        business:  !!(saved.business  || settings.onboarding_completed || detect.has_agent),
        knowledge: !!(saved.knowledge || detect.has_kb),
        users:     !!(saved.users     || detect.has_team),
        channel:   !!(saved.channel   || hasChannel),
        simulator: !!(saved.simulator || row.is_ready),
      };

      // Persistir solo si detectamos algo nuevo (evita writes innecesarios)
      const changed = Object.keys(steps).some(k => steps[k] !== !!saved[k]);
      if (changed) {
        app.db.query(
          `UPDATE tenants SET setup_steps = $2::jsonb, updated_at = NOW() WHERE id = $1`,
          [tenantId, JSON.stringify(steps)]
        ).catch(e => app.log.warn('[Simulator] No se pudo persistir setup_steps:', e.message));
      }

      return reply.send({
        is_ready:          row.is_ready ?? false,
        setup_steps:       steps,
        sessions:          parseInt(simStats.sessions) || 0,
        scenarios_tested:  parseInt(simStats.scenarios_tested) || 0,
        can_approve:       parseInt(simStats.scenarios_tested) >= MIN_SCENARIOS_FOR_APPROVAL,
        min_scenarios:     MIN_SCENARIOS_FOR_APPROVAL,
      });
    } catch (err) {
      app.log.error({ err }, '[Simulator] GET /status error');
      return reply.code(500).send({ error: 'Error al obtener estado' });
    }
  });

  // ── GET /security-events ────────────────────────────────────────────────
  app.get('/security-events', { onRequest: [app.requireTenant] }, async (request, reply) => {
    try {
      const tenantId  = request.tenant.id;
      const sessionId = request.query.session_id;

      let sql = `SELECT id, message, patterns, severity, created_at
                 FROM simulator_security_events
                 WHERE tenant_id = $1`;
      const params = [tenantId];

      if (sessionId) {
        sql += ` AND session_id = $2`;
        params.push(sessionId);
      }
      sql += ` ORDER BY created_at DESC LIMIT 50`;

      const res = await app.db.query(sql, params);
      return reply.send(res.rows);
    } catch (err) {
      app.log.error({ err }, '[Simulator] GET /security-events error');
      return reply.code(500).send({ error: 'Error al obtener eventos de seguridad' });
    }
  });

  // ── POST /chat ──────────────────────────────────────────────────────────
  app.post('/chat', {
    onRequest: [app.requireTenant],
    schema: {
      body: {
        type: 'object',
        required: ['message'],
        properties: {
          message:    { type: 'string', minLength: 1, maxLength: 2000 },
          session_id: { type: 'string' },
          scenario:   { type: 'string' },
          history:    {
            type: 'array',
            items: {
              type: 'object',
              properties: {
                role:    { type: 'string', enum: ['user', 'assistant'] },
                content: { type: 'string' },
              },
            },
          },
        },
      },
    },
  }, async (request, reply) => {
    const tenantId = request.tenant.id;
    const userId   = request.userId;

    const { message, scenario = 'free', history = [] } = request.body;
    const sessionId = request.body.session_id || uuidv4();

    // ── 1. Pre-filtro de seguridad ────────────────────────────────────────
    const guard = analyzeMessage(message);

    if (!guard.safe) {
      // Registrar evento de seguridad
      try {
        await app.db.query(
          `INSERT INTO simulator_security_events (tenant_id, user_id, session_id, message, patterns, severity)
           VALUES ($1, $2, $3, $4, $5, $6)`,
          [tenantId, userId, sessionId, message, guard.patterns, guard.severity]
        );
      } catch (e) { app.log.warn('[Simulator] Cannot save security event:', e.message); }

      return reply.send({
        response:       SIMULATOR_BLOCKED_RESPONSE,
        session_id:     sessionId,
        blocked:        true,
        security_event: { patterns: guard.patterns, severity: guard.severity },
      });
    }

    // ── 2. Obtener sistema del agente activo del tenant ───────────────────
    // Note: tenantId is already validated by requireTenant middleware
    let systemPrompt = 'Eres un asistente de atención al cliente amable y profesional. Responde siempre en español.';
    try {
      const agentRes = await app.db.query(
        `SELECT system_prompt, name FROM agents
         WHERE tenant_id = $1 AND is_active = true
         ORDER BY created_at ASC LIMIT 1`,
        [tenantId]
      );
      if (agentRes.rows[0]?.system_prompt) {
        systemPrompt = agentRes.rows[0].system_prompt;
      }
    } catch (e) {
      app.log.warn('[Simulator] Cannot fetch agent system prompt:', e.message);
    }

    // ── 3. Envolver con meta-prompt de seguridad ──────────────────────────
    const securePrompt = buildSecureSystemPrompt(systemPrompt);

    // ── 4. Construir historial para LLM ──────────────────────────────────
    const llmMessages = [
      ...history.slice(-10).map(m => ({ role: m.role, content: m.content })),
      { role: 'user', content: message },
    ];

    // ── 5. Llamar al LLM real ─────────────────────────────────────────────
    let llmResult;
    try {
      llmResult = await chat({
        systemPrompt: securePrompt,
        messages:     llmMessages,
        taskType:     'default',
      });
    } catch (err) {
      app.log.error({ err }, '[Simulator] LLM error');
      return reply.code(502).send({ error: 'Error al comunicarse con el modelo de IA' });
    }

    // ── 6. Post-filtro — detectar fuga de datos ───────────────────────────
    let responseText  = llmResult.content || 'Lo siento, no pude procesar tu solicitud.';
    let leaked        = false;

    if (checkResponseLeak(responseText)) {
      leaked       = true;
      responseText = SIMULATOR_BLOCKED_RESPONSE;
      app.log.warn({ tenantId, sessionId }, '[Simulator] Response leak detected and blocked');
    }

    // ── 7. Guardar en logs ────────────────────────────────────────────────
    try {
      // Mensaje del usuario
      await app.db.query(
        `INSERT INTO simulator_logs (tenant_id, user_id, session_id, scenario, role, content)
         VALUES ($1, $2, $3, $4, 'user', $5)`,
        [tenantId, userId, sessionId, scenario, message]
      );
      // Respuesta del bot
      await app.db.query(
        `INSERT INTO simulator_logs (tenant_id, user_id, session_id, scenario, role, content, tokens_used, latency_ms, model)
         VALUES ($1, $2, $3, $4, 'assistant', $5, $6, $7, $8)`,
        [tenantId, userId, sessionId, scenario, responseText,
         llmResult.tokensUsed || 0, llmResult.latencyMs || 0, llmResult.model || null]
      );
    } catch (e) {
      app.log.warn('[Simulator] Cannot save log:', e.message);
    }

    // ── 8. Menú visual: si el cliente pide la carta/menú y el negocio tiene
    //    catálogo, adjuntar las tarjetas para que el chat las muestre con foto.
    //    (El simulador es prompt-only, así que el menú lo resolvemos aquí.)
    let menu = null;
    try {
      if (/\b(men[uú]|carta|qu[eé]\s+tienen|que\s+tienen|opciones|platillos?|para\s+comer|de\s+comer|antojo|recomien)/i.test(message)) {
        const pr = await app.db.query(
          `SELECT name, price_cents, currency, description, image_url, category
           FROM products WHERE tenant_id = $1 AND is_active = true
           ORDER BY sort_order ASC, name ASC LIMIT 24`, [tenantId]);
        if (pr.rows.length) {
          menu = pr.rows.map(p => ({
            name:        p.name,
            price_cents: Number(p.price_cents) || 0,
            currency:    p.currency || 'MXN',
            description: p.description || '',
            image_url:   p.image_url || null,
            category:    p.category || '',
          }));
        }
      }
    } catch (e) { app.log.warn('[Simulator] menu fetch:', e.message); }

    return reply.send({
      response:   responseText,
      session_id: sessionId,
      blocked:    leaked,
      menu,
      meta: {
        model:      llmResult.model,
        tokens:     llmResult.tokensUsed,
        latency_ms: llmResult.latencyMs,
      },
    });
  });

  // ── POST /approve ───────────────────────────────────────────────────────
  app.post('/approve', { onRequest: [app.requireAdmin] }, async (request, reply) => {
    const tenantId = request.tenant.id;
    const userId   = request.userId;

    try {
      // Verificar que probó suficientes escenarios
      const sessRes = await app.db.query(
        `SELECT COUNT(DISTINCT scenario) AS scenarios_tested
         FROM simulator_logs
         WHERE tenant_id = $1 AND role = 'user'`,
        [tenantId]
      );
      const tested = parseInt(sessRes.rows[0]?.scenarios_tested) || 0;

      if (tested < MIN_SCENARIOS_FOR_APPROVAL) {
        return reply.code(400).send({
          error: `Debes probar al menos ${MIN_SCENARIOS_FOR_APPROVAL} escenarios antes de aprobar.`,
          scenarios_tested: tested,
          required: MIN_SCENARIOS_FOR_APPROVAL,
        });
      }

      // Marcar is_ready = true y completar el paso del simulador
      await app.db.query(
        `UPDATE tenants
         SET is_ready    = true,
             setup_steps = setup_steps || '{"simulator": true}'::jsonb,
             updated_at  = NOW()
         WHERE id = $1`,
        [tenantId]
      );

      app.log.info({ tenantId, userId }, '[Simulator] ✅ Bot aprobado — is_ready = true');

      // Notificar activación
      const { createNotification } = require('../../services/notifications');
      createNotification(app.db, {
        tenantId,
        type:  'campaign_completed',
        title: '🚀 Bot activado exitosamente',
        body:  'Tu agente IA está listo y comenzará a atender clientes.',
        link:  '/pages/conversations.php',
      });

      return reply.send({
        ok:      true,
        message: 'Bot aprobado. Los webhooks han sido activados.',
        is_ready: true,
      });

    } catch (err) {
      app.log.error({ err }, '[Simulator] POST /approve error');
      return reply.code(500).send({ error: 'Error al aprobar el bot' });
    }
  });

  // ── POST /setup-step ────────────────────────────────────────────────────
  // Marca un paso del wizard como completado
  app.post('/setup-step', {
    onRequest: [app.requireAdmin],
    schema: {
      body: {
        type: 'object',
        required: ['step'],
        properties: {
          step:  { type: 'string' },
          value: { type: 'boolean' },
        },
      },
    },
  }, async (request, reply) => {
    const tenantId = request.tenant.id;

    const { step, value = true } = request.body;
    const ALLOWED_STEPS = ['business', 'knowledge', 'users', 'channel', 'simulator'];
    if (!ALLOWED_STEPS.includes(step)) {
      return reply.code(400).send({ error: 'Paso no válido' });
    }

    try {
      await app.db.query(
        `UPDATE tenants
         SET setup_steps = setup_steps || $2::jsonb,
             updated_at  = NOW()
         WHERE id = $1`,
        [tenantId, JSON.stringify({ [step]: value })]
      );
      return reply.send({ ok: true, step, value });
    } catch (err) {
      app.log.error({ err }, '[Simulator] POST /setup-step error');
      return reply.code(500).send({ error: 'Error al actualizar paso' });
    }
  });
}

module.exports = simulatorRoutes;
