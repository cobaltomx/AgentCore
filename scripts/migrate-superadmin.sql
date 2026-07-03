-- ============================================================
-- AgentCore — Super Admin (plataforma)
-- Fases A/B (planes, tarifas, guardrail) + C (features, alertas)
--                                        + D (números Twilio, logs)
-- Idempotente: seguro de correr varias veces.
-- ============================================================

-- ─────────────────────────────────────────────────────────────
-- FASE A — Planes de venta (editables desde el panel)
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS plans (
  id                     UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  key                    VARCHAR(40) UNIQUE NOT NULL,   -- starter | growth | business…
  name                   VARCHAR(80) NOT NULL,
  monthly_cents          INT  NOT NULL DEFAULT 0,       -- precio/mes (¢ MXN); 0 = custom/enterprise
  included_minutes       INT  NOT NULL DEFAULT 0,
  max_agents             INT  NOT NULL DEFAULT 1,
  overage_per_min_cents  INT  NOT NULL DEFAULT 0,       -- excedente ¢/min
  features               JSONB NOT NULL DEFAULT '[]',   -- ["voice","whatsapp",…]
  is_active              BOOLEAN NOT NULL DEFAULT TRUE,
  sort_order             INT  NOT NULL DEFAULT 99,
  created_at             TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at             TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- ─────────────────────────────────────────────────────────────
-- FASE A — Tarifas de proveedores (costo estimado de infra)
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS provider_rates (
  id          UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  provider    VARCHAR(40) UNIQUE NOT NULL,  -- twilio_voice | deepgram_stt | cartesia_tts | llm_input…
  label       VARCHAR(80) NOT NULL,
  unit        VARCHAR(20) NOT NULL DEFAULT 'min', -- min | 1k_tokens
  rate_cents  NUMERIC(10,4) NOT NULL DEFAULT 0,  -- ¢ MXN por unidad
  is_active   BOOLEAN NOT NULL DEFAULT TRUE,
  created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- ─────────────────────────────────────────────────────────────
-- FASE B — Config global de plataforma (guardrail de margen,
--          modelo de costo, umbrales de alerta de consumo)
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS platform_config (
  key         VARCHAR(60) PRIMARY KEY,
  value       JSONB NOT NULL,
  updated_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Seeds idempotentes (no pisan valores ya configurados) ────────
INSERT INTO platform_config (key, value) VALUES
  ('tokens_per_min',  '4000'),   -- efectivo tras prompt caching
  ('min_margin_pct',  '40'),     -- guardrail de margen mínimo
  ('usage_warn_pct',  '80')      -- FASE C: alerta amarilla de consumo
ON CONFLICT (key) DO NOTHING;

-- Tarifas por defecto (MXN ¢) — solo en instalación fresca ──────
INSERT INTO provider_rates (provider, label, unit, rate_cents) VALUES
  ('twilio_voice', 'Twilio voz',     'min',       30),
  ('deepgram_stt', 'Deepgram STT',   'min',        8),
  ('cartesia_tts', 'Cartesia TTS',   'min',       15),
  ('deepgram_tts', 'Deepgram TTS',   'min',       10),
  ('llm_input',    'LLM entrada',    '1k_tokens',  2),
  ('llm_output',   'LLM salida',     '1k_tokens',  8)
ON CONFLICT (provider) DO NOTHING;

-- ─────────────────────────────────────────────────────────────
-- FASE D — Pool de números Twilio + asignación a tenants
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS twilio_numbers (
  id                 UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  phone_number       VARCHAR(30) UNIQUE NOT NULL,   -- E.164: +52...
  friendly_name      VARCHAR(120),
  account_sid        VARCHAR(80),                   -- NULL = cuenta plataforma (compartida)
  tenant_id          UUID REFERENCES tenants(id) ON DELETE SET NULL,
  status             VARCHAR(20) NOT NULL DEFAULT 'available', -- available | assigned | released
  capabilities       JSONB NOT NULL DEFAULT '{"voice":true,"sms":false,"whatsapp":false}',
  monthly_cost_cents INT NOT NULL DEFAULT 0,        -- costo de renta del número (¢ MXN)
  notes              TEXT,
  created_at         TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at         TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_twilio_numbers_tenant ON twilio_numbers(tenant_id);
CREATE INDEX IF NOT EXISTS idx_twilio_numbers_status ON twilio_numbers(status);

-- ─────────────────────────────────────────────────────────────
-- FASE D — Logs globales: índice para consulta del audit_log
-- (la tabla audit_log ya existe en init.sql)
-- ─────────────────────────────────────────────────────────────
CREATE INDEX IF NOT EXISTS idx_audit_log_created ON audit_log(created_at DESC);
CREATE INDEX IF NOT EXISTS idx_audit_log_action  ON audit_log(action);

-- audit_log.tenant_id sin ON DELETE CASCADE → bloqueaba borrar un tenant con
-- historial (necesario para "borrar cuenta"/GDPR). Recrear la FK con CASCADE.
DO $$
DECLARE fk_name text;
BEGIN
  SELECT conname INTO fk_name FROM pg_constraint
   WHERE conrelid = 'audit_log'::regclass AND contype = 'f'
     AND confrelid = 'tenants'::regclass;
  IF fk_name IS NOT NULL THEN
    EXECUTE 'ALTER TABLE audit_log DROP CONSTRAINT ' || quote_ident(fk_name);
  END IF;
  ALTER TABLE audit_log
    ADD CONSTRAINT audit_log_tenant_id_fkey
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE;
END $$;

-- Nota: las "features on/off por tenant" (Fase C) se guardan como
-- override en tenants.settings->'features' (mapa {key:bool}); no
-- requieren DDL. El efectivo = features del plan ∪ overrides del tenant.
