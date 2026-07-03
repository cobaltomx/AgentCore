'use strict';

/**
 * Gate de runtime de VOZ (webhook simulado de Twilio).
 * Verifica que una llamada entrante se cuelga cuando el tenant NO tiene la
 * feature `voice`, y procede normalmente cuando sí la tiene.
 */

const { test } = require('node:test');
const assert   = require('node:assert');
const { mockDb, buildApp } = require('./_helpers');
const twilioRoutes = require('../src/routes/webhooks/twilio');

// Filas comunes: agente de voz + tenant activo con minutos disponibles.
function baseRoutes(planFeatures) {
  return [
    ["a.channel = 'voice'", [{
      id: 'agent1', tenant_id: 't1', name: 'Recepcionista',
      config: { greeting: 'Clínica Test, ¿en qué le puedo ayudar?' },
      tenant_name: 'Clínica Test', tenant_settings: {},
    }]],
    ['minutes_used_mo, status', [{
      max_minutes_mo: 1000, minutes_used_mo: 0, status: 'active', is_ready: true,
    }]],
    ['plan_features', [{ plan_features: planFeatures, settings: {} }]],
  ];
}

async function callVoice(planFeatures) {
  const app = await buildApp(twilioRoutes, mockDb(baseRoutes(planFeatures)));
  const res = await app.inject({
    method: 'POST',
    url: '/voice',
    headers: { 'content-type': 'application/x-www-form-urlencoded' },
    payload: 'CallSid=CA_TEST&From=%2B5215511112222&To=%2B5215500000000',
  });
  await app.close();
  return res.body;
}

test('voz OFF → la llamada se rechaza y cuelga', async () => {
  const body = await callVoice(['webchat']); // sin 'voice'
  assert.match(body, /no está disponible/i);
  assert.match(body, /<Hangup\s*\/>/i);
});

test('voz ON → la llamada procede (no aparece el rechazo)', async () => {
  const body = await callVoice(['voice']);
  assert.doesNotMatch(body, /no está disponible/i);
  // En el camino feliz se devuelve <Connect><Stream> o el saludo + <Gather>
  assert.ok(/<Connect|<Stream|<Gather|le puedo ayudar/i.test(body), 'esperaba TwiML de continuación');
});
