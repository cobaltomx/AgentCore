<?php
/**
 * Términos de Servicio — página pública (sin autenticación).
 * Enlazada desde login.php y el footer del dashboard (todos los tenants).
 */
require_once __DIR__ . '/includes/config.php';
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Términos de Servicio — <?= e(APP_NAME) ?></title>
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
    .legal-wrap .back-link { display:inline-flex; align-items:center; gap:.35rem; margin-bottom:1.5rem; font-size:.9rem; }
  </style>
</head>
<body>
<a class="skip-link" href="#main">Saltar al contenido principal</a>
<div class="legal-wrap">
  <a href="/login.php" class="back-link">← Volver a AgentCore</a>
  <main id="main">
  <h1>Términos de Servicio — AgentCore</h1>
  <p class="updated">Última actualización: <?= date('d/m/Y') ?></p>

  <h2>1. Aceptación</h2>
  <p>Al crear una cuenta o usar AgentCore aceptas estos Términos. Si no estás de acuerdo, no uses el servicio.</p>

  <h2>2. Descripción del servicio</h2>
  <p>AgentCore es un software como servicio (SaaS) que provee agentes de inteligencia artificial (voz, WhatsApp y chat web) para automatizar ventas, agendamiento de citas y atención al cliente de negocios (los "Tenants"). Podemos modificar, suspender o discontinuar funciones en cualquier momento.</p>

  <h2>3. Cuentas</h2>
  <p>Eres responsable de la actividad de tu cuenta y de mantener tu contraseña segura. Debes tener al menos 18 años para contratar el servicio.</p>

  <h2>4. Pagos y renovación</h2>
  <p>Los planes se cobran de forma mensual y se renuevan automáticamente hasta que canceles. Los precios pueden cambiar con aviso previo. Los impuestos aplicables corren según tu jurisdicción.</p>

  <h2>5. Cancelaciones y reembolsos</h2>
  <p>Puedes cancelar cuando quieras desde tu panel. No ofrecemos reembolsos por períodos ya facturados; la cancelación detiene los cobros futuros pero no genera devolución de lo ya cobrado.</p>

  <h2>6. Uso aceptable</h2>
  <p>No puedes: usar el servicio para actividades ilegales, enviar spam, vulnerar la seguridad, hacer ingeniería inversa, ni revender el acceso sin autorización.</p>

  <h2>7. Contenido del usuario</h2>
  <p>Conservas la propiedad del contenido que subes (catálogos, información de tu negocio, conversaciones). Nos otorgas una licencia limitada para alojarlo y procesarlo con el único fin de operar el servicio.</p>

  <h2>8. Propiedad intelectual</h2>
  <p>El software, marca y diseño de AgentCore son nuestra propiedad.</p>

  <h2>9. Limitación de responsabilidad</h2>
  <p>El servicio se ofrece "tal cual" y "según disponibilidad". En la medida permitida por la ley, no somos responsables por daños indirectos o pérdida de datos/ganancias. Nuestra responsabilidad total se limita a lo que pagaste en los últimos 6 meses.</p>

  <h2>10. Indemnización</h2>
  <p>Aceptas indemnizarnos por reclamos derivados de tu uso indebido del servicio.</p>

  <h2>11. Terminación</h2>
  <p>Podemos suspender o cerrar cuentas que violen estos Términos.</p>

  <h2>12. Ley aplicable y disputas</h2>
  <p>Estos Términos se rigen por las leyes federales de los Estados Unidos Mexicanos, con jurisdicción de los tribunales competentes de Querétaro, México, renunciando a cualquier otro fuero que pudiera corresponder por razón de domicilio presente o futuro.</p>

  <h2>13. Resolución de disputas por arbitraje</h2>
  <p>Cualquier disputa relacionada con estos Términos se resolverá mediante arbitraje vinculante individual, y no en un tribunal ni mediante demanda colectiva, salvo donde la ley lo prohíba. Renuncias al derecho a participar en una acción de clase.</p>

  <h2>14. Cambios</h2>
  <p>Podemos actualizar estos Términos; te avisaremos por correo electrónico o mediante aviso en la aplicación. El uso continuado del servicio implica la aceptación de los cambios.</p>

  <h2>15. Contacto</h2>
  <p>Para dudas sobre estos Términos, escríbenos a <a href="mailto:soporte@agentcore.io">soporte@agentcore.io</a>.</p>
  </main>
</div>
</body>
</html>
