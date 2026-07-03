'use strict';

const { normalizePhoneMx } = require('./phone-utils');

/**
 * Resuelve (ENCUENTRA o CREA) el contacto/cliente del tenant por teléfono.
 *
 * `leads` es la entidad canónica de contacto/cliente del negocio (find-or-create
 * por teléfono). Este servicio CENTRALIZA esa lógica, que antes estaba duplicada
 * en tools/executor.js (saveLead y scheduleAppointment).
 *
 * @param {import('pg').Pool|object} db
 * @param {string} tenantId
 * @param {{phone?:string,name?:string,email?:string,conversationId?:string,sourceChannel?:string,agentId?:string}} data
 * @returns {Promise<{id:?string, created:boolean, phone:string}>}
 */
async function resolveContact(db, tenantId, {
  phone, name, email, conversationId = null, sourceChannel = 'unknown', agentId = null,
} = {}) {
  const normPhone = normalizePhoneMx(phone || '');
  let id = null, created = false;

  // Sin teléfono ni nombre/email no hay nada que resolver.
  if (!normPhone && !name && !email) return { id: null, created: false, phone: '' };

  // 1) Buscar por teléfono (clave de identidad) y enriquecer faltantes.
  if (normPhone) {
    const found = await db.query(
      'SELECT id, name, email FROM leads WHERE tenant_id = $1 AND phone = $2 LIMIT 1',
      [tenantId, normPhone]
    );
    if (found.rows[0]) {
      const lead = found.rows[0];
      id = lead.id;
      const sets = []; const vals = []; let i = 1;
      if (name  && !lead.name)  { sets.push(`name = $${i++}`);  vals.push(name); }
      if (email && !lead.email) { sets.push(`email = $${i++}`); vals.push(email); }
      if (sets.length) {
        vals.push(lead.id);
        await db.query(`UPDATE leads SET ${sets.join(', ')}, updated_at = now() WHERE id = $${i}`, vals).catch(() => {});
      }
    }
  }

  // 2) Crear si no existe (con manejo de carrera por el índice único).
  if (!id) {
    try {
      const ins = await db.query(
        `INSERT INTO leads (tenant_id, conversation_id, name, phone, email, status, source_channel, source_agent_id)
         VALUES ($1, $2, $3, $4, $5, 'new', $6, $7) RETURNING id`,
        [tenantId, conversationId, name || 'Cliente', normPhone || null, email || null, sourceChannel, agentId]
      );
      id = ins.rows[0].id; created = true;
    } catch (e) {
      if (normPhone) {
        const r = await db.query(
          'SELECT id FROM leads WHERE tenant_id = $1 AND phone = $2 LIMIT 1', [tenantId, normPhone]);
        if (r.rows[0]) id = r.rows[0].id;
      }
      if (!id) throw e;
    }
  }

  // 3) Enlazar la conversación al contacto → historial unificado del cliente.
  //    Centralizado aquí cubre todos los canales (voz/WhatsApp/web) sin duplicar.
  if (id && conversationId) {
    await db.query(
      'UPDATE conversations SET lead_id = $1 WHERE id = $2 AND tenant_id = $3 AND lead_id IS NULL',
      [id, conversationId, tenantId]
    ).catch(() => {});
  }

  return { id, created, phone: normPhone };
}

/**
 * Contexto de un cliente RECURRENTE por teléfono, para que el agente lo
 * reconozca y personalice (saludo por nombre, preferencias, alergias, lo de
 * siempre). Devuelve null si es un contacto nuevo/desconocido.
 */
async function getCustomerContext(db, tenantId, phone) {
  const norm = normalizePhoneMx(phone || '');
  if (!norm) return null;
  const r = await db.query(
    'SELECT id, name, custom_data, no_show_count FROM leads WHERE tenant_id = $1 AND phone = $2 LIMIT 1',
    [tenantId, norm]);
  const lead = r.rows[0];
  if (!lead) return null;

  const [appt, ord] = await Promise.all([
    db.query(`SELECT a.scheduled_at, st.name AS service
              FROM appointments a LEFT JOIN service_types st ON st.id = a.service_type_id
              WHERE a.lead_id = $1 AND a.tenant_id = $2 AND a.status IN ('completed','confirmed')
              ORDER BY a.scheduled_at DESC LIMIT 1`, [lead.id, tenantId]),
    db.query('SELECT COUNT(*)::int AS n FROM orders WHERE lead_id = $1 AND tenant_id = $2', [lead.id, tenantId]),
  ]);
  const hasName  = lead.name && lead.name !== 'Cliente';
  const lastAppt = appt.rows[0];
  const orders   = ord.rows[0].n;
  if (!hasName && !lastAppt && orders === 0) return null;   // sin historia real → no "recurrente"

  const cd = lead.custom_data || {};
  const noShows = Number(lead.no_show_count) || 0;
  const NOSHOW_THRESHOLD = parseInt(process.env.NOSHOW_DEPOSIT_THRESHOLD) || 2;
  return {
    id:          lead.id,
    name:        hasName ? lead.name : null,
    firstName:   hasName ? String(lead.name).trim().split(/\s+/)[0] : null,
    lastService: lastAppt?.service || null,
    orders,
    allergies:   cd.allergies   || null,
    preferences: cd.preferences || null,
    noShows,
    requiresDepositByPolicy: noShows >= NOSHOW_THRESHOLD,   // reincidente
  };
}

/** Convierte el contexto de cliente en un bloque para el system prompt. */
function customerContextPrompt(ctx) {
  if (!ctx) return '';
  const l = ['CLIENTE CONOCIDO (ya ha interactuado antes — trátalo con familiaridad):'];
  if (ctx.name)        l.push(`- Se llama ${ctx.name}. Salúdalo por su nombre con calidez; NO le preguntes su nombre.`);
  if (ctx.lastService) l.push(`- Su servicio/pedido más reciente fue: ${ctx.lastService}. Puedes ofrecerle "lo de siempre".`);
  if (ctx.allergies)   l.push(`- ⚠ Alergias/cuidados: ${ctx.allergies}. Tenlo SIEMPRE presente.`);
  if (ctx.preferences) l.push(`- Preferencias: ${ctx.preferences}.`);
  if (ctx.requiresDepositByPolicy)
    l.push(`- ⚠ POLÍTICA DE NO-SHOW: este cliente ha faltado ${ctx.noShows} vez/veces sin avisar. Para agendar, infórmale con tacto que por política se requiere un ANTICIPO para apartar, y usa send_deposit_link ANTES de dar la cita por confirmada.`);
  l.push('Personaliza con naturalidad; NO recites todos sus datos de golpe.');
  return l.join('\n');
}

module.exports = { resolveContact, getCustomerContext, customerContextPrompt };
