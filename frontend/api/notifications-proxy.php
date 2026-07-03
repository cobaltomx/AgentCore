<?php
/**
 * Proxy de notificaciones → Node backend
 *
 * GET  ?action=list              → últimas 30 (carga inicial)
 * GET  ?action=poll&since=<id>   → solo nuevas con id > since
 * POST ?action=read              → marcar leídas  body: { ids?: number[] }
 */
require_once __DIR__ . '/../includes/config.php';

requireAuth();
header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? 'list';

// ── GET list / poll ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    if ($action === 'poll') {
        $since = max(0, (int)($_GET['since'] ?? 0));
        $data  = apiGet('/notifications', ['since' => $since]);
    } else {
        // list — carga inicial, sin filtro
        $data = apiGet('/notifications');
    }

    // apiGet puede devolver ['error'=>..., '_status'=>...] — normalizar
    if (isset($data['error'])) {
        http_response_code(502);
        echo json_encode(['error' => $data['error']]);
        exit;
    }

    // Filtrar el '_status' que agrega apiGet y devolver array limpio
    $rows = array_values(array_filter((array)$data, 'is_array'));
    echo json_encode($rows);
    exit;
}

// ── POST read ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'read') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $data = apiPatch('/notifications/read', $body);

    if (isset($data['error'])) {
        http_response_code(502);
        echo json_encode(['error' => $data['error']]);
        exit;
    }

    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Acción no válida']);
