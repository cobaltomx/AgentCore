#!/usr/bin/env node
/**
 * setup-ea.js — Configura Easy!Appointments via API
 *
 * Ejecutar DESPUÉS de:
 *   1. docker-compose up -d easyappointments mariadb
 *   2. Visitar http://localhost:8082 y completar el wizard de instalación
 *   3. Ir a Configuración → API → Habilitar API y copiar el token a EA_API_TOKEN en .env
 *
 * Uso:
 *   node scripts/setup-ea.js --token=TU_API_TOKEN [--url=http://localhost:8082]
 *
 * Qué hace:
 *   - Crea el servicio "Consulta" si no existe
 *   - Crea el proveedor con horario L-V 9-18h si no existe
 *   - Imprime los IDs para poner en .env (EA_SERVICE_ID, EA_PROVIDER_ID)
 */

'use strict';

const args = Object.fromEntries(
  process.argv.slice(2).map(a => {
    const [k, v] = a.replace(/^--/, '').split('=');
    return [k, v];
  })
);

const BASE_URL  = args.url   || process.env.EA_BASE_URL || 'http://localhost:8082';
const API_TOKEN = args.token || process.env.EA_API_TOKEN;

if (!API_TOKEN) {
  console.error('❌ Falta EA_API_TOKEN. Pásalo con --token=xxx o pon EA_API_TOKEN en .env');
  process.exit(1);
}

const headers = {
  'Authorization': `Bearer ${API_TOKEN}`,
  'Content-Type':  'application/json',
};

async function request(method, path, body) {
  const res = await fetch(`${BASE_URL}/index.php/api/v1${path}`, {
    method,
    headers,
    body: body ? JSON.stringify(body) : undefined,
  });
  const text = await res.text();
  if (!res.ok) throw new Error(`${method} ${path} → ${res.status}: ${text.slice(0, 200)}`);
  return text ? JSON.parse(text) : {};
}

async function main() {
  console.log(`\n🔗 Conectando a Easy!Appointments: ${BASE_URL}\n`);

  // ── 1. Verificar conexión ────────────────────────────────────
  try {
    await request('GET', '/services');
    console.log('✅ API accesible');
  } catch (err) {
    console.error('❌ No se pudo conectar a EA:', err.message);
    console.error('   Asegúrate de que EA esté corriendo y el token sea válido');
    process.exit(1);
  }

  // ── 2. Crear servicio ────────────────────────────────────────
  let services = await request('GET', '/services');
  let service  = services.find(s => s.name === 'Consulta');

  if (!service) {
    console.log('📋 Creando servicio "Consulta"...');
    service = await request('POST', '/services', {
      name:           'Consulta',
      duration:       60,          // minutos
      price:          0,
      currency:       'MXN',
      description:    'Cita agendada por el asistente virtual',
      availabilities_type: 'flexible',
      attendants_number:   1,
    });
    console.log(`   ✅ Servicio creado: id=${service.id}`);
  } else {
    console.log(`   ℹ️  Servicio existente: id=${service.id} "${service.name}"`);
  }

  // ── 3. Crear proveedor ───────────────────────────────────────
  let providers = await request('GET', '/providers');
  let provider  = providers.find(p => p.email === 'proveedor@agentcore.local');

  if (!provider) {
    console.log('👤 Creando proveedor...');
    provider = await request('POST', '/providers', {
      first_name:  'Proveedor',
      last_name:   'AgentCore',
      email:       'proveedor@agentcore.local',
      phone:       '0000000000',
      address:     '',
      city:        '',
      zip_code:    '',
      notes:       'Proveedor configurado automáticamente',
      timezone:    'America/Mexico_City',
      settings: {
        username:     'proveedor',
        notifications: false,
        google_sync:   false,
        working_plan: {
          monday:    { start: '09:00', end: '18:00', breaks: [{ start: '14:00', end: '15:00' }] },
          tuesday:   { start: '09:00', end: '18:00', breaks: [{ start: '14:00', end: '15:00' }] },
          wednesday: { start: '09:00', end: '18:00', breaks: [{ start: '14:00', end: '15:00' }] },
          thursday:  { start: '09:00', end: '18:00', breaks: [{ start: '14:00', end: '15:00' }] },
          friday:    { start: '09:00', end: '18:00', breaks: [{ start: '14:00', end: '15:00' }] },
          saturday:  null,
          sunday:    null,
        },
      },
      services: [service.id],
    });
    console.log(`   ✅ Proveedor creado: id=${provider.id}`);
  } else {
    console.log(`   ℹ️  Proveedor existente: id=${provider.id} "${provider.first_name} ${provider.last_name}"`);
  }

  // ── 4. Resultado ─────────────────────────────────────────────
  console.log(`
╔══════════════════════════════════════════════════╗
║   Easy!Appointments configurado correctamente    ║
╠══════════════════════════════════════════════════╣
║  Agrega estas líneas a tu .env:                  ║
║                                                  ║
║  EA_SERVICE_ID=${String(service.id).padEnd(33)}║
║  EA_PROVIDER_ID=${String(provider.id).padEnd(32)}║
╚══════════════════════════════════════════════════╝

Panel web: ${BASE_URL}
Personaliza nombre/horario del proveedor desde el panel.
`);
}

main().catch(err => {
  console.error('❌ Error inesperado:', err.message);
  process.exit(1);
});
