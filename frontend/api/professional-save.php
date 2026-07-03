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

// Castear booleanos
if (isset($body['is_active'])) $body['is_active'] = filter_var($body['is_active'], FILTER_VALIDATE_BOOLEAN);

if ($action === 'delete') {
    $result = apiDelete("/professionals/{$id}");
} elseif ($id) {
    $result = apiPatch("/professionals/{$id}", $body);
} else {
    $result = apiPost('/professionals', $body);
}
http_response_code($result['_status'] ?? 200);
echo json_encode($result);
