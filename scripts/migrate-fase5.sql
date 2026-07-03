-- ============================================================
-- Migración Fase 5 — pgvector + Knowledge Base chunks
-- psql $DATABASE_URL -f scripts/migrate-fase5.sql
-- ============================================================

-- Habilitar extensión pgvector
-- (requiere PostgreSQL 15+ con pgvector instalado)
CREATE EXTENSION IF NOT EXISTS vector;

-- ── Tabla de chunks vectorizados ──────────────────────────────
-- Cada documento se divide en chunks de ~500 tokens
-- Cada chunk tiene su embedding de 1536 dimensiones (OpenAI small)
CREATE TABLE IF NOT EXISTS kb_chunks (
  id              UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  tenant_id       UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  document_id     UUID NOT NULL REFERENCES kb_documents(id) ON DELETE CASCADE,
  agent_id        UUID REFERENCES agents(id),       -- NULL = aplica a todos los agentes

  -- Contenido
  content         TEXT NOT NULL,                    -- texto del chunk
  chunk_index     INT  NOT NULL DEFAULT 0,          -- posición en el documento
  token_count     INT  NOT NULL DEFAULT 0,

  -- Metadata para filtrado y contexto
  source_type     VARCHAR(20) NOT NULL DEFAULT 'text', -- text|pdf|url|faq
  source_url      TEXT,                             -- si viene de scraping
  page_number     INT,                              -- si viene de PDF
  heading         TEXT,                             -- heading del chunk si lo tiene

  -- Vector embedding (OpenAI text-embedding-3-small = 1536 dims)
  embedding       vector(1536),

  created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Índice de búsqueda vectorial (HNSW — más rápido en consulta)
-- ef_construction=64, m=16 son buenos defaults para <100K chunks
CREATE INDEX IF NOT EXISTS idx_kb_chunks_embedding
  ON kb_chunks USING hnsw (embedding vector_cosine_ops)
  WITH (m = 16, ef_construction = 64);

-- Índice para filtrar por tenant eficientemente
CREATE INDEX IF NOT EXISTS idx_kb_chunks_tenant
  ON kb_chunks(tenant_id);

CREATE INDEX IF NOT EXISTS idx_kb_chunks_document
  ON kb_chunks(document_id);

-- ── Actualizar tabla kb_documents (ya existe desde Fase 0) ───
-- Agregar columnas que faltaban
DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                 WHERE table_name='kb_documents' AND column_name='source_url') THEN
    ALTER TABLE kb_documents ADD COLUMN source_url TEXT;
  END IF;

  IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                 WHERE table_name='kb_documents' AND column_name='metadata') THEN
    ALTER TABLE kb_documents ADD COLUMN metadata JSONB NOT NULL DEFAULT '{}';
  END IF;
END $$;

-- ── Documentos demo para tenant dental ───────────────────────
INSERT INTO kb_documents (tenant_id, agent_id, title, content, file_type, status)
SELECT
  t.id,
  NULL,
  'FAQ Clínica Dental Demo',
  'Preguntas frecuentes sobre servicios dentales.',
  'faq',
  'pending'
FROM tenants t WHERE t.slug = 'demo-dental'
ON CONFLICT DO NOTHING;

-- Verificar
SELECT table_name FROM information_schema.tables
WHERE table_name IN ('kb_chunks', 'kb_documents');
