'use strict';


const { logger } = require('../services/logger');
const log = logger('WhatsAppAgent');
/**
 * WhatsAppAgent — Fase 3
 *
 * Reutiliza el motor completo de Fase 1/2:
 * - Mismo LLM Router
 * - Mismas tools (scheduling, save_lead, etc.)
 * - Mismo SessionManager en Redis
 * - Mismo VoiceAgent como base
 *
 * Diferencias vs VoiceAgent:
 * - Sin STT/TTS (texto directo)
 * - Ventana de sesión de 24h (no duración de llamada)
 * - Mensajes enriquecidos: botones, listas, imágenes
 * - Sistema de prompt diferente (más detallado, puede usar listas)
 * - Manejo de media entrante (imágenes, documentos)
 */

const { chat }          = require('../services/llm-router');
const SessionManager    = require('../services/session-manager');
const { executeToolCall } = require('../tools/executor');
const { createMetaClient } = require('../services/whatsapp/meta-client');
const { sendAgentResponse, buttonResponseToText, listResponseToText } = require('../services/whatsapp/response-builder');

// Ventana de sesión WhatsApp: 24h (ventana de conversación de Meta)
const WA_SESSION_TTL = 24 * 60 * 60;

class WhatsAppAgent {
  constructor({ db, redis }) {
    this.db       = db;
    this.sessions = new SessionManager(redis);
    // Override TTL para WhatsApp
    this.sessions._waTtl = WA_SESSION_TTL;
  }

  /**
   * Procesar mensaje entrante de WhatsApp
   *
   * @param {Object} parsedMessage - Mensaje normalizado por message-parser.js
   * @param {string} tenantId
   * @returns {Promise<void>}
   */
  async handleMessage(parsedMessage, tenantId) {
    const { from, text, messageId, isButton, isList, buttonId, listRowId, contactName } = parsedMessage;

    // Sesión identificada por número de teléfono + tenant
    const sessionId = `wa_${tenantId}_${from}`;

    // Cargar o crear sesión
    let session = await this.sessions.get(sessionId);
    const isNewSession = !session;

    // Cargar agente del tenant para WhatsApp
    const agentResult = await this.db.query(
      `SELECT * FROM agents
       WHERE tenant_id = $1 AND channel = 'whatsapp' AND is_active = true
       LIMIT 1`,
      [tenantId]
    );

    // Fallback: usar primer agente activo del tenant
    const agent = agentResult.rows[0] || await this._getFallbackAgent(tenantId);
    if (!agent) {
      log.error(`[WAAgent] No hay agente WhatsApp para tenant ${tenantId}`);
      return;
    }

    // Crear sesión nueva si no existe
    if (isNewSession) {
      const convResult = await this.db.query(
        `INSERT INTO conversations (tenant_id, agent_id, contact_phone, contact_name, channel, status)
         VALUES ($1, $2, $3, $4, 'whatsapp', 'active') RETURNING id`,
        [tenantId, agent.id, from, contactName || null]
      );

      session = await this.sessions.create({
        sessionId,
        tenantId,
        agentId:        agent.id,
        conversationId: convResult.rows[0].id,
        contactPhone:   from,
        channel:        'whatsapp',
      });
    }

    // Crear cliente Meta para este tenant
    const tenantResult = await this.db.query(
      'SELECT settings, timezone FROM tenants WHERE id = $1',
      [tenantId]
    );
    const tenantSettings = tenantResult.rows[0]?.settings || {};
    const metaClient     = createMetaClient(tenantSettings) || createMetaClient({});

    if (!metaClient) {
      log.error(`[WAAgent] No hay config Meta para tenant ${tenantId}`);
      return;
    }

    // Convertir respuestas de botones/listas a texto para el LLM
    let userText = text;
    if (isButton && buttonId)  userText = buttonResponseToText(buttonId, text);
    if (isList  && listRowId)  userText = listResponseToText(listRowId, text, parsedMessage.listDescription);

    // Guardar mensaje del usuario en sesión y DB
    await this.sessions.addMessage(sessionId, { role: 'user', content: userText });
    await this._persistMessage(session.conversationId, tenantId, { role: 'user', content: userText });

    // Obtener historial para el LLM
    const messages = await this.sessions.getMessagesForLLM(sessionId);

    // System prompt adaptado para WhatsApp (más rico que el de voz)
    const systemPrompt = this._buildSystemPrompt(agent, session.collectedData, isNewSession);

    // Llamar al LLM
    let llmResult = await chat({
      systemPrompt,
      messages,
      tools: this._getAgentTools(agent),
    });

    let responseText = llmResult.content;
    let lastToolName = null;
    let lastToolResult = null;

    // Ejecutar tools si el LLM las invocó
    if (llmResult.toolCalls?.length > 0) {
      for (const toolCall of llmResult.toolCalls) {
        try {
          const result = await executeToolCall({
            name:   toolCall.name,
            input:  toolCall.input,
            session,
            db:     this.db,
          });

          lastToolName   = toolCall.name;
          lastToolResult = result;

          if (result.collectedData) {
            await this.sessions.updateCollectedData(sessionId, result.collectedData);
            // Recargar session con datos actualizados
            session = await this.sessions.get(sessionId);
          }

          await this._persistMessage(session.conversationId, tenantId, {
            role:       'tool',
            content:    JSON.stringify(result),
            toolName:   toolCall.name,
            toolInput:  toolCall.input,
            toolOutput: result,
          });

        } catch (toolErr) {
          log.error(`[WAAgent] Tool ${toolCall.name} falló:`, toolErr.message);
        }
      }

      // Si el tool retorna un `speech` listo, usarlo directamente
      if (lastToolResult?.speech) {
        responseText = lastToolResult.speech;
      } else {
        // Segunda llamada al LLM con resultados del tool
        const toolMessages = [
          ...messages,
          { role: 'assistant', content: responseText || '' },
          { role: 'user', content: `[RESULTADO_TOOL: ${JSON.stringify(lastToolResult)}]` },
        ];
        const secondResult = await chat({
          systemPrompt,
          messages: toolMessages,
          forceModel: agent.llm_model,
        });
        responseText = secondResult.content;
        llmResult    = secondResult;
      }
    }

    // Guardar respuesta del asistente
    await this.sessions.addMessage(sessionId, {
      role:       'assistant',
      content:    responseText,
      tokensUsed: llmResult.tokensUsed,
      latencyMs:  llmResult.latencyMs,
    });

    await this._persistMessage(session.conversationId, tenantId, {
      role:       'assistant',
      content:    responseText,
      tokensUsed: llmResult.tokensUsed,
      latencyMs:  llmResult.latencyMs,
    });

    // Enviar respuesta enriquecida a WhatsApp
    await sendAgentResponse({
      text:       responseText,
      toolName:   lastToolName,
      toolResult: lastToolResult,
      session,
      metaClient,
      to:         from,
    });

    // Detectar fin de conversación
    if (this._detectEnd(responseText)) {
      await this._closeConversation(sessionId, session.conversationId, tenantId, lastToolResult?.outcome);
    }
  }

  // ─── Privados ────────────────────────────────────────────────

  _buildSystemPrompt(agent, collectedData = {}, isNewSession = false) {
    const base = agent.system_prompt;
    const dataCtx = Object.keys(collectedData).length > 0
      ? `\n\nDatos recolectados:\n${JSON.stringify(collectedData, null, 2)}`
      : '';

    const waInstructions = `

INSTRUCCIONES PARA WHATSAPP (tienen PRIORIDAD sobre cualquier regla anterior):
- Eres un asistente de TEXTO, NO de voz. Ignora reglas que asuman una llamada.
- Usa *negrita* para destacar. Nunca uses headers markdown (#).
- Respuestas MUY concisas: 1-3 oraciones cortas por mensaje, sin párrafos largos. Una sola idea o pregunta por turno.
- MINIMIZA LA FRICCIÓN:
  • Para agendar: PRIMERO muestra disponibilidad (check_availability). Pide
    nombre y teléfono SOLO cuando el cliente ya eligió un horario, no al inicio.
  • Si el cliente YA dio un dato (nombre, teléfono, fecha, producto), NO lo
    vuelvas a preguntar — úsalo. Si da varios datos juntos, tómalos todos.
  • Si lo que pide no está disponible, díselo de inmediato y ofrece alternativas.
- Cuando ofrezcas opciones u horarios, el sistema los muestra como botones/lista.
- Si el usuario envía una imagen o documento, acusa de recibo y pregunta cómo ayudar.
- Emojis con moderación. Si dice "gracias" o "adiós", despídete amablemente.`;

    const newSessionCtx = isNewSession
      ? '\n\nEste es el inicio de la conversación. Saluda de forma cálida y pregunta en qué puedes ayudar.'
      : '';

    return base + dataCtx + waInstructions + newSessionCtx;
  }

  _getAgentTools(agent) {
    // Reutiliza el mismo set de tools que VoiceAgent (Fase 1 + Fase 2),
    // filtrado por vertical según la industria del agente.
    const VoiceAgent = require('./voice-agent');
    return VoiceAgent.prototype._getAgentTools.call({}, agent);
  }

  _detectEnd(text) {
    const lower = text.toLowerCase();
    const endPhrases = ['hasta luego', 'fue un placer', 'que tengas', 'adiós', 'nos vemos', 'buen día', 'buenas noches'];
    return endPhrases.some(p => lower.includes(p));
  }

  async _getFallbackAgent(tenantId) {
    const result = await this.db.query(
      'SELECT * FROM agents WHERE tenant_id = $1 AND is_active = true ORDER BY created_at ASC LIMIT 1',
      [tenantId]
    );
    return result.rows[0] || null;
  }

  async _persistMessage(conversationId, tenantId, { role, content, toolName, toolInput, toolOutput, tokensUsed = 0, latencyMs = 0 }) {
    try {
      await this.db.query(
        `INSERT INTO messages (conversation_id, tenant_id, role, content, tool_name, tool_input, tool_output, tokens_used, latency_ms)
         VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9)`,
        [conversationId, tenantId, role, content,
         toolName  || null,
         toolInput  ? JSON.stringify(toolInput)  : null,
         toolOutput ? JSON.stringify(toolOutput) : null,
         tokensUsed, latencyMs]
      );
    } catch (err) {
      log.error('[WAAgent] Error persistiendo mensaje:', err.message);
    }
  }

  async _closeConversation(sessionId, conversationId, tenantId, outcome) {
    try {
      await this.db.query(
        `UPDATE conversations SET status='completed', ended_at=NOW(), outcome=$1 WHERE id=$2`,
        [outcome || 'completed', conversationId]
      );
      await this.sessions.close(sessionId);

      // Voz del cliente: analizar la conversación en background (non-fatal)
      const { analyzeInBackground } = require('../services/conversation-analyzer');
      analyzeInBackground(this.db, conversationId);
    } catch (err) {
      log.error('[WAAgent] Error cerrando conversación:', err.message);
    }
  }
}

module.exports = WhatsAppAgent;
