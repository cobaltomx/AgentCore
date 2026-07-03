'use strict';
const { z } = require('zod');
const { toCanonicalSchedule } = require('../../services/scheduling/doctor-slots');

const dayConfigSchema = z.object({
  active:      z.boolean().optional(),
  start:       z.string().optional(),
  end:         z.string().optional(),
  break_start: z.string().nullable().optional(),
  break_end:   z.string().nullable().optional(),
});

const doctorSchema = z.object({
  name:            z.string().min(2).max(200),
  specialty:       z.string().max(100).optional(),
  phone:           z.string().max(30).optional(),
  email:           z.string().email().optional().or(z.literal('')).or(z.null()),
  is_active:       z.boolean().optional(),
  license_number:  z.string().max(50).optional().nullable(),
  schedule_config: z.record(dayConfigSchema).optional(),
  color:           z.string().max(20).optional(),
  avatar_initials: z.string().max(4).optional(),
  avatar_url:      z.string().max(500).optional().nullable(),
  sort_order:      z.number().int().optional(),
  room:            z.string().max(80).optional().nullable(),          // consultorio asignado
  service_type_ids: z.array(z.string().uuid()).optional(),             // servicios que brinda
});

// Sincroniza, desde el lado del DOCTOR, en qué service_types aparece como
// proveedor (service_types.doctor_ids es la fuente de verdad del link M:N).
async function syncDoctorServices(db, tenantId, doctorId, serviceIds) {
  serviceIds = Array.isArray(serviceIds) ? serviceIds : [];
  // Quitar al doctor de TODOS los servicios donde estaba…
  await db.query(
    `UPDATE service_types SET doctor_ids = array_remove(COALESCE(doctor_ids,'{}'), $2::uuid)
     WHERE tenant_id=$1 AND $2 = ANY(COALESCE(doctor_ids,'{}'))`,
    [tenantId, doctorId]
  );
  if (serviceIds.length) {
    // …y agregarlo a los seleccionados
    await db.query(
      `UPDATE service_types SET doctor_ids = array_append(COALESCE(doctor_ids,'{}'), $3::uuid)
       WHERE tenant_id=$1 AND id = ANY($2::uuid[]) AND NOT ($3 = ANY(COALESCE(doctor_ids,'{}')))`,
      [tenantId, serviceIds, doctorId]
    );
    // Si algún servicio quedó sin default_doctor_id, ponerlo
    await db.query(
      `UPDATE service_types SET default_doctor_id = doctor_ids[1]
       WHERE tenant_id=$1 AND id = ANY($2::uuid[]) AND default_doctor_id IS NULL AND array_length(doctor_ids,1) > 0`,
      [tenantId, serviceIds]
    );
  }
}

async function doctorsRoutes(app) {

  // GET /api/v1/doctors — incluye los servicios que brinda cada doctor (M:N)
  app.get('/', { onRequest: [app.requireTenant] }, async (req) => {
    const { active_only } = req.query;
    let q = `
      SELECT d.*,
        COALESCE((SELECT array_agg(st.id)   FROM service_types st WHERE st.tenant_id=d.tenant_id AND d.id = ANY(st.doctor_ids)), '{}') AS service_type_ids,
        COALESCE((SELECT array_agg(st.name ORDER BY st.sort_order) FROM service_types st WHERE st.tenant_id=d.tenant_id AND d.id = ANY(st.doctor_ids)), '{}') AS service_names
      FROM doctors d WHERE d.tenant_id = $1`;
    if (active_only === 'true') q += ' AND d.is_active = true';
    q += ' ORDER BY d.sort_order ASC, d.name ASC';
    const r = await app.db.query(q, [req.tenant.id]);
    return r.rows;
  });

  // GET /api/v1/doctors/:id
  app.get('/:id', { onRequest: [app.requireTenant] }, async (req, reply) => {
    const r = await app.db.query(
      'SELECT * FROM doctors WHERE id = $1 AND tenant_id = $2',
      [req.params.id, req.tenant.id]
    );
    if (!r.rows[0]) return reply.code(404).send({ error: 'Doctor no encontrado' });
    return r.rows[0];
  });

  // GET /api/v1/doctors/:id/availability?date=YYYY-MM-DD
  app.get('/:id/availability', { onRequest: [app.requireTenant] }, async (req, reply) => {
    const { date, days_ahead = 5 } = req.query;
    const r = await app.db.query(
      'SELECT schedule_config FROM doctors WHERE id = $1 AND tenant_id = $2 AND is_active = true',
      [req.params.id, req.tenant.id]
    );
    if (!r.rows[0]) return reply.code(404).send({ error: 'Doctor no encontrado' });

    // Usa el MISMO generador que el bot (doctor-slots) → respeta ambos formatos
    // de schedule_config y los descansos. Antes solo entendía el formato array.
    const { generateDoctorSlots } = require('../../services/scheduling/doctor-slots');
    const tz = 'America/Mexico_City';
    const slots = generateDoctorSlots(r.rows[0].schedule_config || {}, {
      tz, slotMins: 30, maxDays: parseInt(days_ahead) || 30,
    }).map(s => ({
      time:    s.time,
      display: new Date(s.time).toLocaleString('es-MX', {
        timeZone: tz, weekday: 'long', month: 'short', day: 'numeric',
        hour: '2-digit', minute: '2-digit',
      }),
    }));

    // Filter already booked
    const booked = await app.db.query(
      `SELECT scheduled_at FROM appointments
       WHERE doctor_id = $1 AND tenant_id = $2
         AND status NOT IN ('cancelled')
         AND scheduled_at >= NOW()`,
      [req.params.id, req.tenant.id]
    );
    const bookedSet = new Set(booked.rows.map(r =>
      new Date(r.scheduled_at).toISOString().slice(0, 16)
    ));

    const free = slots.filter(s =>
      !bookedSet.has(new Date(s.time).toISOString().slice(0, 16))
    );

    return { slots: free.slice(0, 20), doctor_id: req.params.id };
  });

  // POST /api/v1/doctors
  app.post('/', { onRequest: [app.requireAdmin] }, async (req, reply) => {
    const parsed = doctorSchema.safeParse(req.body);
    if (!parsed.success) return reply.code(400).send({ error: 'Datos inválidos', details: parsed.error.flatten() });
    const d = parsed.data;

    // Limit per plan
    const count = await app.db.query('SELECT COUNT(*) FROM doctors WHERE tenant_id = $1', [req.tenant.id]);
    if (parseInt(count.rows[0].count) >= 20) {
      return reply.code(400).send({ error: 'Límite de 20 doctores por tenant' });
    }

    const initials = d.avatar_initials || d.name.split(' ').map(w => w[0]).join('').slice(0,2).toUpperCase();

    const r = await app.db.query(
      `INSERT INTO doctors (tenant_id, name, specialty, phone, email, is_active, license_number, schedule_config, color, avatar_initials, avatar_url, sort_order, room)
       VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12,$13) RETURNING *`,
      [req.tenant.id, d.name, d.specialty||null, d.phone||null, d.email||null,
       d.is_active ?? true, d.license_number||null, JSON.stringify(toCanonicalSchedule(d.schedule_config || {})),
       d.color||'#696cff', initials, d.avatar_url||null, d.sort_order||0, d.room||null]
    );
    if (d.service_type_ids !== undefined) {
      await syncDoctorServices(app.db, req.tenant.id, r.rows[0].id, d.service_type_ids);
    }
    return reply.code(201).send(r.rows[0]);
  });

  // PATCH /api/v1/doctors/:id
  app.patch('/:id', { onRequest: [app.requireAdmin] }, async (req, reply) => {
    const parsed = doctorSchema.partial().safeParse(req.body);
    if (!parsed.success) return reply.code(400).send({ error: 'Datos inválidos', details: parsed.error.flatten() });
    const d = parsed.data;

    const fields = [];
    const vals   = [];
    let   idx    = 1;

    if (d.name            !== undefined) { fields.push(`name=$${idx++}`);            vals.push(d.name); }
    if (d.specialty       !== undefined) { fields.push(`specialty=$${idx++}`);       vals.push(d.specialty); }
    if (d.phone           !== undefined) { fields.push(`phone=$${idx++}`);           vals.push(d.phone); }
    if (d.email           !== undefined) { fields.push(`email=$${idx++}`);           vals.push(d.email); }
    if (d.is_active       !== undefined) { fields.push(`is_active=$${idx++}`);       vals.push(d.is_active); }
    if (d.license_number  !== undefined) { fields.push(`license_number=$${idx++}`);  vals.push(d.license_number); }
    if (d.schedule_config !== undefined) { fields.push(`schedule_config=$${idx++}`); vals.push(JSON.stringify(toCanonicalSchedule(d.schedule_config))); }
    if (d.color           !== undefined) { fields.push(`color=$${idx++}`);           vals.push(d.color); }
    if (d.avatar_initials !== undefined) { fields.push(`avatar_initials=$${idx++}`); vals.push(d.avatar_initials); }
    if (d.avatar_url      !== undefined) { fields.push(`avatar_url=$${idx++}`);      vals.push(d.avatar_url); }
    if (d.sort_order      !== undefined) { fields.push(`sort_order=$${idx++}`);      vals.push(d.sort_order); }
    if (d.room            !== undefined) { fields.push(`room=$${idx++}`);            vals.push(d.room); }

    if (!fields.length && d.service_type_ids === undefined)
      return reply.code(400).send({ error: 'Sin campos para actualizar' });

    let doctor;
    if (fields.length) {
      vals.push(req.params.id, req.tenant.id);
      const r = await app.db.query(
        `UPDATE doctors SET ${fields.join(', ')}, updated_at=now()
         WHERE id=$${idx} AND tenant_id=$${idx+1} RETURNING *`,
        vals
      );
      if (!r.rows[0]) return reply.code(404).send({ error: 'Doctor no encontrado' });
      doctor = r.rows[0];
    } else {
      const r = await app.db.query('SELECT * FROM doctors WHERE id=$1 AND tenant_id=$2', [req.params.id, req.tenant.id]);
      if (!r.rows[0]) return reply.code(404).send({ error: 'Doctor no encontrado' });
      doctor = r.rows[0];
    }

    if (d.service_type_ids !== undefined) {
      await syncDoctorServices(app.db, req.tenant.id, req.params.id, d.service_type_ids);
    }
    return doctor;
  });

  // DELETE /api/v1/doctors/:id  (soft delete)
  app.delete('/:id', { onRequest: [app.requireAdmin] }, async (req, reply) => {
    const r = await app.db.query(
      'UPDATE doctors SET is_active=false, updated_at=now() WHERE id=$1 AND tenant_id=$2 RETURNING id',
      [req.params.id, req.tenant.id]
    );
    if (!r.rows[0]) return reply.code(404).send({ error: 'Doctor no encontrado' });
    return { ok: true };
  });
}

module.exports = doctorsRoutes;
