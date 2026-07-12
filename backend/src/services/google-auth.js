'use strict';

const { OAuth2Client } = require('google-auth-library');
const { logger } = require('./logger');
const log = logger('GoogleAuth');

/**
 * Verificación de "Iniciar sesión con Google" (Google Identity Services).
 *
 * Usa la librería oficial de Google (maneja rotación de llaves, expiración,
 * audiencia — código de seguridad crítico que NO se debe reimplementar a mano).
 * Solo requiere GOOGLE_CLIENT_ID (sin secret: el flujo de ID token no lo usa).
 *
 * Degradación elegante: sin GOOGLE_CLIENT_ID configurado, isConfigured() es
 * false y el frontend no debe mostrar el botón.
 */
function isConfigured() {
  return !!process.env.GOOGLE_CLIENT_ID;
}

let client = null;
function getClient() {
  if (!client) client = new OAuth2Client(process.env.GOOGLE_CLIENT_ID);
  return client;
}

/**
 * Verifica el ID token (credential) que manda el botón de Google.
 * @returns {Promise<{email:string, emailVerified:boolean, googleId:string, name:string, picture:string}|null>}
 *          null si el token es inválido/expirado/de otra app.
 */
async function verifyGoogleToken(credential) {
  if (!isConfigured() || !credential) return null;
  try {
    const ticket = await getClient().verifyIdToken({
      idToken: credential,
      audience: process.env.GOOGLE_CLIENT_ID,
    });
    const payload = ticket.getPayload();
    if (!payload || !payload.email) return null;
    return {
      email: String(payload.email).toLowerCase(),
      emailVerified: !!payload.email_verified,
      googleId: payload.sub,
      name: payload.name || '',
      picture: payload.picture || '',
    };
  } catch (e) {
    log.warn({ err: e.message }, 'token de Google inválido');
    return null;
  }
}

module.exports = { isConfigured, verifyGoogleToken };
