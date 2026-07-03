-- ── Cobros / anticipos por el bot ───────────────────────────────────────────
-- Completa el seguimiento de depósitos en appointments. Los campos base ya
-- existen (deposit_status, deposit_amount, deposit_payment_link,
-- deposit_payment_intent). Se añaden moneda, timestamp de pago y la sesión de
-- checkout de Stripe para conciliación.
--
-- deposit_status: 'none' | 'pending' | 'paid' | 'expired' | 'refunded'
-- ────────────────────────────────────────────────────────────────────────────

ALTER TABLE appointments
  ADD COLUMN IF NOT EXISTS deposit_currency VARCHAR(3)  NOT NULL DEFAULT 'mxn',
  ADD COLUMN IF NOT EXISTS deposit_paid_at  TIMESTAMPTZ,
  ADD COLUMN IF NOT EXISTS deposit_checkout_session VARCHAR(255);

-- Índice para resolver rápido el appointment desde un webhook de Stripe
CREATE INDEX IF NOT EXISTS idx_appt_checkout_session
  ON appointments (deposit_checkout_session)
  WHERE deposit_checkout_session IS NOT NULL;

-- Registro de pagos de clientes finales (separado de invoices del SaaS)
CREATE TABLE IF NOT EXISTS customer_payments (
  id              BIGSERIAL    PRIMARY KEY,
  tenant_id       UUID         NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  appointment_id  UUID         REFERENCES appointments(id) ON DELETE SET NULL,
  concept         TEXT         NOT NULL DEFAULT 'Anticipo de cita',
  amount_cents    INT          NOT NULL,
  currency        VARCHAR(3)   NOT NULL DEFAULT 'mxn',
  status          VARCHAR(20)  NOT NULL DEFAULT 'pending'
                    CHECK (status IN ('pending','paid','expired','refunded')),
  stripe_session  VARCHAR(255),
  stripe_payment_intent VARCHAR(255),
  payment_url     TEXT,
  paid_at         TIMESTAMPTZ,
  created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_custpay_tenant  ON customer_payments (tenant_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_custpay_session ON customer_payments (stripe_session);
