'use strict';

/**
 * Twilio Outbound Answer Webhook — Fase 7
 *
 * Twilio llama aquí cuando el contacto contesta la llamada outbound.
 * Este webhook genera el TwiML para que el agente de voz tome el control.
 *
 * Flujo:
 * 1. CampaignWorker inicia llamada → Twilio marca "calling"
 * 2. Contacto contesta → Twilio POST /webhooks/twilio/outbound-answer
 * 3. Este handler carga el contexto del campaign (Redis) y genera saludo
 * 4. El resto del flujo es igual que una llamada inbound (VoiceAgent)
 */

const VoiceAgent = require('../../agents/voice-agent');
const CampaignManager = require('../../services/campaigns/campaign-manager');

async function outboundAnswerRoutes(app) {

  // Anti-spoofing: validar firma X-Twilio-Signature en todos los POST del plugin.
  const { twilioSignaturePreHandler } = require('../../services/twilio-signature');
  app.addHook('preHandler', twilioSignaturePreHandler(app));

  /**
   * POST /webhooks/twilio/outbound-answer
   * Twilio llama aquí cuando el contacto contesta
   */
  app.post('/outbound-answer', async (request, reply) => {
    const { CallSid, AnsweredBy, To } = request.body;
    const { campaign_id, contact_id } = request.query;

    app.log.info({ CallSid, AnsweredBy, campaign_id }, '[Outbound] Llamada contestada');

    // Si contestó un machine/voicemail, colgar
    if (AnsweredBy && AnsweredBy !== 'human') {
      app.log.info({ CallSid, AnsweredBy }, '[Outbound] Contestador detectado — colgando');

      const manager = new CampaignManager({ db: app.db });
      await manager.failContact(contact_id, 'Contestador automático', 240).catch(() => {});

      reply.header('Content-Type', 'text/xml');
      return reply.send(`<?xml version="1.0" encoding="UTF-8"?>
<Response>
  <Say language="es-MX" voice="Polly.Mia">
    Hola, le llamamos del negocio. Por favor llámenos cuando pueda.
  </Say>
  <Hangup/>
</Response>`);
    }

    // Cargar contexto de la campaña desde Redis
    const contextData = await app.redis.get(`outbound:call:${CallSid}`);
    if (!contextData) {
      app.log.error({ CallSid }, '[Outbound] Sin contexto en Redis');
      reply.header('Content-Type', 'text/xml');
      return reply.send(`<?xml version="1.0" encoding="UTF-8"?>
<Response><Hangup/></Response>`);
    }

    const ctx = JSON.parse(contextData);

    // Iniciar VoiceAgent con el script de la campaña como system prompt
    // Modificamos el agente temporalmente para usar el script de la campaña
    const voiceAgent = new VoiceAgent({ db: app.db, redis: app.redis });

    // Crear conversación en DB
    const convResult = await app.db.query(
      `INSERT INTO conversations
         (tenant_id, agent_id, contact_phone, channel, status)
       VALUES ($1,$2,$3,'voice','active') RETURNING id`,
      [ctx.tenantId, ctx.agentId, ctx.contactPhone]
    );
    const conversationId = convResult.rows[0].id;

    // Crear sesión con script de campaña como system_prompt override
    const sessionId = CallSid;
    await voiceAgent.sessions.create({
      sessionId,
      tenantId:       ctx.tenantId,
      agentId:        ctx.agentId,
      conversationId,
      contactPhone:   ctx.contactPhone,
      channel:        'voice',
      callSid:        CallSid,
    });

    // Sobrescribir el system prompt con el script de la campaña
    await voiceAgent.sessions.updateCollectedData(sessionId, {
      campaignId:    ctx.campaignId,
      contactId:     contact_id,
      contactName:   ctx.contactName,
      campaignScript: ctx.script, // VoiceAgent lo usa en _buildSystemPrompt
    });

    // Generar saludo personalizado de la campaña
    const { chat } = require('../../services/llm-router');
    const greetResult = await chat({
      systemPrompt: ctx.script,
      messages: [{
        role:    'user',
        content: `[INICIO_LLAMADA_OUTBOUND] El contacto se llama ${ctx.contactName || 'el cliente'}.`,
      }],
      taskType: 'greeting',
    });

    const greetingText = greetResult.content;

    // Guardar en sesión
    await voiceAgent.sessions.addMessage(sessionId, {
      role:    'assistant',
      content: greetingText,
    });

    // Sintetizar y servir audio
    const { synthesizeToFile } = require('../../services/tts-cartesia');
    let audioUrl;

    if (process.env.CARTESIA_API_KEY) {
      const path = require('path');
      const audioPath = await synthesizeToFile(greetingText, null, `outbound_${CallSid}`);
      audioUrl = `${process.env.APP_URL}/webhooks/twilio/audio/${path.basename(audioPath)}`;
    }

    const twimlBody = audioUrl
      ? `<Play>${audioUrl}</Play>`
      : `<Say language="es-MX" voice="Polly.Mia">${greetingText}</Say>`;

    reply.header('Content-Type', 'text/xml');
    return reply.send(`<?xml version="1.0" encoding="UTF-8"?>
<Response>
  ${twimlBody}
  <Gather input="speech"
          action="${process.env.APP_URL}/webhooks/twilio/gather"
          method="POST" speechTimeout="auto" language="es-MX">
    <Pause length="1"/>
  </Gather>
  <Redirect method="POST">${process.env.APP_URL}/webhooks/twilio/no-input?sid=${CallSid}</Redirect>
</Response>`);
  });
}

module.exports = outboundAnswerRoutes;
