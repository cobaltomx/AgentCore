'use strict';

/**
 * Widget de chat web — superficie PÚBLICA (sin JWT)
 * Prefix: /api/v1/widget
 *
 * Autenticación por widget_key público (va en el <script> del sitio del cliente).
 * NO es secreto → la ruta es segura por diseño: CORS abierto sin credenciales,
 * rate-limit estricto, gate is_ready, y filtro anti-inyección (simulator-guard).
 *
 *   GET  /config?key=wgt_xxx    → config visual del widget (color, saludo, activo)
 *   POST /message               → { key, visitor_id, text } → respuesta del bot
 */

const WebChatAgent = require('../../agents/webchat-agent');
const { analyzeMessage } = require('../../services/simulator-guard');
const { tenantHasFeature } = require('../../services/features');

const BLOCKED_REPLY = 'Lo siento, solo puedo ayudarte con consultas relacionadas al negocio. ¿En qué más puedo ayudarte?';

async function widgetRoutes(app) {

  const webAgent = new WebChatAgent({ db: app.db, redis: app.redis });

  // ── CORS abierto para todo el plugin (widget corre en dominios externos) ──
  app.addHook('onRequest', async (req, reply) => {
    reply.header('Access-Control-Allow-Origin', '*');
    reply.header('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
    reply.header('Access-Control-Allow-Headers', 'Content-Type');
    if (req.method === 'OPTIONS') {
      reply.code(204).send();
    }
  });

  // Resuelve el tenant desde el widget_key (activo + widget habilitado)
  async function resolveTenant(key) {
    if (!key || typeof key !== 'string' || !key.startsWith('wgt_')) return null;
    const r = await app.db.query(
      `SELECT id, name, status, is_ready, settings FROM tenants WHERE widget_key=$1 LIMIT 1`,
      [key]
    );
    return r.rows[0] || null;
  }

  // ── GET /config — el widget pide su configuración visual al cargar ────────
  app.get('/config', {
    config: { rateLimit: { max: 60, timeWindow: '1 minute' } },
  }, async (req, reply) => {
    const tenant = await resolveTenant(req.query.key);
    if (!tenant) return reply.code(404).send({ error: 'Widget no encontrado' });

    const w = tenant.settings?.widget || {};
    const webchatOn = await tenantHasFeature(app.db, tenant.id, 'webchat');
    return reply.send({
      enabled:  w.enabled !== false && !!tenant.is_ready && webchatOn,
      name:     w.name    || tenant.name,
      color:    w.color   || '#696cff',
      welcome:  w.welcome || `¡Hola! 👋 ¿En qué puedo ayudarte?`,
    });
  });

  // ── POST /message — el visitante envía un mensaje ─────────────────────────
  app.post('/message', {
    config: { rateLimit: { max: 20, timeWindow: '1 minute' } }, // anti-abuso
    schema: {
      body: {
        type: 'object',
        required: ['key', 'visitor_id', 'text'],
        properties: {
          key:        { type: 'string' },
          visitor_id: { type: 'string', minLength: 6, maxLength: 64 },
          text:       { type: 'string', minLength: 1, maxLength: 1000 },
        },
      },
    },
  }, async (req, reply) => {
    const { key, visitor_id, text } = req.body;

    const tenant = await resolveTenant(key);
    if (!tenant) return reply.code(404).send({ error: 'Widget no encontrado' });

    // Gate: el bot debe estar activo (aprobado) y el tenant operativo
    if (!['active', 'trial'].includes(tenant.status) || !tenant.is_ready) {
      return reply.send({ reply: 'El asistente no está disponible en este momento.', ended: true });
    }
    if (tenant.settings?.widget?.enabled === false) {
      return reply.send({ reply: 'El chat no está disponible.', ended: true });
    }
    // Gate: el canal de chat web debe estar habilitado para el tenant (Fase C)
    if (!(await tenantHasFeature(app.db, tenant.id, 'webchat'))) {
      return reply.send({ reply: 'El chat no está disponible.', ended: true });
    }

    // Zona de protección: bloquear intentos de inyección antes de tocar el LLM
    const guard = analyzeMessage(text);
    if (!guard.safe) {
      app.log.warn({ tenantId: tenant.id, patterns: guard.patterns }, '[Widget] Mensaje bloqueado');
      return reply.send({ reply: BLOCKED_REPLY, ended: false, blocked: true });
    }

    try {
      const result = await webAgent.handleMessage({
        tenantId:  tenant.id,
        visitorId: visitor_id,
        text,
      });
      return reply.send({ reply: result.reply, ended: result.ended, cards: result.cards || null, cart: result.cart || null });
    } catch (err) {
      app.log.error({ err }, '[Widget] Error procesando mensaje');
      return reply.code(500).send({ reply: 'Ocurrió un error. Intenta de nuevo.', ended: false });
    }
  });

  // ── POST /cart — acción de carrito DETERMINISTA (botón "+" del menú) ───────
  app.post('/cart', {
    config: { rateLimit: { max: 40, timeWindow: '1 minute' } },
    schema: {
      body: {
        type: 'object',
        required: ['key', 'visitor_id'],
        properties: {
          key:          { type: 'string' },
          visitor_id:   { type: 'string', minLength: 6, maxLength: 64 },
          product_name: { type: 'string', maxLength: 200 },
          action:       { type: 'string', enum: ['add', 'view'] },
        },
      },
    },
  }, async (req, reply) => {
    const { key, visitor_id, product_name, action } = req.body;
    const tenant = await resolveTenant(key);
    if (!tenant) return reply.code(404).send({ error: 'Widget no encontrado' });
    if (!['active', 'trial'].includes(tenant.status) || !tenant.is_ready) {
      return reply.send({ cart: { count: 0, total_cents: 0, items: [] } });
    }
    if (!(await tenantHasFeature(app.db, tenant.id, 'webchat'))) {
      return reply.send({ cart: { count: 0, total_cents: 0, items: [] } });
    }
    try {
      const result = await webAgent.cartAction({
        tenantId: tenant.id, visitorId: visitor_id,
        action: action || 'add', productName: product_name,
      });
      return reply.send({
        cart:    result.cart || { count: 0, total_cents: 0, items: [] },
        added:   result.added || null,
        message: result.speech || null,
      });
    } catch (err) {
      app.log.error({ err }, '[Widget] Error en carrito');
      return reply.code(500).send({ error: 'No se pudo actualizar el carrito' });
    }
  });
}

module.exports = widgetRoutes;
