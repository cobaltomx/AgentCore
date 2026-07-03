# ✅ Checklist exhaustivo pre-producción — AgentCore

> Lista para revisar antes de salir a producción y que no se pase nada.
> Los ítems marcados **⚠️ CÓDIGO** son específicos de cómo está construido
> AgentCore hoy (los confirmé en el código) — esos son los que más fácil se
> olvidan. Los enlaces apuntan a las guías de detalle.

---

## 1. 🔐 Seguridad y credenciales

- [ ] **Rotar TODAS las credenciales** del `.env` → [guía](ROTAR-CREDENCIALES.md)
  - [ ] `JWT_SECRET` fuerte (64+ chars aleatorios) — el actual es débil/predecible
  - [ ] Anthropic, OpenAI, Twilio, Deepgram, Cartesia
  - [ ] **⚠️ CÓDIGO — `META_APP_SECRET` está FALTANTE** → sin él, el webhook de
        WhatsApp no verifica firma (cualquiera puede mandar mensajes falsos)
  - [ ] Stripe (`SECRET_KEY` + `WEBHOOK_SECRET`, mismo modo: live)
- [ ] **`.env` NO está en git** (`git check-ignore .env` debe imprimirlo)
- [ ] Si `.env` estuvo en git alguna vez → las claves viejas están quemadas, rótalas
- [ ] **Mover secretos fuera de `.env` plano** → gestor de secretos del hosting
      (Railway/Render/Fly vars, AWS Secrets Manager, Doppler…)
- [ ] **⚠️ CÓDIGO — CORS en producción**: `backend/src/plugins/index.js` permite
      `origin: true` (todo) solo cuando `NODE_ENV !== 'production'`. Confirma que
      en prod restringe a tus dominios (la ruta del widget tiene su CORS abierto
      aparte, eso es correcto)
- [ ] **⚠️ CÓDIGO — Cookies seguras**: `cookie_secure` se activa con
      `APP_ENV === 'production'` → asegúrate de poner `APP_ENV=production`
- [ ] `NODE_ENV=production` en el backend
- [ ] HTTPS en TODO (frontend, backend, webhooks) — sin HTTP plano
- [ ] Rate limiting activo (global + login + widget) — ya implementado, verifícalo
- [ ] Password del **superadmin** cambiada (no la de prueba `test1234`)
- [ ] Revisar que no queden contraseñas de prueba en ningún usuario

---

## 2. 🌐 Infraestructura y dominio

- [ ] Dominio real comprado + DNS configurado
- [ ] Certificados SSL/TLS válidos (Let's Encrypt o el del hosting)
- [ ] **URLs públicas HTTPS** para los 3 webhooks:
  - [ ] Twilio voz → `{API}/webhooks/twilio/...`
  - [ ] Meta WhatsApp → `{API}/webhooks/meta`
  - [ ] Stripe → `{API}/webhooks/stripe`
- [ ] **`APP_URL`** en `.env` apunta al dominio de producción (se usa para armar
      las URLs de los webhooks de Twilio y los success/cancel de Stripe)
- [ ] **PostgreSQL gestionado** (no el contenedor local de docker-compose):
      backups automáticos, alta disponibilidad, `DATABASE_URL` de prod
- [ ] **Redis gestionado/persistente** (sesiones + colas), `REDIS_URL` de prod
- [ ] Orquestación de prod definida (no el `docker-compose.yml` de dev tal cual)
- [ ] Límites de recursos / plan de escalado del backend
- [ ] El contenedor del **frontend PHP** corre en prod (lo agregamos al compose)

---

## 3. 🔌 Integraciones externas (cada una en modo producción)

### WhatsApp / Meta → [guía completa](WHATSAPP-PRODUCCION.md)
- [ ] **Verificación de negocio** de Meta aprobada (tarda 1–3 días)
- [ ] Número propio conectado (NO en la app de WhatsApp)
- [ ] **Token permanente** (System User), no el temporal de 24 h
- [ ] Webhook verificado + campo `messages` suscrito
- [x] **✅ CORREGIDO — Fallback peligroso**: `webhooks/meta.js` usaba "el primer
      tenant activo" si no resolvía el número → en multi-tenant enrutaba al cliente
      equivocado. Ahora el fallback SOLO corre fuera de producción (o con
      `META_ALLOW_SINGLE_TENANT_FALLBACK=on` para instalaciones de un solo tenant);
      en prod, un número no resuelto se ignora con warning.

### Twilio (voz)
- [ ] Número de producción comprado y verificado
- [ ] Webhooks del número apuntan a tu URL pública
- [ ] Auth Token de producción
- [ ] Caller ID / grabación de llamadas configurado según consentimiento legal

### Stripe (pagos)
- [ ] Llaves **live** (`sk_live_…`), no de prueba
- [ ] Endpoint de webhook creado en Stripe → `{API}/webhooks/stripe`
- [ ] `STRIPE_WEBHOOK_SECRET` del endpoint live
- [ ] **Planes y precios creados** en Stripe (`STRIPE_PRICE_STARTER/GROWTH/BUSINESS`,
      `STRIPE_PRICE_METER`) — sin ellos el checkout del SaaS falla
- [ ] Probar un cobro de anticipo real (el flujo que construimos)

### LLM e IA (Anthropic / OpenAI / Deepgram / Cartesia)
- [ ] Llaves de producción con cuota suficiente
- [ ] **⚠️ CÓDIGO — OpenAI sin cuota rompe el RAG**: los embeddings del Knowledge
      Base usan OpenAI (`rag/embeddings.js`). Hoy la key da `429 insufficient_quota`,
      así que los documentos quedan en estado `error`/`pending` y **el bot no usa
      la base de conocimiento** (responde solo con el system prompt). Necesitas una
      key de OpenAI con saldo, O cambiar el proveedor de embeddings (ej. Voyage).
      Tras arreglarlo, re-ingesta los documentos pendientes.
- [x] **✅ CORREGIDO — TTS de Cartesia roto en todo el sistema**: usaba el modelo
      `sonic-multilingual` y versión `2024-06-10`, ambos descontinuados (404). La
      voz de los agentes (preview Y llamadas reales) no sintetizaba. **Corregido** a
      `sonic-2` / `2024-11-13` (overridable por `CARTESIA_MODEL`). Además 2 de las
      4 voces del onboarding (Sofía, Valeria) tenían IDs inválidos → corregidos por
      IDs verificados. Verifica que tu llave Cartesia tenga saldo en producción.
- [ ] **⚠️ CÓDIGO — Modelo válido**: `llm-router.js` usa `claude-sonnet-4-6`
      (corregimos el `claude-sonnet-4-20250514` que daba 404). Verifica que tu
      API key tenga acceso a ese modelo
- [ ] Alertas de gasto activadas en cada proveedor (ver §7)

---

## 4. 🗄️ Base de datos

- [ ] **Aplicar TODAS las migraciones** en la base de prod (en orden). Hoy hay:
      `init.sql`, y los `migrate-*`: clinica, consultorio, fase2/3/5/6/7, avatars,
      notifications, simulator, noshows, deposits, insights, widget
- [ ] **Backups automáticos** configurados + **restauración probada** (un backup
      que no sabes restaurar no sirve)
- [ ] **Limpiar datos demo**: tenants `FitZone Demo`, `Clínica Dental Demo`,
      `Consultorios Demo` y sus usuarios de prueba — NO deben ir a prod
- [ ] Mantener (o recrear limpio) el tenant superadmin `AgentCore Platform`
- [ ] Verificar índices en tablas grandes (conversations, messages)
- [ ] Política de retención de datos (conversaciones, grabaciones, mensajes)

---

## 5. ⚙️ Configuración de producción (las trampas del código)

- [x] **✅ CORREGIDO — Widget apunta a localhost**: ahora `web-widget.php` arma el
      snippet desde `WIDGET_PUBLIC_URL` + `WIDGET_API_URL` (env, default dev) e incluye
      `data-api`, y `widget.js` cae a mismo-origen en prod (solo usa `:3001` en
      localhost). **En prod define `WIDGET_PUBLIC_URL` y `WIDGET_API_URL`** con tu
      dominio y el widget carga en sitios de clientes.
- [ ] `API_BASE_URL` del frontend PHP apunta al backend de prod
- [ ] `META_VERIFY_TOKEN` configurado en `.env` Y en el panel de Meta (ambos)
- [ ] Revisar que no haya `console.log` con datos sensibles
- [ ] Nivel de logs apropiado para prod (no `debug`)
- [x] **✅ CORREGIDO — Borrar tenant bloqueado por `audit_log`**: la FK
      `audit_log.tenant_id` ya tiene `ON DELETE CASCADE` (en `migrate-superadmin.sql`,
      aplicado a dev). Eliminar un tenant arrastra su historial de auditoría →
      "borrar cuenta" / GDPR funciona.

---

## 6. 🧪 QA funcional (probar end-to-end en prod/staging)

- [ ] **Onboarding** de un tenant nuevo completo
- [ ] **Simulador** → aprobar bot → `is_ready=true` activa los webhooks
- [ ] **Llamada de voz** real entra y el bot responde
- [ ] **WhatsApp** real entra y el bot responde
- [ ] **Widget web** carga en un sitio externo y responde
- [ ] **Captura de lead** funciona en los 3 canales
- [ ] **Agendamiento de cita** + recordatorio + confirmación (no-shows)
- [ ] **Cobro de anticipo** genera link y marca pagado al pagar
- [ ] **Reporte de Valor** y **Voz del cliente** muestran datos
- [ ] **Billing del SaaS**: checkout de plan → webhook activa el plan
- [ ] **Gate de plan**: Starter ve upsell de Voz del cliente; Growth+ tiene acceso
- [ ] **Notificaciones** llegan en tiempo real

---

## 7. 💰 Costos y alertas

- [ ] **Alertas de gasto** en CADA proveedor (clave para no llevarte sorpresas):
      OpenAI, Anthropic, Twilio, Meta, Deepgram, Cartesia, Stripe
- [ ] Tracking de uso (minutos/mensajes) funcionando — alimenta el billing metered
- [ ] Confirmar el margen: costo por conversación vs. lo que cobras
- [ ] La Voz del cliente ya es **premium (Growth+)** → no gasta tokens en Starter
- [ ] Definir un tope/circuit-breaker si un tenant dispara consumo anómalo

---

## 8. ⚖️ Legal y cumplimiento (México)

- [ ] **Aviso de privacidad** (LFPDPPP) publicado y enlazado
- [ ] **Términos y condiciones** del servicio
- [ ] **Consentimiento de grabación** de llamadas (Twilio graba voz)
- [ ] Cumplimiento de la **política de WhatsApp Business** de Meta (no spam, opt-in)
- [ ] Política de retención y borrado de datos personales
- [ ] Consentimiento de cookies en el dashboard si aplica
- [ ] Contrato/acuerdo de tratamiento de datos con cada cliente (tú procesas datos
      de SUS clientes)

---

## 9. 📊 Monitoreo y operación

- [ ] **Monitoreo de uptime** del backend, frontend y BD (alertas)
- [ ] **Error tracking** (Sentry o similar) en backend
- [ ] Logs centralizados y consultables
- [ ] Alertas de: webhook caído, BD sin conexión, Redis caído, cola de campañas atascada
- [ ] **Runbook de incidentes** (qué hacer si X se cae)
- [ ] Proceso de **onboarding de tenant nuevo** documentado
- [ ] Proceso de **soporte** (cómo reporta y se atiende un cliente)
- [ ] Health checks (`/health`, `/webhooks/*/status`) monitoreados

---

## 10. 🚀 Día del lanzamiento

- [ ] Desplegar en horario de bajo tráfico
- [ ] Backup completo justo antes
- [ ] Smoke test de los flujos críticos en producción real
- [ ] Tener a la mano cómo hacer **rollback** rápido
- [ ] Monitorear de cerca las primeras horas (logs + gastos + errores)
- [ ] Avisar a los primeros clientes piloto

---

## 🔴 Los 5 que MÁS se olvidan (resumen de las trampas ⚠️ CÓDIGO)

1. **`META_APP_SECRET` faltante** → webhook de WhatsApp sin verificar firma
2. ~~**Widget hardcodeado a localhost**~~ → ✅ corregido (env `WIDGET_PUBLIC_URL`/`WIDGET_API_URL` + `data-api`)
3. ~~**Fallback "primer tenant activo"** en el webhook de Meta~~ → ✅ corregido (gateado a no-prod)
4. **`APP_ENV=production`** → sin esto, las cookies no son `secure` y CORS queda abierto
5. **Llave Stripe placeholder** → los cobros no funcionan hasta poner una válida

---

*Documento generado el 2026-06-15. Revísalo completo antes de cada salida a producción.*

## Dominio público por tenant (cédulas / links de WhatsApp) — PENDIENTE PROD

⚠️ **ngrok es TEMPORAL.** Las cédulas de propiedad (`/p/:id`) y los links que se
envían por WhatsApp usan `publicBase(tenantSettings)` con esta prioridad:

1. `tenant.settings.publicDomain` — **dominio fijo por cliente (lo correcto en prod)**
2. `PUBLIC_BASE_URL` (env del deployment)
3. `APP_URL` (túnel ngrok) — **temporal**: la URL cambia al reiniciar y ngrok
   free muestra una pantalla intermedia antes de la página.

**Para producción de cada cliente/tenant:**
- Asignar un dominio fijo (subdominio propio, p.ej. `cliente.tudominio.com`)
  apuntando al backend, y guardarlo en `settings.publicDomain` de ese tenant.
- Mientras tanto, los links funcionan vía ngrok pero NO son estables ni
  presentables para clientes finales.
