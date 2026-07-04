<?php
/**
 * Política de Privacidad — página pública (sin autenticación).
 * Enlazada desde login.php y el footer del dashboard (todos los tenants).
 */
require_once __DIR__ . '/includes/config.php';
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Política de Privacidad — <?= e(APP_NAME) ?></title>
  <link rel="icon" type="image/x-icon" href="/assets/img/favicon.ico"/>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css"/>
  <link rel="stylesheet" href="/assets/css/agentcore-theme.css"/>
  <style>
    body { font-family:'Inter',sans-serif; background:#f7f8fa; color:#24292f; }
    .legal-wrap { max-width: 760px; margin: 0 auto; padding: 3rem 1.25rem 5rem; }
    .legal-wrap h1 { font-size: 1.5rem; font-weight: 700; margin-bottom: .25rem; }
    .legal-wrap .updated { color:#5c6572; font-size:.85rem; margin-bottom: 2rem; }
    .legal-wrap h2 { font-size: 1.05rem; font-weight: 600; margin-top: 2rem; margin-bottom: .5rem; }
    .legal-wrap p, .legal-wrap li { font-size: .95rem; line-height: 1.7; color:#24292f; }
    .legal-wrap ul { padding-left: 1.25rem; }
    .legal-wrap .back-link { display:inline-flex; align-items:center; gap:.35rem; margin-bottom:1.5rem; font-size:.9rem; }
  </style>
</head>
<body>
<a class="skip-link" href="#main">Saltar al contenido principal</a>
<div class="legal-wrap">
  <a href="/login.php" class="back-link">← Volver a AgentCore</a>
  <main id="main">
  <h1>Política de Privacidad — AgentCore</h1>
  <p class="updated">Última actualización: <?= date('d/m/Y') ?></p>

  <h2>1. Quiénes somos</h2>
  <p>AgentCore es responsable del tratamiento de tus datos personales. Contacto: <a href="mailto:soporte@agentcore.io">soporte@agentcore.io</a>.</p>

  <h2>2. Qué datos recogemos</h2>
  <ul>
    <li><strong>Datos de cuenta:</strong> nombre, correo electrónico, contraseña (cifrada con bcrypt).</li>
    <li><strong>Datos de uso:</strong> dirección IP, navegador, registros (logs) de actividad, páginas visitadas.</li>
    <li><strong>Contenido que subes:</strong> catálogo de productos/servicios, base de conocimiento, información de tu negocio, y las conversaciones (voz, WhatsApp, chat web) entre tus clientes y tus agentes de IA.</li>
    <li><strong>Datos de contacto de tus clientes finales:</strong> nombre y teléfono capturados por el agente de IA durante una llamada o conversación, para que puedas darles seguimiento.</li>
    <li><strong>Datos de pago:</strong> procesados por Stripe; nosotros NO almacenamos el número completo de tu tarjeta.</li>
  </ul>

  <h2>3. Para qué los usamos</h2>
  <p>Para prestar y mantener el servicio (que tus agentes de IA operen), procesar pagos, dar soporte, cumplir obligaciones legales y mejorar el producto.</p>

  <h2>4. Base legal</h2>
  <p>Tratamos tus datos con base en la ejecución del contrato de servicio, tu consentimiento al crear una cuenta, y nuestro interés legítimo en operar, dar soporte y mejorar la plataforma.</p>

  <h2>5. Con quién compartimos</h2>
  <p>Usamos los siguientes proveedores (subprocesadores) que tratan datos por nosotros, únicamente para operar el servicio:</p>
  <ul>
    <li><strong>Anthropic y OpenAI</strong> — procesamiento de lenguaje natural (el "cerebro" de tus agentes de IA).</li>
    <li><strong>Deepgram</strong> — transcripción y síntesis de voz.</li>
    <li><strong>Twilio</strong> — llamadas telefónicas y mensajería.</li>
    <li><strong>Meta (WhatsApp Business Platform)</strong> — mensajería de WhatsApp.</li>
    <li><strong>Stripe</strong> — procesamiento de pagos.</li>
  </ul>
  <p>No vendemos tus datos ni los de tus clientes a terceros.</p>

  <h2>6. Transferencias internacionales</h2>
  <p>Los proveedores listados en la sección anterior procesan datos principalmente en Estados Unidos. Aplicamos las salvaguardas contractuales que estos proveedores ofrecen para el tratamiento de datos.</p>

  <h2>7. Cuánto tiempo los guardamos</h2>
  <p>Mientras tengas una cuenta activa, y el tiempo adicional necesario para cumplir obligaciones legales o fiscales tras la cancelación.</p>

  <h2>8. Tus derechos</h2>
  <p>Puedes solicitar acceso, corrección, borrado, portabilidad u oposición al tratamiento de tus datos escribiendo a <a href="mailto:soporte@agentcore.io">soporte@agentcore.io</a>. Responderemos en el plazo que exija la ley aplicable.</p>

  <h2>9. Cookies</h2>
  <p>Usamos únicamente cookies estrictamente necesarias para mantener tu sesión iniciada (autenticación). No usamos cookies de publicidad ni de analítica de terceros.</p>

  <h2>10. Seguridad</h2>
  <p>Aplicamos medidas técnicas y organizativas para proteger tus datos: contraseñas cifradas con bcrypt, tokens de sesión firmados, conexiones cifradas (HTTPS/TLS) y validación de origen en nuestros webhooks.</p>

  <h2>11. Menores</h2>
  <p>El servicio no está dirigido a menores de 18 años.</p>

  <h2>12. Cambios</h2>
  <p>Podemos actualizar esta política; publicaremos la fecha de la última revisión al inicio de este documento.</p>

  <h2>13. Contacto</h2>
  <p>Responsable del tratamiento de datos: <a href="mailto:soporte@agentcore.io">soporte@agentcore.io</a>.</p>
  </main>
</div>
</body>
</html>
