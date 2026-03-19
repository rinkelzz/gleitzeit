<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();
$csrf = generateCsrfToken();

$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) die('CSRF-Fehler');

    $action = $_POST['action'] ?? '';
    $db     = getDB();

    if ($action === 'update_settings') {
        $weeklyHours   = (float)str_replace(',', '.', $_POST['weekly_hours'] ?? '40');
        $vacationDays  = (int)($_POST['vacation_days_per_year'] ?? 30);

        if ($weeklyHours > 0 && $weeklyHours <= 168 && $vacationDays >= 0) {
            $row = $db->query('SELECT id FROM settings LIMIT 1')->fetch();
            if ($row) {
                $db->prepare(
                    'UPDATE settings SET weekly_hours = ?, vacation_days_per_year = ? WHERE id = ?'
                )->execute([$weeklyHours, $vacationDays, $row['id']]);
            } else {
                $db->prepare(
                    'INSERT INTO settings (weekly_hours, vacation_days_per_year) VALUES (?, ?)'
                )->execute([$weeklyHours, $vacationDays]);
            }
            $success = 'Einstellungen gespeichert.';
        } else {
            $error = 'Ungültige Eingaben.';
        }
    } elseif ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password']     ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (!password_verify($current, LOGIN_PASSWORD_HASH)) {
            $error = 'Aktuelles Passwort falsch.';
        } elseif (strlen($new) < 12) {
            $error = 'Neues Passwort muss mindestens 12 Zeichen haben.';
        } elseif ($new !== $confirm) {
            $error = 'Passwörter stimmen nicht überein.';
        } else {
            $hash = password_hash($new, PASSWORD_DEFAULT);
            $success = 'Neuer Passwort-Hash (in config.php eintragen):<br><code>' . htmlspecialchars($hash) . '</code>';
        }
    }
}

$settings = getSettings();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gleitzeit — Einstellungen</title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<header class="site-header">
    <span class="logo">⏱ Gleitzeit</span>
    <nav>
        <a href="/index.php">Dashboard</a>
        <a href="/month.php">Monat</a>
        <a href="/absences.php">Abwesenheiten</a>
        <a href="/settings.php" class="active">Einstellungen</a>
        <a href="/logout.php">Logout</a>
    </nav>
</header>

<main class="container">
    <h2>Einstellungen</h2>

    <?php if ($success): ?><p class="alert alert-success"><?= $success ?></p><?php endif; ?>
    <?php if ($error):   ?><p class="alert alert-error"><?= htmlspecialchars($error) ?></p><?php endif; ?>

    <section class="card">
        <h3>Arbeitszeit</h3>
        <form method="post" action="/settings.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="update_settings">
            <label>Wochenstunden (Soll)
                <input type="number" name="weekly_hours" value="<?= htmlspecialchars($settings['weekly_hours']) ?>"
                       min="1" max="168" step="0.5" required>
            </label>
            <label>Urlaubstage pro Jahr
                <input type="number" name="vacation_days_per_year" value="<?= (int)$settings['vacation_days_per_year'] ?>"
                       min="0" max="365" required>
            </label>
            <button type="submit" class="btn btn-primary">Speichern</button>
        </form>
    </section>

    <section class="card">
        <h3>Passwort ändern</h3>
        <p class="hint">Nach dem Ändern wird der neue Hash angezeigt — bitte in <code>config.php</code> eintragen.</p>
        <form method="post" action="/settings.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="change_password">
            <label>Aktuelles Passwort <input type="password" name="current_password" required></label>
            <label>Neues Passwort <input type="password" name="new_password" minlength="12" required></label>
            <label>Wiederholen <input type="password" name="confirm_password" required></label>
            <button type="submit" class="btn btn-secondary">Hash generieren</button>
        </form>
    </section>

    <section class="card">
        <h3>API Key</h3>
        <p class="hint">Der API-Key wird in <code>config.php</code> als <code>API_KEY</code> hinterlegt.<br>
        Neuen Key generieren: <code>php -r "echo bin2hex(random_bytes(32));"</code></p>
    </section>
</main>
<script src="/assets/app.js"></script>
</body>
</html>
