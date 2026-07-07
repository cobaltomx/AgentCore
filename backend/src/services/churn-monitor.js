'use strict';

const { logger } = require('./logger');
const { createNotification } = require('./notifications');
const log = logger('ChurnMonitor');

/**
 * Detección de inactividad / riesgo de churn.
 *
 * Un tenant activo cuyo bot dejó de usarse (sin conversaciones en N días) es
 * candidato a cancelar. Este job los detecta y avisa al superadmin para
 * reactivación proactiva (una llamada, un tip, un descuento). Reusa el patrón
 * de notificaciones al tenant de plataforma; dedup una vez por semana.
 *
 * Solo considera tenants que ALGUNA VEZ tuvieron actividad (para no marcar a
 * los recién creados que aún no arrancan).
 */
const INACTIVE_DAYS = parseInt(process.env.CHURN_INACTIVE_DAYS) || 7;

async function checkInactivity(db, redis) {
  let flagged = 0;
  try {
    const platform = await db.query(`SELECT id FROM tenants WHERE slug='agentcore-platform' LIMIT 1`);
    const platformId = platform.rows[0]?.id;
    if (!platformId) return { flagged: 0 };

    // Tenants activos/trial, con historial de conversaciones, pero sin ninguna
    // en los últimos N días.
    const rows = (await db.query(`
      SELECT t.id, t.name,
             MAX(c.created_at) AS last_activity,
             COUNT(c.id)       AS total_convs
        FROM tenants t
        JOIN conversations c ON c.tenant_id = t.id
       WHERE t.status IN ('active', 'trial')
         AND t.slug <> 'agentcore-platform'
       GROUP BY t.id, t.name
      HAVING MAX(c.created_at) < NOW() - ($1 || ' days')::interval
    `, [INACTIVE_DAYS])).rows;

    const week = getISOWeek(new Date());
    for (const r of rows) {
      const key = `churnalert:${r.id}:${week}`;
      try { if (redis && await redis.get(key)) continue; } catch { /* sin dedup si Redis falla */ }

      const days = Math.floor((Date.now() - new Date(r.last_activity).getTime()) / 86400000);
      await createNotification(db, {
        tenantId: platformId,
        type: 'churn_risk',
        title: `📉 Sin actividad: ${r.name}`,
        body: `Su bot no recibe conversaciones desde hace ${days} días. Riesgo de cancelación — considera contactarlo.`,
        link: '/pages/admin/operations.php',
      });
      try { if (redis) await redis.set(key, '1', 'EX', 8 * 24 * 3600); } catch { /* noop */ }
      flagged++;
    }
  } catch (e) { log.warn({ err: e.message }, 'chequeo de inactividad falló'); }

  if (flagged) log.info({ flagged }, 'tenants inactivos notificados');
  return { flagged };
}

/** Etiqueta año-semana para deduplicar una alerta por semana. */
function getISOWeek(d) {
  const date = new Date(Date.UTC(d.getFullYear(), d.getMonth(), d.getDate()));
  const dayNum = (date.getUTCDay() + 6) % 7;
  date.setUTCDate(date.getUTCDate() - dayNum + 3);
  const firstThursday = new Date(Date.UTC(date.getUTCFullYear(), 0, 4));
  const week = 1 + Math.round(((date - firstThursday) / 86400000 - 3 + ((firstThursday.getUTCDay() + 6) % 7)) / 7);
  return `${date.getUTCFullYear()}-W${week}`;
}

module.exports = { checkInactivity };
