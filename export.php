<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$year  = (int)($_GET['year']  ?? date('Y'));
$month = (int)($_GET['month'] ?? date('n'));
$month = max(1, min(12, $month));
$mode  = $_GET['mode'] ?? '';

$from = sprintf('%04d-%02d-01', $year, $month);
$to   = date('Y-m-t', strtotime($from));

$stmt = getDB()->prepare(
    'SELECT * FROM time_entries WHERE DATE(checkin_time) BETWEEN ? AND ? ORDER BY checkin_time ASC'
);
$stmt->execute([$from, $to]);
$entries = $stmt->fetchAll();

// ── CSV download ─────────────────────────────────────────────────────
if ($mode === 'csv') {
    $filename = sprintf('gleitzeit_%04d-%02d.csv', $year, $month);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache');

    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM für Excel
    fputcsv($out, ['datum', 'checkin', 'checkout', 'dauer_h', 'notiz'], ';');

    foreach ($entries as $e) {
        $checkin  = date('Y-m-d H:i', strtotime($e['checkin_time']));
        $checkout = $e['checkout_time'] ? date('Y-m-d H:i', strtotime($e['checkout_time'])) : '';
        $dauer    = $e['checkout_time']
            ? round((strtotime($e['checkout_time']) - strtotime($e['checkin_time'])) / 3600, 2)
            : '';
        fputcsv($out, [
            date('Y-m-d', strtotime($e['checkin_time'])),
            $checkin,
            $checkout,
            $dauer,
            $e['note'] ?? '',
        ], ';');
    }
    fclose($out);
    exit;
}

// ── PDF print view ───────────────────────────────────────────────────
if ($mode === 'pdf') {
    $monthLabel = date('F Y', strtotime($from));
    $settings   = getSettings();
    $dailySecs  = dailyTargetHours() * 3600;
    $breakSecs  = (int)$settings['break_minutes'] * 60;

    // Group by date
    $byDate = [];
    foreach ($entries as $e) {
        $d = date('Y-m-d', strtotime($e['checkin_time']));
        $byDate[$d][] = $e;
    }

    $totalWorked = 0;
    $totalTarget = 0;
    ?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Gleitzeit <?= htmlspecialchars($monthLabel) ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: Arial, sans-serif; font-size: 11pt; color: #111; padding: 2cm; }
        h1 { font-size: 18pt; margin-bottom: 4px; }
        .subtitle { color: #666; font-size: 10pt; margin-bottom: 24px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 24px; }
        th { background: #1a1a2e; color: #fff; padding: 8px 10px; text-align: left; font-size: 9pt; text-transform: uppercase; letter-spacing: .05em; }
        td { padding: 7px 10px; border-bottom: 1px solid #e0e0e0; font-size: 10pt; }
        tr:nth-child(even) td { background: #f9f9f9; }
        .positive { color: #1a7f3c; font-weight: 600; }
        .negative { color: #c0392b; font-weight: 600; }
        tfoot td { font-weight: 700; border-top: 2px solid #111; background: #f0f0f0; }
        .summary { display: flex; gap: 24px; margin-bottom: 24px; }
        .summary-box { border: 1px solid #ddd; border-radius: 6px; padding: 12px 16px; flex: 1; }
        .summary-box .label { font-size: 9pt; color: #666; text-transform: uppercase; letter-spacing: .05em; }
        .summary-box .value { font-size: 16pt; font-weight: 700; margin-top: 2px; }
        .no-print { margin-bottom: 24px; }
        @media print {
            .no-print { display: none; }
            body { padding: 1cm; }
        }
    </style>
</head>
<body>
    <div class="no-print">
        <button onclick="window.print()" style="padding:8px 20px;background:#1a1a2e;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:13px;">
            🖨 Als PDF speichern (Strg+P)
        </button>
        <a href="/export.php?year=<?= $year ?>&month=<?= $month ?>" style="margin-left:12px;color:#1a1a2e;">← Zurück</a>
    </div>

    <h1>⏱ Gleitzeit — <?= htmlspecialchars($monthLabel) ?></h1>
    <p class="subtitle">Erstellt am <?= date('d.m.Y H:i') ?></p>

    <?php
    // Build day rows
    $rows = [];
    $current = strtotime($from);
    $end     = strtotime($to);
    while ($current <= $end) {
        $date = date('Y-m-d', $current);
        $dow  = (int)date('N', $current);
        if ($dow < 6) {
            $absence = getAbsenceForDate($date);
            $worked  = workedSecondsOnDate($date);
            $target  = (int)$dailySecs;
            $break   = $breakSecs;
            if ($absence && !$absence['half_day']) { $target = 0; $break = 0; }
            elseif ($absence && $absence['half_day']) { $target = (int)($dailySecs/2); $break = (int)($breakSecs/2); }
            $net = max(0, $worked - $break);
            $totalWorked += $net;
            $totalTarget += $target;
            $rows[] = compact('date', 'worked', 'net', 'target', 'absence', 'break');
        }
        $current = strtotime('+1 day', $current);
    }
    $overtimeSecs = $totalWorked - $totalTarget;
    ?>

    <div class="summary">
        <div class="summary-box">
            <div class="label">Gearbeitet (netto)</div>
            <div class="value"><?= formatDuration(0, $totalWorked) ?></div>
        </div>
        <div class="summary-box">
            <div class="label">Soll</div>
            <div class="value"><?= formatDuration(0, $totalTarget) ?></div>
        </div>
        <div class="summary-box">
            <div class="label">Überstunden</div>
            <div class="value <?= $overtimeSecs >= 0 ? 'positive' : 'negative' ?>"><?= formatSeconds($overtimeSecs) ?></div>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Datum</th>
                <th>Check-in</th>
                <th>Check-out</th>
                <th>Brutto</th>
                <th>Pause</th>
                <th>Netto</th>
                <th>Soll</th>
                <th>Saldo</th>
                <th>Abwesenheit</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <?php $saldo = $r['net'] - $r['target']; ?>
            <tr>
                <td><?= date('D d.m.', strtotime($r['date'])) ?></td>
                <?php
                $dayEntries = $byDate[$r['date']] ?? [];
                $first = $dayEntries[0] ?? null;
                $last  = end($dayEntries) ?: null;
                ?>
                <td><?= $first ? date('H:i', strtotime($first['checkin_time'])) : '—' ?></td>
                <td><?= ($last && $last['checkout_time']) ? date('H:i', strtotime($last['checkout_time'])) : '—' ?></td>
                <td><?= formatDuration(0, $r['worked']) ?></td>
                <td><?= $r['break'] > 0 ? formatDuration(0, $r['break']) : '—' ?></td>
                <td><?= formatDuration(0, $r['net']) ?></td>
                <td><?= $r['target'] > 0 ? formatDuration(0, $r['target']) : '—' ?></td>
                <td class="<?= $saldo >= 0 ? 'positive' : 'negative' ?>"><?= $r['target'] > 0 || $r['net'] > 0 ? formatSeconds($saldo) : '—' ?></td>
                <td><?= $r['absence'] ? absenceLabel($r['absence']['type']) . ($r['absence']['half_day'] ? ' (½)' : '') : '' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="5"><strong>Gesamt</strong></td>
                <td><?= formatDuration(0, $totalWorked) ?></td>
                <td><?= formatDuration(0, $totalTarget) ?></td>
                <td class="<?= $overtimeSecs >= 0 ? 'positive' : 'negative' ?>"><?= formatSeconds($overtimeSecs) ?></td>
                <td></td>
            </tr>
        </tfoot>
    </table>
</body>
</html>
    <?php
    exit;
}

// ── Export selection page ────────────────────────────────────────────
$activePage = 'export';
function prevMonth(int $y, int $m): array { return $m === 1 ? [$y-1,12] : [$y,$m-1]; }
function nextMonth(int $y, int $m): array { return $m === 12 ? [$y+1,1] : [$y,$m+1]; }
[$py,$pm] = prevMonth($year,$month);
[$ny,$nm] = nextMonth($year,$month);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gleitzeit — Export</title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<?php require __DIR__ . '/includes/nav.php'; ?>
<main class="container">
    <div class="page-header">
        <h1>Export</h1>
        <p class="page-subtitle">Zeiteinträge als CSV oder PDF exportieren</p>
    </div>

    <section class="card">
        <div class="month-nav">
            <a href="/export.php?year=<?= $py ?>&month=<?= $pm ?>" class="btn btn-ghost">&larr;</a>
            <h2><?= date('F Y', strtotime($from)) ?></h2>
            <a href="/export.php?year=<?= $ny ?>&month=<?= $nm ?>" class="btn btn-ghost">&rarr;</a>
        </div>

        <p class="export-count"><?= count($entries) ?> Einträge in diesem Monat</p>

        <div class="export-buttons">
            <a href="/export.php?year=<?= $year ?>&month=<?= $month ?>&mode=csv"
               class="btn btn-primary btn-lg">
                ↓ CSV herunterladen
            </a>
            <a href="/export.php?year=<?= $year ?>&month=<?= $month ?>&mode=pdf"
               target="_blank" class="btn btn-secondary btn-lg">
                🖨 PDF-Ansicht öffnen
            </a>
        </div>

        <div class="csv-hint">
            <strong>CSV-Format:</strong>
            <code>datum;checkin;checkout;dauer_h;notiz</code><br>
            Kompatibel mit Excel, LibreOffice und dem Import-Tool.
        </div>
    </section>
</main>
<script src="/assets/app.js"></script>
</body>
</html>
