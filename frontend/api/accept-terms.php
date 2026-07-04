<?php
/**
 * Proxy: aceptar Términos de Servicio / Privacidad (modal bloqueante del
 * primer login). POST /api/accept-terms.php
 */
require_once __DIR__ . '/../includes/config.php';

requireAuth();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$result = apiPost('/auth/accept-terms', []);
$_SESSION['terms_accepted'] = true;

echo json_encode(['ok' => true]);
