-- ============================================================
-- Fase 0 v2 — `leads` como espina del CLIENTE (no se crea tabla customers)
-- Ejecutar: docker exec -i agentcore_postgres psql -U agentcore -d agentcore < scripts/migrate-customer-spine.sql
-- Idempotente.
-- ============================================================
BEGIN;

-- ── 1. Deduplicar leads por (tenant_id, phone) ────────────────
--    Keeper = el más antiguo del grupo. Se repuntan las referencias y se
--    borran los duplicados ANTES de crear el índice único.
CREATE TEMP TABLE lead_dedup ON COMMIT DROP AS
SELECT id AS dup_id, keeper_id FROM (
  SELECT id,
         first_value(id) OVER (PARTITION BY tenant_id, phone ORDER BY created_at ASC, id) AS keeper_id
  FROM leads
  WHERE phone IS NOT NULL AND phone <> ''
) x
WHERE id <> keeper_id;

-- Repuntar referencias conocidas hacia el keeper.
UPDATE appointments a SET lead_id = d.keeper_id FROM lead_dedup d WHERE a.lead_id = d.dup_id;

-- Borrar los duplicados.
DELETE FROM leads l USING lead_dedup d WHERE l.id = d.dup_id;

-- ── 2. Índice único parcial (clave de identidad) ──────────────
CREATE UNIQUE INDEX IF NOT EXISTS uniq_leads_tenant_phone
  ON leads (tenant_id, phone) WHERE phone IS NOT NULL AND phone <> '';

-- ── 3. Agregados de cliente en leads ──────────────────────────
ALTER TABLE leads
  ADD COLUMN IF NOT EXISTS visit_count       integer NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS no_show_count     integer NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS cancel_count      integer NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS last_visit_at     timestamptz,
  ADD COLUMN IF NOT EXISTS total_spent_cents bigint  NOT NULL DEFAULT 0;

-- (La ficha técnica —alergias, preferencias, consentimiento— reusa
--  leads.custom_data jsonb que YA existe; no se agregan columnas de perfil.)

-- ── 4. Cerrar la fragmentación: enlazar orders y conversations ─
ALTER TABLE orders        ADD COLUMN IF NOT EXISTS lead_id uuid REFERENCES leads(id) ON DELETE SET NULL;
ALTER TABLE conversations ADD COLUMN IF NOT EXISTS lead_id uuid REFERENCES leads(id) ON DELETE SET NULL;
CREATE INDEX IF NOT EXISTS idx_orders_lead        ON orders(lead_id);
CREATE INDEX IF NOT EXISTS idx_conversations_lead ON conversations(lead_id);

COMMIT;

DO $$ BEGIN RAISE NOTICE '✅ migrate-customer-spine.sql aplicado'; END $$;
