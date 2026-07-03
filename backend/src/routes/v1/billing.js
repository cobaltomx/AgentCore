'use strict';

/**
 * Billing Routes — Fase 6
 *
 * GET  /api/v1/billing/status        → Estado de suscripción + facturas
 * GET  /api/v1/billing/plans         → Lista de planes disponibles
 * POST /api/v1/billing/checkout      → Crear sesión de checkout Stripe
 * POST /api/v1/billing/portal        → Crear sesión de portal Stripe
 * POST /api/v1/billing/checkout/verify → Verificar checkout completado
 */

const { StripeService, PLANS } = require('../../services/billing/stripe-service');

async function billingRoutes(app) {

  // GET /api/v1/billing/plans — planes disponibles (público, sin auth)
  app.get('/plans', async () => {
    return Object.entries(PLANS).map(([key, plan]) => ({
      key,
      name:            plan.name,
      monthlyMXN:      plan.monthlyMXN,
      maxAgents:       plan.maxAgents,
      includedMinutes: plan.includedMinutes,
      overagePerMin:   plan.overagePerMin,
      configured:      !!plan.priceId, // indica si está listo en Stripe
    }));
  });

  // GET /api/v1/billing/status — estado de facturación del tenant
  app.get('/status', { onRequest: [app.requireTenant] }, async (req) => {
    const billing = new StripeService({ db: app.db });
    return billing.getBillingStatus(req.tenant.id);
  });

  // POST /api/v1/billing/checkout — iniciar checkout Stripe
  app.post('/checkout', { onRequest: [app.requireTenant] }, async (req, reply) => {
    const { plan } = req.body;

    if (!PLANS[plan]) {
      return reply.code(400).send({ error: `Plan "${plan}" no existe` });
    }

    if (!process.env.STRIPE_SECRET_KEY) {
      return reply.code(503).send({
        error: 'Stripe no configurado. Agrega STRIPE_SECRET_KEY al .env',
      });
    }

    const billing    = new StripeService({ db: app.db });
    const appUrl     = process.env.APP_URL || 'http://localhost:8080';
    const successUrl = `${appUrl}/pages/billing.php?checkout=success`;
    const cancelUrl  = `${appUrl}/pages/billing.php?checkout=cancel`;

    const session = await billing.createCheckoutSession(
      req.tenant.id,
      plan,
      successUrl,
      cancelUrl
    );

    return session;
  });

  // POST /api/v1/billing/portal — abrir portal de Stripe
  app.post('/portal', { onRequest: [app.requireTenant] }, async (req, reply) => {
    if (!process.env.STRIPE_SECRET_KEY) {
      return reply.code(503).send({ error: 'Stripe no configurado' });
    }

    const billing   = new StripeService({ db: app.db });
    const appUrl    = process.env.APP_URL || 'http://localhost:8080';
    const returnUrl = `${appUrl}/pages/billing.php`;

    const session = await billing.createPortalSession(req.tenant.id, returnUrl);
    return session;
  });

  // GET /api/v1/billing/usage — historial de uso
  app.get('/usage', { onRequest: [app.requireTenant] }, async (req) => {
    const result = await app.db.query(
      `SELECT * FROM usage_records
       WHERE tenant_id = $1
       ORDER BY period_start DESC
       LIMIT 12`,
      [req.tenant.id]
    );
    return result.rows;
  });

  // GET /api/v1/billing/invoices — facturas
  app.get('/invoices', { onRequest: [app.requireTenant] }, async (req) => {
    const result = await app.db.query(
      `SELECT * FROM invoices
       WHERE tenant_id = $1
       ORDER BY created_at DESC
       LIMIT 24`,
      [req.tenant.id]
    );
    return result.rows;
  });
}

module.exports = billingRoutes;
