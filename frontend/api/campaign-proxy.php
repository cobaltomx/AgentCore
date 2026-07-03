<?php
/**
 * api/campaign-proxy.php — Proxy unificado para acciones de campaña
 */
require_once __DIR__ . '/../includes/config.php';
header('Content-Type: application/json');

if (!isLoggedIn()) { http_response_code(401); echo json_encode(['error'=>'No autorizado']); exit; }

$method = $_SERVER['REQUEST_METHOD'];

// Validador de UUID reutilizable
function isValidUuid(string $v): bool {
    return (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $v);
}

// ── GET: contactos de una campaña (modal detalle) ────────────────────────────
if ($method === 'GET') {
    $action = $_GET['action'] ?? '';
    $id     = trim($_GET['campaign_id'] ?? '');
    $status = trim($_GET['status'] ?? '');

    if ($action === 'contacts') {
        if (!$id || !isValidUuid($id)) {
            http_response_code(400);
            echo json_encode(['error' => 'campaign_id inválido']);
            exit;
        }
        $params = ['limit' => 100, 'offset' => 0];
        if ($status) $params['status'] = $status;
        $result = apiGet("/campaigns/{$id}/contacts", $params);
        $s = $result['_status'] ?? 200;
        unset($result['_status']);
        http_response_code($s);
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit;
    }
    http_response_code(405); exit;
}

if ($method !== 'POST') { http_response_code(405); exit; }

// ── POST ─────────────────────────────────────────────────────────────────────
$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? '';
$id     = trim($body['campaign_id'] ?? '');

// Para acciones que requieren un campaign_id, validar UUID
$idRequired = in_array($action, ['start', 'pause', 'cancel', 'upload_csv', 'import_leads']);
if ($idRequired) {
    if (!$id || !isValidUuid($id)) {
        http_response_code(400);
        echo json_encode(['error' => 'campaign_id inválido o requerido']);
        exit;
    }
}

switch ($action) {
    case 'create':
        unset($body['action'], $body['campaign_id']);
        $result = apiPost('/campaigns', $body);
        break;

    case 'start':
        $result = apiPost("/campaigns/{$id}/start", []);
        break;

    case 'pause':
        $result = apiPost("/campaigns/{$id}/pause", []);
        break;

    case 'cancel':
        $result = apiPost("/campaigns/{$id}/cancel", []);
        break;

    case 'upload_csv':
        $csv = $body['csv'] ?? '';
        if (!$csv || strlen($csv) > 5_000_000) {
            http_response_code(400);
            echo json_encode(['error' => 'CSV requerido (máximo 5 MB)']);
            exit;
        }
        $result = apiPost("/campaigns/{$id}/contacts/csv", ['csv' => $csv]);
        break;

    case 'import_leads':
        $result = apiPost("/campaigns/{$id}/contacts/from-leads", [
            'lead_status'          => $body['lead_status']         ?? ['new'],
            'days_without_contact' => $body['days_without_contact'] ?? 3,
        ]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Acción no válida']);
        exit;
}

$s = $result['_status'] ?? 200;
unset($result['_status']);
http_response_code($s);
echo json_encode($result, JSON_UNESCAPED_UNICODE);
