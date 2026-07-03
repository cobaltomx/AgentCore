-- ============================================================
-- AgentCore — Avatares (foto de perfil)
-- migrate-avatars.sql
-- Ejecutar: docker exec -i agentcore_postgres psql -U agentcore -d agentcore < scripts/migrate-avatars.sql
-- ============================================================

-- Doctores
ALTER TABLE doctors
  ADD COLUMN IF NOT EXISTS avatar_url TEXT;

-- Profesionales (consultorios)
ALTER TABLE professionals
  ADD COLUMN IF NOT EXISTS avatar_url TEXT;

-- Usuarios del dashboard
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS avatar_url TEXT;

-- Tenants (logo/foto del negocio)
ALTER TABLE tenants
  ADD COLUMN IF NOT EXISTS avatar_url TEXT;

DO $$ BEGIN
  RAISE NOTICE '✅ migrate-avatars.sql aplicado: columnas avatar_url agregadas';
END $$;
