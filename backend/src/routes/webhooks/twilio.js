'use strict';

/**
 * Twilio Voice Webhook — Fase 1
 * 
 * Flujo de una llamada entrante:
 * 1. Twilio recibe llamada → POST /webhooks/twilio/voice
 * 2. Respondemos TwiML: reproducimos saludo + <Gather> para capturar voz
 * 3. Usuario habla → Twilio transcribe (SpeechResult) → POST /webhooks/twilio/gather
 * 4. Opcionalmente re-transcribimos con Deepgram para mejor calidad
 * 5. Pasamos transcript al VoiceAgent → LLM procesa → Cartesia sintetiza
 * 6. Respondemos TwiML con audio + nuevo <Gather>
 * 7. Loop hasta que la conversación termine
 */

const VoiceAgent = require('../../agents/voice-agent');
const { transcribeUrl } = require('../../services/stt-deepgram');
const { synthesizeToFile } = require('../../services/tts-cartesia');
const { createNotification } = require('../../services/notifications');
const { tenantHasFeature } = require('../../services/features');
const path = require('path');
const fs = require('fs').promises;

async function twilioRoutes(app) {

  const voiceAgent = new VoiceAgent({ db: app.db, redis: app.redis });

  // Ruta para servir audios temporales generados por Cartesia
  // Debe registrarse ANTES en el router raíz — aquí como conveniencia
  app.get('/audio/:filename', async (request, reply) => {
    const filename = request.params.filename;
    if (!/^[\w\-]+\.mp3$/.test(filename)) {
      return reply.code(400).send({ error: 'Nombre inválido' });
    }
    const filepath = path.join('/tmp', filename);
    try {
      const buffer = await fs.readFile(filepath);
      reply.header('Content-Type', 'audio/mpeg');
      return reply.send(buffer);
    } catch {
      return reply.code(404).send({ error: 'Audio no encontrado' });
    }
  });

  /**
   * POST /webhooks/twilio/voice
   * Punto de entrada: llamada nueva entrante
   */
  app.post('/voice', async (request, reply) => {
    const { CallSid, From, To } = request.body;
    app.log.info({ CallSid, From, To }, '[Twilio] Llamada entrante');

    try {
      // Buscar agente configurado para este número
      const agentResult = await app.db.query(
        `SELECT a.id, a.tenant_id, a.name, a.config,
                t.name as tenant_name, t.settings as tenant_settings
         FROM agents a
         JOIN tenants t ON t.id = a.tenant_id
         WHERE a.phone_number = $1 AND a.is_active = true AND a.channel = 'voice'
         LIMIT 1`,
        [To]
      );

      if (!agentResult.rows[0]) {
        app.log.warn({ To }, 'Sin agente para este número');
        return xmlReply(reply, `
          <Say language="es-MX" voice="Polly.Mia-Neural">
            Este número no está disponible en este momento. Intente más tarde.
          </Say><Hangup/>`);
      }

      const { id: agentId, tenant_id: tenantId,
              config: agentConfig, tenant_name: tenantName } = agentResult.rows[0];

      // Verificar límite de minutos del plan + estado ready
      const tenantResult = await app.db.query(
        'SELECT max_minutes_mo, minutes_used_mo, status, is_ready FROM tenants WHERE id = $1',
        [tenantId]
      );
      const tenant = tenantResult.rows[0];

      if (!tenant || tenant.status !== 'active') {
        return xmlReply(reply, `<Say language="es-MX" voice="Polly.Mia-Neural">Servicio no disponible.</Say><Hangup/>`);
      }

      // Gate: el canal de voz debe estar habilitado para el tenant (Fase C)
      if (!(await tenantHasFeature(app.db, tenantId, 'voice'))) {
        app.log.warn({ tenantId, To }, '[Twilio] Llamada rechazada — feature voice deshabilitada');
        return xmlReply(reply, `<Say language="es-MX" voice="Polly.Mia-Neural">Este servicio no está disponible.</Say><Hangup/>`);
      }

      // Gate: el bot debe estar aprobado en el simulador (is_ready)
      if (!tenant.is_ready) {
        app.log.warn({ tenantId, To }, '[Twilio] Llamada rechazada — bot no aprobado (is_ready=false)');
        return xmlReply(reply, `
          <Say language="es-MX" voice="Polly.Mia-Neural">
            Este servicio aún no está activo. Por favor intente más tarde.
          </Say><Hangup/>`);
      }

      if (tenant.minutes_used_mo >= tenant.max_minutes_mo) {
        return xmlReply(reply, `
          <Say language="es-MX" voice="Polly.Mia-Neural">
            Hemos alcanzado el límite de atención del mes. 
            Por favor contacte directamente al negocio.
          </Say><Hangup/>`);
      }

      // Notificación de llamada entrante (non-fatal, background)
      createNotification(app.db, {
        tenantId,
        type:  'new_conversation',
        title: `Llamada entrante de ${From}`,
        body:  `Agente: ${agentResult.rows[0].name}`,
        link:  '/pages/conversations.php',
      });

      // Iniciar sesión en background SOLO en el flujo clásico <Gather>.
      // En streaming, el handler del Media Stream crea la sesión (evita doble
      // startCall, doble conversación y un synth de saludo que falla con 402).
      if (process.env.VOICE_STREAMING === 'off') {
        voiceAgent.startCall({ agentId, tenantId, contactPhone: From, callSid: CallSid })
          .catch(err => app.log.error({ err, CallSid }, '[Twilio] Error en startCall background'));
      }

      // Saludo dinámico: prioridad → agent.config.greeting → businessName → tenantName
      const cfg      = agentConfig || {};
      let greeting = cfg.greeting
        || (cfg.businessName ? `${cfg.businessName}, ¿en qué le puedo ayudar?` : null)
        || `${tenantName}, buenas tardes. ¿En qué le puedo ayudar?`;

      // Cliente recurrente (Caller ID conocido) → saludo personalizado por nombre.
      // SOLO si el negocio lo habilitó: un mismo número puede ser de varias
      // personas, así que por defecto no saludamos por nombre.
      if (agentResult.rows[0]?.tenant_settings?.recognizeReturningCallers === true) {
        try {
          const { getCustomerContext } = require('../../services/contacts');
          const ctx = await getCustomerContext(app.db, tenantId, From);
          if (ctx?.firstName) greeting = `¡Hola de nuevo, ${ctx.firstName}! ${greeting}`;
        } catch (e) { /* saludo genérico si falla */ }
      }

      // Hints de especialidades del agente para mejorar reconocimiento de voz
      const hints = (cfg.specialties || [])
        .map(s => s.name).filter(Boolean).join(', ')
        || 'cita, agendar, información, precio, cancelar';

      // ── REWORK: Media Streams (voz bidireccional en tiempo real) ──
      // Una sola voz (Cartesia), barge-in y baja latencia. Fallback al flujo
      // <Gather> clásico si VOICE_STREAMING=off.
      if (process.env.VOICE_STREAMING !== 'off') {
        const wssUrl = (process.env.APP_URL || '').replace(/^https?:/, 'wss:') + '/webhooks/twilio/media-stream';
        return xmlReply(reply, `
          <Connect>
            <Stream url="${wssUrl}">
              <Parameter name="agentId" value="${agentId}"/>
              <Parameter name="tenantId" value="${tenantId}"/>
              <Parameter name="callSid" value="${CallSid}"/>
              <Parameter name="from" value="${xmlEscape(From || '')}"/>
              <Parameter name="greeting" value="${xmlEscape(greeting)}"/>
            </Stream>
          </Connect>`);
      }

      // Flujo clásico (fallback): saludo Polly + Gather + polling
      return xmlReply(reply, `
        <Say language="es-MX" voice="Polly.Mia-Neural">${greeting}</Say>
        <Gather input="speech"
                action="${process.env.APP_URL}/webhooks/twilio/gather"
                method="POST" speechTimeout="auto" language="es-MX"
                hints="${hints}">
          <Pause length="2"/>
        </Gather>
        <Redirect method="POST">${process.env.APP_URL}/webhooks/twilio/no-input?sid=${CallSid}</Redirect>
      `);

    } catch (err) {
      app.log.error({ err, CallSid }, '[Twilio] Error en /voice');
      return xmlReply(reply, `
        <Say language="es-MX" voice="Polly.Mia-Neural">
          Ocurrió un error. Por favor llame de nuevo en unos momentos.
        </Say><Hangup/>`);
    }
  });

  /**
   * POST /webhooks/twilio/gather
   * Twilio envía lo que dijo el usuario (SpeechResult)
   */
  app.post('/gather', async (request, reply) => {
    const { CallSid, SpeechResult, RecordingUrl, Confidence } = request.body;
    app.log.info({ CallSid, transcript: SpeechResult?.substring(0, 60) }, '[Twilio] Gather');

    try {
      // Intentar mejorar transcript con Deepgram si hay grabación
      let transcript = SpeechResult || '';

      if (RecordingUrl && process.env.DEEPGRAM_API_KEY && transcript) {
        try {
          const dg = await transcribeUrl(RecordingUrl);
          if (dg.transcript && dg.confidence > parseFloat(Confidence || 0)) {
            transcript = dg.transcript;
          }
        } catch (e) {
          app.log.warn('[Twilio] Deepgram fallback — usando transcript Twilio');
        }
      }

      if (!transcript.trim()) {
        return xmlReply(reply, `
          <Say language="es-MX" voice="Polly.Mia-Neural">
            Disculpa, no te entendí. ¿Podrías repetir?
          </Say>
          <Gather input="speech"
                  action="${process.env.APP_URL}/webhooks/twilio/gather"
                  method="POST" speechTimeout="auto" language="es-MX">
            <Pause length="1"/>
          </Gather>`);
      }

      // ── Detección de urgencia dental PRE-AI ──────────────────
      // Si el tenant tiene número de guardia y se detecta urgencia → transferir ANTES de ir al AI
      {
        const URGENCY_KEYWORDS = /dolor\s+fuerte|dolor\s+intenso|dolor\s+insoportable|mucho\s+dolor|absceso|fractura|muela\s+rota|sangrado|emergencia\s+dental|urgencia\s+dental|no\s+puedo\s+dormir\s+de/i;
        if (URGENCY_KEYWORDS.test(transcript)) {
          try {
            const sessionRaw = await app.redis.get(`session:call:${CallSid}`);
            const sessionData = sessionRaw ? JSON.parse(sessionRaw) : {};
            if (sessionData.tenantId) {
              const tenantResult = await app.db.query('SELECT settings FROM tenants WHERE id=$1', [sessionData.tenantId]);
              const urgencyPhone = tenantResult.rows[0]?.settings?.clinica?.urgencyPhone;
              if (urgencyPhone) {
                app.log.warn({ CallSid, urgencyPhone }, '[Twilio] Urgencia detectada — transferiendo');
                return xmlReply(reply, `
                  <Say language="es-MX" voice="Polly.Mia-Neural">
                    Entiendo que es una urgencia dental. Le voy a transferir ahora mismo con nuestro equipo de guardia.
                  </Say>
                  <Dial callerId="${(await app.db.query('SELECT phone_number FROM agents WHERE id=$1',[sessionData.agentId])).rows[0]?.phone_number || ''}">
                    <Number>${urgencyPhone}</Number>
                  </Dial>`);
              }
            }
          } catch (urgErr) {
            app.log.warn({ urgErr }, '[Twilio] Error en detección de urgencia pre-AI');
          }
        }
      }
      // ─────────────────────────────────────────────────────────

      // Procesar en background — responder a Twilio INMEDIATAMENTE con filler
      // Evita timeout de 15 seg cuando Claude+tools toman 8-12 seg
      const resultKey = `airesult:${CallSid}`;
      app.redis.del(resultKey).catch(() => {});   // limpiar resultado previo

      // Background: procesar turno y guardar resultado en Redis
      (async () => {
        try {
          const { responseText, ended, outcome, transferTo } = await voiceAgent.processTurn(CallSid, transcript);
          const session = await app.redis.get(`session:call:${CallSid}`);
          const sessionData = session ? JSON.parse(session) : {};
          const agentResult = await app.db.query('SELECT voice_id, phone_number FROM agents WHERE id = $1', [sessionData.agentId]);
          const voiceId = agentResult.rows[0]?.voice_id || null;
          const callerId = agentResult.rows[0]?.phone_number || null;
          // Solo usar Cartesia para respuestas largas (>90 chars) — las cortas usan <Say> nativo
          let audioUrl = null;
          if (responseText.length > 90) {
            try { audioUrl = await audioToUrl(responseText, voiceId, `resp_${CallSid}_${Date.now()}`); } catch {}
          }
          await app.redis.set(resultKey, JSON.stringify({ responseText, audioUrl, ended, outcome, transferTo, callerId }), 'EX', 120);
        } catch (err) {
          app.log.error({ err, CallSid }, '[Twilio] Error en procesamiento background');
          try {
            await app.redis.set(resultKey, JSON.stringify({ error: true }), 'EX', 60);
          } catch (redisErr) {
            app.log.error({ redisErr }, '[Twilio] No se pudo escribir error en Redis');
          }
        }
      })();

      // Filler inteligente: largo para consultas de agenda (Claude tarda más), corto para el resto
      const isSchedulingQuery = /agend|cita|dispon|horario|reserv|cu[aá]ndo|mañana|semana/i.test(transcript);
      const fillerText = isSchedulingQuery
        ? 'Déjame consultar nuestra agenda de citas, un momentito por favor...'
        : pickFiller();

      // Responder a Twilio en <200ms con filler y redirect al resultado
      return xmlReply(reply, `
        <Say language="es-MX" voice="Polly.Mia-Neural">${fillerText}</Say>
        <Redirect method="POST">${process.env.APP_URL}/webhooks/twilio/ai-response?sid=${CallSid}</Redirect>
      `);

    } catch (err) {
      app.log.error({ err, CallSid }, '[Twilio] Error en /gather');
      return xmlReply(reply, `
        <Say language="es-MX" voice="Polly.Mia-Neural">
          Disculpa, tuve un problema. ¿Puedes repetir?
        </Say>
        <Gather input="speech"
                action="${process.env.APP_URL}/webhooks/twilio/gather"
                method="POST" speechTimeout="auto" language="es-MX">
          <Pause length="1"/>
        </Gather>`);
    }
  });

  /**
   * POST /webhooks/twilio/ai-response
   * Espera resultado del procesamiento background y devuelve TwiML con audio
   * Twilio redirige aquí tras el "Un momento..." del /gather
   */
  app.post('/ai-response', async (request, reply) => {
    const { sid } = request.query;
    const resultKey = `airesult:${sid}`;
    app.log.info({ sid }, '[Twilio] Esperando resultado AI...');

    // Polling hasta 13 segundos (Twilio da 15 seg por webhook)
    const maxWait = 13000;
    const pollInterval = 200;  // reducido de 400ms para menor latencia percibida
    const started = Date.now();
    let result = null;

    while (Date.now() - started < maxWait) {
      const raw = await app.redis.get(resultKey);
      if (raw) {
        result = JSON.parse(raw);
        await app.redis.del(resultKey);
        break;
      }
      await new Promise(r => setTimeout(r, pollInterval));
    }

    if (!result || result.error) {
      const retry = parseInt(request.query.retry || '0');
      app.log.warn({ sid, retry }, '[Twilio] Timeout en AI response');

      // Primer intento: otro filler + redirect. Compra ~12 s más.
      if (retry < 2) {
        const extraFiller = retry === 0
          ? 'Ya casi, un momentito más por favor...'
          : 'Casi listo, gracias por la paciencia...';
        return xmlReply(reply, `
          <Say language="es-MX" voice="Polly.Mia-Neural">${extraFiller}</Say>
          <Redirect method="POST">${process.env.APP_URL}/webhooks/twilio/ai-response?sid=${sid}&amp;retry=${retry + 1}</Redirect>
        `);
      }

      // Después de 3 intentos → pedir que repita
      return xmlReply(reply, `
        <Say language="es-MX" voice="Polly.Mia-Neural">
          Disculpa, tuve un problema técnico. ¿Puedes repetirme lo que necesitas?
        </Say>
        <Gather input="speech"
                action="${process.env.APP_URL}/webhooks/twilio/gather"
                method="POST" speechTimeout="auto" language="es-MX">
          <Pause length="1"/>
        </Gather>`);
    }

    const { responseText, audioUrl, ended, outcome, transferTo, callerId } = result;
    const audioBlock = audioUrl
      ? `<Play>${audioUrl}</Play>`
      : `<Say language="es-MX" voice="Polly.Mia-Neural">${responseText.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')}</Say>`;

    app.log.info({ sid, ended, outcome, hasTransfer: !!transferTo, hasAudio: !!audioUrl }, '[Twilio] AI response lista');

    // Transferencia REAL: anunciar y conectar con <Dial>. Si la persona no
    // contesta, Twilio sigue el TwiML de después del Dial (despedida).
    if (outcome === 'transfer_dial' && transferTo) {
      const cid = callerId ? ` callerId="${callerId}"` : '';
      return xmlReply(reply, `
        ${audioBlock}
        <Dial${cid} timeout="25" answerOnBridge="true">
          <Number>${transferTo}</Number>
        </Dial>
        <Say language="es-MX" voice="Polly.Mia-Neural">No fue posible conectar la llamada. Un asesor te contactará en breve. ¡Gracias!</Say>
        <Hangup/>`);
    }

    if (ended) {
      return xmlReply(reply, `${audioBlock}<Hangup/>`);
    }

    return xmlReply(reply, `
      ${audioBlock}
      <Gather input="speech"
              action="${process.env.APP_URL}/webhooks/twilio/gather"
              method="POST" speechTimeout="auto" language="es-MX">
        <Pause length="1"/>
      </Gather>
      <Redirect method="POST">${process.env.APP_URL}/webhooks/twilio/no-input?sid=${sid}</Redirect>
    `);
  });

  /**
   * POST /webhooks/twilio/no-input
   * El usuario no habló durante el Gather
   */
  app.post('/no-input', async (request, reply) => {
    const { sid } = request.query;
    app.log.info({ sid }, '[Twilio] Sin input del usuario');

    return xmlReply(reply, `
      <Say language="es-MX" voice="Polly.Mia-Neural">
        ¿Sigues ahí? ¿En qué puedo ayudarte?
      </Say>
      <Gather input="speech"
              action="${process.env.APP_URL}/webhooks/twilio/gather"
              method="POST" speechTimeout="auto" language="es-MX">
        <Pause length="1"/>
      </Gather>
      <Say language="es-MX" voice="Polly.Mia-Neural">
        Parece que te desconectaste. ¡Hasta luego!
      </Say>
      <Hangup/>`);
  });

  /**
   * POST /webhooks/twilio/status
   * Cambios de estado de la llamada (completed, failed, busy...)
   */
  app.post('/status', async (request) => {
    const { CallSid, CallStatus, CallDuration } = request.body;
    app.log.info({ CallSid, CallStatus, CallDuration }, '[Twilio] Status callback');

    if (['completed', 'failed', 'busy', 'no-answer'].includes(CallStatus)) {
      try {
        await voiceAgent.endCall(CallSid, {
          durationSecs: parseInt(CallDuration) || 0,
        });
      } catch (err) {
        app.log.error({ err }, '[Twilio] Error cerrando llamada');
      }
    }

    return { ok: true };
  });
}

// ─── Helpers ────────────────────────────────────────────────

const FILLERS = [
  'Claro, un momento.',
  'Permítame.',
  'Sí, con gusto.',
  'Un momento.',
  'Claro que sí.',
];
function pickFiller() {
  return FILLERS[Math.floor(Math.random() * FILLERS.length)];
}

function xmlReply(reply, content) {
  reply.header('Content-Type', 'text/xml; charset=utf-8');
  return reply.send(`<?xml version="1.0" encoding="UTF-8"?><Response>${content}</Response>`);
}

// Escapa para atributos/valores XML (TwiML Parameters, etc.)
function xmlEscape(s) {
  return String(s)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&apos;');
}

async function audioToUrl(text, voiceId, filename) {
  // Si no hay Cartesia configurado, usar voz nativa de Twilio como fallback
  if (!process.env.CARTESIA_API_KEY) {
    // Retornar null — el caller debe usar <Say> de Twilio en su lugar
    return null;
  }

  const audioPath = await synthesizeToFile(text, voiceId, filename);
  return `${process.env.APP_URL}/webhooks/twilio/audio/${path.basename(audioPath)}`;
}

module.exports = twilioRoutes;
