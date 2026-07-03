-- ============================================================
-- Migración Fase 7 — Campañas Outbound
-- psql $DATABASE_URL -f scripts/migrate-fase7.sql
-- ============================================================

-- ── Campañas ──────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS campaigns (
  id              UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  tenant_id       UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  agent_id        UUID NOT NULL REFERENCES agents(id),

  name            VARCHAR(200) NOT NULL,
  description     TEXT,

  -- Canal y tipo
  channel         VARCHAR(20) NOT NULL DEFAULT 'voice', -- voice | whatsapp | both
  trigger_type    VARCHAR(20) NOT NULL DEFAULT 'manual', -- manual | auto

  -- Configuración de ejecución
  status          VARCHAR(20) NOT NULL DEFAULT 'draft',
  -- draft | scheduled | running | paused | completed | cancelled

  -- Script/prompt del agente outbound
  script          TEXT NOT NULL,  -- system prompt para esta campaña
  goal            VARCHAR(100),   -- 'book_appointment' | 'qualify_lead' | 'follow_up' | 'custom'

  -- WhatsApp: template aprobado de Meta
  wa_template_name    VARCHAR(100),
  wa_template_lang    VARCHAR(10) DEFAULT 'es_MX',

  -- Horario de ejecución (no llamar fuera de estos horarios)
  allowed_hours_start INT NOT NULL DEFAULT 9,   -- hora 24h
  allowed_hours_end   INT NOT NULL DEFAULT 19,
  allowed_days        INT[] NOT NULL DEFAULT ARRAY[1,2,3,4,5], -- L-V

  -- Trigger automático
  trigger_config  JSONB NOT NULL DEFAULT '{}',
  -- { lead_status: 'new', days_without_contact: 3, max_attempts: 2 }

  -- Límites de rate
  calls_per_hour  INT NOT NULL DEFAULT 10,
  max_attempts    INT NOT NULL DEFAULT 2,

  -- Estadísticas (actualizado en tiempo real)
  total_contacts  INT NOT NULL DEFAULT 0,
  contacted       INT NOT NULL DEFAULT 0,
  converted       INT NOT NULL DEFAULT 0,
  failed          INT NOT NULL DEFAULT 0,

  -- Fechas
  scheduled_at    TIMESTAMPTZ,
  started_at      TIMESTAMPTZ,
  completed_at    TIMESTAMPTZ,
  created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- ── Contactos de campaña (cola de trabajo) ────────────────────
CREATE TABLE IF NOT EXISTS campaign_contacts (
  id              UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  campaign_id     UUID NOT NULL REFERENCES campaigns(id) ON DELETE CASCADE,
  tenant_id       UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  lead_id         UUID REFERENCES leads(id),

  -- Datos de contacto
  name            VARCHAR(120),
  phone           VARCHAR(30) NOT NULL,
  email           VARCHAR(254),
  custom_data     JSONB NOT NULL DEFAULT '{}', -- datos extra del CSV

  -- Estado de este contacto en la campaña
  status          VARCHAR(20) NOT NULL DEFAULT 'pending',
  -- pending | calling | contacted | converted | failed | opted_out

  -- Intentos
  attempts        INT NOT NULL DEFAULT 0,
  last_attempt_at TIMESTAMPTZ,
  next_attempt_at TIMESTAMPTZ,

  -- Resultado
  outcome         VARCHAR(50),
  outcome_data    JSONB,
  conversation_id UUID REFERENCES conversations(id),

  -- Control de cola
  priority        INT NOT NULL DEFAULT 0,  -- mayor = más prioritario
  locked_until    TIMESTAMPTZ,  -- worker lock para evitar doble procesamiento

  created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- ── Índices ───────────────────────────────────────────────────
CREATE INDEX IF NOT EXISTS idx_campaigns_tenant       ON campaigns(tenant_id, status);
CREATE INDEX IF NOT EXISTS idx_campaign_contacts_camp ON campaign_contacts(campaign_id, status);
CREATE INDEX IF NOT EXISTS idx_campaign_contacts_next ON campaign_contacts(next_attempt_at, status)
  WHERE status = 'pending';
CREATE INDEX IF NOT EXISTS idx_campaign_contacts_lock ON campaign_contacts(locked_until)
  WHERE locked_until IS NOT NULL;

-- Triggers
CREATE TRIGGER trg_campaigns_updated
  BEFORE UPDATE ON campaigns
  FOR EACH ROW EXECUTE FUNCTION update_updated_at();

CREATE TRIGGER trg_campaign_contacts_updated
  BEFORE UPDATE ON campaign_contacts
  FOR EACH ROW EXECUTE FUNCTION update_updated_at();

-- Verificar
SELECT table_name FROM information_schema.tables
WHERE table_name IN ('campaigns', 'campaign_contacts');
