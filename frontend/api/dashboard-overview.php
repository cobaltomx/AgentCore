<?php
/**
 * dashboard-overview.php
 * Proxy de solo lectura del cockpit, para el auto-refresh del dashboard (polling).
 * Devuelve el mismo JSON que GET /api/v1/dashboard/overview, scope-ado por la sesión.
 */
require_once __DIR__ . '/../includes/config.php';
requireAuth();

header('Content-Type: application/json');

$dash = apiGet('/dashboard/overview');
$status = $dash['_status'] ?? 500;
unset($dash['_status']);
http_response_code($status === 200 ? 200 : $status);
echo json_encode($dash, JSON_UNESCAPED_UNICODE);
