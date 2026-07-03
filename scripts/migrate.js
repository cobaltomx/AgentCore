#!/usr/bin/env node
'use strict';

/**
 * Migration runner de AgentCore.
 *
 * Antes: 20 .sql aplicados a mano, sin registro → drift garantizado entre
 * dev y producción. Ahora una tabla `schema_migrations` registra qué se aplicó,
 * y este script aplica SOLO lo pendiente, en una transacción por archivo.
 *
 * Uso (desde backend/ o con DATABASE_URL en el entorno):
 *   node scripts/migrate.js            → aplica migraciones pendientes
 *   node scripts/migrate.js --status   → lista aplicadas vs. pendientes
 *   node scripts/migrate.js --baseline → marca TODAS las existentes como
 *                                        aplicadas SIN ejecutarlas (para una
 *                                        BD que ya las tiene, como la actual)
 *
 * Convención para NUEVAS migraciones: prefijo ordenable, p.ej.
 *   scripts/migrate-2026-07-03-descripcion.sql
 * (el orden de aplicación es alfabético por nombre de archivo).
 *
 * `init.sql` y `schema.sql` son schema base/consolidado (los carga Postgres en
 * docker-entrypoint-initdb) y quedan EXCLUIDOS del runner.
 */

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { Pool } = require('pg');

const SCRIPTS_DIR = __dirname;
const args = process.argv.slice(2);
const MODE = args.includes('--baseline') ? 'baseline'
           : args.includes('--status')   ? 'status'
           : 'apply';

const BASE_SCHEMAS = new Set(['init.sql', 'schema.sql']);
function migrationFiles() {
  return fs.readdirSync(SCRIPTS_DIR)
    .filter(f => f.endsWith('.sql') && !BASE_SCHEMAS.has(f))
    .sort();   // orden alfabético = orden de aplicación
}

function checksum(file) {
  return crypto.createHash('md5')
    .update(fs.readFileSync(path.join(SCRIPTS_DIR, file), 'utf8'))
    .digest('hex');
}

async function ensureTable(db) {
  await db.query(`
    CREATE TABLE IF NOT EXISTS schema_migrations (
      filename    TEXT PRIMARY KEY,
      checksum    TEXT NOT NULL,
      applied_at  TIMESTAMPTZ NOT NULL DEFAULT now()
    )`);
}

async function appliedSet(db) {
  const r = await db.query('SELECT filename, checksum FROM schema_migrations');
  return new Map(r.rows.map(x => [x.filename, x.checksum]));
}

async function main() {
  const conn = process.env.DATABASE_URL;
  if (!conn) { console.error('❌ Falta DATABASE_URL en el entorno.'); process.exit(1); }
  const db = new Pool({ connectionString: conn });
  await ensureTable(db);

  const files   = migrationFiles();
  const applied = await appliedSet(db);
  const pending = files.filter(f => !applied.has(f));

  // Detectar drift: archivos aplicados cuyo contenido cambió después.
  for (const f of files) {
    if (applied.has(f) && applied.get(f) !== checksum(f)) {
      console.warn(`⚠️  ${f}: el contenido cambió DESPUÉS de aplicarse (checksum distinto). No se re-aplica; crea una migración nueva.`);
    }
  }

  if (MODE === 'status') {
    console.log(`\nMigraciones: ${applied.size} aplicadas, ${pending.length} pendientes de ${files.length}\n`);
    for (const f of files) console.log(`  ${applied.has(f) ? '✅' : '⬜'} ${f}`);
    await db.end();
    return;
  }

  if (MODE === 'baseline') {
    if (!pending.length) { console.log('Nada que registrar: todas ya están en schema_migrations.'); await db.end(); return; }
    for (const f of pending) {
      await db.query('INSERT INTO schema_migrations (filename, checksum) VALUES ($1,$2) ON CONFLICT DO NOTHING', [f, checksum(f)]);
      console.log(`📌 baseline: ${f} marcado como aplicado (sin ejecutar)`);
    }
    console.log(`\n✅ Baseline completo: ${pending.length} migraciones registradas.`);
    await db.end();
    return;
  }

  // MODE === 'apply'
  if (!pending.length) { console.log('✅ Sin migraciones pendientes.'); await db.end(); return; }
  console.log(`Aplicando ${pending.length} migración(es) pendiente(s)...\n`);
  for (const f of pending) {
    const sql = fs.readFileSync(path.join(SCRIPTS_DIR, f), 'utf8');
    const client = await db.connect();
    try {
      await client.query('BEGIN');
      await client.query(sql);
      await client.query('INSERT INTO schema_migrations (filename, checksum) VALUES ($1,$2)', [f, checksum(f)]);
      await client.query('COMMIT');
      console.log(`  ✅ ${f}`);
    } catch (e) {
      await client.query('ROLLBACK');
      console.error(`  ❌ ${f} falló (rollback): ${e.message}`);
      client.release();
      await db.end();
      process.exit(1);
    }
    client.release();
  }
  console.log(`\n✅ ${pending.length} migración(es) aplicada(s).`);
  await db.end();
}

main().catch(e => { console.error('Error fatal:', e.message); process.exit(1); });
