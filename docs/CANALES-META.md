# 📱 Plan: canales Instagram + Facebook Messenger

> **Estado:** en cola de desarrollo (backlog). Estimación de código: 1.5–2.5 semanas.
> El cuello de botella real NO es programar, sino la **aprobación de Meta** (App
> Review + verificación de negocio), que hay que arrancar en paralelo desde el día 1.

## Por qué es viable con esfuerzo bajo-medio

El "cerebro" del agente **ya es agnóstico al canal**: `tools/executor.js` (search_products,
save_lead, transfer_to_human, agendar, etc.) + RAG + `_buildSystemPrompt` se reusan
idénticos en voz, WhatsApp y webchat. Un agente de ventas en IG/Messenger usa las
**mismas herramientas** — no se reprograma la lógica.

Además, Instagram DM y Messenger corren sobre la **misma Graph API de Meta** que ya
usa WhatsApp (`webhooks/meta.js`, `services/whatsapp/meta-client.js`). Meta unificó
Messenger e Instagram bajo la misma "Messenger Platform" → **hacer uno da ~90% del otro**.
Reuso estimado: **70–80%** del código de mensajería existente.

## Trabajo de CÓDIGO

| Componente | Cambio vs. WhatsApp | Esfuerzo |
|---|---|---|
| Parser de webhook | Messenger/IG usan `entry[].messaging[]` con `sender.id` (PSID/IGSID), no `changes[].value.messages[]`. Rama nueva en `message-parser.js` | 🟢 Bajo |
| Envío de respuestas | `POST /{page-id}/messages` con `{recipient, message}` en vez de `/{phoneNumberId}/messages`. Método nuevo en `meta-client.js` | 🟢 Bajo |
| Ruteo del webhook | `meta.js` hoy filtra `object==='whatsapp_business_account'`; agregar `'page'` (Messenger) e `'instagram'`; resolver tenant por page_id/ig_id | 🟢 Bajo |
| Identidad del contacto | IG/Messenger dan un ID opaco por página (PSID), no teléfono. El "customer spine" (`leads`) hoy indexa por teléfono → añadir identidad por canal (columna psid/igsid o tabla de identidades) | 🟡 Medio |
| Config + gating | `agents.channel` nuevos valores; features `instagram`/`messenger`; UI para conectar página | 🟡 Medio |
| Ventana 24h + message tags | Misma regla que ya se maneja en WhatsApp (error 63016) | 🟢 Bajo (reuso) |

## Trabajo de META (no es código, es lo que tarda)

1. **App Review**: permisos `pages_messaging` + `instagram_manage_messages` requieren
   revisión de Meta (video demo, casos de uso, política de privacidad). Días–semanas.
2. **Business Verification** de la empresa.
3. **OAuth multi-tenant**: flujo "Conectar con Facebook" para que cada cliente vincule
   SU página / cuenta IG y entregue su token (análogo a las credenciales Twilio por
   tenant que ya existen). Guardar y refrescar tokens por tenant.
4. **Requisito IG**: cuenta Instagram **Professional** vinculada a una página de Facebook.

## Fases sugeridas

- **Fase A — Prototipo (código, ~1 sem):** Messenger + IG como canal nuevo reusando el
  cerebro; probar en modo desarrollador con una página propia (sin App Review todavía).
  Valida todo el flujo técnico end-to-end.
- **Fase B — Compliance (en paralelo, arrancar YA):** App Review + business verification.
  Es lo que más tarda y es gated por Meta.
- **Fase C — OAuth multi-tenant:** flujo de conexión self-service por cliente + gestión
  de tokens.

## Archivos que se tocarían

- `backend/src/services/whatsapp/message-parser.js` — rama messaging (PSID/IGSID).
- `backend/src/services/whatsapp/meta-client.js` — envío a `/{page-id}/messages`.
- `backend/src/routes/webhooks/meta.js` — ruteo por `object` (page/instagram).
- `backend/src/agents/whatsapp-agent.js` — renombrar/generalizar a "meta-messaging-agent"
  (mismo brain, distinto emisor). Reuso casi total.
- `backend/src/services/contacts.js` — identidad por canal (además de teléfono).
- Migración: features + identidad de contacto por plataforma.
- Frontend: conectar página (OAuth), gating de canal, editor de agente por canal.

## Nota de arquitectura
Considerar renombrar la carpeta `services/whatsapp/` → `services/meta/` cuando se
generalice, ya que dejará de ser solo WhatsApp. (Deuda menor, hacer al tocar.)
