<?php
/**
 * insights-proxy.php — Voz del cliente
 * POST { action: 'analyze', limit? } → dispara el backfill de análisis
 */
require_once __DIR__ . '/../includes/config.php';
requireAuth();
header('Content-Type: application/json');

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? '';

if ($action === 'analyze') {
    $limit  = (int)($body['limit'] ?? 10);
    $result = apiPost('/reports/insights/analyze', ['limit' => $limit]);
    $status = $result['_status'] ?? 500;
    unset($result['_status']);
    http_response_code(in_array($status, [200, 201]) ? 200 : $status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Acción no válida']);
