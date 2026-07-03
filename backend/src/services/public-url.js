'use strict';

/**
 * Base URL pública para enlaces que ven los clientes (cédulas, links en
 * WhatsApp, imágenes). Cada tenant puede tener su PROPIO dominio fijo.
 *
 * Prioridad:
 *   1. settings.publicDomain del tenant  (dominio fijo por cliente — producción)
 *   2. PUBLIC_BASE_URL del entorno        (dominio del deployment)
 *   3. APP_URL (túnel ngrok)              ← TEMPORAL: cambia al reiniciar y
 *                                            muestra interstitial. Solo para dev.
 *
 * TODO producción: asignar settings.publicDomain a cada tenant (o un dominio
 * propio por implementación) y dejar de depender de ngrok.
 */
function publicBase(tenantSettings = {}) {
  const fromTenant = tenantSettings && (tenantSettings.publicDomain || tenantSettings.domain);
  const base = fromTenant || process.env.PUBLIC_BASE_URL || process.env.APP_URL || '';
  return String(base).replace(/\/$/, '');
}

/** Convierte una URL relativa (/uploads/..) en absoluta usando la base pública. */
function absUrl(url, base) {
  if (!url) return '';
  if (/^https?:\/\//i.test(url)) return url;
  return base + (url.startsWith('/') ? '' : '/') + url;
}

module.exports = { publicBase, absUrl };
