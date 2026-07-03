'use strict';

/**
 * Logger estructurado ligero (sin dependencias) para servicios y workers.
 *
 * Motivo: los servicios se instancian con {db,redis}, no con el logger de
 * Fastify, así que usaban console.log/warn/error sueltos — sin nivel, sin
 * contexto, imposibles de filtrar en producción. Este módulo emite el MISMO
 * formato JSON que pino/Fastify ({"level":N,"time":ms,...,"msg":...}) para que
 * todos los logs sean homogéneos y grep-ables, respetando LOG_LEVEL.
 *
 * Uso:
 *   const { logger } = require('./logger');
 *   const log = logger('Executor');           // componente
 *   log.info({ toolName, tenantId }, 'tool ejecutada');
 *   log.error({ err: e.message }, 'falló X');
 */

const os = require('os');

const LEVELS = { debug: 20, info: 30, warn: 40, error: 50 };
const HOSTNAME = os.hostname();
const PID = process.pid;

function threshold() {
  return LEVELS[String(process.env.LOG_LEVEL || 'info').toLowerCase()] || 30;
}

function emit(levelName, component, args) {
  const levelNum = LEVELS[levelName];
  if (levelNum < threshold()) return;

  // Firma flexible y compatible con console.*:
  //   log.info({ctx}, 'msg')            → estructurado
  //   log.error('[X] falló:', e.message) → strings se unen en msg (drop-in)
  const ctx = {};
  const parts = [];
  for (const a of args) {
    if (a && typeof a === 'object' && !(a instanceof Error)) Object.assign(ctx, a);
    else if (a instanceof Error) { ctx.err = a.message; parts.push(a.message); }
    else if (a !== undefined) parts.push(String(a));
  }

  const rec = {
    level: levelNum,
    time: Date.now(),
    pid: PID,
    hostname: HOSTNAME,
    component,
    ...ctx,
  };
  if (parts.length) rec.msg = parts.join(' ');

  const line = JSON.stringify(rec);
  if (levelNum >= LEVELS.error) process.stderr.write(line + '\n');
  else process.stdout.write(line + '\n');
}

/** Crea un logger con nombre de componente (aparece en cada línea). */
function logger(component = 'app') {
  return {
    debug: (...a) => emit('debug', component, a),
    info:  (...a) => emit('info',  component, a),
    warn:  (...a) => emit('warn',  component, a),
    error: (...a) => emit('error', component, a),
  };
}

module.exports = { logger, LEVELS };
