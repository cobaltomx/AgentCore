<?php
// api/billing-portal.php — Abrir portal de Stripe
require_once __DIR__ . '/../includes/config.php';
header('Content-Type: application/json');

if (!isLoggedIn()) { http_response_code(401); echo json_encode(['error' => 'No autorizado']); exit; }

$result = apiPost('/billing/portal', []);
http_response_code($result['_status'] ?? 200);
echo json_encode($result);
