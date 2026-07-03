'use strict';

/**
 * Estimador de costo de infraestructura por tenant (enfoque HÍBRIDO v1).
 *
 * Calcula el costo a partir de NUESTROS propios datos de uso:
 *   - minutos de voz  = SUM(conversations.duration_secs)/60  (canal voice)
 *   - tokens LLM      = SUM(messages.tokens_used)
 * multiplicados por las tarifas configurables de `provider_rates`.
 *
 * Costo por minuto de voz ≈ twilio_voice + deepgram_stt + tts (cartesia/deepgram).
 * Costo LLM ≈ (tokens/1000) × tarifa mezclada (promedio in/out).
 *
 * TODO (fase real): reemplazar/ajustar con costos reales de las APIs de cada
 * proveedor cuando estén integradas.
 */

async function loadRates(db) {
  const r = await db.query('SELECT provider, rate_cents FROM provider_rates WHERE is_active = true');
  const rates = {};
  for (const row of r.rows) rates[row.provider] = Number(row.rate_cents);
  return rates;
}

function monthBounds(date = new Date()) {
  const since = new Date(Date.UTC(date.getUTCFullYear(), date.getUTCMonth(), 1));
  const until = new Date(Date.UTC(date.getUTCFullYear(), date.getUTCMonth() + 1, 1));
  return { since: since.toISOString(), until: until.toISOString() };
}

/** Costo estimado de UN tenant en un periodo. Devuelve centavos MXN. */
async function estimateTenantCost(db, tenantId, rates, since, until) {
  const v = await db.query(
    `SELECT COALESCE(SUM(duration_secs),0)::bigint AS secs, COUNT(*)::int AS calls
     FROM conversations
     WHERE tenant_id = $1 AND channel = 'voice' AND created_at >= $2 AND created_at < $3`,
    [tenantId, since, until]
  );
  const t = await db.query(
    `SELECT COALESCE(SUM(m.tokens_used),0)::bigint AS tokens
     FROM messages m JOIN conversations c ON c.id = m.conversation_id
     WHERE c.tenant_id = $1 AND m.created_at >= $2 AND m.created_at < $3`,
    [tenantId, since, until]
  );

  const voiceMin = Number(v.rows[0].secs) / 60;
  const calls    = v.rows[0].calls;
  const tokens   = Number(t.rows[0].tokens);

  const tts        = rates.cartesia_tts ?? rates.deepgram_tts ?? 0;
  const perMinVoice = (rates.twilio_voice ?? 0) + (rates.deepgram_stt ?? 0) + tts;
  const llmBlend    = ((rates.llm_input ?? 0) + (rates.llm_output ?? 0)) / 2;

  const costVoice = voiceMin * perMinVoice;
  const costLlm   = (tokens / 1000) * llmBlend;

  return {
    voiceMin: Math.round(voiceMin * 10) / 10,
    calls, tokens,
    costVoiceCents: Math.round(costVoice),
    costLlmCents:   Math.round(costLlm),
    totalCostCents: Math.round(costVoice + costLlm),
  };
}

/**
 * Costo + ingreso + margen de TODOS los tenants en el mes actual.
 * Ingreso = precio mensual del plan (plans.monthly_cents) + excedente facturado.
 * (Tenants en trial → ingreso 0.)
 */
async function estimateAllTenants(db, date = new Date()) {
  const { since, until } = monthBounds(date);
  const rates = await loadRates(db);

  const tenants = (await db.query(
    `SELECT t.id, t.name, t.plan, t.status,
            COALESCE(p.monthly_cents,0) AS plan_cents
     FROM tenants t LEFT JOIN plans p ON p.key = t.plan
     ORDER BY t.created_at ASC`
  )).rows;

  const rows = [];
  let totals = { costCents: 0, revenueCents: 0, marginCents: 0 };

  for (const tn of tenants) {
    const c = await estimateTenantCost(db, tn.id, rates, since, until);
    // Excedente facturado este mes (si lo hay)
    const ov = (await db.query(
      `SELECT COALESCE(SUM(overage_amount_cents),0)::int AS cents
       FROM usage_records WHERE tenant_id = $1 AND period_start >= $2`,
      [tn.id, since]
    )).rows[0].cents;

    const revenueCents = (tn.status === 'active' ? Number(tn.plan_cents) : 0) + Number(ov);
    const marginCents  = revenueCents - c.totalCostCents;

    rows.push({
      tenantId: tn.id, name: tn.name, plan: tn.plan, status: tn.status,
      ...c,
      revenueCents,
      marginCents,
      marginPct: revenueCents > 0 ? Math.round((marginCents / revenueCents) * 100) : null,
    });
    totals.costCents    += c.totalCostCents;
    totals.revenueCents += revenueCents;
    totals.marginCents  += marginCents;
  }

  return { period: { since, until }, rates, rows, totals };
}

module.exports = { estimateAllTenants, estimateTenantCost, loadRates, monthBounds };
