'use strict';

require('dotenv').config();

// Forzar IPv4: este contenedor no tiene ruta IPv6 saliente. Sin esto, Node 20
// intenta direcciones IPv6 primero y las APIs externas (Cartesia, OpenAI,
// Anthropic, Deepgram) fallan con ENETUNREACH en vez de caer a IPv4.
require('dns').setDefaultResultOrder('ipv4first');
try { require('net').setDefaultAutoSelectFamily(false); } catch { /* Node < 19 */ }

const Fastify = require('fastify');
const { registerPlugins } = require('./plugins');
const { registerRoutes } = require('./routes');

const app = Fastify({
  logger: {
    level: process.env.LOG_LEVEL || 'info',
    // pino-pretty transport descartaba logs silenciosamente; salida directa
  },
  trustProxy: true,  // necesario detrás de nginx/cloudflare
});

async function start() {
  try {
    await registerPlugins(app);
    await registerRoutes(app);

    const port = parseInt(process.env.PORT || '3000');
    await app.listen({ port, host: '0.0.0.0' });

    console.log(`\n🤖 AgentCore backend corriendo en http://localhost:${port}`);
    console.log(`📋 ENV: ${process.env.NODE_ENV}`);

    // Iniciar Campaign Worker (Fase 7)
    const CampaignWorker = require('./services/campaigns/campaign-worker');
    const worker = new CampaignWorker({ db: app.db, redis: app.redis });
    worker.start(30000); // tick cada 30 segundos

    // Iniciar Reminder Worker — recordatorios y reactivación de pacientes
    const ReminderWorker = require('./services/campaigns/reminder-worker');
    const reminderWorker = new ReminderWorker({ db: app.db });
    const REMINDER_INTERVAL = 15 * 60 * 1000; // cada 15 minutos
    reminderWorker.runTick(); // ejecutar inmediatamente al iniciar
    const reminderTimer = setInterval(() => reminderWorker.runTick(), REMINDER_INTERVAL);
    console.log('[ReminderWorker] Iniciado — tick cada 15 min');

    // Detener workers al apagar
    process.on('SIGTERM', () => { worker.stop(); clearInterval(reminderTimer); process.exit(0); });
    process.on('SIGINT',  () => { worker.stop(); clearInterval(reminderTimer); process.exit(0); });
  } catch (err) {
    app.log.error(err);
    process.exit(1);
  }
}

start();
