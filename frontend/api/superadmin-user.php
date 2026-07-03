<?php
/**
 * Proxy superadmin: gestión de usuarios de un tenant
 * POST /api/superadmin-user.php
 *   action = "create" → POST /superadmin/tenants/:tenant_id/users
 *   action = "toggle" → PATCH /superadmin/users/:user_id  { is_active }
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

$body   = json_decode(file_get_contents('php://input'), true);
$action = $body['action'] ?? '';

if ($action === 'create') {
    $tenantId = trim($body['tenant_id'] ?? '');
    if (!$tenantId) {
        http_response_code(400);
        echo json_encode(['error' => 'tenant_id requerido']);
        exit;
    }
    $payload = [
        'name'     => $body['name']     ?? '',
        'email'    => $body['email']    ?? '',
        'password' => $body['password'] ?? '',
        'role'     => $body['role']     ?? 'user',
    ];
    $result = apiPost("/superadmin/tenants/{$tenantId}/users", $payload);
    http_response_code($result['_status'] ?? 201);
    unset($result['_status']);
    echo json_encode($result);

} elseif ($action === 'toggle') {
    $userId = trim($body['user_id'] ?? '');
    if (!$userId) {
        http_response_code(400);
        echo json_encode(['error' => 'user_id requerido']);
        exit;
    }
    $result = apiPatch("/superadmin/users/{$userId}", [
        'is_active' => (bool)($body['is_active'] ?? false),
    ]);
    http_response_code($result['_status'] ?? 200);
    unset($result['_status']);
    echo json_encode($result);

} elseif ($action === 'update') {
    // Editar rol / nombre / restablecer contraseña
    $userId = trim($body['user_id'] ?? '');
    if (!$userId) {
        http_response_code(400);
        echo json_encode(['error' => 'user_id requerido']);
        exit;
    }
    $payload = [];
    foreach (['role', 'name', 'password', 'is_active'] as $f) {
        if (isset($body[$f]) && $body[$f] !== '') $payload[$f] = $body[$f];
    }
    if (!$payload) {
        http_response_code(400);
        echo json_encode(['error' => 'Sin cambios']);
        exit;
    }
    $result = apiPatch("/superadmin/users/{$userId}", $payload);
    http_response_code($result['_status'] ?? 200);
    unset($result['_status']);
    echo json_encode($result);

} else {
    http_response_code(400);
    echo json_encode(['error' => 'Acción no reconocida']);
}
