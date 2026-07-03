'use strict';

async function registerRoutes(app) {

  // Health check — sin auth
  app.get('/health', async () => ({
    status: 'ok',
    version: '0.1.0',
    timestamp: new Date().toISOString(),
  }));

  // Páginas públicas (cédula de propiedad) — sin auth, accesibles vía URL pública
  app.register(require('./public'));

  // API v1
  app.register(require('./v1/auth'), { prefix: '/api/v1/auth' });
  app.register(require('./v1/tenants'), { prefix: '/api/v1/tenants' });
  app.register(require('./v1/agents'), { prefix: '/api/v1/agents' });
  app.register(require('./v1/conversations'), { prefix: '/api/v1/conversations' });
  app.register(require('./v1/leads'), { prefix: '/api/v1/leads' });
  app.register(require('./v1/appointments'), { prefix: '/api/v1/appointments' });

  app.register(require('./v1/knowledge-base'), { prefix: '/api/v1/kb' });

  app.register(require('./v1/billing'), { prefix: '/api/v1/billing' });

  app.register(require('./v1/campaigns'),       { prefix: '/api/v1/campaigns' });
  app.register(require('./v1/notifications'),  { prefix: '/api/v1/notifications' });
  app.register(require('./v1/users'),        { prefix: '/api/v1/users' });
  app.register(require('./v1/reports'),      { prefix: '/api/v1/reports' });
  app.register(require('./v1/dashboard'),    { prefix: '/api/v1/dashboard' });
  app.register(require('./v1/superadmin'),   { prefix: '/api/v1/superadmin' });
  app.register(require('./v1/uploads'),      { prefix: '/api/v1/uploads' });
  app.register(require('./v1/simulator'),   { prefix: '/api/v1/simulator' });
  app.register(require('./v1/widget'),       { prefix: '/api/v1/widget' });
  app.register(require('./v1/products'),     { prefix: '/api/v1/products' });
  app.register(require('./v1/orders'),       { prefix: '/api/v1/orders' });
  app.register(require('./v1/vertical-packs'), { prefix: '/api/v1/vertical-packs' });

  // Módulo Clínica
  app.register(require('./v1/doctors'),       { prefix: '/api/v1/doctors' });
  app.register(require('./v1/service-types'), { prefix: '/api/v1/service-types' });

  // Módulo Consultorios
  app.register(require('./v1/professionals'),              { prefix: '/api/v1/professionals' });
  app.register(require('./v1/consultorio-session-types'),  { prefix: '/api/v1/consultorio/session-types' });
  app.register(require('./v1/qualification-questions'),    { prefix: '/api/v1/qualification-questions' });
  app.register(require('./v1/sessions'),                   { prefix: '/api/v1/sessions' });

  // Webhooks — Fase 1/3 (sin prefix /api, Twilio y Meta los llaman directo)
  app.register(require('./webhooks/twilio'),          { prefix: '/webhooks/twilio' });
  app.register(require('./webhooks/twilio-media-stream'), { prefix: '/webhooks/twilio' });
  app.register(require('./webhooks/twilio-outbound'), { prefix: '/webhooks/twilio' });
  app.register(require('./webhooks/meta'),            { prefix: '/webhooks/meta' });
  app.register(require('./webhooks/stripe'),          { prefix: '/webhooks/stripe' });

  app.log.info('✅ Rutas registradas');
}

module.exports = { registerRoutes };
