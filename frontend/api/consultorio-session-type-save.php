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

// voice_keywords: CSV → array
if (isset($body['voice_keywords']) && is_string($body['voice_keywords'])) {
    $body['voice_keywords'] = array_values(array_filter(array_map('trim', explode(',', $body['voice_keywords']))));
}
// professional_ids ya viene como array desde JS
if (isset($body['professional_ids']) && !is_array($body['professional_ids'])) {
    $body['professional_ids'] = array_values(array_filter((array)$body['professional_ids']));
}

// Casteos
foreach (['requires_deposit','is_active'] as $k) {
    if (isset($body[$k])) $body[$k] = filter_var($body[$k], FILTER_VALIDATE_BOOLEAN);
}
foreach (['duration_mins','session_count','deposit_percent','cancellation_hours','sort_order'] as $k) {
    if (isset($body[$k])) $body[$k] = (int)$body[$k];
}
foreach (['deposit_fixed','price_per_session'] as $k) {
    if (isset($body[$k]) && $body[$k] !== '' && $body[$k] !== null) $body[$k] = (float)$body[$k];
    elseif (isset($body[$k])) $body[$k] = null;
}

if ($action === 'delete') {
    $result = apiDelete("/consultorio/session-types/{$id}");
} elseif ($id) {
    $result = apiPatch("/consultorio/session-types/{$id}", $body);
} else {
    $result = apiPost('/consultorio/session-types', $body);
}
http_response_code($result['_status'] ?? 200);
echo json_encode($result);
