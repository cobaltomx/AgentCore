<?php
/**
 * Proxy: Crear / actualizar / eliminar tipo de servicio
 * POST /api/service-type-save.php
 */
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json');

if (!isLoggedIn())                         { http_response_code(401); echo json_encode(['error' => 'No autorizado']); exit; }
if (!isAdmin())                            { http_response_code(403); echo json_encode(['error' => 'Solo admins']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$id     = trim($body['id']     ?? '');
$action = trim($body['action'] ?? '');
unset($body['id'], $body['action']);

// voice_keywords: string CSV → array
if (isset($body['voice_keywords']) && is_string($body['voice_keywords'])) {
    $body['voice_keywords'] = array_values(array_filter(
        array_map('trim', explode(',', $body['voice_keywords']))
    ));
}

// doctor_ids: asegurar que sea array (llega como array desde JS)
if (isset($body['doctor_ids']) && !is_array($body['doctor_ids'])) {
    $body['doctor_ids'] = array_values(array_filter(array_map('trim', (array)$body['doctor_ids'])));
}
// Remover doctor_id singular si viene (usamos doctor_ids)
unset($body['doctor_id']);

// Castear booleanos y números
if (isset($body['is_urgency']))       $body['is_urgency']       = filter_var($body['is_urgency'],       FILTER_VALIDATE_BOOLEAN);
if (isset($body['requires_deposit'])) $body['requires_deposit'] = filter_var($body['requires_deposit'], FILTER_VALIDATE_BOOLEAN);
if (isset($body['duration_mins']))    $body['duration_mins']    = (int)$body['duration_mins'];
if (isset($body['deposit_amount']))   $body['deposit_amount']   = (float)$body['deposit_amount'];

if ($action === 'delete') {
    $result = apiDelete("/service-types/{$id}");
} elseif ($id) {
    $result = apiPatch("/service-types/{$id}", $body);
} else {
    $result = apiPost('/service-types', $body);
}

http_response_code($result['_status'] ?? 200);
echo json_encode($result);
