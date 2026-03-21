# ⏱ Gleitzeit Tracker

Persönliche Arbeitszeiterfassung mit Web-Dashboard und iPhone Shortcuts Integration.

- **Check-in/out** per iPhone Kurzbefehl (automatisch oder manuell)
- **Gleitzeit-Konto & Überstunden-Konto** — getrennte Salden, pro Tag wählbar
- **Automatische Feiertage** für alle 16 Bundesländer
- **Abwesenheitsverwaltung** für Urlaub, Krank, Gleittage und Feiertage
- **Monatsansicht** mit editierbaren und manuell hinzufügbaren Einträgen
- **Export** als CSV (Excel-kompatibel) und PDF-Druckansicht
- **Import** aus CSV mit Vorschau
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

`https://deinserver.de/setup.php` im Browser aufrufen — danach **`setup.php` löschen!**

### 5. HTTPS aktivieren

In `.htaccess` den HTTPS-Redirect einkommentieren:

```apache
RewriteCond %{HTTPS} off
RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
```

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

## Einstellungen

Unter `/settings.php` lassen sich folgende Werte anpassen:

### Arbeitszeit

| Einstellung | Beschreibung | Standard |
|-------------|--------------|---------|
| Wochenstunden (Soll) | Vertraglich vereinbarte Stunden pro Woche | 40 h |
| Urlaubstage pro Jahr | Gesamter Jahresanspruch | 30 Tage |
| Unbezahlte Pause pro Tag | Wird täglich von der Arbeitszeit abgezogen | 30 min |
| Bundesland | Für automatische Feiertags-Berechnung | NW |

Bei Halbtagsabwesenheiten wird die Pause automatisch halbiert.

### Erfassungszeitraum & Überträge

| Einstellung | Beschreibung |
|-------------|-------------|
| **Erfassungsbeginn** | Ab welchem Datum Salden berechnet werden (z. B. Eintrittsdatum). Leer = 1. Januar des aktuellen Jahres |
| **Übertrag Gleitzeit** | Gleitzeit-Saldo aus dem Vorjahr in Minuten (positiv oder negativ) |
| **Übertrag Überstunden** | Überstunden-Saldo aus dem Vorjahr in Minuten |
| **Übertrag Resturlaub** | Nicht genommene Urlaubstage aus dem Vorjahr (auch halbe Tage möglich) |

**Beispiel:** Wer am 15.06. anfängt und 3:30 h Gleitzeit sowie 5 Urlaubstage aus dem Vorjob mitbringt:
- Erfassungsbeginn: `2026-06-15`
- Übertrag Gleitzeit: `210` (= 3 h × 60 + 30 min)
- Übertrag Resturlaub: `5.0`

Das Dashboard zeigt die Konten dann ab dem Erfassungsbeginn inklusive der Überträge.

---

## Gleitzeit- und Überstunden-Konto

Das System unterscheidet zwei getrennte Zeitkonten:

| Konto | Befüllung | Anzeige im Dashboard |
|-------|-----------|----------------------|
| **Gleitzeit-Konto** | Alle normalen Arbeitstage automatisch | Blau |
| **Überstunden-Konto** | Nur Tage, die explizit als Überstunden markiert werden | Gelb |

### Überstunden markieren

In der **Monatsansicht** (`/month.php`) hat jeder Tag einen **☆ Ü**-Button. Ein Klick markiert den Tag als Überstundentag — der Tagessaldo fließt dann ins Überstunden-Konto statt ins Gleitzeit-Konto. Nochmaliger Klick hebt die Markierung auf.

### Gleitzeit entnehmen

Einen freien Tag vom Gleitzeit-Konto nehmen:

1. **Abwesenheiten** → Typ **"Gleittag (Gleitzeit entnehmen)"** eintragen
2. Das Tagessoll wird automatisch vom Gleitzeit-Konto abgezogen

Früher gehen ohne expliziten Eintrag funktioniert ebenfalls automatisch — wer weniger als das Tagessoll arbeitet, hat einen negativen Delta, der das Gleitzeit-Konto reduziert.

---

## Automatische Feiertage

Feiertage werden automatisch für das konfigurierte Bundesland berechnet — kein manuelles Eintragen nötig. Alle 16 Bundesländer werden unterstützt, inkl. aller Sonderfeiertage (Fronleichnam, Reformationstag, Buß- und Bettag etc.).

Feiertage werden in der Überstunden-Berechnung automatisch als freie Tage behandelt. Die vollständige Liste ist unter **Abwesenheiten** einsehbar.

---

## Manuelle Zeiteinträge

Vergessene oder nachträgliche Einträge lassen sich unter **Monat** (`/month.php`) direkt hinzufügen:

1. Gewünschten Monat öffnen
2. Im Formular "Eintrag manuell hinzufügen" Check-in und Check-out eintragen
3. Optional eine Notiz ergänzen → **Hinzufügen**

Bestehende Einträge können über **Bearbeiten** korrigiert oder **Löschen** entfernt werden.

---

## Export & Import

### CSV-Export

Unter **Export** (`/export.php`) den gewünschten Monat wählen und **CSV herunterladen**. Die Datei ist UTF-8 mit BOM — öffnet direkt korrekt in Excel und LibreOffice.

**Format:**
```
datum;checkin;checkout;dauer_h;notiz
2026-03-19;2026-03-19 08:30;2026-03-19 17:00;8.5;Meeting
```

### PDF-Export

Unter **Export** → **PDF-Ansicht öffnen** wird eine druckoptimierte Monatsübersicht geöffnet. Im Browser `Strg+P` → "Als PDF speichern".

### CSV-Import

Unter **Import** (`/import.php`) eine CSV-Datei hochladen. Vor dem eigentlichen Import wird eine Vorschau der erkannten Einträge angezeigt — erst nach Bestätigung werden die Daten übernommen. Vorhandene Einträge werden nicht überschrieben.

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

### Automatisierung (Geo-basiert)

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

## Datenbankschema

```sql
CREATE TABLE settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    weekly_hours DECIMAL(4,2) DEFAULT 40.00,
    vacation_days_per_year INT DEFAULT 30,
    break_minutes INT DEFAULT 30,
    bundesland VARCHAR(2) DEFAULT 'NW',
    tracking_start_date DATE DEFAULT NULL,
    carryover_gleitzeit_minutes INT DEFAULT 0,
    carryover_overtime_minutes INT DEFAULT 0,
    carryover_vacation DECIMAL(4,1) DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE time_entries (
    id INT PRIMARY KEY AUTO_INCREMENT,
    checkin_time DATETIME NOT NULL,
    checkout_time DATETIME NULL,
    note VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE absences (
    id INT PRIMARY KEY AUTO_INCREMENT,
    date DATE NOT NULL,
    type ENUM('vacation','sick','holiday','gleitzeit','other') NOT NULL,
    half_day TINYINT(1) DEFAULT 0,
    note VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE day_flags (
    date DATE PRIMARY KEY,
    overtime TINYINT(1) DEFAULT 0
);
```

---

## Projektstruktur

```
gleitzeit/
├── config.php            # Credentials (gitignored)
├── config.example.php    # Template
├── setup.php             # DB-Setup (einmalig, danach löschen)
├── index.php             # Dashboard (Gleitzeit- & Überstunden-Konto)
├── month.php             # Monatsansicht mit Überstunden-Toggle
├── absences.php          # Abwesenheiten & Feiertagsübersicht
├── export.php            # CSV- und PDF-Export
├── import.php            # CSV-Import mit Vorschau
├── settings.php          # Einstellungen & Passwort
├── login.php / logout.php
├── includes/
│   ├── db.php            # PDO-Singleton
│   ├── auth.php          # Session, CSRF, API-Key, Rate Limiting
│   ├── functions.php     # Zeitberechnung, Gleitzeit/Überstunden-Konten
│   ├── holidays.php      # Feiertage nach Bundesland (alle 16)
│   └── nav.php           # Gemeinsame Navigation
├── api/
│   ├── checkin.php
│   ├── checkout.php
│   └── status.php
└── assets/
    ├── style.css
    └── app.js
```
