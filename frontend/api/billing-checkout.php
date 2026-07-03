<?php
// api/billing-checkout.php — Iniciar checkout Stripe
require_once __DIR__ . '/../includes/config.php';
header('Content-Type: application/json');

if (!isLoggedIn()) { http_response_code(401); echo json_encode(['error' => 'No autorizado']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

$body   = json_decode(file_get_contents('php://input'), true);
$result = apiPost('/billing/checkout', ['plan' => $body['plan'] ?? '']);

http_response_code($result['_status'] ?? 200);
echo json_encode($result);
