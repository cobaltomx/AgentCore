'use strict';


const { logger } = require('../logger');
const log = logger('Stripe');
/**
 * Stripe Billing Service — Fase 6
 *
 * Modelo de precios:
 * - Plan base: precio fijo mensual (Stripe Subscription)
 * - Excedente: precio por minuto adicional (Stripe Metered Billing)
 *
 * Flujo de un cliente nuevo:
 * 1. Admin crea tenant → POST /billing/checkout → Stripe Checkout Session
 * 2. Cliente paga → Stripe redirige a success_url
 * 3. Stripe envía webhook checkout.session.completed
 * 4. Backend activa el plan del tenant
 * 5. Cada minuto excedente → reportar a Stripe vía usage records
 * 6. Stripe cobra automáticamente al final del período
 */

const Stripe = require('stripe');

const stripe = new Stripe(process.env.STRIPE_SECRET_KEY, {
  apiVersion: '2024-06-20',
});

// ── Definición de planes ──────────────────────────────────────
// Estos IDs deben coincidir con los creados en el dashboard de Stripe
// Se configuran en .env después de crearlos

const PLANS = {
  starter: {
    name:             'Starter',
    priceId:          process.env.STRIPE_PRICE_STARTER,    // precio fijo mensual
    meterPriceId:     process.env.STRIPE_PRICE_METER,      // precio por minuto excedente
    maxAgents:        1,
    includedMinutes:  500,
    overagePerMin:    8,    // centavos MXN por minuto excedente
    monthlyMXN:       14900, // centavos = $149 MXN
  },
  growth: {
    name:             'Growth',
    priceId:          process.env.STRIPE_PRICE_GROWTH,
    meterPriceId:     process.env.STRIPE_PRICE_METER,
    maxAgents:        3,
    includedMinutes:  2000,
    overagePerMin:    6,
    monthlyMXN:       39900,
  },
  business: {
    name:             'Business',
    priceId:          process.env.STRIPE_PRICE_BUSINESS,
    meterPriceId:     process.env.STRIPE_PRICE_METER,
    maxAgents:        10,
    includedMinutes:  6000,
    overagePerMin:    5,
    monthlyMXN:       89900,
  },
};

class StripeService {
  constructor({ db }) {
    this.db = db;
  }

  /**
   * Crear o recuperar customer de Stripe para un tenant
   */
  async getOrCreateCustomer(tenantId) {
    // Buscar customer existente
    const tenantResult = await this.db.query(
      'SELECT id, name, stripe_customer_id FROM tenants WHERE id = $1',
      [tenantId]
    );
    const tenant = tenantResult.rows[0];
    if (!tenant) throw new Error(`Tenant ${tenantId} no encontrado`);

    if (tenant.stripe_customer_id) {
      // Verificar que aún existe en Stripe
      try {
        const customer = await stripe.customers.retrieve(tenant.stripe_customer_id);
        if (!customer.deleted) return customer;
      } catch { /* no existe, crear nuevo */ }
    }

    // Crear customer nuevo en Stripe
    const customer = await stripe.customers.create({
      name:     tenant.name,
      metadata: { tenant_id: tenantId, platform: 'agentcore' },
    });

    // Guardar en DB
    await this.db.query(
      'UPDATE tenants SET stripe_customer_id = $1 WHERE id = $2',
      [customer.id, tenantId]
    );

    return customer;
  }

  /**
   * Crear sesión de checkout de Stripe
   * El tenant es redirigido a Stripe para ingresar su tarjeta
   *
   * @param {string} tenantId
   * @param {string} planKey   - 'starter' | 'growth' | 'business'
   * @param {string} successUrl
   * @param {string} cancelUrl
   * @returns {Object} { sessionId, url }
   */
  async createCheckoutSession(tenantId, planKey, successUrl, cancelUrl) {
    const plan = PLANS[planKey];
    if (!plan) throw new Error(`Plan "${planKey}" no encontrado`);
    if (!plan.priceId) throw new Error(`STRIPE_PRICE_${planKey.toUpperCase()} no configurado en .env`);

    const customer = await this.getOrCreateCustomer(tenantId);

    // Construir line items: precio fijo + precio metered (excedente)
    const lineItems = [
      { price: plan.priceId, quantity: 1 },
    ];

    // Agregar precio metered si está configurado
    if (plan.meterPriceId) {
      lineItems.push({ price: plan.meterPriceId });
    }

    const session = await stripe.checkout.sessions.create({
      customer:    customer.id,
      mode:        'subscription',
      line_items:  lineItems,
      success_url: `${successUrl}?session_id={CHECKOUT_SESSION_ID}`,
      cancel_url:  cancelUrl,
      subscription_data: {
        trial_period_days: 14, // 14 días de prueba gratuita
        metadata: {
          tenant_id: tenantId,
          plan:      planKey,
        },
      },
      metadata: { tenant_id: tenantId, plan: planKey },
      locale:   'es',
      currency: 'mxn',
    });

    return { sessionId: session.id, url: session.url };
  }

  /**
   * Crear portal de cliente (gestión de suscripción)
   * El tenant puede actualizar/cancelar desde el portal de Stripe
   */
  async createPortalSession(tenantId, returnUrl) {
    const customer = await this.getOrCreateCustomer(tenantId);

    const session = await stripe.billingPortal.sessions.create({
      customer:   customer.id,
      return_url: returnUrl,
    });

    return { url: session.url };
  }

  /**
   * Reportar minutos de uso excedente a Stripe
   * Se llama al final de cada llamada cuando el tenant supera sus minutos incluidos
   *
   * @param {string} tenantId
   * @param {number} minutesUsed - minutos a reportar
   */
  async reportUsage(tenantId, minutesUsed) {
    if (minutesUsed <= 0) return null;

    // Buscar suscripción activa
    const subResult = await this.db.query(
      `SELECT stripe_subscription_id, stripe_meter_item_id, plan
       FROM subscriptions
       WHERE tenant_id = $1 AND status = 'active'
       LIMIT 1`,
      [tenantId]
    );

    const sub = subResult.rows[0];
    if (!sub?.stripe_subscription_id || !sub?.stripe_meter_item_id) {
      log.warn(`[Stripe] Sin suscripción activa para tenant ${tenantId}`);
      return null;
    }

    // Calcular minutos excedentes
    const plan             = PLANS[sub.plan] || PLANS.starter;
    const tenantResult     = await this.db.query(
      'SELECT minutes_used_mo FROM tenants WHERE id = $1', [tenantId]
    );
    const currentUsed      = (tenantResult.rows[0]?.minutes_used_mo || 0);
    const included         = plan.includedMinutes;
    const overageMinutes   = Math.max(0, currentUsed - included);

    if (overageMinutes <= 0) return null;

    // Reportar a Stripe Metered Billing
    const usageRecord = await stripe.subscriptionItems.createUsageRecord(
      sub.stripe_meter_item_id,
      {
        quantity:  overageMinutes,
        timestamp: Math.floor(Date.now() / 1000),
        action:    'set', // 'set' reemplaza, 'increment' acumula
      }
    );

    // Guardar en nuestra DB
    await this.db.query(
      `UPDATE usage_records
       SET minutes_overage = $1, reported_to_stripe = true, stripe_usage_record_id = $2
       WHERE tenant_id = $3
         AND period_start <= NOW() AND period_end >= NOW()`,
      [overageMinutes, usageRecord.id, tenantId]
    );

    return usageRecord;
  }

  /**
   * Procesar webhook de Stripe
   * Stripe llama a POST /webhooks/stripe con eventos de facturación
   */
  async handleWebhook(rawBody, signature) {
    const webhookSecret = process.env.STRIPE_WEBHOOK_SECRET;

    let event;
    try {
      event = stripe.webhooks.constructEvent(rawBody, signature, webhookSecret);
    } catch (err) {
      throw new Error(`Webhook signature inválida: ${err.message}`);
    }

    log.info(`[Stripe Webhook] ${event.type}`);

    switch (event.type) {

      // Checkout completado → puede ser suscripción del SaaS o anticipo de cliente
      case 'checkout.session.completed': {
        const session = event.data.object;
        if (session.mode === 'payment' &&
            session.metadata?.type === 'appointment_deposit') {
          // Anticipo de un cliente final → marcar cita pagada
          const { DepositService } = require('../deposit-service');
          await new DepositService({ db: this.db }).markPaidFromSession(session);
        } else if (session.mode === 'payment' &&
                   session.metadata?.type === 'product_order') {
          // Pedido del catálogo → marcar orden pagada
          const { CatalogService } = require('../catalog-service');
          await new CatalogService({ db: this.db }).markOrderPaid(session);
        } else {
          // Suscripción del SaaS (AgentCore cobra al tenant)
          await this._handleCheckoutComplete(session);
        }
        break;
      }

      // Suscripción actualizada (upgrade/downgrade)
      case 'customer.subscription.updated':
        await this._handleSubscriptionUpdated(event.data.object);
        break;

      // Suscripción cancelada
      case 'customer.subscription.deleted':
        await this._handleSubscriptionCanceled(event.data.object);
        break;

      // Factura pagada
      case 'invoice.paid':
        await this._handleInvoicePaid(event.data.object);
        break;

      // Pago fallido
      case 'invoice.payment_failed':
        await this._handlePaymentFailed(event.data.object);
        break;

      default:
        log.info(`[Stripe Webhook] Evento ignorado: ${event.type}`);
    }

    return { received: true, type: event.type };
  }

  /**
   * Obtener estado de facturación de un tenant
   */
  async getBillingStatus(tenantId) {
    const [subResult, usageResult, invoiceResult] = await Promise.all([
      this.db.query(
        'SELECT * FROM subscriptions WHERE tenant_id = $1 ORDER BY created_at DESC LIMIT 1',
        [tenantId]
      ),
      this.db.query(
        `SELECT * FROM usage_records WHERE tenant_id = $1
         ORDER BY period_start DESC LIMIT 1`,
        [tenantId]
      ),
      this.db.query(
        `SELECT * FROM invoices WHERE tenant_id = $1
         ORDER BY created_at DESC LIMIT 6`,
        [tenantId]
      ),
    ]);

    return {
      subscription: subResult.rows[0]   || null,
      currentUsage: usageResult.rows[0] || null,
      invoices:     invoiceResult.rows   || [],
    };
  }

  // ─── Handlers de webhooks ────────────────────────────────────

  async _handleCheckoutComplete(session) {
    const tenantId = session.metadata?.tenant_id;
    const planKey  = session.metadata?.plan || 'starter';

    if (!tenantId) return;

    const subscriptionId = session.subscription;
    const stripeSubData  = await stripe.subscriptions.retrieve(subscriptionId, {
      expand: ['items'],
    });

    const plan = PLANS[planKey] || PLANS.starter;

    // Guardar suscripción
    await this.db.query(
      `INSERT INTO subscriptions
         (tenant_id, stripe_customer_id, stripe_subscription_id, stripe_price_id,
          stripe_meter_item_id, plan, status, current_period_start, current_period_end, trial_end)
       VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10)
       ON CONFLICT (stripe_subscription_id) DO UPDATE SET
         status = EXCLUDED.status,
         current_period_start = EXCLUDED.current_period_start,
         current_period_end = EXCLUDED.current_period_end`,
      [
        tenantId,
        session.customer,
        subscriptionId,
        plan.priceId,
        stripeSubData.items.data.find(i => i.price.id === plan.meterPriceId)?.id || null,
        planKey,
        stripeSubData.status,
        new Date(stripeSubData.current_period_start * 1000),
        new Date(stripeSubData.current_period_end   * 1000),
        stripeSubData.trial_end ? new Date(stripeSubData.trial_end * 1000) : null,
      ]
    );

    // Actualizar tenant con nuevo plan
    await this.db.query(
      `UPDATE tenants SET
         plan = $1,
         status = 'active',
         max_agents = $2,
         max_minutes_mo = $3,
         stripe_customer_id = $4
       WHERE id = $5`,
      [planKey, plan.maxAgents, plan.includedMinutes, session.customer, tenantId]
    );

    // Crear registro de uso para este período
    await this.db.query(
      `INSERT INTO usage_records
         (tenant_id, period_start, period_end, minutes_included, plan_amount_cents)
       VALUES ($1,$2,$3,$4,$5)
       ON CONFLICT DO NOTHING`,
      [
        tenantId,
        new Date(stripeSubData.current_period_start * 1000),
        new Date(stripeSubData.current_period_end   * 1000),
        plan.includedMinutes,
        plan.monthlyMXN,
      ]
    );

    log.info(`[Stripe] Tenant ${tenantId} activado en plan ${planKey}`);
  }

  async _handleSubscriptionUpdated(subscription) {
    const tenantId = subscription.metadata?.tenant_id;
    if (!tenantId) return;

    const planKey = subscription.metadata?.plan || 'starter';
    const plan    = PLANS[planKey] || PLANS.starter;

    await this.db.query(
      `UPDATE subscriptions SET status=$1, current_period_start=$2, current_period_end=$3 WHERE stripe_subscription_id=$4`,
      [subscription.status,
       new Date(subscription.current_period_start * 1000),
       new Date(subscription.current_period_end   * 1000),
       subscription.id]
    );

    // Actualizar límites si cambió el plan
    await this.db.query(
      `UPDATE tenants SET plan=$1, max_agents=$2, max_minutes_mo=$3 WHERE id=$4`,
      [planKey, plan.maxAgents, plan.includedMinutes, tenantId]
    );
  }

  async _handleSubscriptionCanceled(subscription) {
    await this.db.query(
      `UPDATE subscriptions SET status='canceled', canceled_at=NOW() WHERE stripe_subscription_id=$1`,
      [subscription.id]
    );

    const tenantId = subscription.metadata?.tenant_id;
    if (tenantId) {
      await this.db.query(
        `UPDATE tenants SET status='suspended', plan='starter', max_agents=1, max_minutes_mo=0 WHERE id=$1`,
        [tenantId]
      );
    }
  }

  async _handleInvoicePaid(invoice) {
    const customerId = invoice.customer;
    const tenantResult = await this.db.query(
      'SELECT id FROM tenants WHERE stripe_customer_id=$1', [customerId]
    );
    const tenantId = tenantResult.rows[0]?.id;
    if (!tenantId) return;

    await this.db.query(
      `INSERT INTO invoices
         (tenant_id, stripe_invoice_id, amount_cents, currency, status, paid_at, pdf_url, stripe_payment_url)
       VALUES ($1,$2,$3,$4,'paid',NOW(),$5,$6)
       ON CONFLICT (stripe_invoice_id) DO UPDATE SET status='paid', paid_at=NOW()`,
      [tenantId, invoice.id, invoice.amount_paid, invoice.currency,
       invoice.invoice_pdf, invoice.hosted_invoice_url]
    );

    // Reactivar tenant si estaba suspendido por falta de pago
    await this.db.query(
      `UPDATE tenants SET status='active' WHERE id=$1 AND status='suspended'`, [tenantId]
    );
  }

  async _handlePaymentFailed(invoice) {
    const customerId = invoice.customer;
    const tenantResult = await this.db.query(
      'SELECT id FROM tenants WHERE stripe_customer_id=$1', [customerId]
    );
    const tenantId = tenantResult.rows[0]?.id;
    if (!tenantId) return;

    await this.db.query(
      `INSERT INTO invoices
         (tenant_id, stripe_invoice_id, amount_cents, currency, status, due_date, stripe_payment_url)
       VALUES ($1,$2,$3,$4,'open',$5,$6)
       ON CONFLICT (stripe_invoice_id) DO UPDATE SET status='open'`,
      [tenantId, invoice.id, invoice.amount_due, invoice.currency,
       invoice.due_date ? new Date(invoice.due_date * 1000) : null,
       invoice.hosted_invoice_url]
    );

    // Marcar como past_due (no suspender inmediatamente)
    await this.db.query(
      `UPDATE subscriptions SET status='past_due' WHERE tenant_id=$1 AND status='active'`,
      [tenantId]
    );

    log.warn(`[Stripe] Pago fallido para tenant ${tenantId}`);
  }
}

module.exports = { StripeService, PLANS };
