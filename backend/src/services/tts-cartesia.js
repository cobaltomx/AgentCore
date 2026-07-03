'use strict';

const axios = require('axios');

const CARTESIA_BASE_URL = 'https://api.cartesia.ai';

// Voces mexicanas reales del catálogo de Cartesia (verificadas con sonic-2).
// Referencia: https://api.cartesia.ai/voices
const SPANISH_VOICES = {
  female_warm:    'b4b8e2af-6139-466e-a93a-30c20d2e1fc5', // Fernanda — cálida MX
  female_natural: '3797b3c0-ab71-40dc-bfa0-a8c6ff9c1e8b', // Carolina — natural MX
  male_formal:    '15d0c2e2-8d29-44c3-be23-d585d5f154a1', // Pedro — formal MX
  female_neutral: '3597a26f-80ef-4bd5-8101-9699bc764917', // Ximena — neutra MX
};

const DEFAULT_VOICE = SPANISH_VOICES.female_warm;

/**
 * Sintetiza texto a audio con Cartesia Sonic
 * Latencia típica: 150-250ms primer chunk (streaming)
 * 
 * @param {string} text - Texto a sintetizar
 * @param {string} voiceId - ID de voz Cartesia (opcional)
 * @param {string} outputFormat - 'mp3' | 'wav' | 'mulaw' (mulaw para Twilio)
 * @returns {Buffer} Audio buffer
 */
async function synthesize(text, voiceId = null, outputFormat = 'mp3') {
  const voice = voiceId || process.env.CARTESIA_DEFAULT_VOICE_ID || DEFAULT_VOICE;

  // Limpiar texto de caracteres problemáticos para TTS
  const cleanText = text
    .replace(/\*\*/g, '')       // quitar markdown bold
    .replace(/\*/g, '')
    .replace(/#{1,6}\s/g, '')   // quitar headers markdown
    .replace(/\n{2,}/g, '. ')   // párrafos → pausa natural
    .replace(/\n/g, ', ')
    .trim();

  if (!cleanText) throw new Error('Texto vacío para TTS');

  const response = await axios.post(
    `${CARTESIA_BASE_URL}/tts/bytes`,
    {
      // sonic-multilingual fue descontinuado (404). sonic-2 es el modelo actual.
      model_id: process.env.CARTESIA_MODEL || 'sonic-2',
      transcript: cleanText,
      voice: {
        mode: 'id',
        id: voice,
      },
      output_format: {
        container: outputFormat === 'mp3' ? 'mp3' : outputFormat === 'mulaw' ? 'raw' : 'wav',
        encoding: outputFormat === 'mulaw' ? 'pcm_mulaw' : 'mp3',
        sample_rate: outputFormat === 'mulaw' ? 8000 : 24000,
      },
      language: 'es',
    },
    {
      headers: {
        'X-API-Key': process.env.CARTESIA_API_KEY,
        'Cartesia-Version': '2024-11-13',
        'Content-Type': 'application/json',
      },
      responseType: 'arraybuffer',
      timeout: 10000,
    }
  );

  return Buffer.from(response.data);
}

/**
 * Sintetiza y retorna como URL de datos base64
 * Útil para responder directamente en TwiML
 * 
 * @param {string} text
 * @param {string} voiceId
 * @returns {string} data URL base64
 */
async function synthesizeToDataUrl(text, voiceId = null) {
  const buffer = await synthesize(text, voiceId, 'mp3');
  return `data:audio/mp3;base64,${buffer.toString('base64')}`;
}

/**
 * Sintetiza y guarda en disco temporal
 * Retorna la ruta del archivo para servir via HTTP
 * 
 * @param {string} text
 * @param {string} voiceId
 * @param {string} filename - Nombre sin extensión
 * @returns {string} filepath
 */
async function synthesizeToFile(text, voiceId = null, filename = null) {
  const fs = require('fs').promises;
  const path = require('path');
  const { v4: uuidv4 } = require('uuid');

  const buffer = await synthesize(text, voiceId, 'mp3');
  const fname = filename || uuidv4();
  const filepath = path.join('/tmp', `${fname}.mp3`);

  await fs.writeFile(filepath, buffer);
  return filepath;
}

/**
 * Estima el costo de síntesis
 * Cartesia cobra por caracteres
 */
function estimateCost(text) {
  const chars = text.length;
  const costPerKChar = 0.065; // USD por 1000 caracteres
  return {
    characters: chars,
    estimatedUSD: (chars / 1000) * costPerKChar,
  };
}

module.exports = { synthesize, synthesizeToDataUrl, synthesizeToFile, estimateCost, SPANISH_VOICES };
