<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

requireApiKey();
checkRateLimit();

$open = getOpenEntry();
if ($open) {
    $since = strtotime($open['checkin_time']);
    $duration = formatDuration($since, time());
    echo json_encode([
        'success'    => true,
        'checked_in' => true,
        'since'      => $open['checkin_time'],
        'duration'   => $duration,
        'message'    => 'Eingecheckt seit ' . formatTime($open['checkin_time']) . ' (' . $duration . ')',
    ]);
} else {
    $today = date('Y-m-d');
    $worked = workedSecondsOnDate($today);
    echo json_encode([
        'success'    => true,
        'checked_in' => false,
        'worked_today' => formatDuration(0, $worked),
        'message'    => $worked > 0
            ? 'Ausgecheckt. Heute gearbeitet: ' . formatDuration(0, $worked)
            : 'Nicht eingecheckt.',
    ]);
}
