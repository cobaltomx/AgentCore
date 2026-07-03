<?php
/**
 * plans-save.php — Super Admin: guardar plan o tarifa de proveedor.
 * POST { type:'plan', key, ...campos }  |  { type:'rate', provider, rate_cents }
 */
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json');

if (!isLoggedIn())                          { http_response_code(401); echo json_encode(['error'=>'No autorizado']); exit; }
if (currentUser()['role'] !== 'superadmin') { http_response_code(403); echo json_encode(['error'=>'Solo superadmin']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST')  { http_response_code(405); exit; }

$b = json_decode(file_get_contents('php://input'), true) ?? [];
$type = $b['type'] ?? '';

if ($type === 'plan') {
    $key = trim($b['key'] ?? '');
    if ($key === '') { http_response_code(400); echo json_encode(['error'=>'key requerido']); exit; }
    $payload = [];
    foreach (['name','monthly_cents','included_minutes','max_agents','overage_per_min_cents','features'] as $f) {
        if (array_key_exists($f, $b)) $payload[$f] = $b[$f];
    }
    $result = apiPatch("/superadmin/plans/" . rawurlencode($key), $payload);
} elseif ($type === 'rate') {
    $provider = trim($b['provider'] ?? '');
    if ($provider === '') { http_response_code(400); echo json_encode(['error'=>'provider requerido']); exit; }
    $result = apiPatch("/superadmin/provider-rates/" . rawurlencode($provider), ['rate_cents' => $b['rate_cents'] ?? 0]);
} elseif ($type === 'cost-model') {
    $payload = [];
    if (isset($b['tokens_per_min'])) $payload['tokens_per_min'] = (int)$b['tokens_per_min'];
    if (isset($b['min_margin_pct'])) $payload['min_margin_pct'] = (int)$b['min_margin_pct'];
    $result = apiPatch('/superadmin/cost-model', $payload);
} else {
    http_response_code(400); echo json_encode(['error'=>'type inválido']); exit;
}

$status = $result['_status'] ?? 500;
unset($result['_status']);
http_response_code(in_array($status, [200,201]) ? 200 : $status);
echo json_encode($result, JSON_UNESCAPED_UNICODE);
