<?php
/**
 * Einmaliges Setup-Skript — nach erfolgreicher Installation löschen!
 * Aufruf: https://deinserver.de/setup.php
 */

// Nur lokal oder mit Setup-Token ausführen
$setupToken = getenv('SETUP_TOKEN') ?: '';
$provided   = $_GET['token'] ?? '';
if ($setupToken && !hash_equals($setupToken, $provided)) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/includes/db.php';

$sql = <<<SQL
CREATE TABLE IF NOT EXISTS settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    weekly_hours DECIMAL(4,2) DEFAULT 40.00,
    vacation_days_per_year INT DEFAULT 30,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS time_entries (
    id INT PRIMARY KEY AUTO_INCREMENT,
    checkin_time DATETIME NOT NULL,
    checkout_time DATETIME NULL,
    note VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS absences (
    id INT PRIMARY KEY AUTO_INCREMENT,
    date DATE NOT NULL,
    type ENUM('vacation','sick','holiday','other') NOT NULL,
    half_day TINYINT(1) DEFAULT 0,
    note VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO settings (weekly_hours, vacation_days_per_year)
SELECT 40.00, 30
WHERE NOT EXISTS (SELECT 1 FROM settings LIMIT 1);
SQL;

try {
    $db = getDB();
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
        $db->exec($statement);
    }
    echo '<p style="color:green;font-family:monospace">✓ Datenbank erfolgreich eingerichtet.</p>';
    echo '<p><strong>Bitte diese Datei (setup.php) jetzt löschen!</strong></p>';
} catch (PDOException $e) {
    http_response_code(500);
    echo '<p style="color:red">Fehler: ' . htmlspecialchars($e->getMessage()) . '</p>';
}

$migrations = [
    "ALTER TABLE absences MODIFY type ENUM('vacation','sick','holiday','gleitzeit','overtime_withdrawal','bildungsurlaub','other') NOT NULL",
    "ALTER TABLE absences ADD UNIQUE KEY uq_absences_date (date)",
];

foreach ($migrations as $i => $migration) {
    try {
        getDB()->exec($migration);
        echo '<p style="color:green;font-family:monospace">&#10003; Migration ' . ($i+1) . ' OK.</p>';
    } catch (PDOException $e) {
        echo '<p style="color:orange;font-family:monospace">&#9888; Migration ' . ($i+1) . ' uebersprungen: '
             . htmlspecialchars($e->getMessage()) . '</p>';
        if ($i === 1) {
            echo '<p style="font-family:monospace">Tipp: Duplikate pruefen:<br>'
                 . '<code>SELECT date, COUNT(*) FROM absences GROUP BY date HAVING COUNT(*) > 1;</code></p>';
        }
    }
}
