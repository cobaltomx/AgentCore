<?php
// api/agent-simulate.php — Simular respuesta del agente IA
require_once __DIR__ . '/../includes/config.php';
header('Content-Type: application/json');
if (!isLoggedIn()) { http_response_code(401); echo json_encode(['error'=>'No autorizado']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

$body    = json_decode(file_get_contents('php://input'), true) ?? [];
$agentId = trim($body['agent_id'] ?? '');
$message = trim($body['message']  ?? '');
$history = is_array($body['history'] ?? null) ? $body['history'] : [];

if (!$agentId || !$message) {
    http_response_code(400);
    echo json_encode(['error' => 'agent_id y message son requeridos']);
    exit;
}

// Validar formato UUID para evitar path injection
if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $agentId)) {
    http_response_code(400);
    echo json_encode(['error' => 'agent_id inválido']);
    exit;
}

// Limitar longitud del mensaje
if (mb_strlen($message) > 2000) {
    http_response_code(400);
    echo json_encode(['error' => 'Mensaje demasiado largo (máximo 2000 caracteres)']);
    exit;
}

$payload = ['message' => $message, 'history' => array_slice($history, -10)];
$result  = apiPost("/agents/{$agentId}/simulate", $payload);
$status  = $result['_status'] ?? 200;
unset($result['_status']);
http_response_code(in_array($status, [200,201]) ? 200 : $status);
echo json_encode($result, JSON_UNESCAPED_UNICODE);
