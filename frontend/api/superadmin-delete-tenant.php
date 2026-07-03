<?php
/**
 * Proxy superadmin: eliminar un tenant (destructivo)
 * POST /api/superadmin-delete-tenant.php  { id, confirm: "<slug>" }
 *   → DELETE /superadmin/tenants/:id?confirm=<slug>
 */
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json');

if (!isLoggedIn() || userRole() !== 'superadmin') {
    http_response_code(403);
    echo json_encode(['error' => 'Acceso denegado']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$body    = json_decode(file_get_contents('php://input'), true) ?: [];
$id      = trim($body['id'] ?? '');
$confirm = trim($body['confirm'] ?? '');
if (!$id || !isValidUuid($id)) {
    http_response_code(400);
    echo json_encode(['error' => 'ID inválido']);
    exit;
}

$result = apiDelete("/superadmin/tenants/{$id}?confirm=" . rawurlencode($confirm));
http_response_code($result['_status'] ?? 200);
unset($result['_status']);
echo json_encode($result);
