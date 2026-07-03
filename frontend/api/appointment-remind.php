<?php
require_once __DIR__ . '/../includes/config.php';
requireAuth();

header('Content-Type: application/json');

$body = json_decode(file_get_contents('php://input'), true) ?? [];
// Acepta un id suelto o una lista de ids (la tarjeta "Necesita tu atención"
// recuerda en lote todas las citas sin confirmar de hoy/mañana).
$ids = $body['ids'] ?? (isset($body['id']) ? [$body['id']] : []);
$ids = array_values(array_filter(array_map('strval', (array)$ids)));

if (!$ids) {
    http_response_code(400);
    echo json_encode(['error' => 'Se requiere al menos una cita.']);
    exit;
}

$sent = 0; $failed = 0; $results = [];
foreach ($ids as $id) {
    requireUuid($id, 'id');
    $r = apiPost('/appointments/' . rawurlencode($id) . '/remind', []);
    $ok = !empty($r['ok']) && ($r['_status'] ?? 0) < 400;
    $ok ? $sent++ : $failed++;
    $results[] = [
        'id'     => $id,
        'ok'     => $ok,
        'status' => $r['status'] ?? null,
        'error'  => $ok ? null : ($r['error'] ?? 'No se pudo enviar'),
    ];
}

echo json_encode(['sent' => $sent, 'failed' => $failed, 'results' => $results]);
