'use strict';

const { logger } = require('../logger');
const { createNotification } = require('../notifications');
const log = logger('TrialMonitor');

/**
 * Monitor de periodos de prueba (conversión trial→pago).
 *
 * Stripe ya suspende al tenant cuando cancela la suscripción tras el trial
 * (webhook `customer.subscription.deleted` → status='suspended'). Lo que
 * FALTABA era la parte de CONVERSIÓN:
 *   1. Recordatorio proactivo ~3 días antes de que termine la prueba
 *      ("agrega tu tarjeta") — una sola vez por tenant (dedup en Redis).
 *   2. Red de seguridad: si un tenant quedó en status='trial' con la prueba
 *      ya vencida y SIN suscripción activa, se suspende (cubre tenants que
 *      nunca pasaron por el webhook de Stripe, p. ej. altas manuales).
 *
 * Corre a diario desde app.js.
 */
async function checkTrials(db, redis) {
  let reminded = 0, suspended = 0;

  // 1) Recordatorio a ~3 días del fin de la prueba.
  try {
    const soon = await db.query(`
      SELECT s.tenant_id, s.trial_end
        FROM subscriptions s
        JOIN tenants t ON t.id = s.tenant_id
       WHERE s.trial_end IS NOT NULL
         AND s.trial_end > NOW()
         AND s.trial_end <= NOW() + INTERVAL '3 days'
         AND t.status IN ('trial', 'active')
    `);
    for (const row of soon.rows) {
      const key = `trialreminded:${row.tenant_id}`;
      try { if (redis && await redis.get(key)) continue; } catch { /* sin dedup si Redis falla */ }
      const days = Math.max(1, Math.ceil((new Date(row.trial_end).getTime() - Date.now()) / 86400000));
      await createNotification(db, {
        tenantId: row.tenant_id,
        type: 'trial_ending',
        title: `Tu prueba gratuita termina en ${days} día${days === 1 ? '' : 's'}`,
        body: 'Agrega tu método de pago para que tu asistente siga atendiendo sin interrupción.',
        link: '/pages/billing.php',
      });
      try { if (redis) await redis.set(key, '1', 'EX', 5 * 24 * 3600); } catch { /* noop */ }
      reminded++;
    }
  } catch (e) { log.warn({ err: e.message }, 'recordatorio de trial falló'); }

  // 2) Red de seguridad: prueba vencida sin suscripción activa → suspender.
  try {
    const r = await db.query(`
      UPDATE tenants SET status='suspended', updated_at=NOW()
       WHERE status='trial'
         AND EXISTS (
           SELECT 1 FROM subscriptions s
            WHERE s.tenant_id = tenants.id
              AND s.trial_end < NOW()
              AND s.status NOT IN ('active', 'paid'))
         AND NOT EXISTS (
           SELECT 1 FROM subscriptions s2
            WHERE s2.tenant_id = tenants.id
              AND s2.status IN ('active', 'paid'))
    `);
    suspended = r.rowCount || 0;
  } catch (e) { log.warn({ err: e.message }, 'suspensión de trial vencido falló'); }

  if (reminded || suspended) log.info({ reminded, suspended }, 'trials revisados');
  return { reminded, suspended };
}

module.exports = { checkTrials };
