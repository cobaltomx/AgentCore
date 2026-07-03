'use strict';
const path = require('path');
const fs   = require('fs');
const { pipeline } = require('stream/promises');
const crypto = require('crypto');

const ALLOWED_MIME = {
  'image/jpeg': 'jpg',
  'image/jpg':  'jpg',
  'image/png':  'png',
  'image/webp': 'webp',
  'image/gif':  'gif',
};

const ALLOWED_ENTITIES = ['doctor', 'professional', 'user', 'tenant'];

async function uploadsRoutes(app) {

  // POST /api/v1/uploads/avatar?entity=doctor&id=<uuid>
  // multipart/form-data — campo "file"
  app.post('/avatar', { onRequest: [app.requireAdmin] }, async (req, reply) => {
    const entity = String(req.query.entity || '').toLowerCase();
    const id     = String(req.query.id || '').trim();

    if (!ALLOWED_ENTITIES.includes(entity)) {
      return reply.code(400).send({ error: 'Entity inválida (doctor|professional|user|tenant)' });
    }

    // Modificar otro tenant requiere superadmin
    if (entity === 'tenant' && id && id !== req.tenant.id && req.role !== 'superadmin') {
      return reply.code(403).send({ error: 'No puedes modificar otro tenant' });
    }

    const file = await req.file();
    if (!file) return reply.code(400).send({ error: 'Sin archivo (campo "file")' });

    const ext = ALLOWED_MIME[file.mimetype];
    if (!ext) return reply.code(400).send({ error: 'Tipo no permitido (jpg, png, webp, gif)' });

    const tenantDir = path.join(app.uploadsDir, 'avatars', req.tenant.id);
    fs.mkdirSync(tenantDir, { recursive: true });

    const safeId   = id ? id.replace(/[^a-z0-9-]/gi, '') : crypto.randomBytes(6).toString('hex');
    const filename = `${entity}-${safeId}-${Date.now()}.${ext}`;
    const filepath = path.join(tenantDir, filename);

    try {
      await pipeline(file.file, fs.createWriteStream(filepath));
    } catch (err) {
      app.log.error({ err }, 'Avatar upload failed');
      return reply.code(500).send({ error: 'Error guardando archivo' });
    }

    if (file.file.truncated) {
      try { fs.unlinkSync(filepath); } catch {}
      return reply.code(413).send({ error: 'Archivo excede el límite (4 MB)' });
    }

    const relUrl = `/uploads/avatars/${req.tenant.id}/${filename}`;

    // Persistir el URL en la entidad (best-effort — el cliente también puede
    // pasarlo al PATCH de la entidad si prefiere control explícito).
    if (id) {
      try {
        const tableMap = {
          doctor:       { table: 'doctors',       where: 'id=$2 AND tenant_id=$3' },
          professional: { table: 'professionals', where: 'id=$2 AND tenant_id=$3' },
          user:         { table: 'users',         where: 'id=$2 AND tenant_id=$3' },
          tenant:       { table: 'tenants',       where: 'id=$2' }, // tenant_id == id
        };
        const cfg = tableMap[entity];
        const vals = entity === 'tenant' ? [relUrl, id] : [relUrl, id, req.tenant.id];
        await app.db.query(
          `UPDATE ${cfg.table} SET avatar_url=$1 WHERE ${cfg.where}`,
          vals
        );
      } catch (err) {
        app.log.warn({ err }, 'Avatar URL no se pudo persistir; cliente debe hacer PATCH manual');
      }
    }

    return reply.send({ ok: true, url: relUrl });
  });

  // POST /api/v1/uploads/property-image
  // Sube UNA foto de propiedad y devuelve su URL. El cliente la agrega a la
  // galería del producto (campo images) vía PATCH /products/:id.
  app.post('/property-image', { onRequest: [app.requireAdmin] }, async (req, reply) => {
    const file = await req.file();
    if (!file) return reply.code(400).send({ error: 'Sin archivo (campo "file")' });
    const ext = ALLOWED_MIME[file.mimetype];
    if (!ext) return reply.code(400).send({ error: 'Tipo no permitido (jpg, png, webp, gif)' });

    const dir = path.join(app.uploadsDir, 'properties', req.tenant.id);
    fs.mkdirSync(dir, { recursive: true });
    const filename = `prop-${crypto.randomBytes(8).toString('hex')}.${ext}`;
    const filepath = path.join(dir, filename);
    try {
      await pipeline(file.file, fs.createWriteStream(filepath));
    } catch (err) {
      app.log.error({ err }, 'Property image upload failed');
      return reply.code(500).send({ error: 'Error guardando archivo' });
    }
    if (file.file.truncated) {
      try { fs.unlinkSync(filepath); } catch {}
      return reply.code(413).send({ error: 'Archivo excede el límite (4 MB)' });
    }
    return reply.send({ ok: true, url: `/uploads/properties/${req.tenant.id}/${filename}` });
  });

  // DELETE /api/v1/uploads/avatar?entity=...&id=...
  // Borra el archivo y vacía avatar_url
  app.delete('/avatar', { onRequest: [app.requireAdmin] }, async (req, reply) => {
    const entity = String(req.query.entity || '').toLowerCase();
    const id     = String(req.query.id || '').trim();
    if (!ALLOWED_ENTITIES.includes(entity) || !id) {
      return reply.code(400).send({ error: 'Parámetros inválidos' });
    }
    if (entity === 'tenant' && id !== req.tenant.id && req.role !== 'superadmin') {
      return reply.code(403).send({ error: 'No puedes modificar otro tenant' });
    }

    const tableMap = {
      doctor:       'doctors',
      professional: 'professionals',
      user:         'users',
      tenant:       'tenants',
    };
    const table = tableMap[entity];

    const r = await app.db.query(
      `SELECT avatar_url FROM ${table} WHERE id=$1${entity === 'tenant' ? '' : ' AND tenant_id=$2'}`,
      entity === 'tenant' ? [id] : [id, req.tenant.id]
    );
    const url = r.rows[0]?.avatar_url;
    if (url && url.startsWith('/uploads/')) {
      const filepath = path.join(app.uploadsDir, url.replace(/^\/uploads\//, ''));
      try { fs.unlinkSync(filepath); } catch {}
    }
    await app.db.query(
      `UPDATE ${table} SET avatar_url=NULL WHERE id=$1${entity === 'tenant' ? '' : ' AND tenant_id=$2'}`,
      entity === 'tenant' ? [id] : [id, req.tenant.id]
    );

    return reply.send({ ok: true });
  });
}

module.exports = uploadsRoutes;
