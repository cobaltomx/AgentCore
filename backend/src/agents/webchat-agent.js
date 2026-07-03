'use strict';


const { logger } = require('../services/logger');
const log = logger('WebchatAgent');
/**
 * WebChatAgent — Canal de chat web (widget embebible)
 *
 * Reutiliza el mismo cerebro que voz/WhatsApp:
 *   - LLM Router (chat)
 *   - Tools (scheduling, save_lead, ...) — el mismo set que VoiceAgent
 *   - RAG (RetrievalService)
 *   - SessionManager en Redis
 *
 * Diferencias clave:
 *   - SÍNCRONO: el visitante manda mensaje → se responde en la misma respuesta HTTP
 *     (no hay webhook async como WhatsApp ni audio como voz).
 *   - Visitante anónimo: sesión identificada por un visitorId del navegador.
 *   - Sin envío externo (Meta/Twilio): la respuesta se devuelve al widget.
 *   - Captura de lead conversacional (el bot pide los datos durante la charla).
 */

const { chat }            = require('../services/llm-router');
const SessionManager      = require('../services/session-manager');
const { executeToolCall } = require('../tools/executor');
const RetrievalService    = require('../services/rag/retrieval');
const VoiceAgent          = require('./voice-agent');

const WEB_SESSION_TTL = 60 * 60; // 1h de inactividad

class WebChatAgent {
  constructor({ db, redis }) {
    this.db        = db;
    this.sessions  = new SessionManager(redis);
    this.retrieval = new RetrievalService({ db });
  }

  /**
   * Procesa un mensaje del widget y devuelve la respuesta del bot.
   * @returns {Promise<{ reply: string, sessionId: string, ended: boolean }>}
   */
  // Acción de carrito DETERMINISTA (botón "+" del menú visual): agrega/consulta
  // sin pasar por el LLM (que a veces "finge" el add). Reusa la tool add_to_cart
  // y la misma sesión, así el bot luego ve el carrito en collectedData.
  async cartAction({ tenantId, visitorId, action = 'add', productName = null, quantity = 1 }) {
    const sessionId = `web_${tenantId}_${visitorId}`;
    let session = await this.sessions.get(sessionId);
    if (!session) {
      const agentRes = await this.db.query(
        `SELECT id FROM agents WHERE tenant_id=$1 AND is_active=true
         ORDER BY (channel='webchat') DESC, created_at ASC LIMIT 1`, [tenantId]);
      const agent = agentRes.rows[0];
      if (!agent) return { cart: { count: 0, total_cents: 0, items: [] } };
      const conv = await this.db.query(
        `INSERT INTO conversations (tenant_id, agent_id, channel, status)
         VALUES ($1,$2,'webchat','active') RETURNING id`, [tenantId, agent.id]);
      session = await this.sessions.create({
        sessionId, tenantId, agentId: agent.id, conversationId: conv.rows[0].id,
        contactPhone: null, channel: 'webchat',
      });
    }
    const name = action === 'view' ? 'view_cart' : 'add_to_cart';
    const result = await executeToolCall({
      name, input: { product_name: productName, quantity }, session, db: this.db,
    });
    if (result.collectedData) await this.sessions.updateCollectedData(sessionId, result.collectedData);
    return result; // { cart, added, speech, ... }
  }

  async handleMessage({ tenantId, visitorId, text, contactName = null }) {
    const sessionId = `web_${tenantId}_${visitorId}`;

    let session = await this.sessions.get(sessionId);
    const isNewSession = !session;

    // Agente activo del tenant (preferir uno de canal webchat, si no el primero activo)
    const agentRes = await this.db.query(
      `SELECT * FROM agents WHERE tenant_id=$1 AND is_active=true
       ORDER BY (channel='webchat') DESC, created_at ASC LIMIT 1`,
      [tenantId]
    );
    const agent = agentRes.rows[0];
    if (!agent) {
      return { reply: 'El asistente no está disponible en este momento.', sessionId, ended: true };
    }

    // Crear sesión + conversación nuevas
    if (isNewSession) {
      const conv = await this.db.query(
        `INSERT INTO conversations (tenant_id, agent_id, contact_name, channel, status)
         VALUES ($1,$2,$3,'webchat','active') RETURNING id`,
        [tenantId, agent.id, contactName]
      );
      session = await this.sessions.create({
        sessionId,
        tenantId,
        agentId:        agent.id,
        conversationId: conv.rows[0].id,
        contactPhone:   null,
        channel:        'webchat',
      });
    }

    // Persistir mensaje del visitante
    await this.sessions.addMessage(sessionId, { role: 'user', content: text });
    await this._persist(session.conversationId, tenantId, { role: 'user', content: text });

    // RAG: contexto relevante de la base de conocimiento
    const rag = await this.retrieval.getContext({
      tenantId, query: text, agentId: agent.id, topK: 3,
    }).catch(() => ({ context: null }));

    const messages     = await this.sessions.getMessagesForLLM(sessionId);
    const systemPrompt = this._buildSystemPrompt(agent, session.collectedData, rag.context, isNewSession);

    // Primera llamada al LLM (con tools). Pasamos el agente para que las tools
    // se filtren por vertical (una inmobiliaria no ve carrito/delivery, etc.).
    let llmResult = await chat({
      systemPrompt,
      messages,
      tools: this._getAgentTools(agent),
    });
    let responseText   = llmResult.content;
    let lastToolResult = null;

    // Ejecutar tools si las invocó
    if (llmResult.toolCalls?.length > 0) {
      for (const tc of llmResult.toolCalls) {
        try {
          const result = await executeToolCall({ name: tc.name, input: tc.input, session, db: this.db });
          lastToolResult = result;
          if (result.collectedData) {
            await this.sessions.updateCollectedData(sessionId, result.collectedData);
            session = await this.sessions.get(sessionId);
          }
          await this._persist(session.conversationId, tenantId, {
            role: 'tool', content: JSON.stringify(result),
            toolName: tc.name, toolInput: tc.input, toolOutput: result,
          });
        } catch (e) {
          log.error(`[WebAgent] Tool ${tc.name} falló:`, e.message);
        }
      }

      if (lastToolResult?.cards?.length) {
        // Hay tarjetas visuales → NO repetir el listado en texto. Conserva la
        // intro natural del LLM si la dio; si no, una breve neutra.
        responseText = (responseText && responseText.trim()) ? responseText : 'Esto es lo que tenemos 😊';
      } else if (lastToolResult?.speech) {
        responseText = lastToolResult.speech;
      } else {
        const second = await chat({
          systemPrompt,
          messages: [
            ...messages,
            { role: 'assistant', content: responseText || '' },
            { role: 'user', content: `[RESULTADO_TOOL: ${JSON.stringify(lastToolResult)}]` },
          ],
        });
        responseText = second.content;
        llmResult    = second;
      }
    }

    if (!responseText) responseText = 'Disculpa, ¿podrías repetir tu pregunta?';

    // Persistir respuesta
    await this.sessions.addMessage(sessionId, {
      role: 'assistant', content: responseText,
      tokensUsed: llmResult.tokensUsed, latencyMs: llmResult.latencyMs,
    });
    await this._persist(session.conversationId, tenantId, {
      role: 'assistant', content: responseText,
      tokensUsed: llmResult.tokensUsed, latencyMs: llmResult.latencyMs,
    });

    const ended = this._detectEnd(responseText);
    if (ended) {
      await this.db.query(
        `UPDATE conversations SET status='completed', ended_at=NOW() WHERE id=$1`,
        [session.conversationId]
      );
      const { analyzeInBackground } = require('../services/conversation-analyzer');
      analyzeInBackground(this.db, session.conversationId);
      await this.sessions.close(sessionId);
    }

    // `cards` (menú con foto) y `cart` (resumen del carrito) viajan al front
    // para render visual (tarjetas + barra de carrito).
    return {
      reply: responseText, sessionId, ended,
      cards: lastToolResult?.cards || null,
      cart:  lastToolResult?.cart  || null,
    };
  }

  // ─── Privados ────────────────────────────────────────────────

  _buildSystemPrompt(agent, collectedData = {}, ragContext = null, isNewSession = false) {
    const base    = agent.system_prompt || 'Eres un asistente de atención al cliente amable y profesional.';
    const dataCtx = Object.keys(collectedData || {}).length > 0
      ? `\n\nDatos recolectados en esta conversación:\n${JSON.stringify(collectedData, null, 2)}`
      : '';
    const ragSection = ragContext
      ? `\n\nINFORMACIÓN RELEVANTE DE LA BASE DE CONOCIMIENTO:\n${ragContext}\n\nUsa esta información para responder con precisión. Si no está en el contexto, responde con lo que sabes o pide más detalles.`
      : '';
    const webInstructions = `

INSTRUCCIONES PARA CHAT WEB (tienen PRIORIDAD sobre cualquier regla anterior):
- Estás en un CHAT DE TEXTO en el sitio web, NO en una llamada telefónica.
  Ignora cualquier instrucción previa que asuma una llamada.
- MINIMIZA LA FRICCIÓN:
  • Para agendar: PRIMERO muestra la disponibilidad (check_availability). Pide
    nombre y teléfono SOLO cuando el cliente ya eligió un horario, no al inicio.
  • Si el cliente YA te dio un dato (nombre, teléfono, fecha, producto), NO se lo
    vuelvas a preguntar. Úsalo directamente.
  • Si te da varios datos juntos, tómalos TODOS de una vez; no los pidas uno por uno.
  • Si lo que pide no está disponible (p. ej. pidió la tarde y solo hay mañana),
    díselo de inmediato y ofrece alternativas, no lo dejes adivinar.
- Respuestas MUY concisas: 1-3 oraciones cortas, sin párrafos largos ni muros de texto. Una sola idea o pregunta por turno. Tono cálido, emojis con moderación.
- MENÚ/CATÁLOGO/PRODUCTOS: cuando el cliente pida ver el menú, la carta, los productos, los precios o "qué tienen", USA SIEMPRE la herramienta search_products (sin filtros para mostrar todo). El chat los muestra con FOTO automáticamente, así que NO los enlistes en texto: solo da una frase breve de presentación (ej. "¡Claro! Aquí está nuestro menú 😊") y deja que las tarjetas hagan el resto.
- No inventes información que no tengas en tu conocimiento.`;
    const newSessionCtx = isNewSession
      ? '\n\nEste es el inicio de la conversación. Saluda con calidez y pregunta en qué puedes ayudar.'
      : '';
    return base + dataCtx + ragSection + webInstructions + newSessionCtx;
  }

  _getAgentTools(agent) {
    // Mismo set de tools que VoiceAgent/WhatsApp (scheduling, save_lead, etc.),
    // filtrado por vertical según la industria del agente.
    return VoiceAgent.prototype._getAgentTools.call({}, agent);
  }

  _detectEnd(text) {
    const lower = (text || '').toLowerCase();
    return ['hasta luego', 'fue un placer', 'que tengas', 'nos vemos', 'buen día', 'buenas noches']
      .some(p => lower.includes(p));
  }

  async _persist(conversationId, tenantId, { role, content, toolName, toolInput, toolOutput, tokensUsed = 0, latencyMs = 0 }) {
    try {
      await this.db.query(
        `INSERT INTO messages (conversation_id, tenant_id, role, content, tool_name, tool_input, tool_output, tokens_used, latency_ms)
         VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9)`,
        [conversationId, tenantId, role, content,
         toolName || null,
         toolInput  ? JSON.stringify(toolInput)  : null,
         toolOutput ? JSON.stringify(toolOutput) : null,
         tokensUsed, latencyMs]
      );
    } catch (err) {
      log.error('[WebAgent] Error persistiendo mensaje:', err.message);
    }
  }
}

module.exports = WebChatAgent;
