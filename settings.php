<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/holidays.php';

requireLogin();
$csrf = generateCsrfToken();

$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) die('CSRF-Fehler');

    $action = $_POST['action'] ?? '';
    $db     = getDB();

    if ($action === 'update_settings') {
        $weeklyHours        = (float)str_replace(',', '.', $_POST['weekly_hours'] ?? '40');
        $vacationDays       = (int)($_POST['vacation_days_per_year'] ?? 30);
        $breakMinutes       = (int)($_POST['break_minutes'] ?? 30);
        $bundesland         = $_POST['bundesland'] ?? 'NW';
        $trackingStartRaw   = $_POST['tracking_start_date'] ?? '';
        $carryoverGleitzeit = (int)($_POST['carryover_gleitzeit_minutes'] ?? 0);
        $carryoverOvertime  = (int)($_POST['carryover_overtime_minutes']  ?? 0);
        $carryoverVacation  = (float)str_replace(',', '.', $_POST['carryover_vacation'] ?? '0');
        $companyFreeDays    = trim($_POST['company_free_days'] ?? '');

        if (!array_key_exists($bundesland, BUNDESLAENDER)) $bundesland = 'NW';

        // Validate tracking start date
        $trackingStart = null;
        if ($trackingStartRaw) {
            $dt = DateTime::createFromFormat('Y-m-d', $trackingStartRaw);
            if ($dt) $trackingStart = $dt->format('Y-m-d');
        }

        if ($weeklyHours > 0 && $weeklyHours <= 168 && $vacationDays >= 0 && $breakMinutes >= 0) {
            $row = $db->query('SELECT id FROM settings LIMIT 1')->fetch();
            if ($row) {
                $db->prepare(
                    'UPDATE settings SET weekly_hours = ?, vacation_days_per_year = ?, break_minutes = ?,
                     bundesland = ?, tracking_start_date = ?,
                     carryover_gleitzeit_minutes = ?, carryover_overtime_minutes = ?,
                     carryover_vacation = ?, company_free_days = ? WHERE id = ?'
                )->execute([
                    $weeklyHours, $vacationDays, $breakMinutes,
                    $bundesland, $trackingStart,
                    $carryoverGleitzeit, $carryoverOvertime, $carryoverVacation,
                    $companyFreeDays,
                    $row['id'],
                ]);
            } else {
                $db->prepare(
                    'INSERT INTO settings (weekly_hours, vacation_days_per_year, break_minutes,
                     bundesland, tracking_start_date,
                     carryover_gleitzeit_minutes, carryover_overtime_minutes, carryover_vacation,
                     company_free_days)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    $weeklyHours, $vacationDays, $breakMinutes,
                    $bundesland, $trackingStart,
                    $carryoverGleitzeit, $carryoverOvertime, $carryoverVacation,
                    $companyFreeDays,
                ]);
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
<?php $activePage = 'settings'; require __DIR__ . '/includes/nav.php'; ?>

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
            <label>Unbezahlte Pause pro Tag (Minuten)
                <input type="number" name="break_minutes" value="<?= (int)($settings['break_minutes'] ?? 30) ?>"
                       min="0" max="240" required>
            </label>
            <label>Bundesland (für automatische Feiertage)
                <select name="bundesland">
                    <?php foreach (BUNDESLAENDER as $code => $name): ?>
                        <option value="<?= $code ?>" <?= ($settings['bundesland'] ?? 'NW') === $code ? 'selected' : '' ?>>
                            <?= htmlspecialchars($name) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <hr class="divider">
            <h4 style="color:var(--text-muted);font-size:.85rem;font-weight:600;text-transform:uppercase;letter-spacing:.05em">Erfassungszeitraum & Überträge</h4>
            <label>Erfassungsbeginn
                <input type="date" name="tracking_start_date"
                       value="<?= htmlspecialchars($settings['tracking_start_date'] ?? '') ?>">
                <span class="hint" style="margin-top:.2rem">Ab diesem Tag werden Salden berechnet. Leer = 1. Januar des aktuellen Jahres.</span>
            </label>
            <label>Übertrag Gleitzeit-Konto (Minuten)
                <input type="number" name="carryover_gleitzeit_minutes"
                       value="<?= (int)($settings['carryover_gleitzeit_minutes'] ?? 0) ?>"
                       step="1" placeholder="z.B. 150 oder -60">
                <span class="hint" style="margin-top:.2rem">Positiv = Plus-Stunden, negativ = Minus-Stunden aus dem Vorjahr. z.B. +2:30 h = 150</span>
            </label>
            <label>Übertrag Überstunden-Konto (Minuten)
                <input type="number" name="carryover_overtime_minutes"
                       value="<?= (int)($settings['carryover_overtime_minutes'] ?? 0) ?>"
                       step="1" placeholder="z.B. 480 für 8 h">
                <span class="hint" style="margin-top:.2rem">Überstunden-Übertrag aus dem Vorjahr in Minuten.</span>
            </label>
            <label>Übertrag Resturlaub (Tage)
                <input type="number" name="carryover_vacation"
                       value="<?= number_format((float)($settings['carryover_vacation'] ?? 0), 1) ?>"
                       step="0.5" placeholder="z.B. 3 oder 2.5">
                <span class="hint" style="margin-top:.2rem">Urlaubstage aus dem Vorjahr, die noch genommen werden können.</span>
            </label>
            <label>Betriebsfreie Tage
                <input type="text" name="company_free_days"
                       value="<?= htmlspecialchars($settings['company_free_days'] ?? '') ?>"
                       placeholder="z. B. 12-24, 12-31">
                <span class="hint">Kommagetrennte Daten im Format MM-TT — gelten jedes Jahr automatisch.</span>
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
