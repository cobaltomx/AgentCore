-- ── Reducción de no-shows ───────────────────────────────────────────────────
-- Añade seguimiento de confirmación de citas a la tabla appointments.
--   confirmation_status: estado del ciclo de confirmación
--   confirmation_requested_at: cuándo se pidió confirmar (en el recordatorio 24h)
--   confirmed_at / cancelled_at: timestamps de la respuesta del paciente
-- ────────────────────────────────────────────────────────────────────────────

ALTER TABLE appointments
  ADD COLUMN IF NOT EXISTS confirmation_status VARCHAR(20) NOT NULL DEFAULT 'pending'
    CHECK (confirmation_status IN ('pending','confirmed','cancelled','no_response')),
  ADD COLUMN IF NOT EXISTS confirmation_requested_at TIMESTAMPTZ,
  ADD COLUMN IF NOT EXISTS confirmed_at  TIMESTAMPTZ,
  ADD COLUMN IF NOT EXISTS cancelled_at  TIMESTAMPTZ;

-- Índice para buscar rápido la cita pendiente de un paciente al recibir su WhatsApp
CREATE INDEX IF NOT EXISTS idx_appt_confirm_lookup
  ON appointments (tenant_id, patient_phone, scheduled_at)
  WHERE confirmation_status = 'pending';
