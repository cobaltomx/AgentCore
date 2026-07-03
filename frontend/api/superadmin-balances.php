<?php
/**
 * Proxy superadmin: saldos de proveedores (Twilio/Deepgram/Anthropic/OpenAI/Stripe)
 * GET /api/superadmin-balances.php[?force=1]
 */
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json');

if (!isLoggedIn() || userRole() !== 'superadmin') {
    http_response_code(403);
    echo json_encode(['error' => 'Acceso denegado']);
    exit;
}

$force  = ($_GET['force'] ?? '') === '1' ? '?force=1' : '';
$result = apiGet('/superadmin/balances' . $force);
echo json_encode($result);
