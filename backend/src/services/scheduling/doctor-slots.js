'use strict';

/**
 * Generación de slots a partir del `schedule_config` que el cliente configura
 * en el dashboard (horarios de atención por doctor/profesional). Es la fuente
 * AUTORITATIVA de disponibilidad para clínica/consultorio (NO un EA global).
 *
 * Soporta los DOS formatos que conviven en BD:
 *   A) Objeto (canónico, lo escribe el editor actual):
 *      { mon: { active:true, start:"09:00", end:"18:00",
 *               break_start:"14:00", break_end:"15:00" }, ... }
 *   B) Array de rangos (legado):
 *      { mon: [{ start:"09:00", end:"14:00" }, { start:"16:00", end:"19:00" }] }
 */

const DOW = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

/** Normaliza el horario de UN día a lista de intervalos [{start,end}] (HH:MM). */
function parseDaySchedule(day) {
  if (!day) return [];

  // Formato B: array de rangos
  if (Array.isArray(day)) {
    return day
      .filter(r => r && r.start && r.end)
      .map(r => ({ start: r.start, end: r.end }));
  }

  // Formato A: objeto con active + break opcional
  if (typeof day === 'object' && day.start && day.end) {
    if (day.active === false) return [];
    if (day.break_start && day.break_end) {
      return [
        { start: day.start,       end: day.break_start },
        { start: day.break_end,   end: day.end },
      ];
    }
    return [{ start: day.start, end: day.end }];
  }
  return [];
}

/** Convierte cualquier formato al CANÓNICO objeto (para unificar storage). */
function toCanonicalSchedule(scheduleConfig = {}) {
  const out = {};
  for (const k of DOW) {
    const intervals = parseDaySchedule(scheduleConfig[k]);
    if (!intervals.length) { out[k] = { active: false }; continue; }
    const first = intervals[0];
    const last  = intervals[intervals.length - 1];
    out[k] = (intervals.length >= 2)
      ? { active: true, start: first.start, end: last.end, break_start: first.end, break_end: last.start }
      : { active: true, start: first.start, end: first.end };
  }
  return out;
}

// Crea un Date a la hora LOCAL del negocio (mismo enfoque tz-safe que generateFixedSlots).
function localSlotDate(dateStr, hour, min, tz) {
  const nominalUtc = new Date(`${dateStr}T${String(hour).padStart(2, '0')}:${String(min).padStart(2, '0')}:00Z`);
  const localH = parseInt(nominalUtc.toLocaleString('en-US', { timeZone: tz, hour: '2-digit', hour12: false }));
  let shift = hour - localH;
  if (shift > 12) shift -= 24;
  if (shift < -12) shift += 24;
  return new Date(nominalUtc.getTime() + shift * 3600000);
}

/**
 * Genera los slots de un doctor según su schedule_config, buscando hasta
 * `maxDays` hacia adelante (las agendas médicas pueden estar llenas por
 * semanas/meses → hay que mirar lejos). Devuelve slots cronológicos SIN filtrar
 * ocupados (eso lo hace el caller cruzando contra `appointments`).
 */
function generateDoctorSlots(scheduleConfig = {}, { tz = 'America/Mexico_City', slotMins = 30, maxDays = 90 } = {}) {
  const slots = [];
  const now = new Date();
  const dowMap = { Sun: 0, Mon: 1, Tue: 2, Wed: 3, Thu: 4, Fri: 5, Sat: 6 };

  for (let d = 1; d <= maxDays; d++) {
    const dayUtc  = new Date(now.getTime() + d * 86400_000);
    const dateStr = dayUtc.toLocaleDateString('sv-SE', { timeZone: tz });          // "YYYY-MM-DD"
    const dowStr  = dayUtc.toLocaleDateString('en-US', { timeZone: tz, weekday: 'short' });
    const dayName = DOW[dowMap[dowStr] ?? dayUtc.getDay()];

    for (const iv of parseDaySchedule(scheduleConfig[dayName])) {
      const [sh, sm] = iv.start.split(':').map(Number);
      const [eh, em] = iv.end.split(':').map(Number);
      const endMin = eh * 60 + em;
      for (let t = sh * 60 + sm; t + slotMins <= endMin; t += slotMins) {
        const slotDate = localSlotDate(dateStr, Math.floor(t / 60), t % 60, tz);
        if (slotDate <= now) continue;
        slots.push({ time: slotDate.toISOString(), date: dateStr, fixed: true });
      }
    }
  }
  return slots;
}

module.exports = { parseDaySchedule, toCanonicalSchedule, generateDoctorSlots, DOW };
