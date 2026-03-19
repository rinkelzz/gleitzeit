<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();
$csrf = generateCsrfToken();

$today      = date('Y-m-d');
$monday     = getCurrentMonday();
$weekDays   = getWeekDays($monday);
$settings   = getSettings();
$dailyHours = dailyTargetHours();
$dailySecs  = (int)($dailyHours * 3600);

$openEntry  = getOpenEntry();

// Handle manual check-in / check-out from dashboard
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token  = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($token)) {
        die('CSRF-Fehler');
    }
    $action = $_POST['action'] ?? '';
    $note   = isset($_POST['note']) ? substr(trim($_POST['note']), 0, 255) : null;
    if ($action === 'checkin')  checkin($note);
    if ($action === 'checkout') checkout($note);
    header('Location: /index.php');
    exit;
}

// Today stats
$todayWorked = workedSecondsOnDate($today);
if ($openEntry && date('Y-m-d', strtotime($openEntry['checkin_time'])) === $today) {
    $liveWorked = $todayWorked + (time() - strtotime($openEntry['checkin_time']));
} else {
    $liveWorked = $todayWorked;
}

// Week stats
$weekWorked   = 0;
$weekTarget   = 0;
$weekDayData  = [];
foreach ($weekDays as $day) {
    $dow     = (int)date('N', strtotime($day));
    $absence = getAbsenceForDate($day);
    $worked  = workedSecondsOnDate($day);
    $target  = $dailySecs;

    if ($absence && !$absence['half_day']) {
        $target = 0;
    } elseif ($absence && $absence['half_day']) {
        $target = (int)($dailySecs / 2);
    }

    $weekWorked  += $worked;
    $weekTarget  += $target;
    $weekDayData[] = [
        'date'    => $day,
        'label'   => date('D, d.m.', strtotime($day)),
        'worked'  => $worked,
        'target'  => $target,
        'absence' => $absence,
        'isToday' => $day === $today,
    ];
}

// Month overtime
$monthStart     = date('Y-m-01');
$monthOvertimeSecs = cumulativeOvertimeSeconds($monthStart, $today);

// Vacation
$remainingVacation = remainingVacationDays();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gleitzeit — Dashboard</title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<header class="site-header">
    <span class="logo">⏱ Gleitzeit</span>
    <nav>
        <a href="/index.php" class="active">Dashboard</a>
        <a href="/month.php">Monat</a>
        <a href="/absences.php">Abwesenheiten</a>
        <a href="/settings.php">Einstellungen</a>
        <a href="/logout.php">Logout</a>
    </nav>
</header>

<main class="container">

    <!-- Check-in / Check-out card -->
    <section class="card checkin-card">
        <?php if ($openEntry): ?>
            <p class="status checked-in">
                Eingecheckt seit <strong><?= formatTime($openEntry['checkin_time']) ?></strong>
                &mdash; <span id="live-duration" data-since="<?= strtotime($openEntry['checkin_time']) ?>">…</span>
            </p>
            <form method="post" action="/index.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action" value="checkout">
                <input type="text" name="note" placeholder="Notiz (optional)" maxlength="255">
                <button type="submit" class="btn btn-danger">Auschecken</button>
            </form>
        <?php else: ?>
            <p class="status checked-out">Aktuell nicht eingecheckt.</p>
            <form method="post" action="/index.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action" value="checkin">
                <input type="text" name="note" placeholder="Notiz (optional)" maxlength="255">
                <button type="submit" class="btn btn-primary">Einchecken</button>
            </form>
        <?php endif; ?>
    </section>

    <!-- Today card -->
    <section class="card">
        <h2>Heute — <?= date('d.m.Y') ?></h2>
        <div class="stat-grid">
            <div class="stat">
                <span class="stat-label">Gearbeitet</span>
                <span class="stat-value" id="today-worked"
                      data-seconds="<?= $liveWorked ?>"
                      data-open="<?= $openEntry ? '1' : '0' ?>"
                      data-since="<?= $openEntry ? strtotime($openEntry['checkin_time']) : 0 ?>">
                    <?= formatDuration(0, $liveWorked) ?>
                </span>
            </div>
            <div class="stat">
                <span class="stat-label">Soll</span>
                <span class="stat-value"><?= number_format($dailyHours, 2) ?> h</span>
            </div>
            <div class="stat">
                <span class="stat-label">Saldo heute</span>
                <?php $todaySaldo = overtimeSecondsOnDate($today); ?>
                <span class="stat-value <?= $todaySaldo >= 0 ? 'positive' : 'negative' ?>">
                    <?= formatSeconds($todaySaldo) ?>
                </span>
            </div>
        </div>
    </section>

    <!-- Week card -->
    <section class="card">
        <h2>Diese Woche (KW <?= date('W') ?>)</h2>
        <table class="week-table">
            <thead>
                <tr><th>Tag</th><th>Gearbeitet</th><th>Soll</th><th>Saldo</th><th>Abwesenheit</th></tr>
            </thead>
            <tbody>
            <?php foreach ($weekDayData as $d): ?>
                <tr class="<?= $d['isToday'] ? 'today-row' : '' ?>">
                    <td><?= $d['label'] ?></td>
                    <td><?= formatDuration(0, $d['worked']) ?></td>
                    <td><?= $d['target'] > 0 ? number_format($d['target'] / 3600, 2) . ' h' : '—' ?></td>
                    <?php $saldo = $d['worked'] - $d['target']; ?>
                    <td class="<?= $saldo >= 0 ? 'positive' : 'negative' ?>"><?= formatSeconds($saldo) ?></td>
                    <td><?= $d['absence'] ? absenceLabel($d['absence']['type']) . ($d['absence']['half_day'] ? ' (½)' : '') : '' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th>Gesamt</th>
                    <th><?= formatDuration(0, $weekWorked) ?></th>
                    <th><?= number_format($weekTarget / 3600, 2) ?> h</th>
                    <?php $weekSaldo = $weekWorked - $weekTarget; ?>
                    <th class="<?= $weekSaldo >= 0 ? 'positive' : 'negative' ?>"><?= formatSeconds($weekSaldo) ?></th>
                    <th></th>
                </tr>
            </tfoot>
        </table>
    </section>

    <!-- Summary cards -->
    <div class="summary-cards">
        <div class="card summary-card">
            <span class="stat-label">Überstunden (Monat)</span>
            <span class="stat-value big <?= $monthOvertimeSecs >= 0 ? 'positive' : 'negative' ?>">
                <?= formatSeconds($monthOvertimeSecs) ?>
            </span>
        </div>
        <div class="card summary-card">
            <span class="stat-label">Resturlaub</span>
            <span class="stat-value big"><?= number_format($remainingVacation, 1) ?> Tage</span>
        </div>
    </div>

</main>

<script src="/assets/app.js"></script>
</body>
</html>
