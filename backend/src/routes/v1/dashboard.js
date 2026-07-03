'use strict';

/**
 * Dashboard API — datos consolidados del "cockpit" del tenant.
 * Una sola llamada alimenta: bandeja de atención, agenda de hoy, KPIs accionables.
 */
async function dashboardRoutes(app) {

  // GET /api/v1/dashboard/overview
  app.get('/overview', { onRequest: [app.requireTenant] }, async (req) => {
    const tid = req.tenant.id;

    const [t, unconfirmed, newLeads, todayAppts, kpi, handoffs] = await Promise.all([
      app.db.query(`SELECT minutes_used_mo, max_minutes_mo, is_ready,
          COALESCE(NULLIF(settings->'businessProfile'->>'industry',''), NULLIF(settings->>'industry',''), '') AS industry
        FROM tenants WHERE id = $1`, [tid]),
      // Citas de HOY/MAÑANA aún no confirmadas → la acción más valiosa
      app.db.query(`
        SELECT id, scheduled_at, patient_name, patient_phone, confirmation_status
        FROM appointments
        WHERE tenant_id = $1
          AND status NOT IN ('cancelled','completed','no_show')
          AND (confirmation_status IS DISTINCT FROM 'confirmed')
          AND scheduled_at >= date_trunc('day', NOW())
          AND scheduled_at <  date_trunc('day', NOW()) + INTERVAL '2 days'
        ORDER BY scheduled_at ASC LIMIT 12`, [tid]),
      // Leads nuevos sin atender
      app.db.query(`
        SELECT id, name, phone, created_at FROM leads
        WHERE tenant_id = $1 AND status = 'new'
        ORDER BY created_at DESC LIMIT 12`, [tid]),
      // Agenda de hoy (todas)
      app.db.query(`
        SELECT id, scheduled_at, patient_name, patient_phone, confirmation_status, status
        FROM appointments
        WHERE tenant_id = $1 AND status <> 'cancelled'
          AND scheduled_at >= date_trunc('day', NOW())
          AND scheduled_at <  date_trunc('day', NOW()) + INTERVAL '1 day'
        ORDER BY scheduled_at ASC`, [tid]),
      // KPIs accionables (últimos 30 días)
      app.db.query(`
        SELECT
          (SELECT COUNT(*) FROM appointments WHERE tenant_id=$1 AND status<>'cancelled' AND created_at > NOW()-INTERVAL '30 days')::int AS appts,
          (SELECT COUNT(*) FROM appointments WHERE tenant_id=$1 AND confirmation_status='confirmed' AND created_at > NOW()-INTERVAL '30 days')::int AS confirmed,
          (SELECT COUNT(*) FROM appointments WHERE tenant_id=$1 AND status='no_show'  AND created_at > NOW()-INTERVAL '30 days')::int AS no_show,
          (SELECT COUNT(*) FROM appointments WHERE tenant_id=$1 AND status='completed' AND created_at > NOW()-INTERVAL '30 days')::int AS completed,
          (SELECT COUNT(*) FROM leads         WHERE tenant_id=$1 AND created_at > NOW()-INTERVAL '30 days')::int AS leads,
          (SELECT COUNT(*) FROM conversations WHERE tenant_id=$1 AND created_at > NOW()-INTERVAL '30 days')::int AS convs
      `, [tid]),
      // Handoffs pendientes: el bot pidió un humano y nadie lo ha resuelto → urgente
      app.db.query(`
        SELECT id, contact_name, contact_phone, channel, handoff_reason, handoff_at
        FROM conversations
        WHERE tenant_id = $1 AND needs_human IS TRUE AND handoff_resolved_at IS NULL
        ORDER BY handoff_at DESC NULLS LAST LIMIT 10`, [tid]),
    ]);

    const tt = t.rows[0] || {};
    const k  = kpi.rows[0] || {};
    const minUsed = Number(tt.minutes_used_mo) || 0;
    const minMax  = Number(tt.max_minutes_mo)  || 0;
    const minPct  = minMax > 0 ? Math.round(minUsed / minMax * 100) : 0;

    const alerts = [];
    if (minPct >= 90)  alerts.push({ type: 'minutes',   level: 'danger',  message: `Has usado el ${minPct}% de tus minutos del mes` });
    if (!tt.is_ready)  alerts.push({ type: 'not_ready', level: 'warning', message: 'Tu bot aún no está aprobado en el Simulador', link: '/pages/simulator.php' });

    const confirmation_rate = k.appts > 0 ? Math.round(k.confirmed / k.appts * 100) : 0;
    const no_show_rate      = (k.no_show + k.completed) > 0 ? Math.round(k.no_show / (k.no_show + k.completed) * 100) : 0;
    const lead_to_appt      = k.leads > 0 ? Math.min(100, Math.round(k.appts / k.leads * 100)) : 0;

    // ── Banda vertical (industria-específica) ──────────────────────
    // El dashboard sigue siendo único; aquí solo añadimos un bloque extra
    // según la industria del tenant. Si es genérica, `vertical` queda null.
    const VERTICAL_BY_INDUSTRY = {
      dental: 'salud', consultorio: 'salud', clinica: 'salud', salud: 'salud', medico: 'salud',
      inmobiliaria: 'inmobiliaria',
      ecommerce: 'comercio', comercio: 'comercio', retail: 'comercio', tienda: 'comercio',
      restaurante: 'restaurante', comida: 'restaurante',
    };
    const vtype = VERTICAL_BY_INDUSTRY[String(tt.industry || '').toLowerCase()] || null;
    let vertical = await computeVertical(vtype, tid, todayAppts.rows, k);

    return {
      generated_at: new Date().toISOString(),
      is_ready: !!tt.is_ready,
      minutes: { used: minUsed, max: minMax, pct: minPct },
      attention: {
        total:      handoffs.rows.length + unconfirmed.rows.length + newLeads.rows.length + alerts.length,
        handoffs:    handoffs.rows,
        unconfirmed: unconfirmed.rows,
        new_leads:   newLeads.rows,
        alerts,
      },
      today: todayAppts.rows,
      kpis: {
        confirmation_rate, no_show_rate, lead_to_appt,
        appts_30d: k.appts, leads_30d: k.leads, convs_30d: k.convs,
        completed_30d: k.completed,
      },
      vertical,
    };
  });

  // Calcula la banda vertical-específica. Devuelve null para industrias
  // genéricas (el dashboard base ya las cubre). Cada vertical hace SOLO sus
  // consultas extra (cero costo para los demás).
  async function computeVertical(type, tid, todayRows, k) {
    if (type === 'salud') {
      // Por especialista: cuántas citas tiene HOY → un especialista con 0 es
      // "sillón vacío" (capacidad ociosa, el dolor #1 de una clínica).
      const docs = await app.db.query(`
        SELECT d.id, d.name, d.room,
          (SELECT COUNT(*) FROM appointments a
            WHERE a.doctor_id = d.id AND a.tenant_id = $1 AND a.status <> 'cancelled'
              AND a.scheduled_at >= date_trunc('day', NOW())
              AND a.scheduled_at <  date_trunc('day', NOW()) + INTERVAL '1 day')::int AS today_count
        FROM doctors d WHERE d.tenant_id = $1 AND d.is_active = true
        ORDER BY d.sort_order ASC, d.name ASC`, [tid]);
      const total     = todayRows.length;
      const confirmed = todayRows.filter(a => a.confirmation_status === 'confirmed').length;
      return {
        type: 'salud',
        salud: {
          no_shows_30d:   k.no_show,
          doctors_active: docs.rows.length,
          idle_today:     docs.rows.filter(d => d.today_count === 0).length,
          today: { total, confirmed, pending: total - confirmed },
          by_doctor: docs.rows,
        },
      };
    }

    if (type === 'inmobiliaria') {
      // Las propiedades viven en `products`; las visitas son `appointments`.
      const [agg, visits] = await Promise.all([
        app.db.query(`SELECT
            (SELECT COUNT(*) FROM products WHERE tenant_id=$1 AND is_active)::int AS props_active,
            (SELECT COUNT(*) FROM products WHERE tenant_id=$1)::int AS props_total,
            (SELECT COUNT(*) FROM appointments WHERE tenant_id=$1 AND status<>'cancelled'
               AND scheduled_at >= date_trunc('day',NOW())
               AND scheduled_at <  date_trunc('day',NOW()) + INTERVAL '1 day')::int AS visits_today,
            (SELECT COUNT(*) FROM appointments WHERE tenant_id=$1 AND status<>'cancelled'
               AND scheduled_at >= date_trunc('day',NOW())
               AND scheduled_at <  date_trunc('day',NOW()) + INTERVAL '7 days')::int AS visits_7d,
            (SELECT COUNT(*) FROM leads WHERE tenant_id=$1 AND status='new')::int AS leads_new`, [tid]),
        app.db.query(`SELECT id, scheduled_at, patient_name, patient_phone, confirmation_status
          FROM appointments WHERE tenant_id=$1 AND status<>'cancelled'
            AND scheduled_at >= date_trunc('day',NOW())
            AND scheduled_at <  date_trunc('day',NOW()) + INTERVAL '7 days'
          ORDER BY scheduled_at ASC LIMIT 8`, [tid]),
      ]);
      const a = agg.rows[0] || {};
      return {
        type: 'inmobiliaria',
        inmobiliaria: {
          properties_active: a.props_active || 0,
          properties_total:  a.props_total  || 0,
          visits_today:      a.visits_today || 0,
          visits_7d:         a.visits_7d    || 0,
          leads_new:         a.leads_new    || 0,
          upcoming_visits:   visits.rows,
        },
      };
    }

    if (type === 'comercio') {
      const [agg, recent] = await Promise.all([
        app.db.query(`SELECT
            (SELECT COUNT(*) FROM orders WHERE tenant_id=$1 AND status<>'cancelled'
               AND created_at >= date_trunc('day',NOW()))::int AS orders_today,
            (SELECT COUNT(*) FROM orders WHERE tenant_id=$1 AND status<>'cancelled'
               AND created_at > NOW()-INTERVAL '30 days')::int AS orders_30d,
            (SELECT COALESCE(ROUND(AVG(total_cents)),0) FROM orders WHERE tenant_id=$1 AND status<>'cancelled'
               AND created_at > NOW()-INTERVAL '30 days')::int AS avg_ticket_cents,
            (SELECT COALESCE(SUM(total_cents),0) FROM orders WHERE tenant_id=$1 AND paid_at IS NOT NULL
               AND created_at > NOW()-INTERVAL '30 days')::bigint AS revenue_30d_cents,
            (SELECT COUNT(*) FROM orders WHERE tenant_id=$1 AND payment_url IS NOT NULL
               AND paid_at IS NULL AND status<>'cancelled')::int AS pending_payments,
            (SELECT COUNT(*) FROM products WHERE tenant_id=$1 AND is_active AND COALESCE(stock,0) <= 0)::int AS out_of_stock,
            (SELECT currency FROM orders WHERE tenant_id=$1 ORDER BY created_at DESC LIMIT 1) AS currency`, [tid]),
        app.db.query(`SELECT id, customer_name, total_cents, currency, status, payment_url, paid_at, created_at
          FROM orders WHERE tenant_id=$1 ORDER BY created_at DESC LIMIT 8`, [tid]),
      ]);
      const a = agg.rows[0] || {};
      return {
        type: 'comercio',
        comercio: {
          orders_today:      a.orders_today || 0,
          orders_30d:        a.orders_30d || 0,
          avg_ticket_cents:  Number(a.avg_ticket_cents) || 0,
          revenue_30d_cents: Number(a.revenue_30d_cents) || 0,
          pending_payments:  a.pending_payments || 0,
          out_of_stock:      a.out_of_stock || 0,
          currency:          a.currency || 'MXN',
          recent_orders:     recent.rows,
        },
      };
    }

    if (type === 'restaurante') {
      // Híbrido: reservaciones = appointments; pedidos a domicilio = orders;
      // menú = products. El nº de comensales (si existe) vive en notes.
      const [agg, todayRes] = await Promise.all([
        app.db.query(`SELECT
            (SELECT COUNT(*) FROM appointments WHERE tenant_id=$1 AND status<>'cancelled'
               AND scheduled_at >= date_trunc('day',NOW())
               AND scheduled_at <  date_trunc('day',NOW()) + INTERVAL '1 day')::int AS res_today,
            (SELECT COUNT(*) FROM appointments WHERE tenant_id=$1 AND status<>'cancelled'
               AND scheduled_at >= date_trunc('day',NOW())
               AND scheduled_at <  date_trunc('day',NOW()) + INTERVAL '7 days')::int AS res_7d,
            (SELECT COUNT(*) FROM orders WHERE tenant_id=$1 AND status<>'cancelled'
               AND created_at >= date_trunc('day',NOW()))::int AS orders_today,
            (SELECT COUNT(*) FROM orders WHERE tenant_id=$1 AND status<>'cancelled'
               AND created_at > NOW()-INTERVAL '30 days')::int AS orders_30d,
            (SELECT COALESCE(ROUND(AVG(total_cents)),0) FROM orders WHERE tenant_id=$1 AND status<>'cancelled'
               AND created_at > NOW()-INTERVAL '30 days')::int AS avg_ticket_cents,
            (SELECT COUNT(*) FROM products WHERE tenant_id=$1 AND is_active)::int AS menu_items,
            (SELECT currency FROM orders WHERE tenant_id=$1 ORDER BY created_at DESC LIMIT 1) AS currency`, [tid]),
        app.db.query(`SELECT id, scheduled_at, patient_name, patient_phone, notes, confirmation_status
          FROM appointments WHERE tenant_id=$1 AND status<>'cancelled'
            AND scheduled_at >= date_trunc('day',NOW())
            AND scheduled_at <  date_trunc('day',NOW()) + INTERVAL '1 day'
          ORDER BY scheduled_at ASC LIMIT 10`, [tid]),
      ]);
      const a = agg.rows[0] || {};
      return {
        type: 'restaurante',
        restaurante: {
          reservations_today: a.res_today || 0,
          reservations_7d:    a.res_7d || 0,
          orders_today:       a.orders_today || 0,
          orders_30d:         a.orders_30d || 0,
          avg_ticket_cents:   Number(a.avg_ticket_cents) || 0,
          menu_items:         a.menu_items || 0,
          currency:           a.currency || 'MXN',
          today_reservations: todayRes.rows,
        },
      };
    }
    return null;
  }
}

module.exports = dashboardRoutes;
