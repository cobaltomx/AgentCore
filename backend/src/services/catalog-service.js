'use strict';


const { logger } = require('./logger');
const log = logger('Catalog');
/**
 * CatalogService — conversational commerce
 *
 * Lógica de catálogo que usan las tools del agente:
 *   - searchProducts: buscar productos activos del tenant
 *   - resolveProduct: encontrar 1 producto por nombre (para agregar al carrito)
 *   - checkout: crear pedido (orders/order_items) + link de pago de Stripe
 *
 * El carrito vive en session.collectedData.cart (lo maneja el executor de tools).
 * El pago reusa Stripe Checkout (mismo patrón que DepositService).
 */

const Stripe = require('stripe');

const CHECKOUT_METADATA_TYPE = 'product_order';

class CatalogService {
  constructor({ db }) {
    this.db = db;
  }

  _stripeFor(tenantSettings) {
    const key = tenantSettings?.stripe?.secretKey || process.env.STRIPE_SECRET_KEY;
    if (!key) return null;
    return new Stripe(key, { apiVersion: '2024-06-20' });
  }

  /** Busca productos activos del tenant. Tokeniza la consulta (lenguaje natural)
   *  y hace match por palabras sobre nombre+descripcion+categoria. */
  async searchProducts(tenantId, opts = {}) {
    const { query, category, limit = 10,
            operation, propertyType, priceMin, priceMax, bedroomsMin } = opts;
    const lim = Math.min(limit, 25);
    const base = `SELECT id, name, description, price_cents, currency, category, stock, attributes, images, image_url
                  FROM products WHERE tenant_id=$1 AND is_active=true`;

    // Filtros ESTRUCTURADOS (precio para cualquier catálogo; operación/tipo/
    // recámaras sobre `attributes` de inmobiliaria). Se ANDean SIEMPRE, incluso
    // en el fallback OR, para que "menos de 20 mil" o "casa" nunca cuelen algo
    // fuera de rango o de otro tipo. Cada filtro empuja su param y usa params.length.
    const addFilters = (params) => {
      let clause = '';
      if (category)           { params.push(`%${category}%`);                    clause += ` AND category ILIKE $${params.length}`; }
      if (operation)          { params.push(String(operation));                  clause += ` AND lower(attributes->>'operation') = lower($${params.length})`; }
      if (propertyType)       { params.push(String(propertyType));               clause += ` AND lower(attributes->>'propertyType') = lower($${params.length})`; }
      if (priceMin != null)   { params.push(Math.round(Number(priceMin) * 100)); clause += ` AND price_cents >= $${params.length}`; }
      if (priceMax != null)   { params.push(Math.round(Number(priceMax) * 100)); clause += ` AND price_cents <= $${params.length}`; }
      if (bedroomsMin != null){ params.push(Number(bedroomsMin));                clause += ` AND COALESCE(NULLIF(attributes->>'bedrooms','')::int,0) >= $${params.length}`; }
      return clause;
    };

    const tokens = this._tokenizeQuery(query);

    // Sin tokens: catálogo filtrado por los criterios estructurados
    if (!tokens.length) {
      const params = [tenantId];
      const sql = base + addFilters(params) + ` ORDER BY sort_order ASC, name ASC LIMIT ${lim}`;
      return (await this.db.query(sql, params)).rows;
    }

    // El "texto buscable" de cada producto
    const HAY = `(coalesce(name,'')||' '||coalesce(description,'')||' '||coalesce(category,''))`;

    // 1) AND: todos los tokens presentes (resultado preciso)
    {
      const params = [tenantId];
      const conds = tokens.map(tok => { params.push(`%${tok}%`); return `${HAY} ILIKE $${params.length}`; });
      const sql = base + ` AND (${conds.join(' AND ')})` + addFilters(params)
                + ` ORDER BY sort_order ASC, name ASC LIMIT ${lim}`;
      const rows = (await this.db.query(sql, params)).rows;
      if (rows.length) return rows;
    }

    // 2) Fallback OR: al menos un token, ordenado por nº de tokens que coinciden
    {
      const params = [tenantId];
      const conds = tokens.map(tok => { params.push(`%${tok}%`); return `${HAY} ILIKE $${params.length}`; });
      let sql = base + ` AND (${conds.join(' OR ')})` + addFilters(params);
      const score = tokens.map(tok => { params.push(`%${tok}%`); return `(${HAY} ILIKE $${params.length})::int`; }).join(' + ');
      sql += ` ORDER BY (${score}) DESC, sort_order ASC LIMIT ${lim}`;
      return (await this.db.query(sql, params)).rows;
    }
  }

  /** Tokeniza una consulta en lenguaje natural: sin acentos, sin stopwords,
   *  con sinónimos de operación (rentar→renta, comprar/vender→venta). */
  _tokenizeQuery(query) {
    if (!query) return [];
    const STOP = new Set(['que','los','las','del','una','unos','unas','con','para','por','favor',
      'quiero','busco','buscar','necesito','tienes','tienen','tiene','hay','interesa','gustaria',
      'algo','muestrame','ensename','disponible','disponibles','zona','colonia','pesos','mensual']);
    const SYN = { rentar:'renta', rento:'renta', rente:'renta', arrendar:'renta',
      comprar:'venta', compra:'venta', vender:'venta', vendo:'venta', adquirir:'venta',
      depa:'departamento', depas:'departamento', deptos:'departamento', depto:'departamento',
      casas:'casa', terrenos:'terreno', bodegas:'bodega', departamentos:'departamento' };
    const out = [];
    const seen = new Set();
    String(query).toLowerCase()
      .normalize('NFD').replace(/[̀-ͯ]/g, '')   // quitar acentos
      .split(/[^a-z0-9]+/)
      .forEach(w => {
        if (!w) return;
        w = SYN[w] || w;
        if (w.length < 3 || STOP.has(w) || seen.has(w)) return;
        seen.add(w); out.push(w);
      });
    return out;
  }

  /** Encuentra el mejor match de 1 producto por nombre (para agregar al carrito). */
  async resolveProduct(tenantId, productName) {
    if (!productName) return null;
    const r = await this.db.query(
      `SELECT id, name, price_cents, currency, stock FROM products
       WHERE tenant_id=$1 AND is_active=true AND name ILIKE $2
       ORDER BY (name ILIKE $3) DESC, length(name) ASC LIMIT 1`,
      [tenantId, `%${productName}%`, productName]
    );
    return r.rows[0] || null;
  }

  /**
   * Crea el pedido a partir del carrito y genera el link de pago.
   * @param {Array} cart - [{ product_id, name, unit_cents, quantity }]
   * @returns {{ url, order_id, total_cents }} o lanza Error con mensaje claro
   */
  async checkout({ tenantId, cart, customerName, customerPhone, conversationId, channel, appUrl, deliveryAddress = null }) {
    if (!Array.isArray(cart) || cart.length === 0) {
      throw new Error('El carrito está vacío.');
    }

    const total = cart.reduce((s, it) => s + (it.unit_cents * it.quantity), 0);
    if (total < 1000) throw new Error('El total del pedido debe ser de al menos $10 MXN.');

    const tRes = await this.db.query('SELECT name, settings FROM tenants WHERE id=$1', [tenantId]);
    const tenant = tRes.rows[0];
    if (!tenant) throw new Error('Negocio no encontrado.');

    // Stripe es OPCIONAL: si no está configurado, el pedido se crea igual como
    // "pago contra entrega" (un restaurante no debe quedar bloqueado sin Stripe).
    const stripe = this._stripeFor(tenant.settings);

    // Resolver el contacto/cliente para enlazar el pedido a su expediente
    // (y de paso enlazar la conversación). Quien hace un pedido ES un cliente.
    let leadId = null;
    try {
      const { resolveContact } = require('./contacts');
      leadId = (await resolveContact(this.db, tenantId, {
        phone: customerPhone, name: customerName, conversationId, sourceChannel: channel || 'order',
      })).id;
    } catch (e) { /* no bloquear el pedido si falla la resolución */ }

    // Crear el pedido (pending)
    const orderRes = await this.db.query(
      `INSERT INTO orders
         (tenant_id, conversation_id, lead_id, customer_name, customer_phone, channel, status, total_cents, currency, notes)
       VALUES ($1,$2,$3,$4,$5,$6,'pending',$7,'mxn',$8) RETURNING id`,
      [tenantId, conversationId || null, leadId, customerName || null, customerPhone || null,
       channel || 'whatsapp', total, deliveryAddress ? `Entrega: ${deliveryAddress}` : null]
    );
    const orderId = orderRes.rows[0].id;

    // Líneas del pedido (snapshot)
    for (const it of cart) {
      await this.db.query(
        `INSERT INTO order_items (order_id, product_id, name, unit_cents, quantity, line_cents)
         VALUES ($1,$2,$3,$4,$5,$6)`,
        [orderId, it.product_id || null, it.name, it.unit_cents, it.quantity, it.unit_cents * it.quantity]
      );
    }

    // Link de pago (solo si hay Stripe). Si falla, seguimos como pago en entrega.
    let paymentUrl = null;
    if (stripe) {
      try {
        const session = await stripe.checkout.sessions.create({
          mode: 'payment',
          line_items: cart.map(it => ({
            price_data: { currency: 'mxn', unit_amount: it.unit_cents, product_data: { name: it.name } },
            quantity: it.quantity,
          })),
          success_url: `${appUrl || ''}/pages/orders.php?order=success`,
          cancel_url:  `${appUrl || ''}/pages/orders.php?order=cancel`,
          locale: 'es',
          metadata: { type: CHECKOUT_METADATA_TYPE, order_id: orderId, tenant_id: tenantId },
        });
        paymentUrl = session.url;
        await this.db.query(
          'UPDATE orders SET stripe_session=$2, payment_url=$3, updated_at=NOW() WHERE id=$1',
          [orderId, session.id, session.url]
        );
      } catch (err) {
        log.warn('[checkout] Stripe no disponible, sigue como pago en entrega:', err.message);
      }
    }

    // Confirmación del pedido por WhatsApp EN SEGUNDO PLANO: no bloquear la
    // respuesta del bot (antes el envío + sondeo de entrega tardaba ~varios s).
    this._sendOrderConfirmation(tenant, {
      cart, total, customerName, customerPhone, deliveryAddress, paymentUrl,
    }).catch(() => {});

    return {
      url: paymentUrl, order_id: orderId, total_cents: total,
      paymentOnDelivery: !paymentUrl,
    };
  }

  // Envía al cliente la confirmación del pedido por WhatsApp (resumen + total +
  // dirección). Honesto: si rebota (fuera de ventana 24h / sin sender), lo indica.
  async _sendOrderConfirmation(tenant, { cart, total, customerName, customerPhone, deliveryAddress, paymentUrl }) {
    if (!customerPhone) return { ok: false, reason: 'sin teléfono' };
    try {
      const { getTwilioClient, getWhatsAppFrom, sendWhatsAppTracked } = require('./twilio-client');
      const { toWhatsAppMx } = require('./phone-utils');
      const twilio  = getTwilioClient(tenant.settings || {});
      const fromNum = getWhatsAppFrom(tenant.settings || {});
      if (!twilio || !fromNum) return { ok: false, reason: 'sin WhatsApp configurado' };

      const biz = tenant.settings?.businessProfile?.businessName || tenant.name || 'el negocio';
      const lines = cart.map(it => `• ${it.quantity} × ${it.name} — $${(it.unit_cents * it.quantity / 100).toLocaleString('es-MX')}`);
      const body = [
        `✅ *Pedido confirmado — ${biz}*`,
        '', ...lines, '',
        `*Total: $${(total / 100).toLocaleString('es-MX')}*`,
        deliveryAddress ? `🛵 Entrega: ${deliveryAddress}` : null,
        paymentUrl ? `💳 Paga aquí: ${paymentUrl}` : '💵 Pago al recibir.',
        '', '¡Gracias por tu pedido!',
      ].filter(Boolean).join('\n');

      const r = await sendWhatsAppTracked(twilio, { from: fromNum, to: toWhatsAppMx(customerPhone), body });
      return { ok: r.ok, status: r.status, errorCode: r.errorCode };
    } catch (e) {
      return { ok: false, reason: e.message };
    }
  }

  /** Marca un pedido pagado desde el webhook de Stripe (idempotente). */
  async markOrderPaid(session) {
    const orderId  = session?.metadata?.order_id;
    const tenantId = session?.metadata?.tenant_id;
    if (!orderId) return { ok: false };

    const upd = await this.db.query(
      `UPDATE orders SET status='paid', paid_at=NOW(), updated_at=NOW()
       WHERE id=$1 AND status<>'paid' RETURNING id, customer_name`,
      [orderId]
    );
    if (!upd.rows[0]) return { ok: true, already: true };

    if (tenantId) {
      try {
        const { createNotification } = require('./notifications');
        createNotification(this.db, {
          tenantId,
          type:  'new_lead',
          title: `🛒 Pedido pagado${upd.rows[0].customer_name ? ' — ' + upd.rows[0].customer_name : ''}`,
          body:  'Un cliente pagó su pedido del catálogo.',
          link:  '/pages/orders.php',
        });
      } catch { /* non-fatal */ }
    }
    return { ok: true, orderId };
  }
}

module.exports = { CatalogService, CHECKOUT_METADATA_TYPE };
