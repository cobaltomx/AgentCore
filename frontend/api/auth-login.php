<?php
/**
 * Proxy de login — POST /api/auth-login.php
 * Recibe { email, password } → llama al backend → guarda sesión → devuelve JSON
 */
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$body     = json_decode(file_get_contents('php://input'), true);
$email    = trim($body['email']    ?? '');
$password = trim($body['password'] ?? '');

if (!$email || !$password) {
    echo json_encode(['ok' => false, 'error' => 'Por favor ingresa tu correo y contraseña.']);
    exit;
}

// Llamar al backend
$ch = curl_init(API_BASE . '/auth/login');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode(['email' => $email, 'password' => $password]),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_SSL_VERIFYPEER => APP_ENV === 'production',
]);

$body   = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$data = json_decode($body, true) ?? [];

if ($status !== 200 || empty($data['token'])) {
    $error = $data['error'] ?? 'Credenciales incorrectas.';
    echo json_encode(['ok' => false, 'error' => $error]);
    exit;
}

// Guardar sesión
$_SESSION['jwt_token'] = $data['token'];
$_SESSION['user']      = $data['user'];
$_SESSION['tenant']    = $data['user']['tenant'];
// Aceptación de Términos/Privacidad (modal bloqueante si aún no aceptó)
$_SESSION['terms_accepted'] = !empty($data['user']['terms_accepted']);

// Determinar si el tenant necesita onboarding
$tenant   = $data['user']['tenant'];
$settings = $tenant['settings'] ?? [];
$role     = $data['user']['role'] ?? 'user';

// El onboarding solo aplica a admins en tenants sin onboarding_completed
$needsOnboarding = in_array($role, ['admin', 'superadmin'])
    && empty($settings['onboarding_completed']);

echo json_encode([
    'ok'       => true,
    'redirect' => $needsOnboarding ? '/onboarding.php' : '/index.php',
]);
