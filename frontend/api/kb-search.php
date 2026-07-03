<?php
// api/kb-search.php — Búsqueda semántica
require_once __DIR__ . '/../includes/config.php';
header('Content-Type: application/json');
if (!isLoggedIn()) { http_response_code(401); echo json_encode(['error'=>'No autorizado']); exit; }

$q   = $_GET['q']   ?? '';
$min = $_GET['min'] ?? null;   // similitud mínima (0.4 - 0.95)

$params = ['q' => $q];
if ($min !== null) {
    $min = max(0.3, min(0.95, (float)$min));
    $params['min_similarity'] = $min;
}

$result = apiGet('/kb/search', $params);
$status = $result['_status'] ?? 200;
unset($result['_status']);
http_response_code($status);
echo json_encode($result, JSON_UNESCAPED_UNICODE);
