'use strict';


const { logger } = require('./logger');
const log = logger('Notifications');
/**
 * Helper para crear notificaciones en DB.
 * Non-fatal: los errores se loguean pero nunca interrumpen el flujo principal.
 *
 * @param {object} db         - Instancia de pool de PostgreSQL (app.db)
 * @param {object} opts
 * @param {string} opts.tenantId
 * @param {string} opts.type   - 'new_conversation'|'new_lead'|'appointment_reminder'|'campaign_completed'
 * @param {string} opts.title  - Texto principal (corto, ~60 chars)
 * @param {string} [opts.body] - Detalle adicional
 * @param {string} [opts.link] - URL relativa: '/pages/conversations.php?id=...'
 */
async function createNotification(db, { tenantId, type, title, body = null, link = null }) {
  try {
    await db.query(
      `INSERT INTO notifications (tenant_id, type, title, body, link)
       VALUES ($1, $2, $3, $4, $5)`,
      [tenantId, type, title, body, link]
    );
  } catch (err) {
    // No relanzar — las notificaciones son un nice-to-have
    log.error('[notifications] createNotification error:', err.message);
  }
}

module.exports = { createNotification };
