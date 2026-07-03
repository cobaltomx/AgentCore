# Setup Stripe — Fase 6

## 1. Crear cuenta Stripe

1. Ir a https://stripe.com → Crear cuenta
2. Activar modo **Test** (toggle en el dashboard)
3. Completar los datos de negocio para modo Live cuando estés listo

---

## 2. Crear productos y precios en Stripe

### Plan Starter ($149 MXN/mes)
```
Dashboard → Products → Add product
Nombre: "AgentCore Starter"
Precio: $149.00 MXN · Mensual (Recurring)
→ Copiar Price ID: price_xxxx → STRIPE_PRICE_STARTER
```

### Plan Growth ($399 MXN/mes)
```
Nombre: "AgentCore Growth"
Precio: $399.00 MXN · Mensual
→ STRIPE_PRICE_GROWTH
```

### Plan Business ($899 MXN/mes)
```
Nombre: "AgentCore Business"
Precio: $899.00 MXN · Mensual
→ STRIPE_PRICE_BUSINESS
```

### Precio por minuto excedente (Metered)
```
Nombre: "Minutos excedentes"
Tipo: Usage-based (Metered)
Precio: $0.08 MXN por unidad (1 unidad = 1 minuto)
Modo de agregación: Sum
→ STRIPE_PRICE_METER
```

---

## 3. Obtener API Keys

```
Dashboard → Developers → API Keys
Secret key: sk_test_xxxx → STRIPE_SECRET_KEY
```

---

## 4. Configurar webhook

```
Dashboard → Developers → Webhooks → Add endpoint

URL: https://TU_DOMINIO/webhooks/stripe
Eventos a escuchar:
  ✅ checkout.session.completed
  ✅ customer.subscription.updated
  ✅ customer.subscription.deleted
  ✅ invoice.paid
  ✅ invoice.payment_failed

→ Copiar Signing secret: whsec_xxxx → STRIPE_WEBHOOK_SECRET
```

### Para desarrollo local (Stripe CLI)
```bash
# Instalar Stripe CLI: https://stripe.com/docs/stripe-cli
stripe login
stripe listen --forward-to localhost:3000/webhooks/stripe

# En otra terminal, simular eventos:
stripe trigger checkout.session.completed
stripe trigger invoice.paid
```

---

## 5. Variables de entorno finales

```env
STRIPE_SECRET_KEY=sk_test_xxxxxxxxxxxxxxxxxxxxxxxxxxxx
STRIPE_WEBHOOK_SECRET=whsec_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
STRIPE_PRICE_STARTER=price_xxxxxxxxxxxxxxxxxxxxxxxx
STRIPE_PRICE_GROWTH=price_xxxxxxxxxxxxxxxxxxxxxxxx
STRIPE_PRICE_BUSINESS=price_xxxxxxxxxxxxxxxxxxxxxxxx
STRIPE_PRICE_METER=price_xxxxxxxxxxxxxxxxxxxxxxxx
```

---

## 6. Habilitar Portal del cliente en Stripe

```
Dashboard → Settings → Billing → Customer portal
→ Activar: "Allow customers to update their payment method"
→ Activar: "Allow customers to cancel subscriptions"
→ Guardar
```

---

## 7. Activar modo Live (producción)

1. Toggle "Test mode" → "Live mode" en Stripe dashboard
2. Cambiar `sk_test_` → `sk_live_` en STRIPE_SECRET_KEY
3. Crear los mismos productos/precios en modo Live
4. Actualizar todos los STRIPE_PRICE_* con los IDs de Live
5. Crear nuevo webhook para el dominio de producción

---

## Costos de Stripe

| Concepto | Costo |
|---|---|
| Tarjetas nacionales MX | 3.6% + $3 MXN |
| Tarjetas internacionales | 4.6% + $3 MXN |
| OXXO Pay | 3% + $3 MXN (máx $20) |
| Sin cuota mensual | $0 |

Para un cliente que paga $149/mes: costo de procesamiento ~$8.37 MXN (5.6%)
Tu margen neto sigue siendo >65% incluyendo el fee de Stripe.

---

## Probar el flujo completo local

1. Levantar backend + ngrok
2. Stripe CLI escuchando: `stripe listen --forward-to localhost:3000/webhooks/stripe`
3. Abrir `http://localhost:8080/pages/billing.php`
4. Click en "Actualizar a Growth"
5. Usar tarjeta de prueba: `4242 4242 4242 4242`, cualquier fecha futura, cualquier CVC
6. Verificar en DB:
```sql
SELECT plan, status, stripe_customer_id FROM tenants WHERE slug = 'demo-dental';
SELECT * FROM subscriptions ORDER BY created_at DESC LIMIT 1;
```
