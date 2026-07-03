'use strict';

/**
 * Session Manager — maneja el estado de cada conversación en Redis
 * 
 * Cada llamada/sesión tiene un sessionId único.
 * El estado persiste en Redis durante la llamada + 1 hora post-cierre.
 * 
 * Estructura de sesión:
 * {
 *   sessionId, tenantId, agentId, conversationId,
 *   contactPhone, channel,
 *   messages: [{role, content, timestamp}],
 *   state: 'greeting' | 'collecting' | 'scheduling' | 'closing' | 'ended',
 *   collectedData: { name, email, phone, intent, ... },
 *   startedAt, lastActivityAt,
 *   callSid (Twilio)
 * }
 */

const SESSION_TTL = parseInt(process.env.REDIS_SESSION_TTL || '3600'); // 1 hora

class SessionManager {
  constructor(redis) {
    this.redis = redis;
  }

  _key(sessionId) {
    return `session:call:${sessionId}`;
  }

  /**
   * Crear nueva sesión al inicio de una llamada
   */
  async create({ sessionId, tenantId, agentId, conversationId, contactPhone, channel = 'voice', callSid }) {
    const session = {
      sessionId,
      tenantId,
      agentId,
      conversationId,
      contactPhone: contactPhone || 'unknown',
      channel,
      callSid: callSid || null,
      messages: [],
      state: 'greeting',
      collectedData: {},
      startedAt: new Date().toISOString(),
      lastActivityAt: new Date().toISOString(),
      turnCount: 0,
    };

    await this.redis.setex(this._key(sessionId), SESSION_TTL, JSON.stringify(session));
    return session;
  }

  /**
   * Obtener sesión activa
   */
  async get(sessionId) {
    const data = await this.redis.get(this._key(sessionId));
    if (!data) return null;
    return JSON.parse(data);
  }

  /**
   * Agregar mensaje al historial y actualizar estado
   */
  async addMessage(sessionId, { role, content, toolName = null, toolInput = null, toolOutput = null, tokensUsed = 0, latencyMs = 0 }) {
    const session = await this.get(sessionId);
    if (!session) throw new Error(`Sesión no encontrada: ${sessionId}`);

    session.messages.push({
      role,
      content,
      toolName,
      toolInput,
      toolOutput,
      tokensUsed,
      latencyMs,
      timestamp: new Date().toISOString(),
    });

    session.lastActivityAt = new Date().toISOString();
    session.turnCount += role === 'user' ? 1 : 0;

    // Mantener historial manejable (últimos 20 mensajes para el LLM)
    // Pero guardar todo en DB, solo limitar lo que se manda al LLM
    if (session.messages.length > 40) {
      session.messages = session.messages.slice(-40);
    }

    await this.redis.setex(this._key(sessionId), SESSION_TTL, JSON.stringify(session));
    return session;
  }

  /**
   * Actualizar datos recolectados (nombre, email, intent, etc.)
   */
  async updateCollectedData(sessionId, data) {
    const session = await this.get(sessionId);
    if (!session) throw new Error(`Sesión no encontrada: ${sessionId}`);

    session.collectedData = { ...session.collectedData, ...data };
    session.lastActivityAt = new Date().toISOString();

    await this.redis.setex(this._key(sessionId), SESSION_TTL, JSON.stringify(session));
    return session;
  }

  /**
   * Cambiar estado de la conversación
   */
  async setState(sessionId, state) {
    const session = await this.get(sessionId);
    if (!session) throw new Error(`Sesión no encontrada: ${sessionId}`);

    session.state = state;
    await this.redis.setex(this._key(sessionId), SESSION_TTL, JSON.stringify(session));
    return session;
  }

  /**
   * Obtener solo los mensajes formateados para el LLM (últimos N)
   */
  async getMessagesForLLM(sessionId, maxMessages = 20) {
    const session = await this.get(sessionId);
    if (!session) return [];

    return session.messages
      .filter(m => m.role === 'user' || m.role === 'assistant')
      .slice(-maxMessages)
      .map(m => ({ role: m.role, content: m.content }));
  }

  /**
   * Cerrar sesión (marcar como ended, extender TTL corto para cleanup)
   */
  async close(sessionId) {
    const session = await this.get(sessionId);
    if (!session) return null;

    session.state = 'ended';
    session.endedAt = new Date().toISOString();

    // Mantener 30 min más para posible análisis post-llamada
    await this.redis.setex(this._key(sessionId), 1800, JSON.stringify(session));
    return session;
  }

  /**
   * Eliminar sesión inmediatamente
   */
  async delete(sessionId) {
    await this.redis.del(this._key(sessionId));
  }
}

module.exports = SessionManager;
