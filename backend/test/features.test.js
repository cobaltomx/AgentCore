'use strict';

const { test } = require('node:test');
const assert   = require('node:assert');
const { mockDb } = require('./_helpers');
const {
  computeEffective,
  tenantEffectiveFeatures,
  tenantHasFeature,
} = require('../src/services/features');

test('computeEffective: sin overrides devuelve las del plan', () => {
  assert.deepEqual(computeEffective(['voice', 'webchat'], {}), ['voice', 'webchat']);
});

test('computeEffective: override false quita una feature del plan', () => {
  assert.deepEqual(computeEffective(['voice', 'webchat'], { webchat: false }), ['voice']);
});

test('computeEffective: override true agrega una feature fuera del plan', () => {
  assert.deepEqual(
    computeEffective(['voice'], { insights: true }).sort(),
    ['insights', 'voice']
  );
});

test('computeEffective: ignora claves desconocidas del catálogo', () => {
  assert.deepEqual(computeEffective(['voice'], { fake_feature: true }), ['voice']);
});

test('computeEffective: plan vacío + override true', () => {
  assert.deepEqual(computeEffective([], { voice: true }), ['voice']);
});

test('computeEffective: tolera planFeatures no-array', () => {
  assert.deepEqual(computeEffective(null, { voice: true }), ['voice']);
});

test('tenantEffectiveFeatures: combina plan y settings.features del tenant', async () => {
  const db = mockDb([
    ['plan_features', [{ plan_features: ['voice', 'whatsapp'], settings: { features: { whatsapp: false } } }]],
  ]);
  const feats = await tenantEffectiveFeatures(db, 't1');
  assert.deepEqual(feats, ['voice']);
});

test('tenantEffectiveFeatures: tenant inexistente → []', async () => {
  const db = mockDb([['plan_features', []]]);
  assert.deepEqual(await tenantEffectiveFeatures(db, 'nope'), []);
});

test('tenantHasFeature: true cuando está en efectivas', async () => {
  const db = mockDb([['plan_features', [{ plan_features: ['voice'], settings: {} }]]]);
  assert.equal(await tenantHasFeature(db, 't1', 'voice'), true);
});

test('tenantHasFeature: false cuando override la apaga', async () => {
  const db = mockDb([['plan_features', [{ plan_features: ['voice', 'webchat'], settings: { features: { webchat: false } } }]]]);
  assert.equal(await tenantHasFeature(db, 't1', 'webchat'), false);
});

test('tenantHasFeature: fail-open ante error de BD', async () => {
  const db = { query: async () => { throw new Error('db down'); } };
  assert.equal(await tenantHasFeature(db, 't1', 'voice'), true);
});
