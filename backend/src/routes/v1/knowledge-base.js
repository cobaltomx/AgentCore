'use strict';

const IngestionPipeline = require('../../services/rag/ingestion');
const RetrievalService  = require('../../services/rag/retrieval');
const { z } = require('zod');

const docSchema = z.object({
  title:     z.string().min(3).max(200),
  file_type: z.enum(['text', 'pdf', 'url', 'faq']),
  content:   z.string().optional(),
  source_url:z.string().url().optional(),
  agent_id:  z.string().uuid().optional(),
});

async function knowledgeBaseRoutes(app) {

  // GET /api/v1/kb — listar documentos del tenant
  app.get('/', { onRequest: [app.requireTenant] }, async (req) => {
    const result = await app.db.query(
      `SELECT id, title, file_type, status, chunk_count, source_url, agent_id, created_at, updated_at
       FROM kb_documents
       WHERE tenant_id = $1
       ORDER BY created_at DESC`,
      [req.tenant.id]
    );
    return result.rows;
  });

  // GET /api/v1/kb/:id — documento con preview de chunks
  app.get('/:id', { onRequest: [app.requireTenant] }, async (req, reply) => {
    const doc = await app.db.query(
      'SELECT * FROM kb_documents WHERE id = $1 AND tenant_id = $2',
      [req.params.id, req.tenant.id]
    );
    if (!doc.rows[0]) return reply.code(404).send({ error: 'Documento no encontrado' });

    const chunks = await app.db.query(
      `SELECT id, content, chunk_index, token_count, heading
       FROM kb_chunks WHERE document_id = $1 ORDER BY chunk_index ASC`,
      [req.params.id]
    );

    return { ...doc.rows[0], chunks: chunks.rows };
  });

  // PATCH /api/v1/kb/:id — actualizar título / contenido (re-ingesta automática)
  app.patch('/:id', { onRequest: [app.requireTenant] }, async (req, reply) => {
    const check = await app.db.query(
      'SELECT * FROM kb_documents WHERE id = $1 AND tenant_id = $2',
      [req.params.id, req.tenant.id]
    );
    if (!check.rows[0]) return reply.code(404).send({ error: 'Documento no encontrado' });

    const { title, content, agent_id } = req.body || {};
    const updates = [];
    const values  = [];
    let idx = 1;

    if (title)               { updates.push(`title = $${idx++}`);    values.push(title); }
    if (content)             { updates.push(`content = $${idx++}`);  values.push(content); }
    if ('agent_id' in (req.body || {})) {
      updates.push(`agent_id = $${idx++}`);
      values.push(agent_id || null);
    }

    if (!updates.length) return reply.code(400).send({ error: 'Sin campos para actualizar' });

    // Si cambia el contenido → resetear estado para re-ingestar
    if (content) {
      updates.push(`status = $${idx++}`);     values.push('pending');
      updates.push(`chunk_count = $${idx++}`); values.push(0);
    }

    values.push(req.params.id, req.tenant.id);
    await app.db.query(
      `UPDATE kb_documents SET ${updates.join(', ')}, updated_at = NOW()
       WHERE id = $${idx} AND tenant_id = $${idx + 1}`,
      values
    );

    // Si cambió el contenido, disparar re-ingesta en background
    if (content) {
      setImmediate(async () => {
        try {
          const pipeline = new IngestionPipeline({ db: app.db });
          await pipeline.ingestDocument(req.params.id);
        } catch (err) {
          app.log.error({ err }, '[KB] Error re-ingesting after update');
        }
      });
    }

    const updated = await app.db.query('SELECT * FROM kb_documents WHERE id = $1', [req.params.id]);
    return updated.rows[0];
  });

  // GET /api/v1/kb/stats — estadísticas de la KB
  app.get('/stats', { onRequest: [app.requireTenant] }, async (req) => {
    const retrieval = new RetrievalService({ db: app.db });
    return retrieval.getStats(req.tenant.id);
  });

  // GET /api/v1/kb/search?q=texto&min_similarity=0.60 — búsqueda semántica
  app.get('/search', { onRequest: [app.requireTenant] }, async (req, reply) => {
    const query = req.query.q;
    if (!query) return reply.code(400).send({ error: 'Parámetro q requerido' });

    const minSimilarity = req.query.min_similarity
      ? Math.max(0.3, Math.min(0.95, parseFloat(req.query.min_similarity)))
      : 0.60;

    const retrieval = new RetrievalService({ db: app.db });
    const { chunks, found, sources } = await retrieval.getContext({
      tenantId: req.tenant.id,
      query,
      topK: 6,
      minSimilarity,
    });

    return { found, chunks, sources, query };
  });

  // POST /api/v1/kb — crear documento
  app.post('/', { onRequest: [app.requireTenant] }, async (req, reply) => {
    const parsed = docSchema.safeParse(req.body);
    if (!parsed.success) {
      return reply.code(400).send({ error: 'Datos inválidos', details: parsed.error.flatten() });
    }

    const d = parsed.data;

    // Validar que tiene contenido o URL
    if (!d.content && !d.source_url) {
      return reply.code(400).send({ error: 'Debe proporcionar content o source_url' });
    }

    const result = await app.db.query(
      `INSERT INTO kb_documents (tenant_id, agent_id, title, content, file_type, source_url, status)
       VALUES ($1, $2, $3, $4, $5, $6, 'pending') RETURNING *`,
      [req.tenant.id, d.agent_id || null, d.title, d.content || null, d.file_type, d.source_url || null]
    );

    const doc = result.rows[0];

    // Disparar ingesta en background (no bloquear el response)
    setImmediate(async () => {
      try {
        const pipeline = new IngestionPipeline({ db: app.db });
        await pipeline.ingestDocument(doc.id);
        app.log.info({ docId: doc.id, title: d.title }, '[KB] Documento ingested');
      } catch (err) {
        app.log.error({ err, docId: doc.id }, '[KB] Error ingesting document');
      }
    });

    return reply.code(201).send({
      ...doc,
      message: 'Documento recibido. El procesamiento comenzará en breve.',
    });
  });

  // POST /api/v1/kb/faq — crear FAQ rápido (pares pregunta-respuesta)
  app.post('/faq', { onRequest: [app.requireTenant] }, async (req, reply) => {
    const { title, faqs, agent_id } = req.body;

    if (!title || !faqs || !Array.isArray(faqs)) {
      return reply.code(400).send({ error: 'Se requiere title y array de faqs [{question, answer}]' });
    }

    const content = JSON.stringify(faqs);

    const result = await app.db.query(
      `INSERT INTO kb_documents (tenant_id, agent_id, title, content, file_type, status)
       VALUES ($1, $2, $3, $4, 'faq', 'pending') RETURNING *`,
      [req.tenant.id, agent_id || null, title, content]
    );

    const doc = result.rows[0];

    setImmediate(async () => {
      try {
        const pipeline = new IngestionPipeline({ db: app.db });
        await pipeline.ingestDocument(doc.id);
      } catch (err) {
        app.log.error({ err }, '[KB] Error ingesting FAQ');
      }
    });

    return reply.code(201).send(doc);
  });

  // POST /api/v1/kb/:id/reingest — re-procesar documento
  app.post('/:id/reingest', { onRequest: [app.requireTenant] }, async (req, reply) => {
    // Verificar pertenencia
    const check = await app.db.query(
      'SELECT id FROM kb_documents WHERE id = $1 AND tenant_id = $2',
      [req.params.id, req.tenant.id]
    );
    if (!check.rows[0]) return reply.code(404).send({ error: 'Documento no encontrado' });

    // Reset a pending
    await app.db.query(
      "UPDATE kb_documents SET status='pending', chunk_count=0 WHERE id=$1",
      [req.params.id]
    );

    setImmediate(async () => {
      try {
        const pipeline = new IngestionPipeline({ db: app.db });
        await pipeline.ingestDocument(req.params.id);
      } catch (err) {
        app.log.error({ err }, '[KB] Error re-ingesting');
      }
    });

    return { message: 'Re-procesamiento iniciado', id: req.params.id };
  });

  // DELETE /api/v1/kb/:id — eliminar documento y sus chunks
  app.delete('/:id', { onRequest: [app.requireTenant] }, async (req, reply) => {
    // kb_chunks se elimina en cascada por FK
    const result = await app.db.query(
      'DELETE FROM kb_documents WHERE id = $1 AND tenant_id = $2 RETURNING id',
      [req.params.id, req.tenant.id]
    );
    if (!result.rows[0]) return reply.code(404).send({ error: 'Documento no encontrado' });
    return { deleted: true, id: result.rows[0].id };
  });
}

module.exports = knowledgeBaseRoutes;
