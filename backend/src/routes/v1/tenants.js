'use strict';

async function tenantsRoutes(app) {

  // GET /api/v1/tenants
  app.get('/', { onRequest: [app.requireTenant] }, async (req) => {
    const r = await app.db.query(
      `SELECT id, slug, name, plan, status, timezone, settings,
              max_agents, max_minutes_mo, minutes_used_mo, created_at,
              widget_key, is_ready
       FROM tenants WHERE id = $1`,
      [req.tenant.id]
    );
    return r.rows[0];
  });

  // POST /api/v1/tenants/twilio/verify — valida credenciales Twilio del tenant
  app.post('/twilio/verify', { onRequest: [app.requireTenant] }, async (req, reply) => {
    const { accountSid, authToken, phoneNumber } = req.body || {};

    // Si no vienen en el body, leer del settings guardado
    const tenantResult = await app.db.query(
      'SELECT settings FROM tenants WHERE id = $1', [req.tenant.id]
    );
    const saved = tenantResult.rows[0]?.settings?.twilio ?? {};

    const sid   = accountSid  || saved.accountSid;
    const token = authToken   || saved.authToken;
    const phone = phoneNumber || saved.phoneNumber;

    // Validar formato básico SID
    if (!sid || !/^AC[a-f0-9]{32}$/.test(sid)) {
      return reply.code(400).send({ ok: false, error: 'Account SID inválido. Debe empezar con "AC" seguido de 32 caracteres hexadecimales.' });
    }
    if (!token || token.length < 20) {
      return reply.code(400).send({ ok: false, error: 'Auth Token inválido o muy corto.' });
    }

    // Verificar contra la API de Twilio
    try {
      const twilio = require('twilio');
      const client = twilio(sid, token);
      const account = await client.api.accounts(sid).fetch();

      return {
        ok:          true,
        accountName: account.friendlyName,
        status:      account.status,
        phone:       phone || null,
        message:     `Conexión exitosa con "${account.friendlyName}" (${account.status})`,
      };
    } catch (err) {
      const msg = err.message || 'Error desconocido';
      // Simplificar errores comunes de Twilio
      const clean = msg.includes('authenticate') || msg.includes('20003')
        ? 'Credenciales incorrectas. Verifica Account SID y Auth Token en la consola de Twilio.'
        : msg.includes('20404')
        ? 'Account SID no encontrado.'
        : msg;
      return reply.code(401).send({ ok: false, error: clean });
    }
  });

  // PATCH /api/v1/tenants/settings — merge parcial de settings JSONB
  app.patch('/settings', { onRequest: [app.requireTenant] }, async (req, reply) => {
    const partial = req.body;
    if (!partial || typeof partial !== 'object') {
      return reply.code(400).send({ error: 'Payload inválido' });
    }

    // El GIRO/industria lo define SOLO el superadmin (gobierna todo el vertical:
    // dashboard, sidebar, tools del agente). El admin del tenant NO puede
    // cambiarlo desde aquí. Como `||` reemplaza businessProfile completo,
    // re-inyectamos SIEMPRE el valor actual para preservarlo.
    if (partial.businessProfile && typeof partial.businessProfile === 'object') {
      const cur = (await app.db.query('SELECT settings FROM tenants WHERE id=$1', [req.tenant.id])).rows[0]?.settings || {};
      const lockedIndustry = cur.businessProfile?.industry ?? cur.industry ?? '';
      if (lockedIndustry) partial.businessProfile.industry = lockedIndustry;
      else delete partial.businessProfile.industry;
    }

    // Sucursal / zona de entrega: si vienen coordenadas del PIN del mapa, son la
    // fuente de verdad (confiar en ellas). Si no, intentar geocodificar el texto
    // (menos preciso; nombres de plazas locales suelen fallar → de ahí el mapa).
    if (partial.delivery && typeof partial.delivery === 'object') {
      const d = partial.delivery;
      const lat = Number(d.originLat), lng = Number(d.originLng);
      const hasCoords = Number.isFinite(lat) && Number.isFinite(lng) && (lat !== 0 || lng !== 0);
      if (hasCoords) {
        d.originLat = lat; d.originLng = lng;
        delete d.geocodeError;
      } else if (d.originAddress) {
        try {
          const { geocodeAddress } = require('../../services/geocode');
          const geo = await geocodeAddress(d.originAddress);
          if (geo) {
            d.originLat = geo.lat; d.originLng = geo.lng;
            d.geocodedAt = new Date().toISOString();
            delete d.geocodeError;
          } else {
            d.geocodeError = true;
          }
        } catch (e) {
          req.log?.warn?.({ e: e.message }, '[settings] geocode delivery falló');
        }
      }
    }

    const result = await app.db.query(
      `UPDATE tenants
       SET settings = settings || $1::jsonb, updated_at = NOW()
       WHERE id = $2
       RETURNING id, settings`,
      [JSON.stringify(partial), req.tenant.id]
    );

    await app.db.query(
      `INSERT INTO audit_log (tenant_id, user_id, action, resource_type, metadata)
       VALUES ($1, $2, 'settings_updated', 'tenant', $3)`,
      [req.tenant.id, req.userId, JSON.stringify({ keys: Object.keys(partial) })]
    ).catch(() => {}); // no crítico

    return result.rows[0];
  });
}

module.exports = tenantsRoutes;
