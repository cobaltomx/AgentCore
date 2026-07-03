# Setup Cal.com Self-Hosted — Fase 2

## Opción A: Cal.com en tu propio VPS (recomendado)

### Requisitos
- VPS con 2 GB RAM mínimo (Hetzner CX21 ~$6/mes, DigitalOcean Basic ~$12/mes)
- Docker + Docker Compose instalados
- Dominio apuntado al VPS (ej: `cal.tudominio.com`)

### Instalación rápida
```bash
# 1. Clonar Cal.com
git clone https://github.com/calcom/cal.com.git
cd cal.com

# 2. Copiar variables de entorno
cp .env.example .env

# 3. Configurar .env mínimo:
# DATABASE_URL=postgresql://cal:password@localhost:5432/caldb
# NEXTAUTH_URL=https://cal.tudominio.com
# NEXTAUTH_SECRET=genera-un-string-aleatorio-largo
# CAL_COM_LICENSE_KEY=           # dejar vacío para community edition

# 4. Levantar con Docker
docker-compose up -d

# 5. Correr migraciones
docker exec cal_app npx prisma migrate deploy
```

### Crear API Key para cada tenant
1. Entrar a `https://cal.tudominio.com`
2. Crear cuenta del negocio (dentista, gimnasio, etc.)
3. Settings → Security → API Keys → Add new key
4. Nombre: "agentcore-integration"
5. Copiar key → guardar en `tenants.settings.calcom.apiKey`

### Crear Event Type (tipo de cita)
1. Dashboard → Event Types → New Event Type
2. Configurar: nombre, duración, disponibilidad
3. Copiar el ID numérico de la URL → guardar en `tenants.settings.calcom.eventTypeId`
   - Ejemplo: `cal.tudominio.com/admin/event-types/123` → ID = 123

---

## Opción B: Cal.com Cloud (más rápido para arrancar)

1. Ir a https://cal.com/pricing → Plan Team (~$12/mes)
2. Crear organización → invitar usuarios del tenant
3. Obtener API key: Settings → Developer → API Keys
4. `baseUrl` en la config = `https://api.cal.com` (default)

---

## Probar integración desde el backend

```bash
# Verificar slots disponibles para un tenant
curl -X GET "http://localhost:3000/api/v1/appointments/availability?days=3" \
  -H "Authorization: Bearer TU_JWT_TOKEN"

# Respuesta esperada:
# {
#   "slots": [
#     { "time": "2025-07-01T10:00:00-06:00", "display": "martes 1 de julio a las 10 de la mañana" },
#     ...
#   ],
#   "source": "calcom",   <-- o "fixed" si usa fallback
#   "total": 24
# }
```

---

## Configurar tenant demo en la DB

```bash
# Aplicar migración
psql $DATABASE_URL -f scripts/migrate-fase2.sql

# Actualizar con datos reales de tu Cal.com
psql $DATABASE_URL -c "
UPDATE tenants
SET settings = settings || jsonb_build_object(
  'calcom', jsonb_build_object(
    'baseUrl', 'https://cal.tudominio.com',
    'apiKey', 'cal_live_XXXXXXXXXXXX',
    'eventTypeId', 123
  )
)
WHERE slug = 'demo-dental';"
```

---

## Probar el flujo de voz completo con agendamiento

Cuando el agente está activo y alguien llama:

1. **Usuario:** "Quiero agendar una cita"
2. **Agente:** llama `check_availability` → obtiene slots de Cal.com
3. **Agente:** "Tenemos disponible mañana martes a las 10 de la mañana, o el miércoles a las 3 de la tarde. ¿Cuál te funciona?"
4. **Usuario:** "La del martes"
5. **Agente:** llama `save_lead` + `schedule_appointment`
6. **Agente:** "Perfecto, queda agendada tu cita para mañana martes a las 10 de la mañana. ¿Algo más en que pueda ayudarte?"

---

## Costos adicionales en Fase 2

| Componente | Costo |
|---|---|
| Cal.com self-hosted (VPS Hetzner) | ~$6-12 USD/mes |
| Cal.com Cloud (alternativa) | $12 USD/mes |
| Sin impacto en APIs de voz | $0 adicional |
