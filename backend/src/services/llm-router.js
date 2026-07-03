'use strict';

/**
 * LLM Router — selecciona el modelo óptimo por tipo de tarea
 * 
 * Regla de oro: 70% de queries van al modelo barato (GPT-4o mini).
 * Solo escalar a Claude Sonnet para negociación/ventas complejas.
 * 
 * Ahorro promedio: 60-75% vs usar siempre el modelo caro.
 */

const OpenAI = require('openai');
const Anthropic = require('@anthropic-ai/sdk');

// maxRetries bajo + timeout corto: si OpenAI está caído o sin cuota (429),
// fallamos rápido y caemos a Claude en <1s en vez de quemar 6-8s en reintentos
// con backoff. Crítico para latencia de voz (presupuesto de ~13s por turno).
const openai = new OpenAI({ apiKey: process.env.OPENAI_API_KEY, maxRetries: 1, timeout: 8000 });
const anthropic = new Anthropic({ apiKey: process.env.ANTHROPIC_API_KEY });

// Modelos Claude actuales. claude-sonnet-4-20250514 fue descontinuado (404).
// Se puede override por .env sin tocar código.
// FAST = Haiku (baja latencia) para saludos/FAQ/default/qualify y el resumen
// post-herramienta. SMART = Sonnet para ventas/quejas/agenda compleja.
// Si Haiku no estuviera disponible, el catch escala a SMART automáticamente.
const MODEL_FAST  = process.env.LLM_MODEL_FAST  || 'claude-haiku-4-5-20251001';
const MODEL_SMART = process.env.LLM_MODEL_SMART || 'claude-sonnet-4-6';
// Fallback CRUZADO de proveedor: si Anthropic muere (sin créditos, caído),
// intentamos una vez con OpenAI (y viceversa). Evita que el bot quede mudo
// por una sola cuenta agotada.
const MODEL_XFALL = process.env.LLM_MODEL_CROSS_FALLBACK || 'gpt-4o-mini';

/** Mensajes en formato Anthropic (bloques tool_use/tool_result) → texto plano
 *  compatible con OpenAI. Solo se usa en el fallback de emergencia. */
function sanitizeForOpenAI(messages) {
  return messages.map((m) => {
    if (typeof m.content === 'string') return { role: m.role, content: m.content };
    if (Array.isArray(m.content)) {
      const text = m.content.map((b) => {
        if (b.type === 'text') return b.text;
        if (b.type === 'tool_use') return `[Acción: ${b.name}(${JSON.stringify(b.input)})]`;
        if (b.type === 'tool_result') return `[Resultado: ${typeof b.content === 'string' ? b.content : JSON.stringify(b.content)}]`;
        return '';
      }).filter(Boolean).join('\n');
      return { role: m.role === 'tool' ? 'user' : m.role, content: text || '…' };
    }
    return { role: m.role, content: String(m.content ?? '') };
  }).filter(m => m.content);
}

const TASK_MODELS = {
  faq:        MODEL_FAST,
  greeting:   MODEL_FAST,
  schedule:   MODEL_SMART,
  qualify:    MODEL_FAST,
  sales:      MODEL_SMART,
  complaint:  MODEL_SMART,
  complex:    MODEL_SMART,
  default:    MODEL_FAST,
};

/**
 * Clasifica el intent del mensaje para seleccionar el modelo correcto
 * Usa heurísticas simples — no gasta tokens en clasificar
 */
function classifyIntent(userMessage, conversationHistory = []) {
  const msg = userMessage.toLowerCase();

  // Señales de complejidad alta → modelo caro
  const complexSignals = [
    'precio', 'descuento', 'oferta', 'negoci', 'problema', 'queja',
    'molest', 'enojad', 'mal servicio', 'cancelar', 'reembolso',
    'comparar', 'competencia', 'por qué debería', 'convénc'
  ];

  // Si la conversación ya tiene 6+ turnos, probablemente es compleja
  const isLongConversation = conversationHistory.length > 6;

  if (complexSignals.some(s => msg.includes(s)) || isLongConversation) {
    return 'sales';
  }

  if (msg.includes('agendar') || msg.includes('cita') || msg.includes('reservar') ||
      msg.includes('disponib') || msg.includes('horario libre')) {
    return 'schedule';
  }

  return 'default';
}

/**
 * Chat completion con routing automático de modelo
 * 
 * @param {Object} params
 * @param {string} params.systemPrompt - System prompt del agente
 * @param {Array}  params.messages - Historial de conversación [{role, content}]
 * @param {Array}  params.tools - Function calling tools (opcional)
 * @param {string} params.forceModel - Forzar modelo específico (opcional)
 * @param {string} params.taskType - Tipo de tarea para routing (opcional)
 * @returns {Object} { content, toolCalls, model, tokensUsed, latencyMs }
 */
async function chat({ systemPrompt, systemDynamic = '', messages, tools = [], forceModel, taskType, _noCrossFallback = false }) {
  const startTime = Date.now();

  // Determinar modelo
  // content puede ser string (OpenAI) o array de bloques (Anthropic tool_result format)
  const lastUserContent = messages.filter(m => m.role === 'user').pop()?.content || '';
  const lastUserMsg = typeof lastUserContent === 'string'
    ? lastUserContent
    : Array.isArray(lastUserContent)
      ? lastUserContent.filter(b => b.type === 'text').map(b => b.text).join(' ')
      : '';
  const intent = taskType || classifyIntent(lastUserMsg, messages);
  const model = forceModel || TASK_MODELS[intent] || TASK_MODELS.default;

  try {
    let content = '';
    let toolCalls = [];
    let tokensUsed = 0;

    if (model.startsWith('claude')) {
      // --- Anthropic ---
      const response = await anthropic.messages.create({
        model,
        max_tokens: 512,  // voz: respuestas cortas, no necesita más
        system: cachedSystem(systemPrompt, systemDynamic),
        messages,
        tools: cachedTools(tools),
      });

      tokensUsed = response.usage.input_tokens + response.usage.output_tokens;

      for (const block of response.content) {
        if (block.type === 'text') content += block.text;
        if (block.type === 'tool_use') {
          toolCalls.push({ name: block.name, input: block.input, id: block.id });
        }
      }

    } else {
      // --- OpenAI ---
      const params = {
        model,
        messages: [{ role: 'system', content: (systemPrompt || '') + (systemDynamic || '') }, ...sanitizeForOpenAI(messages)],
        max_tokens: 400,   // voz/chat: respuestas cortas; menos tokens = menor latencia
        temperature: 0.7,
      };

      if (tools.length > 0) {
        params.tools = tools;
        params.tool_choice = 'auto';
      }

      const response = await openai.chat.completions.create(params);
      const choice = response.choices[0];

      content = choice.message.content || '';
      tokensUsed = response.usage.total_tokens;

      if (choice.message.tool_calls) {
        toolCalls = choice.message.tool_calls.map(tc => ({
          name: tc.function.name,
          input: JSON.parse(tc.function.arguments),
          id: tc.id,
        }));
      }
    }

    return {
      content,
      toolCalls,
      model,
      intent,
      tokensUsed,
      latencyMs: Date.now() - startTime,
    };

  } catch (err) {
    const isNotFound   = err.status === 404 || err.message?.includes('not_found');
    const isRateLimit  = err.status === 429;
    const isNotSonnet  = model !== MODEL_SMART;

    // Si el modelo rápido (Haiku) falló por deprecado o rate-limit → Sonnet
    if (model.startsWith('claude') && (isNotFound || isRateLimit) && isNotSonnet && !_noCrossFallback) {
      console.warn(`[LLMRouter] ${model} no disponible (${err.status}), escalando a ${MODEL_SMART}`);
      return chat({ systemPrompt, systemDynamic, messages, tools, forceModel: MODEL_SMART });
    }

    // FALLBACK CRUZADO de proveedor (una sola vez, sin recursión infinita):
    // cualquier fallo restante de un proveedor → intentar con el otro.
    // Cubre créditos agotados (400 billing / 402), 401, 429, 5xx y caídas.
    if (!_noCrossFallback) {
      const other = model.startsWith('claude') ? MODEL_XFALL : MODEL_SMART;
      const hasKey = other.startsWith('claude') ? !!process.env.ANTHROPIC_API_KEY : !!process.env.OPENAI_API_KEY;
      if (hasKey && other !== model) {
        console.error(`[LLMRouter] ${model} falló (${err.status || 'net'}), fallback cruzado a ${other}:`, err.message?.slice(0, 120));
        return chat({ systemPrompt, systemDynamic, messages, tools, forceModel: other, _noCrossFallback: true });
      }
    }

    console.error(`[LLMRouter] ${model} falló sin fallback:`, err.message);
    throw err;
  }
}

/**
 * Igual que chat() pero con STREAMING de texto (Anthropic). Llama onText(delta)
 * a medida que llegan los tokens, para que el caller pueda sintetizar TTS por
 * frases y empezar a hablar antes de terminar de generar.
 * Para modelos OpenAI (o tras fallback) cae a chat() y emite el texto completo.
 *
 * @returns {Object} { content, toolCalls, model, tokensUsed, latencyMs }
 */
async function chatStream({ systemPrompt, systemDynamic = '', messages, tools = [], forceModel, taskType, onText }) {
  const startTime = Date.now();
  const lastUserContent = messages.filter(m => m.role === 'user').pop()?.content || '';
  const lastUserMsg = typeof lastUserContent === 'string'
    ? lastUserContent
    : Array.isArray(lastUserContent)
      ? lastUserContent.filter(b => b.type === 'text').map(b => b.text).join(' ')
      : '';
  const intent = taskType || classifyIntent(lastUserMsg, messages);
  const model = forceModel || TASK_MODELS[intent] || TASK_MODELS.default;

  // Sin soporte de streaming para OpenAI aquí → respuesta completa
  if (!model.startsWith('claude')) {
    const r = await chat({ systemPrompt, systemDynamic, messages, tools, forceModel: model, taskType });
    if (r.content && onText) onText(r.content);
    return r;
  }

  try {
    const stream = anthropic.messages.stream({
      model,
      max_tokens: 400,
      system: cachedSystem(systemPrompt, systemDynamic),
      messages,
      tools: cachedTools(tools),
    });

    if (onText) stream.on('text', (delta) => onText(delta));

    const final = await stream.finalMessage();
    const u = final.usage || {};
    if (u.cache_read_input_tokens || u.cache_creation_input_tokens)
      console.log(`[LLM] caché: ${u.cache_read_input_tokens||0} leídos / ${u.cache_creation_input_tokens||0} escritos / ${u.input_tokens||0} nuevos`);
    let content = '';
    const toolCalls = [];
    for (const block of final.content) {
      if (block.type === 'text') content += block.text;
      if (block.type === 'tool_use') toolCalls.push({ name: block.name, input: block.input, id: block.id });
    }
    return {
      content, toolCalls, model, intent,
      tokensUsed: (final.usage?.input_tokens || 0) + (final.usage?.output_tokens || 0),
      latencyMs: Date.now() - startTime,
    };
  } catch (err) {
    const isNotFound  = err.status === 404 || err.message?.includes('not_found');
    const isRateLimit = err.status === 429;
    if ((isNotFound || isRateLimit) && model !== MODEL_SMART) {
      console.warn(`[LLMRouter] stream ${model} no disponible (${err.status}), escalando a ${MODEL_SMART}`);
      return chatStream({ systemPrompt, systemDynamic, messages, tools, forceModel: MODEL_SMART, onText });
    }
    // Último recurso: no-streaming
    console.error(`[LLMRouter] stream ${model} falló, fallback no-streaming:`, err.message);
    const r = await chat({ systemPrompt, systemDynamic, messages, tools, forceModel: MODEL_SMART });
    if (r.content && onText) onText(r.content);
    return r;
  }
}

// ── Prompt caching (Anthropic) ──────────────────────────────
// El system prompt (con RAG + FAQs + datos) y las herramientas se reenvían
// IGUAL en cada turno de la llamada → los cacheamos (cache_control ephemeral,
// TTL 5 min) para pagar ~90% menos por esos tokens de entrada repetidos.
// Es la palanca #1 de costo de LLM en voz.
// systemStatic = parte ESTABLE (negocio, FAQs, voz, herramientas) → cacheada.
// systemDynamic = parte VARIABLE (datos recolectados, RAG) → sin caché, va
// después del breakpoint para no romper el prefijo cacheado.
function cachedSystem(systemStatic, systemDynamic) {
  const blocks = [{ type: 'text', text: systemStatic || '', cache_control: { type: 'ephemeral' } }];
  if (systemDynamic && systemDynamic.trim()) blocks.push({ type: 'text', text: systemDynamic });
  return blocks;
}
function cachedTools(tools) {
  if (!tools || !tools.length) return undefined;
  const arr = tools.map(adaptToolForAnthropic);
  arr[arr.length - 1] = { ...arr[arr.length - 1], cache_control: { type: 'ephemeral' } };
  return arr;
}

// Adaptar formato de tools de OpenAI a Anthropic
function adaptToolForAnthropic(tool) {
  return {
    name: tool.function.name,
    description: tool.function.description,
    input_schema: tool.function.parameters,
  };
}

module.exports = { chat, chatStream, classifyIntent, TASK_MODELS };
