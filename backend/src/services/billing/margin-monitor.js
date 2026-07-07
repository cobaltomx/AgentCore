'use strict';

const { logger } = require('../logger');
const { createNotification } = require('../notifications');
const { estimateAllTenants } = require('./cost-estimator');
const log = logger('MarginMonitor');

/**
 * Alerta de rentabilidad: avisa al superadmin cuando un tenant que PAGA está
 * costando más de lo que genera (margen negativo) este mes.
 *
 * El ingreso del plan es fijo por mes; el costo (LLM + voz) se acumula, así que
 * un margen negativo a mitad/fin de mes es señal real de un tenant no rentable
 * (uso desproporcionado a su plan). Reusa cost-estimator (costo real vs. ingreso)
 * y notifica al tenant de plataforma (agentcore-platform), deduplicando una vez
 * por tenant por mes.
 */
async function checkMargins(db, redis) {
  let alerted = 0;
  try {
    const platform = await db.query(`SELECT id FROM tenants WHERE slug='agentcore-platform' LIMIT 1`);
    const platformId = platform.rows[0]?.id;
    if (!platformId) return { alerted: 0 };

    const { rows } = await estimateAllTenants(db);
    const month = new Date().toISOString().slice(0, 7); // YYYY-MM

    for (const r of rows) {
      // Solo tenants que PAGAN (revenue>0) y están en pérdida.
      if (!(r.revenueCents > 0 && r.marginCents < 0)) continue;

      const key = `marginalert:${r.tenantId}:${month}`;
      try { if (redis && await redis.get(key)) continue; } catch { /* sin dedup si Redis falla */ }

      const lossMxn = Math.round(Math.abs(r.marginCents) / 100);
      const costMxn = Math.round((r.totalCostCents || 0) / 100);
      const revMxn  = Math.round(r.revenueCents / 100);
      await createNotification(db, {
        tenantId: platformId,
        type: 'margin_alert',
        title: `⚠️ Margen negativo: ${r.name}`,
        body: `Cuesta ~$${costMxn} y paga ~$${revMxn} este mes (pérdida ~$${lossMxn}, margen ${r.marginPct}%). Revisa su uso o plan.`,
        link: '/pages/admin/costs.php',
      });
      try { if (redis) await redis.set(key, '1', 'EX', 31 * 24 * 3600); } catch { /* noop */ }
      alerted++;
    }
  } catch (e) { log.warn({ err: e.message }, 'chequeo de márgenes falló'); }

  if (alerted) log.info({ alerted }, 'tenants con margen negativo notificados');
  return { alerted };
}

module.exports = { checkMargins };
