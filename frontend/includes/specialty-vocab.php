<?php
/**
 * specialty-vocab.php
 * Mapeo de specialty_type → vocabulario, icono y color.
 * Usado en todas las páginas del módulo Consultorios.
 */

define('SPECIALTY_MAP', [
    'psychology' => [
        'label'   => 'Psicología / Salud mental',
        'person'  => 'paciente',
        'persons' => 'pacientes',
        'session' => 'sesión',
        'sessions'=> 'sesiones',
        'icon'    => 'bx-brain',
        'emoji'   => '🧠',
        'color'   => '#696cff',
        'badge'   => 'bg-label-primary',
    ],
    'legal' => [
        'label'   => 'Derecho / Legal',
        'person'  => 'cliente',
        'persons' => 'clientes',
        'session' => 'consulta',
        'sessions'=> 'consultas',
        'icon'    => 'bx-scale',
        'emoji'   => '⚖️',
        'color'   => '#ff6b6b',
        'badge'   => 'bg-label-danger',
    ],
    'accounting' => [
        'label'   => 'Contabilidad / Fiscal',
        'person'  => 'contribuyente',
        'persons' => 'contribuyentes',
        'session' => 'asesoría',
        'sessions'=> 'asesorías',
        'icon'    => 'bx-bar-chart-alt-2',
        'emoji'   => '📊',
        'color'   => '#28c76f',
        'badge'   => 'bg-label-success',
    ],
    'coaching' => [
        'label'   => 'Coaching / Desarrollo',
        'person'  => 'coachee',
        'persons' => 'coachees',
        'session' => 'sesión',
        'sessions'=> 'sesiones',
        'icon'    => 'bx-target-lock',
        'emoji'   => '🎯',
        'color'   => '#ff9f43',
        'badge'   => 'bg-label-warning',
    ],
    'nutrition' => [
        'label'   => 'Nutrición / Bienestar',
        'person'  => 'paciente',
        'persons' => 'pacientes',
        'session' => 'consulta',
        'sessions'=> 'consultas',
        'icon'    => 'bx-leaf',
        'emoji'   => '🥗',
        'color'   => '#26c0e2',
        'badge'   => 'bg-label-info',
    ],
    'medical' => [
        'label'   => 'Medicina / Salud',
        'person'  => 'paciente',
        'persons' => 'pacientes',
        'session' => 'consulta',
        'sessions'=> 'consultas',
        'icon'    => 'bx-plus-medical',
        'emoji'   => '🩺',
        'color'   => '#ea5455',
        'badge'   => 'bg-label-danger',
    ],
    'other' => [
        'label'   => 'Otro',
        'person'  => 'cliente',
        'persons' => 'clientes',
        'session' => 'sesión',
        'sessions'=> 'sesiones',
        'icon'    => 'bx-user',
        'emoji'   => '👤',
        'color'   => '#868e96',
        'badge'   => 'bg-label-secondary',
    ],
]);

/**
 * Devuelve el vocab de un specialty_type, con fallback a 'other'.
 */
function specialtyVocab(string $type): array {
    return SPECIALTY_MAP[$type] ?? SPECIALTY_MAP['other'];
}

/**
 * JSON del mapa completo para inyectar en JS.
 */
function specialtyMapJson(): string {
    return json_encode(SPECIALTY_MAP);
}
