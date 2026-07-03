-- ============================================================
-- Módulo Consultorios — Migración inicial
-- Ejecutar: docker exec -i agentcore_postgres psql -U agentcore -d agentcore < scripts/migrate-consultorio.sql
-- ============================================================

-- ── 1. Profesionales ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS professionals (
  id              uuid DEFAULT uuid_generate_v4() PRIMARY KEY,
  tenant_id       uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  name            varchar(200) NOT NULL,
  area            varchar(100),           -- Psicología, Derecho, Contabilidad…
  specialty       varchar(200),           -- sub-especialidad libre
  phone           varchar(30),
  email           varchar(200),
  bio             text,                   -- descripción corta para el agente
  license_number  varchar(50),
  avatar_initials varchar(4),
  color           varchar(20) DEFAULT '#696cff',
  is_active       boolean NOT NULL DEFAULT true,
  schedule_config jsonb NOT NULL DEFAULT '{}',  -- misma estructura que doctors
  video_link      text,                   -- Zoom/Meet personal (opción A)
  modality        varchar(20) DEFAULT 'both',   -- 'presencial'|'video'|'both'
  sort_order      integer DEFAULT 0,
  created_at      timestamptz NOT NULL DEFAULT now(),
  updated_at      timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_professionals_tenant ON professionals(tenant_id);

CREATE OR REPLACE TRIGGER trg_professionals_updated
  BEFORE UPDATE ON professionals
  FOR EACH ROW EXECUTE FUNCTION update_updated_at();

-- ── 2. Tipos de sesión ──────────────────────────────────────────
CREATE TABLE IF NOT EXISTS consultorio_session_types (
  id                  uuid DEFAULT uuid_generate_v4() PRIMARY KEY,
  tenant_id           uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  professional_ids    uuid[] DEFAULT '{}',        -- uno o varios profesionales
  name                varchar(100) NOT NULL,
  slug                varchar(60) NOT NULL,
  description         text,
  duration_mins       integer NOT NULL DEFAULT 60,
  session_count       integer NOT NULL DEFAULT 1, -- cuántas sesiones en la serie
  frequency           varchar(20) DEFAULT 'weekly', -- 'weekly'|'biweekly'|'monthly'|'single'
  modality            varchar(20) DEFAULT 'both',
  requires_deposit    boolean DEFAULT false,
  deposit_percent     integer DEFAULT 50 CHECK (deposit_percent BETWEEN 0 AND 100),
  deposit_fixed       numeric(10,2),              -- monto fijo alternativo
  price_per_session   numeric(10,2),              -- precio por sesión para calcular seña
  cancellation_hours  integer DEFAULT 24,
  voice_keywords      text[],
  prep_instructions   text,                       -- qué traer/preparar
  color               varchar(20) DEFAULT '#696cff',
  icon                varchar(60) DEFAULT 'bx-calendar-check',
  sort_order          integer DEFAULT 0,
  is_active           boolean DEFAULT true,
  created_at          timestamptz NOT NULL DEFAULT now(),
  updated_at          timestamptz NOT NULL DEFAULT now(),
  UNIQUE(tenant_id, slug)
);

CREATE INDEX IF NOT EXISTS idx_csession_types_tenant ON consultorio_session_types(tenant_id);

CREATE OR REPLACE TRIGGER trg_csession_types_updated
  BEFORE UPDATE ON consultorio_session_types
  FOR EACH ROW EXECUTE FUNCTION update_updated_at();

-- ── 3. Preguntas de calificación ────────────────────────────────
CREATE TABLE IF NOT EXISTS qualification_questions (
  id                  uuid DEFAULT uuid_generate_v4() PRIMARY KEY,
  tenant_id           uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  professional_id     uuid REFERENCES professionals(id) ON DELETE CASCADE,  -- NULL = todas
  session_type_id     uuid REFERENCES consultorio_session_types(id) ON DELETE CASCADE, -- NULL = todas
  question            text NOT NULL,
  hint                text,               -- guía para el agente al interpretar la respuesta
  answer_type         varchar(20) DEFAULT 'yesno',  -- 'yesno'|'text'|'scale'
  importance          smallint NOT NULL DEFAULT 5 CHECK (importance BETWEEN 1 AND 10),
  disqualify_on       varchar(10),        -- 'yes'|'no'|'any'|null  → si responde esto, descalifica
  sort_order          integer DEFAULT 0,
  is_active           boolean DEFAULT true,
  created_at          timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_qual_questions_tenant ON qualification_questions(tenant_id);

-- ── 4. Series de sesiones ───────────────────────────────────────
CREATE TABLE IF NOT EXISTS session_series (
  id                  uuid DEFAULT uuid_generate_v4() PRIMARY KEY,
  tenant_id           uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  professional_id     uuid REFERENCES professionals(id) ON DELETE SET NULL,
  session_type_id     uuid REFERENCES consultorio_session_types(id) ON DELETE SET NULL,
  conversation_id     uuid REFERENCES conversations(id) ON DELETE SET NULL,
  lead_id             uuid REFERENCES leads(id) ON DELETE SET NULL,
  patient_name        varchar(200),
  patient_phone       varchar(30),
  patient_email       varchar(200),
  total_sessions      integer NOT NULL DEFAULT 8,
  sessions_confirmed  integer DEFAULT 0,
  frequency           varchar(20) DEFAULT 'weekly',  -- 'weekly'|'biweekly'|'monthly'
  modality            varchar(20) DEFAULT 'presencial',
  status              varchar(30) DEFAULT 'pending_professional',
  -- pending_professional → pending_patient → active → completed | cancelled
  notes               text,
  created_at          timestamptz NOT NULL DEFAULT now(),
  updated_at          timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_series_tenant ON session_series(tenant_id);
CREATE INDEX IF NOT EXISTS idx_series_professional ON session_series(professional_id);

CREATE OR REPLACE TRIGGER trg_series_updated
  BEFORE UPDATE ON session_series
  FOR EACH ROW EXECUTE FUNCTION update_updated_at();

-- ── 5. Sesiones individuales ────────────────────────────────────
CREATE TABLE IF NOT EXISTS sessions (
  id                      uuid DEFAULT uuid_generate_v4() PRIMARY KEY,
  tenant_id               uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  series_id               uuid REFERENCES session_series(id) ON DELETE SET NULL,
  professional_id         uuid REFERENCES professionals(id) ON DELETE SET NULL,
  session_type_id         uuid REFERENCES consultorio_session_types(id) ON DELETE SET NULL,
  conversation_id         uuid REFERENCES conversations(id) ON DELETE SET NULL,
  lead_id                 uuid REFERENCES leads(id) ON DELETE SET NULL,
  patient_name            varchar(200),
  patient_phone           varchar(30),
  patient_email           varchar(200),
  session_number          integer DEFAULT 1,  -- número dentro de la serie (1 de N)
  scheduled_at            timestamptz NOT NULL,
  duration_mins           integer NOT NULL DEFAULT 60,
  status                  varchar(30) NOT NULL DEFAULT 'pending_professional',
  -- pending_professional | pending_patient | confirmed | completed | cancelled | no_show
  modality                varchar(20) DEFAULT 'presencial',
  video_link              text,
  deposit_status          varchar(20) DEFAULT 'none',  -- none|pending|paid|refunded
  deposit_amount          numeric(10,2),
  deposit_payment_link    text,
  deposit_payment_intent  varchar(200),
  professional_confirmed_at  timestamptz,
  patient_confirmed_at       timestamptz,
  cancellation_requested_at  timestamptz,
  reminder_24h_sent       boolean DEFAULT false,
  reminder_2h_sent        boolean DEFAULT false,
  post_session_sent       boolean DEFAULT false,
  notes                   text,   -- solo logística, nunca datos sensibles
  created_at              timestamptz NOT NULL DEFAULT now(),
  updated_at              timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_sessions_tenant     ON sessions(tenant_id);
CREATE INDEX IF NOT EXISTS idx_sessions_professional ON sessions(professional_id);
CREATE INDEX IF NOT EXISTS idx_sessions_series     ON sessions(series_id);
CREATE INDEX IF NOT EXISTS idx_sessions_scheduled  ON sessions(scheduled_at);
CREATE INDEX IF NOT EXISTS idx_sessions_phone      ON sessions(patient_phone);

CREATE OR REPLACE TRIGGER trg_sessions_updated
  BEFORE UPDATE ON sessions
  FOR EACH ROW EXECUTE FUNCTION update_updated_at();

-- ── 6. Log de recordatorios de sesiones ────────────────────────
CREATE TABLE IF NOT EXISTS session_reminders (
  id            uuid DEFAULT uuid_generate_v4() PRIMARY KEY,
  session_id    uuid NOT NULL REFERENCES sessions(id) ON DELETE CASCADE,
  tenant_id     uuid NOT NULL,
  reminder_type varchar(30) NOT NULL,  -- 'pending_prof'|'pending_pat'|'24h'|'2h'|'post'|'cancel_warning'
  channel       varchar(20) DEFAULT 'whatsapp',
  message_sid   varchar(100),
  status        varchar(20) DEFAULT 'sent',
  sent_at       timestamptz DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_session_reminders_session ON session_reminders(session_id);

-- ── Actualizar tenant industry → puede ser 'consultorio' ────────
-- (No requiere cambio de schema, se guarda en settings JSONB)

SELECT 'Migración consultorio completada ✓' AS resultado;
