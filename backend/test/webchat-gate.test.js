'use strict';

/**
 * Gate de runtime de WEBCHAT (widget público).
 * - /config refleja enabled=false cuando la feature `webchat` está apagada.
 * - /message rechaza sin invocar al LLM cuando está apagada.
 */

const { test } = require('node:test');
const assert   = require('node:assert');
const { mockDb, buildApp } = require('./_helpers');
const widgetRoutes = require('../src/routes/v1/widget');

const KEY = 'wgt_testkey000000000000000000000000';

function routes(planFeatures) {
  return [
    ['widget_key=$1', [{
      id: 't1', name: 'Negocio Test', status: 'active', is_ready: true,
      settings: { widget: { enabled: true } },
    }]],
    ['plan_features', [{ plan_features: planFeatures, settings: {} }]],
  ];
}

test('webchat OFF → /config enabled=false', async () => {
  const app = await buildApp(widgetRoutes, mockDb(routes([])));
  const res = await app.inject({ method: 'GET', url: `/config?key=${KEY}` });
  await app.close();
  assert.equal(res.json().enabled, false);
});

test('webchat ON → /config enabled=true', async () => {
  const app = await buildApp(widgetRoutes, mockDb(routes(['webchat'])));
  const res = await app.inject({ method: 'GET', url: `/config?key=${KEY}` });
  await app.close();
  assert.equal(res.json().enabled, true);
});

test('webchat OFF → /message rechaza con ended=true', async () => {
  const app = await buildApp(widgetRoutes, mockDb(routes([])));
  const res = await app.inject({
    method: 'POST',
    url: '/message',
    payload: { key: KEY, visitor_id: 'visitor_test_1', text: 'hola' },
  });
  await app.close();
  const j = res.json();
  assert.equal(j.ended, true);
  assert.match(j.reply, /no está disponible/i);
});
