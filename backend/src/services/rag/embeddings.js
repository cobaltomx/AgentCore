'use strict';

/**
 * Embeddings Service — Fase 5
 *
 * Genera vectores de embeddings usando OpenAI text-embedding-3-small.
 * Modelo elegido por mejor relación precio/calidad para RAG en español:
 *   - 1536 dimensiones
 *   - $0.02 por millón de tokens (muy barato)
 *   - Excelente para búsqueda semántica en español
 */

const OpenAI = require('openai');

const openai = new OpenAI({ apiKey: process.env.OPENAI_API_KEY });

const EMBEDDING_MODEL   = 'text-embedding-3-small';
const EMBEDDING_DIMS    = 1536;
const MAX_TOKENS_CHUNK  = 500;    // tokens por chunk (aprox 375 palabras)
const CHUNK_OVERLAP     = 50;     // tokens de overlap entre chunks
const BATCH_SIZE        = 100;    // máximo embeddings por request a OpenAI

/**
 * Generar embedding para un texto
 * @param {string} text
 * @returns {number[]} vector de 1536 dimensiones
 */
async function embedText(text) {
  const clean = cleanTextForEmbedding(text);
  if (!clean) throw new Error('Texto vacío para embedding');

  const response = await openai.embeddings.create({
    model: EMBEDDING_MODEL,
    input: clean,
  });

  return response.data[0].embedding;
}

/**
 * Generar embeddings para múltiples textos en batch
 * Más eficiente y barato que llamadas individuales
 * @param {string[]} texts
 * @returns {number[][]} array de vectores
 */
async function embedBatch(texts) {
  const cleaned = texts.map(cleanTextForEmbedding).filter(Boolean);
  if (cleaned.length === 0) return [];

  // Procesar en batches de BATCH_SIZE
  const allEmbeddings = [];

  for (let i = 0; i < cleaned.length; i += BATCH_SIZE) {
    const batch = cleaned.slice(i, i + BATCH_SIZE);

    const response = await openai.embeddings.create({
      model: EMBEDDING_MODEL,
      input: batch,
    });

    allEmbeddings.push(...response.data.map(d => d.embedding));

    // Rate limit: pequeña pausa entre batches grandes
    if (i + BATCH_SIZE < cleaned.length) {
      await new Promise(r => setTimeout(r, 100));
    }
  }

  return allEmbeddings;
}

/**
 * Divide un texto largo en chunks con overlap
 * Estrategia: dividir por párrafos → unir hasta MAX_TOKENS → overlap
 *
 * @param {string} text        - Texto completo del documento
 * @param {Object} metadata    - Metadata del documento (para heredar a chunks)
 * @returns {Array} chunks [{ content, chunkIndex, tokenCount, heading }]
 */
function chunkText(text, metadata = {}) {
  if (!text || text.length < 10) return [];

  const chunks    = [];
  const paragraphs = splitIntoParagraphs(text);

  let currentChunk  = '';
  let currentTokens = 0;
  let chunkIndex    = 0;
  let currentHeading = metadata.heading || '';

  for (const para of paragraphs) {
    // Detectar headings para metadata
    if (para.match(/^#{1,3}\s/) || para.match(/^[A-ZÁÉÍÓÚ][A-ZÁÉÍÓÚ\s]{3,30}:?\s*$/)) {
      currentHeading = para.replace(/^#+\s*/, '').trim();
    }

    const paraTokens = estimateTokens(para);

    // Si el párrafo solo es demasiado grande, dividirlo por oraciones
    if (paraTokens > MAX_TOKENS_CHUNK) {
      if (currentChunk) {
        chunks.push(makeChunk(currentChunk, chunkIndex++, currentHeading));
        currentChunk  = '';
        currentTokens = 0;
      }

      const sentences = splitIntoSentences(para);
      let sentChunk   = '';
      let sentTokens  = 0;

      for (const sentence of sentences) {
        const stokens = estimateTokens(sentence);
        if (sentTokens + stokens > MAX_TOKENS_CHUNK && sentChunk) {
          chunks.push(makeChunk(sentChunk, chunkIndex++, currentHeading));
          // Overlap: conservar última oración
          const lastSentence = sentChunk.split(/[.!?]\s+/).pop() || '';
          sentChunk  = lastSentence + ' ';
          sentTokens = estimateTokens(sentChunk);
        }
        sentChunk  += sentence + ' ';
        sentTokens += stokens;
      }

      if (sentChunk.trim()) {
        chunks.push(makeChunk(sentChunk, chunkIndex++, currentHeading));
      }
      continue;
    }

    // Agregar párrafo al chunk actual
    if (currentTokens + paraTokens > MAX_TOKENS_CHUNK && currentChunk) {
      chunks.push(makeChunk(currentChunk, chunkIndex++, currentHeading));

      // Overlap: últimas N palabras del chunk anterior
      const words     = currentChunk.split(' ');
      const overlapWords = words.slice(-Math.floor(CHUNK_OVERLAP * 0.75));
      currentChunk    = overlapWords.join(' ') + '\n\n';
      currentTokens   = estimateTokens(currentChunk);
    }

    currentChunk  += para + '\n\n';
    currentTokens += paraTokens;
  }

  // Último chunk
  if (currentChunk.trim()) {
    chunks.push(makeChunk(currentChunk, chunkIndex, currentHeading));
  }

  return chunks.filter(c => c.content.trim().length > 20);
}

/**
 * Estimar costo de embeddings antes de procesar
 */
function estimateCost(totalTokens) {
  const costPerMillion = 0.02; // USD
  return {
    tokens:       totalTokens,
    estimatedUSD: (totalTokens / 1_000_000) * costPerMillion,
  };
}

// ─── Helpers privados ────────────────────────────────────────

function cleanTextForEmbedding(text) {
  return (text || '')
    .replace(/\s+/g, ' ')
    .replace(/[^\w\s.,;:!?¿¡áéíóúÁÉÍÓÚñÑüÜ\-]/g, ' ')
    .trim()
    .substring(0, 8000); // límite de seguridad
}

function splitIntoParagraphs(text) {
  return text
    .split(/\n{2,}/)
    .map(p => p.trim())
    .filter(p => p.length > 0);
}

function splitIntoSentences(text) {
  return text
    .split(/(?<=[.!?])\s+/)
    .map(s => s.trim())
    .filter(s => s.length > 0);
}

// Estimación rápida: ~0.75 tokens por palabra en español
function estimateTokens(text) {
  return Math.ceil(text.split(/\s+/).length * 0.75);
}

function makeChunk(content, index, heading) {
  const clean = content.trim();
  return {
    content:    clean,
    chunkIndex: index,
    tokenCount: estimateTokens(clean),
    heading:    heading || null,
  };
}

module.exports = {
  embedText,
  embedBatch,
  chunkText,
  estimateCost,
  EMBEDDING_DIMS,
  MAX_TOKENS_CHUNK,
};
