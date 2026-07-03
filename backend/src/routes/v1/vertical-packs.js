'use strict';

/**
 * Vertical Packs — /api/v1/vertical-packs
 *
 * GET  /preview        → preview del pack según la industria del tenant
 * POST /apply          → aplica el pack: setea el prompt del agente + crea FAQs en la KB
 */

const { getPack } = require('../../services/vertical-packs');
const IngestionPipeline = require('../../services/rag/ingestion');

async function verticalPacksRoutes(app) {

  // requireTenant no incluye el nombre del tenant → lo resolvemos aquí
  async function tenantIndustryAndName(req) {
    const s = req.tenant.settings || {};
    const industry = s.industry || s.businessProfile?.industry || 'general';
    let name = s.businessName || s.businessProfile?.businessName;
    if (!name) {
      const r = await app.db.query('SELECT name FROM tenants WHERE id=$1', [req.tenant.id]);
      name = r.rows[0]?.name || 'tu negocio';
    }
    return { industry, name };
  }

  // ── GET /preview ──────────────────────────────────────────────
  app.get('/preview', { onRequest: [app.requireTenant] }, async (req) => {
    const { industry, name } = await tenantIndustryAndName(req);
    const pack = getPack(industry, name);
    return { detected_industry: industry, ...pack };
  });

  // ── POST /apply ───────────────────────────────────────────────
  app.post('/apply', { onRequest: [app.requireAdmin] }, async (req, reply) => {
    const { industry, name } = await tenantIndustryAndName(req);
    const overrideIndustry = req.body?.industry || industry;
    const pack = getPack(overrideIndustry, name);

    const applyPrompt = req.body?.apply_prompt !== false; // por defecto sí
    const applyFaqs   = req.body?.apply_faqs   !== false; // por defecto sí

    let promptUpdated = false;
    let faqsCreated   = 0;

    // Agente activo del tenant (opcional: el pack también sirve para sembrar FAQs
    // antes de crear el agente)
    const agentRes = await app.db.query(
      `SELECT id, system_prompt FROM agents WHERE tenant_id=$1 AND is_active=true
       ORDER BY created_at ASC LIMIT 1`,
      [req.tenant.id]
    );
    const agent = agentRes.rows[0] || null;

    // 1. Setear el prompt del agente (solo si hay agente y su prompt está vacío)
    if (applyPrompt && agent) {
      const isEmpty = !agent.system_prompt || agent.system_prompt.trim().length < 20;
      if (isEmpty || req.body?.force === true) {
        await app.db.query(
          `UPDATE agents SET system_prompt=$1,
             config = COALESCE(config,'{}'::jsonb) || $2::jsonb,
             updated_at=NOW() WHERE id=$3`,
          [pack.prompt, JSON.stringify({ ...pack.config, industry: pack.key }), agent.id]
        );
        promptUpdated = true;
      }
    }

    // 2. Crear los FAQs en la base de conocimiento (no requiere agente)
    if (applyFaqs && pack.faqs.length) {
      const content = JSON.stringify(pack.faqs);
      const docRes = await app.db.query(
        `INSERT INTO kb_documents (tenant_id, agent_id, title, content, file_type, status)
         VALUES ($1,$2,$3,$4,'faq','pending') RETURNING id`,
        [req.tenant.id, agent?.id || null, `FAQs — Pack ${pack.label}`, content]
      );
      faqsCreated = pack.faqs.length;

      // Ingesta en background (RAG)
      const docId = docRes.rows[0].id;
      setImmediate(async () => {
        try { await new IngestionPipeline({ db: app.db }).ingestDocument(docId); }
        catch (err) { app.log.error({ err, docId }, '[VerticalPack] Error ingesting FAQs'); }
      });
    }

    return {
      ok: true,
      pack: pack.key,
      label: pack.label,
      prompt_updated: promptUpdated,
      faqs_created: faqsCreated,
    };
  });
}

module.exports = verticalPacksRoutes;
