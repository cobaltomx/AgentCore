'use strict';

/**
 * Notificaciones en tiempo real
 * Prefix: /api/v1/notifications
 *
 * GET  /              → últimas 30 notificaciones (carga inicial)
 * GET  ?since=<id>    → solo las nuevas con id > since (polling)
 * PATCH /read         → marcar como leídas (body: { ids?: number[] } — sin ids = todas)
 * GET  /stream        → SSE: envía notificaciones nuevas + recordatorios de citas
 */
async function notificationsRoutes(app) {

  // ── GET /api/v1/notifications ──────────────────────────────────────────────
  // logLevel warn: el frontend hace polling continuo y a nivel info estas
  // requests ahogan los logs (miles de líneas/día que entierran lo importante).
  app.get('/', { onRequest: [app.requireTenant], logLevel: 'warn' }, async (req) => {
    const since = parseInt(req.query.since) || 0;

    if (since > 0) {
      // Polling incremental — devuelve solo las nuevas (ASC para mantener orden)
      const r = await app.db.query(
        `SELECT * FROM notifications
         WHERE tenant_id = $1 AND id > $2
         ORDER BY id ASC LIMIT 20`,
        [req.tenant.id, since]
      );
      return r.rows;
    }

    // Carga inicial — últimas 30 ordenadas DESC (las más recientes primero)
    const r = await app.db.query(
      `SELECT * FROM notifications
       WHERE tenant_id = $1
       ORDER BY id DESC LIMIT 30`,
      [req.tenant.id]
    );
    return r.rows;
  });

  // ── PATCH /api/v1/notifications/read ──────────────────────────────────────
  app.patch('/read', { onRequest: [app.requireTenant] }, async (req) => {
    const { ids } = req.body || {};

    if (Array.isArray(ids) && ids.length > 0) {
      await app.db.query(
        `UPDATE notifications SET is_read = true
         WHERE tenant_id = $1 AND id = ANY($2::bigint[])`,
        [req.tenant.id, ids]
      );
    } else {
      // Sin ids → marcar todas
      await app.db.query(
        `UPDATE notifications SET is_read = true
         WHERE tenant_id = $1 AND is_read = false`,
        [req.tenant.id]
      );
    }
    return { ok: true };
  });

  // ── GET /api/v1/notifications/stream — SSE ────────────────────────────────
  app.get('/stream', { onRequest: [app.requireTenant], logLevel: 'warn' }, async (req, reply) => {
    // Tomar control del socket — Fastify no cerrará la respuesta
    reply.hijack();
    const res = reply.raw;

    res.writeHead(200, {
      'Content-Type':      'text/event-stream',
      'Cache-Control':     'no-cache',
      'Connection':        'keep-alive',
      'X-Accel-Buffering': 'no',       // Deshabilitar buffer en Nginx
    });

    const tenantId = req.tenant.id;
    let lastId = parseInt(req.query.lastId) || 0;

    // Enviar evento nombrado al cliente
    const send = (event, data) => {
      try {
        res.write(`event: ${event}\ndata: ${JSON.stringify(data)}\n\n`);
      } catch { /* socket cerrado */ }
    };

    // Confirmación de conexión
    send('connected', { ok: true, ts: Date.now() });

    const poll = async () => {
      try {
        // ── 1. Nuevas notificaciones desde lastId ──
        const r = await app.db.query(
          `SELECT * FROM notifications
           WHERE tenant_id = $1 AND id > $2
           ORDER BY id ASC LIMIT 20`,
          [tenantId, lastId]
        );
        if (r.rows.length) {
          lastId = r.rows[r.rows.length - 1].id;
          for (const n of r.rows) send('notification', n);
        } else {
          // Heartbeat para mantener la conexión viva
          res.write(': ping\n\n');
        }

        // ── 2. Recordatorios de citas (próximas 60 min, sin notif previa) ──
        const appts = await app.db.query(`
          SELECT a.id, l.name AS lead_name, a.scheduled_at
          FROM appointments a
          LEFT JOIN leads l ON l.id = a.lead_id
          WHERE a.tenant_id = $1
            AND a.status = 'confirmed'
            AND a.scheduled_at BETWEEN NOW() AND NOW() + INTERVAL '60 minutes'
            AND NOT EXISTS (
              SELECT 1 FROM notifications n
              WHERE n.tenant_id = $1
                AND n.type = 'appointment_reminder'
                AND n.link LIKE '%' || a.id::text
            )
          LIMIT 5
        `, [tenantId]);

        for (const appt of appts.rows) {
          const mins  = Math.round((new Date(appt.scheduled_at) - new Date()) / 60_000);
          const title = appt.lead_name
            ? `Cita con ${appt.lead_name} en ${mins} min`
            : `Cita agendada en ${mins} min`;

          const ins = await app.db.query(
            `INSERT INTO notifications (tenant_id, type, title, body, link)
             VALUES ($1, 'appointment_reminder', $2, $3, $4)
             RETURNING *`,
            [tenantId, title, `Cita confirmada a las ${new Date(appt.scheduled_at).toLocaleTimeString('es-MX', { hour:'2-digit', minute:'2-digit' })}`,
             `/pages/appointments.php`]
          );
          if (ins.rows[0]) {
            lastId = Math.max(lastId, ins.rows[0].id);
            send('notification', ins.rows[0]);
          }
        }
      } catch (err) {
        app.log.error(err, '[SSE] poll error');
        try { res.write(': error\n\n'); } catch { /* socket cerrado */ }
      }
    };

    // Primera consulta inmediata, luego cada 15 segundos
    await poll();
    const timer = setInterval(poll, 15_000);

    // Limpiar cuando el cliente cierre la conexión
    req.raw.on('close', () => {
      clearInterval(timer);
      try { res.end(); } catch { /* ya cerrado */ }
    });
  });
}

module.exports = notificationsRoutes;
