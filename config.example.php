<?php
// Kopiere diese Datei zu config.php und passe die Werte an.
// config.php wird NICHT ins Git eingecheckt!

define('DB_HOST', 'localhost');
define('DB_NAME', 'gleitzeit');
define('DB_USER', 'gleitzeit_user');
define('DB_PASS', 'dein_passwort');
define('DB_CHARSET', 'utf8mb4');

// Langer zufälliger String für die API-Authentifizierung (iPhone Shortcuts)
// Generieren: php -r "echo bin2hex(random_bytes(32));"
define('API_KEY', 'dein_geheimer_api_key');

// Passwort-Hash für den Web-Login
// Generieren: php -r "echo password_hash('dein_passwort', PASSWORD_DEFAULT);"
define('LOGIN_PASSWORD_HASH', '$2y$12$...');

// Zeitzone
define('TIMEZONE', 'Europe/Berlin');

// Rate Limiting: max. API-Calls pro Minute pro IP
define('RATE_LIMIT_MAX', 10);
define('RATE_LIMIT_WINDOW', 60); // Sekunden
