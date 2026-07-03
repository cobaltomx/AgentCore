-- ── Widget de chat web (canal webchat) ──────────────────────────────────────
-- Cada tenant recibe un widget_key PÚBLICO (va en el <script> del sitio del
-- cliente; no es secreto). La config visual vive en settings.widget.
-- ────────────────────────────────────────────────────────────────────────────

ALTER TABLE tenants
  ADD COLUMN IF NOT EXISTS widget_key VARCHAR(40) UNIQUE;

-- DEFAULT: todo tenant NUEVO genera su widget_key automáticamente
-- (sin esto, los tenants creados después de la migración quedaban sin clave
--  y su widget web devolvía 404).
ALTER TABLE tenants
  ALTER COLUMN widget_key SET DEFAULT 'wgt_' || replace(gen_random_uuid()::text, '-', '');

-- Backfill: generar widget_key para tenants existentes que no lo tengan
UPDATE tenants
SET widget_key = 'wgt_' || replace(gen_random_uuid()::text, '-', '')
WHERE widget_key IS NULL;

-- Índice para resolver el tenant desde el widget_key en cada request del widget
CREATE INDEX IF NOT EXISTS idx_tenants_widget_key
  ON tenants (widget_key) WHERE widget_key IS NOT NULL;
