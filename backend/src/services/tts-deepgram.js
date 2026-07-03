'use strict';

/**
 * TTS de respaldo con Deepgram Aura-2 (español).
 * Salida μ-law 8kHz RAW (container=none) — lista para Twilio Media Streams.
 * Se usa como fallback cuando Cartesia falla (sin créditos / caído).
 */

const https = require('https');

const DEFAULT_MODEL = process.env.DEEPGRAM_TTS_MODEL || 'aura-2-celeste-es';

/**
 * @param {string} text
 * @param {string} model - voz Aura-2 (ej. aura-2-celeste-es)
 * @returns {Promise<Buffer>} μ-law 8kHz raw
 */
function synthesizeMulaw(text, model = DEFAULT_MODEL) {
  return new Promise((resolve, reject) => {
    if (!text || !text.trim()) return reject(new Error('Texto vacío para TTS'));
    const payload = JSON.stringify({ text });
    const qs = `model=${encodeURIComponent(model)}&encoding=mulaw&sample_rate=8000&container=none`;
    const req = https.request({
      host: 'api.deepgram.com',
      path: `/v1/speak?${qs}`,
      method: 'POST',
      family: 4,   // el contenedor no tiene ruta IPv6 saliente
      headers: {
        'Authorization': `Token ${process.env.DEEPGRAM_API_KEY}`,
        'Content-Type': 'application/json',
      },
      timeout: 10000,
    }, (r) => {
      const chunks = [];
      r.on('data', d => chunks.push(d));
      r.on('end', () => {
        const buf = Buffer.concat(chunks);
        if (r.statusCode !== 200) {
          return reject(new Error(`Deepgram TTS ${r.statusCode}: ${buf.toString('utf8').slice(0, 120)}`));
        }
        resolve(buf);
      });
    });
    req.on('error', reject);
    req.on('timeout', () => { req.destroy(); reject(new Error('Deepgram TTS timeout')); });
    req.write(payload);
    req.end();
  });
}

module.exports = { synthesizeMulaw };
