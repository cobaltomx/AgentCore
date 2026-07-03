<?php
// api/kb-delete.php
require_once __DIR__ . '/../includes/config.php';
header('Content-Type: application/json');
if (!isLoggedIn()) { http_response_code(401); echo json_encode(['error'=>'No autorizado']); exit; }

$body = json_decode(file_get_contents('php://input'), true);
$id   = trim($body['id'] ?? '');
if (!$id) { http_response_code(400); echo json_encode(['error'=>'ID requerido']); exit; }
requireUuid($id, 'id');

// DELETE via curl
$ch = curl_init(API_BASE . "/kb/{$id}");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST  => 'DELETE',
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . jwtToken(), 'Content-Type: application/json'],
    CURLOPT_TIMEOUT        => 10,
]);
$body_r  = curl_exec($ch);
$status  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

http_response_code($status);
echo $body_r ?: json_encode(['deleted' => true]);
