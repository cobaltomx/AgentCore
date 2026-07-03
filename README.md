# AgentCore — Plataforma SaaS de Agentes IA

## Stack
- **Backend:** Node.js + Fastify
- **DB:** PostgreSQL 16 + Redis 7
- **Frontend:** PHP + Bootstrap 5
- **Infra local:** Docker Compose
- **Producción:** HostGator VPS

---

## Setup Local (primera vez)

### 1. Clonar y configurar variables
```bash
cp .env.example .env
# Edita .env con tus API keys
```

### 2. Levantar servicios con Docker
```bash
cd docker
docker-compose up -d
```
Esto levanta PostgreSQL, Redis y el backend.
El init.sql corre automáticamente y crea todo el schema + datos demo.

### 3. Verificar que funciona
```bash
curl http://localhost:3000/health
# {"status":"ok","version":"0.1.0",...}
```

### 4. Login de prueba
```bash
curl -X POST http://localhost:3000/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@demo-dental.com","password":"Admin123!"}'
```

---

## Estructura del proyecto
```
agentcore/
├── backend/
│   └── src/
│       ├── app.js              ← Entry point
│       ├── plugins/            ← DB, Redis, JWT, CORS
│       ├── routes/
│       │   ├── v1/             ← CRUD endpoints
│       │   └── webhooks/       ← Twilio, Meta
│       ├── services/           ← Lógica de negocio (Fase 1+)
│       ├── agents/             ← Motor de agentes IA (Fase 1+)
│       └── tools/              ← Function calling tools (Fase 2+)
├── frontend/                   ← PHP + Bootstrap (Fase 4)
├── docker/
│   └── docker-compose.yml
├── scripts/
│   └── init.sql                ← Schema completo
├── .env.example
└── README.md
```

---

## Fases de desarrollo
| Fase | Contenido |
|------|-----------|
| ✅ 0 | Setup, schema multitenancy, API base |
| 1    | Agente de voz (Twilio + Deepgram + LLM + TTS) |
| 2    | Agendamiento autónomo (Cal.com) |
| 3    | WhatsApp (Meta Cloud API) |
| 4    | Dashboard PHP + multitenancy real |
| 5    | RAG + knowledge base |
| 6    | Billing Stripe |
| 7    | Agente outbound |

---

## URLs locales
- Backend API: http://localhost:3000
- Health check: http://localhost:3000/health
- PostgreSQL: localhost:5432
- Redis: localhost:6379
