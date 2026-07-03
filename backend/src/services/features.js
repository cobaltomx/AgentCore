'use strict';

/**
 * Features efectivas por tenant — fuente única de verdad.
 *
 * Efectivo = features del plan (plans.features) ∪ overrides del tenant
 * (tenants.settings.features, mapa { key: true|false }).
 *   true  → fuerza ON  (aunque el plan no lo incluya)
 *   false → fuerza OFF (aunque el plan lo incluya)
 *   ausente → hereda del plan
 *
 * Lo escribe el Super Admin (Fase C) vía PATCH /superadmin/tenants/:id/features.
 * Se consulta en runtime en cada canal (voz/WhatsApp/webchat) para gatear.
 */

// Catálogo canónico (coincide con $ALL_FEATURES en plans.php)
const FEATURE_CATALOG = {
  voice:    'Voz',
  webchat:  'Chat web',
  whatsapp: 'WhatsApp',
  rag:      'RAG (KB)',
  outbound: 'Outbound',
  insights: 'Insights',
  priority: 'Soporte prioritario',
};

/** Combina features de plan con overrides → array de keys efectivas. */
function computeEffective(planFeatures, overrides) {
  const set = new Set(Array.isArray(planFeatures) ? planFeatures : []);
  for (const [k, v] of Object.entries(overrides || {})) {
    if (v === true) set.add(k);
    else if (v === false) set.delete(k);
  }
  return [...set].filter((k) => FEATURE_CATALOG[k]);
}

/** Devuelve las features efectivas de un tenant (array). Tenant inexistente → []. */
async function tenantEffectiveFeatures(db, tenantId) {
  const r = await db.query(
    `SELECT COALESCE(p.features, '[]'::jsonb) AS plan_features, t.settings
     FROM tenants t LEFT JOIN plans p ON p.key = t.plan
     WHERE t.id = $1`,
    [tenantId]
  );
  if (!r.rows[0]) return [];
  const planFeatures = Array.isArray(r.rows[0].plan_features) ? r.rows[0].plan_features : [];
  const overrides = (r.rows[0].settings && r.rows[0].settings.features) || {};
  return computeEffective(planFeatures, overrides);
}

/** ¿El tenant tiene habilitada una feature concreta? (con tolerancia a fallo) */
async function tenantHasFeature(db, tenantId, feature) {
  try {
    const feats = await tenantEffectiveFeatures(db, tenantId);
    return feats.includes(feature);
  } catch (e) {
    // Ante un error de BD preferimos NO bloquear el canal (fail-open) y dejar
    // que los otros gates (status/minutos/is_ready) hagan su trabajo.
    return true;
  }
}

module.exports = { FEATURE_CATALOG, computeEffective, tenantEffectiveFeatures, tenantHasFeature };
