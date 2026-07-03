'use strict';


const { logger } = require('../services/logger');
const log = logger('Executor');
/**
 * Tool Executor v2 — Fase 2
 *
 * Tools disponibles:
 * ─ Fase 1: save_lead, transfer_to_human
 * ─ Fase 2: check_availability, schedule_appointment, cancel_appointment, find_appointment
 * ─ Fase 5: search_knowledge_base (stub)
 */

const SchedulingService  = require('../services/scheduling/scheduling-service');
const { slotsToSpeech, bookingConfirmationSpeech } = require('../services/scheduling/slot-formatter');
const { createNotification } = require('../services/notifications');
const { geocodeAddress, haversineKm } = require('../services/geocode');

async function executeToolCall({ name, input, session, db }) {
  switch (name) {

    // ── Fase 1 ────────────────────────────────────────────
    case 'save_lead':
      return await saveLead({ input, session, db });

    case 'transfer_to_human':
      return await transferToHuman({ input, session, db });

    // ── Fase 2 ────────────────────────────────────────────
    case 'check_availability':
      return await checkAvailability({ input, session, db });

    case 'schedule_appointment':
      return await scheduleAppointment({ input, session, db });

    case 'cancel_appointment':
      return await cancelAppointment({ input, session, db });

    case 'find_appointment':
      return await findAppointment({ input, session, db });

    case 'join_waitlist':
      return await joinWaitlist({ input, session, db });

    // ── Fase 5: RAG ───────────────────────────────────────────
    case 'search_knowledge_base':
      return await searchKnowledgeBase({ input, session, db });

    // ── Módulo Clínica ────────────────────────────────────────
    case 'triage_service':
      return await triageService({ input, session, db });

    case 'escalate_urgency':
      return await escalateUrgency({ input, session, db });

    case 'send_deposit_link':
      return await sendDepositLink({ input, session, db });

    // ── Módulo Consultorios ───────────────────────────────────────
    case 'qualify_lead':
      return await qualifyLead({ input, session, db });

    case 'book_session_series':
      return await bookSessionSeries({ input, session, db });

    case 'send_video_link':
      return await sendVideoLink({ input, session, db });

    // ── Catálogo / comercio ───────────────────────────────────────
    case 'search_products':
      return await searchProducts({ input, session, db });

    case 'send_property_info':
      return await sendPropertyInfo({ input, session, db });

    case 'add_to_cart':
      return await addToCart({ input, session, db });

    case 'remove_from_cart':
      return await removeFromCart({ input, session, db });

    case 'view_cart':
      return await viewCart({ input, session, db });

    case 'checkout_order':
      return await checkoutOrder({ input, session, db });

    case 'check_delivery_area':
      return await checkDeliveryArea({ input, session, db });

    default:
      log.warn(`[ToolExecutor] Tool desconocida: ${name}`);
      return { success: false, message: `Herramienta "${name}" no disponible` };
  }
}

// ─── Implementaciones ─────────────────────────────────────────────────────────

async function checkAvailability({ input, session, db }) {
  try {
    const scheduling = new SchedulingService({ db });
    const daysAhead  = input.days_ahead || 7;   // ventana de búsqueda (antes 5)

    // Usar doctor específico si el triage ya lo identificó
    const doctorId      = input.doctor_id || session.collectedData?.doctorId || null;
    const durationMins  = session.collectedData?.durationMins || 30;

    // maxSlots 6 + reparto entre días (spreadAcrossDays) → ofrece variedad de
    // días y horarios en vez de 4 huecos seguidos de la misma mañana.
    const { slots, source, total } = await scheduling.getAvailableSlots(
      session.tenantId,
      { daysAhead, maxSlots: 6, doctorId, durationMins }
    );

    if (slots.length === 0) {
      return {
        success:  true,
        hasSlots: false,
        speech:   'No tenemos horarios disponibles en los próximos días. ¿Te puedo llamar cuando se libere un espacio?',
        slots:    [],
        source,
      };
    }

    // Generar speech natural para el agente
    const speech = slotsToSpeech(slots, 'America/Mexico_City', Math.min(slots.length, 3));

    // Guardar slots en sesión para el siguiente turno (el usuario elegirá uno)
    const collectedData = { availableSlots: slots, slotsSource: source };

    return {
      success:       true,
      hasSlots:      true,
      slots,
      source,
      total,
      speech,        // el LLM usa esto directamente como su respuesta
      collectedData, // se guarda en sesión
    };

  } catch (err) {
    log.error('[ToolExecutor] check_availability error:', err.message);
    return {
      success: false,
      speech:  'Tuve un problema consultando los horarios. ¿Puedes llamarnos directamente para agendar?',
      message: err.message,
    };
  }
}

// Normalización de teléfono: fuente única en services/phone-utils.
const { normalizePhoneMx, toWhatsAppMx } = require('../services/phone-utils');
// Resolución de contacto/cliente (find-or-create por teléfono) centralizada.
const { resolveContact } = require('../services/contacts');

async function scheduleAppointment({ input, session, db }) {
  try {
    // ── Guard: ya se agendó en esta conversación ───────────────
    const existingId = session.collectedData?.appointmentId;
    if (existingId) {
      const existingTime = session.collectedData?.appointmentTime;
      const displayTime  = existingTime
        ? new Intl.DateTimeFormat('es-MX', {
            timeZone: 'America/Mexico_City',
            weekday: 'long', month: 'long', day: 'numeric',
            hour: '2-digit', minute: '2-digit', hour12: true,
          }).format(new Date(existingTime))
        : 'el horario confirmado';

      log.info(`[ToolExecutor] schedule_appointment bloqueado — ya existe appointmentId ${existingId}`);
      return {
        success:       true,
        alreadyBooked: true,
        appointmentId: existingId,
        startTime:     existingTime,
        speech:        `Tu cita ya quedó confirmada para ${displayTime}. ¿Hay algo más en que pueda ayudarte?`,
        outcome:       'appointment_booked',
      };
    }

    const {
      start_time,   // ISO datetime elegido por el usuario
      name,
      phone,
      email,
      notes,
      slot_index,   // índice del slot de availableSlots (alternativo a start_time)
    } = input;

    // Resolver start_time si viene como índice
    let resolvedTime = start_time;
    if (!resolvedTime && slot_index !== undefined) {
      const slots = session.collectedData?.availableSlots || [];
      resolvedTime = slots[slot_index]?.time;
    }

    if (!resolvedTime) {
      return {
        success: false,
        speech:  'Necesito saber qué horario prefieres. ¿Cuál de las opciones te funciona?',
      };
    }

    // Resolver datos del cliente con fallbacks
    const resolvedName  = name  || session.collectedData?.leadName  || 'Cliente';
    const resolvedPhone = normalizePhoneMx(phone || session.collectedData?.leadPhone || session.contactPhone || '');
    const resolvedEmail = email || session.collectedData?.leadEmail  || '';

    // Resolver el contacto (lead) por teléfono — servicio centralizado
    // (find-or-create). Antes esta lógica estaba duplicada aquí y en saveLead.
    let leadId = session.collectedData?.leadId;
    if (!leadId) {
      try {
        const c = await resolveContact(db, session.tenantId, {
          phone: resolvedPhone, name: resolvedName, email: resolvedEmail,
          conversationId: session.conversationId,
          sourceChannel: session.channel || 'voice', agentId: session.agentId,
        });
        leadId = c.id;
      } catch (leadErr) {
        log.warn('[ToolExecutor] No se pudo resolver contacto:', leadErr.message);
      }
    }

    // Datos de triaje dental (si aplica)
    const serviceTypeId = session.collectedData?.serviceTypeId || null;
    const doctorId      = session.collectedData?.doctorId      || null;
    const durationMins  = session.collectedData?.durationMins  || null;
    const isUrgency     = session.collectedData?.isUrgency     || false;

    const scheduling   = new SchedulingService({ db });
    const appointment  = await scheduling.createAppointment({
      tenantId:       session.tenantId,
      conversationId: session.conversationId,
      leadId:         leadId || null,
      startTime:      resolvedTime,
      name:           resolvedName,
      phone:          resolvedPhone,
      email:          resolvedEmail,
      notes:          notes || '',
      serviceTypeId,
      doctorId,
      durationMins,
      isUrgency,
      patientName:    resolvedName,
      patientPhone:   resolvedPhone,
    });

    let speech = bookingConfirmationSpeech(appointment);

    // ── Confirmación por WhatsApp al teléfono confirmado, con datos de la propiedad ──
    const property = input.property || session.collectedData?.propertyInterest || notes || '';
    let waSent = false;
    if (resolvedPhone) {
      try {
        const { getTwilioClient, getWhatsAppFrom, sendWhatsAppTracked } = require('../services/twilio-client');
        const tRow    = (await db.query('SELECT name, settings FROM tenants WHERE id=$1', [session.tenantId])).rows[0] || {};
        const bizName = tRow.settings?.businessProfile?.businessName || tRow.name || 'el equipo';
        const twilio  = getTwilioClient(tRow.settings || {});
        const fromNum = getWhatsAppFrom(tRow.settings || {});
        if (!fromNum) throw new Error('Sin remitente de WhatsApp configurado (TWILIO_WHATSAPP_FROM)');
        const lines = [`Hola ${resolvedName}, confirmamos tu cita:`,
                       `📅 ${appointment.displayTime || resolvedTime}`];
        if (property) lines.push(`🏠 ${property}`);
        lines.push('', 'Te esperamos. Si necesitas reagendar, responde a este mensaje.', `— ${bizName}`);
        const waTo = toWhatsAppMx(resolvedPhone);
        // Si hay plantilla aprobada (cold contact fuera de ventana de 24h), úsala;
        // si no, freeform (solo entrega dentro de la ventana). Ver runbook.
        const tmpl = process.env.TWILIO_WA_TEMPLATE_APPOINTMENT;
        const r = await sendWhatsAppTracked(twilio, tmpl
          ? { from: fromNum, to: waTo, contentSid: tmpl,
              contentVariables: { '1': resolvedName, '2': appointment.displayTime || resolvedTime, '3': property || bizName } }
          : { from: fromNum, to: waTo, body: lines.join('\n') });
        waSent = r.ok;
        if (r.ok) log.info(`[scheduleAppointment] Confirmación WhatsApp ${r.status} → ${waTo}`);
        else log.warn(`[scheduleAppointment] WhatsApp NO entregado (${r.status}/err ${r.errorCode}) → ${waTo}`);
      } catch (waErr) {
        log.warn('[scheduleAppointment] WhatsApp confirmación no enviada:', waErr.message);
      }
    }
    // Honestidad: el rebote de WhatsApp (p.ej. 63016, fuera de ventana de 24h)
    // llega de forma asíncrona, así que NO afirmamos que "ya llegó". Si el envío
    // se rechazó de inmediato (waSent=false) lo decimos claro y ofrecemos alternativa.
    speech += waSent
      ? ` Te estoy enviando la confirmación por WhatsApp al número que confirmaste; si no te llega en un momento, dímelo y te la reenvío.`
      : ` Tu cita ya quedó registrada; no pude enviarte la confirmación por WhatsApp, así que te la haremos llegar por otra vía.`;

    let requiresDeposit = session.collectedData?.requiresDeposit || false;
    const depositAmount   = session.collectedData?.depositAmount   || 0;

    // POLÍTICA DE NO-SHOW: si el cliente es reincidente (faltó ≥ umbral sin
    // avisar), exigir anticipo aunque el servicio no lo pida por default.
    if (!requiresDeposit && leadId) {
      try {
        const threshold = parseInt(process.env.NOSHOW_DEPOSIT_THRESHOLD) || 2;
        const ns = await db.query('SELECT no_show_count FROM leads WHERE id = $1', [leadId]);
        if ((Number(ns.rows[0]?.no_show_count) || 0) >= threshold) {
          requiresDeposit = true;
          speech += ' Como en ocasiones anteriores no se pudo concretar la cita, por política te pediremos un pequeño anticipo para apartar el lugar; enseguida te comparto cómo.';
        }
      } catch { /* no bloquear el agendado */ }
    }

    return {
      success:          true,
      appointmentId:    appointment.id,
      startTime:        appointment.scheduled_at,
      displayTime:      appointment.displayTime,
      requires_deposit: requiresDeposit,
      deposit_amount:   depositAmount,
      whatsapp_sent:    waSent,
      speech,
      outcome:          'appointment_booked',
      collectedData: {
        appointmentId:    appointment.id,
        appointmentTime:  appointment.scheduled_at,
        requiresDeposit,
        depositAmount,
      },
    };

  } catch (err) {
    log.error('[ToolExecutor] schedule_appointment error:', err.message);

    // Error de slot tomado — pedir otro
    if (err.message.includes('ya no está disponible')) {
      return {
        success: false,
        speech:  'Lo siento, ese horario acaba de ser tomado. ¿Te doy otras opciones disponibles?',
        retryCheckAvailability: true,
      };
    }

    return {
      success: false,
      speech:  'No pude confirmar la cita en este momento. ¿Puedo darte el número directo para agendar?',
      message: err.message,
    };
  }
}

async function cancelAppointment({ input, session, db }) {
  try {
    const { appointment_id, reason } = input;

    const scheduling = new SchedulingService({ db });
    await scheduling.cancelAppointment(appointment_id, session.tenantId, reason || 'Cancelado por el cliente');

    return {
      success: true,
      speech:  `Tu cita ha sido cancelada. Si necesitas reagendar, con gusto te ayudo. ¿Algo más en que pueda ayudarte?`,
      outcome: 'appointment_cancelled',
    };

  } catch (err) {
    log.error('[ToolExecutor] cancel_appointment error:', err.message);
    return {
      success: false,
      speech:  'No encontré esa cita. ¿Puedes darme más detalles para localizarla?',
      message: err.message,
    };
  }
}

async function findAppointment({ input, session, db }) {
  try {
    const phone = input.phone || session.collectedData?.leadPhone || session.contactPhone;
    if (!phone) {
      return { success: false, speech: '¿Me puedes dar tu número de teléfono para buscar tu cita?' };
    }

    const scheduling   = new SchedulingService({ db });
    const appointments = await scheduling.findUpcomingByPhone(session.tenantId, phone);

    if (appointments.length === 0) {
      return {
        success: true,
        found:   false,
        speech:  `No encontré citas próximas registradas con el número ${phone}. ¿Quieres que agende una nueva?`,
      };
    }

    const appt     = appointments[0];
    const timeStr  = new Intl.DateTimeFormat('es-MX', {
      timeZone: 'America/Mexico_City',
      weekday: 'long', month: 'long', day: 'numeric',
      hour: '2-digit', minute: '2-digit', hour12: true,
    }).format(new Date(appt.scheduled_at));

    return {
      success:      true,
      found:        true,
      appointments,
      speech:       `Tienes una cita agendada para ${timeStr}. ¿Deseas confirmarla, cancelarla o reagendarla?`,
      collectedData: { appointmentId: appt.id, appointmentTime: appt.scheduled_at },
    };

  } catch (err) {
    log.error('[ToolExecutor] find_appointment error:', err.message);
    return { success: false, speech: 'No pude consultar tus citas en este momento.', message: err.message };
  }
}

// ── Fase 1 tools (sin cambios) ─────────────────────────────────────────────

async function saveLead({ input, session, db }) {
  try {
    const { name, phone, email, intent, notes } = input;

    // Find-or-create centralizado (normaliza teléfono → dedup-safe). Antes esta
    // función guardaba el teléfono SIN normalizar, generando duplicados frente a
    // scheduleAppointment (que sí normaliza). Ya no.
    const c = await resolveContact(db, session.tenantId, {
      phone, name, email, conversationId: session.conversationId,
      sourceChannel: session.channel || 'voice', agentId: session.agentId,
    });
    const leadId = c.id;

    if (leadId) {
      // Enriquecer con datos del lead (notas, intent en custom_data).
      await db.query(
        `UPDATE leads SET name  = COALESCE(NULLIF($1,''), name),
                          email = COALESCE($2, email),
                          notes = COALESCE($3, notes),
                          custom_data = COALESCE(custom_data,'{}'::jsonb) || $4::jsonb,
                          updated_at = NOW()
         WHERE id = $5`,
        [name || '', email || null, notes || null, JSON.stringify(intent ? { intent } : {}), leadId]
      );
      // Notificación solo si el contacto es NUEVO.
      if (c.created) {
        createNotification(db, {
          tenantId: session.tenantId,
          type:     'new_lead',
          title:    `Nuevo lead: ${name || 'Cliente'}`,
          body:     phone ? `Teléfono: ${phone}` : null,
          link:     '/pages/leads.php',
        });
      }
    }

    await db.query(
      `UPDATE conversations SET outcome='lead_captured', outcome_data=$1 WHERE id=$2`,
      [JSON.stringify({ leadId, name, phone, intent }), session.conversationId]
    );

    return {
      success: true, leadId,
      message: `Lead guardado: ${name}`,
      collectedData: { leadId, leadName: name, leadPhone: phone, leadEmail: email, intent },
      outcome: 'lead_captured',
    };
  } catch (err) {
    log.error('[ToolExecutor] save_lead error:', err.message);
    return { success: false, message: 'No se pudo guardar el contacto' };
  }
}

async function joinWaitlist({ input, session, db }) {
  try {
    const { addToWaitlist } = require('../services/waitlist-service');
    const phone = normalizePhoneMx(input.phone || session.collectedData?.leadPhone || session.contactPhone || '');
    const c = await resolveContact(db, session.tenantId, {
      phone, name: input.name || session.collectedData?.leadName,
      conversationId: session.conversationId, sourceChannel: session.channel || 'voice', agentId: session.agentId,
    });
    if (!c.id) return { success: true, speech: '¿Me das tu nombre y un teléfono para anotarte en la lista de espera?' };

    // Servicio y doctor por nombre (opcionales); si ya venían en la sesión, se usan.
    let serviceTypeId = session.collectedData?.serviceTypeId || null;
    if (!serviceTypeId && input.service) {
      const s = await db.query('SELECT id FROM service_types WHERE tenant_id=$1 AND is_active AND name ILIKE $2 LIMIT 1', [session.tenantId, `%${input.service}%`]);
      serviceTypeId = s.rows[0]?.id || null;
    }
    let doctorId = session.collectedData?.doctorId || null;
    if (!doctorId && input.doctor) {
      const d = await db.query('SELECT id FROM doctors WHERE tenant_id=$1 AND is_active AND name ILIKE $2 LIMIT 1', [session.tenantId, `%${input.doctor}%`]);
      doctorId = d.rows[0]?.id || null;
    }

    await addToWaitlist(db, session.tenantId, { leadId: c.id, serviceTypeId, doctorId, note: input.preference || null });
    return {
      success: true,
      speech:  'Listo, te anoté en la lista de espera. En cuanto se libere un lugar te aviso por WhatsApp. ¿Algo más en lo que te ayude?',
      outcome: 'waitlisted',
    };
  } catch (e) {
    log.error('[joinWaitlist] Error:', e.message);
    return { success: false, speech: 'No pude anotarte en la lista de espera. Intenta de nuevo.' };
  }
}

async function transferToHuman({ input, session, db }) {
  const reason = input.reason || 'El cliente pidió hablar con una persona';

  try {
    if (session?.conversationId && db) {
      // Componer un resumen de contexto para que el humano retome sin leer todo
      const cd = session.collectedData || {};
      const ctx = [];
      if (cd.leadName)  ctx.push(`Cliente: ${cd.leadName}`);
      if (cd.leadPhone || session.contactPhone) ctx.push(`Tel: ${cd.leadPhone || session.contactPhone}`);
      if (cd.intent)    ctx.push(`Busca: ${cd.intent}`);
      if (Array.isArray(cd.cart) && cd.cart.length) {
        ctx.push(`Carrito: ${cd.cart.map(i => `${i.quantity}× ${i.name}`).join(', ')}`);
      }
      if (cd.appointmentId) ctx.push('Tiene una cita en proceso');
      const contextNote = (ctx.length ? ctx.join(' · ') + '. ' : '') + `Motivo: ${reason}`;

      // Marcar la conversación para atención humana
      await db.query(
        `UPDATE conversations
         SET needs_human = true, handoff_reason = $2, handoff_at = NOW(),
             handoff_resolved_at = NULL
         WHERE id = $1`,
        [session.conversationId, contextNote]
      );

      // Notificar al equipo (non-fatal)
      const { createNotification } = require('../services/notifications');
      createNotification(db, {
        tenantId: session.tenantId,
        type:  'new_conversation',
        title: '🙋 Un cliente necesita atención humana',
        body:  contextNote.substring(0, 90),
        link:  '/pages/conversations.php?needs_human=1',
      });
    }
  } catch (err) {
    log.error('[transferToHuman] Error marcando handoff:', err.message);
  }

  // ── Handoff consciente del canal ─────────────────────────────
  // En VOZ no se puede "atender por aquí": o hay un número al cual transferir
  // (Dial real) o se toma el dato para devolver la llamada. Nunca prometer
  // "no cuelgue" sin un Dial detrás.
  const isVoice = session?.channel === 'voice';
  let transferTo = null;
  if (isVoice && db && session?.tenantId) {
    try {
      const r = await db.query('SELECT settings FROM tenants WHERE id = $1', [session.tenantId]);
      const s = r.rows[0]?.settings || {};
      transferTo = s.transferPhone || s.voiceTransferPhone || s.clinica?.urgencyPhone || null;
    } catch (e) {
      log.error('[transferToHuman] Error leyendo número de transferencia:', e.message);
    }
  }

  if (isVoice && transferTo) {
    // Transferencia real en la llamada → el webhook emite <Dial>
    return {
      success: true, action: 'transfer', reason, transferTo,
      speech: 'Con gusto, te comunico ahora con un asesor. Quédate en la línea, por favor.',
      outcome: 'transfer_dial',
      collectedData: { transferReason: reason },
    };
  }

  if (isVoice) {
    // Sin número de transferencia → tomar el dato y devolver la llamada.
    // La llamada SIGUE (no se cuelga) para poder capturar nombre/teléfono.
    return {
      success: true, action: 'transfer', reason,
      speech: 'Con gusto. Voy a registrar tu solicitud para que un asesor te devuelva la llamada lo antes posible. ¿Me confirmas tu nombre y un número de contacto?',
      outcome: 'handoff_pending',
      collectedData: { transferReason: reason },
    };
  }

  // Canales de chat (WhatsApp / web): el humano retoma el hilo de forma asíncrona.
  return {
    success: true, action: 'transfer', reason,
    speech: 'Claro, te comunico con uno de nuestros asesores. En un momento te atienden por aquí. ¿Hay algo más que quieras dejar anotado mientras tanto?',
    outcome: 'transferred',
    collectedData: { transferReason: reason },
  };
}

async function searchKnowledgeBase({ input, session, db }) {
  try {
    const RetrievalService = require('../services/rag/retrieval');
    const retrieval = new RetrievalService({ db });

    const { chunks, found, sources } = await retrieval.getContext({
      tenantId: session.tenantId,
      query:    input.query || '',
      agentId:  session.agentId,
      topK:     3,
      minSimilarity: 0.65,
    });

    if (!found || chunks.length === 0) {
      return {
        success: true,
        found:   false,
        speech:  null, // el LLM responde con lo que sabe
        message: 'No se encontró información específica sobre ese tema en la base de conocimiento.',
      };
    }

    const contextText = chunks.map(c => c.content).join('\n\n');

    return {
      success: true,
      found:   true,
      context: contextText,
      sources,
      speech:  null, // el LLM usa el contexto para formular la respuesta
    };
  } catch (err) {
    log.error('[ToolExecutor] search_knowledge_base error:', err.message);
    return { success: false, message: 'Error consultando la base de conocimiento' };
  }
}

// ─── Módulo Clínica ───────────────────────────────────────────────────────────

/**
 * triageService — Identifica tipo de servicio y doctor por la descripción del usuario
 */
async function triageService({ input, session, db }) {
  try {
    const desc = (input.user_description || '').toLowerCase();

    // 1. Buscar en service_types del tenant
    const stResult = await db.query(
      `SELECT st.*, d.name AS doctor_name, d.phone AS doctor_phone, d.schedule_config,
              d.color AS doctor_color, d.avatar_initials
       FROM service_types st
       LEFT JOIN doctors d ON d.id = st.default_doctor_id
       WHERE st.tenant_id = $1 AND st.is_active = true
       ORDER BY st.sort_order ASC`,
      [session.tenantId]
    );

    let matched = null;
    let urgencyMatch = false;

    for (const st of stResult.rows) {
      const keywords = st.voice_keywords || [st.slug, st.name.toLowerCase()];
      const hit = keywords.some(kw => desc.includes(kw.toLowerCase()));

      if (hit) {
        if (st.is_urgency) { matched = st; urgencyMatch = true; break; }
        if (!matched) matched = st;
      }
    }

    // 2. Detección genérica de urgencia por palabras clave
    const urgencyKws = ['dolor fuerte','dolor intenso','dolor insoportable','mucho dolor',
      'no puedo dormir','absceso','fractura','muela rota','sangrado','accidente',
      'golpe','urgencia','urgente','emergencia','inflamación severa','hinchazón'];
    const hasUrgencyWords = urgencyKws.some(kw => desc.includes(kw));

    if (hasUrgencyWords && !urgencyMatch) {
      // Buscar servicio de urgencia del tenant
      const urgSt = stResult.rows.find(s => s.is_urgency);
      if (urgSt) { matched = urgSt; urgencyMatch = true; }
    }

    if (!matched) {
      // Sin match — retornar lista de opciones para el agente
      const options = stResult.rows.map(s => s.name).join(', ');
      return {
        success:    true,
        matched:    false,
        is_urgency: false,
        speech: options
          ? `¿Podría indicarme qué tipo de servicio necesita? Ofrecemos: ${options}.`
          : '¿Podría indicarme qué tipo de servicio dental necesita?',
        service_type: null,
      };
    }

    // 3. Guardar en sesión para check_availability doctor-aware
    const collectedData = {
      serviceTypeId:     matched.id,
      serviceTypeName:   matched.name,
      serviceSlug:       matched.slug,
      durationMins:      matched.duration_mins,
      doctorId:          matched.default_doctor_id || null,
      doctorName:        matched.doctor_name || null,
      isUrgency:         matched.is_urgency || urgencyMatch,
      requiresDeposit:   matched.requires_deposit || false,
      depositAmount:     matched.deposit_amount || 0,
      prepInstructions:  matched.prep_instructions || null,
      postInstructions:  matched.post_instructions || null,
    };

    let speech = '';
    if (matched.is_urgency || urgencyMatch) {
      speech = `Entiendo que es una urgencia dental. Voy a conectarle ahora mismo con nuestro equipo de guardia.`;
    } else {
      speech = `Perfecto, para ${matched.name}`;
      if (matched.doctor_name) speech += ` trabajamos con el doctor ${matched.doctor_name}`;
      speech += `. La cita tiene una duración de ${matched.duration_mins} minutos.`;
      if (matched.prep_instructions) speech += ` Le recuerdo que ${matched.prep_instructions}.`;
      speech += ` ¿Tiene alguna preferencia de horario?`;
    }

    return {
      success:       true,
      matched:       true,
      is_urgency:    matched.is_urgency || urgencyMatch,
      service_type:  matched,
      doctor_id:     matched.default_doctor_id,
      doctor_name:   matched.doctor_name,
      duration_mins: matched.duration_mins,
      prep:          matched.prep_instructions,
      requires_deposit: matched.requires_deposit,
      deposit_amount:   matched.deposit_amount,
      speech,
      collectedData,
    };

  } catch (err) {
    log.error('[ToolExecutor] triage_service error:', err.message);
    return {
      success:    false,
      is_urgency: false,
      speech:     '¿Podría decirme qué tipo de servicio dental necesita? Por ejemplo limpieza, valoración, urgencia o tratamiento.',
    };
  }
}

/**
 * escalateUrgency — Transfiere al número de guardia y crea lead urgente
 */
async function escalateUrgency({ input, session, db }) {
  try {
    const { reason, patient_phone } = input;
    const phone = patient_phone || session.contactPhone;

    // Buscar número de guardia del tenant
    const tenantResult = await db.query(
      'SELECT settings FROM tenants WHERE id = $1',
      [session.tenantId]
    );
    const settings = tenantResult.rows[0]?.settings || {};
    const urgencyPhone = settings.clinica?.urgencyPhone || settings.urgencyPhone || null;

    // Resolver el contacto (find-or-create centralizado, teléfono normalizado)
    // y marcarlo como urgente — antes esto insertaba el teléfono sin normalizar.
    try {
      const c = await resolveContact(db, session.tenantId, {
        phone, conversationId: session.conversationId,
        sourceChannel: session.channel || 'voice', agentId: session.agentId,
      });
      if (c.id) {
        await db.query(
          `UPDATE leads SET score = GREATEST(COALESCE(score,0), 100),
                            custom_data = COALESCE(custom_data,'{}'::jsonb) || $1::jsonb,
                            updated_at = now()
           WHERE id = $2`,
          [JSON.stringify({ intent: 'urgencia_dental', urgency_reason: reason, is_urgency: true }), c.id]
        );
      }
    } catch (e) { log.warn('[escalateUrgency] No se pudo marcar contacto urgente:', e.message); }

    // Intentar enviar WhatsApp de alerta al número de guardia
    if (urgencyPhone) {
      try {
        const { getTwilioClient, getTwilioFrom } = require('../services/twilio-client');
        const tenantSettings = settings;
        const twilio   = getTwilioClient(tenantSettings);
        const fromNum  = getTwilioFrom(tenantSettings);
        const msg = `🚨 *URGENCIA DENTAL*\nPaciente: ${phone}\nMotivo: ${reason}\nLlamar de inmediato.`;
        await twilio.messages.create({ body: msg, from: `whatsapp:${fromNum}`, to: `whatsapp:${urgencyPhone}` });
      } catch (wErr) {
        log.warn('[escalateUrgency] No se pudo enviar alerta WhatsApp:', wErr.message);
      }
    }

    return {
      success:       true,
      urgency_phone: urgencyPhone,
      transfer:      true,  // señal para que el agente transfiera la llamada
      speech: urgencyPhone
        ? `Entiendo. Le voy a transferir ahora mismo con nuestro doctor de guardia. Un momento por favor.`
        : `Entiendo que es una urgencia. Por favor comuníquese directamente con la clínica para atención inmediata. ¿Desea que le repita el número?`,
      collectedData: { urgencyEscalated: true, urgencyReason: reason },
      ended: !!urgencyPhone,  // si hay número de transferencia → terminar sesión AI
    };

  } catch (err) {
    log.error('[ToolExecutor] escalate_urgency error:', err.message);
    return {
      success: false,
      speech:  'Hay una urgencia. Por favor llame directamente a la clínica para atención inmediata.',
      ended:   false,
    };
  }
}

/**
 * sendDepositLink — Genera link de Stripe y lo envía por WhatsApp
 */
async function sendDepositLink({ input, session, db }) {
  try {
    const { appointment_id, patient_phone, amount } = input;
    const phone = patient_phone || session.contactPhone;

    // Obtener datos de la cita
    const apptResult = await db.query(
      'SELECT * FROM appointments WHERE id = $1 AND tenant_id = $2',
      [appointment_id, session.tenantId]
    );
    const appt = apptResult.rows[0];
    if (!appt) return { success: false, speech: 'No encontré la cita para generar el pago.' };

    const depositAmount = amount || appt.deposit_amount || 200; // fallback 200 MXN

    // Intentar crear Payment Link de Stripe
    let paymentLink = null;
    try {
      const stripe = require('stripe')(process.env.STRIPE_SECRET_KEY);
      const price = await stripe.prices.create({
        currency:     'mxn',
        unit_amount:  Math.round(depositAmount * 100),
        product_data: { name: `Anticipo cita dental — ${appt.title || 'Consulta'}` },
      });
      const link = await stripe.paymentLinks.create({
        line_items: [{ price: price.id, quantity: 1 }],
        metadata: { appointment_id, tenant_id: session.tenantId },
        after_completion: { type: 'redirect', redirect: { url: `${process.env.APP_URL || 'https://agentcore.io'}/gracias` } },
      });
      paymentLink = link.url;

      // Guardar link en appointment
      await db.query(
        `UPDATE appointments SET deposit_payment_link=$1, deposit_status='pending', deposit_amount=$2
         WHERE id=$3`,
        [paymentLink, depositAmount, appointment_id]
      );
    } catch (stripeErr) {
      log.warn('[sendDepositLink] Stripe no disponible:', stripeErr.message);
      paymentLink = null;
    }

    // Enviar por WhatsApp
    if (paymentLink && phone) {
      try {
        const { getTwilioClient, getTwilioFrom } = require('../services/twilio-client');
        const tenantSettings = (await db.query('SELECT settings FROM tenants WHERE id=$1', [session.tenantId])).rows[0]?.settings || {};
        const twilio  = getTwilioClient(tenantSettings);
        const fromNum = getTwilioFrom(tenantSettings);
        const msg = `Para confirmar su cita, realice el anticipo de $${depositAmount} MXN en el siguiente enlace:\n${paymentLink}\n\nGracias por elegirnos.`;
        await twilio.messages.create({ body: msg, from: `whatsapp:${fromNum}`, to: `whatsapp:${phone}` });
      } catch (waErr) {
        log.warn('[sendDepositLink] WhatsApp no disponible:', waErr.message);
      }
    }

    return {
      success:       true,
      payment_link:  paymentLink,
      amount:        depositAmount,
      speech: paymentLink
        ? `Le acabo de enviar un link de pago por WhatsApp para apartar su cita con un anticipo de ${depositAmount} pesos. Una vez realizado el pago, su cita quedará confirmada.`
        : `Para confirmar su cita necesitamos un anticipo de ${depositAmount} pesos. ¿Le gustaría que le enviemos los datos de pago?`,
    };

  } catch (err) {
    log.error('[ToolExecutor] send_deposit_link error:', err.message);
    return {
      success: false,
      speech:  'No pude generar el link de pago en este momento. Le contactaremos para coordinar el anticipo.',
    };
  }
}

// ══════════════════════════════════════════════════════════════════
// MÓDULO CONSULTORIOS
// ══════════════════════════════════════════════════════════════════

/**
 * qualify_lead — Califica el lead con las preguntas configuradas
 * Si alguna pregunta crítica descalifica, retorna qualified: false
 * El agente debe llamar esto ANTES de book_session_series
 */
async function qualifyLead({ input, session, db }) {
  const { professional_id, session_type_id, answers = [] } = input;
  // answers: [{ question_id, answer }] donde answer es 'yes'|'no'|texto

  const tenantId = session.tenantId;

  // Cargar preguntas activas
  let q = `SELECT * FROM qualification_questions
           WHERE tenant_id = $1 AND is_active = true`;
  const vals = [tenantId];
  let idx = 2;
  if (professional_id) {
    q += ` AND (professional_id = $${idx++} OR professional_id IS NULL)`;
    vals.push(professional_id);
  }
  if (session_type_id) {
    q += ` AND (session_type_id = $${idx++} OR session_type_id IS NULL)`;
    vals.push(session_type_id);
  }
  q += ' ORDER BY sort_order ASC, importance DESC';

  const questionsResult = await db.query(q, vals);
  const questions = questionsResult.rows;

  if (questions.length === 0) {
    // Sin preguntas configuradas → calificar directamente
    return { qualified: true, score: 10, questions: [], message: 'Sin preguntas de calificación configuradas.' };
  }

  // Si no vienen respuestas, devolver las preguntas para que el agente las haga
  if (answers.length === 0) {
    return {
      qualified: null,  // pendiente
      questions: questions.map(q => ({
        id:          q.id,
        question:    q.question,
        hint:        q.hint,
        answer_type: q.answer_type,
        importance:  q.importance,
      })),
      message: 'Realiza las siguientes preguntas al cliente antes de proceder.',
    };
  }

  // Evaluar respuestas
  let disqualified = false;
  let disqualifyReason = '';
  let totalScore = 0;
  let maxScore = 0;

  for (const qq of questions) {
    maxScore += qq.importance;
    const ans = answers.find(a => a.question_id === qq.id);
    if (!ans) continue;

    const ansLower = ans.answer.toLowerCase().trim();
    const isYes = ['si','sí','yes','true','1'].includes(ansLower);
    const isNo  = ['no','not','false','0'].includes(ansLower);

    // Verificar descalificación
    if (qq.disqualify_on === 'yes' && isYes) {
      disqualified = true;
      disqualifyReason = `El cliente respondió "sí" a: "${qq.question}"`;
      break;
    }
    if (qq.disqualify_on === 'no' && isNo) {
      disqualified = true;
      disqualifyReason = `El cliente respondió "no" a: "${qq.question}"`;
      break;
    }
    if (qq.disqualify_on === 'any') {
      disqualified = true;
      disqualifyReason = `Pregunta descalificadora: "${qq.question}"`;
      break;
    }

    // Sumar score positivo si respondió favorablemente
    if (!disqualified) totalScore += qq.importance;
  }

  const scorePercent = maxScore > 0 ? Math.round((totalScore / maxScore) * 10) : 10;

  if (disqualified) {
    // Guardar en collectedData para que el agente no insista
    if (session.collectedData) session.collectedData.leadDisqualified = true;
    return {
      qualified: false,
      score: 0,
      reason: disqualifyReason,
      message: 'El caso no aplica para este servicio. Informa al cliente amablemente y ofrece alternativas si las hay.',
    };
  }

  return {
    qualified: true,
    score: scorePercent,
    message: scorePercent >= 6
      ? 'Lead calificado. Procede a agendar la sesión.'
      : 'Lead parcialmente calificado. Procede con precaución.',
  };
}

/**
 * book_session_series — Agenda N sesiones recurrentes
 * Crea la serie y las sesiones con status pending_professional
 * Envía alerta WhatsApp al profesional
 */
async function bookSessionSeries({ input, session, db }) {
  const {
    professional_id, session_type_id,
    patient_name, patient_phone, patient_email,
    total_sessions, frequency, modality, first_session_at,
    notes,
  } = input;

  const tenantId      = session.tenantId;
  const conversationId = session.conversationId;
  const leadId        = session.collectedData?.leadId || null;

  // Obtener tenant settings y datos del profesional
  const tenantResult = await db.query(
    'SELECT settings, name FROM tenants WHERE id = $1', [tenantId]
  );
  const tenant = tenantResult.rows[0];
  const settings = tenant?.settings || {};

  // Obtener duración del tipo de sesión
  let durationMins = 60;
  let sessionTypeName = 'Sesión';
  if (session_type_id) {
    const st = await db.query(
      'SELECT duration_mins, name FROM consultorio_session_types WHERE id=$1', [session_type_id]
    );
    if (st.rows[0]) { durationMins = st.rows[0].duration_mins; sessionTypeName = st.rows[0].name; }
  }

  // Frecuencia → días entre sesiones
  const freqDays = { single: 0, weekly: 7, biweekly: 14, monthly: 30 };
  const gap = freqDays[frequency] || 7;
  const firstDate = new Date(first_session_at);

  // Crear la serie
  const seriesResult = await db.query(
    `INSERT INTO session_series
       (tenant_id, professional_id, session_type_id, conversation_id, lead_id,
        patient_name, patient_phone, patient_email, total_sessions, frequency, modality, notes)
     VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12) RETURNING *`,
    [
      tenantId, professional_id || null, session_type_id || null,
      conversationId || null, leadId,
      patient_name, patient_phone || null, patient_email || null,
      total_sessions || 1, frequency || 'single', modality || 'presencial', notes || null,
    ]
  );
  const series = seriesResult.rows[0];

  // Crear sesiones individuales
  const sessionDates = [];
  for (let i = 0; i < (total_sessions || 1); i++) {
    const sessionDate = new Date(firstDate.getTime() + i * gap * 86400_000);
    const r = await db.query(
      `INSERT INTO sessions
         (tenant_id, series_id, professional_id, session_type_id, conversation_id, lead_id,
          patient_name, patient_phone, patient_email, session_number,
          scheduled_at, duration_mins, status, modality)
       VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12,'pending_professional',$13) RETURNING id, scheduled_at`,
      [
        tenantId, series.id, professional_id || null, session_type_id || null,
        conversationId || null, leadId,
        patient_name, patient_phone || null, patient_email || null,
        i + 1, sessionDate.toISOString(), durationMins, modality || 'presencial',
      ]
    );
    sessionDates.push(r.rows[0].scheduled_at);
  }

  // Guardar en sesión del agente
  if (session.collectedData) {
    session.collectedData.seriesId = series.id;
    session.collectedData.sessionBooked = true;
  }

  // Notificar al profesional por WhatsApp si hay teléfono configurado
  if (professional_id) {
    const profResult = await db.query(
      'SELECT name, phone FROM professionals WHERE id=$1', [professional_id]
    );
    const prof = profResult.rows[0];
    if (prof?.phone) {
      try {
        const { getTwilioClient, getTwilioFrom } = require('../services/twilio-client');
        const twilio  = getTwilioClient(settings);
        const fromNum = getTwilioFrom(settings);

        const firstDisplay = new Date(sessionDates[0]).toLocaleDateString('es-MX', {
          timeZone: 'America/Mexico_City', weekday: 'long', day: 'numeric', month: 'long',
          hour: '2-digit', minute: '2-digit',
        });

        const body = `📅 *Nueva solicitud de ${sessionTypeName}*\n\n` +
          `${prof.name}, tienes una nueva solicitud:\n` +
          `👤 *Paciente:* ${patient_name}\n` +
          `📋 *Sesiones:* ${total_sessions} (${frequency === 'weekly' ? 'semanales' : frequency === 'biweekly' ? 'quincenales' : 'mensuales'})\n` +
          `📅 *Primera sesión:* ${firstDisplay}\n` +
          `📍 *Modalidad:* ${modality === 'video' ? 'Videollamada' : 'Presencial'}\n\n` +
          `Por favor confirma los horarios desde el panel de administración.\n` +
          `_${tenant?.name}_`;

        await twilio.messages.create({
          body,
          from: `whatsapp:${fromNum}`,
          to:   `whatsapp:${prof.phone}`,
        });
      } catch (err) {
        log.warn('[BookSeries] Error enviando alerta al profesional:', err.message);
      }
    }
  }

  const freqLabel = { single: '', weekly: 'semanales', biweekly: 'quincenales', monthly: 'mensuales' }[frequency] || '';
  return {
    success: true,
    series_id: series.id,
    sessions_created: sessionDates.length,
    first_session: sessionDates[0],
    message: `Se agendaron ${total_sessions} sesiones ${freqLabel} a partir del ${new Date(sessionDates[0]).toLocaleDateString('es-MX', { timeZone:'America/Mexico_City', weekday:'long', day:'numeric', month:'long' })}. El profesional las confirmará pronto y recibirás un aviso.`,
    status: 'pending_professional',
  };
}

/**
 * send_video_link — Envía el link de videollamada del profesional al paciente
 */
async function sendVideoLink({ input, session, db }) {
  const { professional_id, session_id, patient_phone } = input;
  const tenantId = session.tenantId;

  const tenantResult = await db.query('SELECT settings, name FROM tenants WHERE id=$1', [tenantId]);
  const settings = tenantResult.rows[0]?.settings || {};
  const tenantName = tenantResult.rows[0]?.name || '';

  // Obtener link del profesional
  let videoLink = null;
  if (professional_id) {
    const pr = await db.query('SELECT video_link, name FROM professionals WHERE id=$1 AND tenant_id=$2', [professional_id, tenantId]);
    if (pr.rows[0]?.video_link) videoLink = pr.rows[0].video_link;
  }

  // Si no tiene link de profesional, buscar en la sesión
  if (!videoLink && session_id) {
    const sr = await db.query('SELECT video_link FROM sessions WHERE id=$1', [session_id]);
    if (sr.rows[0]?.video_link) videoLink = sr.rows[0].video_link;
  }

  if (!videoLink) {
    return { success: false, message: 'El profesional no tiene link de videollamada configurado. Contacta al consultorio para obtener el link.' };
  }

  const phone = patient_phone || session.contactPhone;
  if (!phone) return { success: false, message: 'No hay teléfono del paciente para enviar el link.' };

  try {
    const { getTwilioClient, getTwilioFrom } = require('../services/twilio-client');
    const twilio  = getTwilioClient(settings);
    const fromNum = getTwilioFrom(settings);
    const confidentialityNote = settings?.consultorio?.confidentialityMessage || 'Por privacidad, no compartas información sensible por este medio.';

    await twilio.messages.create({
      body: `🎥 *Link de tu sesión — ${tenantName}*\n\n${videoLink}\n\n⚠️ _${confidentialityNote}_`,
      from: `whatsapp:${fromNum}`,
      to:   `whatsapp:${phone}`,
    });

    return { success: true, message: `Link de videollamada enviado a ${phone}` };
  } catch (err) {
    log.error('[SendVideoLink] Error:', err.message);
    return { success: false, message: 'No se pudo enviar el link. Verifica la configuración de WhatsApp.' };
  }
}

// ─── Catálogo / comercio ──────────────────────────────────────────────────────

// Formato de dinero CON moneda explícita → así el bot dice "pesos"/"dólares"
// y nunca confunde el "$" con dólares por defecto. Default MXN.
const CURRENCY_WORD = { MXN: 'pesos', USD: 'dólares', EUR: 'euros' };
function money(cents, currency = 'MXN') {
  const n = (cents / 100).toLocaleString('es-MX', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
  const code = String(currency || 'MXN').toUpperCase();
  const word = CURRENCY_WORD[code];
  return word ? `$${n} ${word}` : `$${n} ${code}`;
}

// Moneda del tenant (settings.businessProfile.currency → default MXN), cacheada.
const _currencyCache = new Map();
async function tenantCurrency(db, tenantId) {
  const hit = _currencyCache.get(tenantId);
  if (hit && Date.now() - hit.ts < 60000) return hit.cur;
  let cur = 'MXN';
  try {
    const r = await db.query('SELECT settings FROM tenants WHERE id = $1', [tenantId]);
    const s = r.rows[0]?.settings || {};
    cur = (s.businessProfile?.currency || s.currency || 'MXN');
  } catch { /* default MXN */ }
  _currencyCache.set(tenantId, { cur, ts: Date.now() });
  return cur;
}

async function searchProducts({ input, session, db }) {
  try {
    const { CatalogService } = require('../services/catalog-service');
    const catalog = new CatalogService({ db });
    const products = await catalog.searchProducts(session.tenantId, {
      query:        input.query         || null,
      category:     input.category      || null,
      operation:    input.operation     || null,
      propertyType: input.property_type || null,
      priceMin:     input.price_min  != null ? input.price_min : null,
      priceMax:     input.price_max  != null ? input.price_max : null,
      bedroomsMin:  input.bedrooms   != null ? input.bedrooms  : null,
    });

    // ¿Es un catálogo de propiedades? (attributes de inmobiliaria)
    const isRealEstate = products.some(p => p.attributes?.operation || p.attributes?.propertyType)
      || input.operation || input.property_type;

    if (!products.length) {
      const filtered = input.operation || input.property_type || input.price_max != null || input.bedrooms != null;
      return {
        success: true,
        speech: isRealEstate
          ? (filtered
              ? 'No encontré propiedades con esas características. ¿Ampliamos la zona o el presupuesto?'
              : 'No tengo propiedades que coincidan. ¿Me das más detalles de lo que buscas?')
          : (input.query
              ? `No encontré productos que coincidan con "${input.query}". ¿Quieres que te muestre todo el catálogo?`
              : 'Por ahora no tenemos productos en el catálogo.'),
        collectedData: { lastProductSearch: [] },
      };
    }

    const cur = await tenantCurrency(db, session.tenantId);
    const priceOf = (p) => (p.attributes?.priceLabel ? String(p.attributes.priceLabel) : money(p.price_cents, cur));
    const list = products.slice(0, isRealEstate ? 3 : 8)
      .map(p => {
        if (isRealEstate) {
          const a = p.attributes || {};
          const bits = [];
          if (a.zone) bits.push(a.zone);
          if (a.bedrooms) bits.push(`${a.bedrooms} rec`);
          if (a.bathrooms) bits.push(`${a.bathrooms} baños`);
          if (a.areaM2) bits.push(`${a.areaM2} m²`);
          return `• ${p.name} — ${priceOf(p)}${bits.length ? ` (${bits.join(', ')})` : ''}`;
        }
        return `• ${p.name} — ${money(p.price_cents, cur)}${p.description ? ` (${p.description})` : ''}`;
      })
      .join('\n');

    return {
      success: true,
      speech: isRealEstate
        ? `Tengo estas opciones:\n${list}\n\n¿Te mando la ficha de alguna por WhatsApp o agendamos una visita?`
        : `Esto es lo que tenemos:\n${list}\n\n¿Cuál te interesa o quieres agregar al carrito?`,
      // Tarjetas para canales con UI visual (chat web): foto + precio + categoría.
      // Voz las ignora (usa `speech`). El front decide si las muestra.
      cards: products.slice(0, 24).map(p => ({
        name:        p.name,
        price_cents: Number(p.price_cents) || 0,
        currency:    p.currency || 'MXN',
        description: p.description || '',
        image_url:   p.image_url || null,
        category:    p.category || '',
      })),
      // Guardar para resolver referencias del usuario ("el segundo", etc.)
      collectedData: { lastProductSearch: products.map(p => ({ id: p.id, name: p.name, unit_cents: p.price_cents })) },
    };
  } catch (err) {
    log.error('[searchProducts] Error:', err.message);
    return { success: false, speech: 'Tuve un problema consultando el catálogo. Intenta de nuevo.' };
  }
}

// Valida si una dirección de entrega cae dentro del radio de delivery del
// negocio (configurado en settings.delivery). Geocodifica la dirección y mide
// la distancia al origen del negocio.
async function checkDeliveryArea({ input, session, db }) {
  const address = String(input.address || '').trim();
  if (!address) {
    return { success: true, speech: '¿Me das la dirección completa de entrega? Calle, número y colonia.' };
  }

  const row = (await db.query('SELECT settings FROM tenants WHERE id=$1', [session.tenantId])).rows[0] || {};
  const d = (row.settings && row.settings.delivery) || {};
  const origin = (d.originLat != null && d.originLng != null)
    ? { lat: Number(d.originLat), lng: Number(d.originLng) } : null;
  const radiusKm = Number(d.radiusKm) || 0;

  if (!origin || !radiusKm) {
    // Sin zona configurada → no bloquear el pedido (fail-open), avisar honesto.
    return {
      success: true, configured: false,
      speech: 'Tomo tu dirección; confirmaremos la cobertura de entrega al procesar el pedido.',
    };
  }

  const geo = await geocodeAddress(address, { near: origin });
  if (!geo) {
    // FAIL-OPEN: no insistir en bucle (la voz puede transcribir mal la colonia).
    // Al 2º intento fallido, ACEPTAMOS el pedido y marcamos confirmación manual,
    // en vez de repetir "no pude ubicar" indefinidamente.
    const attempts = (Number(session.collectedData?.deliveryAttempts) || 0) + 1;
    if (attempts >= 2) {
      return {
        success: true, resolved: false, accepted: true,
        speech: `No logré confirmar la zona en el mapa, pero tomo tu pedido a ${address}. El repartidor te contactará para ubicarte exactamente. ¿Continuamos?`,
        collectedData: { deliveryAttempts: attempts, deliveryAddress: address, deliveryManualConfirm: true },
      };
    }
    return {
      success: true, resolved: false,
      speech: 'No me quedó clara la dirección. ¿Me confirmas la colonia o un punto de referencia?',
      collectedData: { deliveryAttempts: attempts },
    };
  }

  const distance = haversineKm(origin, geo);
  const within = distance <= radiusKm;
  const dTxt = distance < 1 ? `${Math.round(distance * 1000)} metros` : `${distance.toFixed(1)} km`;

  if (within) {
    return {
      success: true, within: true, distance_km: Number(distance.toFixed(2)),
      speech: `¡Perfecto! Tu dirección está dentro de nuestra zona de entrega (a unos ${dTxt}). Continúo con el pedido.`,
    };
  }
  return {
    success: true, within: false, distance_km: Number(distance.toFixed(2)),
    speech: `Lo siento, esa dirección está a ${dTxt} y nuestro reparto a domicilio llega hasta ${radiusKm} km. ¿Te gustaría que lo dejes para pasar a recogerlo, o lo enviamos a una dirección más cercana?`,
  };
}

// Resumen estructurado del carrito para la UI del chat (barra de carrito).
function cartSummary(cart) {
  cart = Array.isArray(cart) ? cart : [];
  return {
    count:       cart.reduce((s, i) => s + i.quantity, 0),
    total_cents: cart.reduce((s, i) => s + i.unit_cents * i.quantity, 0),
    items:       cart.map(i => ({ name: i.name, quantity: i.quantity, unit_cents: i.unit_cents })),
  };
}

async function addToCart({ input, session, db }) {
  try {
    const { CatalogService } = require('../services/catalog-service');
    const catalog = new CatalogService({ db });
    const qty = Math.max(1, parseInt(input.quantity) || 1);

    const product = await catalog.resolveProduct(session.tenantId, input.product_name);
    if (!product) {
      return {
        success: true,
        speech:  `No encontré "${input.product_name}" en el catálogo. ¿Quieres que te muestre los productos disponibles?`,
      };
    }

    // Validar stock si está definido
    if (product.stock != null && product.stock < qty) {
      return { success: true, speech: `Solo nos quedan ${product.stock} de ${product.name}. ¿Te llevas esa cantidad?` };
    }

    const cart = Array.isArray(session.collectedData?.cart) ? [...session.collectedData.cart] : [];
    const existing = cart.find(it => it.product_id === product.id);
    if (existing) existing.quantity += qty;
    else cart.push({ product_id: product.id, name: product.name, unit_cents: product.price_cents, quantity: qty });

    const total = cart.reduce((s, it) => s + it.unit_cents * it.quantity, 0);
    const cur = await tenantCurrency(db, session.tenantId);

    return {
      success: true,
      speech:  `Agregué ${qty} × ${product.name} a tu carrito. Llevas ${cart.reduce((s,i)=>s+i.quantity,0)} producto(s), total ${money(total, cur)}. ¿Algo más o cerramos el pedido?`,
      cart:    cartSummary(cart),
      added:   { name: product.name, quantity: qty },
      collectedData: { cart },
    };
  } catch (err) {
    log.error('[addToCart] Error:', err.message);
    return { success: false, speech: 'No pude agregar el producto. Intenta de nuevo.' };
  }
}

async function removeFromCart({ input, session, db }) {
  try {
    const cart = Array.isArray(session.collectedData?.cart) ? [...session.collectedData.cart] : [];
    if (!cart.length) return { success: true, speech: 'Tu carrito ya está vacío.', cart: cartSummary([]) };

    const name = String(input.product_name || '').toLowerCase().trim();
    // Match flexible por nombre (ignora "(orden)" y coincidencias parciales).
    const idx = cart.findIndex(it => {
      const n = it.name.toLowerCase();
      const base = n.split('(')[0].trim();
      return n.includes(name) || (name.length >= 3 && (base.includes(name) || name.includes(base)));
    });
    if (idx === -1) {
      return { success: true, speech: `No encontré "${input.product_name}" en tu carrito. Tienes: ${cart.map(i => i.name).join(', ')}. ¿Cuál quito?` };
    }

    const item = cart[idx];
    const removedName = item.name;
    const qty = parseInt(input.quantity);
    if (Number.isFinite(qty) && qty > 0 && qty < item.quantity) {
      item.quantity -= qty;                 // quitar solo algunas unidades
    } else {
      cart.splice(idx, 1);                  // quitar el producto completo
    }

    const cur = await tenantCurrency(db, session.tenantId);
    const total = cart.reduce((s, it) => s + it.unit_cents * it.quantity, 0);
    const speech = cart.length
      ? `Listo, quité ${removedName}. Tu carrito queda en ${money(total, cur)}. ¿Algo más o cerramos el pedido?`
      : `Listo, quité ${removedName}. Tu carrito quedó vacío. ¿Quieres ver el menú?`;
    return { success: true, speech, cart: cartSummary(cart), collectedData: { cart } };
  } catch (err) {
    log.error('[removeFromCart] Error:', err.message);
    return { success: false, speech: 'No pude actualizar el carrito. Intenta de nuevo.' };
  }
}

async function viewCart({ session, db }) {
  const cart = Array.isArray(session.collectedData?.cart) ? session.collectedData.cart : [];
  if (!cart.length) {
    return { success: true, speech: 'Tu carrito está vacío. ¿Quieres ver el catálogo?' };
  }
  const cur = await tenantCurrency(db, session.tenantId);
  const lines = cart.map(it => `• ${it.quantity} × ${it.name} — ${money(it.unit_cents * it.quantity, cur)}`).join('\n');
  const total = cart.reduce((s, it) => s + it.unit_cents * it.quantity, 0);
  return {
    success: true,
    speech:  `Tu carrito:\n${lines}\n\n*Total: ${money(total, cur)}*\n¿Confirmamos el pedido?`,
    cart:    cartSummary(cart),
  };
}

async function checkoutOrder({ input, session, db }) {
  try {
    const cart = Array.isArray(session.collectedData?.cart) ? session.collectedData.cart : [];
    if (!cart.length) {
      return { success: true, speech: 'Tu carrito está vacío. Agrega productos antes de pagar.' };
    }

    const { CatalogService } = require('../services/catalog-service');
    const catalog = new CatalogService({ db });

    const result = await catalog.checkout({
      tenantId:       session.tenantId,
      cart,
      customerName:   input.customer_name  || session.collectedData?.leadName  || null,
      customerPhone:  input.customer_phone || session.collectedData?.leadPhone || session.contactPhone || null,
      conversationId: session.conversationId,
      channel:        session.channel || 'whatsapp',
      appUrl:         process.env.APP_URL,
      deliveryAddress: session.collectedData?.deliveryAddress || null,
    });

    const cur = await tenantCurrency(db, session.tenantId);
    const totalTxt = money(result.total_cents, cur);
    // Por VOZ no se dictan URLs; se confirma que la confirmación va por WhatsApp.
    const speech = result.paymentOnDelivery
      ? `¡Listo! Tu pedido quedó confirmado, total ${totalTxt}, pago al recibir. Te llegará la confirmación por WhatsApp en un momento.`
      : `¡Listo! Tu pedido por ${totalTxt} quedó registrado. Te llegará por WhatsApp la confirmación y el link de pago.`;
    return {
      success: true,
      speech,
      cart:        cartSummary([]),                 // carrito vaciado
      payment_url: result.url,
      // Vaciar el carrito tras generar el pedido
      collectedData: { cart: [], lastOrderId: result.order_id },
    };
  } catch (err) {
    log.error('[checkoutOrder] Error:', err.message);
    return { success: true, speech: err.message || 'No pude generar el link de pago. Intenta de nuevo.' };
  }
}

/**
 * send_property_info — Envía la cédula de la propiedad por WhatsApp:
 * foto principal + ficha de datos + link a la cédula web completa (con galería).
 */
async function sendPropertyInfo({ input, session, db }) {
  const phone = normalizePhoneMx(input.phone || session.collectedData?.leadPhone || session.contactPhone || '');
  if (!phone) {
    return { success: true, speech: '¿A qué número de WhatsApp te envío la ficha de la propiedad?' };
  }
  const { CatalogService } = require('../services/catalog-service');
  const catalog = new CatalogService({ db });
  const products = input.property
    ? await catalog.searchProducts(session.tenantId, { query: input.property, limit: 3 })
    : [];
  if (!products.length) {
    return { success: true, speech: `No encontré "${input.property || 'esa propiedad'}". ¿Me dices cuál te interesa para enviarte la ficha?` };
  }

  const { getTwilioClient, getWhatsAppFrom, sendWhatsAppTracked } = require('../services/twilio-client');
  const { publicBase, absUrl } = require('../services/public-url');
  const tRow    = (await db.query('SELECT settings FROM tenants WHERE id=$1', [session.tenantId])).rows[0] || {};
  const twilio  = getTwilioClient(tRow.settings || {});
  const fromNum = getWhatsAppFrom(tRow.settings || {});
  const base    = publicBase(tRow.settings || {});   // dominio fijo del tenant → env → ngrok (temporal)
  const waTo    = toWhatsAppMx(phone);

  let sent = 0;
  const enviadas = [];
  for (const p of products.slice(0, 3)) {
    const a = p.attributes || {};
    const imgs  = (Array.isArray(p.images) && p.images.length ? p.images : (p.image_url ? [p.image_url] : [])).map(u => absUrl(u, base));
    const cover = imgs[0];
    const link  = `${base}/p/${p.id}`;
    const specs = [];
    if (a.bedrooms)  specs.push(`${a.bedrooms} rec`);
    if (a.bathrooms) specs.push(`${a.bathrooms} baño${a.bathrooms > 1 ? 's' : ''}`);
    if (a.areaM2)    specs.push(`${a.areaM2} m²`);
    const lines = [
      `*${p.name}*`,
      a.priceLabel || ('$' + (p.price_cents / 100).toLocaleString('es-MX')),
      specs.length ? specs.join(' · ') : null,
      a.zone ? `📍 ${a.zone}` : null,
      '',
      '📋 Ficha completa con fotos:',
      link,
    ].filter(Boolean);
    try {
      // Foto adjunta solo si está habilitado (el sandbox de WhatsApp no soporta
      // media → el mensaje fallaría). En el sandbox la foto llega vía el preview
      // Open Graph del link. En producción (sender aprobado): WHATSAPP_MEDIA=on.
      const withMedia = process.env.WHATSAPP_MEDIA === 'on' && cover;
      // Envío RASTREADO: solo contamos como enviado si NO rebotó (p.ej. 63016).
      // Con plantilla aprobada (env) → cold contact entrega; si no, freeform.
      const tmpl = process.env.TWILIO_WA_TEMPLATE_PROPERTY;
      const r = await sendWhatsAppTracked(twilio, tmpl
        ? { from: fromNum, to: waTo, contentSid: tmpl,
            contentVariables: { '1': p.name, '2': a.priceLabel || ('$' + (p.price_cents / 100).toLocaleString('es-MX')), '3': link } }
        : { from: fromNum, to: waTo, body: lines.join('\n'), mediaUrl: withMedia ? cover : undefined });
      if (r.ok) { sent++; enviadas.push(p.name); }
      else log.warn(`[sendPropertyInfo] WhatsApp NO entregado (${r.status}/err ${r.errorCode}) → ${waTo}`);
    } catch (e) {
      log.warn('[sendPropertyInfo] WhatsApp falló:', e.message);
    }
  }

  return {
    success: true,
    whatsapp_sent: sent > 0,
    speech: sent
      ? `Te estoy enviando ${sent === 1 ? 'la ficha' : 'las fichas'} por WhatsApp con fotos y todos los datos; si no te llega en un momento, avísame. ¿Te gustaría agendar una visita?`
      : 'No pude enviarte la ficha por WhatsApp en este momento. ¿Quieres que te la mande a otro número o te paso los datos por aquí?',
    collectedData: { propertyInterest: enviadas[0] || products[0].name },
  };
}

module.exports = { executeToolCall };
