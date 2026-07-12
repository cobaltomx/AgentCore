<?php
/**
 * AgentCore Dashboard — Config Central
 * Fase 4 · PHP + Bootstrap 5 (Sneat)
 */

// ── Entorno ────────────────────────────────────────────────
define('APP_ENV',     getenv('APP_ENV')     ?: 'development');
define('API_BASE',    getenv('API_BASE_URL') ?: 'http://localhost:3001/api/v1');
// Base raíz del backend (sin /api/v1) — usada para servir /uploads/*
define('BACKEND_ROOT', preg_replace('#/api/v1/?$#', '', API_BASE));
define('APP_VERSION', '0.4.0');
define('APP_NAME',    'AgentCore');

// ── Login con Google / CAPTCHA (claves PÚBLICAS, seguras de exponer) ──
// Vacío = la funcionalidad se oculta (degradación elegante, ver login.php).
define('GOOGLE_CLIENT_ID',   getenv('GOOGLE_CLIENT_ID')   ?: '');
define('RECAPTCHA_SITE_KEY', getenv('RECAPTCHA_SITE_KEY') ?: '');

// ── Widget de chat web (lo embeben los clientes en SUS sitios) ──
// En prod define estas env vars con tu dominio público; el default es para dev.
//   WIDGET_PUBLIC_URL → URL del script que el cliente pega en su sitio.
//   WIDGET_API_URL    → API que el navegador del visitante consulta (público).
define('WIDGET_PUBLIC_URL', getenv('WIDGET_PUBLIC_URL') ?: 'http://localhost:8080/widget.js');
define('WIDGET_API_URL',    getenv('WIDGET_API_URL')    ?: (API_BASE . '/widget'));

// ── Sesión PHP ─────────────────────────────────────────────
session_start([
    'cookie_lifetime' => 604800,   // 7 días
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
    'cookie_secure'   => APP_ENV === 'production',
]);

// ── Detección de tenant por subdominio ─────────────────────
// dental.agentcore.io  → slug = "dental"
// agentcore.io         → slug = null (login global)
function detectTenantSlug(): ?string {
    $host  = $_SERVER['HTTP_HOST'] ?? '';
    $parts = explode('.', $host);

    // Subdominio presente si hay 3+ partes
    // ej: dental.agentcore.io → ['dental','agentcore','io']
    if (count($parts) >= 3 && $parts[0] !== 'www') {
        return strtolower($parts[0]);
    }
    return null;
}

define('TENANT_SLUG', detectTenantSlug());

// ── Helpers de sesión ──────────────────────────────────────
function isLoggedIn(): bool {
    return !empty($_SESSION['jwt_token']) && !empty($_SESSION['user']);
}

function currentUser(): ?array {
    return $_SESSION['user'] ?? null;
}

function currentTenant(): ?array {
    return $_SESSION['tenant'] ?? null;
}

function jwtToken(): string {
    return $_SESSION['jwt_token'] ?? '';
}

function requireAuth(): void {
    if (!isLoggedIn()) {
        header('Location: /login.php');
        exit;
    }
}

function logout(): void {
    // Notificar al backend
    apiPost('/auth/logout', []);
    session_destroy();
    header('Location: /login.php');
    exit;
}

// ── Roles ──────────────────────────────────────────────────────
function userRole(): string {
    return $_SESSION['user']['role'] ?? 'user';
}

function isSuperAdmin(): bool {
    return userRole() === 'superadmin';
}

function isAdmin(): bool {
    return in_array(userRole(), ['admin', 'superadmin']);
}

function isRegularUser(): bool {
    return !isAdmin();
}

/**
 * Redirige al dashboard si el usuario no tiene alguno de los roles indicados.
 * Uso: requireRole('admin', 'superadmin')
 */
function requireRole(string ...$roles): void {
    if (!in_array(userRole(), $roles)) {
        header('Location: /pages/dashboard.php?forbidden=1');
        exit;
    }
}

// ── Perfil de negocio del tenant ───────────────────────────────
function tenantSettings(): array {
    return $_SESSION['tenant']['settings'] ?? [];
}

function tenantIndustry(): string {
    $s = tenantSettings();
    // Soporta ambas rutas: settings.industry (legacy) y settings.businessProfile.industry
    return $s['industry'] ?? $s['businessProfile']['industry'] ?? '';
}

function tenantBusinessName(): string {
    $s = tenantSettings();
    // Soporta ambas rutas: settings.businessName (legacy) y settings.businessProfile.businessName
    return $s['businessName']
        ?? $s['businessProfile']['businessName']
        ?? (currentTenant()['name'] ?? '');
}

/**
 * Vocabulario del "catálogo" según el giro del tenant. El catálogo vive en la
 * tabla `products`, pero CADA vertical lo nombra distinto: un restaurante tiene
 * un "Menú" de "platillos", una inmobiliaria un listado de "Propiedades", una
 * tienda un "Catálogo" de "productos". Esto individualiza el sidebar y la página
 * para que ningún tenant vea terminología genérica que no le corresponde.
 */
function catalogVocab(): array {
    $defaults = [
        'label'         => 'Catálogo',                 // etiqueta del sidebar
        'title'         => 'Catálogo de productos',
        'subtitle'      => 'Tu bot podrá mostrar y vender estos productos por WhatsApp.',
        'singular'      => 'producto',
        'singular_cap'  => 'Producto',
        'new_word'      => 'Nuevo',                     // concordancia de género del botón
        'icon'          => 'bx-store',
        'category_ph'   => 'Ej. Higiene',
        'show_stock'    => true,    // ¿el modal/tarjeta muestra inventario?
        'show_property' => false,   // ¿muestra los campos de propiedad inmobiliaria?
        'show_catalog'  => true,    // ¿se muestra el item de catálogo en el sidebar?
        'show_orders'   => true,    // ¿se muestra el item de pedidos en el sidebar?
        'orders_label'  => 'Pedidos',
    ];
    $map = [
        'restaurante' => [
            'label'=>'Menú', 'title'=>'Menú', 'subtitle'=>'Tu bot podrá mostrar tu menú y tomar pedidos por WhatsApp.',
            'singular'=>'platillo', 'singular_cap'=>'Platillo', 'new_word'=>'Nuevo', 'icon'=>'bx-restaurant',
            'category_ph'=>'Ej. Entradas, Platillos, Bebidas', 'show_stock'=>false, 'show_property'=>false,
            'orders_label'=>'Pedidos a domicilio',
        ],
        'inmobiliaria' => [
            'label'=>'Propiedades', 'title'=>'Propiedades', 'subtitle'=>'Tu bot podrá mostrar estas propiedades y agendar visitas.',
            'singular'=>'propiedad', 'singular_cap'=>'Propiedad', 'new_word'=>'Nueva', 'icon'=>'bx-building-house',
            'category_ph'=>'Ej. Departamento, Casa', 'show_stock'=>false, 'show_property'=>true,
            'show_orders'=>false,   // una inmobiliaria no maneja "pedidos"
        ],
        // Salud usa el módulo Clínica + service_types, NO el catálogo de productos
        // ni pedidos → se ocultan ambos.
        'salud' => ['show_catalog'=>false, 'show_orders'=>false],
    ];
    $ind = tenantIndustry();
    $aliases = [
        'comida'=>'restaurante',
        'dental'=>'salud', 'consultorio'=>'salud', 'clinica'=>'salud', 'medico'=>'salud',
    ];
    $ind = $aliases[$ind] ?? $ind;
    return array_merge($defaults, $map[$ind] ?? []);
}

// ── Features por plan ──────────────────────────────────────────
// Planes con acceso a funciones premium (Voz del cliente, etc.)
const PREMIUM_PLANS = ['growth', 'business', 'enterprise'];

/**
 * ¿El tenant actual tiene un plan premium? (superadmin siempre sí)
 */
function isPremiumPlan(): bool {
    if (isSuperAdmin()) return true;
    $plan = currentTenant()['plan'] ?? 'starter';
    return in_array($plan, PREMIUM_PLANS, true);
}

/**
 * Gate de feature por tenant.
 * Prioridad: override explícito del superadmin (settings.features) → gating por plan.
 * El superadmin escribe overrides desde el panel (Fase C): { key: true|false }.
 */
function tenantHasFeature(string $feature): bool {
    if (isSuperAdmin()) return true;

    // 1) Override explícito por tenant (lo fija el superadmin en settings.features)
    $overrides = tenantSettings()['features'] ?? [];
    if (is_array($overrides) && array_key_exists($feature, $overrides)) {
        return (bool)$overrides[$feature];
    }

    // 2) Fallback: gating por plan (premium)
    $premiumFeatures = ['insights'];  // Voz del cliente
    if (in_array($feature, $premiumFeatures, true)) {
        return isPremiumPlan();
    }
    return true; // features base disponibles para todos
}

// ── API Client ─────────────────────────────────────────────
/**
 * GET a la API con JWT automático
 */
function apiGet(string $endpoint, array $params = []): array {
    $url = API_BASE . $endpoint;
    if ($params) $url .= '?' . http_build_query($params);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . jwtToken(),
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => APP_ENV === 'production',
    ]);

    $body   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error  = curl_error($ch);
    curl_close($ch);

    if ($error) return ['error' => $error, '_status' => 0];

    $data = json_decode($body, true) ?? [];
    $data['_status'] = $status;

    // Token expirado → redirigir al login
    if ($status === 401) {
        session_destroy();
        header('Location: /login.php?expired=1');
        exit;
    }

    return $data;
}

/**
 * POST a la API
 */
function apiPost(string $endpoint, array $payload): array {
    $ch = curl_init(API_BASE . $endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . jwtToken(),
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => APP_ENV === 'production',
    ]);

    $body   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($body, true) ?? [];
    $data['_status'] = $status;
    return $data;
}

/**
 * PUT a la API
 */
function apiPut(string $endpoint, array $payload): array {
    $ch = curl_init(API_BASE . $endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'PUT',
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . jwtToken(),
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => APP_ENV === 'production',
    ]);

    $body   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($body, true) ?? [];
    $data['_status'] = $status;
    return $data;
}

/**
 * PATCH a la API
 */
function apiPatch(string $endpoint, array $payload): array {
    $ch = curl_init(API_BASE . $endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'PATCH',
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . jwtToken(),
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => APP_ENV === 'production',
    ]);

    $body   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($body, true) ?? [];
    $data['_status'] = $status;
    return $data;
}

/**
 * DELETE a la API
 */
function apiDelete(string $endpoint): array {
    $ch = curl_init(API_BASE . $endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'DELETE',
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . jwtToken(),
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => APP_ENV === 'production',
    ]);
    $body   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode($body, true) ?? [];
    $data['_status'] = $status;
    return $data;
}

// ── Validación ─────────────────────────────────────────────
/**
 * Valida formato UUID v4. Usar antes de interpolar IDs en rutas de API.
 */
function isValidUuid(string $value): bool {
    return (bool)preg_match(
        '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
        $value
    );
}

/**
 * Valida un UUID y termina la petición con 400 si no es válido.
 */
function requireUuid(string $value, string $field = 'id'): string {
    if (!isValidUuid($value)) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => "{$field} inválido"]);
        exit;
    }
    return $value;
}

// ── Utilidades de vista ────────────────────────────────────
function e(mixed $val): string {
    return htmlspecialchars((string)($val ?? ''), ENT_QUOTES, 'UTF-8');
}

function formatDate(string $iso, string $format = 'd/m/Y H:i'): string {
    try {
        $dt = new DateTime($iso, new DateTimeZone('America/Mexico_City'));
        return $dt->format($format);
    } catch (\Throwable) {
        return $iso;
    }
}

function formatMins(int $secs): string {
    if ($secs < 60)  return "{$secs}s";
    $m = intdiv($secs, 60);
    $s = $secs % 60;
    return $s > 0 ? "{$m}m {$s}s" : "{$m}m";
}

function planBadge(string $plan): string {
    $badges = [
        'starter'    => 'bg-label-secondary',
        'growth'     => 'bg-label-primary',
        'business'   => 'bg-label-success',
        'enterprise' => 'bg-label-warning',
    ];
    $cls = $badges[$plan] ?? 'bg-label-secondary';
    return "<span class='badge {$cls}'>" . ucfirst(e($plan)) . "</span>";
}

function statusBadge(string $status): string {
    $map = [
        'active'    => ['bg-label-success',  'Activo'],
        'completed' => ['bg-label-info',     'Completado'],
        'failed'    => ['bg-label-danger',   'Fallido'],
        'new'       => ['bg-label-secondary','Nuevo'],
        'contacted' => ['bg-label-info',     'Contactado'],
        'qualified' => ['bg-label-primary',  'Calificado'],
        'converted' => ['bg-label-success',  'Convertido'],
        'loyal'     => ['bg-label-warning',  'Fidelizado'],
        'lost'      => ['bg-label-danger',   'Perdido'],
        'confirmed' => ['bg-label-success',  'Confirmada'],
        'cancelled' => ['bg-label-danger',   'Cancelada'],
        'pending'   => ['bg-label-warning',  'Pendiente'],
    ];
    [$cls, $label] = $map[$status] ?? ['bg-label-secondary', ucfirst($status)];
    return "<span class='badge {$cls}'>{$label}</span>";
}

/**
 * Renderiza un avatar con foto (si avatar_url existe) o iniciales en círculo.
 *
 * @param array  $entity  Debe contener al menos: name. Opcional: avatar_url, avatar_initials, color.
 * @param string $size    'xs' | 'sm' | '' (md) | 'lg' | 'xl'
 * @param string $extra   Clases extra para el wrapper .avatar
 */
function avatar(array $entity, string $size = '', string $extra = ''): string {
    $name      = (string)($entity['name'] ?? '');
    $initials  = strtoupper($entity['avatar_initials'] ?? (
        $name ? mb_substr(implode('', array_map(fn($w) => mb_substr($w, 0, 1), array_slice(explode(' ', trim($name)), 0, 2))), 0, 2) : '?'
    ));
    $url       = $entity['avatar_url'] ?? null;
    $color     = $entity['color'] ?? '';
    $sizeClass = $size ? "avatar-{$size}" : '';
    $extraCls  = e($extra);

    if ($url) {
        // Si es ruta /uploads/* del backend, prefijar con BACKEND_ROOT
        $src = (strpos($url, '/uploads/') === 0) ? BACKEND_ROOT . $url : $url;
        return '<div class="avatar ' . $sizeClass . ' ' . $extraCls . '">'
             . '<img src="' . e($src) . '" alt="' . e($name) . '" class="rounded-circle" loading="lazy">'
             . '</div>';
    }

    if ($color) {
        $style = 'background:' . e($color) . '22;color:' . e($color) . ';';
        return '<div class="avatar ' . $sizeClass . ' ' . $extraCls . '">'
             . '<span class="avatar-initial rounded-circle" style="' . $style . '">' . e($initials) . '</span>'
             . '</div>';
    }

    // Color por hash del nombre (consistente entre renders)
    $palette = ['primary','success','info','warning','danger','secondary'];
    $idx     = abs(crc32($name)) % count($palette);
    $cls     = 'bg-label-' . $palette[$idx];
    return '<div class="avatar ' . $sizeClass . ' ' . $extraCls . '">'
         . '<span class="avatar-initial rounded-circle ' . $cls . '">' . e($initials) . '</span>'
         . '</div>';
}

function channelIcon(string $channel): string {
    $icons = [
        'voice'    => '<i class="bx bx-phone text-primary"></i>',
        'whatsapp' => '<i class="bx bxl-whatsapp text-success"></i>',
        'webchat'  => '<i class="bx bx-chat text-info"></i>',
        'sms'      => '<i class="bx bx-message text-warning"></i>',
    ];
    return $icons[$channel] ?? '<i class="bx bx-radio-circle"></i>';
}

/**
 * Guarda la sesión a partir de la respuesta {token,user} del backend y
 * calcula a dónde redirigir. Compartido por auth-login.php y auth-google.php
 * para no duplicar esta lógica (onboarding, términos, etc.).
 */
function establishSession(array $data): array {
    $_SESSION['jwt_token']       = $data['token'];
    $_SESSION['user']            = $data['user'];
    $_SESSION['tenant']          = $data['user']['tenant'];
    $_SESSION['terms_accepted']  = !empty($data['user']['terms_accepted']);

    $tenant   = $data['user']['tenant'];
    $settings = $tenant['settings'] ?? [];
    $role     = $data['user']['role'] ?? 'user';
    $needsOnboarding = in_array($role, ['admin', 'superadmin'])
        && empty($settings['onboarding_completed']);

    return ['ok' => true, 'redirect' => $needsOnboarding ? '/onboarding.php' : '/index.php'];
}
