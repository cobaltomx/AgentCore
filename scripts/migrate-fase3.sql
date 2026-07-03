-- ============================================================
-- Migración Fase 3 — WhatsApp por tenant
-- psql $DATABASE_URL -f scripts/migrate-fase3.sql
-- ============================================================

-- Agregar config WhatsApp al tenant demo-dental
-- (llenar con datos reales después de crear la app en Meta)
UPDATE tenants
SET settings = settings || jsonb_build_object(
  'whatsapp', jsonb_build_object(
    'phoneNumberId', '',          -- META_PHONE_NUMBER_ID de tu app
    'accessToken',   '',          -- Token de larga duración
    'businessId',    ''           -- WhatsApp Business Account ID
  )
)
WHERE slug = 'demo-dental';

-- Crear agente WhatsApp para demo-dental
INSERT INTO agents (tenant_id, name, system_prompt, channel, llm_model)
SELECT
  t.id,
  'Asistente WhatsApp',
  'Eres un asistente amable de la Clínica Dental Demo atendiendo por WhatsApp.
Tu objetivo es agendar citas, responder preguntas sobre servicios y capturar datos de contacto.
Habla en español de México, de forma profesional pero cálida.
Puedes usar listas y formato de texto enriquecido.
Si no puedes resolver algo, ofrece conectar con el equipo.',
  'whatsapp',
  'gpt-4o-mini'
FROM tenants t WHERE t.slug = 'demo-dental'
ON CONFLICT DO NOTHING;

-- Verificar
SELECT a.name, a.channel, t.slug
FROM agents a JOIN tenants t ON t.id = a.tenant_id
WHERE t.slug = 'demo-dental';
