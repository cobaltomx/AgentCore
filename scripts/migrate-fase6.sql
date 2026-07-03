-- ============================================================
-- Migración Fase 6 — Billing con Stripe
-- psql $DATABASE_URL -f scripts/migrate-fase6.sql
-- ============================================================

-- ── Tabla de suscripciones ────────────────────────────────────
CREATE TABLE IF NOT EXISTS subscriptions (
  id                    UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  tenant_id             UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,

  -- Stripe IDs
  stripe_customer_id    VARCHAR(100) UNIQUE,
  stripe_subscription_id VARCHAR(100) UNIQUE,
  stripe_price_id       VARCHAR(100),              -- precio del plan base
  stripe_meter_item_id  VARCHAR(100),              -- item de uso (minutos excedentes)

  -- Plan
  plan                  VARCHAR(30) NOT NULL DEFAULT 'starter',
  status                VARCHAR(30) NOT NULL DEFAULT 'trialing',
  -- trialing | active | past_due | canceled | unpaid

  -- Fechas de ciclo
  current_period_start  TIMESTAMPTZ,
  current_period_end    TIMESTAMPTZ,
  trial_end             TIMESTAMPTZ,
  canceled_at           TIMESTAMPTZ,

  -- Metadata
  metadata              JSONB NOT NULL DEFAULT '{}',
  created_at            TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at            TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- ── Tabla de uso por período ──────────────────────────────────
CREATE TABLE IF NOT EXISTS usage_records (
  id              UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  tenant_id       UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  subscription_id UUID REFERENCES subscriptions(id),

  -- Período de facturación
  period_start    TIMESTAMPTZ NOT NULL,
  period_end      TIMESTAMPTZ NOT NULL,

  -- Consumo
  minutes_included  INT NOT NULL DEFAULT 0,     -- incluidos en el plan
  minutes_used      INT NOT NULL DEFAULT 0,     -- total usados
  minutes_overage   INT NOT NULL DEFAULT 0,     -- excedente cobrable

  -- Costo
  plan_amount_cents     INT NOT NULL DEFAULT 0, -- costo del plan en centavos MXN
  overage_amount_cents  INT NOT NULL DEFAULT 0, -- costo excedente
  total_amount_cents    INT NOT NULL DEFAULT 0,

  -- Estado de reporte a Stripe
  reported_to_stripe  BOOLEAN NOT NULL DEFAULT FALSE,
  stripe_usage_record_id VARCHAR(100),

  created_at    TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- ── Tabla de facturas / invoices ──────────────────────────────
CREATE TABLE IF NOT EXISTS invoices (
  id                  UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  tenant_id           UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  subscription_id     UUID REFERENCES subscriptions(id),

  stripe_invoice_id   VARCHAR(100) UNIQUE,
  stripe_payment_url  TEXT,

  amount_cents        INT NOT NULL DEFAULT 0,
  currency            VARCHAR(3)  NOT NULL DEFAULT 'mxn',
  status              VARCHAR(30) NOT NULL DEFAULT 'draft',
  -- draft | open | paid | void | uncollectible

  period_start        TIMESTAMPTZ,
  period_end          TIMESTAMPTZ,
  paid_at             TIMESTAMPTZ,
  due_date            TIMESTAMPTZ,

  pdf_url             TEXT,
  created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- ── Índices ───────────────────────────────────────────────────
CREATE INDEX IF NOT EXISTS idx_subscriptions_tenant  ON subscriptions(tenant_id);
CREATE INDEX IF NOT EXISTS idx_subscriptions_stripe  ON subscriptions(stripe_subscription_id);
CREATE INDEX IF NOT EXISTS idx_usage_tenant_period   ON usage_records(tenant_id, period_start DESC);
CREATE INDEX IF NOT EXISTS idx_invoices_tenant       ON invoices(tenant_id, created_at DESC);

-- ── Triggers updated_at ───────────────────────────────────────
CREATE TRIGGER trg_subscriptions_updated
  BEFORE UPDATE ON subscriptions
  FOR EACH ROW EXECUTE FUNCTION update_updated_at();

-- ── Agregar stripe_customer_id a tenants ──────────────────────
DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                 WHERE table_name='tenants' AND column_name='stripe_customer_id') THEN
    ALTER TABLE tenants ADD COLUMN stripe_customer_id VARCHAR(100);
  END IF;
END $$;

-- Verificar
SELECT table_name FROM information_schema.tables
WHERE table_name IN ('subscriptions','usage_records','invoices');
