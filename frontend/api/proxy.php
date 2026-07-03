<?php
/**
 * proxy.php — Proxy GET genérico para endpoints del backend.
 * Uso: GET /api/proxy.php?path=/sessions/series/UUID&param=val
 *
 * Pasa el body JSON del backend tal cual (sin parsear) para preservar
 * arrays vs objetos. Solo rutas autorizadas, solo admins.
 */
require_once __DIR__ . '/../includes/config.php';
header('Content-Type: application/json');

if (!isLoggedIn()) { http_response_code(401); echo json_encode(['error'=>'No autorizado']); exit; }
if (!isAdmin())    { http_response_code(403); echo json_encode(['error'=>'Acceso denegado']); exit; }

$path = $_GET['path'] ?? '';

// Validar path: solo letras, números, slash, guion, guion bajo, punto, dos puntos
if (!$path || !preg_match('#^/[a-zA-Z0-9/_\-.:]+$#', $path)) {
    http_response_code(400); echo json_encode(['error'=>'Ruta inválida']); exit;
}

// Rutas permitidas — whitelist
$allowed = [
    '#^/sessions#',
    '#^/professionals#',
    '#^/consultorio/session-types#',
    '#^/qualification-questions#',
    '#^/doctors#',
    '#^/service-types#',
];
$ok = false;
foreach ($allowed as $pattern) { if (preg_match($pattern, $path)) { $ok = true; break; } }
if (!$ok) { http_response_code(403); echo json_encode(['error'=>'Ruta no permitida']); exit; }

// Construir URL completa con todos los query params excepto 'path'
$qs = $_GET;
unset($qs['path']);
$fullUrl = rtrim(API_BASE, '/') . $path . ($qs ? '?' . http_build_query($qs) : '');

// Curl directo — preserva body raw para no corromper arrays JSON
$ch = curl_init($fullUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . jwtToken(),
        'Accept: application/json',
    ],
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_SSL_VERIFYPEER => defined('APP_ENV') && APP_ENV === 'production',
]);

$body   = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err    = curl_error($ch);
curl_close($ch);

if ($err) {
    http_response_code(502);
    echo json_encode(['error' => 'Backend no disponible', 'detail' => $err]);
    exit;
}

// Token expirado → 401 al cliente
if ($status === 401) {
    http_response_code(401);
    echo json_encode(['error' => 'Sesión expirada']);
    exit;
}

http_response_code($status ?: 200);
// Pasar body tal cual — array o objeto, el frontend decide
echo $body !== false ? $body : json_encode(['error' => 'Sin respuesta del backend']);
