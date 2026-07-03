-- ── Handoff humano inteligente ──────────────────────────────────────────────
-- Marca conversaciones que necesitan atención de una persona, con el motivo y
-- un resumen del contexto para que el humano retome sin leer todo.
-- ────────────────────────────────────────────────────────────────────────────

ALTER TABLE conversations
  ADD COLUMN IF NOT EXISTS needs_human         BOOLEAN     NOT NULL DEFAULT false,
  ADD COLUMN IF NOT EXISTS handoff_reason      TEXT,
  ADD COLUMN IF NOT EXISTS handoff_at          TIMESTAMPTZ,
  ADD COLUMN IF NOT EXISTS handoff_resolved_at TIMESTAMPTZ;

-- Índice para listar rápido las conversaciones pendientes de atención humana
CREATE INDEX IF NOT EXISTS idx_conv_needs_human
  ON conversations (tenant_id, handoff_at DESC)
  WHERE needs_human = true AND handoff_resolved_at IS NULL;
