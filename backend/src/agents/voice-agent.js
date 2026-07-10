'use strict';


const { logger } = require('../services/logger');
const log = logger('VoiceAgent');
/**
 * VoiceAgent — Motor principal del agente de voz
 * 
 * Flujo por turno:
 * 1. Recibe transcript del usuario (ya procesado por Deepgram)
 * 2. Carga historial de sesión desde Redis
 * 3. Construye contexto: system prompt + historial + datos recolectados
 * 4. Llama al LLM router (modelo según intent)
 * 5. Si el LLM invoca tools → ejecutar → devolver resultado al LLM
 * 6. Genera respuesta final de texto
 * 7. Sintetiza audio con Cartesia
 * 8. Persiste mensajes en PostgreSQL
 * 9. Retorna audio buffer para enviar al usuario
 */

const { chat, chatStream } = require('../services/llm-router');
const { synthesize } = require('../services/tts-cartesia');
const SessionManager = require('../services/session-manager');
const { executeToolCall } = require('../tools/executor');

class VoiceAgent {
  constructor({ db, redis }) {
    this.db = db;
    this.sessions = new SessionManager(redis);
    this._kbCache = new Map();   // tenantId -> { has, ts } (evita RAG si no hay KB)
  }

  /**
   * ¿El tenant tiene chunks de KB? Cacheado 60s. Si no hay KB, se salta el
   * embedding RAG (que en este entorno pega a OpenAI 429 y gasta ~1.5s).
   */
  async _tenantHasKb(tenantId) {
    const cached = this._kbCache.get(tenantId);
    if (cached && Date.now() - cached.ts < 60000) return cached.has;
    let has = false;
    try {
      const r = await this.db.query(
        `SELECT EXISTS(
           SELECT 1 FROM kb_chunks c
           JOIN kb_documents d ON d.id = c.document_id
           WHERE d.tenant_id = $1
         ) AS has`,
        [tenantId]
      );
      has = !!r.rows[0]?.has;
    } catch { /* ante error, no bloquear: tratar como sin KB */ }
    this._kbCache.set(tenantId, { has, ts: Date.now() });
    return has;
  }

  /**
   * Inicializar agente al inicio de llamada
   * Crea sesión, carga config del agente, genera saludo
   * 
   * @param {Object} params
   * @returns {Object} { sessionId, audioBuffer, twiml }
   */
  async startCall({ agentId, tenantId, contactPhone, callSid, skipSynth = false, greetingOverride = null }) {
    // 1. Cargar config del agente desde DB
    const agentResult = await this.db.query(
      'SELECT * FROM agents WHERE id = $1 AND tenant_id = $2 AND is_active = true',
      [agentId, tenantId]
    );

    const agent = agentResult.rows[0];
    if (!agent) throw new Error(`Agente ${agentId} no encontrado o inactivo`);

    // Cargar profesionales si es consultorio (para vocabulario dinámico)
    const cfg = agent.config || {};
    const professionals = cfg.industry === 'consultorio'
      ? await this._loadProfessionals(tenantId)
      : [];

    // 2. Crear registro de conversación en DB
    const convResult = await this.db.query(
      `INSERT INTO conversations (tenant_id, agent_id, contact_phone, channel, status)
       VALUES ($1, $2, $3, 'voice', 'active') RETURNING id`,
      [tenantId, agentId, contactPhone]
    );
    const conversationId = convResult.rows[0].id;

    // 3. Crear sesión en Redis
    const sessionId = callSid || `call_${Date.now()}`;
    await this.sessions.create({
      sessionId,
      tenantId,
      agentId,
      conversationId,
      contactPhone,
      channel: 'voice',
      callSid,
    });

    // 3.5 — Reconocer cliente recurrente por Caller ID + cargar la moneda del
    //       negocio, para personalizar y para que el bot diga "pesos"/"dólares".
    try {
      const { resolveContact, getCustomerContext, customerContextPrompt } = require('../services/contacts');
      await resolveContact(this.db, tenantId, { phone: contactPhone, conversationId, sourceChannel: 'voice', agentId });

      // Settings del negocio: moneda + si reconoce llamantes recurrentes.
      let priceCurrencyWord = 'pesos mexicanos';
      let recognizeCallers = false;
      try {
        const ts = await this.db.query('SELECT settings FROM tenants WHERE id = $1', [tenantId]);
        const s = ts.rows[0]?.settings || {};
        const code = String(s.businessProfile?.currency || s.currency || 'MXN').toUpperCase();
        priceCurrencyWord = { MXN: 'pesos mexicanos', USD: 'dólares', EUR: 'euros' }[code] || code;
        recognizeCallers = s.recognizeReturningCallers === true; // default OFF
      } catch { /* default pesos / sin reconocimiento */ }

      const upd = { priceCurrencyWord };
      // Reconocer al cliente por Caller ID SOLO si el negocio lo habilitó: un
      // mismo número puede ser de varias personas, así que por defecto NO
      // asumimos identidad ni saludamos por nombre.
      if (recognizeCallers) {
        const ctx = await getCustomerContext(this.db, tenantId, contactPhone);
        if (ctx) { upd.customerCtx = customerContextPrompt(ctx); upd.customerName = ctx.name; }
      }
      await this.sessions.updateCollectedData(sessionId, upd);
    } catch (e) { /* no bloquear la llamada si falla */ }

    // 4. Generar saludo inicial. Si nos pasan un saludo pre-computado
    //    (streaming usa el cfg.greeting), evitamos una llamada LLM extra.
    let greetingText;
    let greetingResult = { tokensUsed: 0, latencyMs: 0 };
    if (greetingOverride) {
      greetingText = greetingOverride;
    } else {
      const gp = this._buildSystemPrompt(agent, {}, null, {}, professionals);
      greetingResult = await chat({
        systemPrompt: gp.static, systemDynamic: gp.dynamic,
        messages: [{ role: 'user', content: '[INICIO_LLAMADA]' }],
        taskType: 'greeting',
      });
      greetingText = greetingResult.content;
    }

    // 5. Guardar en sesión y DB
    await this.sessions.addMessage(sessionId, {
      role: 'assistant',
      content: greetingText,
      tokensUsed: greetingResult.tokensUsed,
      latencyMs: greetingResult.latencyMs,
    });

    await this._persistMessage(conversationId, tenantId, {
      role: 'assistant',
      content: greetingText,
      tokensUsed: greetingResult.tokensUsed,
      latencyMs: greetingResult.latencyMs,
    });

    // 6. Sintetizar saludo (omitido en streaming: el caller hace su propia
    //    síntesis μ-law para mantener UNA sola voz y baja latencia)
    const audioBuffer = skipSynth ? null : await synthesize(greetingText, agent.voice_id);

    return {
      sessionId,
      conversationId,
      greetingText,
      audioBuffer,
      voiceId: agent.voice_id,
      agent,
    };
  }

  /**
   * Procesar un turno de conversación
   * Recibe el transcript del usuario, retorna audio de respuesta
   * 
   * @param {string} sessionId
   * @param {string} userTranscript - Texto transcrito por Deepgram
   * @returns {Object} { responseText, audioBuffer, ended, outcome }
   */
  async processTurn(sessionId, userTranscript, { skipSynth = false } = {}) {
    const session = await this.sessions.get(sessionId);
    if (!session) throw new Error(`Sesión no encontrada: ${sessionId}`);

    const startTime = Date.now();

    // Guardar mensaje del usuario
    await this.sessions.addMessage(sessionId, { role: 'user', content: userTranscript });
    await this._persistMessage(session.conversationId, session.tenantId, {
      role: 'user', content: userTranscript,
    });

    // Cargar config del agente
    const agentResult = await this.db.query('SELECT * FROM agents WHERE id = $1', [session.agentId]);
    const agent = agentResult.rows[0];
    const agentCfg = agent.config || {};

    // Obtener historial formateado para el LLM
    const messages = await this.sessions.getMessagesForLLM(sessionId);

    // Cargar profesionales si es consultorio (para vocabulario dinámico)
    const professionals = agentCfg.industry === 'consultorio'
      ? await this._loadProfessionals(session.tenantId)
      : [];

    // RAG: buscar contexto relevante en knowledge base del tenant.
    // Optimización de latencia de voz:
    //  - Saltar si el tenant NO tiene KB (cacheado): el embedding es puro gasto.
    //  - Saltar consultas triviales (<3 palabras: "sí", "gracias").
    //  - Tope de 700ms: si el embedding se cuelga/reintenta, no bloquea el turno.
    const wordCount = userTranscript.trim().split(/\s+/).filter(Boolean).length;
    let ragResult = { context: null, found: false };
    if (wordCount >= 3 && await this._tenantHasKb(session.tenantId)) {
      const RetrievalService = require('../services/rag/retrieval');
      const retrieval = new RetrievalService({ db: this.db });
      ragResult = await Promise.race([
        retrieval.getContext({
          tenantId: session.tenantId,
          query:    userTranscript,
          agentId:  session.agentId,
          topK:     3,
        }),
        new Promise(res => setTimeout(() => res({ context: null, found: false, timedOut: true }), 700)),
      ]).catch(() => ({ context: null, found: false }));
    }

    // Construir system prompt (estable cacheable + dinámico por turno)
    const sp = this._buildSystemPrompt(agent, session.collectedData, ragResult.context, session, professionals);

    // Llamar al LLM
    let llmResult = await chat({
      systemPrompt: sp.static, systemDynamic: sp.dynamic,
      messages,
      tools: this._getAgentTools(agent),
    });

    let responseText = llmResult.content;
    let outcome = null;
    let transferTo = null;

    // Ejecutar tool calls si los hay
    if (llmResult.toolCalls && llmResult.toolCalls.length > 0) {
      const toolResults = [];

      for (const toolCall of llmResult.toolCalls) {
        try {
          const result = await executeToolCall({
            name: toolCall.name,
            input: toolCall.input,
            session,
            db: this.db,
          });

          toolResults.push({ toolName: toolCall.name, result });

          // Actualizar datos recolectados si el tool retorna datos
          if (result.collectedData) {
            await this.sessions.updateCollectedData(sessionId, result.collectedData);
            // ⚡ Actualizar también el objeto en memoria para que el próximo tool
            // en el mismo turno vea el leadId, leadPhone, etc. inmediatamente
            session.collectedData = { ...session.collectedData, ...result.collectedData };
          }

          if (result.outcome) outcome = result.outcome;
          if (result.transferTo) transferTo = result.transferTo;

          // Persistir tool call
          await this._persistMessage(session.conversationId, session.tenantId, {
            role: 'tool',
            content: JSON.stringify(result),
            toolName: toolCall.name,
            toolInput: toolCall.input,
            toolOutput: result,
          });

        } catch (toolErr) {
          log.error(`[VoiceAgent] Tool ${toolCall.name} falló:`, toolErr.message);
          toolResults.push({ toolName: toolCall.name, error: toolErr.message });
        }
      }

      // Segunda llamada al LLM con los resultados de los tools
      // Formato Anthropic: assistant lleva content blocks (tool_use) + user lleva tool_result blocks
      const assistantBlocks = [];
      if (responseText) assistantBlocks.push({ type: 'text', text: responseText });
      for (const tc of llmResult.toolCalls) {
        assistantBlocks.push({ type: 'tool_use', id: tc.id, name: tc.name, input: tc.input });
      }

      const toolResultMessages = [
        ...messages,
        { role: 'assistant', content: assistantBlocks },
        {
          role: 'user',
          content: toolResults.map((tr, i) => ({
            type: 'tool_result',
            tool_use_id: llmResult.toolCalls[i].id,
            content: JSON.stringify(tr.result || { error: tr.error }),
          })),
        },
      ];

      // Resumen post-herramienta: convertir el resultado del tool en una frase
      // hablada. Tarea simple → modelo rápido (Haiku vía taskType 'faq') para
      // recortar la latencia del segundo turno.
      llmResult = await chat({
        systemPrompt: sp.static, systemDynamic: sp.dynamic,
        messages: toolResultMessages,
        taskType: 'faq',
      });

      responseText = llmResult.content;
    }

    // Detectar si la conversación debe terminar
    let shouldEnd = this._detectConversationEnd(responseText, session.turnCount);

    // Override según outcome de handoff:
    // - transfer_dial: el webhook hace <Dial>; no colgar antes de transferir.
    // - handoff_pending: mantener la llamada viva para capturar nombre/teléfono
    //   de callback (aunque el LLM se haya despedido).
    if (outcome === 'transfer_dial' || outcome === 'handoff_pending') {
      shouldEnd = false;
    }

    // Guardar respuesta del asistente
    const totalLatency = Date.now() - startTime;
    await this.sessions.addMessage(sessionId, {
      role: 'assistant',
      content: responseText,
      tokensUsed: llmResult.tokensUsed,
      latencyMs: totalLatency,
    });

    await this._persistMessage(session.conversationId, session.tenantId, {
      role: 'assistant',
      content: responseText,
      tokensUsed: llmResult.tokensUsed,
      latencyMs: totalLatency,
    });

    // Sintetizar respuesta (omitido en streaming — síntesis μ-law la hace el caller)
    const audioBuffer = skipSynth ? null : await synthesize(responseText, agent.voice_id);

    // Si termina: cerrar conversación
    if (shouldEnd) {
      await this._closeConversation(sessionId, session.conversationId, session.tenantId, outcome);
    }

    return {
      responseText,
      audioBuffer,
      ended: shouldEnd,
      outcome,
      transferTo,
      latencyMs: totalLatency,
      model: llmResult.model,
    };
  }

  /**
   * Igual que processTurn pero TRANSMITE la respuesta por frases: llama
   * onSentence(frase) a medida que el LLM las genera, para que el caller
   * (Media Stream) empiece a sintetizar/hablar antes de terminar de generar.
   * Reduce mucho la latencia percibida (tiempo hasta el primer audio).
   */
  async processTurnStreaming(sessionId, userTranscript, { onSentence } = {}) {
    const session = await this.sessions.get(sessionId);
    if (!session) throw new Error(`Sesión no encontrada: ${sessionId}`);
    const startTime = Date.now();

    await this.sessions.addMessage(sessionId, { role: 'user', content: userTranscript });
    await this._persistMessage(session.conversationId, session.tenantId, { role: 'user', content: userTranscript });

    const agentResult = await this.db.query('SELECT * FROM agents WHERE id = $1', [session.agentId]);
    const agent = agentResult.rows[0];
    const agentCfg = agent.config || {};
    const messages = await this.sessions.getMessagesForLLM(sessionId);
    const professionals = agentCfg.industry === 'consultorio'
      ? await this._loadProfessionals(session.tenantId) : [];

    // RAG (mismo gateo que processTurn: salta si no hay KB / consulta trivial)
    const wordCount = userTranscript.trim().split(/\s+/).filter(Boolean).length;
    let ragResult = { context: null, found: false };
    if (wordCount >= 3 && await this._tenantHasKb(session.tenantId)) {
      const RetrievalService = require('../services/rag/retrieval');
      const retrieval = new RetrievalService({ db: this.db });
      ragResult = await Promise.race([
        retrieval.getContext({ tenantId: session.tenantId, query: userTranscript, agentId: session.agentId, topK: 3 }),
        new Promise(res => setTimeout(() => res({ context: null, found: false, timedOut: true }), 700)),
      ]).catch(() => ({ context: null, found: false }));
    }

    const sp = this._buildSystemPrompt(agent, session.collectedData, ragResult.context, session, professionals);

    // ── Emisor de frases ────────────────────────────────────────
    // Acumula hasta un cierre de oración, pero FUSIONA fragmentos cortos
    // (mín. 18 chars) para no emitir trozos como "Claro." sueltos que suenan
    // cortados. Cada chunk se habla apenas está listo (streaming).
    let buf = '', pending = '', spokenText = '';
    const SENT = /(.+?[.!?…])(\s+|$)/s;
    const MIN_CHUNK = 18;
    const emitChunk = (t) => { spokenText += (spokenText ? ' ' : '') + t; onSentence && onSentence(t); };
    const emit = (delta) => {
      buf += delta;
      let m;
      while ((m = SENT.exec(buf))) {
        pending = (pending ? pending + ' ' : '') + m[1].trim();
        buf = buf.slice(m[0].length);
        if (pending.length >= MIN_CHUNK) { emitChunk(pending); pending = ''; }
        if (!buf) break;
      }
    };
    const flush = () => {
      const rest = ((pending ? pending + ' ' : '') + buf).trim();
      pending = ''; buf = '';
      if (rest) emitChunk(rest);
    };

    // 1ª llamada (con tools), transmitiendo cualquier texto previo
    let llmResult = await chatStream({ systemPrompt: sp.static, systemDynamic: sp.dynamic, messages, tools: this._getAgentTools(agent), onText: emit });
    let outcome = null, transferTo = null;

    // Ejecutar tools si los hay y hacer 2ª llamada (también transmitida)
    if (llmResult.toolCalls && llmResult.toolCalls.length > 0) {
      const toolResults = [];
      for (const toolCall of llmResult.toolCalls) {
        try {
          const result = await executeToolCall({ name: toolCall.name, input: toolCall.input, session, db: this.db });
          toolResults.push({ toolName: toolCall.name, result });
          if (result.collectedData) {
            await this.sessions.updateCollectedData(sessionId, result.collectedData);
            session.collectedData = { ...session.collectedData, ...result.collectedData };
          }
          if (result.outcome) outcome = result.outcome;
          if (result.transferTo) transferTo = result.transferTo;
          await this._persistMessage(session.conversationId, session.tenantId, {
            role: 'tool', content: JSON.stringify(result),
            toolName: toolCall.name, toolInput: toolCall.input, toolOutput: result,
          });
        } catch (toolErr) {
          log.error(`[VoiceAgent] Tool ${toolCall.name} falló:`, toolErr.message);
          toolResults.push({ toolName: toolCall.name, error: toolErr.message });
        }
      }

      const assistantBlocks = [];
      if (llmResult.content) assistantBlocks.push({ type: 'text', text: llmResult.content });
      for (const tc of llmResult.toolCalls) assistantBlocks.push({ type: 'tool_use', id: tc.id, name: tc.name, input: tc.input });
      const toolResultMessages = [
        ...messages,
        { role: 'assistant', content: assistantBlocks },
        { role: 'user', content: toolResults.map((tr, i) => ({
            type: 'tool_result', tool_use_id: llmResult.toolCalls[i].id,
            content: JSON.stringify(tr.result || { error: tr.error }),
          })) },
      ];

      llmResult = await chatStream({ systemPrompt: sp.static, systemDynamic: sp.dynamic, messages: toolResultMessages, taskType: 'faq', onText: emit });
    }

    flush();
    const responseText = spokenText || llmResult.content || '';

    let shouldEnd = this._detectConversationEnd(responseText, session.turnCount);
    if (outcome === 'transfer_dial' || outcome === 'handoff_pending') shouldEnd = false;

    const totalLatency = Date.now() - startTime;
    await this.sessions.addMessage(sessionId, { role: 'assistant', content: responseText, tokensUsed: llmResult.tokensUsed, latencyMs: totalLatency });
    await this._persistMessage(session.conversationId, session.tenantId, { role: 'assistant', content: responseText, tokensUsed: llmResult.tokensUsed, latencyMs: totalLatency });

    if (shouldEnd) await this._closeConversation(sessionId, session.conversationId, session.tenantId, outcome);

    return { responseText, ended: shouldEnd, outcome, transferTo, latencyMs: totalLatency };
  }

  /**
   * Cerrar conversación manualmente (cuando Twilio notifica fin de llamada)
   */
  async endCall(sessionId, { durationSecs, recordingUrl } = {}) {
    const session = await this.sessions.get(sessionId);
    if (!session) return;

    await this._closeConversation(
      sessionId,
      session.conversationId,
      session.tenantId,
      null,
      { durationSecs, recordingUrl }
    );
  }

  // ─── Métodos privados ────────────────────────────────────────

  _buildSystemPrompt(agent, collectedData = {}, ragContext = null, session = {}, professionals = []) {
    // ── Generar prompt desde config estructurada (si existe) ───────
    const cfg = agent.config || {};
    const hasStructuredConfig = cfg.businessName || cfg.specialties?.length || cfg.faqs?.length
      || cfg.industry === 'dental' || cfg.enableTriage
      || cfg.industry === 'consultorio';
    const basePrompt = hasStructuredConfig
      ? this._promptFromConfig(agent, cfg, professionals)
      : (agent.system_prompt || '');

    // El contexto del cliente y la moneda se renderizan como secciones propias,
    // NO dentro del volcado JSON de datos recolectados.
    const { customerCtx, priceCurrencyWord, ...restData } = collectedData || {};
    // El carrito guarda importes en CENTAVOS (unit_cents). Para que el modelo NO
    // los lea como pesos (ej. 28000 en vez de $280), lo mostramos ya en pesos.
    if (Array.isArray(restData.cart)) {
      restData.cart = restData.cart.map(it => ({
        producto: it.name, cantidad: it.quantity, precio_pesos: (it.unit_cents || 0) / 100,
      }));
    }
    const dataContext = Object.keys(restData).length > 0
      ? `\n\nDatos recolectados en esta conversación:\n${JSON.stringify(restData, null, 2)}`
      : '';
    const customerSection = customerCtx ? `\n\n${customerCtx}` : '';
    // MONEDA: el modelo tiende a leer "$120" como "dólares". Le ordenamos decir
    // SIEMPRE la moneda del negocio al mencionar cualquier precio.
    const curWord = priceCurrencyWord || 'pesos mexicanos';
    const curShort = curWord.split(' ')[0]; // "pesos" / "dólares"
    const moneySection = `\n\nMONEDA: todos los precios están en ${curWord}. SIEMPRE di la moneda al mencionar CUALQUIER precio, incluso al itemizar (ej. di "doscientos ochenta ${curShort}", NUNCA solo "$280" ni el número suelto). PROHIBIDO leer el "$" como "dólares" o decir "dólares"${curShort === 'dólares' ? '' : ' (la moneda NO es dólares)'}.`;

    // Teléfono del llamante (Caller ID). NO asumirlo como contacto: el cliente
    // puede llamar desde otro número. Hay que CONFIRMARLO para la confirmación
    // por WhatsApp.
    const callerPhone = session.contactPhone && session.contactPhone !== 'unknown'
      ? `\n\nDato: el número desde el que llama (Caller ID) es ${session.contactPhone}. ANTES de agendar, CONFIRMA a qué número enviar la confirmación por WhatsApp, así: "¿Le envío la confirmación por WhatsApp a este mismo número, o prefiere darme otro?". Usa el número que confirme en schedule_appointment (campo phone). No lo pidas más de una vez.`
      : '';

    // Inyectar contexto RAG si hay resultados relevantes
    const ragSection = ragContext
      ? `\n\nINFORMACIÓN RELEVANTE DE LA BASE DE CONOCIMIENTO:\n${ragContext}\n\nUsa esta información para responder con precisión. Si la respuesta está en el contexto anterior, úsala directamente. Si no está, responde con lo que sabes o pide más detalles.`
      : '';

    // Núcleo común a TODOS los verticales.
    const coreVoice = `
\n\nINSTRUCCIONES DE COMPORTAMIENTO PARA VOZ:
- Respuestas cortas y naturales (máximo 1-2 oraciones por turno). Ve al grano.
- MULETILLAS PROHIBIDAS (IMPORTANTE): NO digas frases de relleno como "déjeme verificar", "permítame un momento", "con gusto reviso", "voy a verificar eso", "déjeme consultarlo", "déjeme procesar su pedido", "déjeme armar su pedido", "voy a procesar". Cuando necesites usar una herramienta, LLÁMALA DIRECTAMENTE SIN ANUNCIARLO y responde solo con el resultado. El sistema reproduce un sonido de espera automáticamente; si tú dices una muletilla, lo INTERRUMPES y el cliente se queda en silencio. Habla solo cuando tengas el resultado real.
- No uses listas, bullets, ni markdown — es una conversación hablada
- Cuando el usuario diga [INICIO_LLAMADA], saluda de forma cálida y pregunta en qué puedes ayudar
- ANTI-FRICCIÓN (importante): NO repreguntes datos que el usuario ya dio ni que estén en "Datos recolectados". NO repitas el saludo ni el menú de opciones. NO pidas confirmación de cada cosa — solo confirma al cerrar una acción.
- Si la intención ya es clara, ACTÚA (llama la herramienta) en vez de hacer preguntas de relleno. Ej: si pide agendar, avanza al flujo de agenda; no preguntes "¿en qué más puedo ayudar?" a media tarea.
- Si necesitas un dato que falta, pide UNO solo y el más importante primero (no varios en la misma frase)
- NOMBRE: en cuanto el usuario diga su nombre, DALO POR BUENO y AVANZA. Úsalo solo UNA vez ("Gracias, {nombre}") y NO lo repitas. NUNCA vuelvas a pedir el nombre si ya lo dijo (revisa el historial y "Datos recolectados"); NO pidas que lo confirme ni que lo deletree salvo que de plano no se haya entendido NADA. Pedir el nombre más de una vez está PROHIBIDO.
- TELÉFONO (citas): antes de agendar, confirma UNA vez a qué número enviar la confirmación por WhatsApp (puede llamar desde otro teléfono) y pásalo en schedule_appointment.phone.
- Cuando completes una acción (cita agendada, lead guardado), confirma en una frase y pregunta si necesita algo más
- Para despedirte, usa frases como "Fue un placer atenderte" o "Hasta luego"
- Si el usuario quiere hablar con un humano o pide un asesor, llama a la herramienta transfer_to_human. NO te despidas ni digas "hasta luego": si hay transferencia disponible se conectará la llamada; si no, toma su nombre y número para que un asesor le devuelva la llamada y guárdalo con save_lead.
- REGLA CRÍTICA — CITAS: si en los datos recolectados ya hay un appointmentId, la cita YA FUE AGENDADA. NO llames schedule_appointment de nuevo. Solo confirma el horario existente.
- CONFIRMACIONES — UNA SOLA VEZ: pide confirmación de una acción MÁXIMO una vez. En cuanto el cliente diga "sí/confirmo/por favor", EJECÚTALA y NO vuelvas a preguntar lo mismo. Repetir la misma confirmación genera fricción y alarga la llamada.`;

    // Bloque específico del vertical (evita instrucciones que no le tocan).
    const vIndustry = String(cfg.industry || '').toLowerCase();
    let verticalVoice = '';
    if (vIndustry === 'inmobiliaria') {
      verticalVoice = `
- PROPIEDADES: en schedule_appointment.property incluye tipo, zona y precio de la propiedad.
- FICHA/INFO DE PROPIEDAD: si el cliente pide información, fotos, la ficha o "que le mandes los detalles" de una propiedad, confirma a qué número de WhatsApp y usa send_property_info (le llega foto + datos + link a la ficha web). No dictes todos los datos por voz; mejor envíale la cédula.
- VARIAS PROPIEDADES/VISITAS: agenda UNA cita COMPLETA a la vez. Si el cliente quiere ver varias propiedades, enfócate en la PRIMERA: elige propiedad → revisa disponibilidad → agenda → confirma. Recién DESPUÉS pregunta "¿Quiere que agendemos también la visita a la otra propiedad?" y repite el flujo. NUNCA mezcles dos propiedades, dos fechas o dos disponibilidades en el mismo paso.`;
    } else if (vIndustry === 'restaurante' || vIndustry === 'ecommerce') {
      verticalVoice = `
- CARRITO — QUITAR PRODUCTOS: si el cliente pide quitar, cancelar o cambiar un producto, llama de inmediato remove_from_cart con el nombre. NUNCA uses view_cart para quitar algo, y NUNCA digas "voy a quitarlo" sin llamar la herramienta (si no, el producto se queda y el cliente lo repite).`;
    }
    const voiceInstructions = coreVoice + verticalVoice;

    // Estable (cacheable): negocio + caller + instrucciones de voz + (las tools
    // se cachean aparte). Dinámico (varía por turno): datos recolectados + RAG.
    return {
      static:  basePrompt + callerPhone + voiceInstructions,
      dynamic: dataContext + customerSection + moneySection + ragSection,
      full:    basePrompt + dataContext + customerSection + moneySection + callerPhone + ragSection + voiceInstructions,
    };
  }

  _getAgentTools(agent) {
    const allTools = [
      // ── Fase 1 ────────────────────────────────────────
      {
        type: 'function',
        function: {
          name: 'save_lead',
          description: 'Guarda los datos de contacto del usuario como lead. Úsalo cuando ya tengas nombre y teléfono.',
          parameters: {
            type: 'object',
            properties: {
              name:   { type: 'string', description: 'Nombre completo' },
              phone:  { type: 'string', description: 'Teléfono' },
              email:  { type: 'string', description: 'Email (si lo proporcionó)' },
              intent: { type: 'string', description: 'Qué quiere: cita, información, compra, queja...' },
              notes:  { type: 'string', description: 'Notas de la conversación' },
            },
            required: ['name', 'phone', 'intent'],
          },
        },
      },
      {
        type: 'function',
        function: {
          name: 'transfer_to_human',
          description: 'Transfiere con un humano cuando el usuario lo pide o la situación lo requiere',
          parameters: {
            type: 'object',
            properties: {
              reason: { type: 'string', description: 'Motivo de la transferencia' },
            },
            required: ['reason'],
          },
        },
      },

      // ── Fase 2: Agendamiento ───────────────────────────
      {
        type: 'function',
        function: {
          name: 'check_availability',
          description: 'Consulta los horarios disponibles para agendar una cita. Úsalo ANTES de schedule_appointment.',
          parameters: {
            type: 'object',
            properties: {
              days_ahead: { type: 'integer', description: 'Cuántos días hacia adelante buscar (default 5)', default: 5 },
            },
            required: [],
          },
        },
      },
      {
        type: 'function',
        function: {
          name: 'schedule_appointment',
          description: 'Agenda una cita/visita en el horario elegido. Llama check_availability primero. IMPORTANTE: el phone debe ser el número CONFIRMADO por el cliente para WhatsApp (puede llamar desde otro teléfono). Solo llama esta herramienta UNA VEZ por conversación. Si ya hay un appointmentId en el contexto, la cita ya fue agendada — no la repitas.',
          parameters: {
            type: 'object',
            properties: {
              start_time:  { type: 'string', description: 'ISO datetime del slot elegido (de check_availability)' },
              slot_index:  { type: 'integer', description: 'Índice del slot (0=primero, 1=segundo...) como alternativa a start_time' },
              name:        { type: 'string', description: 'Nombre del cliente' },
              phone:       { type: 'string', description: 'Teléfono CONFIRMADO del cliente (al que se enviará la confirmación por WhatsApp)' },
              email:       { type: 'string', description: 'Email (opcional)' },
              property:    { type: 'string', description: 'Propiedad de interés para la confirmación: tipo, zona y precio (ej: "Departamento en renta - Juriquilla, $16,000/mes")' },
              notes:       { type: 'string', description: 'Motivo de la cita u observaciones' },
            },
            required: ['name', 'phone'],
          },
        },
      },
      {
        type: 'function',
        function: {
          name: 'cancel_appointment',
          description: 'Cancela una cita existente',
          parameters: {
            type: 'object',
            properties: {
              appointment_id: { type: 'string', description: 'ID de la cita a cancelar' },
              reason:         { type: 'string', description: 'Motivo de cancelación' },
            },
            required: ['appointment_id'],
          },
        },
      },
      {
        type: 'function',
        function: {
          name: 'find_appointment',
          description: 'Busca citas próximas del usuario por teléfono. Úsalo cuando pregunte por su cita.',
          parameters: {
            type: 'object',
            properties: {
              phone: { type: 'string', description: 'Teléfono para buscar' },
            },
            required: [],
          },
        },
      },
      {
        type: 'function',
        function: {
          name: 'join_waitlist',
          description: 'Anota al cliente en la LISTA DE ESPERA cuando NO hay horarios disponibles o el que quería está ocupado y acepta esperar. Se le avisará por WhatsApp en cuanto se libere un lugar. Ofrécela siempre que no puedas agendar por falta de cupo.',
          parameters: {
            type: 'object',
            properties: {
              service:    { type: 'string', description: 'Servicio deseado (opcional)' },
              doctor:     { type: 'string', description: 'Profesional preferido (opcional)' },
              preference: { type: 'string', description: 'Preferencia de horario en texto, ej. "por las tardes" (opcional)' },
              name:       { type: 'string', description: 'Nombre del cliente (si aún no se tiene)' },
              phone:      { type: 'string', description: 'Teléfono del cliente (si aún no se tiene)' },
            },
            required: [],
          },
        },
      },

      // ── Módulo Clínica ─────────────────────────────────────
      {
        type: 'function',
        function: {
          name: 'triage_service',
          description: 'Identifica el tipo de servicio dental y el doctor asignado. Úsalo cuando el usuario quiera agendar una cita, ANTES de check_availability. Detecta si es urgencia.',
          parameters: {
            type: 'object',
            properties: {
              user_description: {
                type: 'string',
                description: 'Lo que describió el usuario: "limpieza", "me duele una muela", "quiero valoración", "urgencia dental", etc.',
              },
            },
            required: ['user_description'],
          },
        },
      },
      {
        type: 'function',
        function: {
          name: 'escalate_urgency',
          description: 'Transfiere inmediatamente al número de guardia/urgencias cuando hay dolor intenso, sangrado, fractura dental u otra emergencia. Siempre úsala si el usuario describe dolor severo o urgencia dental.',
          parameters: {
            type: 'object',
            properties: {
              reason: {
                type: 'string',
                description: 'Descripción breve de la urgencia (dolor, fractura, sangrado, etc.)',
              },
              patient_phone: {
                type: 'string',
                description: 'Teléfono del paciente para que el doctor pueda devolver la llamada',
              },
            },
            required: ['reason'],
          },
        },
      },
      {
        type: 'function',
        function: {
          name: 'send_deposit_link',
          description: 'Genera y envía un link de pago para apartar la cita (anticipo). Úsalo después de schedule_appointment cuando el servicio requiera depósito.',
          parameters: {
            type: 'object',
            properties: {
              appointment_id: {
                type: 'string',
                description: 'ID de la cita recién agendada',
              },
              patient_phone: {
                type: 'string',
                description: 'Teléfono al que enviar el link de pago por WhatsApp',
              },
              amount: {
                type: 'number',
                description: 'Monto del depósito en pesos MXN (si se conoce)',
              },
            },
            required: ['appointment_id'],
          },
        },
      },

      // ── Módulo Consultorios ────────────────────────────────────
      {
        type: 'function',
        function: {
          name: 'qualify_lead',
          description: 'Califica al cliente antes de agendar. Carga las preguntas configuradas y evalúa las respuestas. Llama primero sin "answers" para obtener las preguntas; luego con "answers" para evaluar.',
          parameters: {
            type: 'object',
            properties: {
              professional_id:  { type: 'string', description: 'ID del profesional (opcional, filtra preguntas)' },
              session_type_id:  { type: 'string', description: 'ID del tipo de sesión (opcional)' },
              answers: {
                type: 'array',
                description: 'Respuestas del cliente. Si está vacío, retorna las preguntas a hacer.',
                items: {
                  type: 'object',
                  properties: {
                    question_id: { type: 'string' },
                    answer:      { type: 'string', description: 'Respuesta: "si", "no", texto libre, o número 1-10' },
                  },
                  required: ['question_id', 'answer'],
                },
              },
            },
            required: [],
          },
        },
      },
      {
        type: 'function',
        function: {
          name: 'book_session_series',
          description: 'Agenda una serie de sesiones recurrentes (terapia, asesoría, etc.). Crea todas las sesiones con status pending_professional y notifica al profesional.',
          parameters: {
            type: 'object',
            properties: {
              professional_id:  { type: 'string', description: 'UUID del profesional' },
              session_type_id:  { type: 'string', description: 'UUID del tipo de sesión' },
              patient_name:     { type: 'string', description: 'Nombre completo del paciente' },
              patient_phone:    { type: 'string', description: 'Teléfono del paciente con código país' },
              patient_email:    { type: 'string', description: 'Email del paciente (opcional)' },
              total_sessions:   { type: 'number', description: 'Número de sesiones a agendar' },
              frequency:        { type: 'string', enum: ['single','weekly','biweekly','monthly'], description: 'Frecuencia de repetición' },
              modality:         { type: 'string', enum: ['presencial','video'], description: 'Modalidad de la sesión' },
              first_session_at: { type: 'string', description: 'Fecha/hora ISO de la primera sesión' },
              notes:            { type: 'string', description: 'Notas de logística (NO datos sensibles del caso)' },
            },
            required: ['patient_name', 'first_session_at', 'frequency', 'modality', 'total_sessions'],
          },
        },
      },
      {
        type: 'function',
        function: {
          name: 'send_video_link',
          description: 'Envía el link de videollamada del profesional al paciente por WhatsApp.',
          parameters: {
            type: 'object',
            properties: {
              professional_id: { type: 'string', description: 'UUID del profesional' },
              session_id:      { type: 'string', description: 'UUID de la sesión (alternativo)' },
              patient_phone:   { type: 'string', description: 'Teléfono del paciente' },
            },
            required: [],
          },
        },
      },

      // ── Catálogo / comercio (solo si el negocio vende productos) ──
      {
        type: 'function',
        function: {
          name: 'search_products',
          description: 'Busca en el catálogo del negocio (productos o propiedades). Úsalo cuando el cliente busca algo o pide opciones. Para inmobiliaria, EXTRAE del lenguaje del cliente los filtros estructurados (operación, tipo, presupuesto, recámaras) y pásalos además del texto: así no muestras nada fuera de rango. Devuelve la lista con precios.',
          parameters: {
            type: 'object',
            properties: {
              query:         { type: 'string', description: 'Texto libre a buscar (zona/colonia, nombre). Vacío = todo el catálogo.' },
              category:      { type: 'string', description: 'Filtrar por categoría' },
              operation:     { type: 'string', enum: ['venta', 'renta'], description: 'Inmobiliaria: comprar→venta, rentar→renta' },
              property_type: { type: 'string', enum: ['casa', 'departamento', 'terreno', 'bodega'], description: 'Inmobiliaria: tipo de propiedad' },
              price_min:     { type: 'number', description: 'Precio mínimo en pesos (no centavos)' },
              price_max:     { type: 'number', description: 'Precio máximo/presupuesto en pesos (ej. "menos de 20 mil" → 20000)' },
              bedrooms:      { type: 'integer', description: 'Inmobiliaria: recámaras mínimas requeridas' },
            },
            required: [],
          },
        },
      },
      {
        type: 'function',
        function: {
          name: 'send_property_info',
          description: 'Envía por WhatsApp la CÉDULA de una propiedad: foto + ficha de datos + link a la ficha web con galería. Úsalo cuando el cliente pida información, fotos, la ficha, o "que le mandes los detalles" de una propiedad. Confirma antes a qué número enviarla.',
          parameters: {
            type: 'object',
            properties: {
              property: { type: 'string', description: 'Propiedad a enviar: nombre/zona/tipo (ej: "Departamento Condesa renta")' },
              phone:    { type: 'string', description: 'Número de WhatsApp al que enviar (confírmalo; puede ser distinto al de la llamada)' },
            },
            required: ['property'],
          },
        },
      },
      {
        type: 'function',
        function: {
          name: 'add_to_cart',
          description: 'Agrega un producto al carrito del cliente. Úsalo cuando el cliente quiere comprar o agregar un producto.',
          parameters: {
            type: 'object',
            properties: {
              product_name: { type: 'string', description: 'Nombre del producto a agregar' },
              quantity:     { type: 'integer', description: 'Cantidad (default 1)', default: 1 },
            },
            required: ['product_name'],
          },
        },
      },
      {
        type: 'function',
        function: {
          name: 'remove_from_cart',
          description: 'QUITA un producto del carrito (o reduce su cantidad). Úsalo SIEMPRE que el cliente diga que ya no quiere algo, que lo quites, lo canceles o lo cambies. NO uses view_cart para esto.',
          parameters: {
            type: 'object',
            properties: {
              product_name: { type: 'string', description: 'Nombre del producto a quitar (como está en el carrito)' },
              quantity:     { type: 'integer', description: 'Cantidad a quitar; omítelo para quitar el producto completo' },
            },
            required: ['product_name'],
          },
        },
      },
      {
        type: 'function',
        function: {
          name: 'view_cart',
          description: 'Muestra el carrito actual del cliente con el total. Úsalo cuando pregunta qué lleva o cuánto va.',
          parameters: { type: 'object', properties: {}, required: [] },
        },
      },
      {
        type: 'function',
        function: {
          name: 'check_delivery_area',
          description: 'Valida si una dirección de entrega cae dentro de la zona de reparto a domicilio del negocio. ÚSALO SIEMPRE en pedidos a domicilio, en cuanto el cliente te dé la dirección completa (calle, número y colonia) y ANTES de cerrar el pedido. No lo uses si el cliente va a pasar a recoger.',
          parameters: {
            type: 'object',
            properties: {
              address: { type: 'string', description: 'Dirección completa de entrega: calle, número, colonia y ciudad si la dice' },
            },
            required: ['address'],
          },
        },
      },
      {
        type: 'function',
        function: {
          name: 'checkout_order',
          description: 'Cierra el pedido: crea la orden y genera un link de pago de Stripe. Úsalo cuando el cliente confirma que quiere pagar/finalizar la compra.',
          parameters: {
            type: 'object',
            properties: {
              customer_name:  { type: 'string', description: 'Nombre del cliente (si lo tienes)' },
              customer_phone: { type: 'string', description: 'Teléfono del cliente (si lo tienes)' },
            },
            required: [],
          },
        },
      },
    ];

    // ── Aislamiento por vertical (evita ruido/colisión de tools) ──────
    // Cada industria solo ve el núcleo común + sus tools especializadas.
    // Una industria DESCONOCIDA conserva todas (compat. con tenants legados).
    const CORE = new Set([
      'save_lead', 'transfer_to_human', 'check_availability', 'schedule_appointment',
      'cancel_appointment', 'find_appointment', 'join_waitlist',
    ]);
    const VERTICAL_TOOLS = {
      inmobiliaria: ['search_products', 'send_property_info'],
      restaurante:  ['search_products', 'add_to_cart', 'remove_from_cart', 'view_cart', 'check_delivery_area', 'checkout_order'],
      ecommerce:    ['search_products', 'add_to_cart', 'remove_from_cart', 'view_cart', 'check_delivery_area', 'checkout_order'],
      dental:       ['triage_service', 'escalate_urgency', 'send_deposit_link'],
      consultorio:  ['qualify_lead', 'book_session_series', 'send_video_link'],
    };
    const industry = String(agent?.config?.industry || '').toLowerCase();
    const allowed = VERTICAL_TOOLS[industry];
    if (!allowed) return allTools;
    return allTools.filter(t => CORE.has(t.function.name) || allowed.includes(t.function.name));
  }

  _detectConversationEnd(responseText, turnCount) {
    // OJO: no incluir "transferir"/"pasarte con" — una transferencia NO es un
    // fin de llamada (se maneja con <Dial> u handoff_pending). Si se marcan como
    // fin, el webhook cuelga justo después de decir "no cuelgue".
    const endPhrases = [
      'hasta luego', 'hasta pronto', 'fue un placer', 'que tengas',
      'buen día', 'buenas noches', 'adiós', 'nos vemos',
    ];

    const lower = responseText.toLowerCase();
    const hasEndPhrase = endPhrases.some(p => lower.includes(p));
    // Red de seguridad anti-bucle/coste, NO un corte de UX: 15 era demasiado bajo
    // (un pedido con menú + varios platillos + dirección + datos supera 15 turnos
    // y la llamada se colgaba a media orden). Subido a 40, configurable por env.
    const maxTurns = parseInt(process.env.VOICE_MAX_TURNS) || 60;
    const tooLong = turnCount > maxTurns;

    return hasEndPhrase || tooLong;
  }

  async _persistMessage(conversationId, tenantId, { role, content, toolName, toolInput, toolOutput, tokensUsed = 0, latencyMs = 0 }) {
    try {
      await this.db.query(
        `INSERT INTO messages (conversation_id, tenant_id, role, content, tool_name, tool_input, tool_output, tokens_used, latency_ms)
         VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9)`,
        [
          conversationId, tenantId, role, content,
          toolName || null,
          toolInput ? JSON.stringify(toolInput) : null,
          toolOutput ? JSON.stringify(toolOutput) : null,
          tokensUsed, latencyMs,
        ]
      );
    } catch (err) {
      log.error('[VoiceAgent] Error persistiendo mensaje:', err.message);
    }
  }

  /**
   * Genera el system_prompt a partir de la config estructurada del agente.
   * Esto permite que el dashboard edite el agente sin tocar texto crudo.
   */
  _promptFromConfig(agent, cfg, professionals = []) {
    const toneMap = {
      professional: 'profesional y amable',
      friendly:     'cálido, cercano y empático',
      formal:       'formal y cortés',
      casual:       'relajado y amigable',
    };
    const tone = toneMap[cfg.tone] || 'amable y profesional';

    let prompt = `Eres ${agent.name}, el asistente virtual de ${cfg.businessName || 'la empresa'}.`;
    prompt += ` Tu trato debe ser ${tone}.`;

    if (cfg.objective) {
      prompt += `\n\nOBJETIVO PRINCIPAL: ${cfg.objective}`;
    }

    if (cfg.greeting) {
      prompt += `\n\nSALUDO: Cuando el usuario inicie la llamada, saluda diciendo exactamente: "${cfg.greeting}"`;
    }

    if (cfg.specialties?.length) {
      prompt += `\n\nESPECIALIDADES DISPONIBLES:\n`;
      cfg.specialties.forEach(s => {
        prompt += `• ${s.name}`;
        if (s.specialist) prompt += ` — atendido por ${s.specialist}`;
        if (s.description) prompt += ` (${s.description})`;
        prompt += '\n';
      });
      prompt += `\nCuando el usuario quiera agendar una cita, primero llama a triage_service con lo que describió el usuario para identificar el servicio y doctor correctos. Luego usa check_availability.`;
    }

    // Instrucciones de triaje dental (industria clínica)
    if (cfg.industry === 'dental' || cfg.enableTriage) {
      prompt += `
\n\nFLUJO DE TRIAJE DENTAL (OBLIGATORIO):
1. Cuando el usuario quiera agendar, PRIMERO llama triage_service para identificar el servicio.
2. Si triage_service retorna is_urgency=true → llama escalate_urgency inmediatamente.
3. Si es servicio normal → usa el doctor_id retornado en check_availability.
4. Después de schedule_appointment, si requires_deposit=true → llama send_deposit_link.
5. Informa al paciente las instrucciones de preparación (prep_instructions) del servicio.

URGENCIAS DENTALES — activa escalate_urgency ante:
"dolor fuerte", "dolor intenso", "dolor insoportable", "absceso", "fractura dental",
"muela rota", "sangrado", "accidente", "golpe en los dientes", "no puedo dormir del dolor"`;
    }

    // ── Flujo Consultorios ──────────────────────────────────────────
    if (cfg.industry === 'consultorio') {
      // Mapa de vocabulario por specialty_type
      const VOCAB = {
        psychology: { person: 'paciente',      persons: 'pacientes',      session: 'sesión',   sessions: 'sesiones',  area: 'psicología' },
        legal:      { person: 'cliente',        persons: 'clientes',        session: 'consulta', sessions: 'consultas', area: 'derecho / legal' },
        accounting: { person: 'contribuyente',  persons: 'contribuyentes',  session: 'asesoría', sessions: 'asesorías', area: 'contabilidad / fiscal' },
        coaching:   { person: 'coachee',        persons: 'coachees',        session: 'sesión',   sessions: 'sesiones',  area: 'coaching' },
        nutrition:  { person: 'paciente',       persons: 'pacientes',       session: 'consulta', sessions: 'consultas', area: 'nutrición' },
        medical:    { person: 'paciente',       persons: 'pacientes',       session: 'consulta', sessions: 'consultas', area: 'medicina' },
        other:      { person: 'cliente',        persons: 'clientes',        session: 'sesión',   sessions: 'sesiones',  area: 'profesional' },
      };

      // Determinar vocabulario dominante
      const specTypes = [...new Set(professionals.map(p => p.specialty_type || 'other'))];
      const vocab = specTypes.length === 1 ? (VOCAB[specTypes[0]] || VOCAB.other) : VOCAB.other;

      // Lista de profesionales con su vocab específico
      let profRoster = '';
      if (professionals.length > 0) {
        profRoster = '\n\nPROFESIONALES DISPONIBLES:\n';
        professionals.forEach(p => {
          const pv = VOCAB[p.specialty_type || 'other'] || VOCAB.other;
          profRoster += `• ${p.name}`;
          if (p.specialty) profRoster += ` (${p.specialty})`;
          profRoster += ` — atiende ${pv.sessions} con ${pv.persons}`;
          if (p.modality === 'video') profRoster += ', modalidad video';
          else if (p.modality === 'presencial') profRoster += ', modalidad presencial';
          profRoster += '\n';
        });
      }

      const confMsg = cfg.confidentialityMessage ||
        'Por respeto a la privacidad, no compartas detalles sensibles del caso por este medio. Solo manejamos logística.';

      prompt += `\n\nVOCABULARIO: En este consultorio, al ${vocab.person} se le llama "${vocab.person}" y las citas son "${vocab.sessions}". Usa siempre estos términos.
${profRoster}
CONFIDENCIALIDAD (MUY IMPORTANTE): ${confMsg}
Nunca solicites ni repitas diagnósticos, síntomas específicos, situaciones legales o financieras detalladas.

FLUJO DE CONSULTORIOS (OBLIGATORIO EN ESTE ORDEN):
1. SALUDO + CONFIDENCIALIDAD: Menciona brevemente que la conversación es solo para agendar ${vocab.sessions}, no para dar ${vocab.sessions} en este momento.
2. CALIFICACIÓN: Llama qualify_lead ANTES de agendar. Si retorna qualified: null → haz las preguntas al ${vocab.person} y vuelve a llamar qualify_lead con las respuestas. Si qualified: false → informa amablemente que el caso no aplica y ofrece alternativas.
3. AGENDAMIENTO: Si qualified: true → usa check_availability para mostrar horarios. Una vez elegido, llama book_session_series con los datos del ${vocab.person}.
4. VIDEOLLAMADA: Si la ${vocab.session} es en modalidad 'video' → llama send_video_link para enviar el link.
5. RECORDATORIO: Informa que el profesional confirmará y el ${vocab.person} recibirá un aviso por WhatsApp.

POLÍTICA DE CANCELACIÓN: Deben cancelar con al menos ${cfg.cancellationHours || 24} horas de anticipación.
${vocab.sessions.charAt(0).toUpperCase() + vocab.sessions.slice(1)} RECURRENTES: Si el ${vocab.person} quiere ${vocab.sessions} continuas → pregunta si prefiere reservar la serie completa ahora.`;
    }

    // ── Flujo Inmobiliaria ──────────────────────────────────────────
    if (cfg.industry === 'inmobiliaria') {
      prompt += `

FLUJO INMOBILIARIO (síguelo con naturalidad, sin sonar a cuestionario):
1. ENTENDER LA NECESIDAD: identifica lo esencial para buscar bien — operación (¿compra o renta?), tipo (casa, departamento, terreno, bodega), zona/colonia, presupuesto y, si aplica, recámaras. Pide UN dato a la vez, el más importante primero; no interrogues.
2. BUSCAR: en cuanto tengas operación + (zona o tipo o presupuesto), llama search_products con lo que describió el cliente (ej. "departamento renta Condesa 2 recámaras"). Presenta 1 a 3 opciones que encajen, di nombre, zona y precio (con la moneda). No leas fichas completas por voz.
3. FICHA: si el cliente quiere fotos, detalles o "que le mandes la información" de una propiedad, confirma UNA vez a qué WhatsApp y usa send_property_info (le llega foto + datos + link). No dictes todos los datos.
4. CALIFICAR (lead scoring, sin sonar a filtro): de forma conversacional captura presupuesto real, forma de pago (crédito hipotecario/Infonavit/Fovissste/contado) y qué tan pronto necesita mudarse/cerrar. Guarda esto con save_lead (intent="compra" o "renta", notes con presupuesto/forma de pago/urgencia).
5. AGENDAR VISITA: cuando muestre interés en ver una propiedad, usa check_availability y luego schedule_appointment. En schedule_appointment.property incluye tipo, zona y precio; confirma UNA vez a qué WhatsApp mandar la confirmación.
6. ASESOR: si pide hablar con un asesor, quiere negociar precio, o es un prospecto caliente (presupuesto claro + urgencia), llama transfer_to_human. Si no hay transferencia disponible, toma nombre y teléfono con save_lead para que un asesor le devuelva la llamada.
7. SIN COINCIDENCIAS (CRÍTICO — NUNCA dejes ir al cliente sin esto): si search_products no devuelve NADA que encaje con lo que busca, NUNCA cierres la conversación sin antes capturar nombre y teléfono con save_lead (intent="compra" o "renta", notes con la zona/tipo/presupuesto que buscaba). Dile algo como "por ahora no tengo algo así, pero déjame tus datos y en cuanto tengamos una opción similar te contacto". Un cliente sin propiedad disponible sigue siendo un lead valioso — perderlo sin registrarlo es inaceptable. EN CUANTO el cliente te dé su nombre y teléfono en este escenario, LLAMA save_lead DE INMEDIATO — PROHIBIDO decir "queda registrado" o "un asesor te contactará" sin haber llamado realmente la herramienta; si dices eso sin llamarla, el lead se pierde de verdad aunque suene bien.

REGLAS: nunca inventes propiedades, precios ni disponibilidad — usa solo lo que devuelva search_products. Si no hay match, dilo, ofrece alternativas cercanas (otra zona/presupuesto) Y CAPTURA SUS DATOS (regla 7). Mostrar propiedades y agendar visitas NO tiene costo.`;
    }

    if (cfg.faqs?.length) {
      prompt += `\n\nINFORMACIÓN FRECUENTE (responde con estos datos exactos):\n`;
      cfg.faqs.forEach(faq => {
        prompt += `P: ${faq.q}\nR: ${faq.a}\n\n`;
      });
    }

    if (cfg.extraInstructions) {
      prompt += `\nINSTRUCCIONES ADICIONALES:\n${cfg.extraInstructions}`;
    }

    return prompt;
  }

  /**
   * Carga profesionales activos del tenant para construir vocabulario en el prompt.
   * Solo se llama para agentes con industry === 'consultorio'.
   */
  async _loadProfessionals(tenantId) {
    try {
      const r = await this.db.query(
        `SELECT id, name, specialty, specialty_type, modality, is_active
         FROM professionals
         WHERE tenant_id = $1 AND is_active = true
         ORDER BY sort_order ASC, name ASC`,
        [tenantId]
      );
      return r.rows;
    } catch (err) {
      log.warn('[VoiceAgent] No se pudieron cargar profesionales:', err.message);
      return [];
    }
  }

  async _closeConversation(sessionId, conversationId, tenantId, outcome, { durationSecs, recordingUrl } = {}) {
    try {
      const session = await this.sessions.get(sessionId);

      await this.db.query(
        `UPDATE conversations
         SET status = 'completed', ended_at = NOW(), duration_secs = $1,
             recording_url = $2, outcome = $3
         WHERE id = $4`,
        [durationSecs || null, recordingUrl || null, outcome || 'completed', conversationId]
      );

      // Registrar uso y reportar excedente a Stripe (Fase 6)
      if (durationSecs) {
        const UsageTracker = require('../services/billing/usage-tracker');
        const tracker = new UsageTracker({ db: this.db });
        await tracker.recordUsage(tenantId, durationSecs).catch(err => {
          log.error('[VoiceAgent] Error registrando uso:', err.message);
        });
      }

      await this.sessions.close(sessionId);

      // Voz del cliente: analizar la conversación en background (non-fatal)
      const { analyzeInBackground } = require('../services/conversation-analyzer');
      analyzeInBackground(this.db, conversationId);
    } catch (err) {
      log.error('[VoiceAgent] Error cerrando conversación:', err.message);
    }
  }
}

module.exports = VoiceAgent;
