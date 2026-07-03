# 🔍 Auditoría integral — puesta a punto para producción

> **Fecha:** 2 jul 2026 · **Alcance:** backend (75 js / 17.6k líneas), frontend (105 php / 24.5k líneas), BD, infra Docker, seguridad, resiliencia.
> **Estado general:** el código está sano (0 errores de sintaxis, sin código muerto relevante, 27/27 tests pasan, multi-tenancy bien aislado). Lo que bloquea producción es **infraestructura y resiliencia operativa**, no el código de negocio.

---

## 🔴 CRÍTICO — bloquea salir a producción

### C1. El proyecto NO está en control de versiones
`Documents/agentcore` no es repositorio git. Sin historial, sin rollback, sin respaldo del código.
**Acción:** `git init` + `.gitignore` (.env, node_modules, uploads) + primer commit + remoto privado (GitHub).

### C2. LLM sin saldo y sin resiliencia (la falla de hoy)
- Las 2 API keys (Anthropic y OpenAI) están **agotadas** → el bot contesta el saludo y muere.
- `llm-router` solo escala Haiku→Sonnet **dentro de Anthropic**; no hay fallback cruzado de proveedor.
- En llamada, ante error de LLM repite "tuve un problema ¿puedes repetirlo?" **en bucle infinito** (sin circuit breaker).
- Nadie se enteró hasta que falló una llamada real (sin alerta de saldo).
**Acción:** (a) recargar saldo; (b) fallback cruzado Anthropic⇄OpenAI en llm-router; (c) circuit breaker en llamada: a la 2ª falla consecutiva → mensaje de cortesía + save_lead + colgar; (d) check de salud de keys en panel Super Admin + alerta.

### C3. JWT_SECRET débil
Empieza con "agentc…" (predecible). Un atacante que lo adivine firma tokens de cualquier tenant/superadmin.
**Acción:** generar secreto aleatorio de 64 bytes, rotarlo en .env; invalida sesiones activas (aceptable).

### C4. Webhook de Twilio sin validar firma
`/webhooks/twilio/voice` no verifica `X-Twilio-Signature` → cualquiera que descubra la URL puede inyectar llamadas/transcripciones falsas y quemar minutos/LLM. (Stripe SÍ valida; Twilio no.)
**Acción:** middleware con `twilio.validateRequest` sobre la URL pública + auth token.

### C5. Infra en modo desarrollo
- `NODE_ENV=development`, backend corre con **nodemon** (`npm run dev`) y bind-mounts de código.
- **Postgres (5432) y Redis (6379) expuestos al host; Redis SIN password.**
- Sin backups de Postgres (volumen Docker sin estrategia).
**Acción:** compose de producción separado: `NODE_ENV=production`, `node src/server.js`, sin bind-mounts, puertos de BD/Redis solo en red interna, `requirepass` en Redis, `pg_dump` diario a almacenamiento externo.

### C6. Dependencia de ngrok (URL efímera)
Los webhooks de Twilio apuntan a un dominio que muere al reiniciar el túnel. `public-url.js` ya tiene el TODO.
**Acción:** dominio fijo + TLS (VPS con Caddy/nginx, o Cloudflare Tunnel gratuito con dominio propio) y setear `publicDomain` por tenant.

### C7. Cobro e integraciones reales pendientes (lo "redituable")
- Stripe en `sk_test` → checkouts de planes/anticipos/pedidos no cobran de verdad.
- WhatsApp en sandbox (63015/63016) → confirmaciones, fichas de propiedades y recordatorios **no llegan** a clientes reales. `META_PHONE_NUMBER_ID` vacío.
**Acción:** llave Stripe live + webhook secret; sender de WhatsApp aprobado con plantillas (Twilio WA o Meta Cloud API).

---

## 🟠 ALTO — arreglar antes o justo después del lanzamiento

| # | Hallazgo | Acción |
|---|----------|--------|
| A1 | **59 conversaciones "active" zombies** (>24h sin cerrar) — ensucian métricas y el dashboard | Job de cierre: `status='completed'` tras N horas de inactividad (cron en reminder-worker) |
| A2 | **Sin migration runner**: 20 SQL aplicados a mano, sin tabla de tracking → drift seguro entre dev/prod | Tabla `schema_migrations` + script que aplique pendientes en orden (o node-pg-migrate) |
| A3 | **Log spam**: el polling de notifications loguea cada request a nivel info (miles de líneas/día ahogaron el diagnóstico de hoy) | `logLevel: 'warn'` en esa ruta (Fastify config por ruta) |
| A4 | **console.log directo** en servicios (executor 30, workers 15) en vez de `app.log` | Pasar logger a servicios; los console.log no llevan contexto ni nivel |
| A5 | **Deuda de cerebro dividido**: simulador y webchat usan `system_prompt` crudo e ignoran `_promptFromConfig` (config estructurada) | Unificar: un solo builder de prompt para los 3 canales |
| A6 | **Sin CI**: los 27 tests existen pero nadie los corre automáticamente | GitHub Actions: node --test + php -l en cada push (depende de C1) |
| A7 | Llamada sin límite de duración de sesión ante LLM caído (watchdog de silencio re-engancha) | Cuenta de fallos por llamada (parte de C2c) |

## 🟡 MEDIO — deuda técnica a programar

| # | Hallazgo | Acción |
|---|----------|--------|
| M1 | `tools/executor.js` = **1537 líneas** (switch monolítico de ~25 tools) | Partir por dominio: `tools/catalog.js`, `tools/scheduling.js`, `tools/realestate.js`, `tools/leads.js` + registry |
| M2 | `voice-agent.js` 1171, `superadmin.js` 1055, `agent-editor.php` 1484, `knowledge-base.php` 1187 | Extraer módulos al tocarlos (no big-bang) |
| M3 | Búsqueda de catálogo por ILIKE sin índice trigram (escaneo completo) | OK con catálogos <1k items; si crece: índice GIN pg_trgm |
| M4 | `calcom-client.js` es solo el generador de slots fijos legado (nombre confuso) | Renombrar a `fixed-slots.js` cuando se toque |
| M5 | Estadísticas de pg_stat desactualizadas | `ANALYZE` periódico (autovacuum lo cubre; verificar en prod) |
| M6 | Sin monitoreo de uptime/errores | UptimeRobot/healthchecks.io al `/health` + notificación |

## 🟢 LO QUE ESTÁ BIEN (no tocar)

- **0 errores de sintaxis** en 180 archivos; **27/27 tests pasan**.
- **Sin código muerto**: todos los servicios referenciados, sin páginas huérfanas, sin funciones duplicadas (phone-utils/contacts centralizados en Fase 0 dieron fruto).
- **Multi-tenancy sólido**: todas las rutas v1 con guards; widget público con rate-limit por ruta + widget_key + gate is_ready; JWT server-side en sesión PHP; bcrypt; uploads con whitelist MIME.
- **Sin secretos hardcodeados** en el código (todo por env).
- **BD íntegra**: 0 huérfanos, 0 leads duplicados, índices correctos en tablas calientes (incl. uniq_leads_tenant_phone).
- Stripe webhook **sí** valida firma; reminder-worker no reintenta infinito; TTS con fallback Cartesia→Deepgram.

---

## 📋 Orden de ejecución sugerido

**Semana 1 — Fundaciones (sin costo externo):**
1. C1 git init + remoto ✦ 2. C3 JWT secret ✦ 3. C4 firma Twilio ✦ 4. C2b/c fallback cruzado + circuit breaker ✦ 5. A1 job de cierre de zombies ✦ 6. A3/A4 logging

**Semana 2 — Infra de producción:**
7. C5 compose de prod + Redis pass + backups ✦ 8. C6 dominio fijo ✦ 9. A2 migration runner ✦ 10. A6 CI

**Semana 3 — Monetización (requiere cuentas/pagos):**
11. C2a recarga LLM + alerta de saldo ✦ 12. C7 Stripe live + WhatsApp sender ✦ 13. Piloto inmobiliaria (Fase 1 del plan vertical)

---
*Generado por auditoría automatizada + manual. Actualizar al cerrar cada punto.*
