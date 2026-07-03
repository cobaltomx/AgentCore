-- ============================================================
-- AgentCore — Schema Base Multitenancy
-- Fase 0 · Versión 1.0
-- ============================================================

-- Extensiones
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
CREATE EXTENSION IF NOT EXISTS "pgcrypto";

-- ============================================================
-- TENANTS (clientes de la plataforma / empresas)
-- ============================================================
CREATE TABLE tenants (
  id              UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  slug            VARCHAR(60) UNIQUE NOT NULL,        -- "dental-rodriguez", usado en URLs
  name            VARCHAR(120) NOT NULL,
  plan            VARCHAR(30) NOT NULL DEFAULT 'starter', -- starter | growth | business | enterprise
  status          VARCHAR(20) NOT NULL DEFAULT 'active',  -- active | suspended | trial | cancelled
  timezone        VARCHAR(60) NOT NULL DEFAULT 'America/Mexico_City',
  locale          VARCHAR(10) NOT NULL DEFAULT 'es-MX',
  -- Límites del plan
  max_agents      INT NOT NULL DEFAULT 1,
  max_minutes_mo  INT NOT NULL DEFAULT 500,
  minutes_used_mo INT NOT NULL DEFAULT 0,
  -- Metadata
  settings        JSONB NOT NULL DEFAULT '{}',        -- config flexible por tenant
  created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- ============================================================
-- USERS (usuarios del dashboard admin — pertenecen a un tenant)
-- ============================================================
CREATE TABLE users (
  id              UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  tenant_id       UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  email           VARCHAR(254) UNIQUE NOT NULL,
  password_hash   TEXT NOT NULL,
  name            VARCHAR(120) NOT NULL,
  role            VARCHAR(30) NOT NULL DEFAULT 'admin',  -- admin | manager | viewer
  is_active       BOOLEAN NOT NULL DEFAULT TRUE,
  last_login_at   TIMESTAMPTZ,
  created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- ============================================================
-- AGENTS (los agentes IA configurados por tenant)
-- ============================================================
CREATE TABLE agents (
  id              UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  tenant_id       UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  name            VARCHAR(120) NOT NULL,              -- "Recepcionista Clínica Rodríguez"
  voice_id        VARCHAR(100),                       -- ElevenLabs/Cartesia voice ID
  llm_model       VARCHAR(60) NOT NULL DEFAULT 'gpt-4o-mini',
  system_prompt   TEXT NOT NULL DEFAULT '',
  language        VARCHAR(10) NOT NULL DEFAULT 'es-MX',
  -- Canal de entrada
  channel         VARCHAR(30) NOT NULL DEFAULT 'voice', -- voice | whatsapp | webchat | sms
  phone_number    VARCHAR(30),                         -- número Twilio asignado
  whatsapp_number VARCHAR(30),
  -- Estado
  is_active       BOOLEAN NOT NULL DEFAULT TRUE,
  -- Config adicional
  config          JSONB NOT NULL DEFAULT '{}',
  created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- ============================================================
-- CONVERSATIONS (cada llamada / sesión de chat)
-- ============================================================
CREATE TABLE conversations (
  id              UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  tenant_id       UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  agent_id        UUID NOT NULL REFERENCES agents(id),
  -- Quién contactó
  contact_phone   VARCHAR(30),
  contact_name    VARCHAR(120),
  contact_email   VARCHAR(254),
  -- Canal y estado
  channel         VARCHAR(30) NOT NULL DEFAULT 'voice',
  status          VARCHAR(30) NOT NULL DEFAULT 'active', -- active | completed | failed | transferred
  -- Duración (voz)
  started_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  ended_at        TIMESTAMPTZ,
  duration_secs   INT,
  -- Resultado
  outcome         VARCHAR(50),   -- appointment_booked | lead_captured | faq_resolved | escalated
  outcome_data    JSONB,         -- datos estructurados del resultado (cita agendada, etc.)
  -- Metadatos
  recording_url   TEXT,
  summary         TEXT,          -- resumen generado por LLM al cerrar
  sentiment       VARCHAR(20),   -- positive | neutral | negative
  metadata        JSONB NOT NULL DEFAULT '{}',
  created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- ============================================================
-- MESSAGES (turnos de conversación)
-- ============================================================
CREATE TABLE messages (
  id              UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  conversation_id UUID NOT NULL REFERENCES conversations(id) ON DELETE CASCADE,
  tenant_id       UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  role            VARCHAR(20) NOT NULL,  -- user | assistant | tool
  content         TEXT NOT NULL,
  tool_name       VARCHAR(60),           -- si role=tool, qué herramienta se invocó
  tool_input      JSONB,
  tool_output     JSONB,
  tokens_used     INT,
  latency_ms      INT,                   -- tiempo de respuesta del LLM
  created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- ============================================================
-- LEADS (prospectos capturados por los agentes)
-- ============================================================
CREATE TABLE leads (
  id              UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  tenant_id       UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  conversation_id UUID REFERENCES conversations(id),
  -- Datos del prospecto
  name            VARCHAR(120),
  phone           VARCHAR(30),
  email           VARCHAR(254),
  -- Estado CRM
  status          VARCHAR(30) NOT NULL DEFAULT 'new', -- new | contacted | qualified | converted | lost
  score           INT DEFAULT 0,           -- 0-100, calculado por el agente
  notes           TEXT,
  -- Fuente
  source_channel  VARCHAR(30),
  source_agent_id UUID REFERENCES agents(id),
  -- Custom fields por tenant
  custom_data     JSONB NOT NULL DEFAULT '{}',
  created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- ============================================================
-- APPOINTMENTS (citas agendadas por los agentes)
-- ============================================================
CREATE TABLE appointments (
  id              UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  tenant_id       UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  conversation_id UUID REFERENCES conversations(id),
  lead_id         UUID REFERENCES leads(id),
  -- Datos de la cita
  title           VARCHAR(200),
  scheduled_at    TIMESTAMPTZ NOT NULL,
  duration_mins   INT NOT NULL DEFAULT 60,
  location        TEXT,
  notes           TEXT,
  -- Estado
  status          VARCHAR(30) NOT NULL DEFAULT 'pending', -- pending | confirmed | cancelled | completed
  -- Referencia externa (Cal.com, Google Calendar, etc.)
  external_ref    VARCHAR(200),
  external_source VARCHAR(60),  -- calcom | gcal | manual
  -- Recordatorios
  reminder_sent   BOOLEAN NOT NULL DEFAULT FALSE,
  created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- ============================================================
-- KNOWLEDGE BASE (documentos para RAG — Fase 5)
-- Tabla creada ahora, se llena en Fase 5
-- ============================================================
CREATE TABLE kb_documents (
  id              UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  tenant_id       UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  agent_id        UUID REFERENCES agents(id),          -- NULL = aplica a todos los agentes del tenant
  title           VARCHAR(200) NOT NULL,
  content         TEXT,
  file_url        TEXT,
  file_type       VARCHAR(20),     -- pdf | txt | url | faq
  chunk_count     INT DEFAULT 0,   -- cuántos chunks vectorizados tiene
  status          VARCHAR(20) NOT NULL DEFAULT 'pending', -- pending | processing | ready | error
  created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- ============================================================
-- AUDIT LOG (trazabilidad de acciones admin)
-- ============================================================
CREATE TABLE audit_log (
  id              BIGSERIAL PRIMARY KEY,
  tenant_id       UUID REFERENCES tenants(id),
  user_id         UUID REFERENCES users(id),
  action          VARCHAR(100) NOT NULL,
  resource_type   VARCHAR(60),
  resource_id     UUID,
  ip_address      INET,
  metadata        JSONB,
  created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- ============================================================
-- ÍNDICES CRÍTICOS DE PERFORMANCE
-- ============================================================
CREATE INDEX idx_users_tenant         ON users(tenant_id);
CREATE INDEX idx_agents_tenant        ON agents(tenant_id);
CREATE INDEX idx_conversations_tenant ON conversations(tenant_id);
CREATE INDEX idx_conversations_agent  ON conversations(agent_id);
CREATE INDEX idx_conversations_status ON conversations(tenant_id, status);
CREATE INDEX idx_messages_conv        ON messages(conversation_id);
CREATE INDEX idx_messages_tenant      ON messages(tenant_id);
CREATE INDEX idx_leads_tenant         ON leads(tenant_id);
CREATE INDEX idx_leads_status         ON leads(tenant_id, status);
CREATE INDEX idx_appointments_tenant  ON appointments(tenant_id);
CREATE INDEX idx_appointments_date    ON appointments(tenant_id, scheduled_at);
CREATE INDEX idx_audit_tenant         ON audit_log(tenant_id, created_at DESC);

-- ============================================================
-- ROW LEVEL SECURITY (aislamiento entre tenants)
-- ============================================================
ALTER TABLE users          ENABLE ROW LEVEL SECURITY;
ALTER TABLE agents         ENABLE ROW LEVEL SECURITY;
ALTER TABLE conversations  ENABLE ROW LEVEL SECURITY;
ALTER TABLE messages       ENABLE ROW LEVEL SECURITY;
ALTER TABLE leads          ENABLE ROW LEVEL SECURITY;
ALTER TABLE appointments   ENABLE ROW LEVEL SECURITY;
ALTER TABLE kb_documents   ENABLE ROW LEVEL SECURITY;

-- Nota: Las policies RLS se aplican en Fase 4 cuando implementemos auth completo.
-- Por ahora el backend filtra por tenant_id en todas las queries.

-- ============================================================
-- TENANT DEMO (seed para desarrollo local)
-- ============================================================
INSERT INTO tenants (slug, name, plan, max_agents, max_minutes_mo) VALUES
  ('demo-dental', 'Clínica Dental Demo', 'growth', 3, 2000),
  ('demo-gym', 'FitZone Gimnasio Demo', 'starter', 1, 500);

-- Usuario admin para tenant demo
INSERT INTO users (tenant_id, email, password_hash, name, role)
SELECT 
  id,
  'admin@demo-dental.com',
  crypt('Admin123!', gen_salt('bf')),
  'Admin Demo',
  'admin'
FROM tenants WHERE slug = 'demo-dental';

-- Agente demo
INSERT INTO agents (tenant_id, name, system_prompt, channel, llm_model)
SELECT
  id,
  'Recepcionista IA',
  'Eres una recepcionista amable de la Clínica Dental Demo. Tu objetivo es agendar citas, responder preguntas sobre servicios y capturar datos de contacto. Habla siempre en español de México, de forma profesional pero cálida. Si no sabes algo, ofrece transferir con el personal.',
  'voice',
  'gpt-4o-mini'
FROM tenants WHERE slug = 'demo-dental';

-- ============================================================
-- FUNCIÓN: updated_at automático
-- ============================================================
CREATE OR REPLACE FUNCTION update_updated_at()
RETURNS TRIGGER AS $$
BEGIN NEW.updated_at = NOW(); RETURN NEW; END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_tenants_updated      BEFORE UPDATE ON tenants      FOR EACH ROW EXECUTE FUNCTION update_updated_at();
CREATE TRIGGER trg_users_updated        BEFORE UPDATE ON users         FOR EACH ROW EXECUTE FUNCTION update_updated_at();
CREATE TRIGGER trg_agents_updated       BEFORE UPDATE ON agents        FOR EACH ROW EXECUTE FUNCTION update_updated_at();
CREATE TRIGGER trg_leads_updated        BEFORE UPDATE ON leads         FOR EACH ROW EXECUTE FUNCTION update_updated_at();
CREATE TRIGGER trg_appointments_updated BEFORE UPDATE ON appointments  FOR EACH ROW EXECUTE FUNCTION update_updated_at();
CREATE TRIGGER trg_kb_updated           BEFORE UPDATE ON kb_documents  FOR EACH ROW EXECUTE FUNCTION update_updated_at();
