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

// Casteos para crear serie
if ($action === 'create-series') {
    if (isset($body['total_sessions'])) $body['total_sessions'] = (int)$body['total_sessions'];
    if (isset($body['duration_mins']))  $body['duration_mins']  = (int)$body['duration_mins'];
    foreach (['professional_id','session_type_id','patient_email','notes'] as $k) {
        if (isset($body[$k]) && $body[$k] === '') $body[$k] = null;
    }
    $result = apiPost('/sessions/series', $body);
    http_response_code($result['_status'] ?? 200);
    echo json_encode($result);
    exit;
}

if ($action === 'confirm-series') {
    // Confirm all sessions in a series
    $result = apiPatch("/sessions/series/{$id}/confirm-all", $body);
} elseif ($action === 'delete') {
    $result = apiDelete("/sessions/{$id}");
} elseif ($id) {
    $result = apiPatch("/sessions/{$id}", $body);
} else {
    $result = apiPost('/sessions', $body);
}
http_response_code($result['_status'] ?? 200);
echo json_encode($result);
