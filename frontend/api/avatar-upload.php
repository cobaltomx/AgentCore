<?php
/**
 * Proxy: subir avatar al backend (multipart)
 * POST /api/avatar-upload.php
 *   Form fields:
 *     - file:   archivo de imagen
 *     - entity: doctor | professional | user | tenant
 *     - id:     UUID de la entidad (opcional al crear)
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

$entity = strtolower(trim($_POST['entity'] ?? ''));
$id     = trim($_POST['id'] ?? '');
if (!in_array($entity, ['doctor','professional','user','tenant'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Entity inválida']);
    exit;
}

$file = $_FILES['file'];
$mime = $file['type'] ?: mime_content_type($file['tmp_name']);
$cfile = new CURLFile($file['tmp_name'], $mime, $file['name']);

$url = API_BASE . '/uploads/avatar?entity=' . urlencode($entity);
if ($id) $url .= '&id=' . urlencode($id);

$ch = curl_init($url);
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
