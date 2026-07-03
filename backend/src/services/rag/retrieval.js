'use strict';

/**
 * RAG Retrieval Service — Fase 5
 *
 * Búsqueda semántica sobre la knowledge base del tenant.
 * Retorna los chunks más relevantes para una query dada.
 *
 * Estrategia de retrieval:
 * 1. Embedear la query del usuario
 * 2. Búsqueda por similitud coseno en pgvector (filtrado por tenant)
 * 3. Reranking por relevancia + diversidad (evitar chunks muy similares)
 * 4. Formatear contexto para inyectar en el system prompt
 */

const { embedText } = require('./embeddings');

class RetrievalService {
  constructor({ db }) {
    this.db = db;
  }

  /**
   * Buscar chunks relevantes para una query
   *
   * @param {Object} params
   * @param {string} params.tenantId
   * @param {string} params.query        - Pregunta/texto del usuario
   * @param {string} params.agentId      - Para filtrar chunks del agente (opcional)
   * @param {number} params.topK         - Cuántos chunks retornar (default 5)
   * @param {number} params.minSimilarity - Umbral mínimo 0-1 (default 0.70)
   * @returns {Array} chunks relevantes con score
   */
  async search({ tenantId, query, agentId, topK = 5, minSimilarity = 0.70 }) {
    if (!query || query.trim().length < 3) return [];

    // 1. Embedear la query
    const queryEmbedding = await embedText(query);
    const vectorStr      = `[${queryEmbedding.join(',')}]`;

    // 2. Búsqueda vectorial con pgvector
    // La función <=> es la distancia coseno (1 - similitud)
    // Filtramos por tenant y opcionalmente por agente
    const agentFilter = agentId
      ? 'AND (c.agent_id = $4 OR c.agent_id IS NULL)'
      : '';

    const params = [vectorStr, tenantId, topK * 2]; // topK*2 para reranking
    if (agentId) params.push(agentId);

    const result = await this.db.query(
      `SELECT
         c.id,
         c.content,
         c.heading,
         c.source_type,
         c.source_url,
         c.chunk_index,
         d.title as document_title,
         1 - (c.embedding <=> $1::vector) AS similarity
       FROM kb_chunks c
       JOIN kb_documents d ON d.id = c.document_id
       WHERE c.tenant_id = $2
         AND d.status = 'ready'
         ${agentFilter}
         AND 1 - (c.embedding <=> $1::vector) >= ${minSimilarity}
       ORDER BY c.embedding <=> $1::vector
       LIMIT $3`,
      params
    );

    if (result.rows.length === 0) return [];

    // 3. Reranking: eliminar chunks muy similares entre sí (diversidad)
    const reranked = this._diversityRerank(result.rows, topK);

    return reranked;
  }

  /**
   * Buscar y formatear el contexto completo para inyectar en el LLM
   *
   * @param {Object} params - mismo que search()
   * @returns {Object} { context: string, chunks: Array, found: boolean }
   */
  async getContext({ tenantId, query, agentId, topK = 4, minSimilarity = 0.68 }) {
    const chunks = await this.search({ tenantId, query, agentId, topK, minSimilarity });

    if (chunks.length === 0) {
      return { context: null, chunks: [], found: false };
    }

    // Formatear para inyectar en el system prompt
    const contextBlocks = chunks.map((chunk, i) => {
      const source = chunk.document_title || chunk.source_url || 'Base de conocimiento';
      const heading = chunk.heading ? ` — ${chunk.heading}` : '';
      return `[Fuente ${i + 1}: ${source}${heading}]\n${chunk.content}`;
    });

    const context = contextBlocks.join('\n\n---\n\n');

    return {
      context,
      chunks,
      found:   true,
      sources: [...new Set(chunks.map(c => c.document_title).filter(Boolean))],
    };
  }

  /**
   * Verificar si la knowledge base del tenant tiene contenido listo
   */
  async hasContent(tenantId, agentId = null) {
    const result = await this.db.query(
      `SELECT COUNT(*) FROM kb_chunks c
       JOIN kb_documents d ON d.id = c.document_id
       WHERE c.tenant_id = $1 AND d.status = 'ready'
       ${agentId ? 'AND (c.agent_id = $2 OR c.agent_id IS NULL)' : ''}`,
      agentId ? [tenantId, agentId] : [tenantId]
    );
    return parseInt(result.rows[0].count) > 0;
  }

  /**
   * Stats de la KB para el dashboard
   */
  async getStats(tenantId) {
    const result = await this.db.query(
      `SELECT
         COUNT(DISTINCT d.id)  AS documents,
         COUNT(c.id)           AS chunks,
         SUM(c.token_count)    AS total_tokens,
         COUNT(DISTINCT d.file_type) AS source_types
       FROM kb_documents d
       LEFT JOIN kb_chunks c ON c.document_id = d.id
       WHERE d.tenant_id = $1 AND d.status = 'ready'`,
      [tenantId]
    );
    return result.rows[0];
  }

  // ─── Privados ────────────────────────────────────────────────

  /**
   * Reranking por diversidad: elimina chunks con contenido muy similar
   * Usa comparación simple de palabras clave (sin costo adicional)
   */
  _diversityRerank(chunks, topK) {
    const selected = [];
    const usedKeywords = new Set();

    for (const chunk of chunks) {
      // Extraer palabras clave del chunk (palabras >4 chars)
      const keywords = chunk.content
        .toLowerCase()
        .split(/\s+/)
        .filter(w => w.length > 4)
        .slice(0, 20);

      // Contar overlap con chunks ya seleccionados
      const overlap = keywords.filter(k => usedKeywords.has(k)).length;
      const overlapRatio = keywords.length > 0 ? overlap / keywords.length : 0;

      // Aceptar si overlap < 60% (no demasiado similar a lo ya seleccionado)
      if (overlapRatio < 0.6) {
        selected.push(chunk);
        keywords.forEach(k => usedKeywords.add(k));
      }

      if (selected.length >= topK) break;
    }

    // Si quedaron pocos tras el filtro, complementar con los siguientes
    if (selected.length < topK && chunks.length > selected.length) {
      for (const chunk of chunks) {
        if (!selected.find(s => s.id === chunk.id)) {
          selected.push(chunk);
          if (selected.length >= topK) break;
        }
      }
    }

    return selected;
  }
}

module.exports = RetrievalService;
