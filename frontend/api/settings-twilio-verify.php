<?php
// api/settings-twilio-verify.php — Verificar credenciales Twilio
require_once __DIR__ . '/../includes/config.php';
header('Content-Type: application/json');
if (!isLoggedIn()) { http_response_code(401); echo json_encode(['error' => 'No autorizado']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

$body = json_decode(file_get_contents('php://input'), true) ?? [];

$result = apiPost('/tenants/twilio/verify', $body);
$status = $result['_status'] ?? 200;
unset($result['_status']);
http_response_code($status);
echo json_encode($result, JSON_UNESCAPED_UNICODE);
