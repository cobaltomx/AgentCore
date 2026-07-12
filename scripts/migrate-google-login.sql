-- Login con Google: vincula la cuenta EXISTENTE (por email) al ID de Google
-- tras el primer login exitoso. No crea usuarios nuevos por sí sola.
ALTER TABLE users ADD COLUMN IF NOT EXISTS google_id TEXT;
CREATE UNIQUE INDEX IF NOT EXISTS uniq_users_google_id ON users (google_id) WHERE google_id IS NOT NULL;
