<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();
$csrf = generateCsrfToken();

$year  = (int)($_GET['year']  ?? date('Y'));
$month = (int)($_GET['month'] ?? date('n'));
// Clamp
$month = max(1, min(12, $month));

// Handle edit / delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) die('CSRF-Fehler');

    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);
    $db     = getDB();

    if ($action === 'add') {
        $checkin  = $_POST['checkin_time']  ?? '';
        $checkout = $_POST['checkout_time'] ?? '';
        $note     = substr(trim($_POST['note'] ?? ''), 0, 255);

        $checkinDt  = DateTime::createFromFormat('Y-m-d\TH:i', $checkin);
        $checkoutDt = $checkout ? DateTime::createFromFormat('Y-m-d\TH:i', $checkout) : null;

        if ($checkinDt) {
            $db->prepare(
                'INSERT INTO time_entries (checkin_time, checkout_time, note) VALUES (?, ?, ?)'
            )->execute([
                $checkinDt->format('Y-m-d H:i:s'),
                $checkoutDt ? $checkoutDt->format('Y-m-d H:i:s') : null,
                $note ?: null,
            ]);
        }
    } elseif ($action === 'delete' && $id > 0) {
        $db->prepare('DELETE FROM time_entries WHERE id = ?')->execute([$id]);
    } elseif ($action === 'edit' && $id > 0) {
        $checkin  = $_POST['checkin_time']  ?? '';
        $checkout = $_POST['checkout_time'] ?? '';
        $note     = substr(trim($_POST['note'] ?? ''), 0, 255);

        // Validate datetime format
        $checkinDt  = DateTime::createFromFormat('Y-m-d\TH:i', $checkin);
        $checkoutDt = $checkout ? DateTime::createFromFormat('Y-m-d\TH:i', $checkout) : null;

        if ($checkinDt) {
            $db->prepare(
                'UPDATE time_entries SET checkin_time = ?, checkout_time = ?, note = ? WHERE id = ?'
            )->execute([
                $checkinDt->format('Y-m-d H:i:s'),
                $checkoutDt ? $checkoutDt->format('Y-m-d H:i:s') : null,
                $note ?: null,
                $id,
            ]);
        }
    }
    header("Location: /month.php?year=$year&month=$month");
    exit;
}

// Fetch entries for month
$from = sprintf('%04d-%02d-01', $year, $month);
$to   = date('Y-m-t', strtotime($from));

$stmt = getDB()->prepare(
    'SELECT * FROM time_entries
     WHERE DATE(checkin_time) BETWEEN ? AND ?
     ORDER BY checkin_time ASC'
);
$stmt->execute([$from, $to]);
$entries = $stmt->fetchAll();

// Prev/next month
function prevMonth(int $y, int $m): array {
    return $m === 1 ? [$y - 1, 12] : [$y, $m - 1];
}
function nextMonth(int $y, int $m): array {
    return $m === 12 ? [$y + 1, 1] : [$y, $m + 1];
}
[$py, $pm] = prevMonth($year, $month);
[$ny, $nm] = nextMonth($year, $month);

$editId = (int)($_GET['edit'] ?? 0);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gleitzeit — <?= date('F Y', strtotime($from)) ?></title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<header class="site-header">
    <span class="logo">⏱ Gleitzeit</span>
    <nav>
        <a href="/index.php">Dashboard</a>
        <a href="/month.php" class="active">Monat</a>
        <a href="/absences.php">Abwesenheiten</a>
        <a href="/settings.php">Einstellungen</a>
        <a href="/logout.php">Logout</a>
    </nav>
</header>

<main class="container">
    <div class="month-nav">
        <a href="/month.php?year=<?= $py ?>&month=<?= $pm ?>" class="btn btn-secondary">&larr;</a>
        <h2><?= date('F Y', strtotime($from)) ?></h2>
        <a href="/month.php?year=<?= $ny ?>&month=<?= $nm ?>" class="btn btn-secondary">&rarr;</a>
    </div>

    <!-- Add entry form -->
    <section class="card">
        <h3>Eintrag manuell hinzufügen</h3>
        <form method="post" action="/month.php?year=<?= $year ?>&month=<?= $month ?>" class="inline-edit-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="add">
            <label>Check-in
                <input type="datetime-local" name="checkin_time"
                       value="<?= date('Y-m') ?>-01T09:00" required>
            </label>
            <label>Check-out
                <input type="datetime-local" name="checkout_time"
                       value="<?= date('Y-m') ?>-01T17:00">
            </label>
            <label>Notiz
                <input type="text" name="note" maxlength="255" placeholder="Optional">
            </label>
            <button type="submit" class="btn btn-primary">Hinzufügen</button>
        </form>
    </section>

    <!-- Entries list -->
    <section class="card">
        <?php if (empty($entries)): ?>
            <p class="empty">Keine Einträge in diesem Monat.</p>
        <?php else: ?>
        <table class="entries-table">
            <thead>
                <tr>
                    <th>Check-in</th>
                    <th>Check-out</th>
                    <th>Dauer</th>
                    <th>Notiz</th>
                    <th>Aktionen</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($entries as $e): ?>
                <?php if ($editId === (int)$e['id']): ?>
                <tr class="edit-row">
                    <td colspan="5">
                        <form method="post" action="/month.php?year=<?= $year ?>&month=<?= $month ?>" class="inline-edit-form">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                            <input type="hidden" name="action" value="edit">
                            <input type="hidden" name="id" value="<?= $e['id'] ?>">
                            <label>Check-in
                                <input type="datetime-local" name="checkin_time"
                                       value="<?= date('Y-m-d\TH:i', strtotime($e['checkin_time'])) ?>" required>
                            </label>
                            <label>Check-out
                                <input type="datetime-local" name="checkout_time"
                                       value="<?= $e['checkout_time'] ? date('Y-m-d\TH:i', strtotime($e['checkout_time'])) : '' ?>">
                            </label>
                            <label>Notiz
                                <input type="text" name="note" value="<?= htmlspecialchars($e['note'] ?? '') ?>" maxlength="255">
                            </label>
                            <button type="submit" class="btn btn-primary">Speichern</button>
                            <a href="/month.php?year=<?= $year ?>&month=<?= $month ?>" class="btn btn-secondary">Abbrechen</a>
                        </form>
                    </td>
                </tr>
                <?php else: ?>
                <tr>
                    <td><?= htmlspecialchars(date('d.m. H:i', strtotime($e['checkin_time']))) ?></td>
                    <td><?= $e['checkout_time'] ? htmlspecialchars(date('d.m. H:i', strtotime($e['checkout_time']))) : '<em>offen</em>' ?></td>
                    <td>
                        <?php if ($e['checkout_time']): ?>
                            <?= formatDuration(strtotime($e['checkin_time']), strtotime($e['checkout_time'])) ?>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($e['note'] ?? '') ?></td>
                    <td class="actions">
                        <a href="/month.php?year=<?= $year ?>&month=<?= $month ?>&edit=<?= $e['id'] ?>" class="btn btn-secondary btn-sm">Bearbeiten</a>
                        <form method="post" action="/month.php?year=<?= $year ?>&month=<?= $month ?>" class="inline-form"
                              onsubmit="return confirm('Eintrag wirklich löschen?')">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $e['id'] ?>">
                            <button type="submit" class="btn btn-danger btn-sm">Löschen</button>
                        </form>
                    </td>
                </tr>
                <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </section>
</main>
<script src="/assets/app.js"></script>
</body>
</html>
