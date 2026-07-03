<?php
// api/kb-proxy.php — Crear documento en KB
require_once __DIR__ . '/../includes/config.php';
header('Content-Type: application/json');
if (!isLoggedIn()) { http_response_code(401); echo json_encode(['error'=>'No autorizado']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

$body   = json_decode(file_get_contents('php://input'), true);
$type   = $body['file_type'] ?? '';

// FAQ usa endpoint dedicado
$endpoint = $type === 'faq' ? '/kb/faq' : '/kb';
$result   = apiPost($endpoint, $body);

http_response_code($result['_status'] ?? 201);
echo json_encode($result);
