'use strict';

/**
 * Campaign Worker — Fase 7
 *
 * Loop principal que:
 * 1. Busca campañas activas
 * 2. Toma contactos de la cola (con lock atómico)
 * 3. Hace la llamada o envía WhatsApp
 * 4. Registra el resultado
 * 5. Respeta rate limits (calls_per_hour)
 *
 * Se ejecuta como un setInterval en el proceso del backend.
 * En producción se movería a un worker process separado (Bull/BullMQ).
 */

const CampaignManager     = require('./campaign-manager');
const { createMetaClient } = require('../whatsapp/meta-client');
const { chat }            = require('../llm-router');
const { getTwilioClient, getTwilioFrom } = require('../twilio-client');

// Tracking de rate por campaña: { campaignId: [timestamps] }
const callTimestamps = new Map();

class CampaignWorker {
  constructor({ db, redis }) {
    this.db      = db;
    this.redis   = redis;
    this.manager = new CampaignManager({ db });
    this.running = false;
    this._interval = null;
  }

  /**
   * Iniciar el worker loop
   * Cada 30 segundos busca trabajo disponible
   */
  start(intervalMs = 30000) {
    if (this.running) return;
    this.running   = true;
    this._interval = setInterval(() => this._tick(), intervalMs);
    console.log('[CampaignWorker] Iniciado — tick cada', intervalMs / 1000, 's');
  }

  stop() {
    this.running = false;
    if (this._interval) clearInterval(this._interval);
    console.log('[CampaignWorker] Detenido');
  }

  /**
   * Tick principal: procesar una ronda de campañas activas
   */
  async _tick() {
    try {
      // Obtener campañas en ejecución
      const result = await this.db.query(
        `SELECT c.*, a.system_prompt, a.channel as agent_channel,
                a.phone_number, a.voice_id, a.llm_model,
                t.settings as tenant_settings
         FROM campaigns c
         JOIN agents a ON a.id = c.agent_id
         JOIN tenants t ON t.id = c.tenant_id
         WHERE c.status = 'running'
           AND t.status = 'active'
         ORDER BY c.created_at ASC
         LIMIT 10`
      );

      for (const campaign of result.rows) {
        await this._processCampaign(campaign).catch(err => {
          console.error(`[CampaignWorker] Error en campaña ${campaign.id}:`, err.message);
        });
      }

      // Procesar triggers automáticos cada 10 ticks (~5 min)
      const tickCount = parseInt(await this.redis.incr('worker:tick_count') || '0');
      if (tickCount % 10 === 0) {
        await this.manager.processTriggers().catch(err => {
          console.error('[CampaignWorker] Error procesando triggers:', err.message);
        });
      }

    } catch (err) {
      console.error('[CampaignWorker] Error en tick:', err.message);
    }
  }

  /**
   * Procesar una campaña: tomar contacto, ejecutar acción
   */
  async _processCampaign(campaign) {
    // Verificar rate limit
    if (!this._checkRateLimit(campaign.id, campaign.calls_per_hour)) return;

    // Tomar el siguiente contacto disponible
    const contact = await this.manager.claimNextContact(campaign.id);
    if (!contact) return;

    console.log(`[CampaignWorker] Procesando contacto ${contact.phone} en campaña "${campaign.name}"`);

    try {
      let result;

      // Seleccionar canal
      if (campaign.channel === 'voice' || campaign.channel === 'both') {
        result = await this._makeOutboundCall(campaign, contact);
      }

      if (campaign.channel === 'whatsapp' || (campaign.channel === 'both' && !result?.called)) {
        result = await this._sendWhatsAppMessage(campaign, contact);
      }

      // Registrar resultado exitoso
      if (result?.success) {
        await this.manager.completeContact(
          contact.id,
          result.outcome || 'contacted',
          result.outcomeData || {},
          result.conversationId || null
        );
      } else {
        await this.manager.failContact(
          contact.id,
          result?.error || 'Sin respuesta',
          60 // reintentar en 1 hora
        );
      }

    } catch (err) {
      console.error(`[CampaignWorker] Error contactando ${contact.phone}:`, err.message);
      await this.manager.failContact(contact.id, err.message);
    }
  }

  /**
   * Realizar llamada outbound con Twilio
   * Twilio llama al número y conecta con el agente de voz
   */
  async _makeOutboundCall(campaign, contact) {
    // Obtener settings del tenant para credenciales Twilio
    const tenantResult = await this.db.query(
      'SELECT settings FROM tenants WHERE id = $1',
      [campaign.tenant_id]
    );
    const tenantSettings = tenantResult.rows[0]?.settings ?? {};

    const client   = getTwilioClient(tenantSettings);
    const fromNum  = campaign.phone_number
                  || getTwilioFrom(tenantSettings)
                  || process.env.TWILIO_PHONE_NUMBER;

    if (!fromNum) throw new Error('Sin número Twilio configurado para este tenant');

    // URL del webhook que manejará la llamada cuando contesten
    const callbackUrl = `${process.env.APP_URL}/webhooks/twilio/outbound-answer?` +
      `campaign_id=${campaign.id}&contact_id=${contact.id}`;

    try {
      const call = await client.calls.create({
        to:          contact.phone,
        from:        fromNum,
        url:         callbackUrl,
        statusCallback: `${process.env.APP_URL}/webhooks/twilio/status`,
        statusCallbackMethod: 'POST',
        machineDetection: 'DetectMessageEnd', // detectar contestador
        timeout:     25, // segundos antes de colgar si no contestan
      });

      // Guardar referencia en Redis para cuando Twilio llame al webhook
      await this.redis.setex(
        `outbound:call:${call.sid}`,
        3600,
        JSON.stringify({
          campaignId:  campaign.id,
          contactId:   contact.id,
          tenantId:    campaign.tenant_id,
          agentId:     campaign.agent_id,
          script:      campaign.script,
          contactName: contact.name,
          contactPhone:contact.phone,
        })
      );

      console.log(`[CampaignWorker] Llamada iniciada ${call.sid} → ${contact.phone}`);
      this._trackRateLimit(campaign.id);

      return { success: true, called: true, callSid: call.sid, outcome: 'calling' };

    } catch (err) {
      // Número inválido o fuera de servicio
      if (err.code === 21211 || err.code === 21614) {
        return { success: false, error: 'Número inválido', outcome: 'failed' };
      }
      throw err;
    }
  }

  /**
   * Enviar mensaje de WhatsApp
   * Usa template aprobado por Meta (requerido para mensajes iniciados por el negocio)
   */
  async _sendWhatsAppMessage(campaign, contact) {
    const tenantSettings = campaign.tenant_settings || {};
    const metaClient     = createMetaClient(tenantSettings);

    if (!metaClient) {
      throw new Error('WhatsApp no configurado para este tenant');
    }

    // Si hay template de Meta configurado, usarlo (obligatorio para outbound)
    if (campaign.wa_template_name) {
      const result = await metaClient.sendTemplate(
        contact.phone,
        campaign.wa_template_name,
        campaign.wa_template_lang || 'es_MX',
        this._buildTemplateComponents(campaign, contact)
      );

      if (result.success) {
        // Guardar en Redis para tracking de respuesta
        await this.redis.setex(
          `outbound:wa:${contact.phone}`,
          86400, // 24h — ventana de conversación de WhatsApp
          JSON.stringify({
            campaignId:  campaign.id,
            contactId:   contact.id,
            tenantId:    campaign.tenant_id,
            agentId:     campaign.agent_id,
          })
        );

        return { success: true, outcome: 'contacted', outcomeData: { messageId: result.messageId } };
      }

      return { success: false, error: 'Error enviando template WhatsApp' };
    }

    // Fallback: mensaje de texto si no hay template (solo funciona dentro de ventana 24h)
    // Generar mensaje personalizado con LLM
    const message = await this._generateOutboundMessage(campaign, contact);
    const result  = await metaClient.sendText(contact.phone, message);

    return result.success
      ? { success: true, outcome: 'contacted' }
      : { success: false, error: 'Error enviando WhatsApp' };
  }

  /**
   * Generar mensaje de salida personalizado con LLM
   */
  async _generateOutboundMessage(campaign, contact) {
    const result = await chat({
      systemPrompt: `Eres un asistente que genera mensajes de seguimiento cortos y profesionales para WhatsApp. 
                     Máximo 2 oraciones. No uses markdown. Personaliza con el nombre del contacto.`,
      messages: [{
        role:    'user',
        content: `Genera un mensaje para: ${contact.name || 'Cliente'}. 
                  Objetivo: ${campaign.goal}. 
                  Script de referencia: ${campaign.script.substring(0, 200)}`,
      }],
      taskType: 'faq',
    });

    return result.content;
  }

  /**
   * Construir componentes del template de Meta con datos del contacto
   */
  _buildTemplateComponents(campaign, contact) {
    // Template básico con nombre del contacto como variable
    return [
      {
        type:       'body',
        parameters: [
          { type: 'text', text: contact.name || 'Cliente' },
        ],
      },
    ];
  }

  // ─── Rate limiting ───────────────────────────────────────────

  _checkRateLimit(campaignId, callsPerHour) {
    const now       = Date.now();
    const window    = 3600000; // 1 hora en ms
    const timestamps = callTimestamps.get(campaignId) || [];

    // Filtrar timestamps dentro de la última hora
    const recent = timestamps.filter(t => now - t < window);
    callTimestamps.set(campaignId, recent);

    return recent.length < callsPerHour;
  }

  _trackRateLimit(campaignId) {
    const timestamps = callTimestamps.get(campaignId) || [];
    timestamps.push(Date.now());
    callTimestamps.set(campaignId, timestamps);
  }
}

module.exports = CampaignWorker;
