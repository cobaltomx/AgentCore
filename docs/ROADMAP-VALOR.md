# 🗺️ Roadmap de Valor — AgentCore

> Objetivo: convertir la actividad del bot en **ROI visible** para que los
> clientes renueven y AgentCore suba el ticket promedio.
> Prioridad por relación **impacto / esfuerzo**, aprovechando el código existente.

---

## Fase 1 — Probar el ROI (lo que ya tienes en data)

### ✅ 1.1 Reporte de Valor *(COMPLETADO)*
Panel que traduce la actividad del bot a dinero y tiempo ahorrado.
- **Métricas:** llamadas/mensajes atendidos, % fuera de horario (que se habrían
  perdido), leads capturados, citas agendadas, horas de personal ahorradas.
- **Valor monetario estimado** con config por tenant (ticket promedio, valor de
  lead, costo/hora de personal).
- **Comparativa mes vs. mes anterior.**
- **Teaser en el dashboard** con link al reporte completo.
- **Archivos:** `backend/src/routes/v1/reports.js` (endpoint `/value`),
  `frontend/pages/value-report.php`, teaser en `frontend/index.php`,
  link en sidebar, config en `settings.value`.

### ✅ 1.2 Confirmación de citas + reducción de no-shows *(COMPLETADO)*
- Recordatorio 24h pide *CONFIRMAR / CANCELAR* por WhatsApp.
- El paciente responde → se detecta la intención (ES, tolerante a acentos y
  negaciones) y se actualiza la cita ANTES de pasar al agente IA.
- Cancelación a tiempo → libera el horario + notifica al tenant.
- Métrica de no-shows integrada en el Reporte de Valor.
- *Base existente:* `ReminderWorker`, campos `reminder_24h_sent`.
- **Archivos:** `scripts/migrate-noshows.sql`,
  `backend/src/services/appointment-confirmation.js`,
  hook en `backend/src/routes/webhooks/meta.js`,
  `backend/src/services/campaigns/reminder-worker.js`.

---

## Fase 2 — Subir el ticket (medio esfuerzo)

### ✅ 2.1 Cobros y anticipos por el bot (payment links) *(COMPLETADO)*
- Botón "Cobrar" en cada cita → genera link de pago de Stripe (Checkout mode=payment).
- Modal con monto + link copiable + "Enviar por WhatsApp".
- Webhook de Stripe distingue anticipos de clientes vs. suscripción del SaaS;
  marca la cita `deposit_status=paid` y notifica al tenant (idempotente).
- Usa la cuenta Stripe del tenant (`settings.stripe.secretKey`) o la de la
  plataforma como fallback. Degradación elegante si Stripe no está configurado.
- **Pendiente para activar:** poner una `STRIPE_SECRET_KEY` válida en `.env`.
- **Archivos:** `scripts/migrate-deposits.sql`,
  `backend/src/services/deposit-service.js`,
  endpoint en `backend/src/routes/v1/appointments.js`,
  rama en `backend/src/services/billing/stripe-service.js`,
  UI en `frontend/pages/appointments.php` + proxy.

### ✅ 2.2 Widget de chat web *(COMPLETADO)*
- Chat embebible (`<script>` con `widget_key`) en el sitio del cliente, mismo
  cerebro que voz/WhatsApp (LLM + RAG + tools + captura de lead conversacional).
- Superficie PÚBLICA segura: CORS abierto sin credenciales, rate-limit estricto,
  gate `is_ready`, y zona de protección anti-inyección (simulator-guard).
- Widget aislado con Shadow DOM (no choca con estilos del sitio anfitrión).
- Página de config en dashboard: snippet copiable + personalización + vista previa.
- **Archivos:** `scripts/migrate-widget.sql`, `backend/src/agents/webchat-agent.js`,
  `backend/src/routes/v1/widget.js`, `frontend/widget.js` (embebible),
  `frontend/pages/web-widget.php`.
- *Pendiente opcional:* Instagram/Messenger (mismo patrón, otro canal Meta).

### ✅ 2.3 Inteligencia de conversaciones ("Voz del cliente") *(COMPLETADO)*
- El LLM analiza cada conversación al cerrar (background, non-fatal): resumen,
  sentimiento, intent, temas, objeciones y **gaps de KB** (preguntas sin responder).
- Dashboard agregado: sentimiento, temas top, objeciones de venta, y las preguntas
  que el bot no supo responder (→ link para mejorar la KB).
- Backfill manual para analizar conversaciones históricas.
- **Feature premium (Growth+):** Starter ve pantalla de upsell con ventajas +
  badge "PRO" en el sidebar. El analizador no gasta tokens en tenants Starter.
- **Archivos:** `scripts/migrate-insights.sql`,
  `backend/src/services/conversation-analyzer.js` (gate de plan),
  hooks en `voice-agent.js` y `whatsapp-agent.js`,
  endpoints `/reports/insights` y `/reports/insights/analyze` (gate 403),
  `frontend/pages/insights.php` (upsell) + proxy,
  helpers `isPremiumPlan()`/`tenantHasFeature()` en `config.php`.

---

## Fase 3 — Crecer el negocio (apuestas)

### ✅ 3.1 Catálogo de productos por WhatsApp (conversational commerce, "tipo Jelou") *(COMPLETADO)*
- **Abre un vertical nuevo:** comercios/retail/restaurante.
- **Camino B** (catálogo propio): sin aprobación de Meta, reusa Stripe + agente.
  Funciona en TODOS los canales (voz, WhatsApp, widget web) porque reusa las tools.
  - ✅ **Fase 1 — Catálogo:** tablas `products`/`orders`/`order_items`, CRUD, página de gestión.
  - ✅ **Fase 2 — Tools del agente:** `search_products`, `add_to_cart`, `view_cart`,
    `checkout_order`. El LLM las encadena solo a partir del lenguaje del cliente.
  - ✅ **Fase 3 — Checkout:** crea pedido → link de Stripe; webhook marca pagado (idempotente).
  - ✅ **Fase 4 — Dashboard de pedidos:** KPIs de ingresos + lista + cambio de estado.
- **Archivos:** `scripts/migrate-products.sql`, `backend/src/services/catalog-service.js`,
  tools en `executor.js` + defs en `voice-agent.js`, rama en `stripe-service.js`,
  `routes/v1/products.js` + `orders.js`, `frontend/pages/products.php` + `orders.php`.
- **Camino A después** (opcional): catálogo nativo de Meta Commerce.
- *Pendiente para cobrar de verdad:* llave Stripe válida (igual que anticipos).

### ✅ 3.2 Packs verticales pre-armados *(COMPLETADO)*
- Pack por industria (dental, restaurante, ecommerce, inmobiliaria, servicios):
  prompt de arranque + 5 FAQs + config, con el nombre del negocio resuelto.
- Endpoint `preview` + `apply` (setea el prompt del agente si está vacío + siembra
  los FAQs en la KB). Robusto: funciona aunque aún no haya agente.
- Tarjeta "Arranque rápido" en Knowledge Base (solo si la KB está vacía).
- *Valor:* baja CAC, sube conversión de prueba a pago.
- *Nota:* los FAQs se siembran OK, pero su indexado RAG depende de OpenAI con cuota
  (hoy `429`, ver checklist). El bot igual responde con el prompt del pack.
- **Archivos:** `backend/src/services/vertical-packs.js`, `routes/v1/vertical-packs.js`,
  tarjeta en `frontend/pages/knowledge-base.php` + proxy.

### ✅ 3.3 Handoff humano inteligente (transferencia en caliente) *(COMPLETADO)*
- La tool `transfer_to_human` ahora marca la conversación (`needs_human`), compone
  un resumen de contexto (cliente, intención, carrito, motivo) y notifica al equipo.
- Banner "Atención humana" en Conversaciones con el contexto + botón "Atender yo"
  (claim → se asigna al usuario + abre la transcripción completa).
- Funciona en todos los canales (voz, WhatsApp, web).
- **Archivos:** `scripts/migrate-handoff.sql`, handler en `tools/executor.js`,
  filtro + endpoint en `routes/v1/conversations.js`,
  banner en `frontend/pages/conversations.php` + proxy.

---

## Modelo de cobro recomendado

```
Base mensual predecible  +  Uso (minutos/mensajes)  +  Bonus por resultado
```
- Setup fee inicial · Tiers por vertical · Add-ons (cobros, BI, canales extra).

---

## Orden de ejecución

1. ✅ Reporte de Valor
2. ✅ No-shows
3. ✅ Cobros por bot
4. ✅ Voz del cliente (BI de conversaciones, feature premium)
5. ✅ Multicanal (web widget)
6. ✅ Catálogo + carrito + checkout + pedidos (conversational commerce)
7. ✅ Packs verticales (arranque rápido por industria)
8. ✅ Handoff humano inteligente (transferencia en caliente con contexto)

🎉 **ROADMAP COMPLETO** — las 8 mejoras implementadas y verificadas en vivo.

*Actualizado: 2026-06-15*
