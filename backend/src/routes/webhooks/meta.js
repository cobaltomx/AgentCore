'use strict';

/**
 * Meta WhatsApp Webhook — Fase 3
 *
 * GET  /webhooks/meta  → Verificación (Meta lo llama al configurar)
 * POST /webhooks/meta  → Mensajes entrantes
 *
 * CRÍTICO: Meta requiere respuesta 200 en < 5 segundos.
 * El procesamiento del mensaje va en setImmediate (background).
 */

const { parseWebhookPayload, verifyWebhookSignature } = require('../../services/whatsapp/message-parser');
const WhatsAppAgent = require('../../agents/whatsapp-agent');
const { createNotification } = require('../../services/notifications');
const { tenantHasFeature } = require('../../services/features');

async function metaWebhooks(app) {

  const waAgent = new WhatsAppAgent({ db: app.db, redis: app.redis });

  // GET — verificación de webhook
  app.get('/', async (request, reply) => {
    const mode      = request.query['hub.mode'];
    const token     = request.query['hub.verify_token'];
    const challenge = request.query['hub.challenge'];

    if (mode === 'subscribe' && token === process.env.META_VERIFY_TOKEN) {
      app.log.info('[Meta] Webhook verificado ✅');
      return reply.send(challenge);
    }

    app.log.warn('[Meta] Verificación fallida');
    return reply.code(403).send('Forbidden');
  });

  // POST — mensajes entrantes
  app.post('/', async (request, reply) => {

    // Verificar firma HMAC si está configurado
    if (process.env.META_APP_SECRET) {
      const signature = request.headers['x-hub-signature-256'];
      const rawBody   = JSON.stringify(request.body);
      if (!verifyWebhookSignature(rawBody, signature, process.env.META_APP_SECRET)) {
        app.log.warn('[Meta] Firma inválida');
        return reply.code(401).send('Unauthorized');
      }
    }

    // Responder 200 inmediatamente — Meta reintenta si no recibe respuesta
    reply.code(200).send({ status: 'ok' });

    // Procesar en background
    setImmediate(async () => {
      try {
        const body = request.body;
        if (body?.object !== 'whatsapp_business_account') return;

        const parsed = parseWebhookPayload(body);
        if (!parsed) return;

        app.log.info({
          from:    parsed.from,
          type:    parsed.rawType,
          preview: parsed.text?.substring(0, 60),
        }, '[Meta] Mensaje entrante');

        // Buscar tenant por Phone Number ID
        const tenantResult = await app.db.query(
          `SELECT t.id, t.is_ready FROM tenants t
           WHERE t.settings->'whatsapp'->>'phoneNumberId' = $1
             AND t.status = 'active'
           LIMIT 1`,
          [parsed.businessPhoneId]
        );

        let tenantId    = tenantResult.rows[0]?.id;
        let tenantReady = tenantResult.rows[0]?.is_ready;

        // Fallback de DESARROLLO: si el número entrante es el de plataforma
        // (.env) y no se resolvió tenant, usar el primer tenant activo.
        // ⚠️ PELIGROSO en multi-tenant (enruta al cliente equivocado), así que
        // SOLO se permite fuera de producción, o si se opta explícitamente con
        // META_ALLOW_SINGLE_TENANT_FALLBACK=on (instalación de un solo tenant).
        const allowFallback = process.env.NODE_ENV !== 'production'
          || process.env.META_ALLOW_SINGLE_TENANT_FALLBACK === 'on';
        if (!tenantId && allowFallback && process.env.META_PHONE_NUMBER_ID === parsed.businessPhoneId) {
          const fb = await app.db.query(
            "SELECT id, is_ready FROM tenants WHERE status='active' ORDER BY created_at ASC LIMIT 1"
          );
          tenantId    = fb.rows[0]?.id;
          tenantReady = fb.rows[0]?.is_ready;
          if (tenantId) app.log.warn({ tenantId, phoneId: parsed.businessPhoneId },
            '[Meta] Usando fallback de un-solo-tenant (NO usar en multi-tenant prod)');
        }

        if (!tenantId) {
          app.log.warn({ phoneId: parsed.businessPhoneId }, '[Meta] Tenant no encontrado');
          return;
        }

        // Gate: el bot debe estar aprobado en el simulador (is_ready)
        if (!tenantReady) {
          app.log.warn({ tenantId, from: parsed.from }, '[Meta] Mensaje ignorado — bot no aprobado (is_ready=false)');
          return;
        }

        // Gate: el canal WhatsApp debe estar habilitado para el tenant (Fase C)
        if (!(await tenantHasFeature(app.db, tenantId, 'whatsapp'))) {
          app.log.warn({ tenantId, from: parsed.from }, '[Meta] Mensaje ignorado — feature whatsapp deshabilitada');
          return;
        }

        // Notificación de mensaje WhatsApp entrante (non-fatal)
        createNotification(app.db, {
          tenantId,
          type:  'new_conversation',
          title: `WhatsApp de ${parsed.from}`,
          body:  parsed.text ? parsed.text.substring(0, 80) : 'Mensaje multimedia',
          link:  '/pages/conversations.php',
        });

        // Marcar como leído (non-fatal: si falla, igual procesamos el mensaje)
        const { createMetaClient } = require('../../services/whatsapp/meta-client');
        const td = await app.db.query('SELECT settings FROM tenants WHERE id=$1', [tenantId]);
        const mc = createMetaClient(td.rows[0]?.settings || {});
        if (mc) {
          try { await mc.markAsRead(parsed.messageId); }
          catch (e) { app.log.warn({ e: e.message }, '[Meta] markAsRead falló (non-fatal)'); }
        }

        // ── Reducción de no-shows: ¿es una confirmación de cita? ──────────
        // Se procesa ANTES del agente para que un "sí" suelto no confunda al LLM.
        try {
          const { handleConfirmationReply } = require('../../services/appointment-confirmation');
          const conf = await handleConfirmationReply(app.db, {
            tenantId,
            fromPhone: parsed.from,
            text:      parsed.text || '',
          });
          if (conf.handled) {
            // Responder al paciente (non-fatal: la cita ya se actualizó en BD)
            if (mc && conf.reply) {
              try { await mc.sendText(parsed.from, conf.reply); }
              catch (e) { app.log.warn({ e: e.message }, '[Meta] sendText confirmación falló (non-fatal)'); }
            }

            // Notificar al tenant si el paciente canceló (para llenar el hueco)
            if (conf.action === 'cancel') {
              createNotification(app.db, {
                tenantId,
                type:  'appointment_reminder',
                title: `Cita cancelada por ${parsed.from}`,
                body:  'Un paciente canceló su cita. El horario quedó libre.',
                link:  '/pages/appointments.php',
              });
            }
            app.log.info({ tenantId, action: conf.action }, '[Meta] Confirmación de cita procesada');
            return; // no pasar al agente IA
          }
        } catch (confErr) {
          app.log.warn({ confErr }, '[Meta] Error en confirmación de cita, continúa con agente');
        }

        // Procesar con el agente
        await waAgent.handleMessage(parsed, tenantId);

      } catch (err) {
        app.log.error({ err }, '[Meta] Error en background');
      }
    });
  });

  // Health check
  app.get('/status', async () => ({
    status:        'active',
    timestamp:     new Date().toISOString(),
    verifyToken:   process.env.META_VERIFY_TOKEN    ? '✅' : '❌ falta META_VERIFY_TOKEN',
    phoneNumberId: process.env.META_PHONE_NUMBER_ID ? '✅' : '❌ falta META_PHONE_NUMBER_ID',
    appSecret:     process.env.META_APP_SECRET      ? '✅' : '⚠️ sin verificación HMAC',
  }));
}

module.exports = metaWebhooks;
