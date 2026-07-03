'use strict';


const { logger } = require('./logger');
const log = logger('ConvAnalyzer');
/**
 * ConversationAnalyzer — "Voz del cliente"
 *
 * Al cerrar una conversación, el LLM extrae inteligencia accionable:
 *   - summary:      resumen de 1-2 líneas
 *   - sentiment:    positivo | neutral | negativo
 *   - intent:       motivo principal (agendar, precio, queja, info, soporte...)
 *   - topics:       temas mencionados
 *   - objections:   objeciones de venta detectadas (precio, competencia, dudas)
 *   - kb_gap:       true si el bot NO pudo responder algo
 *   - unanswered_question: la pregunta sin responder (si kb_gap)
 *
 * Se ejecuta en background y es non-fatal: nunca rompe el cierre de la conversación.
 */

const { chat } = require('./llm-router');

// Planes con acceso a "Voz del cliente" (debe coincidir con el frontend)
const PREMIUM_PLANS = ['growth', 'business', 'enterprise'];

const ANALYSIS_SYSTEM_PROMPT = `Eres un analista de conversaciones de atención al cliente.
Recibirás la transcripción de una conversación entre un cliente y un asistente (bot).
Devuelve ÚNICAMENTE un objeto JSON válido (sin texto adicional, sin markdown) con esta forma exacta:

{
  "summary": "resumen de 1-2 líneas de qué quería el cliente y cómo terminó",
  "sentiment": "positivo" | "neutral" | "negativo",
  "intent": "una palabra/frase corta: agendar | precio | informacion | queja | soporte | cancelar | otro",
  "topics": ["tema1", "tema2"],
  "objections": ["objeción si la hubo, ej. 'precio alto'"],
  "kb_gap": true | false,
  "unanswered_question": "la pregunta que el bot NO supo responder, o null"
}

Reglas:
- kb_gap = true SOLO si el cliente preguntó algo que el bot no pudo contestar (dijo que no sabía, evadió, o escaló).
- Si no hubo objeciones, devuelve [] en objections.
- Sé conciso. Responde en español.`;

/**
 * Analiza una conversación por su id y persiste el resultado.
 * @returns {object|null} el análisis, o null si no se pudo
 */
async function analyzeConversation(db, conversationId) {
  // Gate premium: solo analizar conversaciones de tenants con plan Growth+.
  // Se verifica ANTES de cargar mensajes/llamar al LLM para no gastar tokens.
  const planRow = await db.query(
    `SELECT t.plan FROM conversations c JOIN tenants t ON t.id = c.tenant_id WHERE c.id = $1`,
    [conversationId]
  );
  const plan = planRow.rows[0]?.plan;
  if (!plan || !PREMIUM_PLANS.includes(plan)) return null;

  // Cargar mensajes de la conversación
  const msgs = await db.query(
    `SELECT role, content FROM messages
     WHERE conversation_id = $1 AND role IN ('user','assistant')
     ORDER BY created_at ASC
     LIMIT 40`,
    [conversationId]
  );

  // Sin mensajes suficientes → no analizar (evita gasto de tokens)
  if (msgs.rows.length < 2) return null;

  const transcript = msgs.rows
    .map(m => `${m.role === 'user' ? 'Cliente' : 'Bot'}: ${m.content}`)
    .join('\n');

  let result;
  try {
    const llm = await chat({
      systemPrompt: ANALYSIS_SYSTEM_PROMPT,
      messages: [{ role: 'user', content: `Transcripción:\n\n${transcript}` }],
      taskType: 'default',  // modelo rápido/barato
    });
    result = _parseJson(llm.content);
  } catch (err) {
    log.warn('[ConvAnalyzer] LLM error:', err.message);
    return null;
  }

  if (!result) return null;

  // Normalizar
  const analysis = {
    intent:              String(result.intent || 'otro').slice(0, 40),
    topics:              Array.isArray(result.topics) ? result.topics.slice(0, 8).map(t => String(t).slice(0, 60)) : [],
    objections:          Array.isArray(result.objections) ? result.objections.slice(0, 5).map(o => String(o).slice(0, 80)) : [],
    kb_gap:              result.kb_gap === true,
    unanswered_question: result.kb_gap === true && result.unanswered_question
                           ? String(result.unanswered_question).slice(0, 200) : null,
  };
  const sentiment = ['positivo', 'neutral', 'negativo'].includes(result.sentiment)
    ? result.sentiment : 'neutral';
  const summary = String(result.summary || '').slice(0, 500);

  // Persistir
  await db.query(
    `UPDATE conversations
     SET summary = $2, sentiment = $3, analysis = $4::jsonb, analyzed_at = NOW()
     WHERE id = $1`,
    [conversationId, summary, sentiment, JSON.stringify(analysis)]
  );

  return { summary, sentiment, ...analysis };
}

/** Extrae JSON de la respuesta del LLM, tolerante a fences markdown o texto extra. */
function _parseJson(text) {
  if (!text) return null;
  try { return JSON.parse(text); } catch { /* intentar extraer */ }
  const match = text.match(/\{[\s\S]*\}/);
  if (match) {
    try { return JSON.parse(match[0]); } catch { return null; }
  }
  return null;
}

/** Dispara el análisis en background (non-fatal). Para usar en el cierre de conversación. */
function analyzeInBackground(db, conversationId) {
  setImmediate(async () => {
    try {
      await analyzeConversation(db, conversationId);
    } catch (err) {
      log.warn('[ConvAnalyzer] background error:', err.message);
    }
  });
}

module.exports = { analyzeConversation, analyzeInBackground };
