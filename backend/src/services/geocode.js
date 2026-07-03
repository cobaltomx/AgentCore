'use strict';

/**
 * Geocodificación + distancia para validar zonas de entrega (delivery).
 *
 * Proveedor: Google Maps Geocoding si hay GOOGLE_MAPS_API_KEY; si no, cae a
 * OpenStreetMap / Nominatim (gratis, sin API key — uso ligero, respeta su
 * política: User-Agent identificable y baja frecuencia).
 */

const axios = require('axios');

const GOOGLE_KEY = process.env.GOOGLE_MAPS_API_KEY || '';
const COUNTRY    = (process.env.GEOCODE_COUNTRY || 'mx').toLowerCase();

/**
 * Geocodifica una dirección de texto → { lat, lng, formatted } o null.
 */
async function geocodeAddress(address, { near = null } = {}) {
  const q = String(address || '').trim();
  if (q.length < 4) return null;

  if (GOOGLE_KEY) {
    try {
      const params = { address: q, key: GOOGLE_KEY, region: COUNTRY, language: 'es' };
      // SESGO geográfico hacia el negocio: ayuda a que nombres mal transcritos
      // por voz (ej. "Cibata" por "Zibata") resuelvan a la zona local correcta.
      if (near && Number.isFinite(near.lat) && Number.isFinite(near.lng)) {
        const d = 0.25; // ~25 km de viewport alrededor del negocio
        params.bounds = `${near.lat - d},${near.lng - d}|${near.lat + d},${near.lng + d}`;
      }
      const { data } = await axios.get('https://maps.googleapis.com/maps/api/geocode/json', { params, timeout: 6000 });
      const r = data.results && data.results[0];
      if (r) return { lat: r.geometry.location.lat, lng: r.geometry.location.lng, formatted: r.formatted_address };
    } catch (err) {
      console.warn('[geocode] Google falló, intento Nominatim:', err.message);
    }
  }

  // Fallback: Nominatim (OpenStreetMap)
  try {
    const params = { q, format: 'json', limit: 1, countrycodes: COUNTRY, addressdetails: 0 };
    if (near && Number.isFinite(near.lat) && Number.isFinite(near.lng)) {
      const d = 0.25;
      params.viewbox = `${near.lng - d},${near.lat + d},${near.lng + d},${near.lat - d}`;
      params.bounded = 0;   // preferir el viewport, sin excluir fuera de él
    }
    const { data } = await axios.get('https://nominatim.openstreetmap.org/search', {
      params, headers: { 'User-Agent': 'AgentCore/1.0 (delivery-area-check)' }, timeout: 6000,
    });
    const r = Array.isArray(data) && data[0];
    if (r) return { lat: parseFloat(r.lat), lng: parseFloat(r.lon), formatted: r.display_name };
  } catch (err) {
    console.warn('[geocode] Nominatim falló:', err.message);
  }
  return null;
}

/** Distancia en km entre dos puntos {lat,lng} (haversine). */
function haversineKm(a, b) {
  const R = 6371;
  const toRad = (d) => (d * Math.PI) / 180;
  const dLat = toRad(b.lat - a.lat);
  const dLng = toRad(b.lng - a.lng);
  const lat1 = toRad(a.lat), lat2 = toRad(b.lat);
  const h = Math.sin(dLat / 2) ** 2 + Math.cos(lat1) * Math.cos(lat2) * Math.sin(dLng / 2) ** 2;
  return 2 * R * Math.asin(Math.sqrt(h));
}

module.exports = { geocodeAddress, haversineKm };
