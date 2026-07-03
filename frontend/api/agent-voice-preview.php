<?php
// api/agent-voice-preview.php — Sintetizar muestra de voz con Cartesia (streaming binary)
require_once __DIR__ . '/../includes/config.php';
if (!isLoggedIn()) { http_response_code(401); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$voiceId = $body['voice_id'] ?? '';
$text    = $body['text']     ?? '';

if (!$voiceId) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'voice_id requerido']);
    exit;
}

$payload = ['voice_id' => $voiceId];
if ($text) $payload['text'] = $text;

$ch = curl_init(API_BASE . '/agents/voice-preview');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . jwtToken(),
        'Content-Type: application/json',
    ],
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_SSL_VERIFYPEER => APP_ENV === 'production',
]);

$responseBody = curl_exec($ch);
$status       = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType  = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

if ($status !== 200) {
    http_response_code($status);
    header('Content-Type: application/json');
    // Backend might have returned JSON error
    echo $responseBody;
    exit;
}

header('Content-Type: ' . ($contentType ?: 'audio/mpeg'));
header('Content-Length: ' . strlen($responseBody));
header('Cache-Control: no-cache');
echo $responseBody;
