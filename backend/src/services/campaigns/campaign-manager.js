'use strict';

/**
 * Campaign Manager — Fase 7
 *
 * Responsabilidades:
 * - Crear campañas (manual o con trigger automático)
 * - Importar contactos desde CSV o desde leads de la DB
 * - Gestionar el estado de la campaña
 * - Disparar triggers automáticos (leads sin contactar)
 * - Actualizar estadísticas en tiempo real
 */

const { parse: csvParse } = require('csv-parse/sync');

class CampaignManager {
  constructor({ db }) {
    this.db = db;
  }

  /**
   * Crear nueva campaña
   */
  async createCampaign({
    tenantId, agentId, name, description, channel = 'voice',
    triggerType = 'manual', script, goal = 'follow_up',
    waTemplateName, waTemplateLang = 'es_MX',
    allowedHoursStart = 9, allowedHoursEnd = 19,
    allowedDays = [1,2,3,4,5],
    triggerConfig = {}, callsPerHour = 10,
    maxAttempts = 2, scheduledAt = null,
  }) {
    const result = await this.db.query(
      `INSERT INTO campaigns
         (tenant_id, agent_id, name, description, channel, trigger_type,
          status, script, goal, wa_template_name, wa_template_lang,
          allowed_hours_start, allowed_hours_end, allowed_days,
          trigger_config, calls_per_hour, max_attempts, scheduled_at)
       VALUES ($1,$2,$3,$4,$5,$6,'draft',$7,$8,$9,$10,$11,$12,$13,$14,$15,$16,$17)
       RETURNING *`,
      [
        tenantId, agentId, name, description || null, channel, triggerType,
        script, goal, waTemplateName || null, waTemplateLang,
        allowedHoursStart, allowedHoursEnd, allowedDays,
        JSON.stringify(triggerConfig), callsPerHour, maxAttempts,
        scheduledAt || null,
      ]
    );
    return result.rows[0];
  }

  /**
   * Importar contactos desde CSV
   * Columnas esperadas: name, phone, email (opcionales: cualquier campo extra)
   *
   * @param {string} campaignId
   * @param {string} csvContent - contenido del CSV como string
   * @returns {Object} { imported, skipped, errors }
   */
  async importContactsFromCsv(campaignId, csvContent) {
    const campaign = await this._getCampaign(campaignId);
    if (!campaign) throw new Error('Campaña no encontrada');

    let records;
    try {
      records = csvParse(csvContent, {
        columns:          true,
        skip_empty_lines: true,
        trim:             true,
        bom:              true, // manejar UTF-8 BOM
      });
    } catch (err) {
      throw new Error(`Error parseando CSV: ${err.message}`);
    }

    const results = { imported: 0, skipped: 0, errors: [] };

    for (const row of records) {
      // Normalizar nombres de columnas (case-insensitive)
      const normalized = {};
      for (const [key, val] of Object.entries(row)) {
        normalized[key.toLowerCase().trim()] = val;
      }

      const phone = normalized.phone || normalized.telefono || normalized.tel || normalized.celular;
      if (!phone || phone.trim().length < 8) {
        results.skipped++;
        continue;
      }

      const name      = normalized.name || normalized.nombre || normalized.cliente || 'Sin nombre';
      const email     = normalized.email || normalized.correo || null;

      // Extraer campos custom (todo lo que no es phone/name/email)
      const customData = {};
      for (const [key, val] of Object.entries(normalized)) {
        if (!['phone','telefono','tel','celular','name','nombre','cliente','email','correo'].includes(key)) {
          customData[key] = val;
        }
      }

      try {
        // Buscar lead existente por teléfono
        const leadResult = await this.db.query(
          'SELECT id FROM leads WHERE tenant_id = $1 AND phone = $2 LIMIT 1',
          [campaign.tenant_id, phone.trim()]
        );

        await this.db.query(
          `INSERT INTO campaign_contacts
             (campaign_id, tenant_id, lead_id, name, phone, email, custom_data, next_attempt_at)
           VALUES ($1,$2,$3,$4,$5,$6,$7, NOW())
           ON CONFLICT DO NOTHING`,
          [
            campaignId,
            campaign.tenant_id,
            leadResult.rows[0]?.id || null,
            name.trim(),
            phone.trim(),
            email?.trim() || null,
            JSON.stringify(customData),
          ]
        );
        results.imported++;
      } catch (err) {
        results.errors.push({ phone, error: err.message });
      }
    }

    // Actualizar total de contactos
    await this._updateStats(campaignId);

    return results;
  }

  /**
   * Importar leads no convertidos como contactos de campaña
   * Para triggers automáticos
   *
   * @param {string} campaignId
   * @param {Object} filter - { leadStatus, daysWithoutContact }
   */
  async importFromLeads(campaignId, filter = {}) {
    const campaign = await this._getCampaign(campaignId);
    const {
      leadStatus         = ['new', 'contacted'],
      daysWithoutContact = 3,
    } = filter;

    const statusPlaceholders = leadStatus.map((_, i) => `$${i + 3}`).join(',');

    const result = await this.db.query(
      `INSERT INTO campaign_contacts
         (campaign_id, tenant_id, lead_id, name, phone, email, next_attempt_at)
       SELECT
         $1, l.tenant_id, l.id, l.name, l.phone, l.email, NOW()
       FROM leads l
       WHERE l.tenant_id = $2
         AND l.status IN (${statusPlaceholders})
         AND (l.updated_at < NOW() - INTERVAL '${parseInt(daysWithoutContact)} days'
              OR l.updated_at IS NULL)
         AND l.phone IS NOT NULL
         AND NOT EXISTS (
           SELECT 1 FROM campaign_contacts cc
           WHERE cc.campaign_id = $1 AND cc.phone = l.phone
         )
       ON CONFLICT DO NOTHING`,
      [campaignId, campaign.tenant_id, ...leadStatus]
    );

    await this._updateStats(campaignId);

    return { imported: result.rowCount };
  }

  /**
   * Activar campaña (cambiar a running)
   */
  async startCampaign(campaignId) {
    const campaign = await this._getCampaign(campaignId);
    if (!['draft', 'paused', 'scheduled'].includes(campaign.status)) {
      throw new Error(`No se puede iniciar una campaña en estado "${campaign.status}"`);
    }

    const contactCount = await this.db.query(
      'SELECT COUNT(*) FROM campaign_contacts WHERE campaign_id = $1 AND status = $2',
      [campaignId, 'pending']
    );

    if (parseInt(contactCount.rows[0].count) === 0) {
      throw new Error('La campaña no tiene contactos pendientes');
    }

    await this.db.query(
      `UPDATE campaigns SET status='running', started_at=NOW() WHERE id=$1`,
      [campaignId]
    );

    return { started: true, pendingContacts: parseInt(contactCount.rows[0].count) };
  }

  /**
   * Pausar campaña
   */
  async pauseCampaign(campaignId) {
    await this.db.query(
      `UPDATE campaigns SET status='paused' WHERE id=$1 AND status='running'`,
      [campaignId]
    );
    return { paused: true };
  }

  /**
   * Cancelar campaña
   */
  async cancelCampaign(campaignId) {
    await this.db.query(
      `UPDATE campaigns SET status='cancelled', completed_at=NOW() WHERE id=$1`,
      [campaignId]
    );
    return { cancelled: true };
  }

  /**
   * Obtener el siguiente contacto listo para ser llamado/enviado
   * Usa locking optimista para evitar que dos workers procesen el mismo contacto
   *
   * @param {string} campaignId
   * @returns {Object|null} contacto o null si no hay
   */
  async claimNextContact(campaignId) {
    const campaign = await this._getCampaign(campaignId);

    // Verificar que es horario permitido
    if (!this._isAllowedTime(campaign)) return null;

    // Atomic claim: seleccionar y lockear en una sola query
    const result = await this.db.query(
      `UPDATE campaign_contacts
       SET status='calling', locked_until=NOW() + INTERVAL '5 minutes',
           attempts=attempts+1, last_attempt_at=NOW()
       WHERE id = (
         SELECT id FROM campaign_contacts
         WHERE campaign_id = $1
           AND status = 'pending'
           AND (next_attempt_at IS NULL OR next_attempt_at <= NOW())
           AND (locked_until IS NULL OR locked_until < NOW())
           AND attempts < $2
         ORDER BY priority DESC, next_attempt_at ASC
         LIMIT 1
         FOR UPDATE SKIP LOCKED
       )
       RETURNING *`,
      [campaignId, campaign.max_attempts]
    );

    return result.rows[0] || null;
  }

  /**
   * Marcar contacto como completado con su resultado
   */
  async completeContact(contactId, outcome, outcomeData = {}, conversationId = null) {
    const result = await this.db.query(
      `UPDATE campaign_contacts
       SET status=$1, outcome=$2, outcome_data=$3,
           conversation_id=$4, locked_until=NULL
       WHERE id=$5
       RETURNING campaign_id, tenant_id`,
      [
        ['appointment_booked','lead_captured','converted'].includes(outcome) ? 'converted' : 'contacted',
        outcome,
        JSON.stringify(outcomeData),
        conversationId,
        contactId,
      ]
    );

    if (result.rows[0]) {
      await this._updateStats(result.rows[0].campaign_id);
    }
  }

  /**
   * Marcar contacto como fallido (no contestó, número inválido, etc.)
   * Si quedan intentos, programar reintento
   */
  async failContact(contactId, reason, retryAfterMinutes = 60) {
    const contact = await this.db.query(
      'SELECT * FROM campaign_contacts WHERE id=$1', [contactId]
    );
    const c = contact.rows[0];
    if (!c) return;

    const campaign = await this._getCampaign(c.campaign_id);
    const canRetry = c.attempts < campaign.max_attempts;

    await this.db.query(
      `UPDATE campaign_contacts
       SET status=$1, locked_until=NULL,
           next_attempt_at=$2,
           outcome_data=COALESCE(outcome_data,'{}') || $3::jsonb
       WHERE id=$4`,
      [
        canRetry ? 'pending' : 'failed',
        canRetry ? new Date(Date.now() + retryAfterMinutes * 60000) : null,
        JSON.stringify({ lastError: reason }),
        contactId,
      ]
    );

    await this._updateStats(c.campaign_id);
  }

  /**
   * Marcar opt-out (el contacto pidió no ser contactado)
   */
  async optOutContact(contactId) {
    const result = await this.db.query(
      `UPDATE campaign_contacts SET status='opted_out', locked_until=NULL WHERE id=$1
       RETURNING campaign_id, phone, tenant_id`,
      [contactId]
    );

    if (result.rows[0]) {
      // Marcar el lead como perdido también
      await this.db.query(
        `UPDATE leads SET status='lost', notes=CONCAT(COALESCE(notes,''), '\nOpt-out en campaña')
         WHERE phone=$1 AND tenant_id=$2`,
        [result.rows[0].phone, result.rows[0].tenant_id]
      );
      await this._updateStats(result.rows[0].campaign_id);
    }
  }

  /**
   * Verificar y disparar triggers automáticos de todas las campañas activas
   * Llamar desde un cron job cada hora
   */
  async processTriggers(tenantId = null) {
    const query = tenantId
      ? `SELECT * FROM campaigns WHERE trigger_type='auto' AND status='running' AND tenant_id=$1`
      : `SELECT * FROM campaigns WHERE trigger_type='auto' AND status='running'`;

    const campaigns = await this.db.query(query, tenantId ? [tenantId] : []);
    const results   = [];

    for (const campaign of campaigns.rows) {
      if (!this._isAllowedTime(campaign)) continue;

      const config = campaign.trigger_config || {};
      const { imported } = await this.importFromLeads(campaign.id, {
        leadStatus:         config.lead_status         || ['new'],
        daysWithoutContact: config.days_without_contact || 3,
      });

      results.push({ campaignId: campaign.id, name: campaign.name, newContacts: imported });
    }

    return results;
  }

  /**
   * Estadísticas de campañas del tenant
   */
  async getCampaignStats(tenantId) {
    const result = await this.db.query(
      `SELECT
         COUNT(*) FILTER (WHERE status='running')   AS running,
         COUNT(*) FILTER (WHERE status='completed') AS completed,
         SUM(total_contacts)                        AS total_contacts,
         SUM(contacted)                             AS total_contacted,
         SUM(converted)                             AS total_converted,
         CASE WHEN SUM(contacted) > 0
           THEN ROUND(SUM(converted)::numeric / SUM(contacted) * 100, 1)
           ELSE 0 END                               AS conversion_rate
       FROM campaigns WHERE tenant_id=$1`,
      [tenantId]
    );
    return result.rows[0];
  }

  // ─── Helpers privados ────────────────────────────────────────

  async _getCampaign(campaignId) {
    const result = await this.db.query('SELECT * FROM campaigns WHERE id=$1', [campaignId]);
    return result.rows[0];
  }

  async _updateStats(campaignId) {
    await this.db.query(
      `UPDATE campaigns SET
         total_contacts = (SELECT COUNT(*) FROM campaign_contacts WHERE campaign_id=$1),
         contacted  = (SELECT COUNT(*) FROM campaign_contacts WHERE campaign_id=$1 AND status IN ('contacted','converted','opted_out')),
         converted  = (SELECT COUNT(*) FROM campaign_contacts WHERE campaign_id=$1 AND status='converted'),
         failed     = (SELECT COUNT(*) FROM campaign_contacts WHERE campaign_id=$1 AND status='failed'),
         -- Auto-completar si todos procesados
         status = CASE
           WHEN status='running' AND (SELECT COUNT(*) FROM campaign_contacts WHERE campaign_id=$1 AND status='pending') = 0
           THEN 'completed'
           ELSE status
         END,
         completed_at = CASE
           WHEN status='running' AND (SELECT COUNT(*) FROM campaign_contacts WHERE campaign_id=$1 AND status='pending') = 0
           THEN NOW()
           ELSE completed_at
         END
       WHERE id=$1`,
      [campaignId]
    );
  }

  _isAllowedTime(campaign) {
    const now     = new Date();
    const tzOffset = -6; // America/Mexico_City (aproximado; en producción usar Intl)
    const mx      = new Date(now.getTime() + tzOffset * 3600000);
    const hour    = mx.getUTCHours();
    const day     = mx.getUTCDay(); // 0=dom

    const allowedDays  = campaign.allowed_days  || [1,2,3,4,5];
    const startHour    = campaign.allowed_hours_start ?? 9;
    const endHour      = campaign.allowed_hours_end   ?? 19;

    return allowedDays.includes(day) && hour >= startHour && hour < endHour;
  }
}

module.exports = CampaignManager;
