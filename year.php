<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/holidays.php';

requireLogin();
$csrf = generateCsrfToken();

$year = (int)($_GET['year'] ?? date('Y'));
$year = ($year >= 2000 && $year <= 2100) ? $year : (int)date('Y');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) die('CSRF-Fehler');

    $action = $_POST['action'] ?? '';
    $db     = getDB();

    if ($action === 'add') {
        $type       = $_POST['type'] ?? '';
        $note       = substr(trim($_POST['note'] ?? ''), 0, 255);
        $dates      = $_POST['dates'] ?? [];
        $validTypes = ['vacation', 'sick', 'gleitzeit', 'overtime_withdrawal'];

        if (in_array($type, $validTypes, true) && !empty($dates)) {
            $stmt = $db->prepare(
                'INSERT INTO absences (date, type, half_day, note)
                 VALUES (?, ?, 0, ?)
                 ON DUPLICATE KEY UPDATE type = VALUES(type), note = VALUES(note)'
            );
            foreach ($dates as $date) {
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                    $stmt->execute([$date, $type, $note ?: null]);
                }
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $db->prepare('DELETE FROM absences WHERE id = ?')->execute([$id]);
        }
    }

    header("Location: /year.php?year=$year");
    exit;
}

$stmt = getDB()->prepare('SELECT * FROM absences WHERE YEAR(date) = ? ORDER BY date ASC');
$stmt->execute([$year]);
$absenceMap = [];
foreach ($stmt->fetchAll() as $row) {
    $absenceMap[$row['date']] = $row;
}

$settings = getSettings();
$bl       = $settings['bundesland'] ?? 'NW';
$holidays = getHolidays($year, $bl);

$typeInfo = [
    'vacation'            => ['label' => 'TU',  'css' => 'tile-vacation'],
    'gleitzeit'           => ['label' => 'EGZ', 'css' => 'tile-gleitzeit'],
    'overtime_withdrawal' => ['label' => 'EMA', 'css' => 'tile-overtime'],
    'sick'                => ['label' => 'AU',  'css' => 'tile-sick'],
    'holiday'             => ['label' => 'FT',  'css' => 'tile-holiday'],
    'other'               => ['label' => '?',   'css' => 'tile-empty'],
];

function renderTile(string $date, array $absenceMap, array $holidays, array $typeInfo): string {
    $dayNum    = (int)date('j', strtotime($date));
    $dow       = (int)date('N', strtotime($date));
    $isWeekend = $dow >= 6;
    $isHoliday = isset($holidays[$date]);
    $absence   = $absenceMap[$date] ?? null;

    $tileId = $tileType = $tileLabel = '';
    $selectable = '1';
    $halfday    = '0';
    $cssClass   = 'tile-empty';

    if ($isWeekend) {
        $cssClass   = 'tile-weekend';
        $selectable = '0';
    } elseif ($absence) {
        $info      = $typeInfo[$absence['type']] ?? ['label' => '?', 'css' => 'tile-empty'];
        $cssClass  = $info['css'];
        $tileLabel = $info['label'];
        $tileId    = (string)$absence['id'];
        $tileType  = $absence['type'];
        $halfday   = $absence['half_day'] ? '1' : '0';
        if ($absence['half_day']) {
            $cssClass  .= ' tile-halfday';
            $tileLabel .= ' 1/2';
            $selectable = '0';
        }
    } elseif ($isHoliday) {
        $cssClass   = $typeInfo['holiday']['css'];
        $tileLabel  = $typeInfo['holiday']['label'];
        $selectable = '0';
    }

    if ($isHoliday) $selectable = '0'; // Holidays always non-selectable

    $label = $tileLabel !== ''
        ? '<span class="tile-label">' . htmlspecialchars($tileLabel) . '</span>'
        : '';

    return sprintf(
        '<div class="day-tile %s" data-date="%s" data-type="%s" data-id="%s" data-halfday="%s" data-selectable="%s">%d%s</div>',
        htmlspecialchars($cssClass),
        htmlspecialchars($date),
        htmlspecialchars($tileType),
        htmlspecialchars($tileId),
        $halfday,
        $selectable,
        $dayNum,
        $label
    );
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gleitzeit &mdash; Jahr <?= $year ?></title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<?php $activePage = 'year'; require __DIR__ . '/includes/nav.php'; ?>
<main class="container">

    <div class="year-nav">
        <a href="/year.php?year=<?= $year - 1 ?>" class="btn btn-secondary">&larr;</a>
        <h2><?= $year ?></h2>
        <a href="/year.php?year=<?= $year + 1 ?>" class="btn btn-secondary">&rarr;</a>
    </div>

    <div class="year-grid">
    <?php
    $monthNames = ['Januar','Februar','M&auml;rz','April','Mai','Juni','Juli','August','September','Oktober','November','Dezember'];
    $dayNames   = ['Mo','Di','Mi','Do','Fr','Sa','So'];

    for ($m = 1; $m <= 12; $m++):
        $firstDay    = mktime(0, 0, 0, $m, 1, $year);
        $daysInMonth = (int)date('t', $firstDay);
        $startDow    = (int)date('N', $firstDay);
    ?>
    <div class="month-block">
        <h3><?= $monthNames[$m - 1] ?></h3>
        <div class="cal-grid">
            <?php foreach ($dayNames as $dn): ?>
                <div class="cal-header"><?= $dn ?></div>
            <?php endforeach; ?>
            <?php for ($f = 1; $f < $startDow; $f++): ?>
                <div class="day-tile tile-filler"></div>
            <?php endfor; ?>
            <?php for ($d = 1; $d <= $daysInMonth; $d++):
                echo renderTile(sprintf('%04d-%02d-%02d', $year, $m, $d), $absenceMap, $holidays, $typeInfo);
            endfor; ?>
        </div>
    </div>
    <?php endfor; ?>
    </div>

    <div class="year-legend">
        <div class="legend-item"><div class="legend-dot" style="background:#dcfce7;border:1px solid #d1fae5"></div> TU &ndash; Tarifurlaub</div>
        <div class="legend-item"><div class="legend-dot" style="background:#dbeafe;border:1px solid #bfdbfe"></div> EGZ &ndash; Entnahme Gleitzeit</div>
        <div class="legend-item"><div class="legend-dot" style="background:#ede9fe;border:1px solid #ddd6fe"></div> EMA &ndash; Entnahme Mehrarbeit</div>
        <div class="legend-item"><div class="legend-dot" style="background:#fee2e2;border:1px solid #fecaca"></div> AU &ndash; Krank</div>
        <div class="legend-item"><div class="legend-dot" style="background:#ffedd5;border:1px solid #fed7aa"></div> FT &ndash; Feiertag</div>
        <div class="legend-item"><div class="legend-dot" style="background:#f3f4f6;border:1px solid #e5e7eb"></div> Wochenende</div>
    </div>

</main>

<!-- Auswahl-Panel -->
<div class="year-panel-overlay" id="yearPanel">
    <div class="year-panel">
        <h3 id="panelTitle">Tage eintragen</h3>
        <form method="post" action="/year.php?year=<?= $year ?>" id="addForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="add">
            <div id="datesContainer"></div>
            <div class="year-panel-types" id="typeButtons">
                <button type="button" data-type="vacation">TU</button>
                <button type="button" data-type="gleitzeit">EGZ</button>
                <button type="button" data-type="overtime_withdrawal">EMA</button>
                <button type="button" data-type="sick">AU</button>
            </div>
            <input type="hidden" name="type" id="selectedType" value="">
            <input type="text" name="note" placeholder="Notiz (optional)" maxlength="255"
                   style="width:100%;padding:.4rem .6rem;border:1px solid var(--border);border-radius:var(--radius-sm);margin-top:.25rem">
            <div class="year-panel-actions" style="margin-top:.5rem">
                <button type="button" class="btn btn-secondary" id="cancelBtn">Abbrechen</button>
                <button type="submit" class="btn btn-primary" id="submitBtn" disabled>Eintragen</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete-Formular -->
<form method="post" action="/year.php?year=<?= $year ?>" id="deleteForm" style="display:none">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" id="deleteId" value="">
</form>

<script src="/assets/app.js"></script>
<script>
(function () {
    'use strict';
    let dragActive = false, dragMoved = false, startTile = null, selectedDates = [];

    const panel     = document.getElementById('yearPanel');
    const datesBox  = document.getElementById('datesContainer');
    const typeBtns  = document.querySelectorAll('#typeButtons button');
    const selType   = document.getElementById('selectedType');
    const submitBtn = document.getElementById('submitBtn');
    const cancelBtn = document.getElementById('cancelBtn');
    const delForm   = document.getElementById('deleteForm');
    const delId     = document.getElementById('deleteId');

    function getSelectableTile(e) {
        return e.target.closest('.day-tile[data-selectable="1"]');
    }

    function clearSelection() {
        document.querySelectorAll('.day-tile.selected').forEach(t => t.classList.remove('selected'));
        selectedDates = [];
    }

    function addToSelection(tile) {
        const date = tile.dataset.date;
        if (date && !selectedDates.includes(date)) {
            selectedDates.push(date);
            tile.classList.add('selected');
        }
    }

    function showPanel() {
        datesBox.innerHTML = '';
        selectedDates.forEach(date => {
            const inp = document.createElement('input');
            inp.type = 'hidden';
            inp.name = 'dates[]';
            inp.value = date;
            datesBox.appendChild(inp);
        });
        const n = selectedDates.length;
        document.getElementById('panelTitle').textContent =
            n === 1 ? '1 Tag eintragen' : n + ' Tage eintragen';
        panel.classList.add('active');
    }

    function hidePanel() {
        panel.classList.remove('active');
        clearSelection();
        typeBtns.forEach(b => b.classList.remove('active-type'));
        selType.value = '';
        submitBtn.disabled = true;
    }

    typeBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            typeBtns.forEach(b => b.classList.remove('active-type'));
            btn.classList.add('active-type');
            selType.value = btn.dataset.type;
            submitBtn.disabled = false;
        });
    });

    cancelBtn.addEventListener('click', hidePanel);
    panel.addEventListener('click', e => { if (e.target === panel) hidePanel(); });

    document.addEventListener('mousedown', e => {
        const tile = getSelectableTile(e);
        if (!tile) return;
        e.preventDefault();
        dragActive = true;
        dragMoved  = false;
        startTile  = tile;
        clearSelection();
        addToSelection(tile);
    });

    document.addEventListener('mouseover', e => {
        if (!dragActive) return;
        const tile = getSelectableTile(e);
        if (!tile || tile === startTile) return;
        dragMoved = true;
        addToSelection(tile);
    });

    document.addEventListener('mouseup', () => {
        if (!dragActive) return;
        dragActive = false;

        if (!dragMoved) {
            const tile = startTile;
            const id   = tile ? tile.dataset.id : '';
            clearSelection();
            if (id && confirm('Eintrag f\u00fcr ' + (tile.dataset.date || 'diesen Tag') + ' l\u00f6schen?')) {
                delId.value = id;
                delForm.submit();
            }
        } else if (selectedDates.length > 0) {
            showPanel();
        }

        startTile = null;
    });
})();
</script>
</body>
</html>
