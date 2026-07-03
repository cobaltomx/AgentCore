-- ============================================================
-- Fase 1.3 — Lista de espera (waitlist). Reusa `leads` como cliente.
-- Ejecutar: docker exec -i agentcore_postgres psql -U agentcore -d agentcore < scripts/migrate-waitlist.sql
-- Idempotente.
-- ============================================================
CREATE TABLE IF NOT EXISTS waitlist (
  id              UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  tenant_id       UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  lead_id         UUID REFERENCES leads(id) ON DELETE SET NULL,   -- el cliente
  service_type_id UUID REFERENCES service_types(id) ON DELETE SET NULL,
  doctor_id       UUID REFERENCES doctors(id) ON DELETE SET NULL, -- profesional preferido (opcional)
  preferred_from  TIMESTAMPTZ,   -- ventana de fechas deseada (opcional)
  preferred_to    TIMESTAMPTZ,
  note            TEXT,          -- "prefiere tardes", texto libre
  status          VARCHAR(20) NOT NULL DEFAULT 'waiting',  -- waiting | notified | booked | expired | cancelled
  notified_at     TIMESTAMPTZ,
  created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_waitlist_tenant_status ON waitlist(tenant_id, status);
CREATE INDEX IF NOT EXISTS idx_waitlist_lead          ON waitlist(lead_id);

DO $$ BEGIN RAISE NOTICE '✅ migrate-waitlist.sql aplicado'; END $$;
