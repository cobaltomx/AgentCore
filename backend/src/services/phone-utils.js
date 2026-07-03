'use strict';

/**
 * Utilidades de teléfono (México por defecto). Fuente ÚNICA — antes estaba
 * duplicado en tools/executor.js y routes/v1/appointments.js.
 */

// Normaliza un teléfono a E.164 (México por defecto). Respeta los que ya
// vienen en E.164 válido (p.ej. el Caller ID de Twilio).
function normalizePhoneMx(raw) {
  if (!raw) return '';
  const trimmed = String(raw).trim();
  if (/^\+\d{11,15}$/.test(trimmed)) return trimmed;     // ya es E.164
  let d = trimmed.replace(/[^\d]/g, '');
  if (!d) return '';
  if (d.startsWith('00')) d = d.slice(2);                // prefijo internacional 00
  if (d.length === 10) d = '52' + d;                     // MX local de 10 dígitos
  return '+' + d;
}

// Formato de destinatario para WhatsApp México: los MÓVILES usan +52 1 + 10
// dígitos. Twilio/Meta registran el número del sandbox con ese "1".
function toWhatsAppMx(e164) {
  const d = String(e164 || '').replace(/[^\d]/g, '');
  if (d.length === 12 && d.startsWith('52')) return '+521' + d.slice(2);   // +52XXXXXXXXXX → +521XXXXXXXXXX
  if (d.length === 10) return '+521' + d;                                  // local → +521…
  return e164 && String(e164).startsWith('+') ? e164 : '+' + d;
}

module.exports = { normalizePhoneMx, toWhatsAppMx };
