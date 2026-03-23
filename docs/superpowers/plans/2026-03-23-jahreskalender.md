# Jahres-Kalenderüberblick Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Neue Seite `year.php` mit farbcodierten Mini-Kalendern für alle 12 Monate, Drag-Selektion zum Eintragen von Abwesenheiten (TU/EGZ/EMA/AU).

**Architecture:** Klassisches PHP-POST-Pattern wie `month.php`. Datenhaltung in der bestehenden `absences`-Tabelle mit ENUM-Erweiterung + UNIQUE KEY. Vanilla JS für Drag-Selektion und Delete-Confirm.

**Tech Stack:** PHP 8+, MySQL/MariaDB, Vanilla JS (ES6), CSS Custom Properties (bestehende style.css erweitern)

**Spec:** `docs/superpowers/specs/2026-03-23-jahreskalender-design.md`

---

## File Map

| Datei | Aktion | Zweck |
|-------|--------|-------|
| `setup.php` | Modify | DB-Migration: ENUM + UNIQUE KEY |
| `includes/functions.php` | Modify | `absenceLabel()` + `getAccountBalances()` fix |
| `absences.php` | Modify | `$validTypes` erweitern |
| `includes/nav.php` | Modify | "Jahr"-Link einfuegen |
| `assets/style.css` | Modify | CSS fuer Jahreskalender-Kacheln, Grid, Panel |
| `year.php` | Create | Hauptseite: PHP-Logic + HTML-Rendering |

---

## Task 1: DB-Migration in setup.php

**Files:**
- Modify: `setup.php`

- [ ] **Schritt 1: Migrations-Block in setup.php ergaenzen**

Nach dem bestehenden try/catch-Block folgenden Block einfuegen:

```php
$migrations = [
    "ALTER TABLE absences MODIFY type ENUM('vacation','sick','holiday','gleitzeit','overtime_withdrawal','other') NOT NULL",
    "ALTER TABLE absences ADD UNIQUE KEY uq_absences_date (date)",
];

foreach ($migrations as $i => $migration) {
    try {
        getDB()->exec($migration);
        echo '<p style="color:green;font-family:monospace">Migration ' . ($i+1) . ' OK.</p>';
    } catch (PDOException $e) {
        echo '<p style="color:orange;font-family:monospace">Migration ' . ($i+1) . ' uebersprungen: '
             . htmlspecialchars($e->getMessage()) . '</p>';
        if ($i === 1) {
            echo '<p style="font-family:monospace">Tipp: Duplikate pruefen:<br>'
                 . '<code>SELECT date, COUNT(*) FROM absences GROUP BY date HAVING COUNT(*) > 1;</code></p>';
        }
    }
}
```

- [ ] **Schritt 2: Manuell testen**

  `http://localhost/setup.php` aufrufen. Erwartet: beide Migrationen OK (oder orange "uebersprungen" wenn bereits vorhanden).

- [ ] **Schritt 3: Commit**

```
git add setup.php
git commit -m "feat: db migration for overtime_withdrawal type and unique date key"
```

---

## Task 2: functions.php — absenceLabel + getAccountBalances

**Files:**
- Modify: `includes/functions.php`

- [ ] **Schritt 1: `absenceLabel()` erweitern**

```php
function absenceLabel(string $type): string {
    return match($type) {
        'vacation'             => 'Urlaub',
        'sick'                 => 'Krank',
        'holiday'              => 'Feiertag',
        'gleitzeit'            => 'Gleittag',
        'overtime_withdrawal'  => 'Mehrarbeit-Entnahme',
        default                => 'Sonstiges',
    };
}
```

- [ ] **Schritt 2: `getAccountBalances()` fix**

`overtime_withdrawal`-Tage muessen in `$overtime`-Bucket landen. Ersetze die Funktion:

```php
function getAccountBalances(string $fromDate, string $toDate): array {
    $gleitzeit = 0;
    $overtime  = 0;
    $current   = strtotime($fromDate);
    $end       = strtotime($toDate);

    while ($current <= $end) {
        $date  = date('Y-m-d', $current);
        $delta = dailyDeltaSeconds($date);
        if ($delta !== null) {
            $absence = getAbsenceForDate($date);
            if (isDayOvertime($date) || ($absence && $absence['type'] === 'overtime_withdrawal')) {
                $overtime += $delta;
            } else {
                $gleitzeit += $delta;
            }
        }
        $current = strtotime('+1 day', $current);
    }

    return ['gleitzeit' => $gleitzeit, 'overtime' => $overtime];
}
```

- [ ] **Schritt 3: Dashboard pruefen**

  Gleitzeit- + Ueberstunden-Konto sollten unveraendert sein (solange keine `overtime_withdrawal`-Eintraege).

- [ ] **Schritt 4: Commit**

```
git add includes/functions.php
git commit -m "feat: add overtime_withdrawal label and fix account balance routing"
```

---

## Task 3: absences.php + nav.php

**Files:**
- Modify: `absences.php`
- Modify: `includes/nav.php`

- [ ] **Schritt 1: absences.php validTypes erweitern**

```php
$validTypes = ['vacation', 'sick', 'holiday', 'gleitzeit', 'overtime_withdrawal', 'other'];
```

- [ ] **Schritt 2: nav.php — Jahr-Link hinzufuegen**

Nach dem Abwesenheiten-Link:

```php
<a href="/year.php"      <?= $activePage === 'year'      ? 'class="active"' : '' ?>>Jahr</a>
```

- [ ] **Schritt 3: Commit**

```
git add absences.php includes/nav.php
git commit -m "feat: add overtime_withdrawal to valid types and Jahr nav link"
```

---

## Task 4: CSS fuer Jahreskalender

**Files:**
- Modify: `assets/style.css`

- [ ] **Schritt 1: CSS-Block ans Ende anhaengen**

```css
/* Jahreskalender */
.year-nav { display:flex; align-items:center; justify-content:center; gap:1.5rem; margin-bottom:1.5rem; }
.year-nav h2 { font-size:1.4rem; font-weight:700; }

.year-grid { display:grid; grid-template-columns:1fr 1fr; gap:1.25rem; }
@media (max-width:700px) { .year-grid { grid-template-columns:1fr; } }

.month-block { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius); padding:1rem; box-shadow:var(--shadow-sm); }
.month-block h3 { font-size:.95rem; font-weight:700; margin-bottom:.5rem; }

.cal-grid { display:grid; grid-template-columns:repeat(7,1fr); gap:2px; }
.cal-header { font-size:.65rem; font-weight:600; text-align:center; color:var(--text-subtle); padding:2px 0; }

.day-tile {
    aspect-ratio:1; border-radius:4px; font-size:.65rem; font-weight:600;
    display:flex; flex-direction:column; align-items:center; justify-content:center;
    cursor:default; user-select:none; line-height:1.1;
    border:1px solid transparent; position:relative;
}
.day-tile[data-selectable="1"] { cursor:pointer; }
.day-tile[data-selectable="1"]:hover { border-color:var(--primary); }

.day-tile.tile-weekend   { background:#f3f4f6; color:var(--text-subtle); }
.day-tile.tile-empty     { background:var(--surface-2); color:var(--text); }
.day-tile.tile-vacation  { background:#dcfce7; color:#15803d; }
.day-tile.tile-gleitzeit { background:#dbeafe; color:#1d4ed8; }
.day-tile.tile-overtime  { background:#ede9fe; color:#7c3aed; }
.day-tile.tile-sick      { background:#fee2e2; color:#b91c1c; }
.day-tile.tile-holiday   { background:#ffedd5; color:#c2410c; }
.day-tile.tile-halfday   { opacity:.65; }
.day-tile.tile-filler    { background:transparent; border:none; cursor:default; }
.day-tile.selected       { outline:2px solid var(--primary); outline-offset:1px; }

.tile-label { font-size:.55rem; font-weight:700; letter-spacing:.02em; line-height:1; }

/* Auswahl-Panel */
.year-panel-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.3); z-index:500; align-items:center; justify-content:center; }
.year-panel-overlay.active { display:flex; }
.year-panel { background:var(--surface); border-radius:var(--radius); padding:1.5rem; box-shadow:var(--shadow-md); min-width:280px; display:flex; flex-direction:column; gap:1rem; }
.year-panel h3 { font-size:1rem; font-weight:700; }
.year-panel-types { display:flex; gap:.5rem; flex-wrap:wrap; }
.year-panel-types button { flex:1; padding:.5rem; border:2px solid var(--border); border-radius:var(--radius-sm); background:var(--surface-2); font-weight:700; font-size:.85rem; cursor:pointer; }
.year-panel-types button.active-type { border-color:var(--primary); background:var(--primary-light); }
.year-panel-actions { display:flex; gap:.5rem; justify-content:flex-end; }

/* Legende */
.year-legend { display:flex; flex-wrap:wrap; gap:.75rem; margin-top:1.5rem; font-size:.8rem; }
.legend-item { display:flex; align-items:center; gap:.4rem; }
.legend-dot { width:14px; height:14px; border-radius:3px; flex-shrink:0; }
```

- [ ] **Schritt 2: Commit**

```
git add assets/style.css
git commit -m "feat: add year calendar CSS styles"
```

---

## Task 5: year.php erstellen

**Files:**
- Create: `year.php`

- [ ] **Schritt 1: PHP-Backend (POST-Handling + Datenabruf)**

Anfang der Datei:

```php
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
        $type    = $_POST['type'] ?? '';
        $note    = substr(trim($_POST['note'] ?? ''), 0, 255);
        $dates   = $_POST['dates'] ?? [];
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

// Alle Abwesenheiten des Jahres als Map date => row
$stmt = getDB()->prepare('SELECT * FROM absences WHERE YEAR(date) = ? ORDER BY date ASC');
$stmt->execute([$year]);
$absenceMap = [];
foreach ($stmt->fetchAll() as $row) {
    $absenceMap[$row['date']] = $row;
}

$settings  = getSettings();
$bl        = $settings['bundesland'] ?? 'NW';
$holidays  = getHolidays($year, $bl);

$typeInfo = [
    'vacation'            => ['label' => 'TU',  'css' => 'tile-vacation'],
    'gleitzeit'           => ['label' => 'EGZ', 'css' => 'tile-gleitzeit'],
    'overtime_withdrawal' => ['label' => 'EMA', 'css' => 'tile-overtime'],
    'sick'                => ['label' => 'AU',  'css' => 'tile-sick'],
    'holiday'             => ['label' => 'FT',  'css' => 'tile-holiday'],
    'other'               => ['label' => '?',   'css' => 'tile-empty'],
];

function renderTile(string $date, array $absenceMap, array $holidays, array $typeInfo): string {
    $dayNum    = date('j', strtotime($date));
    $dow       = (int)date('N', strtotime($date));
    $isWeekend = $dow >= 6;
    $isHoliday = isset($holidays[$date]);
    $absence   = $absenceMap[$date] ?? null;

    $tileId = $tileType = $tileLabel = '';
    $selectable = '1';
    $halfday    = '0';
    $cssClass   = 'tile-empty';

    if ($isWeekend) {
        $cssClass = 'tile-weekend'; $selectable = '0';
    } elseif ($absence) {
        $info = $typeInfo[$absence['type']] ?? ['label' => '?', 'css' => 'tile-empty'];
        $cssClass  = $info['css'];
        $tileLabel = $info['label'];
        $tileId    = (string)$absence['id'];
        $tileType  = $absence['type'];
        $halfday   = $absence['half_day'] ? '1' : '0';
        if ($absence['half_day']) {
            $cssClass .= ' tile-halfday';
            $tileLabel .= ' 1/2';
            $selectable = '0';
        }
    } elseif ($isHoliday) {
        $cssClass  = $typeInfo['holiday']['css'];
        $tileLabel = $typeInfo['holiday']['label'];
        $selectable = '0';
    }

    $label = $tileLabel
        ? '<span class="tile-label">' . htmlspecialchars($tileLabel) . '</span>'
        : '';

    return sprintf(
        '<div class="day-tile %s" data-date="%s" data-type="%s" data-id="%s" data-halfday="%s" data-selectable="%s">%d%s</div>',
        htmlspecialchars($cssClass), $date,
        htmlspecialchars($tileType), htmlspecialchars($tileId),
        $halfday, $selectable, $dayNum, $label
    );
}
?>
```

- [ ] **Schritt 2: HTML-Template**

```php
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
    $monthNames = ['Januar','Februar','März','April','Mai','Juni','Juli','August','September','Oktober','November','Dezember'];
    $dayNames   = ['Mo','Di','Mi','Do','Fr','Sa','So'];

    for ($m = 1; $m <= 12; $m++):
        $firstDay    = mktime(0,0,0,$m,1,$year);
        $daysInMonth = (int)date('t', $firstDay);
        $startDow    = (int)date('N', $firstDay);
    ?>
    <div class="month-block">
        <h3><?= $monthNames[$m-1] ?></h3>
        <div class="cal-grid">
            <?php foreach ($dayNames as $dn): ?>
                <div class="cal-header"><?= $dn ?></div>
            <?php endforeach; ?>
            <?php for ($f = 1; $f < $startDow; $f++): ?>
                <div class="day-tile tile-filler"></div>
            <?php endfor; ?>
            <?php for ($d = 1; $d <= $daysInMonth; $d++):
                echo renderTile(sprintf('%04d-%02d-%02d',$year,$m,$d), $absenceMap, $holidays, $typeInfo);
            endfor; ?>
        </div>
    </div>
    <?php endfor; ?>
    </div>

    <div class="year-legend">
        <div class="legend-item"><div class="legend-dot" style="background:#dcfce7"></div> TU &ndash; Tarifurlaub</div>
        <div class="legend-item"><div class="legend-dot" style="background:#dbeafe"></div> EGZ &ndash; Entnahme Gleitzeit</div>
        <div class="legend-item"><div class="legend-dot" style="background:#ede9fe"></div> EMA &ndash; Entnahme Mehrarbeit</div>
        <div class="legend-item"><div class="legend-dot" style="background:#fee2e2"></div> AU &ndash; Krank</div>
        <div class="legend-item"><div class="legend-dot" style="background:#ffedd5"></div> FT &ndash; Feiertag</div>
        <div class="legend-item"><div class="legend-dot" style="background:#f3f4f6"></div> Wochenende</div>
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
                   style="width:100%;padding:.4rem .6rem;border:1px solid var(--border);border-radius:var(--radius-sm)">
            <div class="year-panel-actions">
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
    let dragActive = false, dragMoved = false, startTile = null, selectedDates = [];

    const panel      = document.getElementById('yearPanel');
    const datesBox   = document.getElementById('datesContainer');
    const typeBtns   = document.querySelectorAll('#typeButtons button');
    const selType    = document.getElementById('selectedType');
    const submitBtn  = document.getElementById('submitBtn');
    const cancelBtn  = document.getElementById('cancelBtn');
    const delForm    = document.getElementById('deleteForm');
    const delId      = document.getElementById('deleteId');

    function selTile(e) { return e.target.closest('.day-tile[data-selectable="1"]'); }
    function clearSel() {
        document.querySelectorAll('.day-tile.selected').forEach(t => t.classList.remove('selected'));
        selectedDates = [];
    }
    function addTile(t) {
        const d = t.dataset.date;
        if (d && !selectedDates.includes(d)) { selectedDates.push(d); t.classList.add('selected'); }
    }
    function showPanel() {
        datesBox.innerHTML = '';
        selectedDates.forEach(d => {
            const i = document.createElement('input');
            i.type = 'hidden'; i.name = 'dates[]'; i.value = d;
            datesBox.appendChild(i);
        });
        const n = selectedDates.length;
        document.getElementById('panelTitle').textContent = n === 1 ? '1 Tag eintragen' : n + ' Tage eintragen';
        panel.classList.add('active');
    }
    function hidePanel() {
        panel.classList.remove('active');
        clearSel();
        typeBtns.forEach(b => b.classList.remove('active-type'));
        selType.value = ''; submitBtn.disabled = true;
    }

    typeBtns.forEach(b => b.addEventListener('click', () => {
        typeBtns.forEach(x => x.classList.remove('active-type'));
        b.classList.add('active-type');
        selType.value = b.dataset.type;
        submitBtn.disabled = false;
    }));

    cancelBtn.addEventListener('click', hidePanel);
    panel.addEventListener('click', e => { if (e.target === panel) hidePanel(); });

    document.addEventListener('mousedown', e => {
        const t = selTile(e);
        if (!t) return;
        e.preventDefault();
        dragActive = true; dragMoved = false; startTile = t;
        clearSel(); addTile(t);
    });
    document.addEventListener('mouseover', e => {
        if (!dragActive) return;
        const t = selTile(e);
        if (!t || t === startTile) return;
        dragMoved = true; addTile(t);
    });
    document.addEventListener('mouseup', () => {
        if (!dragActive) return;
        dragActive = false;
        if (!dragMoved) {
            const t = startTile, id = t ? t.dataset.id : '';
            clearSel();
            if (id && confirm('Eintrag fuer ' + (t.dataset.date || 'diesen Tag') + ' loeschen?')) {
                delId.value = id; delForm.submit();
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
```

- [ ] **Schritt 3: Gesamttest im Browser**

  1. `http://localhost/year.php` — 12 Monate, 2-Spalten, Wochenenden grau, Feiertage orange
  2. Drag ueber mehrere Werktage → Panel erscheint → Typ waehlen → Eintragen → Kacheln eingefaerbt
  3. Auf eingefaerbte Kachel klicken → Confirm → geloescht
  4. Jahr-Navigation ← → funktioniert
  5. Mobil (< 700px) → 1 Spalte

- [ ] **Schritt 4: Commit**

```
git add year.php
git commit -m "feat: add Jahreskalender (year.php) with drag selection and absence entry"
```

---

## Task 6: Abschluss

- [ ] **Schritt 1: EMA-Test**

  EMA-Tag ueber Jahreskalender eintragen → Dashboard → Ueberstunden-Konto nimmt ab.
  EGZ-Tag eintragen → Gleitzeit-Konto nimmt ab.

- [ ] **Schritt 2: TODO.md aktualisieren**

  `- [x] Jahres Kalender Ueberblick`

- [ ] **Schritt 3: Final Commit**

```
git add TODO.md
git commit -m "docs: mark Jahreskalender as done in TODO"
```
