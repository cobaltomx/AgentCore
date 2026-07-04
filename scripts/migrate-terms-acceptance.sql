-- Aceptación de Términos de Servicio / Privacidad al primer login.
-- terms_accepted_at NULL = aún no aceptó (se le muestra el modal bloqueante).
ALTER TABLE users ADD COLUMN IF NOT EXISTS terms_accepted_at TIMESTAMPTZ;
