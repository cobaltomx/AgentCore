-- ── Voz del cliente (inteligencia de conversaciones) ────────────────────────
-- Almacena el análisis post-conversación generado por el LLM al cerrar.
--   summary / sentiment ya existen en conversations (estaban sin poblar).
--   analysis (JSONB) agrega: topics[], objections[], kb_gap (bool),
--                            unanswered_question, intent.
-- ────────────────────────────────────────────────────────────────────────────

ALTER TABLE conversations
  ADD COLUMN IF NOT EXISTS analysis JSONB,
  ADD COLUMN IF NOT EXISTS analyzed_at TIMESTAMPTZ;

-- Índice para filtrar rápido las conversaciones con gap de conocimiento
CREATE INDEX IF NOT EXISTS idx_conv_kb_gap
  ON conversations (tenant_id, created_at DESC)
  WHERE (analysis->>'kb_gap') = 'true';

-- Índice general para las que ya tienen análisis
CREATE INDEX IF NOT EXISTS idx_conv_analyzed
  ON conversations (tenant_id, analyzed_at DESC)
  WHERE analyzed_at IS NOT NULL;
