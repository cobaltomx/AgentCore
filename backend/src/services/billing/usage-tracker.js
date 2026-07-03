'use strict';

/**
 * Usage Tracker — Fase 6
 *
 * Se llama al final de cada llamada/conversación para:
 * 1. Incrementar minutos usados del tenant en DB
 * 2. Si supera los incluidos, reportar excedente a Stripe
 * 3. Crear alerta si está cerca del límite (80% / 100%)
 *
 * Se integra en VoiceAgent._closeConversation()
 */

const { StripeService } = require('./stripe-service');

class UsageTracker {
  constructor({ db }) {
    this.db            = db;
    this.stripeService = new StripeService({ db });
  }

  /**
   * Registrar uso de minutos tras una conversación
   *
   * @param {string} tenantId
   * @param {number} durationSecs - duración real de la llamada en segundos
   * @returns {Object} { minutesUsed, minutesMax, overageMinutes, nearLimit, overLimit }
   */
  async recordUsage(tenantId, durationSecs) {
    if (!durationSecs || durationSecs <= 0) return null;

    const minutes = Math.ceil(durationSecs / 60); // redondear hacia arriba

    // Incrementar contador en DB y obtener totales actualizados
    const result = await this.db.query(
      `UPDATE tenants
       SET minutes_used_mo = minutes_used_mo + $1
       WHERE id = $2
       RETURNING minutes_used_mo, max_minutes_mo, plan`,
      [minutes, tenantId]
    );

    const tenant = result.rows[0];
    if (!tenant) return null;

    const used     = tenant.minutes_used_mo;
    const max      = tenant.max_minutes_mo;
    const overage  = Math.max(0, used - max);
    const pct      = max > 0 ? (used / max) * 100 : 100;
    const nearLimit = pct >= 80 && pct < 100;
    const overLimit = used >= max;

    // Reportar excedente a Stripe si aplica
    if (overage > 0) {
      this.stripeService.reportUsage(tenantId, overage).catch(err => {
        console.error(`[UsageTracker] Error reportando a Stripe:`, err.message);
      });
    }

    // Actualizar registro de uso del período actual
    await this.db.query(
      `UPDATE usage_records
       SET minutes_used = $1, minutes_overage = $2,
           overage_amount_cents = $2 * (
             SELECT COALESCE((settings->>'overagePerMin')::int, 8)
             FROM tenants WHERE id = $3
           )
       WHERE tenant_id = $3
         AND period_start <= NOW() AND period_end >= NOW()`,
      [used, overage, tenantId]
    ).catch(() => {}); // no crítico

    return { minutesUsed: used, minutesMax: max, overageMinutes: overage, nearLimit, overLimit, pct };
  }

  /**
   * Resetear contador de minutos al inicio de cada período
   * Llamar desde un cron job el día 1 de cada mes
   */
  async resetMonthlyUsage(tenantId) {
    await this.db.query(
      'UPDATE tenants SET minutes_used_mo = 0 WHERE id = $1',
      [tenantId]
    );
  }

  /**
   * Reset masivo para todos los tenants activos
   * Cron: 0 0 1 * * (día 1 de cada mes)
   */
  async resetAllTenants() {
    const result = await this.db.query(
      `UPDATE tenants SET minutes_used_mo = 0 WHERE status = 'active'
       RETURNING id, slug`
    );
    console.log(`[UsageTracker] Reset de uso para ${result.rowCount} tenants`);
    return result.rows;
  }
}

module.exports = UsageTracker;
