# Setup Meta WhatsApp Cloud API — Fase 3

## Requisitos
- Cuenta de Facebook Business (no personal)
- Número de teléfono que NO esté en WhatsApp actualmente
- Dominio con HTTPS (ngrok para desarrollo local)

---

## Paso 1: Crear App en Meta for Developers

1. Ir a https://developers.facebook.com/apps/
2. **Create App** → tipo: **Business**
3. Nombre: "AgentCore Dev" (o el nombre de tu negocio)
4. Business Portfolio: seleccionar o crear uno

---

## Paso 2: Agregar WhatsApp al App

1. En el dashboard del app → **Add Product** → **WhatsApp** → Set Up
2. Ir a **WhatsApp > API Setup**

### Obtener credenciales de prueba (gratis, sin aprobación)
Meta te da un número de prueba y 5 números de destino gratuitos:

```
Phone Number ID: 1234567890         → META_PHONE_NUMBER_ID
Temporary Access Token: EAAxxxxx    → META_WHATSAPP_TOKEN (expira en 24h)
WhatsApp Business Account ID: 987   → META_BUSINESS_ID
```

> Para producción necesitas un token de larga duración (System User Token)

---

## Paso 3: Agregar número de destino de prueba

En **API Setup** → **To** → **Manage phone number list**
- Agregar tu número de WhatsApp personal (+521XXXXXXXXXX)
- Verificar con el código que te llegará por WhatsApp

---

## Paso 4: Configurar Webhook

1. En tu app → **WhatsApp > Configuration** → **Webhook**
2. **Edit** → pegar:
   - **Callback URL:** `https://TU_NGROK.ngrok.io/webhooks/meta`
   - **Verify Token:** el valor de `META_VERIFY_TOKEN` en tu `.env`
3. Click **Verify and Save**
4. **Subscribe** a los campos:
   - ✅ `messages`

### El backend debe estar corriendo cuando hagas esto:
```bash
# Terminal 1
cd agentcore/docker && docker-compose up -d

# Terminal 2
ngrok http 3000
# Copia la URL https://xxxx.ngrok.io → META_VERIFY_TOKEN en .env

# Verificar webhook activo
curl https://xxxx.ngrok.io/webhooks/meta/status
```

---

## Paso 5: Variables de entorno

```env
META_WHATSAPP_TOKEN=EAAxxxxxxxxxxxxxxx
META_PHONE_NUMBER_ID=1234567890123456
META_BUSINESS_ID=9876543210
META_VERIFY_TOKEN=mi_token_secreto_cualquier_string
META_APP_SECRET=xxxxxx    # En App Settings > Basic > App Secret
```

---

## Paso 6: Aplicar migración

```bash
docker exec agentcore_postgres psql -U agentcore -d agentcore \
  -f /docker-entrypoint-initdb.d/migrate-fase3.sql
```

O actualizar directamente:
```sql
UPDATE tenants
SET settings = settings || jsonb_build_object(
  'whatsapp', jsonb_build_object(
    'phoneNumberId', 'TU_PHONE_NUMBER_ID',
    'accessToken',   'TU_TOKEN',
    'businessId',    'TU_BUSINESS_ID'
  )
)
WHERE slug = 'demo-dental';
```

---

## Paso 7: Probar

Envía "Hola" desde tu WhatsApp al número de prueba de Meta.
Deberías recibir el saludo del agente con el menú de botones.

Logs en tiempo real:
```bash
docker logs agentcore_backend -f | grep -i meta
```

---

## Token de larga duración (producción)

El token temporal dura 24h. Para producción:

1. Business Settings → System Users → Add System User
2. Asignar rol: Admin
3. Generate Token → seleccionar app → permisos: `whatsapp_business_messaging`
4. Copiar token → no tiene expiración

---

## Aprobar número de producción

Para usar tu propio número (no el de prueba):

1. WhatsApp > Phone Numbers → Add phone number
2. Proceso de verificación (SMS o llamada)
3. Display name y descripción del negocio
4. Meta revisa en 1-3 días hábiles

---

## Costo estimado (Meta Cloud API)

| Tier | Conversaciones/mes | Costo |
|------|-------------------|-------|
| Gratis | 1,000 | $0 |
| Pagado | >1,000 | ~$0.0042-0.0088 por conversación |

Una "conversación" = ventana de 24h desde el primer mensaje.
Para PyMEs con <1,000 conv/mes = **completamente gratis**.
