# Setup de Cuentas — Fase 1

## 1. Twilio (telefonía)

### Crear cuenta
1. Ir a https://www.twilio.com/try-twilio
2. Registrarse (trial gratuito con $15 USD de crédito)
3. Verificar tu número de teléfono

### Obtener credenciales
1. Dashboard → Account Info (lado derecho)
2. Copiar **Account SID** y **Auth Token**
3. Pegar en tu `.env`:
   ```
   TWILIO_ACCOUNT_SID=ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
   TWILIO_AUTH_TOKEN=xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
   ```

### Comprar número mexicano
1. Phone Numbers → Manage → Buy a Number
2. Country: Mexico
3. Capabilities: Voice ✅
4. Buscar → Comprar (~$1.15 USD/mes)
5. Copiar el número (+521XXXXXXXXXX) a `.env`:
   ```
   TWILIO_DEFAULT_NUMBER=+521XXXXXXXXXX
   ```

### Configurar webhook
1. Phone Numbers → Manage → Active Numbers → clic en tu número
2. Voice Configuration:
   - **A call comes in:** Webhook
   - URL: `https://TU_DOMINIO/webhooks/twilio/voice`
   - HTTP: POST
3. **Call status changes:** `https://TU_DOMINIO/webhooks/twilio/status`
4. Save

### Para desarrollo local (ngrok)
```bash
# Instalar ngrok: https://ngrok.com/download
ngrok http 3000
# Copia la URL https://xxxx.ngrok.io
# Úsala en el webhook de Twilio
```

---

## 2. Deepgram (Speech-to-Text)

### Crear cuenta
1. Ir a https://console.deepgram.com/signup
2. Registro gratuito — $200 USD de crédito inicial

### Crear API Key
1. Dashboard → API Keys → Create a New API Key
2. Nombre: "agentcore-production"
3. Permisos: Usage (Member)
4. Copiar la key a `.env`:
   ```
   DEEPGRAM_API_KEY=xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
   DEEPGRAM_MODEL=nova-3
   DEEPGRAM_LANGUAGE=es-419
   ```

### Verificar funcionamiento
```bash
curl -X POST https://api.deepgram.com/v1/listen \
  -H "Authorization: Token TU_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"url":"https://static.deepgram.com/examples/Bueller-Life-moves-pretty-fast.wav"}' \
  | jq .results.channels[0].alternatives[0].transcript
```

---

## 3. Cartesia (Text-to-Speech)

### Crear cuenta
1. Ir a https://play.cartesia.ai
2. Registro → Dashboard
3. API Keys → Create Key
4. Copiar a `.env`:
   ```
   CARTESIA_API_KEY=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
   ```

### Probar voces en español
1. Dashboard → Voices → filtrar por "Spanish"
2. Escuchar muestras y elegir la que más te guste
3. Copiar el Voice ID:
   ```
   CARTESIA_DEFAULT_VOICE_ID=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
   ```

---

## 4. Agregar agente a un número en la DB

Después de comprar el número Twilio, asignarlo al agente demo:

```sql
UPDATE agents 
SET phone_number = '+521XXXXXXXXXX'
WHERE name = 'Recepcionista IA' 
  AND tenant_id = (SELECT id FROM tenants WHERE slug = 'demo-dental');
```

---

## 5. Probar el flujo completo

```bash
# 1. Levantar backend
cd docker && docker-compose up -d

# 2. Exponer con ngrok
ngrok http 3000

# 3. Actualizar APP_URL en .env con la URL de ngrok
APP_URL=https://xxxx.ngrok.io

# 4. Llamar al número Twilio desde tu teléfono
# Deberías escuchar el saludo del agente IA

# 5. Revisar logs
docker logs agentcore_backend -f
```

---

## Costos estimados de prueba (primer mes)

| Servicio | Tier | Costo |
|----------|------|-------|
| Twilio | Trial $15 crédito | $0 |
| Twilio número MX | $1.15/mes | $1.15 |
| Twilio llamadas | $0.0085/min × 100 min | $0.85 |
| Deepgram | $200 crédito inicial | $0 |
| Cartesia | Free tier (~50 min) | $0 |
| OpenAI | ~$0.15/M tokens × 50K | ~$0.01 |
| **Total mes 1** | | **~$2 USD** |
