<?php
/**
 * product-save.php — CRUD de productos del catálogo
 * POST { action:'create'|'update'|'delete', ...campos }
 */
require_once __DIR__ . '/../includes/config.php';
requireAuth();
if (!isAdmin()) { http_response_code(403); echo json_encode(['error'=>'No autorizado']); exit; }
header('Content-Type: application/json');

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? '';

function productPayload(array $b): array {
    $p = [];
    foreach (['name','description','category','sku','image_url'] as $f) {
        if (array_key_exists($f, $b) && $b[$f] !== '') $p[$f] = $b[$f];
    }
    if (isset($b['price'])  && $b['price'] !== '')  $p['price']  = (float)$b['price'];
    if (isset($b['stock']) && $b['stock'] !== '')  $p['stock']  = (int)$b['stock'];
    if (isset($b['is_active']))                     $p['is_active'] = (bool)$b['is_active'];
    if (isset($b['attributes']) && is_array($b['attributes'])) $p['attributes'] = $b['attributes'];
    if (isset($b['images'])     && is_array($b['images']))     $p['images']     = $b['images'];
    return $p;
}

if ($action === 'create') {
    $result = apiPost('/products', productPayload($body));
} elseif ($action === 'update') {
    $id = trim($body['id'] ?? '');
    if (!$id || !isValidUuid($id)) { http_response_code(400); echo json_encode(['error'=>'id inválido']); exit; }
    $result = apiPatch("/products/{$id}", productPayload($body));
} elseif ($action === 'delete') {
    $id = trim($body['id'] ?? '');
    if (!$id || !isValidUuid($id)) { http_response_code(400); echo json_encode(['error'=>'id inválido']); exit; }
    $result = apiDelete("/products/{$id}" . (!empty($body['hard']) ? '?hard=1' : ''));
} else {
    http_response_code(400); echo json_encode(['error'=>'Acción no válida']); exit;
}

$status = $result['_status'] ?? 500;
unset($result['_status']);
http_response_code(in_array($status, [200,201]) ? ($action==='create'?201:200) : $status);
echo json_encode($result, JSON_UNESCAPED_UNICODE);
