# ⏱ Gleitzeit Tracker

Persönliche Arbeitszeiterfassung mit Web-Dashboard und iPhone Shortcuts Integration.

- **Check-in/out** per iPhone Kurzbefehl (automatisch oder manuell)
- **Dashboard** mit Tages- und Wochenübersicht, Überstunden-Saldo
- **Abwesenheitsverwaltung** für Urlaub, Krank, Feiertage
- **Monatsansicht** mit editierbaren und manuell hinzufügbaren Einträgen
- **Konfigurierbare Pausenzeit** wird täglich automatisch abgezogen

---

## Voraussetzungen

- PHP 8.1+
- MySQL 8.0+ / MariaDB 10.6+
- Apache mit `mod_rewrite` (oder Docker, s. u.)

---

## Installation

### 1. Dateien deployen

```bash
git clone https://github.com/rinkelzz/gleitzeit.git
cd gleitzeit
```

### 2. Konfiguration

```bash
cp config.example.php config.php
```

`config.php` bearbeiten:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'gleitzeit');
define('DB_USER', 'gleitzeit_user');
define('DB_PASS', 'dein_passwort');

// Langen zufälligen API-Key generieren:
// php -r "echo bin2hex(random_bytes(32));"
define('API_KEY', 'dein_api_key');

// Passwort-Hash generieren:
// php -r "echo password_hash('dein_passwort', PASSWORD_DEFAULT);"
define('LOGIN_PASSWORD_HASH', '$2y$12$...');
```

### 3. Datenbank anlegen

```sql
CREATE DATABASE gleitzeit CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'gleitzeit_user'@'localhost' IDENTIFIED BY 'dein_passwort';
GRANT ALL PRIVILEGES ON gleitzeit.* TO 'gleitzeit_user'@'localhost';
```

### 4. Tabellen einrichten

`https://deinserver.de/setup.php` im Browser aufrufen.
**Danach `setup.php` löschen!**

### 5. HTTPS aktivieren

In `.htaccess` den HTTPS-Redirect einkommentieren:

```apache
RewriteCond %{HTTPS} off
RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
```

---

## Einstellungen

Unter `/settings.php` lassen sich folgende Werte anpassen:

| Einstellung | Beschreibung | Standard |
|-------------|--------------|---------|
| Wochenstunden (Soll) | Vertraglich vereinbarte Stunden pro Woche | 40 h |
| Urlaubstage pro Jahr | Gesamter Jahresanspruch | 30 Tage |
| Unbezahlte Pause pro Tag | Wird täglich von der Arbeitszeit abgezogen | 30 min |

Bei Halbtagsabwesenheiten wird die Pause automatisch halbiert.

---

## Lokaler Test mit Docker

```bash
docker compose up -d
```

Öffne **http://localhost:8081** — Datenbank einrichten:

```bash
curl http://localhost:8081/setup.php
```

Container stoppen: `docker compose down`

---

## API-Endpunkte

Alle API-Calls erfordern den Header `X-API-Key: {dein_key}`.

| Methode | Endpoint | Funktion |
|---------|----------|----------|
| `POST` | `/api/checkin.php` | Check-in starten |
| `POST` | `/api/checkout.php` | Check-out speichern |
| `GET` | `/api/status.php` | Aktuellen Status abfragen |

**Beispiel-Response:**
```json
{"success": true, "message": "Eingecheckt um 08:32", "time": "2026-03-19T08:32:00"}
```

**Fehler-Response:**
```json
{"success": false, "message": "Bereits eingecheckt seit 08:32"}
```

---

## iPhone Kurzbefehle einrichten

### Vorbereitung

1. **Shortcuts-App** öffnen (vorinstalliert auf iPhone)
2. Oben rechts **+** tippen → "Leerer Kurzbefehl"

### Kurzbefehl "Arbeit Start"

| Schritt | Einstellung |
|---------|-------------|
| Aktion | **"URL-Inhalt abrufen"** |
| URL | `https://deinserver.de/api/checkin.php` |
| Methode | `POST` |
| Header | Name: `X-API-Key` / Wert: `{dein_api_key}` |
| Aktion | **"Ergebnis anzeigen"** (zeigt Bestätigung) |

**Kurzbefehl speichern** → Name: "Arbeit Start"

### Kurzbefehl "Arbeit Ende"

Gleicher Aufbau, nur URL: `.../api/checkout.php`

### Kurzbefehl "Status prüfen"

| Schritt | Einstellung |
|---------|-------------|
| Aktion | **"URL-Inhalt abrufen"** |
| URL | `https://deinserver.de/api/status.php` |
| Methode | `GET` |
| Header | Name: `X-API-Key` / Wert: `{dein_api_key}` |
| Aktion | **"Wörterbuch"** → Schlüssel `message` abrufen |
| Aktion | **"Ergebnis anzeigen"** |

---

### Automatisierung (Geo-basiert)

Mit der Automatisierungs-Funktion in Shortcuts kannst du Check-in/out automatisch auslösen:

1. Shortcuts-App → Tab **"Automatisierung"** → **+**
2. **"Wenn ich einen Ort verlasse/ankomme"** wählen
3. Arbeitsadresse eingeben
4. Aktion: den jeweiligen Kurzbefehl ausführen
5. **"Ohne Bestätigung ausführen"** aktivieren (optional)

> **Tipp:** Erstelle zwei Automatisierungen — eine für "Ankommen" (Check-in) und eine für "Verlassen" (Check-out).

---

## Sicherheitshinweise

- `config.php` wird **nie** ins Git eingecheckt (→ `.gitignore`)
- API-Key sollte mindestens 32 zufällige Bytes sein
- Web-Login verwendet `password_hash()` / `password_verify()`
- Alle Formulare sind CSRF-geschützt
- Rate Limiting: max. 10 API-Calls pro Minute pro IP
- HTTPS ist Pflicht im Produktivbetrieb

---

## Manuelle Zeiteinträge

Vergessene oder nachträgliche Einträge lassen sich unter **Monat** (`/month.php`) direkt hinzufügen:

1. Gewünschten Monat öffnen
2. Im Formular "Eintrag manuell hinzufügen" Check-in und Check-out eintragen
3. Optional eine Notiz ergänzen
4. **Hinzufügen** klicken

Bestehende Einträge können über **Bearbeiten** korrigiert oder über **Löschen** entfernt werden.

---

## Projektstruktur

```
gleitzeit/
├── config.php          # Credentials (gitignored)
├── config.example.php  # Template
├── setup.php           # DB-Setup (einmalig, danach löschen)
├── index.php           # Dashboard
├── month.php           # Monatsansicht
├── absences.php        # Abwesenheitsverwaltung
├── settings.php        # Einstellungen & Passwort
├── login.php / logout.php
├── includes/
│   ├── db.php          # PDO-Singleton
│   ├── auth.php        # Session, CSRF, API-Key, Rate Limiting
│   └── functions.php   # Überstunden-Berechnung, Hilfsfunktionen
├── api/
│   ├── checkin.php
│   ├── checkout.php
│   └── status.php
└── assets/
    ├── style.css
    └── app.js
```
