'use strict';

const { z }    = require('zod');
const { chat } = require('../../services/llm-router');
const { synthesize } = require('../../services/tts-cartesia');

const agentSchema = z.object({
  name: z.string().min(3).max(120),
  voice_id: z.string().optional(),
  llm_model: z.enum([
    'claude-haiku-4-5-20251001', 'claude-sonnet-4-6',
    'gpt-4o-mini', 'gpt-4o',
    'claude-sonnet-4-20250514', // legacy: aún aceptado para no romper agentes viejos
  ]).default('claude-haiku-4-5-20251001'),
  system_prompt: z.string().min(10).optional(),
  language: z.string().default('es-MX'),
  channel: z.enum(['voice', 'whatsapp', 'webchat', 'sms']).default('voice'),
  phone_number: z.string().optional(),
  whatsapp_number: z.string().optional(),
  is_active: z.boolean().optional(),
  config: z.record(z.any()).default({}),
});

async function agentsRoutes(app) {

  // GET /api/v1/agents — listar agentes del tenant
  app.get('/', { onRequest: [app.requireTenant] }, async (request) => {
    const result = await app.db.query(
      'SELECT * FROM agents WHERE tenant_id = $1 ORDER BY created_at DESC',
      [request.tenant.id]
    );
    return result.rows;
  });

  // GET /api/v1/agents/:id
  app.get('/:id', { onRequest: [app.requireTenant] }, async (request, reply) => {
    const result = await app.db.query(
      'SELECT * FROM agents WHERE id = $1 AND tenant_id = $2',
      [request.params.id, request.tenant.id]
    );
    if (!result.rows[0]) return reply.code(404).send({ error: 'Agente no encontrado' });
    return result.rows[0];
  });

  // POST /api/v1/agents — crear agente
  app.post('/', { onRequest: [app.requireTenant] }, async (request, reply) => {
    const parsed = agentSchema.safeParse(request.body);
    if (!parsed.success) {
      return reply.code(400).send({ error: 'Datos inválidos', details: parsed.error.flatten() });
    }

    // Validar límite de agentes del plan
    const countResult = await app.db.query(
      'SELECT COUNT(*) FROM agents WHERE tenant_id = $1 AND is_active = true',
      [request.tenant.id]
    );
    const currentCount = parseInt(countResult.rows[0].count);

    const tenantResult = await app.db.query(
      'SELECT max_agents FROM tenants WHERE id = $1',
      [request.tenant.id]
    );
    const maxAgents = tenantResult.rows[0]?.max_agents || 1;

    if (currentCount >= maxAgents) {
      return reply.code(403).send({
        error: `Tu plan permite máximo ${maxAgents} agente(s). Actualiza tu plan para agregar más.`,
      });
    }

    const d = parsed.data;
    const result = await app.db.query(
      `INSERT INTO agents (tenant_id, name, voice_id, llm_model, system_prompt, language, channel, phone_number, whatsapp_number, config)
       VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10) RETURNING *`,
      [request.tenant.id, d.name, d.voice_id, d.llm_model, d.system_prompt, d.language, d.channel, d.phone_number, d.whatsapp_number, JSON.stringify(d.config)]
    );

    return reply.code(201).send(result.rows[0]);
  });

  // PUT /api/v1/agents/:id — actualizar agente
  app.put('/:id', { onRequest: [app.requireTenant] }, async (request, reply) => {
    const parsed = agentSchema.partial().safeParse(request.body);
    if (!parsed.success) {
      return reply.code(400).send({ error: 'Datos inválidos', details: parsed.error.flatten() });
    }

    // Verificar que el agente pertenece al tenant
    const existing = await app.db.query(
      'SELECT id FROM agents WHERE id = $1 AND tenant_id = $2',
      [request.params.id, request.tenant.id]
    );
    if (!existing.rows[0]) return reply.code(404).send({ error: 'Agente no encontrado' });

    const d = parsed.data;
    const fields = [];
    const values = [];
    let idx = 1;

    for (const [key, val] of Object.entries(d)) {
      fields.push(`${key} = $${idx}`);
      values.push(typeof val === 'object' ? JSON.stringify(val) : val);
      idx++;
    }

    if (!fields.length) return reply.code(400).send({ error: 'No hay campos para actualizar' });

    values.push(request.params.id);
    const result = await app.db.query(
      `UPDATE agents SET ${fields.join(', ')} WHERE id = $${idx} RETURNING *`,
      values
    );

    return result.rows[0];
  });

  // DELETE /api/v1/agents/:id — desactivar agente (soft delete)
  app.delete('/:id', { onRequest: [app.requireTenant] }, async (request, reply) => {
    const result = await app.db.query(
      'UPDATE agents SET is_active = false WHERE id = $1 AND tenant_id = $2 RETURNING id',
      [request.params.id, request.tenant.id]
    );
    if (!result.rows[0]) return reply.code(404).send({ error: 'Agente no encontrado' });
    return { message: 'Agente desactivado', id: result.rows[0].id };
  });

  // POST /api/v1/agents/:id/simulate — probar respuesta del agente sin llamada real
  app.post('/:id/simulate', { onRequest: [app.requireTenant] }, async (request, reply) => {
    const { message, history = [] } = request.body || {};
    if (!message) return reply.code(400).send({ error: 'message requerido' });

    const agentResult = await app.db.query(
      'SELECT * FROM agents WHERE id = $1 AND tenant_id = $2',
      [request.params.id, request.tenant.id]
    );
    const agent = agentResult.rows[0];
    if (!agent) return reply.code(404).send({ error: 'Agente no encontrado' });

    const cfg = agent.config || {};

    // Build a simplified system prompt from config
    let systemPrompt = agent.system_prompt || '';

    if (cfg.businessName || cfg.specialties?.length || cfg.faqs?.length) {
      const lines = [];
      if (cfg.businessName) lines.push(`Eres un asistente virtual de ${cfg.businessName}.`);
      if (cfg.industry)     lines.push(`Industria: ${cfg.industry}.`);
      if (cfg.tone)         lines.push(`Tono: ${cfg.tone}.`);
      if (cfg.objective)    lines.push(`Objetivo principal: ${cfg.objective}.`);
      if (cfg.greeting)     lines.push(`Saludo inicial: "${cfg.greeting}"`);
      if (cfg.specialties?.length) {
        lines.push(`\nServicios/especialidades:\n${cfg.specialties.map(s => `- ${s}`).join('\n')}`);
      }
      if (cfg.faqs?.length) {
        lines.push(`\nPreguntas frecuentes:\n${cfg.faqs.map(f => `P: ${f.question}\nR: ${f.answer}`).join('\n\n')}`);
      }
      if (cfg.extraInstructions) lines.push(`\nInstrucciones adicionales:\n${cfg.extraInstructions}`);
      systemPrompt = lines.join('\n');
    }

    systemPrompt += '\n\nEsto es una simulación de chat (no es una llamada de voz). Responde de forma natural. Puedes usar párrafos cortos.';

    const messages = [
      ...history.map(h => ({ role: h.role, content: h.content })),
      { role: 'user', content: message },
    ];

    const result = await chat({ systemPrompt, messages, tools: [] });
    return { response: result.content, tokensUsed: result.tokensUsed, model: result.model };
  });

  // POST /api/v1/agents/voice-preview — sintetizar muestra de voz con Cartesia
  app.post('/voice-preview', { onRequest: [app.requireTenant] }, async (request, reply) => {
    const { voice_id, text } = request.body || {};
    if (!voice_id) return reply.code(400).send({ error: 'voice_id requerido' });

    const sampleText = text || 'Hola, soy tu asistente virtual. ¿En qué puedo ayudarte hoy?';

    try {
      const audioBuffer = await synthesize(sampleText, voice_id);
      reply.header('Content-Type', 'audio/mpeg');
      reply.header('Content-Length', audioBuffer.length);
      return reply.send(audioBuffer);
    } catch (err) {
      app.log.error({ err }, '[VoicePreview] Error sintetizando');
      return reply.code(500).send({ error: 'Error al sintetizar audio' });
    }
  });
}

module.exports = agentsRoutes;
