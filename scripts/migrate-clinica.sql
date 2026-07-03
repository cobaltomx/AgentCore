-- ============================================================
-- AgentCore — Módulo Clínica Dental
-- migrate-clinica.sql
-- Ejecutar: docker exec -i agentcore_postgres psql -U agentcore -d agentcore < scripts/migrate-clinica.sql
-- ============================================================

-- ── 1. Doctores / Especialistas ───────────────────────────────
CREATE TABLE IF NOT EXISTS doctors (
  id              UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  tenant_id       UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  name            VARCHAR(200) NOT NULL,
  specialty       VARCHAR(100),          -- "Endodoncia", "Ortodoncia", "General"
  phone           VARCHAR(30),           -- teléfono directo / guardia
  email           VARCHAR(200),
  is_active       BOOLEAN NOT NULL DEFAULT true,
  schedule_config JSONB NOT NULL DEFAULT '{}',
  -- Formato:
  -- { "mon":[{"start":"09:00","end":"14:00"}],
  --   "tue":[{"start":"09:00","end":"14:00"},{"start":"16:00","end":"19:00"}],
  --   ... "sun":[] }
  color           VARCHAR(20) DEFAULT '#696cff',
  avatar_initials VARCHAR(4),
  sort_order      INTEGER DEFAULT 0,
  created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_doctors_tenant ON doctors(tenant_id);
-- Consultorio/sala asignado al especialista
ALTER TABLE doctors ADD COLUMN IF NOT EXISTS room VARCHAR(80);

-- ── 2. Tipos de servicio ──────────────────────────────────────
CREATE TABLE IF NOT EXISTS service_types (
  id                  UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  tenant_id           UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  name                VARCHAR(100) NOT NULL,   -- "Limpieza dental"
  slug                VARCHAR(60)  NOT NULL,   -- "limpieza"
  duration_mins       INTEGER NOT NULL DEFAULT 30,
  is_urgency          BOOLEAN NOT NULL DEFAULT false,
  requires_deposit    BOOLEAN NOT NULL DEFAULT false,
  deposit_amount      DECIMAL(10,2) DEFAULT 0,
  prep_instructions   TEXT,   -- "Cepíllese antes", "Ayuno de 6 h"
  post_instructions   TEXT,   -- "No ingiera alimentos por 2 h"
  voice_keywords      TEXT[],                  -- ["limpieza","limpieza dental","profilaxis"]
  default_doctor_id   UUID REFERENCES doctors(id) ON DELETE SET NULL,
  color               VARCHAR(20) DEFAULT '#696cff',
  icon                VARCHAR(60) DEFAULT 'bx-tooth',
  sort_order          INTEGER DEFAULT 0,
  is_active           BOOLEAN NOT NULL DEFAULT true,
  created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
  UNIQUE(tenant_id, slug)
);

CREATE INDEX IF NOT EXISTS idx_service_types_tenant ON service_types(tenant_id);

-- ── 3. Extender appointments ──────────────────────────────────
ALTER TABLE appointments
  ADD COLUMN IF NOT EXISTS service_type_id     UUID REFERENCES service_types(id) ON DELETE SET NULL,
  ADD COLUMN IF NOT EXISTS doctor_id           UUID REFERENCES doctors(id)       ON DELETE SET NULL,
  ADD COLUMN IF NOT EXISTS is_urgency          BOOLEAN NOT NULL DEFAULT false,
  ADD COLUMN IF NOT EXISTS deposit_status      VARCHAR(30) NOT NULL DEFAULT 'none',
  -- 'none' | 'pending' | 'paid' | 'waived' | 'refunded'
  ADD COLUMN IF NOT EXISTS deposit_payment_link TEXT,
  ADD COLUMN IF NOT EXISTS deposit_payment_intent VARCHAR(200),
  ADD COLUMN IF NOT EXISTS deposit_amount      DECIMAL(10,2),
  ADD COLUMN IF NOT EXISTS patient_name        VARCHAR(200),
  ADD COLUMN IF NOT EXISTS patient_phone       VARCHAR(30),
  ADD COLUMN IF NOT EXISTS reminder_24h_sent   BOOLEAN NOT NULL DEFAULT false,
  ADD COLUMN IF NOT EXISTS reminder_2h_sent    BOOLEAN NOT NULL DEFAULT false,
  ADD COLUMN IF NOT EXISTS post_instr_sent     BOOLEAN NOT NULL DEFAULT false;

CREATE INDEX IF NOT EXISTS idx_appt_doctor      ON appointments(doctor_id);
CREATE INDEX IF NOT EXISTS idx_appt_reminder    ON appointments(tenant_id, scheduled_at, reminder_24h_sent)
  WHERE status NOT IN ('cancelled');

-- ── 4. Tabla de guardias / turnos de urgencia ─────────────────
CREATE TABLE IF NOT EXISTS urgency_shifts (
  id          UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  tenant_id   UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  doctor_id   UUID REFERENCES doctors(id) ON DELETE SET NULL,
  day_of_week SMALLINT NOT NULL, -- 0=dom, 1=lun, ..., 6=sab
  start_time  TIME NOT NULL,
  end_time    TIME NOT NULL,
  phone       VARCHAR(30) NOT NULL,  -- número al que se transfiere
  is_active   BOOLEAN NOT NULL DEFAULT true,
  created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_urgency_shifts_tenant ON urgency_shifts(tenant_id);

-- ── 5. Historial de recordatorios enviados ─────────────────────
CREATE TABLE IF NOT EXISTS appointment_reminders (
  id              UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  appointment_id  UUID NOT NULL REFERENCES appointments(id) ON DELETE CASCADE,
  tenant_id       UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  reminder_type   VARCHAR(40) NOT NULL, -- '24h' | '2h' | 'post' | 'deposit'
  channel         VARCHAR(20) NOT NULL DEFAULT 'whatsapp',
  status          VARCHAR(20) NOT NULL DEFAULT 'sent', -- 'sent' | 'failed'
  message_sid     VARCHAR(100),
  sent_at         TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_reminders_appt ON appointment_reminders(appointment_id);

-- ── 6. Trigger updated_at para doctors ───────────────────────
DO $$ BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_trigger WHERE tgname = 'trg_doctors_updated'
  ) THEN
    CREATE TRIGGER trg_doctors_updated
      BEFORE UPDATE ON doctors
      FOR EACH ROW EXECUTE FUNCTION update_updated_at();
  END IF;
END $$;

-- ── Done ──────────────────────────────────────────────────────
DO $$ BEGIN
  RAISE NOTICE '✅ migrate-clinica.sql aplicado correctamente';
END $$;
