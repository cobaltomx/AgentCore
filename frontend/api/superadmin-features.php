<?php
/**
 * Proxy superadmin: features on/off de un tenant
 * GET  /api/superadmin-features.php?id=<uuid>          → GET   /superadmin/tenants/:id/features
 * POST /api/superadmin-features.php { id, features:{} } → PATCH /superadmin/tenants/:id/features
 *   features: { key: true|false|null }  (null = heredar del plan)
 */
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json');

if (!isLoggedIn() || userRole() !== 'superadmin') {
    http_response_code(403);
    echo json_encode(['error' => 'Acceso denegado']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $id = trim($_GET['id'] ?? '');
    if (!$id || !isValidUuid($id)) {
        http_response_code(400);
        echo json_encode(['error' => 'ID inválido']);
        exit;
    }
    $result = apiGet("/superadmin/tenants/{$id}/features");
    http_response_code($result['_status'] ?? 200);
    unset($result['_status']);
    echo json_encode($result);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$id   = trim($body['id'] ?? '');
if (!$id || !isValidUuid($id)) {
    http_response_code(400);
    echo json_encode(['error' => 'ID inválido']);
    exit;
}

$result = apiPatch("/superadmin/tenants/{$id}/features", ['features' => $body['features'] ?? []]);
http_response_code($result['_status'] ?? 200);
unset($result['_status']);
echo json_encode($result);
