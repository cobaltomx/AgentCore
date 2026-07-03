'use strict';

/**
 * ReminderWorker — Recordatorios de citas y reactivación de pacientes
 *
 * Corre cada 15 minutos (integrado en CampaignWorker o como job independiente)
 *
 * Flujos:
 * 1. Recordatorio 24h antes  → WhatsApp con instrucciones de prep
 * 2. Recordatorio 2h antes   → WhatsApp de confirmación
 * 3. Post-consulta           → WhatsApp con instrucciones post (3h después)
 * 4. Reactivación semestral  → Pacientes sin cita en 6 meses → Campaña outbound
 */

const { getTwilioClient, getTwilioFrom } = require('../twilio-client');

class ReminderWorker {
  constructor({ db }) {
    this.db = db;
  }

  async runTick() {
    try {
      await this.sendUpcomingReminders();
      await this.sendPostConsultaInstructions();
      // La reactivación se ejecuta 1 vez al día para no sobrecargar
      const now = new Date();
      if (now.getHours() === 10 && now.getMinutes() < 15) {
        await this.sendReactivacionCampaign();
      }
    } catch (err) {
      console.error('[ReminderWorker] Error en tick:', err.message);
    }
  }

  // ── 1 & 2. Recordatorios previos ─────────────────────────────────────────

  async sendUpcomingReminders() {
    const now = new Date();

    // Citas en las próximas 23–25 h (recordatorio 24h)
    const in24h_start = new Date(now.getTime() + 23 * 3600_000);
    const in24h_end   = new Date(now.getTime() + 25 * 3600_000);

    // Citas en la próximas 1.5–2.5 h (recordatorio 2h)
    const in2h_start  = new Date(now.getTime() + 90  * 60_000);
    const in2h_end    = new Date(now.getTime() + 150 * 60_000);

    // Recordatorio 24h
    const r24 = await this.db.query(
      `SELECT a.*, st.prep_instructions, st.name AS service_name,
              d.name AS doctor_name, t.settings AS tenant_settings, t.name AS tenant_name
       FROM appointments a
       JOIN tenants t ON t.id = a.tenant_id
       LEFT JOIN service_types st ON st.id = a.service_type_id
       LEFT JOIN doctors d ON d.id = a.doctor_id
       WHERE a.status = 'confirmed'
         AND a.reminder_24h_sent = false
         AND a.patient_phone IS NOT NULL
         AND a.scheduled_at BETWEEN $1 AND $2`,
      [in24h_start.toISOString(), in24h_end.toISOString()]
    );

    for (const appt of r24.rows) {
      await this._sendReminder(appt, '24h');
    }

    // Recordatorio 2h
    const r2 = await this.db.query(
      `SELECT a.*, st.prep_instructions, st.name AS service_name,
              d.name AS doctor_name, t.settings AS tenant_settings, t.name AS tenant_name
       FROM appointments a
       JOIN tenants t ON t.id = a.tenant_id
       LEFT JOIN service_types st ON st.id = a.service_type_id
       LEFT JOIN doctors d ON d.id = a.doctor_id
       WHERE a.status = 'confirmed'
         AND a.reminder_2h_sent = false
         AND a.patient_phone IS NOT NULL
         AND a.scheduled_at BETWEEN $1 AND $2`,
      [in2h_start.toISOString(), in2h_end.toISOString()]
    );

    for (const appt of r2.rows) {
      await this._sendReminder(appt, '2h');
    }
  }

  async _sendReminder(appt, type) {
    try {
      const settings = appt.tenant_settings || {};
      const twilio   = getTwilioClient(settings);
      const fromNum  = getTwilioFrom(settings);

      const scheduledAt = new Date(appt.scheduled_at);
      const displayTime = scheduledAt.toLocaleString('es-MX', {
        timeZone:   'America/Mexico_City',
        weekday:    'long',
        day:        'numeric',
        month:      'long',
        hour:       '2-digit',
        minute:     '2-digit',
      });

      let body = '';
      if (type === '24h') {
        body = `🦷 *Recordatorio de cita — ${appt.tenant_name}*\n\n`;
        body += `Hola ${appt.patient_name || 'estimado paciente'}, le recordamos su cita`;
        if (appt.service_name) body += ` de *${appt.service_name}*`;
        if (appt.doctor_name)  body += ` con el *Dr. ${appt.doctor_name}*`;
        body += `\n📅 *${displayTime}*\n\n`;
        if (appt.prep_instructions) {
          body += `📋 *Preparación:* ${appt.prep_instructions}\n\n`;
        }
        // Solicitud de confirmación (reducción de no-shows)
        body += `Por favor confírmenos su asistencia:\n`;
        body += `✅ Responda *CONFIRMAR* para confirmar\n`;
        body += `❌ Responda *CANCELAR* si no podrá asistir\n\n`;
        body += `¡Le esperamos! 😊`;
      } else {
        // 2h
        body = `⏰ *Su cita es en 2 horas — ${appt.tenant_name}*\n\n`;
        body += `Recordatorio: ${displayTime}`;
        if (appt.doctor_name) body += ` — Dr. ${appt.doctor_name}`;
        body += `.\n\nRecuerde llegar 10 minutos antes. ¡Le esperamos!`;
      }

      const msg = await twilio.messages.create({
        body,
        from: `whatsapp:${fromNum}`,
        to:   `whatsapp:${appt.patient_phone}`,
      });

      // Marcar como enviado. En el 24h, además abrir la ventana de confirmación.
      const col = type === '24h' ? 'reminder_24h_sent' : 'reminder_2h_sent';
      if (type === '24h') {
        await this.db.query(
          `UPDATE appointments
           SET reminder_24h_sent = true,
               confirmation_requested_at = COALESCE(confirmation_requested_at, NOW())
           WHERE id = $1`,
          [appt.id]
        );
      } else {
        await this.db.query(`UPDATE appointments SET ${col}=true WHERE id=$1`, [appt.id]);
      }

      // Registrar en historial
      await this.db.query(
        `INSERT INTO appointment_reminders (appointment_id, tenant_id, reminder_type, channel, message_sid)
         VALUES ($1,$2,$3,'whatsapp',$4)`,
        [appt.id, appt.tenant_id, type, msg.sid]
      );

      console.log(`[ReminderWorker] ${type} enviado — cita ${appt.id} → ${appt.patient_phone}`);

    } catch (err) {
      console.error(`[ReminderWorker] Error enviando ${type} para cita ${appt.id}:`, err.message);
      // Marcar como enviado (con error) para no reintentar indefinidamente
      const col = type === '24h' ? 'reminder_24h_sent' : 'reminder_2h_sent';
      await this.db.query(`UPDATE appointments SET ${col}=true WHERE id=$1`, [appt.id]).catch(() => {});
      await this.db.query(
        `INSERT INTO appointment_reminders (appointment_id, tenant_id, reminder_type, channel, status)
         VALUES ($1,$2,$3,'whatsapp','failed')`,
        [appt.id, appt.tenant_id, type]
      ).catch(() => {});
    }
  }

  // ── 3. Post-consulta ────────────────────────────────────────────────────────

  async sendPostConsultaInstructions() {
    const now         = new Date();
    const threeHAgo   = new Date(now.getTime() - 3  * 3600_000);
    const sixHAgo     = new Date(now.getTime() - 6  * 3600_000);

    const r = await this.db.query(
      `SELECT a.*, st.post_instructions, st.name AS service_name,
              d.name AS doctor_name, t.settings AS tenant_settings, t.name AS tenant_name
       FROM appointments a
       JOIN tenants t ON t.id = a.tenant_id
       LEFT JOIN service_types st ON st.id = a.service_type_id
       LEFT JOIN doctors d ON d.id = a.doctor_id
       WHERE a.status = 'confirmed'
         AND a.post_instr_sent = false
         AND a.patient_phone IS NOT NULL
         AND st.post_instructions IS NOT NULL
         AND a.scheduled_at BETWEEN $1 AND $2`,
      [sixHAgo.toISOString(), threeHAgo.toISOString()]
    );

    for (const appt of r.rows) {
      try {
        const settings = appt.tenant_settings || {};
        const twilio   = getTwilioClient(settings);
        const fromNum  = getTwilioFrom(settings);

        const body = `🦷 *Cuidados post-consulta — ${appt.tenant_name}*\n\n` +
          `Estimado/a ${appt.patient_name || 'paciente'}, esperamos que se sienta bien.\n\n` +
          `📋 *Indicaciones:*\n${appt.post_instructions}\n\n` +
          `Si tiene alguna molestia o pregunta, responda a este mensaje. ¡Que se recupere pronto! 💙`;

        await twilio.messages.create({
          body,
          from: `whatsapp:${fromNum}`,
          to:   `whatsapp:${appt.patient_phone}`,
        });

        await this.db.query('UPDATE appointments SET post_instr_sent=true WHERE id=$1', [appt.id]);
        await this.db.query(
          `INSERT INTO appointment_reminders (appointment_id, tenant_id, reminder_type, channel)
           VALUES ($1,$2,'post','whatsapp')`,
          [appt.id, appt.tenant_id]
        );

        console.log(`[ReminderWorker] Post-consulta enviado — cita ${appt.id}`);
      } catch (err) {
        console.error(`[ReminderWorker] Error post-consulta ${appt.id}:`, err.message);
        await this.db.query('UPDATE appointments SET post_instr_sent=true WHERE id=$1', [appt.id]).catch(() => {});
      }
    }
  }

  // ── 4. Reactivación de pacientes inactivos ────────────────────────────────

  async sendReactivacionCampaign() {
    const sixMonthsAgo = new Date(Date.now() - 180 * 86400_000);

    // Pacientes con última cita hace más de 6 meses y que tienen teléfono
    const r = await this.db.query(
      `SELECT DISTINCT ON (a.patient_phone, a.tenant_id)
              a.patient_name, a.patient_phone, a.tenant_id,
              t.settings AS tenant_settings, t.name AS tenant_name,
              MAX(a.scheduled_at) AS last_appt
       FROM appointments a
       JOIN tenants t ON t.id = a.tenant_id
       WHERE a.patient_phone IS NOT NULL
         AND a.status = 'confirmed'
       GROUP BY a.patient_phone, a.tenant_id, a.patient_name, t.settings, t.name
       HAVING MAX(a.scheduled_at) < $1
         AND MAX(a.scheduled_at) > NOW() - INTERVAL '24 months'
       LIMIT 50`,
      [sixMonthsAgo.toISOString()]
    );

    for (const patient of r.rows) {
      // Verificar que no haya cita futura ya agendada
      const futureAppt = await this.db.query(
        `SELECT id FROM appointments
         WHERE patient_phone = $1 AND tenant_id = $2 AND scheduled_at > NOW()
           AND status != 'cancelled' LIMIT 1`,
        [patient.patient_phone, patient.tenant_id]
      );
      if (futureAppt.rows[0]) continue; // ya tiene cita, saltar

      // Verificar que no se haya enviado reactivación en las últimas 4 semanas
      const recentReminder = await this.db.query(
        `SELECT ar.id FROM appointment_reminders ar
         JOIN appointments a ON a.id = ar.appointment_id
         WHERE a.patient_phone = $1 AND a.tenant_id = $2
           AND ar.reminder_type = 'reactivacion'
           AND ar.sent_at > NOW() - INTERVAL '4 weeks'
         LIMIT 1`,
        [patient.patient_phone, patient.tenant_id]
      );
      if (recentReminder.rows[0]) continue;

      try {
        const settings = patient.tenant_settings || {};
        const twilio   = getTwilioClient(settings);
        const fromNum  = getTwilioFrom(settings);

        const body = `🦷 *${patient.tenant_name} te recuerda*\n\n` +
          `Hola ${patient.patient_name || 'estimado paciente'} 😊\n\n` +
          `Han pasado varios meses desde tu última visita. La limpieza dental cada 6 meses es importante para mantener tu salud bucal.\n\n` +
          `¿Te gustaría agendar tu cita de limpieza? Responde *SÍ* y te contactamos para darte los horarios disponibles.\n\n` +
          `_Si ya tienes cita con nosotros, ignora este mensaje._`;

        await twilio.messages.create({
          body,
          from: `whatsapp:${fromNum}`,
          to:   `whatsapp:${patient.patient_phone}`,
        });

        // Registrar para no re-enviar
        // Usamos una cita ficticia o la última cita real — simplificamos con un appointment_id dummy
        const lastAppt = await this.db.query(
          `SELECT id FROM appointments WHERE patient_phone=$1 AND tenant_id=$2 ORDER BY scheduled_at DESC LIMIT 1`,
          [patient.patient_phone, patient.tenant_id]
        );
        if (lastAppt.rows[0]) {
          await this.db.query(
            `INSERT INTO appointment_reminders (appointment_id, tenant_id, reminder_type, channel)
             VALUES ($1,$2,'reactivacion','whatsapp')`,
            [lastAppt.rows[0].id, patient.tenant_id]
          );
        }

        console.log(`[ReminderWorker] Reactivación enviada → ${patient.patient_phone} (tenant: ${patient.tenant_id})`);

      } catch (err) {
        console.error(`[ReminderWorker] Error reactivación ${patient.patient_phone}:`, err.message);
      }
    }
  }
}

module.exports = ReminderWorker;
