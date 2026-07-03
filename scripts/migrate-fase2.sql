-- ============================================================
-- Migración Fase 2 — Configuración de agendamiento por tenant
-- Ejecutar: psql $DATABASE_URL -f scripts/migrate-fase2.sql
-- ============================================================

-- Agregar config de agendamiento al tenant demo-dental
UPDATE tenants
SET settings = jsonb_build_object(
  -- Configuración Cal.com (completar con datos reales)
  'calcom', jsonb_build_object(
    'baseUrl',     'https://cal.tudominio.com',   -- URL de tu Cal.com self-hosted
    'apiKey',      '',                              -- API key del tenant en Cal.com
    'eventTypeId', 0                               -- ID del tipo de evento en Cal.com
  ),
  -- Fallback: slots fijos si Cal.com no está configurado
  'scheduling', jsonb_build_object(
    'workDays',         ARRAY[1,2,3,4,5],          -- Lun-Vie (0=dom, 6=sab)
    'startHour',        9,                          -- 9:00 AM
    'endHour',          18,                         -- 6:00 PM
    'slotDurationMins', 30,                         -- cada 30 minutos
    'timezone',         'America/Mexico_City',
    'blockedDates',     ARRAY[]::text[]             -- fechas bloqueadas ["2025-12-25"]
  )
)
WHERE slug = 'demo-dental';

-- Config para demo-gym (solo slots fijos, sin Cal.com)
UPDATE tenants
SET settings = jsonb_build_object(
  'scheduling', jsonb_build_object(
    'workDays',         ARRAY[1,2,3,4,5,6],        -- Lun-Sab
    'startHour',        7,                          -- 7:00 AM
    'endHour',          21,                         -- 9:00 PM
    'slotDurationMins', 60,
    'timezone',         'America/Mexico_City',
    'blockedDates',     ARRAY[]::text[]
  )
)
WHERE slug = 'demo-gym';

-- Verificar
SELECT slug, settings FROM tenants WHERE slug IN ('demo-dental', 'demo-gym');
