<?php
/**
 * API Proxy: Lead status change
 * POST /api/lead-status.php
 * { id, status }
 */
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'No autorizado']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$body   = json_decode(file_get_contents('php://input'), true);
$id     = trim($body['id']     ?? '');
$status = trim($body['status'] ?? '');

if (!$id || !$status) {
    http_response_code(400);
    echo json_encode(['error' => 'Faltan parámetros']);
    exit;
}
requireUuid($id, 'id');

// Whitelist de etapas del pipeline (debe coincidir con el backend).
$allowedStatuses = ['new', 'contacted', 'qualified', 'converted', 'loyal', 'lost'];
if (!in_array($status, $allowedStatuses, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Estado inválido']);
    exit;
}

$result = apiPatch("/leads/{$id}/status", ['status' => $status]);
http_response_code($result['_status'] ?? 200);
echo json_encode($result);
