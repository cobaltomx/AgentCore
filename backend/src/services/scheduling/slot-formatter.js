'use strict';

/**
 * Slot Formatter — convierte slots en lenguaje natural para el agente de voz
 *
 * El agente no puede decir "2025-07-01T10:00:00-06:00" en voz.
 * Este módulo genera frases naturales y comprensibles.
 *
 * Ejemplos:
 *   "mañana martes a las 10 de la mañana"
 *   "el jueves 3 de julio a las 3 de la tarde"
 *   "hoy a las 4 y media de la tarde"
 */

/**
 * Convierte un array de slots en una lista verbal para el agente
 * Máximo 3-4 opciones para no abrumar al usuario por teléfono
 *
 * @param {Array}  slots     - Array de { time, display, ... }
 * @param {string} timezone
 * @param {number} maxOffer  - Cuántos slots ofrecer verbalmente (default 3)
 * @returns {string} Texto listo para TTS
 */
function slotsToSpeech(slots, timezone = 'America/Mexico_City', maxOffer = 3) {
  if (!slots || slots.length === 0) {
    return 'Lo siento, no tenemos horarios disponibles en los próximos días. ¿Te puedo contactar cuando se libere un espacio?';
  }

  const offered = slots.slice(0, maxOffer);

  if (offered.length === 1) {
    return `Tenemos disponible ${naturalTime(offered[0].time, timezone)}. ¿Te funciona ese horario?`;
  }

  if (offered.length === 2) {
    return `Tenemos dos opciones: ${naturalTime(offered[0].time, timezone)}, o ${naturalTime(offered[1].time, timezone)}. ¿Cuál te viene mejor?`;
  }

  // 3 opciones — más natural para voz
  const last = offered[offered.length - 1];
  const rest = offered.slice(0, -1);
  const listStr = rest.map(s => naturalTime(s.time, timezone)).join(', ');
  return `Tenemos disponible ${listStr}, o ${naturalTime(last.time, timezone)}. ¿Alguna de esas te funciona?`;
}

/**
 * Genera confirmación verbal de una cita agendada
 */
function bookingConfirmationSpeech(appointment, timezone = 'America/Mexico_City') {
  const timeStr = naturalTime(appointment.scheduled_at || appointment.startTime, timezone);
  return `Perfecto, queda agendada tu cita para ${timeStr}. Te llegará una confirmación. ¿Hay algo más en que pueda ayudarte?`;
}

/**
 * Convierte un ISO datetime en lenguaje natural en español
 * "mañana martes a las 10 de la mañana"
 * "el jueves 3 de julio a las 3:30 de la tarde"
 */
function naturalTime(isoTime, timezone = 'America/Mexico_City') {
  const date    = new Date(isoTime);
  const now     = new Date();
  const todayTz = toTzDate(now, timezone);
  const dateTz  = toTzDate(date, timezone);

  // Diferencia en días calendario
  const diffDays = Math.round((dateTz - todayTz) / 86400000);

  // Parte de la hora
  const hour   = date.toLocaleString('es-MX', { timeZone: timezone, hour: 'numeric', hour12: false });
  const minute = date.toLocaleString('es-MX', { timeZone: timezone, minute: '2-digit' });
  const h      = parseInt(hour);
  const m      = parseInt(minute);

  let hourStr;
  if (m === 0) {
    hourStr = h === 12 ? 'al mediodía' : h < 12 ? `a las ${h} de la mañana` : `a las ${h - 12 || 12} de la tarde`;
  } else if (m === 30) {
    hourStr = h < 12 ? `a las ${h} y media de la mañana` : `a las ${h - 12 || 12} y media de la tarde`;
  } else {
    const h12 = h > 12 ? h - 12 : h;
    const period = h < 12 ? 'de la mañana' : 'de la tarde';
    hourStr = `a las ${h12}:${minute} ${period}`;
  }

  // Parte de la fecha
  let dateStr;
  if (diffDays === 0) {
    dateStr = 'hoy';
  } else if (diffDays === 1) {
    const weekday = date.toLocaleString('es-MX', { timeZone: timezone, weekday: 'long' });
    dateStr = `mañana ${weekday}`;
  } else if (diffDays <= 6) {
    const weekday = date.toLocaleString('es-MX', { timeZone: timezone, weekday: 'long' });
    dateStr = `este ${weekday}`;
  } else {
    const weekday = date.toLocaleString('es-MX', { timeZone: timezone, weekday: 'long' });
    const day     = date.toLocaleString('es-MX', { timeZone: timezone, day: 'numeric' });
    const month   = date.toLocaleString('es-MX', { timeZone: timezone, month: 'long' });
    dateStr = `el ${weekday} ${day} de ${month}`;
  }

  return `${dateStr} ${hourStr}`;
}

/**
 * Convierte un índice numérico hablado a índice de array
 * "la primera" → 0, "la segunda" → 1, "la tercera" → 2
 * Útil para cuando el usuario dice "quiero la segunda opción"
 */
function parseSlotChoice(userText, slotsCount) {
  const text = userText.toLowerCase();

  const ordinals = {
    'primer': 0, 'primera': 0, 'uno': 0, 'una': 0, '1': 0,
    'segund': 1, 'dos': 1, '2': 1,
    'tercer': 2, 'tres': 2, '3': 2,
    'cuart':  3, 'cuatro': 3, '4': 3,
  };

  for (const [word, idx] of Object.entries(ordinals)) {
    if (text.includes(word) && idx < slotsCount) {
      return idx;
    }
  }

  // Buscar mención de hora concreta: "las 10", "las 3 y media"
  const hourMatch = text.match(/las?\s+(\d{1,2})/);
  if (hourMatch) {
    return parseInt(hourMatch[1]); // retornar hora para búsqueda por tiempo
  }

  return null; // no se pudo parsear
}

// Helper: fecha sin hora en la timezone dada
function toTzDate(date, timezone) {
  const parts = new Intl.DateTimeFormat('en-US', {
    timeZone: timezone, year: 'numeric', month: '2-digit', day: '2-digit',
  }).formatToParts(date);

  const get = type => parseInt(parts.find(p => p.type === type).value);
  return new Date(get('year'), get('month') - 1, get('day'));
}

module.exports = { slotsToSpeech, bookingConfirmationSpeech, naturalTime, parseSlotChoice };
