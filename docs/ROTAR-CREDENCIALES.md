# 🔐 Guía: Rotar API Keys y JWT_SECRET

> **Para qué sirve esto:** Las credenciales actuales en `.env` quedaron expuestas
> (en el filesystem y posiblemente en git). Esta guía explica, paso a paso, cómo
> generar credenciales nuevas y reemplazarlas sin romper el sistema.
>
> **Cuánto tarda:** ~30–40 min. **Frecuencia recomendada:** cada 90 días, o
> inmediatamente si sospechas que una clave se filtró.

---

## ⚠️ Antes de empezar — lee esto

- **Haz una copia de seguridad del `.env` actual** antes de tocarlo:
  ```powershell
  Copy-Item C:\Users\jorge\Documents\agentcore\.env C:\Users\jorge\Documents\agentcore\.env.backup-2026-06-15
  ```
- **Rotar `JWT_SECRET` cierra la sesión de TODOS los usuarios.** Tendrán que
  volver a iniciar sesión. Hazlo en un horario de bajo tráfico.
- **Cada proveedor se rota por separado.** Puedes hacerlos de uno en uno y
  reiniciar el backend entre cada uno para verificar que nada se rompió.
- **Nunca pegues estas claves en chats, capturas, ni las subas a git.**
  Verifica que `.env` esté en `.gitignore` (ver paso 0).

---

## Paso 0 — Verificar que `.env` NO está en git

```powershell
cd C:\Users\jorge\Documents\agentcore
git check-ignore .env
```

- Si imprime `.env` → está ignorado correctamente. ✅
- Si **no imprime nada** → NO está ignorado. Agrégalo de inmediato:
  ```powershell
  Add-Content .gitignore "`n.env"
  git rm --cached .env   # deja de rastrearlo sin borrar el archivo local
  ```
  Si `.env` ya fue committeado antes, las claves viejas siguen en el historial
  de git → **debes rotarlas todas** (que es justo lo que hace esta guía).

---

## Paso 1 — JWT_SECRET (el más importante y el más fácil)

El `JWT_SECRET` firma los tokens de sesión. El actual
(`agentcore_cookie_xpertek3d_2025`) es corto y predecible: un atacante podría
falsificar tokens y entrar como cualquier usuario.

### 1.1 Generar un secreto fuerte (64 bytes aleatorios)

```powershell
node -e "console.log(require('crypto').randomBytes(48).toString('base64'))"
```

Esto imprime algo como:
```
kJ8vN2mP9qLx4Rt7yW3sB6dF1gH5jK0nM2pQ8rT4uV6wX9zA1cD3eF7gH0iJ2kL
```
Cópialo.

### 1.2 Reemplazar en `.env`

Abre `C:\Users\jorge\Documents\agentcore\.env` y cambia la línea:
```diff
- JWT_SECRET=agentcore_cookie_xpertek3d_2025
+ JWT_SECRET=kJ8vN2mP9qLx4Rt7yW3sB6dF1gH5jK0nM2pQ8rT4uV6wX9zA1cD3eF7gH0iJ2kL
```

### 1.3 Reiniciar el backend

```powershell
docker restart agentcore_backend
```

### 1.4 Verificar

- Abre http://localhost:8080 → te pedirá iniciar sesión de nuevo (esto es lo
  esperado, porque los tokens viejos ya no son válidos).
- Inicia sesión → si entras, el secreto nuevo funciona. ✅

---

## Paso 2 — Anthropic (Claude)

El backend usa Claude para los agentes de voz/WhatsApp y el simulador.

### 2.1 Revocar la clave vieja y crear una nueva

1. Entra a https://console.anthropic.com/settings/keys
2. Localiza la clave actual (empieza con `sk-ant-api03-…`) → **Revoke / Delete**.
3. Clic en **Create Key** → ponle nombre (ej. `agentcore-prod-2026-06`) → cópiala.
   > ⚠️ Solo se muestra UNA vez. Si la pierdes, genera otra.

### 2.2 Reemplazar en `.env`

```diff
- ANTHROPIC_API_KEY=sk-ant-api03-KV03ea…
+ ANTHROPIC_API_KEY=sk-ant-api03-TU_NUEVA_CLAVE
```

### 2.3 Reiniciar y verificar

```powershell
docker restart agentcore_backend
```
Verifica con una prueba del simulador (manda un mensaje de chat). Si responde,
la clave funciona. ✅

---

## Paso 3 — OpenAI

> Nota: el sistema hoy enruta todo a Claude (`llm-router.js`), pero conserva
> OpenAI como respaldo. Rótala igual por higiene.

1. Entra a https://platform.openai.com/api-keys
2. Localiza la clave actual (`sk-proj-…`) → **Revoke**.
3. **Create new secret key** → cópiala.
4. En `.env`:
   ```diff
   - OPENAI_API_KEY=sk-proj-NciH9…
   + OPENAI_API_KEY=sk-proj-TU_NUEVA_CLAVE
   ```
5. `docker restart agentcore_backend`

---

## Paso 4 — Twilio (llamadas de voz)

Twilio tiene DOS valores: el SID de la cuenta y el Auth Token.

### 4.1 Rotar el Auth Token

1. Entra a https://console.twilio.com
2. En el dashboard principal verás **Account SID** y **Auth Token**.
3. El `ACCOUNT_SID` normalmente NO cambia (identifica tu cuenta). El que se
   rota es el **Auth Token**: clic en **View** → **Create secondary token** o
   usa la opción de rotación de Twilio (genera uno nuevo y luego revoca el viejo).
4. En `.env`:
   ```diff
     TWILIO_ACCOUNT_SID=ACbefc0c0f8f…      # normalmente igual
   - TWILIO_AUTH_TOKEN=62988e9fd0…
   + TWILIO_AUTH_TOKEN=TU_NUEVO_TOKEN
   ```
5. `docker restart agentcore_backend`

> ⚠️ Twilio valida la firma de los webhooks con el Auth Token. Tras rotarlo,
> haz una llamada de prueba al número del agente para confirmar que entra.

---

## Paso 5 — Deepgram (transcripción de voz)

1. Entra a https://console.deepgram.com → **API Keys**.
2. Revoca la clave actual → **Create a New API Key** (permiso: *Member* o el que
   ya usabas) → cópiala.
3. En `.env`:
   ```diff
   - DEEPGRAM_API_KEY=904d0edc899…
   + DEEPGRAM_API_KEY=TU_NUEVA_CLAVE
   ```
4. `docker restart agentcore_backend`

---

## Paso 6 — Cartesia (síntesis de voz / TTS)

1. Entra a https://play.cartesia.ai → **API Keys** (en configuración de cuenta).
2. Revoca la clave actual (`sk_car_…`) → genera una nueva → cópiala.
3. En `.env`:
   ```diff
   - CARTESIA_API_KEY=sk_car_FY8na…
   + CARTESIA_API_KEY=TU_NUEVA_CLAVE
   ```
   > El `CARTESIA_DEFAULT_VOICE_ID` NO es secreto (es un identificador de voz
   > público), no necesita rotarse.
4. `docker restart agentcore_backend`

---

## Paso 7 — Meta / WhatsApp Cloud API

Meta usa **4 valores** (3 configurados + 1 recomendado). Panel:
https://developers.facebook.com/apps → tu App → **WhatsApp**.

### 7.1 `META_WHATSAPP_TOKEN` (access token) — el importante

Es el token con el que el backend envía mensajes de WhatsApp.
- Los tokens **temporales** del panel duran 24 h (solo para pruebas).
- Para producción usa un **token de System User permanente**:
  1. https://business.facebook.com → **Configuración del negocio** → **Usuarios → Usuarios del sistema**.
  2. Crea/elige un System User → **Generar nuevo token** → App correcta →
     permisos `whatsapp_business_messaging` y `whatsapp_business_management`.
  3. Cópialo (solo se muestra una vez).
- Para **revocar** el viejo: en el mismo System User, elimina el token anterior.
- En `.env`:
  ```diff
  - META_WHATSAPP_TOKEN=EAAG…(viejo)
  + META_WHATSAPP_TOKEN=TU_NUEVO_TOKEN
  ```

### 7.2 `META_PHONE_NUMBER_ID` — normalmente NO cambia

Identifica tu número de WhatsApp; **no es secreto**. Solo cámbialo si migras de
número. Lo encuentras en **WhatsApp → Configuración de la API** del panel.

### 7.3 `META_VERIFY_TOKEN` — lo eliges tú

Es una cadena que **tú inventas** y que Meta usa para verificar tu webhook.
Para rotarlo hay que cambiarlo en **DOS lados** o el webhook deja de verificar:
1. Genera uno nuevo:
   ```powershell
   node -e "console.log('vt_' + require('crypto').randomBytes(16).toString('hex'))"
   ```
2. Ponlo en `.env`: `META_VERIFY_TOKEN=vt_...`
3. **También** actualízalo en el panel de Meta → **WhatsApp → Configuración →
   Webhook → Verificar token**, y vuelve a verificar.

### 7.4 `META_APP_SECRET` — ⚠️ FALTA y es de seguridad

> **Hoy NO está configurado** → el webhook de WhatsApp acepta mensajes **sin
> verificar la firma HMAC**. Eso significa que cualquiera que conozca tu URL de
> webhook podría enviarte mensajes falsos. **Recomendado agregarlo.**

1. Panel de Meta → tu App → **Configuración → Básica** → campo **Clave secreta
   de la app** (App Secret) → *Mostrar* → cópiala.
2. Agrégala a `.env` (línea nueva):
   ```diff
   + META_APP_SECRET=tu_app_secret
   ```
3. `docker restart agentcore_backend`. El código ya la usa automáticamente:
   cuando está presente, valida la firma `x-hub-signature-256` de cada webhook
   y rechaza los que no coincidan.

Tras cambiar cualquiera de los 4: `docker restart agentcore_backend` y haz una
prueba enviando un WhatsApp al número del bot.

---

## Paso 8 — Stripe (pagos)

Stripe usa **2 valores**. Panel: https://dashboard.stripe.com (arriba a la
izquierda puedes alternar entre modo **Prueba** y **Producción** — rota la del
modo que uses).

### 8.1 `STRIPE_SECRET_KEY`

1. **Desarrolladores → Claves de API** → en **Clave secreta**, clic en
   **Rotar clave** (Stripe te deja conservar la vieja activa unas horas para
   migrar sin downtime) → copia la nueva (`sk_test_…` o `sk_live_…`).
2. En `.env`:
   ```diff
   - STRIPE_SECRET_KEY=sk_test_…(viejo)
   + STRIPE_SECRET_KEY=TU_NUEVA_CLAVE
   ```
   > Esta misma llave activa los **cobros por el bot** (anticipos de cita). Si
   > hoy está en placeholder, al poner una válida los cobros empiezan a funcionar.

### 8.2 `STRIPE_WEBHOOK_SECRET`

Es el secreto que valida la firma de los webhooks de Stripe.
1. **Desarrolladores → Webhooks** → tu endpoint (`/webhooks/stripe`) →
   **Firma del webhook** → *Revelar* / *Roll secret* → copia (`whsec_…`).
2. En `.env`:
   ```diff
   - STRIPE_WEBHOOK_SECRET=whsec_…(viejo)
   + STRIPE_WEBHOOK_SECRET=TU_NUEVO_SECRET
   ```
3. `docker restart agentcore_backend`.

> ⚠️ Si cambias entre modo Prueba y Producción, **ambos** valores
> (`STRIPE_SECRET_KEY` y `STRIPE_WEBHOOK_SECRET`) deben ser del mismo modo, o los
> webhooks fallarán la verificación de firma.

---

## Paso 9 — Verificación final completa

Después de rotar todo, reinicia limpio y prueba el flujo end-to-end:

```powershell
docker restart agentcore_backend
# Espera ~8 segundos a que arranque
docker logs agentcore_backend --tail 15
```

Deberías ver:
```
✅ PostgreSQL conectado
✅ Rutas registradas
Server listening at http://0.0.0.0:3000
✅ Redis conectado
```

Luego prueba manualmente:
- [ ] **Login** en http://localhost:8080 (verifica JWT nuevo)
- [ ] **Simulador** → manda un mensaje de chat (verifica Anthropic)
- [ ] **Llamada de prueba** al número del agente (verifica Twilio + Deepgram + Cartesia)
- [ ] **WhatsApp de prueba** al número configurado (verifica Meta)
- [ ] **Cobrar anticipo** en una cita → genera link de pago (verifica Stripe)

Si todos pasan, la rotación está completa. ✅

---

## Paso 10 — Limpiar rastros

1. **Borra el backup del `.env`** una vez confirmes que todo funciona:
   ```powershell
   Remove-Item C:\Users\jorge\Documents\agentcore\.env.backup-2026-06-15
   ```
2. Si las claves viejas estuvieron en el historial de git, considera reescribir
   el historial con `git filter-repo` o, más simple, asume que están quemadas
   (ya las revocaste, así que no sirven a nadie).

---

## 📌 Recomendaciones a futuro (para no repetir esto)

1. **Usa un gestor de secretos** en producción en vez de `.env` plano:
   - Para empezar: variables de entorno del hosting (Railway, Render, Fly.io).
   - Más adelante: AWS Secrets Manager, Doppler, o HashiCorp Vault.
2. **Separa claves de desarrollo y producción.** Nunca uses las mismas.
3. **Activa alertas de gasto** en cada proveedor (OpenAI, Anthropic, Twilio) para
   detectar uso anómalo si una clave se filtra.
4. **Rota cada 90 días** aunque no sospeches nada. Pon un recordatorio.
5. **Principio de menor privilegio:** crea claves con el mínimo de permisos
   necesarios (ej. en Deepgram, solo transcripción; no admin).

---

*Documento generado el 2026-06-15. Actualízalo si cambian los proveedores.*
