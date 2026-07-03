-- ── Simulator & Guided Setup migration ─────────────────────────────────────
-- Adds:
--   tenants.is_ready          BOOLEAN — gates webhook activation
--   tenants.setup_steps       JSONB   — tracks guided onboarding progress
--   simulator_logs            TABLE   — audit log for simulator sessions
--   simulator_security_events TABLE   — injection attempts log
-- ────────────────────────────────────────────────────────────────────────────

-- 1. Add columns to tenants (idempotent)
ALTER TABLE tenants
  ADD COLUMN IF NOT EXISTS is_ready     BOOLEAN NOT NULL DEFAULT false,
  ADD COLUMN IF NOT EXISTS setup_steps  JSONB   NOT NULL DEFAULT '{}'::jsonb;

-- 2. Simulator chat logs
CREATE TABLE IF NOT EXISTS simulator_logs (
  id            BIGSERIAL    PRIMARY KEY,
  tenant_id     UUID         NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  user_id       UUID         NOT NULL,
  session_id    TEXT         NOT NULL,
  scenario      TEXT,                          -- scenario key or 'free'
  role          VARCHAR(10)  NOT NULL CHECK (role IN ('user','assistant')),
  content       TEXT         NOT NULL,
  tokens_used   INT,
  latency_ms    INT,
  model         TEXT,
  created_at    TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_simlogs_tenant ON simulator_logs(tenant_id, session_id);

-- 3. Security event log (injection attempts)
CREATE TABLE IF NOT EXISTS simulator_security_events (
  id          BIGSERIAL    PRIMARY KEY,
  tenant_id   UUID         NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  user_id     UUID         NOT NULL,
  session_id  TEXT         NOT NULL,
  message     TEXT         NOT NULL,           -- the user's blocked message
  patterns    TEXT[]       NOT NULL DEFAULT '{}', -- matched patterns
  severity    VARCHAR(10)  NOT NULL DEFAULT 'medium' CHECK (severity IN ('low','medium','high')),
  created_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_simevents_tenant ON simulator_security_events(tenant_id, created_at DESC);
