# 🚀 Despliegue a producción — AgentCore

Guía para pasar de dev (ngrok) a un servidor real con **dominio fijo + TLS**.
Todo el stack corre en Docker; el reverse proxy (Caddy) obtiene el certificado
HTTPS automáticamente.

## 0. Requisitos
- Un VPS/servidor Linux con Docker + Docker Compose (2 vCPU / 4 GB es suficiente para el piloto).
- Un **dominio** apuntando (registro A) a la IP del servidor, p. ej. `app.tudominio.com`.
- Puertos 80 y 443 abiertos.

## 1. Clonar y configurar `.env`
```bash
git clone <tu-repo> agentcore && cd agentcore
cp .env.example .env
```
Editar `.env` y llenar **como mínimo**:
- `NODE_ENV=production`
- `POSTGRES_PASSWORD` — fuerte y única
- `REDIS_PASSWORD` — fuerte y única
- `JWT_SECRET` — genera uno: `node -e "console.log(require('crypto').randomBytes(64).toString('hex'))"`
- `PUBLIC_DOMAIN=app.tudominio.com` y `PUBLIC_URL=https://app.tudominio.com`
- `TWILIO_VALIDATE_SIGNATURE=true`
- Llaves de proveedores: `ANTHROPIC_API_KEY`, `OPENAI_API_KEY`, `DEEPGRAM_API_KEY`,
  `TWILIO_ACCOUNT_SID`, `TWILIO_AUTH_TOKEN`, `STRIPE_SECRET_KEY` (live), sender de WhatsApp.

## 2. Levantar el stack de producción
```bash
docker compose -f docker/docker-compose.prod.yml up -d --build
```
Diferencias vs. dev (endurecimiento): imagen construida (sin nodemon/bind-mounts),
Postgres y Redis **sin puertos al host**, Redis con contraseña, frontend con
Apache, y **Caddy** con TLS automático enrutando `/api`,`/webhooks`,`/health`→backend
y el resto→frontend.

## 3. Migraciones de base de datos
En el primer arranque, Postgres carga `scripts/schema.sql` (schema CONSOLIDADO
completo, 38 tablas). Después, registra el histórico de migraciones como baseline
para que futuras migraciones se apliquen incrementalmente:
```bash
# Instalación nueva (schema.sql ya se cargó al crear el volumen):
docker compose -f docker/docker-compose.prod.yml exec backend npm run db:migrate:baseline

# Ver estado en cualquier momento:
docker compose -f docker/docker-compose.prod.yml exec backend npm run db:migrate:status

# Futuras migraciones (archivos nuevos scripts/migrate-YYYY-MM-DD-*.sql):
docker compose -f docker/docker-compose.prod.yml exec backend npm run db:migrate
```
> `schema.sql` se regenera desde una BD al día con:
> `docker exec agentcore_postgres pg_dump -U agentcore -d agentcore --schema-only --no-owner --no-privileges > scripts/schema.sql`

## 4. Apuntar Twilio al dominio fijo
En la consola de Twilio, para el número productivo → **Voice → A call comes in**:
```
POST  https://app.tudominio.com/webhooks/twilio/voice
```
(Status callback: `.../webhooks/twilio/status`.)
Como `PUBLIC_URL` está definido, el backend genera automáticamente las URLs de
media-stream y callbacks sobre ese dominio (ya no ngrok). Con
`TWILIO_VALIDATE_SIGNATURE=true`, se rechaza cualquier request sin firma válida.

## 5. Backups automáticos
```bash
# Probar una vez:
./scripts/backup-db.sh
# Programar diario (cron del host), 3am, conserva 14 días:
( crontab -l 2>/dev/null; echo "0 3 * * * cd $(pwd) && ./scripts/backup-db.sh >> /var/log/agentcore-backup.log 2>&1" ) | crontab -
```
Restaurar: ver cabecera de `scripts/backup-db.sh`.

## 6. Verificación post-deploy
- [ ] `https://app.tudominio.com/health` responde `200` con candado TLS válido.
- [ ] Login al dashboard funciona (JWT nuevo).
- [ ] Panel Super Admin → Operación → **Saldos** en verde (LLM/voz/Twilio con crédito).
- [ ] Llamada de prueba: el bot contesta y conversa.
- [ ] `docker compose -f docker/docker-compose.prod.yml exec backend npm run db:migrate:status` → 0 pendientes.
- [ ] Postgres/Redis NO accesibles desde internet (`nmap` a 5432/6379 = cerrados).

## Notas
- **Escalado:** para más tráfico, separar Postgres/Redis a servicios gestionados y
  correr varias réplicas del backend detrás de Caddy.
- **Monitoreo:** apuntar UptimeRobot/healthchecks.io a `/health`.
- El worker de saldos ya notifica al superadmin si un proveedor se agota.
