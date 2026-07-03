'use strict';

/**
 * DepositService — Cobros / anticipos por el bot
 *
 * Permite que un TENANT cobre a SUS clientes (ej. anticipo de cita) generando
 * un link de pago de Stripe. Distinto del billing del SaaS (que cobra AgentCore
 * a sus tenants).
 *
 * Cuenta de Stripe usada (en orden de prioridad):
 *   1. settings.stripe.secretKey del tenant  (su propia cuenta — ideal)
 *   2. STRIPE_SECRET_KEY de la plataforma     (fallback para piloto)
 *
 * Flujo:
 *   1. Bot/usuario genera link → createDepositLink()
 *   2. Cliente paga en Stripe → webhook checkout.session.completed
 *   3. markPaidFromSession() marca el anticipo pagado y notifica al tenant
 */

const Stripe = require('stripe');

const DEPOSIT_METADATA_TYPE = 'appointment_deposit';

class DepositService {
  constructor({ db }) {
    this.db = db;
  }

  /** Devuelve un cliente Stripe para el tenant, o null si no hay llave. */
  _stripeFor(tenantSettings) {
    const key = tenantSettings?.stripe?.secretKey || process.env.STRIPE_SECRET_KEY;
    if (!key) return null;
    return new Stripe(key, { apiVersion: '2024-06-20' });
  }

  /**
   * Crea un link de pago de anticipo para una cita.
   * @returns {{ url, amount_cents, currency }} o lanza Error con mensaje claro
   */
  async createDepositLink({ appointmentId, tenantId, amountCents, appUrl }) {
    // Cargar cita + settings + monto sugerido del tipo de servicio
    const r = await this.db.query(
      `SELECT a.id, a.patient_name, a.patient_phone, a.scheduled_at,
              a.deposit_amount, a.deposit_status,
              st.name AS service_name, st.deposit_amount AS st_deposit,
              t.name AS tenant_name, t.settings AS settings
       FROM appointments a
       JOIN tenants t ON t.id = a.tenant_id
       LEFT JOIN service_types st ON st.id = a.service_type_id
       WHERE a.id = $1 AND a.tenant_id = $2`,
      [appointmentId, tenantId]
    );
    const appt = r.rows[0];
    if (!appt) throw new Error('Cita no encontrada');
    if (appt.deposit_status === 'paid') throw new Error('Esta cita ya tiene el anticipo pagado');

    // Determinar monto: parámetro > deposit_amount de la cita > del tipo de servicio
    const pesos = amountCents != null
      ? amountCents / 100
      : Number(appt.deposit_amount) || Number(appt.st_deposit) || 0;
    const cents = Math.round(pesos * 100);
    if (cents < 1000) { // mínimo $10 MXN (Stripe exige montos mínimos)
      throw new Error('El monto del anticipo debe ser de al menos $10 MXN');
    }

    const stripe = this._stripeFor(appt.settings);
    if (!stripe) throw new Error('Stripe no está configurado. Agrega tu llave en Configuración o STRIPE_SECRET_KEY.');

    const concept = `Anticipo${appt.service_name ? ' — ' + appt.service_name : ''} · ${appt.tenant_name}`;

    // Checkout Session en modo pago único (no suscripción)
    let session;
    try {
      session = await stripe.checkout.sessions.create({
        mode: 'payment',
        line_items: [{
          price_data: {
            currency:     'mxn',
            unit_amount:  cents,
            product_data: { name: concept },
          },
          quantity: 1,
        }],
        success_url: `${appUrl || ''}/pages/appointments.php?deposit=success`,
        cancel_url:  `${appUrl || ''}/pages/appointments.php?deposit=cancel`,
        locale: 'es',
        metadata: {
          type:           DEPOSIT_METADATA_TYPE,
          appointment_id: appointmentId,
          tenant_id:      tenantId,
        },
      });
    } catch (err) {
      // Traducir errores de Stripe a mensajes accionables para el admin
      if (err.type === 'StripeAuthenticationError') {
        throw new Error('Stripe no está configurado correctamente. Revisa tu llave secreta en Configuración.');
      }
      throw new Error(`No se pudo generar el link de pago: ${err.message}`);
    }

    // Persistir en la cita y registrar el pago pendiente
    await this.db.query(
      `UPDATE appointments
       SET deposit_status = 'pending',
           deposit_amount = $2,
           deposit_currency = 'mxn',
           deposit_payment_link = $3,
           deposit_checkout_session = $4,
           updated_at = NOW()
       WHERE id = $1`,
      [appointmentId, pesos, session.url, session.id]
    );

    await this.db.query(
      `INSERT INTO customer_payments
         (tenant_id, appointment_id, concept, amount_cents, currency, status, stripe_session, payment_url)
       VALUES ($1,$2,$3,$4,'mxn','pending',$5,$6)`,
      [tenantId, appointmentId, concept, cents, session.id, session.url]
    );

    return { url: session.url, amount_cents: cents, currency: 'mxn' };
  }

  /**
   * Marca un anticipo como pagado a partir de una checkout.session de Stripe.
   * Idempotente: si ya estaba pagado, no hace nada.
   * @returns {{ ok: boolean, appointmentId?: string }}
   */
  async markPaidFromSession(session) {
    const appointmentId = session?.metadata?.appointment_id;
    const tenantId      = session?.metadata?.tenant_id;
    if (!appointmentId) return { ok: false };

    const paymentIntent = session.payment_intent || null;

    const upd = await this.db.query(
      `UPDATE appointments
       SET deposit_status = 'paid',
           deposit_paid_at = NOW(),
           deposit_payment_intent = $2,
           updated_at = NOW()
       WHERE id = $1 AND deposit_status <> 'paid'
       RETURNING id, patient_name`,
      [appointmentId, paymentIntent]
    );

    await this.db.query(
      `UPDATE customer_payments
       SET status = 'paid', paid_at = NOW(), stripe_payment_intent = $2
       WHERE stripe_session = $1 AND status <> 'paid'`,
      [session.id, paymentIntent]
    );

    if (!upd.rows[0]) return { ok: true, appointmentId, already: true };

    // Notificar al tenant (non-fatal)
    if (tenantId) {
      try {
        const { createNotification } = require('./notifications');
        createNotification(this.db, {
          tenantId,
          type:  'new_lead',
          title: `💳 Anticipo pagado${upd.rows[0].patient_name ? ' — ' + upd.rows[0].patient_name : ''}`,
          body:  'Un cliente pagó el anticipo de su cita.',
          link:  '/pages/appointments.php',
        });
      } catch { /* non-fatal */ }
    }

    return { ok: true, appointmentId };
  }
}

module.exports = { DepositService, DEPOSIT_METADATA_TYPE };
