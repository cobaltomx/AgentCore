<?php
/**
 * Proxy superadmin: editar un tenant
 * POST /api/superadmin-tenant.php
 *   action = "basic"    → PATCH /superadmin/tenants/:id   (name, plan, status, max_agents, max_minutes_mo)
 *   action = "settings" → PATCH /superadmin/tenants/:id/settings (businessProfile, tone, objective…)
 *   action = "status"   → PATCH /superadmin/tenants/:id   (status solamente)
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
$id     = trim($body['id']     ?? '');
$action = trim($body['action'] ?? 'basic');
unset($body['id'], $body['action']);

if (!$id) {
    http_response_code(400);
    echo json_encode(['error' => 'ID requerido']);
    exit;
}

if ($action === 'settings') {
    $result = apiPatch("/superadmin/tenants/{$id}/settings", $body);
} else {
    // basic | status
    $result = apiPatch("/superadmin/tenants/{$id}", $body);
}

http_response_code($result['_status'] ?? 200);
unset($result['_status']);
echo json_encode($result);
