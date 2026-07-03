'use strict';

/**
 * Gate de runtime de WHATSAPP (webhook simulado de Meta).
 * El handler responde 200 de inmediato y procesa en background (setImmediate),
 * así que espiamos WhatsAppAgent.prototype.handleMessage para comprobar si el
 * agente llega a procesar el mensaje o el gate lo bloquea antes.
 */

const { test } = require('node:test');
const assert   = require('node:assert');
const { mockDb, buildApp, tick } = require('./_helpers');

const metaWebhooks   = require('../src/routes/webhooks/meta');
const WhatsAppAgent  = require('../src/agents/whatsapp-agent');

// Payload mínimo de un mensaje de texto entrante de WhatsApp.
const WA_PAYLOAD = {
  object: 'whatsapp_business_account',
  entry: [{
    changes: [{
      value: {
        metadata: { phone_number_id: 'PHONE123', display_phone_number: '+5215500000000' },
        contacts: [{ profile: { name: 'Test' }, wa_id: '5215511112222' }],
        messages: [{ id: 'wamid.TEST', from: '5215511112222', timestamp: '1700000000', type: 'text', text: { body: 'hola' } }],
      },
    }],
  }],
};

function routes(planFeatures) {
  return [
    ['phoneNumberId', [{ id: 't1', is_ready: true }]],          // resolución de tenant
    ['plan_features', [{ plan_features: planFeatures, settings: {} }]],
    ['SELECT settings FROM tenants WHERE id=$1', [{ settings: {} }]], // markAsRead
  ];
}

async function deliver(planFeatures) {
  // Neutralizar firma y token de Meta: así createMetaClient() → null y no se
  // hace ninguna llamada de red (markAsRead), volviendo el flujo determinista.
  const saved = { sec: process.env.META_APP_SECRET, tok: process.env.META_WHATSAPP_TOKEN };
  delete process.env.META_APP_SECRET;
  delete process.env.META_WHATSAPP_TOKEN;

  const calls = [];
  const orig = WhatsAppAgent.prototype.handleMessage;
  WhatsAppAgent.prototype.handleMessage = async function (...args) { calls.push(args); };

  try {
    const app = await buildApp(metaWebhooks, mockDb(routes(planFeatures)));
    const res = await app.inject({ method: 'POST', url: '/', payload: WA_PAYLOAD });
    assert.equal(res.statusCode, 200);           // Meta siempre recibe 200 rápido
    await tick(80);                              // dejar correr el setImmediate
    await app.close();
    return calls;
  } finally {
    WhatsAppAgent.prototype.handleMessage = orig;
    if (saved.sec !== undefined) process.env.META_APP_SECRET = saved.sec;
    if (saved.tok !== undefined) process.env.META_WHATSAPP_TOKEN = saved.tok;
  }
}

test('whatsapp OFF → el agente NO procesa el mensaje', async () => {
  const calls = await deliver(['voice']); // sin 'whatsapp'
  assert.equal(calls.length, 0);
});

test('whatsapp ON → el agente SÍ procesa el mensaje', async () => {
  const calls = await deliver(['whatsapp']);
  assert.equal(calls.length, 1);
  assert.equal(calls[0][1], 't1'); // handleMessage(parsed, tenantId)
});
