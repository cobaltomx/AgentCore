<?php
/**
 * vertical-pack-apply.php — aplica el pack vertical de la industria del tenant
 */
require_once __DIR__ . '/../includes/config.php';
requireAuth();
if (!isAdmin()) { http_response_code(403); echo json_encode(['error'=>'No autorizado']); exit; }
header('Content-Type: application/json');

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$result = apiPost('/vertical-packs/apply', $body);

$s = $result['_status'] ?? 500;
unset($result['_status']);
http_response_code(in_array($s, [200,201]) ? 200 : $s);
echo json_encode($result, JSON_UNESCAPED_UNICODE);
