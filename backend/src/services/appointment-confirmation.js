'use strict';

/**
 * AppointmentConfirmation — Reducción de no-shows
 *
 * Cuando un paciente responde por WhatsApp a un recordatorio de cita, este
 * módulo detecta si confirma o cancela, y actualiza la cita. Se invoca ANTES
 * del agente IA en el webhook de Meta: si el mensaje corresponde a una
 * confirmación pendiente, se procesa aquí y NO se pasa al agente (evita que
 * el LLM "interprete" un simple "sí" de forma ambigua).
 *
 * Flujo:
 *   1. ReminderWorker envía recordatorio 24h pidiendo CONFIRMAR / CANCELAR.
 *   2. Paciente responde → este módulo busca su cita pendiente.
 *   3. Si confirma → status confirmation_confirmed, libera al equipo de llamar.
 *   4. Si cancela → status cancelled, libera el horario.
 */

// ── Detección de intención (texto + botones interactivos) ────────────────────

// Nota: se evita \b junto a vocales acentuadas (í, é) porque en regex JS sin
// flag 'u' no produce frontera de palabra correcta. Se delimita con \s, puntuación o anclas.
const CONFIRM_PATTERNS = [
  /\bconfirm(o|ar|ada?|amos)?\b/i,
  /(^|[\s,.!¡])s[ií]($|[\s,.!])/i,                       // "sí" / "si" como palabra
  /\b(sip|claro|por\s+supuesto|asistir[ée]|ah[ií]\s+estar[ée]|ah[ií]\s+nos\s+vemos)/i,
  /\b(ok|okey|okay|de\s+acuerdo|perfecto|va|sale)\b/i,
  /👍|✅|🙌/,
];

const CANCEL_PATTERNS = [
  /\bcancel(a|ar|o|ada?|en)?/i,
  // Negaciones: "no podré", "no voy", "no confirmo", "no puedo", "no asistiré"
  /\bno\b[\s,]{0,3}(podr|voy|asist|puedo|alcanzo|confirm|ir[ée]?|llego)/i,
  /\b(reagendar|reprogramar|cambiar\s+(la\s+)?cita|otro\s+d[ií]a|otra\s+fecha|posponer)/i,
];

/**
 * Detecta la intención de confirmación de un mensaje.
 * @param {string} text
 * @returns {'confirm'|'cancel'|null}
 */
function detectConfirmationIntent(text) {
  if (!text || typeof text !== 'string') return null;
  const t = text.trim().toLowerCase();
  if (!t) return null;

  // Cancelar tiene prioridad: "no confirmo" debe leerse como cancelación
  if (CANCEL_PATTERNS.some(p => p.test(t))) return 'cancel';
  if (CONFIRM_PATTERNS.some(p => p.test(t))) return 'confirm';
  return null;
}

/**
 * Busca la cita pendiente de confirmación más próxima para un teléfono.
 * Solo considera citas futuras a las que ya se les pidió confirmación.
 * @returns {object|null} la fila de la cita o null
 */
async function findPendingAppointment(db, tenantId, patientPhone) {
  // El teléfono entrante puede venir en distintos formatos según el proveedor:
  // Twilio envía E.164 (+5215...), Meta envía solo dígitos (5215...), y México
  // a veces añade un "1" tras el código de país. Se compara por los últimos 10
  // dígitos (número nacional) para ser robusto a esas variaciones.
  const r = await db.query(
    `SELECT a.id, a.scheduled_at, a.patient_name, a.tenant_id,
            t.settings AS tenant_settings, t.name AS tenant_name,
            d.name AS doctor_name, st.name AS service_name
     FROM appointments a
     JOIN tenants t ON t.id = a.tenant_id
     LEFT JOIN doctors d ON d.id = a.doctor_id
     LEFT JOIN service_types st ON st.id = a.service_type_id
     WHERE a.tenant_id = $1
       AND RIGHT(regexp_replace(a.patient_phone, '[^0-9]', '', 'g'), 10)
         = RIGHT(regexp_replace($2,             '[^0-9]', '', 'g'), 10)
       AND a.confirmation_status = 'pending'
       AND a.confirmation_requested_at IS NOT NULL
       AND a.scheduled_at > NOW()
       AND a.status = 'confirmed'
     ORDER BY a.scheduled_at ASC
     LIMIT 1`,
    [tenantId, patientPhone]
  );
  return r.rows[0] || null;
}

/**
 * Procesa una posible respuesta de confirmación.
 * @returns {{ handled: boolean, reply?: string, action?: 'confirm'|'cancel' }}
 *   handled=false → no había confirmación pendiente o el mensaje no es de confirmación;
 *   el llamador debe pasar el mensaje al agente IA normalmente.
 */
async function handleConfirmationReply(db, { tenantId, fromPhone, text }) {
  const intent = detectConfirmationIntent(text);
  if (!intent) return { handled: false };

  const appt = await findPendingAppointment(db, tenantId, fromPhone);
  if (!appt) return { handled: false }; // no hay cita pendiente → que lo maneje el agente

  const scheduledStr = new Date(appt.scheduled_at).toLocaleString('es-MX', {
    timeZone: 'America/Mexico_City',
    weekday: 'long', day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit',
  });
  const name = appt.patient_name ? appt.patient_name.split(' ')[0] : '';

  if (intent === 'confirm') {
    await db.query(
      `UPDATE appointments
       SET confirmation_status = 'confirmed', confirmed_at = NOW(), updated_at = NOW()
       WHERE id = $1`,
      [appt.id]
    );
    const reply =
      `✅ ¡Gracias${name ? ' ' + name : ''}! Tu cita queda *confirmada* para:\n` +
      `📅 ${scheduledStr}` +
      (appt.doctor_name ? `\n👨‍⚕️ Dr. ${appt.doctor_name}` : '') +
      `\n\n¡Te esperamos! Si necesitas cambiarla, responde *CANCELAR*.`;
    return { handled: true, reply, action: 'confirm', appointmentId: appt.id };
  }

  // intent === 'cancel'
  await db.query(
    `UPDATE appointments
     SET confirmation_status = 'cancelled', status = 'cancelled',
         cancelled_at = NOW(), updated_at = NOW()
     WHERE id = $1`,
    [appt.id]
  );
  const reply =
    `Entendido${name ? ' ' + name : ''}, tu cita del ${scheduledStr} ha sido *cancelada*. ` +
    `Cuando quieras reagendar, escríbenos y con gusto te damos nuevos horarios. 😊`;
  return { handled: true, reply, action: 'cancel', appointmentId: appt.id };
}

module.exports = {
  detectConfirmationIntent,
  findPendingAppointment,
  handleConfirmationReply,
};
