'use strict';

const CampaignManager = require('../../services/campaigns/campaign-manager');
const { z } = require('zod');

const createSchema = z.object({
  name:               z.string().min(3).max(200),
  agent_id:           z.string().uuid(),
  channel:            z.enum(['voice','whatsapp','both']).default('voice'),
  trigger_type:       z.enum(['manual','auto']).default('manual'),
  script:             z.string().min(20),
  goal:               z.string().default('follow_up'),
  wa_template_name:   z.string().optional(),
  allowed_hours_start:z.number().int().min(0).max(23).default(9),
  allowed_hours_end:  z.number().int().min(1).max(24).default(19),
  allowed_days:       z.array(z.number()).default([1,2,3,4,5]),
  trigger_config:     z.record(z.any()).default({}),
  calls_per_hour:     z.number().int().min(1).max(60).default(10),
  max_attempts:       z.number().int().min(1).max(5).default(2),
  scheduled_at:       z.string().datetime().optional(),
  description:        z.string().optional(),
});

async function campaignsRoutes(app) {

  const manager = new CampaignManager({ db: app.db });

  // GET /api/v1/campaigns — listar campañas del tenant
  app.get('/', { onRequest: [app.requireTenant] }, async (req) => {
    const result = await app.db.query(
      `SELECT c.*, a.name as agent_name
       FROM campaigns c JOIN agents a ON a.id = c.agent_id
       WHERE c.tenant_id = $1
       ORDER BY c.created_at DESC`,
      [req.tenant.id]
    );
    return result.rows;
  });

  // GET /api/v1/campaigns/stats
  app.get('/stats', { onRequest: [app.requireTenant] }, async (req) => {
    return manager.getCampaignStats(req.tenant.id);
  });

  // GET /api/v1/campaigns/:id
  app.get('/:id', { onRequest: [app.requireTenant] }, async (req, reply) => {
    const result = await app.db.query(
      `SELECT c.*, a.name as agent_name
       FROM campaigns c JOIN agents a ON a.id = c.agent_id
       WHERE c.id=$1 AND c.tenant_id=$2`,
      [req.params.id, req.tenant.id]
    );
    if (!result.rows[0]) return reply.code(404).send({ error: 'Campaña no encontrada' });
    return result.rows[0];
  });

  // GET /api/v1/campaigns/:id/contacts — contactos de la campaña
  app.get('/:id/contacts', { onRequest: [app.requireTenant] }, async (req) => {
    const limit  = parseInt(req.query.limit)  || 50;
    const offset = parseInt(req.query.offset) || 0;
    const status = req.query.status;

    let query = `SELECT * FROM campaign_contacts WHERE campaign_id=$1`;
    const params = [req.params.id];
    let idx = 2;

    if (status) { query += ` AND status=$${idx++}`; params.push(status); }
    query += ` ORDER BY created_at ASC LIMIT $${idx++} OFFSET $${idx}`;
    params.push(limit, offset);

    const result = await app.db.query(query, params);
    return { data: result.rows, total: result.rowCount };
  });

  // POST /api/v1/campaigns — crear campaña
  app.post('/', { onRequest: [app.requireTenant] }, async (req, reply) => {
    const parsed = createSchema.safeParse(req.body);
    if (!parsed.success) {
      return reply.code(400).send({ error: 'Datos inválidos', details: parsed.error.flatten() });
    }

    const d = parsed.data;

    // Verificar que el agente pertenece al tenant
    const agentCheck = await app.db.query(
      'SELECT id FROM agents WHERE id=$1 AND tenant_id=$2',
      [d.agent_id, req.tenant.id]
    );
    if (!agentCheck.rows[0]) return reply.code(400).send({ error: 'Agente no válido' });

    const campaign = await manager.createCampaign({ tenantId: req.tenant.id, ...d });
    return reply.code(201).send(campaign);
  });

  // POST /api/v1/campaigns/:id/contacts/csv — importar contactos desde CSV
  app.post('/:id/contacts/csv', { onRequest: [app.requireTenant] }, async (req, reply) => {
    // Verificar pertenencia
    const check = await app.db.query(
      'SELECT id FROM campaigns WHERE id=$1 AND tenant_id=$2',
      [req.params.id, req.tenant.id]
    );
    if (!check.rows[0]) return reply.code(404).send({ error: 'Campaña no encontrada' });

    const { csv } = req.body;
    if (!csv || typeof csv !== 'string') {
      return reply.code(400).send({ error: 'Se requiere el campo csv con el contenido' });
    }
    if (csv.length > 5_000_000) {
      return reply.code(413).send({ error: 'CSV demasiado grande (máximo 5 MB)' });
    }

    const result = await manager.importContactsFromCsv(req.params.id, csv);
    return result;
  });

  // POST /api/v1/campaigns/:id/contacts/from-leads — importar desde leads
  app.post('/:id/contacts/from-leads', { onRequest: [app.requireTenant] }, async (req, reply) => {
    const check = await app.db.query(
      'SELECT id FROM campaigns WHERE id=$1 AND tenant_id=$2',
      [req.params.id, req.tenant.id]
    );
    if (!check.rows[0]) return reply.code(404).send({ error: 'Campaña no encontrada' });

    const { lead_status, days_without_contact } = req.body;
    const result = await manager.importFromLeads(req.params.id, {
      leadStatus:         lead_status,
      daysWithoutContact: days_without_contact,
    });
    return result;
  });

  // POST /api/v1/campaigns/:id/start
  app.post('/:id/start', { onRequest: [app.requireTenant] }, async (req, reply) => {
    const check = await app.db.query(
      'SELECT id FROM campaigns WHERE id=$1 AND tenant_id=$2',
      [req.params.id, req.tenant.id]
    );
    if (!check.rows[0]) return reply.code(404).send({ error: 'Campaña no encontrada' });

    const result = await manager.startCampaign(req.params.id);
    return result;
  });

  // POST /api/v1/campaigns/:id/pause
  app.post('/:id/pause', { onRequest: [app.requireTenant] }, async (req, reply) => {
    const check = await app.db.query(
      'SELECT id FROM campaigns WHERE id=$1 AND tenant_id=$2',
      [req.params.id, req.tenant.id]
    );
    if (!check.rows[0]) return reply.code(404).send({ error: 'Campaña no encontrada' });

    return manager.pauseCampaign(req.params.id);
  });

  // POST /api/v1/campaigns/:id/cancel
  app.post('/:id/cancel', { onRequest: [app.requireTenant] }, async (req, reply) => {
    const check = await app.db.query(
      'SELECT id FROM campaigns WHERE id=$1 AND tenant_id=$2',
      [req.params.id, req.tenant.id]
    );
    if (!check.rows[0]) return reply.code(404).send({ error: 'Campaña no encontrada' });

    return manager.cancelCampaign(req.params.id);
  });
}

module.exports = campaignsRoutes;
