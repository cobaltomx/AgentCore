'use strict';


const { logger } = require('./logger');
const log = logger('BalanceMonitor');
/**
 * Monitor de saldos de proveedores (Twilio, Deepgram, Anthropic, OpenAI).
 *
 * Nació de un incidente real: se agotó el crédito de LLM y nadie se enteró
 * hasta que las llamadas empezaron a morir en silencio. Este módulo:
 *   - Consulta saldos REALES donde hay API (Twilio Balance, Deepgram Balances).
 *   - Hace un "canario" mínimo (1 token) donde no la hay (Anthropic, OpenAI)
 *     para detectar cuentas agotadas o keys inválidas.
 *   - Cachea 10 min (el canario cuesta fracciones de centavo, no spamear).
 *   - checkAndNotify() corre en un worker cada 30 min y notifica al superadmin
 *     SOLO cuando el estado empeora (sin duplicar avisos).
 *
 * Estados: ok | low | out | error | unconfigured
 */

const TTL_MS = parseInt(process.env.BALANCE_CACHE_TTL_MS) || 10 * 60 * 1000;
const TWILIO_LOW_USD   = parseFloat(process.env.BALANCE_TWILIO_LOW)   || 5;
const DEEPGRAM_LOW_USD = parseFloat(process.env.BALANCE_DEEPGRAM_LOW) || 5;

let cache = { at: 0, data: null };
let inflight = null;

// ── Proveedores ──────────────────────────────────────────────────

async function twilioBalance() {
  if (!process.env.TWILIO_ACCOUNT_SID || !process.env.TWILIO_AUTH_TOKEN) {
    return { provider: 'twilio', label: 'Twilio (voz/WhatsApp)', status: 'unconfigured' };
  }
  try {
    const twilio = require('twilio')(process.env.TWILIO_ACCOUNT_SID, process.env.TWILIO_AUTH_TOKEN);
    const b = await twilio.balance.fetch();
    const amount = parseFloat(b.balance);
    return {
      provider: 'twilio', label: 'Twilio (voz/WhatsApp)',
      status: amount <= 1 ? 'out' : amount <= TWILIO_LOW_USD ? 'low' : 'ok',
      balance: amount, currency: b.currency || 'USD',
    };
  } catch (e) {
    return { provider: 'twilio', label: 'Twilio (voz/WhatsApp)', status: 'error', detail: e.message?.slice(0, 120) };
  }
}

async function deepgramBalance() {
  const key = process.env.DEEPGRAM_API_KEY;
  if (!key) return { provider: 'deepgram', label: 'Deepgram (voz STT/TTS)', status: 'unconfigured' };
  try {
    const headers = { Authorization: `Token ${key}` };
    const projects = await (await fetch('https://api.deepgram.com/v1/projects', { headers })).json();
    const pid = projects?.projects?.[0]?.project_id;
    if (!pid) return { provider: 'deepgram', label: 'Deepgram (voz STT/TTS)', status: 'error', detail: 'sin proyectos' };
    const bal = await (await fetch(`https://api.deepgram.com/v1/projects/${pid}/balances`, { headers })).json();
    const amount = (bal?.balances || []).reduce((s, b) => s + (parseFloat(b.amount) || 0), 0);
    return {
      provider: 'deepgram', label: 'Deepgram (voz STT/TTS)',
      status: amount <= 1 ? 'out' : amount <= DEEPGRAM_LOW_USD ? 'low' : 'ok',
      balance: amount, currency: 'USD',
    };
  } catch (e) {
    return { provider: 'deepgram', label: 'Deepgram (voz STT/TTS)', status: 'error', detail: e.message?.slice(0, 120) };
  }
}

/** Canario de 1 token: no hay API de saldo, pero un request mínimo revela
 *  si la cuenta está agotada (400 billing / 429 quota) o la key inválida. */
async function anthropicCanary() {
  if (!process.env.ANTHROPIC_API_KEY) return { provider: 'anthropic', label: 'Anthropic (cerebro LLM)', status: 'unconfigured' };
  try {
    const Anthropic = require('@anthropic-ai/sdk');
    const c = new Anthropic({ apiKey: process.env.ANTHROPIC_API_KEY, maxRetries: 0, timeout: 10000 });
    await c.messages.create({ model: process.env.LLM_MODEL_FAST || 'claude-haiku-4-5-20251001', max_tokens: 1, messages: [{ role: 'user', content: 'ok' }] });
    return { provider: 'anthropic', label: 'Anthropic (cerebro LLM)', status: 'ok' };
  } catch (e) {
    const msg = e.message || '';
    if (msg.includes('credit balance')) return { provider: 'anthropic', label: 'Anthropic (cerebro LLM)', status: 'out', detail: 'créditos agotados' };
    if (e.status === 401) return { provider: 'anthropic', label: 'Anthropic (cerebro LLM)', status: 'error', detail: 'API key inválida' };
    if (e.status === 429) return { provider: 'anthropic', label: 'Anthropic (cerebro LLM)', status: 'low', detail: 'rate limit' };
    return { provider: 'anthropic', label: 'Anthropic (cerebro LLM)', status: 'error', detail: msg.slice(0, 120) };
  }
}

async function openaiCanary() {
  if (!process.env.OPENAI_API_KEY) return { provider: 'openai', label: 'OpenAI (LLM respaldo)', status: 'unconfigured' };
  try {
    const OpenAI = require('openai');
    const c = new OpenAI({ apiKey: process.env.OPENAI_API_KEY, maxRetries: 0, timeout: 10000 });
    await c.chat.completions.create({ model: 'gpt-4o-mini', max_tokens: 1, messages: [{ role: 'user', content: 'ok' }] });
    return { provider: 'openai', label: 'OpenAI (LLM respaldo)', status: 'ok' };
  } catch (e) {
    const msg = e.message || '';
    if (e.status === 429 && /quota|billing/i.test(msg)) return { provider: 'openai', label: 'OpenAI (LLM respaldo)', status: 'out', detail: 'cuota agotada' };
    if (e.status === 401) return { provider: 'openai', label: 'OpenAI (LLM respaldo)', status: 'error', detail: 'API key inválida' };
    if (e.status === 429) return { provider: 'openai', label: 'OpenAI (LLM respaldo)', status: 'low', detail: 'rate limit' };
    return { provider: 'openai', label: 'OpenAI (LLM respaldo)', status: 'error', detail: msg.slice(0, 120) };
  }
}

function stripeMode() {
  const k = process.env.STRIPE_SECRET_KEY || '';
  if (!k || k.includes('placeholder')) return { provider: 'stripe', label: 'Stripe (cobros)', status: 'unconfigured' };
  return { provider: 'stripe', label: 'Stripe (cobros)', status: k.startsWith('sk_live') ? 'ok' : 'low', detail: k.startsWith('sk_live') ? 'modo live' : 'modo TEST (no cobra de verdad)' };
}

// ── API pública ──────────────────────────────────────────────────

/** Todos los saldos, cacheados TTL_MS. force=true ignora el caché. */
async function getBalances({ force = false } = {}) {
  if (!force && cache.data && Date.now() - cache.at < TTL_MS) return cache.data;
  if (inflight) return inflight;   // no duplicar canarios concurrentes
  inflight = (async () => {
    const [tw, dg, an, oa] = await Promise.all([twilioBalance(), deepgramBalance(), anthropicCanary(), openaiCanary()]);
    const providers = [an, oa, dg, tw, stripeMode()];
    const worst = ['out', 'error', 'low', 'unconfigured', 'ok']
      .find(s => providers.some(p => p.status === s)) || 'ok';
    const data = { checkedAt: new Date().toISOString(), overall: worst, providers };
    cache = { at: Date.now(), data };
    inflight = null;
    return data;
  })();
  return inflight;
}

/** Chequea y notifica al superadmin cuando un proveedor EMPEORA de estado.
 *  Guarda el último estado en Redis para no duplicar avisos. */
async function checkAndNotify(db, redis) {
  const SEV = { ok: 0, unconfigured: 0, low: 1, error: 2, out: 3 };
  const data = await getBalances({ force: true });
  let lastRaw = null;
  try { lastRaw = await redis.get('balance:laststate'); } catch {}
  const last = lastRaw ? JSON.parse(lastRaw) : {};

  for (const p of data.providers) {
    const prev = last[p.provider] || 'ok';
    if (SEV[p.status] > SEV[prev] && SEV[p.status] >= 1) {
      try {
        const { createNotification } = require('./notifications');
        const platform = await db.query(`SELECT id FROM tenants WHERE slug='agentcore-platform' LIMIT 1`);
        if (platform.rows[0]) {
          const icon = p.status === 'out' ? '🔴' : p.status === 'error' ? '⛔' : '🟡';
          await createNotification(db, {
            tenantId: platform.rows[0].id,
            type: 'balance_alert',
            title: `${icon} ${p.label}: ${p.status === 'out' ? 'SALDO AGOTADO' : p.status === 'low' ? 'saldo bajo' : 'error de cuenta'}`,
            body: p.detail || (p.balance != null ? `Saldo: $${p.balance.toFixed(2)} ${p.currency}` : null),
            link: '/pages/admin/operations.php',
          });
        }
      } catch (e) { log.warn('[BalanceMonitor] no se pudo notificar:', e.message); }
    }
    last[p.provider] = p.status;
  }
  try { await redis.set('balance:laststate', JSON.stringify(last)); } catch {}
  return data;
}

module.exports = { getBalances, checkAndNotify };
