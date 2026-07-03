<?php
/**
 * Proxy: subir foto de propiedad al backend (multipart)
 * POST /api/property-photo-upload.php  (campo "file")
 * Devuelve { ok, url } con la URL de la foto subida.
 */
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json');

if (!isLoggedIn())                         { http_response_code(401); echo json_encode(['error' => 'No autorizado']); exit; }
if (!isAdmin())                            { http_response_code(403); echo json_encode(['error' => 'Solo admins']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'No se recibió archivo válido']);
    exit;
}

$file  = $_FILES['file'];
$mime  = $file['type'] ?: mime_content_type($file['tmp_name']);
$cfile = new CURLFile($file['tmp_name'], $mime, $file['name']);

$ch = curl_init(API_BASE . '/uploads/property-image');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => ['file' => $cfile],
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . jwtToken(),
        'Accept: application/json',
    ],
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_SSL_VERIFYPEER => APP_ENV === 'production',
]);
$body   = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

http_response_code($status ?: 500);
echo $body ?: json_encode(['error' => 'Error al subir']);
