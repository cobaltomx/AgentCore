<?php
require_once __DIR__ . '/../includes/config.php';
header('Content-Type: application/json');
if (!isLoggedIn())                         { http_response_code(401); echo json_encode(['error'=>'No autorizado']); exit; }
if (!isAdmin())                            { http_response_code(403); echo json_encode(['error'=>'Solo admins']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$id     = trim($body['id']     ?? '');
$action = trim($body['action'] ?? '');
unset($body['id'], $body['action']);

// Limpiar opcionales nulos
foreach (['professional_id','session_type_id','disqualify_on'] as $k) {
    if (isset($body[$k]) && $body[$k] === '') $body[$k] = null;
}
if (isset($body['importance'])) $body['importance'] = (int)$body['importance'];
if (isset($body['sort_order'])) $body['sort_order'] = (int)$body['sort_order'];
if (isset($body['is_active']))  $body['is_active']  = filter_var($body['is_active'], FILTER_VALIDATE_BOOLEAN);

if ($action === 'delete') {
    $result = apiDelete("/qualification-questions/{$id}");
} elseif ($id) {
    $result = apiPatch("/qualification-questions/{$id}", $body);
} else {
    $result = apiPost('/qualification-questions', $body);
}
http_response_code($result['_status'] ?? 200);
echo json_encode($result);
