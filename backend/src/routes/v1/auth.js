'use strict';

const bcrypt = require('bcryptjs');
const { z }  = require('zod');
const { randomUUID } = require('crypto');
const { isValidPassword, PASSWORD_POLICY_ERROR } = require('../../services/password-policy');

const loginSchema = z.object({
  email: z.string().email(),
  password: z.string().min(8),
});

async function authRoutes(app) {

  // POST /api/v1/auth/login — rate limit: 10 intentos / 15 min por IP
  app.post('/login', {
    config: {
      rateLimit: { max: 10, timeWindow: '15 minutes' },
    },
  }, async (request, reply) => {
    const parsed = loginSchema.safeParse(request.body);
    if (!parsed.success) {
      return reply.code(400).send({ error: 'Datos inválidos', details: parsed.error.flatten() });
    }

    const { email, password } = parsed.data;

    const result = await app.db.query(
      `SELECT u.id, u.tenant_id, u.email, u.name, u.role, u.password_hash, u.is_active, u.avatar_url,
              u.terms_accepted_at,
              t.slug as tenant_slug, t.name as tenant_name, t.plan, t.status as tenant_status,
              t.max_agents, t.max_minutes_mo, t.minutes_used_mo, t.settings as tenant_settings,
              t.avatar_url as tenant_avatar_url, t.is_ready, t.setup_steps
       FROM users u
       JOIN tenants t ON t.id = u.tenant_id
       WHERE u.email = $1`,
      [email]
    );

    const user = result.rows[0];

    if (!user || !user.is_active) {
      return reply.code(401).send({ error: 'Credenciales incorrectas' });
    }

    if (user.tenant_status !== 'active' && user.tenant_status !== 'trial') {
      return reply.code(403).send({ error: 'Cuenta suspendida. Contacta soporte.' });
    }

    const validPassword = await bcrypt.compare(password, user.password_hash);
    if (!validPassword) {
      return reply.code(401).send({ error: 'Credenciales incorrectas' });
    }

    return reply.code(200).send(await issueSession(app, user));
  });

  // Emite JWT + sesión Redis + payload de usuario — comparte esta lógica
  // /login (password) y /google (OAuth) para no duplicarla ni divergir.
  async function issueSession(app, user) {
    await app.db.query('UPDATE users SET last_login_at = NOW() WHERE id = $1', [user.id]);

    const token = app.jwt.sign(
      {
        user_id: user.id,
        tenant_id: user.tenant_id,
        tenant_slug: user.tenant_slug,
        role: user.role,
      },
      { expiresIn: process.env.JWT_EXPIRES_IN || '7d' }
    );

    await app.redis.setex(
      `session:${user.id}`,
      7 * 24 * 3600,
      JSON.stringify({ user_id: user.id, tenant_id: user.tenant_id })
    );

    return {
      token,
      user: {
        id:              user.id,
        email:           user.email,
        name:            user.name,
        role:            user.role,
        avatar_url:      user.avatar_url,
        terms_accepted:  !!user.terms_accepted_at,
        tenant: {
          id:              user.tenant_id,
          slug:            user.tenant_slug,
          name:            user.tenant_name,
          plan:            user.plan,
          avatar_url:      user.tenant_avatar_url,
          max_agents:      user.max_agents,
          max_minutes_mo:  user.max_minutes_mo,
          minutes_used_mo: user.minutes_used_mo,
          settings:        user.tenant_settings ?? {},
          is_ready:        user.is_ready ?? false,
          setup_steps:     user.setup_steps ?? {},
        },
      },
    };
  }

  // POST /api/v1/auth/google — "Iniciar sesión con Google". Vincula a una
  // cuenta YA EXISTENTE por email (este SaaS no tiene auto-registro: los
  // usuarios los da de alta un admin/superadmin dentro de un tenant). Si el
  // email de Google no coincide con ningún usuario, NO crea cuenta — pide
  // que solicite acceso a su administrador.
  app.post('/google', {
    config: { rateLimit: { max: 10, timeWindow: '15 minutes' } },
  }, async (request, reply) => {
    const { credential } = request.body || {};
    if (!credential) return reply.code(400).send({ error: 'Falta el token de Google' });

    const { isConfigured, verifyGoogleToken } = require('../../services/google-auth');
    if (!isConfigured()) return reply.code(503).send({ error: 'Inicio de sesión con Google no disponible' });

    const g = await verifyGoogleToken(credential);
    if (!g || !g.emailVerified) return reply.code(401).send({ error: 'Token de Google inválido' });

    const result = await app.db.query(
      `SELECT u.id, u.tenant_id, u.email, u.name, u.role, u.is_active, u.avatar_url, u.terms_accepted_at,
              t.slug as tenant_slug, t.name as tenant_name, t.plan, t.status as tenant_status,
              t.max_agents, t.max_minutes_mo, t.minutes_used_mo, t.settings as tenant_settings,
              t.avatar_url as tenant_avatar_url, t.is_ready, t.setup_steps
       FROM users u
       JOIN tenants t ON t.id = u.tenant_id
       WHERE LOWER(u.email) = $1`,
      [g.email]
    );
    const user = result.rows[0];

    if (!user || !user.is_active) {
      return reply.code(404).send({
        error: `No existe una cuenta con ${g.email}. Pide a tu administrador que te dé de alta primero.`,
      });
    }
    if (user.tenant_status !== 'active' && user.tenant_status !== 'trial') {
      return reply.code(403).send({ error: 'Cuenta suspendida. Contacta soporte.' });
    }

    // Vincular el google_id la primera vez (no bloquea el login si falla).
    await app.db.query('UPDATE users SET google_id = $1 WHERE id = $2 AND google_id IS NULL', [g.googleId, user.id])
      .catch(() => {});

    return reply.code(200).send(await issueSession(app, user));
  });

  // PATCH /auth/me — actualizar perfil propio (nombre, password, avatar_url)
  app.patch('/me', { onRequest: [app.authenticate] }, async (request, reply) => {
    const { name, current_password, new_password, avatar_url } = request.body || {};
    const userId = request.user.user_id;

    const fields = [];
    const values = [];
    let idx = 1;

    if (name !== undefined) {
      if (typeof name !== 'string' || name.trim().length < 2) {
        return reply.code(400).send({ error: 'El nombre debe tener al menos 2 caracteres' });
      }
      fields.push(`name = $${idx++}`);
      values.push(name.trim());
    }

    if (new_password !== undefined) {
      if (!current_password) return reply.code(400).send({ error: 'Debes ingresar tu contraseña actual' });
      if (!isValidPassword(new_password)) return reply.code(400).send({ error: PASSWORD_POLICY_ERROR });

      // Verificar contraseña actual
      const userRow = await app.db.query('SELECT password_hash FROM users WHERE id = $1', [userId]);
      const valid = await bcrypt.compare(current_password, userRow.rows[0]?.password_hash || '');
      if (!valid) return reply.code(401).send({ error: 'La contraseña actual es incorrecta' });

      const hash = await bcrypt.hash(new_password, 12);
      fields.push(`password_hash = $${idx++}`);
      values.push(hash);
    }

    if (avatar_url !== undefined) {
      fields.push(`avatar_url = $${idx++}`);
      values.push(avatar_url || null);
    }

    if (!fields.length) return reply.code(400).send({ error: 'Sin campos para actualizar' });

    values.push(userId);
    const result = await app.db.query(
      `UPDATE users SET ${fields.join(', ')}, updated_at = NOW()
       WHERE id = $${idx}
       RETURNING id, name, email, role, avatar_url, updated_at`,
      values
    );

    return result.rows[0];
  });

  // POST /api/v1/auth/forgot-password — rate limit: 5 intentos / 15 min por IP
  // (evita enumeración de emails y, cuando se active el envío real, spam masivo)
  app.post('/forgot-password', {
    config: { rateLimit: { max: 5, timeWindow: '15 minutes' } },
  }, async (request, reply) => {
    const { email } = request.body || {};
    if (!email) return reply.code(400).send({ error: 'Email requerido' });

    const result = await app.db.query(
      'SELECT id, name, email FROM users WHERE LOWER(email) = LOWER($1) AND is_active = true',
      [email]
    );
    const user = result.rows[0];

    // Siempre responder OK para no revelar si el email existe
    if (!user) {
      return { ok: true, message: 'Si el correo existe, recibirás un enlace.' };
    }

    const token  = randomUUID().replace(/-/g, '');
    const ttlSec = 3600; // 1 hora
    await app.redis.setex(`pwreset:${token}`, ttlSec, user.id);

    const appUrl = process.env.PUBLIC_URL || process.env.APP_URL || 'http://localhost:8080';
    const resetLink = `${appUrl}/reset-password.php?token=${token}`;

    // Envío real por email (si hay proveedor configurado). Degrada con
    // elegancia: en dev sin proveedor, devolvemos el link en la respuesta.
    const { sendEmail, isConfigured, wrapHtml } = require('../../services/email');
    const html = wrapHtml(
      'Restablece tu contraseña',
      `Hola${user.name ? ' ' + user.name : ''}, recibimos una solicitud para restablecer tu contraseña de AgentCore. El enlace expira en 1 hora. Si no fuiste tú, ignora este correo.`,
      'Restablecer contraseña', resetLink);
    const emailResult = await sendEmail({
      to: user.email,
      subject: 'Restablece tu contraseña — AgentCore',
      html,
      text: `Restablece tu contraseña de AgentCore (expira en 1 hora): ${resetLink}`,
    });

    const isDev = (process.env.NODE_ENV || 'development') !== 'production';
    return {
      ok: true,
      message: emailResult.sent
        ? 'Si el correo existe, recibirás un enlace para restablecer tu contraseña.'
        : 'Enlace generado.',
      // Solo exponer el link en dev cuando NO se envió por correo (sin proveedor).
      ...((isDev && !emailResult.sent) && { reset_link: resetLink, expires_in: '1 hora' }),
    };
  });

  // POST /api/v1/auth/reset-password — rate limit: 10 intentos / 15 min por IP
  app.post('/reset-password', {
    config: { rateLimit: { max: 10, timeWindow: '15 minutes' } },
  }, async (request, reply) => {
    const { token, password } = request.body || {};
    if (!token || !password) return reply.code(400).send({ error: 'Token y contraseña requeridos' });
    if (!isValidPassword(password)) return reply.code(400).send({ error: PASSWORD_POLICY_ERROR });

    const userId = await app.redis.get(`pwreset:${token}`);
    if (!userId) return reply.code(400).send({ error: 'Token inválido o expirado' });

    const hash = await bcrypt.hash(password, 12);
    const result = await app.db.query(
      'UPDATE users SET password_hash = $1, updated_at = NOW() WHERE id = $2 RETURNING id, email',
      [hash, userId]
    );

    if (!result.rows[0]) return reply.code(404).send({ error: 'Usuario no encontrado' });

    // Invalidar token
    await app.redis.del(`pwreset:${token}`);
    // Invalidar sesiones activas
    await app.redis.del(`session:${userId}`);

    return { ok: true, message: 'Contraseña actualizada correctamente' };
  });

  // GET /api/v1/auth/validate-reset-token
  app.get('/validate-reset-token', async (request, reply) => {
    const { token } = request.query || {};
    if (!token) return reply.code(400).send({ valid: false });
    const userId = await app.redis.get(`pwreset:${token}`);
    if (!userId) return reply.code(400).send({ valid: false, error: 'Token inválido o expirado' });
    return { valid: true };
  });

  // POST /api/v1/auth/logout
  app.post('/logout', { onRequest: [app.authenticate] }, async (request, reply) => {
    await app.redis.del(`session:${request.user.user_id}`);
    return reply.code(200).send({ message: 'Sesión cerrada' });
  });

  // GET /api/v1/auth/me
  app.get('/me', { onRequest: [app.authenticate] }, async (request, reply) => {
    const result = await app.db.query(
      `SELECT u.id, u.email, u.name, u.role, u.avatar_url,
              t.id as tenant_id, t.slug, t.name as tenant_name, t.plan, t.avatar_url as tenant_avatar_url,
              t.max_agents, t.max_minutes_mo, t.minutes_used_mo,
              t.settings as tenant_settings
       FROM users u JOIN tenants t ON t.id = u.tenant_id
       WHERE u.id = $1`,
      [request.user.user_id]
    );

    if (!result.rows[0]) return reply.code(404).send({ error: 'Usuario no encontrado' });

    const row = result.rows[0];
    return {
      user: {
        id:         row.id,
        email:      row.email,
        name:       row.name,
        role:       row.role,
        avatar_url: row.avatar_url,
      },
      tenant: {
        id:              row.tenant_id,
        slug:            row.slug,
        name:            row.tenant_name,
        plan:            row.plan,
        avatar_url:      row.tenant_avatar_url,
        max_agents:      row.max_agents,
        max_minutes_mo:  row.max_minutes_mo,
        minutes_used_mo: row.minutes_used_mo,
        settings:        row.tenant_settings ?? {},
      },
    };
  });

  // POST /api/v1/auth/accept-terms — registra la aceptación de Términos de
  // Servicio y Privacidad (modal bloqueante en el primer login).
  app.post('/accept-terms', { onRequest: [app.authenticate] }, async (request, reply) => {
    await app.db.query(
      `UPDATE users SET terms_accepted_at = NOW() WHERE id = $1 AND terms_accepted_at IS NULL`,
      [request.user.user_id]
    );
    return reply.code(200).send({ ok: true });
  });
}

module.exports = authRoutes;
