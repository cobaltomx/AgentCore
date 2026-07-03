'use strict';

const fp = require('fastify-plugin');
const path = require('path');
const fs = require('fs');

async function registerPlugins(app) {

  // CORS
  await app.register(require('@fastify/cors'), {
    origin: process.env.NODE_ENV === 'development'
      ? true
      : [process.env.APP_URL, /\.agentcore\.io$/],
    credentials: true,
  });

  // Body parsers
  await app.register(require('@fastify/formbody'));

  // WebSocket — Twilio Media Streams (audio bidireccional en tiempo real)
  await app.register(require('@fastify/websocket'), {
    options: { maxPayload: 1048576 }, // 1 MB por frame (de sobra para μ-law 20ms)
  });

  // Multipart (uploads de avatares, etc.) — 4 MB máx
  await app.register(require('@fastify/multipart'), {
    limits: { fileSize: 4 * 1024 * 1024, files: 1 },
  });

  // Static — sirve /uploads/* (avatares y otros archivos subidos)
  const uploadsDir = path.resolve(process.env.UPLOADS_DIR || path.join(__dirname, '..', '..', 'uploads'));
  if (!fs.existsSync(uploadsDir)) fs.mkdirSync(uploadsDir, { recursive: true });
  app.decorate('uploadsDir', uploadsDir);
  await app.register(require('@fastify/static'), {
    root: uploadsDir,
    prefix: '/uploads/',
    decorateReply: false,
  });

  // Cookies
  await app.register(require('@fastify/cookie'), {
    secret: process.env.COOKIE_SECRET,
  });

  // JWT
  await app.register(require('@fastify/jwt'), {
    secret: process.env.JWT_SECRET,
    cookie: {
      cookieName: 'token',
      signed:     false,
    },
  });

  // Rate limiting global
  await app.register(require('@fastify/rate-limit'), {
    max: 300,
    timeWindow: '1 minute',
    // Permitir override por ruta
    allowList: [],
    keyGenerator: (req) =>
      req.headers['x-real-ip'] ||
      req.headers['x-forwarded-for']?.split(',')[0]?.trim() ||
      req.ip,
    errorResponseBuilder: () => ({
      statusCode: 429,
      error: 'Too Many Requests',
      message: 'Límite de solicitudes excedido. Intenta más tarde.',
    }),
  });

  // PostgreSQL
  const { Pool } = require('pg');
  const pool = new Pool({ connectionString: process.env.DATABASE_URL });
  
  // Test connection
  try {
    const client = await pool.connect();
    await client.query('SELECT 1');
    client.release();
    app.log.info('✅ PostgreSQL conectado');
  } catch (err) {
    app.log.error('❌ PostgreSQL error:', err.message);
    throw err;
  }

  // Decorar app con db
  app.decorate('db', pool);

  // Redis
  const { Redis } = require('ioredis');
  const redis = new Redis(process.env.REDIS_URL);
  
  redis.on('connect', () => app.log.info('✅ Redis conectado'));
  redis.on('error', (err) => app.log.error('❌ Redis error:', err.message));
  
  app.decorate('redis', redis);

  // Decorador: verificar autenticación
  app.decorate('authenticate', async function(request, reply) {
    try {
      await request.jwtVerify();
    } catch (err) {
      reply.code(401).send({ error: 'No autorizado' });
    }
  });

  // Decorador: verificar tenant (extrae tenant_id del JWT y valida)
  app.decorate('requireTenant', async function(request, reply) {
    try {
      await request.jwtVerify();
      const { tenant_id, user_id } = request.user;

      if (!tenant_id) {
        return reply.code(403).send({ error: 'Tenant no identificado' });
      }

      // Validar que el tenant esté activo o en trial
      const result = await app.db.query(
        'SELECT id, status, plan, max_agents, max_minutes_mo, minutes_used_mo, settings FROM tenants WHERE id = $1',
        [tenant_id]
      );

      if (!result.rows[0] || !['active','trial'].includes(result.rows[0].status)) {
        return reply.code(403).send({ error: 'Tenant inactivo o suspendido' });
      }

      // Inyectar en request para uso en handlers
      request.tenant = result.rows[0];
      request.userId = user_id;
    } catch (err) {
      reply.code(401).send({ error: 'No autorizado' });
    }
  });

  // Decorador: solo admin o superadmin del tenant
  app.decorate('requireAdmin', async function(request, reply) {
    try {
      await request.jwtVerify();
      const { tenant_id, user_id, role } = request.user;

      if (!['admin', 'superadmin'].includes(role)) {
        return reply.code(403).send({ error: 'Se requiere rol admin' });
      }

      if (!tenant_id) return reply.code(403).send({ error: 'Tenant no identificado' });

      const result = await app.db.query(
        'SELECT id, status, plan, max_agents, max_minutes_mo, minutes_used_mo, settings FROM tenants WHERE id = $1',
        [tenant_id]
      );

      if (!result.rows[0] || !['active','trial'].includes(result.rows[0].status)) {
        return reply.code(403).send({ error: 'Tenant inactivo o suspendido' });
      }

      request.tenant = result.rows[0];
      request.userId = user_id;
      request.role   = role;
    } catch (err) {
      reply.code(401).send({ error: 'No autorizado' });
    }
  });

  // Decorador: solo superadmin global (AgentCore platform)
  app.decorate('requireSuperAdmin', async function(request, reply) {
    try {
      await request.jwtVerify();
      const { user_id, role } = request.user;

      if (role !== 'superadmin') {
        return reply.code(403).send({ error: 'Acceso exclusivo a superadmin' });
      }

      request.userId = user_id;
      request.role   = role;
    } catch (err) {
      reply.code(401).send({ error: 'No autorizado' });
    }
  });

  app.log.info('✅ Plugins registrados');
}

module.exports = { registerPlugins };
