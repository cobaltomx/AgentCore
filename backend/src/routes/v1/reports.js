'use strict';

/**
 * Reports API — métricas por tenant
 * GET /api/v1/reports/stats      — KPIs generales
 * GET /api/v1/reports/timeline   — conversaciones por día (últimos N días)
 * GET /api/v1/reports/agents     — desempeño por agente
 * GET /api/v1/reports/leads      — leads por estado y por día
 * GET /api/v1/reports/user/:id   — actividad de un usuario específico
 */
async function reportsRoutes(app) {

  // ── KPIs generales ───────────────────────────────────────────
  app.get('/stats', { onRequest: [app.requireTenant] }, async (req) => {
    const tid   = req.tenant.id;
    const days  = parseInt(req.query.days || '30');

    const r = await app.db.query(`
      SELECT
        -- Conversaciones
        (SELECT COUNT(*)  FROM conversations
         WHERE tenant_id=$1 AND created_at > NOW()-($2||' days')::interval)::int   AS convs_total,
        (SELECT COUNT(*)  FROM conversations
         WHERE tenant_id=$1 AND channel='voice'
           AND created_at > NOW()-($2||' days')::interval)::int                    AS convs_voice,
        (SELECT COUNT(*)  FROM conversations
         WHERE tenant_id=$1 AND channel='whatsapp'
           AND created_at > NOW()-($2||' days')::interval)::int                    AS convs_whatsapp,
        (SELECT COALESCE(ROUND(AVG(duration_secs)/60.0,1),0)
         FROM conversations
         WHERE tenant_id=$1 AND duration_secs IS NOT NULL
           AND created_at > NOW()-($2||' days')::interval)                         AS avg_duration_mins,

        -- Leads
        (SELECT COUNT(*) FROM leads
         WHERE tenant_id=$1 AND created_at > NOW()-($2||' days')::interval)::int   AS leads_total,
        (SELECT COUNT(*) FROM leads
         WHERE tenant_id=$1 AND status='converted'
           AND created_at > NOW()-($2||' days')::interval)::int                    AS leads_converted,
        (SELECT COUNT(*) FROM leads
         WHERE tenant_id=$1 AND status='new'
           AND created_at > NOW()-($2||' days')::interval)::int                    AS leads_new,
        (SELECT COUNT(*) FROM leads
         WHERE tenant_id=$1 AND status='qualified'
           AND created_at > NOW()-($2||' days')::interval)::int                    AS leads_qualified,

        -- Citas
        (SELECT COUNT(*) FROM appointments
         WHERE tenant_id=$1 AND created_at > NOW()-($2||' days')::interval)::int   AS appts_total,
        (SELECT COUNT(*) FROM appointments
         WHERE tenant_id=$1 AND status='confirmed'
           AND created_at > NOW()-($2||' days')::interval)::int                    AS appts_confirmed,
        (SELECT COUNT(*) FROM appointments
         WHERE tenant_id=$1 AND status='cancelled'
           AND created_at > NOW()-($2||' days')::interval)::int                    AS appts_cancelled,

        -- Uso de minutos
        (SELECT minutes_used_mo FROM tenants WHERE id=$1)::int                     AS minutes_used,
        (SELECT max_minutes_mo  FROM tenants WHERE id=$1)::int                     AS minutes_max,

        -- Usuarios activos (con al menos 1 conversación en el período)
        (SELECT COUNT(DISTINCT assigned_to) FROM conversations
         WHERE tenant_id=$1 AND assigned_to IS NOT NULL
           AND created_at > NOW()-($2||' days')::interval)::int                    AS active_users
    `, [tid, days]);

    const d = r.rows[0];

    // Tasa de conversión
    d.conversion_rate = d.leads_total > 0
      ? Math.round(d.leads_converted / d.leads_total * 100)
      : 0;

    // Tasa de agendamiento (citas / conversaciones)
    d.scheduling_rate = d.convs_total > 0
      ? Math.round(d.appts_total / d.convs_total * 100)
      : 0;

    return d;
  });

  // ── Reporte de Valor (ROI) ───────────────────────────────────
  // Traduce la actividad del bot a dinero y tiempo ahorrado.
  // Config por tenant en settings.value: { avgTicket, valuePerLead, staffHourlyCost, currency }
  app.get('/value', { onRequest: [app.requireTenant] }, async (req) => {
    const tid  = req.tenant.id;
    const days = Math.max(1, Math.min(365, parseInt(req.query.days || '30')));

    // Config de negocio (horario + valores monetarios) con defaults sensatos
    const settings   = req.tenant.settings || {};
    const sched      = settings.scheduling || {};
    const tz         = sched.timezone   || 'America/Mexico_City';
    const startHour  = Number.isInteger(sched.startHour) ? sched.startHour : 9;
    const endHour    = Number.isInteger(sched.endHour)   ? sched.endHour   : 18;
    const workDays   = Array.isArray(sched.workDays) && sched.workDays.length
      ? sched.workDays : [1, 2, 3, 4, 5];   // L-V (DOW Postgres: 0=Dom..6=Sáb)

    const val        = settings.value || {};
    const avgTicket  = Number(val.avgTicket)       >= 0 ? Number(val.avgTicket)       : 800;
    const valPerLead = Number(val.valuePerLead)    >= 0 ? Number(val.valuePerLead)    : 150;
    const staffCost  = Number(val.staffHourlyCost) >= 0 ? Number(val.staffHourlyCost) : 80;
    const currency   = val.currency || 'MXN';

    // Una query agrega el período actual y el anterior (para comparativa)
    const r = await app.db.query(`
      WITH params AS (
        SELECT $2::int AS days, $3::int AS start_h, $4::int AS end_h, $5::int[] AS work_days
      ),
      conv AS (
        SELECT
          c.created_at,
          c.duration_secs,
          (c.created_at AT TIME ZONE $6) AS local_ts,
          CASE WHEN c.created_at > NOW() - ($2||' days')::interval THEN 'cur'
               WHEN c.created_at > NOW() - (($2::int*2)||' days')::interval THEN 'prev'
          END AS bucket
        FROM conversations c
        WHERE c.tenant_id = $1
          AND c.created_at > NOW() - (($2::int*2)||' days')::interval
      ),
      conv_flagged AS (
        SELECT
          bucket,
          duration_secs,
          (EXTRACT(DOW  FROM local_ts)::int  NOT IN (SELECT unnest(work_days) FROM params)
           OR EXTRACT(HOUR FROM local_ts)::int <  (SELECT start_h FROM params)
           OR EXTRACT(HOUR FROM local_ts)::int >= (SELECT end_h   FROM params)) AS after_hours
        FROM conv WHERE bucket IS NOT NULL
      )
      SELECT
        -- Conversaciones
        COUNT(*) FILTER (WHERE bucket='cur')::int                              AS convs_cur,
        COUNT(*) FILTER (WHERE bucket='prev')::int                             AS convs_prev,
        COUNT(*) FILTER (WHERE bucket='cur' AND after_hours)::int              AS after_hours_cur,
        COUNT(*) FILTER (WHERE bucket='prev' AND after_hours)::int             AS after_hours_prev,
        COALESCE(SUM(duration_secs) FILTER (WHERE bucket='cur'),0)::int        AS secs_cur,
        COALESCE(SUM(duration_secs) FILTER (WHERE bucket='prev'),0)::int       AS secs_prev
      FROM conv_flagged
    `, [tid, days, startHour, endHour, workDays, tz]);

    const c = r.rows[0];

    // Leads y citas por período (cur vs prev) en una query
    const r2 = await app.db.query(`
      SELECT
        (SELECT COUNT(*) FROM leads WHERE tenant_id=$1
           AND created_at > NOW()-($2||' days')::interval)::int                AS leads_cur,
        (SELECT COUNT(*) FROM leads WHERE tenant_id=$1
           AND created_at > NOW()-(($2::int*2)||' days')::interval
           AND created_at <= NOW()-($2||' days')::interval)::int               AS leads_prev,
        (SELECT COUNT(*) FROM appointments WHERE tenant_id=$1
           AND created_at > NOW()-($2||' days')::interval)::int                AS appts_cur,
        (SELECT COUNT(*) FROM appointments WHERE tenant_id=$1
           AND created_at > NOW()-(($2::int*2)||' days')::interval
           AND created_at <= NOW()-($2||' days')::interval)::int               AS appts_prev,
        -- Confirmaciones automáticas (reducción de no-shows)
        (SELECT COUNT(*) FROM appointments WHERE tenant_id=$1
           AND confirmation_status='confirmed'
           AND confirmed_at > NOW()-($2||' days')::interval)::int              AS confirmed_cur,
        (SELECT COUNT(*) FROM appointments WHERE tenant_id=$1
           AND confirmation_status='cancelled'
           AND cancelled_at > NOW()-($2||' days')::interval)::int              AS cancelled_early_cur
    `, [tid, days]);
    const a = r2.rows[0];

    // ── Cálculo de valor monetario y tiempo ────────────────────
    const hoursSavedCur  = +(c.secs_cur  / 3600).toFixed(1);
    const hoursSavedPrev = +(c.secs_prev / 3600).toFixed(1);

    const valueAppts     = a.appts_cur  * avgTicket;     // citas agendadas
    const valueLeads     = a.leads_cur  * valPerLead;    // pipeline capturado
    const valueAfterHrs  = c.after_hours_cur * valPerLead; // contactos rescatados fuera de horario
    const valueTimeSaved = Math.round(hoursSavedCur * staffCost);
    // No-shows evitados: cada cancelación anticipada libera un horario re-ocupable
    const noShowRecovery = a.cancelled_early_cur * avgTicket;
    const valueTotal     = valueAppts + valueLeads + valueTimeSaved + noShowRecovery;

    // Helper: variación % vs período anterior
    const pct = (cur, prev) => prev > 0 ? Math.round((cur - prev) / prev * 100)
                                        : (cur > 0 ? 100 : 0);

    return {
      period_days: days,
      currency,
      config: { avgTicket, valPerLead, staffHourlyCost: staffCost },

      metrics: {
        convs:         { value: c.convs_cur,        change: pct(c.convs_cur, c.convs_prev) },
        after_hours:   { value: c.after_hours_cur,  change: pct(c.after_hours_cur, c.after_hours_prev) },
        leads:         { value: a.leads_cur,        change: pct(a.leads_cur, a.leads_prev) },
        appointments:  { value: a.appts_cur,        change: pct(a.appts_cur, a.appts_prev) },
        hours_saved:   { value: hoursSavedCur,      change: pct(hoursSavedCur, hoursSavedPrev) },
        confirmations: { value: a.confirmed_cur,    change: 0 },
        early_cancels: { value: a.cancelled_early_cur, change: 0 },
      },

      value: {
        appointments:   valueAppts,
        leads:          valueLeads,
        after_hours:    valueAfterHrs,
        time_saved:     valueTimeSaved,
        no_show_recovery: noShowRecovery,
        total:          valueTotal,
      },
    };
  });

  // Planes con acceso a "Voz del cliente" (coincide con frontend)
  const INSIGHTS_PLANS = ['growth', 'business', 'enterprise'];

  // ── Voz del cliente — inteligencia de conversaciones ─────────
  app.get('/insights', { onRequest: [app.requireTenant] }, async (req, reply) => {
    if (!INSIGHTS_PLANS.includes(req.tenant.plan)) {
      return reply.code(403).send({ error: 'upgrade_required', feature: 'insights' });
    }
    const tid  = req.tenant.id;
    const days = Math.max(1, Math.min(365, parseInt(req.query.days || '30')));

    const [counts, sentiment, gaps, topics, objections] = await Promise.all([
      // Cobertura del análisis
      app.db.query(`
        SELECT COUNT(*)::int AS total,
               COUNT(analyzed_at)::int AS analyzed
        FROM conversations
        WHERE tenant_id=$1 AND created_at > NOW()-($2||' days')::interval`,
        [tid, days]),
      // Distribución de sentimiento
      app.db.query(`
        SELECT sentiment, COUNT(*)::int AS total
        FROM conversations
        WHERE tenant_id=$1 AND analyzed_at IS NOT NULL
          AND created_at > NOW()-($2||' days')::interval
        GROUP BY sentiment`,
        [tid, days]),
      // Gaps de conocimiento — preguntas sin responder (lo más accionable)
      app.db.query(`
        SELECT analysis->>'unanswered_question' AS question,
               created_at
        FROM conversations
        WHERE tenant_id=$1
          AND (analysis->>'kb_gap')='true'
          AND analysis->>'unanswered_question' IS NOT NULL
          AND created_at > NOW()-($2||' days')::interval
        ORDER BY created_at DESC
        LIMIT 20`,
        [tid, days]),
      // Temas más frecuentes (desanidando el array JSONB)
      app.db.query(`
        SELECT lower(topic) AS topic, COUNT(*)::int AS total
        FROM conversations c,
             jsonb_array_elements_text(c.analysis->'topics') AS topic
        WHERE c.tenant_id=$1 AND c.analyzed_at IS NOT NULL
          AND c.created_at > NOW()-($2||' days')::interval
        GROUP BY lower(topic)
        ORDER BY total DESC
        LIMIT 12`,
        [tid, days]),
      // Objeciones más frecuentes
      app.db.query(`
        SELECT lower(obj) AS objection, COUNT(*)::int AS total
        FROM conversations c,
             jsonb_array_elements_text(c.analysis->'objections') AS obj
        WHERE c.tenant_id=$1 AND c.analyzed_at IS NOT NULL
          AND c.created_at > NOW()-($2||' days')::interval
        GROUP BY lower(obj)
        ORDER BY total DESC
        LIMIT 10`,
        [tid, days]),
    ]);

    const cnt = counts.rows[0] || { total: 0, analyzed: 0 };
    return {
      period_days: days,
      coverage:    { total: cnt.total, analyzed: cnt.analyzed },
      sentiment:   sentiment.rows,
      kb_gaps:     gaps.rows,
      topics:      topics.rows,
      objections:  objections.rows,
    };
  });

  // ── Backfill: analizar conversaciones existentes sin análisis ─
  app.post('/insights/analyze', { onRequest: [app.requireAdmin] }, async (req, reply) => {
    if (!INSIGHTS_PLANS.includes(req.tenant.plan)) {
      return reply.code(403).send({ error: 'upgrade_required', feature: 'insights' });
    }
    const tid   = req.tenant.id;
    const limit = Math.max(1, Math.min(50, parseInt(req.body?.limit) || 10));

    const pending = await app.db.query(
      `SELECT id FROM conversations
       WHERE tenant_id=$1 AND analyzed_at IS NULL AND status='completed'
       ORDER BY created_at DESC LIMIT $2`,
      [tid, limit]
    );

    const { analyzeConversation } = require('../../services/conversation-analyzer');
    let analyzed = 0;
    for (const row of pending.rows) {
      try {
        const r = await analyzeConversation(app.db, row.id);
        if (r) analyzed++;
      } catch (e) { app.log.warn('[Insights] analyze error: ' + e.message); }
    }
    return { requested: pending.rows.length, analyzed };
  });

  // ── Timeline — conversaciones por día ────────────────────────
  app.get('/timeline', { onRequest: [app.requireTenant] }, async (req) => {
    const tid  = req.tenant.id;
    const days = parseInt(req.query.days || '30');

    const r = await app.db.query(`
      SELECT
        DATE(created_at AT TIME ZONE 'America/Mexico_City')::text  AS day,
        channel,
        COUNT(*)::int AS total
      FROM conversations
      WHERE tenant_id = $1
        AND created_at > NOW() - ($2||' days')::interval
      GROUP BY day, channel
      ORDER BY day ASC
    `, [tid, days]);

    // Pivotar: { day, voice, whatsapp, webchat, total }
    const map = {};
    for (const row of r.rows) {
      const key = row.day;
      if (!map[key]) map[key] = { day: key, voice: 0, whatsapp: 0, webchat: 0, total: 0 };
      map[key][row.channel] = (map[key][row.channel] || 0) + row.total;
      map[key].total += row.total;
    }

    return Object.values(map);
  });

  // ── Desempeño por agente ──────────────────────────────────────
  app.get('/agents', { onRequest: [app.requireTenant] }, async (req) => {
    const tid  = req.tenant.id;
    const days = parseInt(req.query.days || '30');

    const r = await app.db.query(`
      SELECT
        a.id, a.name, a.channel, a.is_active,
        (SELECT COUNT(*) FROM conversations c
          WHERE c.agent_id = a.id AND c.tenant_id = $1
            AND c.created_at > NOW() - ($2||' days')::interval
        )::int                                            AS conv_count,
        (SELECT COALESCE(ROUND(AVG(c2.duration_secs)/60.0,1),0) FROM conversations c2
          WHERE c2.agent_id = a.id AND c2.tenant_id = $1
            AND c2.created_at > NOW() - ($2||' days')::interval
        )                                                 AS avg_mins,
        (SELECT COUNT(*) FROM leads l
          WHERE l.source_agent_id = a.id AND l.tenant_id = $1
            AND l.created_at > NOW() - ($2||' days')::interval
        )::int                                            AS leads_generated,
        (SELECT COUNT(*) FROM appointments ap
          JOIN conversations c3 ON ap.conversation_id = c3.id
          WHERE c3.agent_id = a.id AND ap.tenant_id = $1
            AND ap.created_at > NOW() - ($2||' days')::interval
        )::int                                            AS appts_scheduled
      FROM agents a
      WHERE a.tenant_id = $1
      ORDER BY conv_count DESC
    `, [tid, days]);

    return r.rows;
  });

  // ── Leads por estado y por día ────────────────────────────────
  app.get('/leads', { onRequest: [app.requireTenant] }, async (req) => {
    const tid  = req.tenant.id;
    const days = parseInt(req.query.days || '30');

    const [byStatus, byDay] = await Promise.all([
      app.db.query(`
        SELECT status, COUNT(*)::int AS total
        FROM leads WHERE tenant_id=$1
          AND created_at > NOW()-($2||' days')::interval
        GROUP BY status ORDER BY total DESC
      `, [tid, days]),
      app.db.query(`
        SELECT DATE(created_at AT TIME ZONE 'America/Mexico_City')::text AS day,
               COUNT(*)::int AS total
        FROM leads WHERE tenant_id=$1
          AND created_at > NOW()-($2||' days')::interval
        GROUP BY day ORDER BY day ASC
      `, [tid, days]),
    ]);

    return { by_status: byStatus.rows, by_day: byDay.rows };
  });

  // ── Actividad por usuario dentro del tenant ───────────────────
  app.get('/users', { onRequest: [app.requireAdmin] }, async (req) => {
    const tid  = req.tenant.id;
    const days = parseInt(req.query.days || '30');

    const r = await app.db.query(`
      SELECT
        u.id, u.name, u.email, u.role,
        COUNT(DISTINCT l.id)::int  AS leads_assigned,
        COUNT(DISTINCT c.id)::int  AS convs_assigned
      FROM users u
      LEFT JOIN leads         l ON l.assigned_to = u.id
                                AND l.created_at > NOW()-($2||' days')::interval
      LEFT JOIN conversations c ON c.assigned_to = u.id
                                AND c.created_at > NOW()-($2||' days')::interval
      WHERE u.tenant_id = $1
      GROUP BY u.id
      ORDER BY u.name
    `, [tid, days]);

    return r.rows;
  });
}

module.exports = reportsRoutes;
