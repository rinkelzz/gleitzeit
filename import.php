<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();
$csrf = generateCsrfToken();

$errors   = [];
$preview  = [];
$imported = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) die('CSRF-Fehler');

    $action = $_POST['action'] ?? '';

    // ── Preview ───────────────────────────────────────────────────────
    if ($action === 'preview' && isset($_FILES['csvfile'])) {
        $file = $_FILES['csvfile'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Upload-Fehler.';
        } elseif (!in_array(mime_content_type($file['tmp_name']), ['text/plain', 'text/csv', 'application/csv', 'application/octet-stream'], true)
                  && !str_ends_with(strtolower($file['name']), '.csv')) {
            $errors[] = 'Nur CSV-Dateien erlaubt.';
        } else {
            $handle = fopen($file['tmp_name'], 'r');
            // Strip BOM
            $bom = fread($handle, 3);
            if ($bom !== "\xEF\xBB\xBF") rewind($handle);

            $header = null;
            $row    = 0;
            while (($line = fgetcsv($handle, 1000, ';')) !== false) {
                $row++;
                if ($row === 1) { $header = array_map('strtolower', $line); continue; }
                if (count($line) < 2) continue;

                // Support both formats: with/without full datetime in checkin column
                $datum   = trim($line[0] ?? '');
                $checkin = trim($line[1] ?? '');
                $checkout= trim($line[2] ?? '');
                $note    = trim($line[4] ?? '');

                // Parse checkin: accept "2026-03-19 08:30" or "2026-03-19T08:30" or just "08:30"
                $checkinDt = DateTime::createFromFormat('Y-m-d H:i', $checkin)
                          ?: DateTime::createFromFormat('Y-m-d\TH:i', $checkin)
                          ?: ($datum ? DateTime::createFromFormat('Y-m-d H:i', $datum . ' ' . $checkin) : null);

                $checkoutDt = null;
                if ($checkout) {
                    $checkoutDt = DateTime::createFromFormat('Y-m-d H:i', $checkout)
                               ?: DateTime::createFromFormat('Y-m-d\TH:i', $checkout)
                               ?: ($datum ? DateTime::createFromFormat('Y-m-d H:i', $datum . ' ' . $checkout) : null);
                }

                if (!$checkinDt) {
                    $errors[] = "Zeile $row: Ungültiges Datum/Zeit-Format — übersprungen.";
                    continue;
                }

                $preview[] = [
                    'checkin'  => $checkinDt->format('Y-m-d H:i:s'),
                    'checkout' => $checkoutDt ? $checkoutDt->format('Y-m-d H:i:s') : null,
                    'note'     => substr($note, 0, 255),
                ];
            }
            fclose($handle);

            // Store preview in session for confirmation step
            $_SESSION['import_preview'] = $preview;
        }
    }

    // ── Confirm import ────────────────────────────────────────────────
    if ($action === 'confirm' && !empty($_SESSION['import_preview'])) {
        $db   = getDB();
        $stmt = $db->prepare('INSERT INTO time_entries (checkin_time, checkout_time, note) VALUES (?, ?, ?)');
        foreach ($_SESSION['import_preview'] as $row) {
            $stmt->execute([$row['checkin'], $row['checkout'], $row['note'] ?: null]);
            $imported++;
        }
        unset($_SESSION['import_preview']);
    }

    // ── Cancel ────────────────────────────────────────────────────────
    if ($action === 'cancel') {
        unset($_SESSION['import_preview']);
    }
}

// Load preview from session if available
if (empty($preview) && !empty($_SESSION['import_preview'])) {
    $preview = $_SESSION['import_preview'];
}

$activePage = 'import';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gleitzeit — Import</title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<?php require __DIR__ . '/includes/nav.php'; ?>
<main class="container">
    <div class="page-header">
        <h1>Import</h1>
        <p class="page-subtitle">Zeiteinträge aus CSV-Datei importieren</p>
    </div>

    <?php if ($imported > 0): ?>
        <div class="alert alert-success">✓ <?= $imported ?> Einträge erfolgreich importiert.</div>
    <?php endif; ?>

    <?php foreach ($errors as $e): ?>
        <div class="alert alert-error"><?= htmlspecialchars($e) ?></div>
    <?php endforeach; ?>

    <?php if (!empty($preview)): ?>
        <!-- Vorschau -->
        <section class="card">
            <h2>Vorschau — <?= count($preview) ?> Einträge</h2>
            <p class="hint" style="margin-bottom:1rem">Bitte prüfen und dann importieren oder abbrechen.</p>
            <table class="entries-table">
                <thead>
                    <tr><th>Check-in</th><th>Check-out</th><th>Notiz</th></tr>
                </thead>
                <tbody>
                <?php foreach (array_slice($preview, 0, 50) as $r): ?>
                    <tr>
                        <td><?= date('d.m.Y H:i', strtotime($r['checkin'])) ?></td>
                        <td><?= $r['checkout'] ? date('d.m.Y H:i', strtotime($r['checkout'])) : '—' ?></td>
                        <td><?= htmlspecialchars($r['note'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (count($preview) > 50): ?>
                    <tr><td colspan="3" style="color:#888;text-align:center">… und <?= count($preview)-50 ?> weitere</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            <div style="display:flex;gap:.75rem;margin-top:1.25rem">
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="confirm">
                    <button type="submit" class="btn btn-primary">✓ Jetzt importieren</button>
                </form>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="cancel">
                    <button type="submit" class="btn btn-ghost">Abbrechen</button>
                </form>
            </div>
        </section>

    <?php else: ?>
        <!-- Upload form -->
        <section class="card">
            <h2>CSV-Datei hochladen</h2>
            <form method="post" enctype="multipart/form-data" class="import-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action" value="preview">
                <div class="file-drop" id="fileDrop">
                    <input type="file" name="csvfile" id="csvfile" accept=".csv" required>
                    <label for="csvfile">
                        <span class="file-drop-icon">📂</span>
                        <span class="file-drop-text">CSV-Datei auswählen oder hierher ziehen</span>
                        <span class="file-drop-hint" id="fileName">Maximal 2 MB</span>
                    </label>
                </div>
                <button type="submit" class="btn btn-primary">Vorschau laden</button>
            </form>
        </section>

        <section class="card">
            <h3>Erwartetes CSV-Format</h3>
            <p class="hint" style="margin-bottom:.75rem">Trennzeichen: Semikolon <code>;</code> — kompatibel mit dem Export-Tool.</p>
            <pre class="code-block">datum;checkin;checkout;dauer_h;notiz
2026-03-19;2026-03-19 08:30;2026-03-19 17:00;8.5;Meeting
2026-03-20;2026-03-20 09:00;2026-03-20 17:30;8.5;</pre>
            <p class="hint" style="margin-top:.75rem">
                Die Spalten <code>dauer_h</code> und <code>notiz</code> sind optional.<br>
                Bereits vorhandene Einträge werden <strong>nicht</strong> überschrieben — es werden nur neue hinzugefügt.
            </p>
        </section>
    <?php endif; ?>
</main>
<script src="/assets/app.js"></script>
</body>
</html>
