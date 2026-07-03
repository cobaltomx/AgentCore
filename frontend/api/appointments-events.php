<?php
/**
 * appointments-events.php
 * Fuente de eventos para FullCalendar — convierte citas del backend al formato esperado.
 *
 * FullCalendar llama: GET /api/appointments-events.php?start=YYYY-MM-DD&end=YYYY-MM-DD
 */
require_once __DIR__ . '/../includes/config.php';
requireAuth();

header('Content-Type: application/json');

$start = $_GET['start'] ?? date('Y-m-01');
$end   = $_GET['end']   ?? date('Y-m-t');

// Recortar a solo fecha YYYY-MM-DD (FullCalendar manda ISO completo)
$start = substr($start, 0, 10);
$end   = substr($end,   0, 10);

$result = apiGet('/appointments', [
    'from'  => $start . 'T00:00:00Z',
    'to'    => $end   . 'T23:59:59Z',
    'limit' => 300,
]);

$events = [];

foreach (($result['data'] ?? []) as $a) {
    $status = $a['status'] ?? 'pending';

    $color = match ($status) {
        'confirmed' => '#696cff',   // morado Sneat
        'completed' => '#71dd37',   // verde
        'cancelled' => '#ff3e1d',   // rojo
        'pending'   => '#ffab00',   // ámbar
        'no_show'   => '#8592a3',   // gris
        default     => '#8592a3',
    };

    // Calcular fin de la cita
    $startTs = strtotime($a['scheduled_at']);
    $durMins  = (int)($a['duration_mins'] ?? 60);
    $endTs    = $startTs + $durMins * 60;

    $events[] = [
        'id'    => $a['id'],
        // Nombre a mostrar: lead → paciente (lo que captura el bot) → título → genérico
        'title' => $a['lead_name'] ?: ($a['patient_name'] ?: ($a['title'] ?? 'Cita')),
        'start' => date('c', $startTs),   // ISO 8601
        'end'   => date('c', $endTs),
        'color' => $color,
        'extendedProps' => [
            'phone'    => $a['lead_phone'] ?? '',
            'status'   => $status,
            'notes'    => $a['notes']      ?? '',
            'location' => $a['location']   ?? '',
            'source'   => $a['external_source'] ?? 'manual',
            'duration' => $durMins,
        ],
    ];
}

echo json_encode($events, JSON_UNESCAPED_UNICODE);
