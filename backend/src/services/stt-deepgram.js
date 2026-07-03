'use strict';

const { createClient, LiveTranscriptionEvents } = require('@deepgram/sdk');

const deepgram = createClient(process.env.DEEPGRAM_API_KEY);

/**
 * Transcribe audio buffer a texto (batch — para grabaciones cortas)
 * Usado cuando Twilio nos manda el audio grabado
 * 
 * @param {Buffer} audioBuffer - Audio en formato mulaw/pcm/mp3
 * @param {string} mimeType - 'audio/mulaw' | 'audio/wav' | 'audio/mp3'
 * @returns {Object} { transcript, confidence, words, durationSecs }
 */
async function transcribeBuffer(audioBuffer, mimeType = 'audio/mulaw') {
  const { result, error } = await deepgram.listen.prerecorded.transcribeFile(
    audioBuffer,
    {
      model: process.env.DEEPGRAM_MODEL || 'nova-3',
      language: process.env.DEEPGRAM_LANGUAGE || 'es-419',
      smart_format: true,       // puntuación automática
      punctuate: true,
      diarize: false,
      utterances: false,
      numerals: true,           // "veinte" → "20"
    }
  );

  if (error) throw new Error(`Deepgram error: ${error.message}`);

  const channel = result.results?.channels?.[0];
  const alternative = channel?.alternatives?.[0];

  return {
    transcript: alternative?.transcript || '',
    confidence: alternative?.confidence || 0,
    words: alternative?.words || [],
    durationSecs: result.metadata?.duration || 0,
  };
}

/**
 * Transcribe desde URL pública (grabación de Twilio)
 * Twilio nos da una URL de la grabación, Deepgram la descarga directamente
 * 
 * @param {string} recordingUrl - URL de grabación de Twilio
 * @returns {Object} { transcript, confidence }
 */
async function transcribeUrl(recordingUrl) {
  // Agregar credenciales básicas de Twilio a la URL
  const authUrl = recordingUrl.replace(
    'https://',
    `https://${process.env.TWILIO_ACCOUNT_SID}:${process.env.TWILIO_AUTH_TOKEN}@`
  );

  const { result, error } = await deepgram.listen.prerecorded.transcribeUrl(
    { url: authUrl },
    {
      model: process.env.DEEPGRAM_MODEL || 'nova-3',
      language: process.env.DEEPGRAM_LANGUAGE || 'es-419',
      smart_format: true,
      punctuate: true,
      numerals: true,
    }
  );

  if (error) throw new Error(`Deepgram URL transcription error: ${error.message}`);

  const alternative = result.results?.channels?.[0]?.alternatives?.[0];

  return {
    transcript: alternative?.transcript || '',
    confidence: alternative?.confidence || 0,
  };
}

/**
 * Crea una conexión WebSocket de streaming STT
 * Para transcripción en tiempo real (latencia ~200-300ms)
 * Usado en flujos de baja latencia con WebSockets
 * 
 * @param {Function} onTranscript - Callback cuando llega un transcript final
 * @param {Function} onError - Callback de error
 * @returns {LiveClient} conexión activa de Deepgram
 */
function createLiveStream({ onTranscript, onSpeechStarted, onUtteranceEnd, onOpen, onError, onClose } = {}) {
  const connection = deepgram.listen.live({
    model: process.env.DEEPGRAM_MODEL || 'nova-3',
    language: process.env.DEEPGRAM_LANGUAGE || 'es-419',
    smart_format: true,
    punctuate: true,
    numerals: true,
    interim_results: true,       // parciales: detectar inicio de habla (barge-in)
    // Más PACIENTE: damos tiempo a que el cliente piense o consulte a alguien
    // antes de cerrar su turno (antes 300/1000 cortaba a media frase). Env-tunable.
    endpointing: parseInt(process.env.VOICE_ENDPOINTING_MS) || 700,            // ms de silencio para cerrar utterance
    utterance_end_ms: parseInt(process.env.VOICE_UTTERANCE_END_MS) || 2800,    // respaldo de fin de turno (límite real de paciencia: pensar menú/dirección)
    vad_events: true,            // Voice Activity Detection (SpeechStarted)
    encoding: 'mulaw',           // formato de Twilio Media Streams
    sample_rate: 8000,           // tasa de muestreo de Twilio
    channels: 1,
  });

  connection.on(LiveTranscriptionEvents.Open, () => { if (onOpen) onOpen(); });

  connection.on(LiveTranscriptionEvents.Transcript, (data) => {
    const alt = data.channel?.alternatives?.[0];
    const transcript = alt?.transcript;
    if (!transcript) return;
    onTranscript && onTranscript({
      transcript,
      confidence:  alt?.confidence || 0,
      isFinal:     !!data.is_final,
      speechFinal: !!data.speech_final,
    });
  });

  // VAD: el usuario empezó a hablar → usado para barge-in (cortar al bot)
  connection.on(LiveTranscriptionEvents.SpeechStarted, () => {
    onSpeechStarted && onSpeechStarted();
  });

  // Respaldo de fin de turno cuando endpointing no dispara speech_final
  connection.on(LiveTranscriptionEvents.UtteranceEnd, () => {
    onUtteranceEnd && onUtteranceEnd();
  });

  connection.on(LiveTranscriptionEvents.Error, (err) => {
    console.error('[Deepgram] Streaming error:', err);
    if (onError) onError(err);
  });

  connection.on(LiveTranscriptionEvents.Close, () => {
    console.log('[Deepgram] Stream cerrado');
    if (onClose) onClose();
  });

  return connection;
}

module.exports = { transcribeBuffer, transcribeUrl, createLiveStream };
