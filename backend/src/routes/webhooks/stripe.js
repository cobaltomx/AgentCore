'use strict';

/**
 * Stripe Webhook — Fase 6
 * POST /webhooks/stripe
 *
 * CRÍTICO: Stripe requiere el body RAW (sin parsear) para verificar la firma.
 * Por eso este endpoint usa config: { rawBody: true }
 */

const { StripeService } = require('../../services/billing/stripe-service');

async function stripeWebhookRoute(app) {

  app.post('/', {
    config: { rawBody: true },
    // Excluir del rate limit global (Stripe puede enviar muchos eventos)
  }, async (request, reply) => {

    const signature = request.headers['stripe-signature'];
    const rawBody   = request.rawBody || JSON.stringify(request.body);

    if (!signature) {
      app.log.warn('[Stripe Webhook] Sin stripe-signature header');
      return reply.code(400).send({ error: 'Missing signature' });
    }

    try {
      const billing = new StripeService({ db: app.db });
      const result  = await billing.handleWebhook(rawBody, signature);

      app.log.info({ type: result.type }, '[Stripe Webhook] Procesado ✅');
      return reply.code(200).send({ received: true });

    } catch (err) {
      app.log.error({ err }, '[Stripe Webhook] Error');

      // Si es error de firma → 400
      if (err.message.includes('signature')) {
        return reply.code(400).send({ error: err.message });
      }

      // Otros errores → 500 para que Stripe reintente
      return reply.code(500).send({ error: 'Webhook processing failed' });
    }
  });

  // Health check del webhook
  app.get('/status', async () => ({
    status:         'active',
    stripeConfigured: !!process.env.STRIPE_SECRET_KEY,
    webhookConfigured: !!process.env.STRIPE_WEBHOOK_SECRET,
  }));
}

module.exports = stripeWebhookRoute;
