<?php
/**
 * appointments-proxy.php
 * Proxy AJAX para operaciones de citas desde el dashboard.
 *
 * POST /api/appointments-proxy.php
 *   { action: 'create', name, phone, email, scheduled_at, duration_mins, notes }
 *   { id, status }           → PATCH status
 */
require_once __DIR__ . '/../includes/config.php';
requireAuth();

header('Content-Type: application/json');

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true) ?? [];

$action = $body['action'] ?? 'status';

// ── Listar citas por rango (toggle Hoy/Semana/Mes del dashboard) ──
if ($action === 'list') {
    $params = ['limit' => 200];
    if (!empty($body['from'])) $params['from'] = $body['from'];
    if (!empty($body['to']))   $params['to']   = $body['to'];
    if (!empty($body['status'])) $params['status'] = $body['status'];
    $result = apiGet('/appointments', $params);
    $status = $result['_status'] ?? 500;
    unset($result['_status']);
    http_response_code($status === 200 ? 200 : $status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Crear cita manual ─────────────────────────────────────────
if ($action === 'create') {
    $payload = [
        'name'          => $body['name']          ?? '',
        'phone'         => $body['phone']         ?? '',
        'email'         => $body['email']         ?? null,
        'scheduled_at'  => $body['scheduled_at']  ?? '',
        'duration_mins' => (int)($body['duration_mins'] ?? 60),
        'notes'         => $body['notes']         ?? null,
    ];

    // Eliminar nulls (para que Zod no queje)
    $payload = array_filter($payload, fn($v) => $v !== null && $v !== '');

    $result = apiPost('/appointments', $payload);

    $status = $result['_status'] ?? 500;
    unset($result['_status']);

    http_response_code(in_array($status, [200, 201]) ? 201 : $status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Editar cita (reagendar, notas, duración) ──────────────────
if ($action === 'edit') {
    $id = $body['id'] ?? null;
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'id requerido']); exit; }

    $allowed  = ['scheduled_at', 'duration_mins', 'notes', 'location', 'title'];
    $payload  = [];
    foreach ($allowed as $field) {
        if (array_key_exists($field, $body) && $body[$field] !== null && $body[$field] !== '') {
            $payload[$field] = $field === 'duration_mins' ? (int)$body[$field] : $body[$field];
        }
    }

    if (empty($payload)) { http_response_code(400); echo json_encode(['error' => 'Sin campos']); exit; }

    $result = apiPatch("/appointments/{$id}", $payload);
    $status = $result['_status'] ?? 500;
    unset($result['_status']);
    http_response_code(in_array($status, [200, 201]) ? 200 : $status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Generar link de cobro de anticipo ─────────────────────────
if ($action === 'deposit_link') {
    $id = $body['id'] ?? null;
    if (!$id || !isValidUuid($id)) {
        http_response_code(400);
        echo json_encode(['error' => 'id de cita inválido']);
        exit;
    }
    $payload = [];
    if (isset($body['amount']) && $body['amount'] !== '') {
        $payload['amount'] = (float)$body['amount'];
    }
    $result = apiPost("/appointments/{$id}/deposit-link", $payload);
    $status = $result['_status'] ?? 500;
    unset($result['_status']);
    http_response_code(in_array($status, [200, 201]) ? 200 : $status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Cambiar estado ────────────────────────────────────────────
$id        = $body['id']     ?? null;
$newStatus = $body['status'] ?? null;

if (!$id || !$newStatus) {
    http_response_code(400);
    echo json_encode(['error' => 'id y status son requeridos']);
    exit;
}

$validStatuses = ['pending', 'confirmed', 'cancelled', 'completed', 'no_show'];
if (!in_array($newStatus, $validStatuses, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Status inválido']);
    exit;
}

$result = apiPatch("/appointments/{$id}/status", ['status' => $newStatus]);
$status = $result['_status'] ?? 500;
unset($result['_status']);

http_response_code(in_array($status, [200, 201]) ? 200 : $status);
echo json_encode($result, JSON_UNESCAPED_UNICODE);
