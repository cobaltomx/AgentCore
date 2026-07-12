'use strict';

const bcrypt = require('bcryptjs');
const { z }  = require('zod');
const { isValidPassword, PASSWORD_POLICY_ERROR } = require('../../services/password-policy');

const passwordField = z.string().refine(isValidPassword, { message: PASSWORD_POLICY_ERROR });

const createUserSchema = z.object({
  name:       z.string().min(2).max(120),
  email:      z.string().email(),
  password:   passwordField,
  role:       z.enum(['admin', 'user']).default('user'),
  avatar_url: z.string().max(500).optional().nullable(),
});

const updateUserSchema = z.object({
  name:       z.string().min(2).max(120).optional(),
  role:       z.enum(['admin', 'user']).optional(),
  is_active:  z.boolean().optional(),
  password:   passwordField.optional(),
  avatar_url: z.string().max(500).optional().nullable(),
});

async function usersRoutes(app) {

  // GET /api/v1/users — listar usuarios del tenant
  app.get('/', { onRequest: [app.requireAdmin] }, async (request) => {
    const result = await app.db.query(
      `SELECT id, name, email, role, is_active, avatar_url, last_login_at, created_at
       FROM users
       WHERE tenant_id = $1
       ORDER BY created_at ASC`,
      [request.tenant.id]
    );
    return result.rows;
  });

  // POST /api/v1/users — crear usuario en el tenant
  app.post('/', { onRequest: [app.requireAdmin] }, async (request, reply) => {
    const parsed = createUserSchema.safeParse(request.body);
    if (!parsed.success) {
      // Mensaje legible para el campo con error
      const issues = parsed.error.issues;
      const first  = issues[0];
      const fieldMap = { name: 'Nombre', email: 'Correo', password: 'Contraseña', role: 'Rol' };
      const fieldLabel = fieldMap[first?.path?.[0]] || first?.path?.[0] || 'Campo';
      const msg = first?.message === 'String must contain at least 8 character(s)'
        ? `${fieldLabel}: mínimo 8 caracteres`
        : first?.message === 'Invalid email'
        ? 'El correo no tiene formato válido'
        : `${fieldLabel}: ${first?.message}`;
      return reply.code(400).send({ error: msg });
    }

    const { name, email, password, role, avatar_url } = parsed.data;

    // No permitir que un admin cree otro superadmin
    if (role === 'superadmin') {
      return reply.code(403).send({ error: 'No puedes crear un superadmin' });
    }

    // Verificar que el email no exista
    const existing = await app.db.query('SELECT id FROM users WHERE email = $1', [email]);
    if (existing.rows[0]) {
      return reply.code(409).send({ error: 'El correo ya está registrado' });
    }

    const passwordHash = await bcrypt.hash(password, 10);

    const result = await app.db.query(
      `INSERT INTO users (tenant_id, name, email, password_hash, role, avatar_url)
       VALUES ($1, $2, $3, $4, $5, $6)
       RETURNING id, name, email, role, is_active, avatar_url, created_at`,
      [request.tenant.id, name, email, passwordHash, role, avatar_url || null]
    );

    return reply.code(201).send(result.rows[0]);
  });

  // PATCH /api/v1/users/:id — actualizar usuario
  app.patch('/:id', { onRequest: [app.requireAdmin] }, async (request, reply) => {
    const parsed = updateUserSchema.safeParse(request.body);
    if (!parsed.success) {
      return reply.code(400).send({ error: 'Datos inválidos', details: parsed.error.flatten() });
    }

    // Verificar que el usuario pertenece al tenant
    const existing = await app.db.query(
      'SELECT id, role FROM users WHERE id = $1 AND tenant_id = $2',
      [request.params.id, request.tenant.id]
    );
    if (!existing.rows[0]) {
      return reply.code(404).send({ error: 'Usuario no encontrado' });
    }

    // Un admin no puede cambiar el rol de otro admin a superadmin
    if (parsed.data.role === 'superadmin') {
      return reply.code(403).send({ error: 'No puedes asignar rol superadmin' });
    }

    // No puede modificarse a sí mismo el rol o is_active
    if (request.params.id === request.userId &&
        (parsed.data.role !== undefined || parsed.data.is_active === false)) {
      return reply.code(400).send({ error: 'No puedes cambiar tu propio rol o desactivar tu cuenta' });
    }

    const d = parsed.data;
    const fields = [];
    const values = [];
    let idx = 1;

    if (d.name       !== undefined) { fields.push(`name = $${idx}`);          values.push(d.name);          idx++; }
    if (d.role       !== undefined) { fields.push(`role = $${idx}`);          values.push(d.role);          idx++; }
    if (d.is_active  !== undefined) { fields.push(`is_active = $${idx}`);     values.push(d.is_active);     idx++; }
    if (d.avatar_url !== undefined) { fields.push(`avatar_url = $${idx}`);    values.push(d.avatar_url);    idx++; }
    if (d.password   !== undefined) {
      const hash = await bcrypt.hash(d.password, 10);
      fields.push(`password_hash = $${idx}`);
      values.push(hash);
      idx++;
    }

    if (!fields.length) return reply.code(400).send({ error: 'Sin campos para actualizar' });

    values.push(request.params.id);
    const result = await app.db.query(
      `UPDATE users SET ${fields.join(', ')}, updated_at = NOW()
       WHERE id = $${idx}
       RETURNING id, name, email, role, is_active, avatar_url, updated_at`,
      values
    );

    return result.rows[0];
  });

  // DELETE /api/v1/users/:id — desactivar usuario (soft delete)
  app.delete('/:id', { onRequest: [app.requireAdmin] }, async (request, reply) => {
    if (request.params.id === request.userId) {
      return reply.code(400).send({ error: 'No puedes desactivar tu propia cuenta' });
    }

    const result = await app.db.query(
      `UPDATE users SET is_active = false, updated_at = NOW()
       WHERE id = $1 AND tenant_id = $2
       RETURNING id, name, email`,
      [request.params.id, request.tenant.id]
    );

    if (!result.rows[0]) return reply.code(404).send({ error: 'Usuario no encontrado' });
    return { message: 'Usuario desactivado', user: result.rows[0] };
  });
}

module.exports = usersRoutes;
