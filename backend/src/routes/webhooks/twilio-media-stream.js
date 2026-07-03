'use strict';

/**
 * Twilio Media Streams — voz bidireccional en tiempo real (rework de baja latencia)
 *
 * Flujo:
 *  1. /voice responde TwiML <Connect><Stream> → Twilio abre este WebSocket.
 *  2. Audio entrante (μ-law 8kHz, 20ms) → Deepgram live (STT streaming).
 *  3. Al cerrar el turno (speech_final) → VoiceAgent.processTurn → texto.
 *  4. Cartesia (o fallback Deepgram) sintetiza μ-law 8kHz → se envía de vuelta.
 *  5. Barge-in: si el usuario habla mientras el bot habla → <clear> + corta TTS.
 *
 * Detalles de fluidez:
 *  - El audio se envía SIN paceo (Twilio bufferea y reproduce suave); el envío
 *    se "resuelve" por DURACIÓN del audio, no por el eco del mark (evita que el
 *    ciclo de fillers gire sin pausa).
 *  - Mientras el LLM piensa, se reproducen fillers cortos cacheados, uno tras
 *    otro (sin acumular), que paran apenas llega la respuesta.
 */

const VoiceAgent = require('../../agents/voice-agent');
const { createLiveStream } = require('../../services/stt-deepgram');
const { synthesize } = require('../../services/tts-cartesia');
const { synthesizeMulaw: deepgramTts } = require('../../services/tts-deepgram');

const FRAME_BYTES = 160;   // μ-law 8kHz, 20ms
const sleep = (ms) => new Promise(r => setTimeout(r, ms));

// Watchdog de silencio (ms). Configurables por env para afinar sin tocar código.
// SILENCE_FIRST: cuánto esperar callado ANTES de "¿sigue ahí?" — dale tiempo al
//   cliente a pensar/revisar su calendario (antes 9s, demasiado agresivo).
// SILENCE_SECOND: tras ese aviso, cuánto más esperar antes de despedirse/colgar.
const SILENCE_FIRST_MS  = parseInt(process.env.VOICE_SILENCE_MS)        || 14000;
const SILENCE_SECOND_MS = parseInt(process.env.VOICE_SILENCE_HANGUP_MS) || 12000;
// Espera tras la última frase final antes de que el bot tome el turno. Más alto
// = más paciente con pausas del cliente (pensar, dar dirección por partes).
const TURN_DEBOUNCE_MS  = parseInt(process.env.VOICE_TURN_DEBOUNCE_MS)  || 2300;

// TTS μ-law con fallback: Cartesia (premium) → Deepgram Aura-2 (respaldo).
// Si Cartesia falla (402 sin créditos, caído), lo marcamos "muerto" 5 min y
// vamos DIRECTO a Deepgram — así no gastamos una llamada fallida (~0.5s) en
// CADA frase del streaming, que sumaba latencia en toda la llamada.
// VOICE_TTS=deepgram fuerza el respaldo Deepgram y se salta Cartesia por
// completo (útil cuando Cartesia está rate-limited/429 y queremos una demo
// estable sin el "dead air" del primer intento fallido).
const FORCE_DEEPGRAM = String(process.env.VOICE_TTS || '').toLowerCase() === 'deepgram';
let cartesiaDeadUntil = 0;
async function ttsMulaw(text, voiceId) {
  if (FORCE_DEEPGRAM || Date.now() < cartesiaDeadUntil) {
    return await deepgramTts(text);
  }
  try {
    return await synthesize(text, voiceId, 'mulaw');
  } catch (err) {
    cartesiaDeadUntil = Date.now() + 5 * 60 * 1000;
    console.warn('[MediaStream] Cartesia TTS no disponible (' + (err.message || err) + ') → Deepgram por 5 min');
    return await deepgramTts(text);
  }
}

// Fillers de espera (cortos y variados; se sintetizan 1 vez por voz y se cachean).
// Variedad alta para que no suene repetitivo en una misma llamada.
const FILLER_TEXTS = [
  'Permíteme un momento, por favor.',
  'Claro, déjame revisar eso.',
  'Muy bien, lo busco enseguida.',
  'Dame un segundito, por favor.',
  'Perfecto, déjame consultarlo.',
  'Ahora mismo lo reviso.',
  'Con gusto, un momento.',
  'Déjame checar esa información.',
  'Va, lo verifico rapidito.',
  'Enseguida te digo.',
];
const fillerCache = new Map();   // voiceId -> [Buffer μ-law]

const fillerInflight = new Map();   // key -> Promise<Buffer[]> (warm-up compartido)
async function getFillers(voiceId) {
  const key = voiceId || 'default';
  if (fillerCache.has(key))    return fillerCache.get(key);      // ya listos
  if (fillerInflight.has(key)) return fillerInflight.get(key);   // llamada simultánea → comparte
  // Warm-up en LOTES de 3 (rápido sin saturar el TTS ni quitarle voz al saludo).
  const p = (async () => {
    const valid = [];
    for (let i = 0; i < FILLER_TEXTS.length; i += 3) {
      const batch = await Promise.all(FILLER_TEXTS.slice(i, i + 3).map(t => ttsMulaw(t, voiceId).catch(() => null)));
      for (const b of batch) if (b) valid.push(b);
      fillerCache.set(key, valid);   // ir publicando los que ya están (variedad creciente)
    }
    fillerInflight.delete(key);
    return valid;
  })();
  fillerInflight.set(key, p);
  return p;
}

async function mediaStreamRoutes(app) {
  const voiceAgent = new VoiceAgent({ db: app.db, redis: app.redis });

  app.get('/media-stream', { websocket: true }, (connection) => {
    const ws = connection.socket;

    // ── Estado ──────────────────────────────────────────────────
    let streamSid   = null;
    let callSid     = null;
    let voiceId     = null;
    let dg          = null;
    let dgReady     = false;
    let speaking    = false;
    let speakStartedAt = 0;     // cuándo empezó a hablar el bot (margen anti-barge-in falso)
    let processing  = false;
    let ttsAbort    = false;
    let finalBuffer = '';
    let turnTimer   = null;
    let turnSeq     = 0;
    let lastFillerIdx = -1;     // evita repetir el mismo filler consecutivo
    let silenceTimer   = null;  // watchdog de silencio (re-enganche)
    let silencePrompts = 0;
    let closed      = false;
    let dgKeepAlive = null;     // intervalo KeepAlive de Deepgram
    let dgReconnects = 0;       // reconexiones de Deepgram en esta llamada
    let llmFailCount = 0;       // circuit breaker: fallos LLM consecutivos

    let speakChain      = Promise.resolve();   // serializa los speaks
    let currentAudioEnd = null;                // resuelve el sendAudio en curso (barge-in)
    let speechGen       = 0;                    // invalida audio en cola tras barge-in

    const log  = (msg, extra = {}) => app.log.info({ callSid, ...extra }, `[MediaStream] ${msg}`);
    const send = (obj) => { if (ws.readyState === 1) ws.send(JSON.stringify(obj)); };

    // ── Enviar un buffer μ-law; resolver por duración del audio ──
    function sendAudio(mulaw) {
      return new Promise((resolve) => {
        if (!mulaw || !mulaw.length || closed) return resolve();
        if (!speaking) speakStartedAt = Date.now();
        speaking = true;
        ttsAbort = false;
        for (let i = 0; i < mulaw.length; i += FRAME_BYTES) {
          if (ttsAbort || closed) break;
          send({ event: 'media', streamSid, media: { payload: mulaw.subarray(i, i + FRAME_BYTES).toString('base64') } });
        }
        if (ttsAbort || closed) { speaking = false; return resolve(); }
        send({ event: 'mark', streamSid, mark: { name: 'eos' } });
        const durMs = (mulaw.length / 8000) * 1000;
        let done = false;
        const finish = () => {
          if (done) return; done = true;
          clearTimeout(timer); currentAudioEnd = null; speaking = false; resolve();
        };
        const timer = setTimeout(finish, durMs + 120);
        currentAudioEnd = finish;   // barge-in puede cortar antes
      });
    }

    // Encola un speak. La SÍNTESIS arranca de inmediato (en paralelo) y solo el
    // ENVÍO respeta el orden de la cadena → sin huecos audibles entre frases.
    function say(textOrBuffer) {
      const gen = speechGen;
      const audioP = Buffer.isBuffer(textOrBuffer)
        ? Promise.resolve(textOrBuffer)
        : ttsMulaw(textOrBuffer, voiceId).catch(err => {
            app.log.error({ err: err.message, callSid }, '[MediaStream] TTS falló'); return null;
          });
      speakChain = speakChain.then(async () => {
        if (closed || gen !== speechGen) return;   // barge-in invalidó esta voz
        const buf = await audioP;
        if (!buf || closed || gen !== speechGen) return;
        await sendAudio(buf);
      }).catch(() => {});
      return speakChain;
    }

    // ── Corta el audio en curso (barge-in / interrupción) ───────
    function cancelSpeech() {
      speechGen++;                 // invalida lo que esté en cola
      ttsAbort = true;
      send({ event: 'clear', streamSid });
      if (currentAudioEnd) currentAudioEnd();
      speakChain = Promise.resolve();
      speaking = false;
    }

    // ── Fillers encadenados a la reproducción (sin backlog) ─────
    async function runFillers(myTurn) {
      await sleep(250);
      while (processing && myTurn === turnSeq && !closed) {
        const fillers = fillerCache.get(voiceId || 'default') || [];
        if (fillers.length) {
          // Elegir uno distinto al anterior (evita repetir consecutivamente)
          let idx = Math.floor(Math.random() * fillers.length);
          if (fillers.length > 1 && idx === lastFillerIdx) idx = (idx + 1) % fillers.length;
          lastFillerIdx = idx;
          await say(fillers[idx]);
        } else {
          await sleep(200);
        }
      }
    }

    // ── Cierre de turno: procesar y responder ───────────────────
    async function handleTurn() {
      const text = finalBuffer.trim();
      finalBuffer = '';
      if (!text || processing || closed) return;
      const myTurn = ++turnSeq;
      processing = true;
      let firstChunk = false;
      const fillersDone = runFillers(myTurn);

      // Cada frase generada por el LLM se habla de inmediato (streaming).
      // Al llegar la primera, se detienen los fillers.
      const onSentence = (s) => {
        if (myTurn !== turnSeq || closed) return;   // barge-in invalidó el turno
        if (!firstChunk) { firstChunk = true; processing = false; }
        say(s);
      };

      try {
        log('turno usuario', { text: text.substring(0, 80) });
        const t0 = Date.now();
        const { ended, outcome, transferTo } = await voiceAgent.processTurnStreaming(callSid, text, { onSentence });
        log('respuesta lista', { ms: Date.now() - t0, ended, outcome });
        llmFailCount = 0;   // turno exitoso → resetear el circuit breaker

        processing = false;
        await fillersDone;

        if (myTurn !== turnSeq || closed) return;   // el usuario interrumpió

        // Transferencia REAL: tras decir "te comunico…", redirige la llamada
        // viva a un <Dial> con el número de transferencia (vía Twilio REST).
        if (outcome === 'transfer_dial' && transferTo) {
          await speakChain;
          try {
            const { getTwilioClient } = require('../../services/twilio-client');
            const client = getTwilioClient({});
            const cid = process.env.TWILIO_DEFAULT_NUMBER ? ` callerId="${process.env.TWILIO_DEFAULT_NUMBER}"` : '';
            await client.calls(callSid).update({ twiml:
              `<Response><Dial timeout="25"${cid}><Number>${transferTo}</Number></Dial>` +
              `<Say language="es-MX" voice="Polly.Mia-Neural">No fue posible conectar. Un asesor te contactará en breve.</Say></Response>` });
            log('transferencia: llamada redirigida a ' + transferTo);
          } catch (e) {
            app.log.error({ err: e.message, callSid }, '[MediaStream] Error redirigiendo transferencia');
          }
          return;
        }

        if (ended) {
          await speakChain;          // espera a que termine el audio transmitido
          if (myTurn === turnSeq && !closed) {
            cleanup();
            if (ws.readyState === 1) ws.close();
          }
          return;
        }

        // Respuesta entregada y la llamada sigue → vigilar silencio del usuario
        if (myTurn === turnSeq && !closed) armSilenceWatch();
      } catch (err) {
        app.log.error({ err, callSid }, '[MediaStream] Error en processTurnStreaming');
        processing = false;
        await fillersDone.catch(() => {});
        if (myTurn !== turnSeq || closed) return;

        // ── Circuit breaker: si el LLM falla 2 veces seguidas (créditos
        //    agotados, proveedor caído), NO dejamos al cliente en un bucle de
        //    "tuve un problema": despedida digna + colgar. El lead/registro de
        //    la llamada ya queda en conversations para seguimiento humano.
        llmFailCount++;
        if (llmFailCount >= 2) {
          log('circuit breaker: LLM caído, cerrando llamada con cortesía', { fails: llmFailCount });
          await say('Lo siento, estamos teniendo un problema técnico en este momento. Un asesor te devolverá la llamada muy pronto. Gracias por tu paciencia.');
          await speakChain;
          cleanup();
          if (ws.readyState === 1) ws.close();
          return;
        }
        await say('Disculpa, tuve un problema. ¿Puedes repetirlo?');
      }
    }

    function scheduleTurn(delay = 50) {
      if (turnTimer) clearTimeout(turnTimer);
      turnTimer = setTimeout(handleTurn, delay);
    }

    // ── Watchdog de silencio: re-engancha si el usuario se queda callado o su
    //    voz no se transcribe (antes el bot quedaba mudo → el cliente colgaba).
    function disarmSilenceWatch() {
      if (silenceTimer) { clearTimeout(silenceTimer); silenceTimer = null; }
    }
    function armSilenceWatch(ms = SILENCE_FIRST_MS) {
      disarmSilenceWatch();
      silenceTimer = setTimeout(onSilence, ms);
    }
    async function onSilence() {
      silenceTimer = null;
      if (closed) return;
      if (processing || speaking) { armSilenceWatch(); return; }   // ocupado → re-armar
      if (silencePrompts === 0) {
        silencePrompts = 1;
        say('¿Sigue ahí? Tómese su tiempo, aquí le espero.');
        armSilenceWatch(SILENCE_SECOND_MS);   // tras el aviso, más holgura antes de colgar
      } else {
        // Segundo silencio → despedida y cierre limpio (el lead/cita ya quedó guardado)
        say('Parece que se cortó la comunicación. Le enviaremos la información por mensaje. ¡Hasta pronto!');
        await speakChain;
        cleanup();
        if (ws.readyState === 1) ws.close();
      }
    }

    // ── Deepgram ────────────────────────────────────────────────
    function onTranscript({ transcript, isFinal, speechFinal }) {
      if (!transcript) return;
      disarmSilenceWatch();        // el usuario habló → cancelar el watchdog
      silencePrompts = 0;

      // Barge-in REAL: el usuario dice ≥2 palabras mientras el bot YA está
      // HABLANDO (no mientras "piensa"). Si se dispara durante processing, la
      // continuación natural del propio usuario ("...sí, hola") mata su turno.
      // Además, un margen de 600ms tras empezar a hablar evita cortar el
      // arranque con el eco/cola de la frase del usuario.
      const words = transcript.trim().split(/\s+/).filter(w => w.length >= 2).length;
      if (speaking && words >= 2 && (Date.now() - speakStartedAt) > 600) {
        cancelSpeech();
        turnSeq++;               // invalida el turno/voz en vuelo
        log('barge-in: usuario interrumpió');
      }

      if (isFinal) {
        finalBuffer = (finalBuffer + ' ' + transcript).trim();
        // Debounce: agrupa continuaciones del MISMO usuario y le da tiempo a
        // seguir hablando (pensar, dar una dirección por partes, consultar a
        // alguien). Antes 450ms cortaba al cliente; subido y env-tunable.
        scheduleTurn(TURN_DEBOUNCE_MS);
      }
    }

    // Crea (o RECREA) el stream STT de Deepgram. Deepgram cierra conexiones
    // de larga duración; sin reconexión, las llamadas largas se quedaban sin
    // transcripción → el watchdog de silencio las colgaba (~26s). Aquí, si la
    // llamada sigue viva cuando Deepgram cierra, reconectamos.
    function connectDeepgram() {
      dgReady = false;
      dg = createLiveStream({
        onTranscript,
        onUtteranceEnd: () => { if (finalBuffer.trim()) scheduleTurn(0); },
        onOpen:  () => { dgReady = true; log('Deepgram abierto'); },
        onError: (err) => app.log.error({ err, callSid }, '[MediaStream] Deepgram error'),
        onClose: () => {
          dgReady = false;
          if (!closed && dgReconnects < 100) {
            dgReconnects++;
            log('Deepgram cerrado en llamada activa → reconectando', { intento: dgReconnects });
            setTimeout(() => { if (!closed) { try { connectDeepgram(); } catch (e) {
              app.log.error({ e: e.message, callSid }, '[MediaStream] Error reconectando Deepgram'); } } }, 200);
          }
        },
      });
    }

    async function init(params) {
      const agentId  = params.agentId;
      const tenantId = params.tenantId;
      const from     = params.from || 'unknown';
      const greeting = params.greeting || null;

      connectDeepgram();
      // KeepAlive: evita que Deepgram cierre por inactividad en pausas largas.
      dgKeepAlive = setInterval(() => {
        try { if (dg && dgReady && typeof dg.keepAlive === 'function') dg.keepAlive(); } catch {}
      }, 8000);

      try {
        // Timeout de seguridad: si startCall se cuelga (DB/red lenta) más de
        // 12s, el usuario quedaba en silencio total hasta que Twilio colgaba
        // por timeout propio (~30s) sin ningún diagnóstico. Con esto, al menos
        // se corta con un mensaje y queda registrado en logs.
        const res = await Promise.race([
          voiceAgent.startCall({
            agentId, tenantId, contactPhone: from, callSid,
            skipSynth: true, greetingOverride: greeting,
          }),
          new Promise((_, rej) => setTimeout(() => rej(new Error('startCall timeout 12s')), 12000)),
        ]);
        voiceId = res.voiceId;
        getFillers(voiceId).catch(() => {});
        say(res.greetingText);
        armSilenceWatch();
      } catch (err) {
        app.log.error({ err, callSid }, '[MediaStream] Error en startCall');
        if (!closed) {
          await say('Hola, en este momento tenemos un problema técnico. Por favor intenta de nuevo en unos minutos.');
          await speakChain;
          cleanup();
          if (ws.readyState === 1) ws.close();
        }
      }
    }

    function cleanup() {
      if (closed) return;
      closed = true;
      ttsAbort = true;
      if (turnTimer) clearTimeout(turnTimer);
      if (silenceTimer) clearTimeout(silenceTimer);
      if (dgKeepAlive) clearInterval(dgKeepAlive);
      if (currentAudioEnd) currentAudioEnd();
      try { dg && dg.requestClose && dg.requestClose(); } catch {}
      try { dg && dg.finish && dg.finish(); } catch {}
      voiceAgent.endCall(callSid, {}).catch(() => {});
      log('conexión cerrada');
    }

    // ── Mensajes de Twilio ──────────────────────────────────────
    ws.on('message', (raw) => {
      let msg;
      try { msg = JSON.parse(raw.toString()); } catch { return; }

      switch (msg.event) {
        case 'start':
          streamSid = msg.start.streamSid;
          callSid   = msg.start.callSid || msg.start.customParameters?.callSid || streamSid;
          log('stream iniciado', { streamSid });
          init(msg.start.customParameters || {});
          break;

        case 'media':
          if (dg && dgReady && msg.media?.track !== 'outbound' && msg.media?.payload) {
            try { dg.send(Buffer.from(msg.media.payload, 'base64')); } catch {}
          }
          break;

        case 'stop':
          log('stream detenido por Twilio');
          cleanup();
          break;
      }
    });

    ws.on('close', cleanup);
    ws.on('error', (err) => { app.log.error({ err, callSid }, '[MediaStream] WS error'); cleanup(); });
  });
}

module.exports = mediaStreamRoutes;
