-- migrate-notifications.sql
-- Tabla de notificaciones en tiempo real por tenant

CREATE TABLE IF NOT EXISTS notifications (
  id         BIGSERIAL PRIMARY KEY,
  tenant_id  UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  type       VARCHAR(60) NOT NULL,       -- new_conversation | new_lead | appointment_reminder | campaign_completed
  title      TEXT NOT NULL,
  body       TEXT,
  link       TEXT,                       -- URL relativa para navegar al detalle
  is_read    BOOLEAN NOT NULL DEFAULT false,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_notif_tenant_new
  ON notifications(tenant_id, id DESC)
  WHERE is_read = false;

CREATE INDEX IF NOT EXISTS idx_notif_tenant_since
  ON notifications(tenant_id, id ASC);
