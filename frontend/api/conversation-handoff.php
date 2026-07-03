<?php
/**
 * conversation-handoff.php — atender/resolver el handoff humano de una conversación
 * POST { id, action: 'claim'|'resolve' }
 */
require_once __DIR__ . '/../includes/config.php';
requireAuth();
header('Content-Type: application/json');

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$id     = trim($body['id'] ?? '');
$action = ($body['action'] ?? 'resolve') === 'claim' ? 'claim' : 'resolve';

if (!$id || !isValidUuid($id)) { http_response_code(400); echo json_encode(['error'=>'id inválido']); exit; }

$result = apiPatch("/conversations/{$id}/handoff", ['action' => $action]);
$s = $result['_status'] ?? 500;
unset($result['_status']);
http_response_code(in_array($s, [200,201]) ? 200 : $s);
echo json_encode($result, JSON_UNESCAPED_UNICODE);
