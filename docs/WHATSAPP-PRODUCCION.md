# 📱 Guía: Poner WhatsApp en producción (Meta Cloud API)

> **Para qué sirve:** conectar un número real de WhatsApp a AgentCore para
> atender clientes de verdad, sin el límite de 5 destinatarios del número de
> prueba. Específica para cómo está construido AgentCore.
>
> **Tiempo:** ~1–2 h de configuración + **1–3 días** de espera por la
> verificación de negocio de Meta (no depende de ti).

---

## 0. Conceptos antes de empezar

- **NO** es la app de WhatsApp Business del celular. Es **WhatsApp Business
  Platform (Cloud API)** — el producto para que software envíe/reciba mensajes.
- ⚠️ **El número que conectes NO puede estar activo en la app de WhatsApp**
  (ni la normal ni la Business). Usa un **número nuevo** o libera uno borrándolo
  de la app primero.
- **Todo es gratis de crear.** Solo pagas a Meta **por conversación** (hay un
  tramo gratuito mensual de conversaciones de servicio).

### Las dos formas de configurarlo en AgentCore

AgentCore soporta dos modelos (lo decide el código en `meta-client.js`):

| Modelo | Cuándo usarlo | Dónde van las credenciales |
|---|---|---|
| **Compartido (1 número)** | Piloto, o todos tus clientes comparten un número | Variables `.env` globales (`META_*`) |
| **Multi-tenant (1 número por cliente)** | SaaS real: cada cliente conecta su propio WhatsApp | `settings.whatsapp` de cada tenant |

> El código usa primero la config del tenant (`settings.whatsapp.accessToken` /
> `phoneNumberId`) y si está vacía, cae a las variables `.env`. Así que puedes
> empezar con el modelo compartido y migrar a multi-tenant sin tocar código.

---

## 1. Crear las cuentas base (una sola vez)

1. **Meta Business** → https://business.facebook.com → crea tu cuenta de negocio
   (si ya tienes una de Facebook/Instagram, úsala).
2. **App de desarrollador** → https://developers.facebook.com/apps → **Crear app**
   → tipo **"Empresa / Business"**.
3. En la app, **Agregar producto → WhatsApp → Configurar**.
   - Esto crea automáticamente una **WhatsApp Business Account (WABA)** y te da
     un **número de prueba gratuito**.

En este punto ya puedes **probar** (con hasta 5 números destinatarios) sin
verificación de negocio.

---

## 2. Obtener las credenciales para AgentCore

En el panel de la app → **WhatsApp → Configuración de la API**, vas a ver:

| Dato del panel | Variable en AgentCore |
|---|---|
| **Identificador del número de teléfono** | `META_PHONE_NUMBER_ID` |
| **Token de acceso temporal** (24 h, para probar) | `META_WHATSAPP_TOKEN` |
| **Clave secreta de la app** (Configuración → Básica) | `META_APP_SECRET` |
| Lo eliges tú (cadena aleatoria) | `META_VERIFY_TOKEN` |

> El token temporal sirve para probar. Para producción necesitas un **token
> permanente** (ver §5).

Genera el verify token:
```powershell
node -e "console.log('vt_' + require('crypto').randomBytes(16).toString('hex'))"
```

Ponlos en `.env` (modelo compartido):
```
META_PHONE_NUMBER_ID=...
META_WHATSAPP_TOKEN=...
META_VERIFY_TOKEN=vt_...
META_APP_SECRET=...
```
Y `docker restart agentcore_backend`.

---

## 3. Conectar el webhook (lo más técnico)

Meta necesita una **URL pública HTTPS** para enviarte los mensajes. AgentCore
expone el webhook en:

```
{TU_URL_PUBLICA}/webhooks/meta
```

### El problema en desarrollo: localhost no sirve
Meta no puede alcanzar `localhost`. Dos opciones:

- **Desarrollo / pruebas:** usa un túnel como **ngrok**:
  ```powershell
  ngrok http 3001
  ```
  Te da una URL tipo `https://abc123.ngrok.app` → tu webhook sería
  `https://abc123.ngrok.app/webhooks/meta`.
- **Producción:** la URL de tu servidor desplegado, p. ej.
  `https://api.tudominio.com/webhooks/meta`.

### Registrar el webhook en Meta
1. Panel de la app → **WhatsApp → Configuración → Webhook → Editar**.
2. **URL de devolución de llamada:** `{TU_URL_PUBLICA}/webhooks/meta`
3. **Verificar token:** el mismo valor de `META_VERIFY_TOKEN`.
4. Clic en **Verificar y guardar**.
   - Meta hace un `GET` a tu webhook; AgentCore responde el `challenge`
     automáticamente si el token coincide (ya está implementado).
   - ✅ Si guarda sin error, el handshake funcionó.
5. **Suscribir campos:** en la misma pantalla, activa el campo **`messages`**
   (es el que entrega los mensajes entrantes).

---

## 4. Probar con el número de prueba

1. En el panel **WhatsApp → Configuración de la API**, agrega tu celular
   personal como número destinatario de prueba (hasta 5).
2. Manda un WhatsApp **desde tu celular** al número de prueba de Meta.
3. En AgentCore deberías ver la conversación entrar (revisa
   `docker logs agentcore_backend` → `[Meta] Mensaje entrante`).

> ⚠️ **Recuerda el gate `is_ready`:** AgentCore solo responde si el bot del
> tenant está **aprobado en el Simulador**. Si no contesta, verifica que el
> tenant tenga `is_ready = true`.

---

## 5. Pasar a PRODUCCIÓN (atender clientes reales)

El número de prueba sirve para 5 destinatarios. Para abrir a todo el mundo:

### 5.1 Verificación de negocio (la espera)
- Panel de Meta Business → **Centro de seguridad / Verificación del negocio**.
- Sube documentos del negocio (acta, comprobante de domicilio, etc.).
- Meta tarda **1–3 días** (a veces más). Sin esto, los límites de mensajería
  son bajos.

### 5.2 Conectar tu número propio
- **WhatsApp → Configuración de la API → Agregar número de teléfono.**
- Debe ser un número que **NO esté en la app de WhatsApp**.
- Meta lo verifica por SMS/llamada.

### 5.3 Token permanente (no el de 24 h)
1. https://business.facebook.com → **Configuración del negocio → Usuarios →
   Usuarios del sistema** → crea uno (rol Admin).
2. **Generar nuevo token** → elige tu app → permisos
   `whatsapp_business_messaging` + `whatsapp_business_management`.
3. Cópialo → ponlo en `META_WHATSAPP_TOKEN` (o en `settings.whatsapp.accessToken`
   del tenant) → `docker restart agentcore_backend`.

### 5.4 Activar la verificación de firma (seguridad)
Asegúrate de tener `META_APP_SECRET` en `.env` (Configuración → Básica de la app).
Con eso, AgentCore valida la firma de cada webhook y rechaza los falsos.

---

## 6. Modelo multi-tenant (cada cliente su propio WhatsApp)

Cuando un cliente quiera usar **su** número (no el tuyo), guarda **su** config en
`settings.whatsapp` de ese tenant:

```json
{
  "whatsapp": {
    "phoneNumberId": "ID del número del cliente",
    "accessToken":   "token permanente del cliente",
    "businessId":    "WABA id del cliente (opcional)"
  }
}
```

- Cada cliente registra el **mismo webhook** (`{TU_URL}/webhooks/meta`) en su
  propia app de Meta.
- AgentCore identifica al tenant correcto por el `phoneNumberId` que viene en
  cada mensaje (busca `settings.whatsapp.phoneNumberId` que coincida).
- Esto es lo que hace verdaderamente "SaaS" la plataforma: cada cliente con su
  número, su marca y su conversación aislada.

> 💡 A futuro conviene un flujo de **Embedded Signup** de Meta para que el
> cliente conecte su WhatsApp con unos clics desde tu dashboard, sin pasarte
> tokens a mano. Es una mejora del roadmap, no necesaria para arrancar.

---

## 7. Costos y límites (para que lo platiques con el cliente)

- **Plataforma:** gratis.
- **Conversaciones:** Meta cobra por conversación iniciada (precios varían por
  país; México es de los más baratos). Hay **conversaciones de servicio
  gratuitas** al mes.
- **Tiers de mensajería:** empiezas limitado (ej. 1,000 clientes/día) y Meta
  sube el límite automáticamente según tu buen uso y la verificación.

---

## ✅ Checklist para estar en producción

- [ ] Cuenta Meta Business creada
- [ ] App de desarrollador con producto WhatsApp
- [ ] **Verificación de negocio** aprobada por Meta
- [ ] Número propio conectado (no en la app de WhatsApp)
- [ ] **Token permanente** (System User) en `META_WHATSAPP_TOKEN`
- [ ] `META_APP_SECRET` configurado (firma de webhooks)
- [ ] Webhook `{TU_URL}/webhooks/meta` verificado + campo `messages` suscrito
- [ ] URL pública HTTPS real (no ngrok)
- [ ] Tenant con `is_ready = true` (bot aprobado en el Simulador)
- [ ] Prueba: WhatsApp real → el bot responde

---

## 8. ⚠️ Mensajes que INICIA el negocio tras una llamada (plantillas / error 63016)

Hay **dos** flujos de WhatsApp distintos en AgentCore:

| Flujo | Vía | Ventana |
|---|---|---|
| El cliente te **escribe** y el bot responde | Meta Cloud API (`meta-client.js`, §1–7) | Dentro de 24h → texto libre OK |
| El bot **inicia** contacto tras una **llamada de voz** (ficha de propiedad, confirmación de cita) | **Twilio** (`twilio-client.js` + `executor.js`) | **Cold contact** → requiere **plantilla aprobada** |

### El problema (diagnóstico real, jun 2026)
En una llamada real, el bot dijo "te envié la ficha/confirmación" pero **no llegó nada**. Twilio marcó los mensajes `undelivered / error **63016**` = *"mensaje freeform fuera de la ventana de 24h; usa una plantilla"*.

**Causa:** WhatsApp solo permite texto libre si el cliente te escribió en las últimas 24h. Quien **llama por teléfono nunca abrió un chat** → no hay ventana → todo mensaje libre rebota. Esto **no se arregla en código**: para iniciar el contacto se necesita una **plantilla (HSM) aprobada por Meta**.

### Lo que ya quedó hecho en código (stopgap honesto)
- `sendWhatsAppTracked()` (en `twilio-client.js`) rastrea la entrega (poll corto + `statusCallback`) y registra los rebotes en log.
- El bot **ya no afirma** que el mensaje llegó: dice *"te lo estoy enviando; si no te llega, avísame"* y, si el envío se rechaza de inmediato, ofrece otra vía. (El rebote 63016 es asíncrono ~varios seg, no atrapable dentro de la llamada.)

### Lo que falta para que SÍ entregue (tu tarea en consola)
1. **Twilio WhatsApp Sender de producción** (no el sandbox). Twilio Console → Messaging → Senders → WhatsApp senders → registra tu número aprobado. Pon ese número en `TWILIO_WHATSAPP_FROM` del `.env`.
2. **Crear plantillas en Twilio Content Template Builder** (Console → Messaging → Content Template Builder), categoría **Utility**, y enviarlas a aprobación de WhatsApp (1–2 días). Necesitas dos:
   - **Confirmación de cita** — ej: `Hola {{1}}, confirmamos tu cita para {{2}}. {{3}}`
   - **Ficha de propiedad** — ej: `{{1}} — {{2}}. Más info: {{3}}`
3. Al aprobarse, Twilio te da un **Content SID** (`HX…`) por plantilla. Ponlos en `.env`:
   ```
   TWILIO_WHATSAPP_FROM=+1XXXXXXXXXX        # sender de producción
   TWILIO_WA_TEMPLATE_APPOINTMENT=HX...     # plantilla de confirmación de cita
   TWILIO_WA_TEMPLATE_PROPERTY=HX...        # plantilla de ficha de propiedad
   ```
   `docker restart agentcore_backend`.

### Cómo lo usa el código (ya está cableado, gateado por env)
`executor.js` (scheduleAppointment / sendPropertyInfo): **si** las variables `TWILIO_WA_TEMPLATE_*` están definidas, envía como **plantilla** (`contentSid` + `contentVariables` `{1,2,3}`) → entrega a cold contacts sin 63016. **Si no** están, sigue en freeform (solo entrega dentro de ventana). Las posiciones de variables deben coincidir con `{{1}}/{{2}}/{{3}}` de la plantilla que apruebes.

### Probar
Tras configurar, haz una llamada de prueba que agende una cita: el WhatsApp de confirmación debe llegar al número dictado (sin necesidad de que ese número te haya escrito antes). Verifica en `docker logs agentcore_backend` que no haya `NO entregado (… err 63016)`.

> Alternativa rápida si urge antes de aprobar plantillas: enviar por **SMS** (no tiene ventana de 24h), aunque la entrega de SMS internacional US→MX es variable. No está implementado; decisión pendiente.

---

*Documento generado el 2026-06-15 (sección 8 agregada 2026-06-22). Los pasos del
panel de Meta/Twilio pueden cambiar; verifica en la doc oficial si algo no coincide.*
