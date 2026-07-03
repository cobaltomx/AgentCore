'use strict';

/**
 * Utilidades de test (runner nativo `node --test`, sin dependencias extra).
 *
 * mockDb(routes): simula un Pool de pg. `routes` = [[substringSQL, rowsOrFn], …];
 *   el primer substring que aparezca en el SQL gana; por defecto { rows: [] }.
 *   Guarda todas las llamadas en `.calls` para aserciones.
 * buildApp(registerFn, db, opts): arma una instancia Fastify mínima con formbody,
 *   decora db/redis y registra SOLO la ruta bajo prueba. Usar con app.inject().
 */

const Fastify   = require('fastify');
const formbody  = require('@fastify/formbody');

function mockDb(routes = []) {
  const db = {
    calls: [],
    async query(sql, params) {
      db.calls.push({ sql, params });
      for (const [needle, rows] of routes) {
        if (sql.includes(needle)) {
          const r = typeof rows === 'function' ? rows(params) : rows;
          return { rows: r || [] };
        }
      }
      return { rows: [] };
    },
    async connect() {
      return { query: (s, p) => db.query(s, p), release() {} };
    },
  };
  return db;
}

// Redis no-op: cualquier método devuelve una promesa que resuelve a null.
function mockRedis() {
  return new Proxy({}, { get: () => async () => null });
}

async function buildApp(registerFn, db, opts = {}) {
  const app = Fastify({ logger: false });
  await app.register(formbody);
  app.decorate('db', db);
  app.decorate('redis', opts.redis || mockRedis());
  await app.register(registerFn);
  await app.ready();
  return app;
}

const tick = (ms = 50) => new Promise((r) => setTimeout(r, ms));

module.exports = { mockDb, mockRedis, buildApp, tick };
