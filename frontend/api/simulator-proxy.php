<?php
/**
 * Simulator Proxy → Node backend /api/v1/simulator
 *
 * GET  ?action=scenarios
 * GET  ?action=status
 * GET  ?action=security_events[&session_id=xxx]
 * POST ?action=chat        body: { message, session_id?, scenario?, history? }
 * POST ?action=approve
 * POST ?action=setup_step  body: { step, value? }
 */
require_once __DIR__ . '/../includes/config.php';

requireAuth();
header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? 'status';
$method = $_SERVER['REQUEST_METHOD'];

// ── GET actions ──────────────────────────────────────────────────────────────
if ($method === 'GET') {
    if ($action === 'scenarios') {
        $data = apiGet('/simulator/scenarios');
    } elseif ($action === 'status') {
        $data = apiGet('/simulator/status');
    } elseif ($action === 'security_events') {
        $params = [];
        if (!empty($_GET['session_id'])) $params['session_id'] = $_GET['session_id'];
        $data = apiGet('/simulator/security-events', $params);
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Acción no válida']);
        exit;
    }

    if (isset($data['error'])) {
        http_response_code(502);
        echo json_encode(['error' => $data['error']]);
        exit;
    }

    // apiGet añade '_status' al array. Para listas (security_events) esto
    // rompe Array.isArray() en el cliente al convertir el array en objeto.
    if ($action === 'security_events') {
        // Devolver SOLO los elementos array (los eventos), descartando '_status'
        $rows = array_values(array_filter((array)$data, 'is_array'));
        echo json_encode($rows, JSON_UNESCAPED_UNICODE);
    } else {
        unset($data['_status']);
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ── POST actions ─────────────────────────────────────────────────────────────
if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    if ($action === 'chat') {
        $data = apiPost('/simulator/chat', $body);
    } elseif ($action === 'approve') {
        $data = apiPost('/simulator/approve', []);
    } elseif ($action === 'setup_step') {
        $data = apiPost('/simulator/setup-step', $body);
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Acción no válida']);
        exit;
    }

    if (isset($data['error'])) {
        $status = $data['_status'] ?? 502;
        http_response_code((int)$status);
        echo json_encode(['error' => $data['error'],
                          'scenarios_tested' => $data['scenarios_tested'] ?? null,
                          'required' => $data['required'] ?? null]);
        exit;
    }

    echo json_encode($data);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Método no permitido']);
