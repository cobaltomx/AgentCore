'use strict';

/**
 * Document Ingestion Pipeline — Fase 5
 *
 * Flujo para cada tipo de fuente:
 *
 * TEXT/TXT  → limpiar → chunk → embed → guardar en kb_chunks
 * PDF       → extraer texto (pdf-parse) → chunk → embed → guardar
 * URL       → fetch + cheerio scraping → limpiar → chunk → embed → guardar
 * FAQ       → parsear pares Q&A → cada par = 1 chunk → embed → guardar
 *
 * El procesamiento es async y actualiza kb_documents.status:
 * pending → processing → ready (o error)
 */

const { chunkText, embedBatch, estimateCost } = require('./embeddings');

class IngestionPipeline {
  constructor({ db }) {
    this.db = db;
  }

  /**
   * Procesar un documento completo
   * Punto de entrada principal — detecta el tipo y llama al extractor correcto
   *
   * @param {string} documentId - ID de kb_documents
   * @returns {Object} { chunksCreated, tokensUsed, costUSD }
   */
  async ingestDocument(documentId) {
    // Cargar documento
    const docResult = await this.db.query(
      'SELECT * FROM kb_documents WHERE id = $1',
      [documentId]
    );
    const doc = docResult.rows[0];
    if (!doc) throw new Error(`Documento ${documentId} no encontrado`);

    // Marcar como procesando
    await this._setStatus(documentId, 'processing');

    try {
      // Extraer texto según el tipo
      let rawText = '';
      let extraMetadata = {};

      switch (doc.file_type) {
        case 'text':
        case 'txt':
          rawText = doc.content || '';
          break;

        case 'pdf':
          rawText = await this._extractPdf(doc);
          break;

        case 'url':
          const scraped = await this._scrapeUrl(doc.source_url || doc.content);
          rawText = scraped.text;
          extraMetadata = { title: scraped.title, url: scraped.url };
          break;

        case 'faq':
          rawText = this._processFaq(doc.content || '');
          break;

        default:
          rawText = doc.content || '';
      }

      if (!rawText || rawText.trim().length < 20) {
        await this._setStatus(documentId, 'error', 'Texto extraído vacío o muy corto');
        return { chunksCreated: 0, tokensUsed: 0, costUSD: 0 };
      }

      // Dividir en chunks
      const chunks = chunkText(rawText, { heading: doc.title });

      if (chunks.length === 0) {
        await this._setStatus(documentId, 'error', 'No se pudieron generar chunks');
        return { chunksCreated: 0, tokensUsed: 0, costUSD: 0 };
      }

      // Generar embeddings en batch (eficiente)
      const texts      = chunks.map(c => c.content);
      const embeddings = await embedBatch(texts);
      const totalTokens = chunks.reduce((sum, c) => sum + c.tokenCount, 0);
      const { estimatedUSD } = estimateCost(totalTokens);

      // Eliminar chunks anteriores del documento (re-ingesta)
      await this.db.query('DELETE FROM kb_chunks WHERE document_id = $1', [documentId]);

      // Insertar chunks con embeddings
      for (let i = 0; i < chunks.length; i++) {
        const chunk    = chunks[i];
        const embedding = embeddings[i];

        if (!embedding) continue;

        // Convertir array a formato pgvector: '[0.1,0.2,...]'
        const vectorStr = `[${embedding.join(',')}]`;

        await this.db.query(
          `INSERT INTO kb_chunks
             (tenant_id, document_id, agent_id, content, chunk_index, token_count,
              source_type, source_url, heading, embedding)
           VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10::vector)`,
          [
            doc.tenant_id,
            documentId,
            doc.agent_id || null,
            chunk.content,
            chunk.chunkIndex,
            chunk.tokenCount,
            doc.file_type,
            extraMetadata.url || doc.source_url || null,
            chunk.heading || null,
            vectorStr,
          ]
        );
      }

      // Actualizar estado y chunk_count
      await this.db.query(
        `UPDATE kb_documents SET status='ready', chunk_count=$1, updated_at=NOW() WHERE id=$2`,
        [chunks.length, documentId]
      );

      console.log(`[RAG] Documento ingested: ${doc.title} → ${chunks.length} chunks, ~$${estimatedUSD.toFixed(4)}`);

      return { chunksCreated: chunks.length, tokensUsed: totalTokens, costUSD: estimatedUSD };

    } catch (err) {
      console.error(`[RAG] Error ingesting ${documentId}:`, err.message);
      await this._setStatus(documentId, 'error', err.message);
      throw err;
    }
  }

  /**
   * Re-ingestar todos los documentos pendientes de un tenant
   */
  async ingestPending(tenantId) {
    const result = await this.db.query(
      `SELECT id FROM kb_documents
       WHERE tenant_id = $1 AND status IN ('pending', 'error')
       ORDER BY created_at ASC`,
      [tenantId]
    );

    const results = [];
    for (const row of result.rows) {
      try {
        const r = await this.ingestDocument(row.id);
        results.push({ id: row.id, ...r, success: true });
      } catch (err) {
        results.push({ id: row.id, success: false, error: err.message });
      }
    }

    return results;
  }

  // ─── Extractores por tipo ────────────────────────────────────

  async _extractPdf(doc) {
    try {
      const pdfParse = require('pdf-parse');
      let pdfBuffer;

      if (doc.file_url) {
        // Descargar PDF desde URL
        const axios = require('axios');
        const response = await axios.get(doc.file_url, { responseType: 'arraybuffer', timeout: 15000 });
        pdfBuffer = Buffer.from(response.data);
      } else if (doc.content) {
        // content es base64
        pdfBuffer = Buffer.from(doc.content, 'base64');
      } else {
        throw new Error('PDF sin fuente (url ni content)');
      }

      const data = await pdfParse(pdfBuffer);
      return data.text || '';

    } catch (err) {
      // Si pdf-parse no está instalado, instrucción clara
      if (err.code === 'MODULE_NOT_FOUND') {
        throw new Error('Instalar dependencia: npm install pdf-parse');
      }
      throw err;
    }
  }

  async _scrapeUrl(url) {
    if (!url || !url.startsWith('http')) throw new Error('URL inválida');

    const axios    = require('axios');
    const cheerio  = require('cheerio');

    const response = await axios.get(url, {
      timeout: 10000,
      headers: {
        'User-Agent': 'Mozilla/5.0 (compatible; AgentCore/1.0; +https://agentcore.io)',
        'Accept':     'text/html,application/xhtml+xml',
        'Accept-Language': 'es-MX,es;q=0.9',
      },
      maxRedirects: 3,
    });

    const $ = cheerio.load(response.data);

    // Eliminar elementos no útiles
    $('script, style, nav, footer, header, aside, .cookie-banner, .ads, [class*="cookie"]').remove();

    // Extraer título
    const title = $('title').text().trim() || $('h1').first().text().trim() || url;

    // Extraer texto principal
    const mainContent = $('main, article, .content, .post-content, #content').first();
    const text = mainContent.length > 0
      ? mainContent.text()
      : $('body').text();

    // Limpiar texto
    const cleanText = text
      .replace(/\t/g, ' ')
      .replace(/[ ]{2,}/g, ' ')
      .replace(/\n{3,}/g, '\n\n')
      .trim();

    return { text: cleanText, title, url };
  }

  /**
   * Convierte FAQ en texto estructurado para chunking
   * Formato de input (JSON string):
   * [{ "question": "¿Cuánto cuesta?", "answer": "Depende del servicio..." }, ...]
   *
   * O texto plano con formato:
   * P: ¿Cuánto cuesta?
   * R: Depende del servicio...
   */
  _processFaq(content) {
    // Intentar parsear como JSON
    try {
      const faqs = JSON.parse(content);
      if (Array.isArray(faqs)) {
        return faqs
          .filter(f => f.question && f.answer)
          .map(f => `Pregunta: ${f.question}\nRespuesta: ${f.answer}`)
          .join('\n\n---\n\n');
      }
    } catch {
      // No es JSON, tratar como texto plano con formato P:/R:
    }

    // Parsear formato P: ... R: ...
    const lines   = content.split('\n');
    const pairs   = [];
    let currentQ  = '';
    let currentA  = '';

    for (const line of lines) {
      const trimmed = line.trim();
      if (trimmed.startsWith('P:') || trimmed.startsWith('Q:') || trimmed.match(/^\d+\.\s/)) {
        if (currentQ && currentA) pairs.push({ q: currentQ, a: currentA });
        currentQ = trimmed.replace(/^[PQ]:\s*/, '').replace(/^\d+\.\s*/, '');
        currentA = '';
      } else if (trimmed.startsWith('R:') || trimmed.startsWith('A:')) {
        currentA = trimmed.replace(/^[RA]:\s*/, '');
      } else if (currentA && trimmed) {
        currentA += ' ' + trimmed;
      }
    }
    if (currentQ && currentA) pairs.push({ q: currentQ, a: currentA });

    if (pairs.length > 0) {
      return pairs
        .map(p => `Pregunta: ${p.q}\nRespuesta: ${p.a}`)
        .join('\n\n---\n\n');
    }

    // Fallback: devolver el contenido tal cual
    return content;
  }

  async _setStatus(documentId, status, errorMsg = null) {
    const notes = errorMsg ? JSON.stringify({ error: errorMsg }) : null;
    await this.db.query(
      `UPDATE kb_documents SET status=$1, metadata=COALESCE($2::jsonb, metadata), updated_at=NOW() WHERE id=$3`,
      [status, notes, documentId]
    );
  }
}

module.exports = IngestionPipeline;
