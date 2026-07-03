<?php
/**
 * Proxy superadmin: crear nuevo tenant + admin
 * POST /api/superadmin-create-tenant.php
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

$body = json_decode(file_get_contents('php://input'), true);

$result = apiPost('/superadmin/tenants', $body);
http_response_code($result['_status'] ?? 200);
unset($result['_status']);
echo json_encode($result);
