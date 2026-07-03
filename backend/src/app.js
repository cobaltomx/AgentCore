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

    // Monitor de saldos — chequea proveedores cada 30 min y notifica al
    // superadmin si algo se agota (nació del incidente "sin créditos y nadie
    // se enteró"). El primer chequeo se hace a los 60s para no frenar el boot.
    const { checkAndNotify } = require('./services/balance-monitor');
    const balanceTick = () => checkAndNotify(app.db, app.redis)
      .then(d => console.log(`[BalanceMonitor] estado general: ${d.overall}`))
      .catch(e => console.warn('[BalanceMonitor] tick falló:', e.message));
    const balanceFirst = setTimeout(balanceTick, 60 * 1000);
    const balanceTimer = setInterval(balanceTick, 30 * 60 * 1000);
    console.log('[BalanceMonitor] Iniciado — tick cada 30 min');

    // Cierre de conversaciones zombies — 'active' sin actividad >6h pasan a
    // 'completed' para no ensuciar métricas/dashboard. Corre cada hora.
    const closeZombies = async () => {
      try {
        const r = await app.db.query(
          `UPDATE conversations SET status='completed', ended_at = COALESCE(ended_at, now())
           WHERE status='active'
             AND COALESCE(
                   (SELECT MAX(created_at) FROM messages m WHERE m.conversation_id = conversations.id),
                   started_at
                 ) < now() - interval '6 hours'`
        );
        if (r.rowCount > 0) console.log(`[ZombieCloser] ${r.rowCount} conversaciones inactivas cerradas`);
      } catch (e) { console.warn('[ZombieCloser] falló:', e.message); }
    };
    closeZombies();
    const zombieTimer = setInterval(closeZombies, 60 * 60 * 1000);

    // Detener workers al apagar
    const stopAll = () => { worker.stop(); clearInterval(reminderTimer); clearTimeout(balanceFirst); clearInterval(balanceTimer); clearInterval(zombieTimer); process.exit(0); };
    process.on('SIGTERM', stopAll);
    process.on('SIGINT',  stopAll);
  } catch (err) {
    app.log.error(err);
    process.exit(1);
  }
}

start();
