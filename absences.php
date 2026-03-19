<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();
$csrf = generateCsrfToken();

$year = (int)($_GET['year'] ?? date('Y'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) die('CSRF-Fehler');

    $action = $_POST['action'] ?? '';
    $db     = getDB();

    if ($action === 'add') {
        $date    = $_POST['date'] ?? '';
        $type    = $_POST['type'] ?? '';
        $halfDay = isset($_POST['half_day']) ? 1 : 0;
        $note    = substr(trim($_POST['note'] ?? ''), 0, 255);

        $validTypes = ['vacation', 'sick', 'holiday', 'other'];
        if ($date && in_array($type, $validTypes, true)) {
            $stmt = $db->prepare(
                'INSERT INTO absences (date, type, half_day, note) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE type = VALUES(type), half_day = VALUES(half_day), note = VALUES(note)'
            );
            $stmt->execute([$date, $type, $halfDay, $note ?: null]);
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $db->prepare('DELETE FROM absences WHERE id = ?')->execute([$id]);
        }
    }
    header("Location: /absences.php?year=$year");
    exit;
}

$stmt = getDB()->prepare('SELECT * FROM absences WHERE YEAR(date) = ? ORDER BY date DESC');
$stmt->execute([$year]);
$absenceList = $stmt->fetchAll();

$taken = vacationDaysTakenThisYear();
$remaining = remainingVacationDays();
$settings = getSettings();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gleitzeit — Abwesenheiten</title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<header class="site-header">
    <span class="logo">⏱ Gleitzeit</span>
    <nav>
        <a href="/index.php">Dashboard</a>
        <a href="/month.php">Monat</a>
        <a href="/absences.php" class="active">Abwesenheiten</a>
        <a href="/settings.php">Einstellungen</a>
        <a href="/logout.php">Logout</a>
    </nav>
</header>

<main class="container">
    <h2>Abwesenheiten <?= $year ?></h2>

    <div class="summary-cards">
        <div class="card summary-card">
            <span class="stat-label">Urlaub gesamt</span>
            <span class="stat-value big"><?= $settings['vacation_days_per_year'] ?> Tage</span>
        </div>
        <div class="card summary-card">
            <span class="stat-label">Genommen</span>
            <span class="stat-value big"><?= number_format($taken, 1) ?> Tage</span>
        </div>
        <div class="card summary-card">
            <span class="stat-label">Restanspruch</span>
            <span class="stat-value big <?= $remaining >= 0 ? 'positive' : 'negative' ?>"><?= number_format($remaining, 1) ?> Tage</span>
        </div>
    </div>

    <!-- Add form -->
    <section class="card">
        <h3>Abwesenheit eintragen</h3>
        <form method="post" action="/absences.php?year=<?= $year ?>" class="add-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="add">
            <div class="form-row">
                <label>Datum <input type="date" name="date" value="<?= date('Y-m-d') ?>" required></label>
                <label>Typ
                    <select name="type" required>
                        <option value="vacation">Urlaub</option>
                        <option value="sick">Krank</option>
                        <option value="holiday">Feiertag</option>
                        <option value="other">Sonstiges</option>
                    </select>
                </label>
                <label class="checkbox-label">
                    <input type="checkbox" name="half_day" value="1"> Halber Tag
                </label>
                <label>Notiz <input type="text" name="note" maxlength="255" placeholder="Optional"></label>
            </div>
            <button type="submit" class="btn btn-primary">Eintragen</button>
        </form>
    </section>

    <!-- List -->
    <section class="card">
        <?php if (empty($absenceList)): ?>
            <p class="empty">Keine Abwesenheiten in <?= $year ?>.</p>
        <?php else: ?>
        <table class="entries-table">
            <thead>
                <tr><th>Datum</th><th>Typ</th><th>Halbtag</th><th>Notiz</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($absenceList as $a): ?>
                <tr>
                    <td><?= date('d.m.Y', strtotime($a['date'])) ?></td>
                    <td><?= absenceLabel($a['type']) ?></td>
                    <td><?= $a['half_day'] ? '½' : '' ?></td>
                    <td><?= htmlspecialchars($a['note'] ?? '') ?></td>
                    <td>
                        <form method="post" action="/absences.php?year=<?= $year ?>" class="inline-form"
                              onsubmit="return confirm('Eintrag löschen?')">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $a['id'] ?>">
                            <button type="submit" class="btn btn-danger btn-sm">Löschen</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </section>
</main>
<script src="/assets/app.js"></script>
</body>
</html>
