<?php
/**
 * API Proxy: Guardar settings del tenant
 * POST /api/settings-save.php
 */
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json');

if (!isLoggedIn())                            { http_response_code(401); echo json_encode(['error' => 'No autorizado']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST')    { http_response_code(405); exit; }

$partial = json_decode(file_get_contents('php://input'), true);
if (!$partial)                                { http_response_code(400); echo json_encode(['error' => 'Payload inválido']); exit; }

// Actualizar settings del tenant via PATCH
// El backend debe tener un endpoint PATCH /tenants que mergee settings
$result = apiPatch('/tenants/settings', $partial);

http_response_code($result['_status'] ?? 200);
echo json_encode($result);
