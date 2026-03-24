# Betriebsfreie Tage Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Konfigurierbare betriebsfreie Tage (z. B. 24.12., 31.12.) die jedes Jahr automatisch wie gesetzliche Feiertage behandelt werden — Tagesziel = 0, nicht anwählbar im Jahreskalender.

**Architecture:** Ein neues Textfeld in den Settings speichert kommagetrennte `MM-DD`-Werte. `getHolidays()` wird am Ende um diese Tage ergänzt — da alle anderen Stellen (Dashboard, Monatsansicht, Jahreskalender, Abwesenheiten) bereits über `getHolidays()` / `getHolidayName()` arbeiten, fließt die Änderung automatisch durch das gesamte System.

**Tech Stack:** PHP 8.1, MySQL 8.0, PDO

---

## File Map

| Datei | Was ändert sich |
|-------|-----------------|
| `setup.php` | Neue Migration: `company_free_days`-Spalte in `settings` |
| `includes/functions.php` | Fallback-Array in `getSettings()` um `company_free_days` ergänzen |
| `includes/holidays.php` | `getHolidays()` am Ende um betriebsfreie Tage erweitern |
| `settings.php` | Formularfeld + `$companyFreeDays`-Variable + beide SQL-Statements |
| `absences.php` | `checkdate()`-Guard in Feiertagsloop + Titel/Hinweis anpassen |

---

## Task 1: DB-Migration

**Files:**
- Modify: `setup.php:59-62`

- [ ] **Schritt 1: Migration hinzufügen**

In `setup.php`, `$migrations`-Array um folgenden Eintrag ergänzen (als dritten Eintrag):

```php
"ALTER TABLE settings ADD COLUMN company_free_days VARCHAR(255) DEFAULT ''",
```

Das Array sieht danach so aus:

```php
$migrations = [
    "ALTER TABLE absences MODIFY type ENUM('vacation','sick','holiday','gleitzeit','overtime_withdrawal','bildungsurlaub','other') NOT NULL",
    "ALTER TABLE absences ADD UNIQUE KEY uq_absences_date (date)",
    "ALTER TABLE settings ADD COLUMN company_free_days VARCHAR(255) DEFAULT ''",
];
```

- [ ] **Schritt 2: Migration manuell ausführen**

```bash
docker compose up -d
curl -s http://localhost:8081/setup.php | grep -E "(Migration|Fehler|OK|uebersprungen)"
```

Erwartete Ausgabe: `✓ Migration 3 OK.` (oder „uebersprungen" wenn Spalte schon existiert — beides korrekt).

- [ ] **Schritt 3: Spalte prüfen**

```bash
docker compose exec db mysql -ugleitzeit -pgleitzeit gleitzeit -e "DESCRIBE settings;" | grep company
```

Erwartete Ausgabe: `company_free_days | varchar(255) | YES | | |`

- [ ] **Schritt 4: Committen**

```bash
git add setup.php
git commit -m "feat: add company_free_days migration to settings table"
```

---

## Task 2: getSettings()-Fallback ergänzen

**Files:**
- Modify: `includes/functions.php:44-53`

- [ ] **Schritt 1: Fallback-Array erweitern**

In `includes/functions.php`, das Fallback-Array in `getSettings()` um die neue Spalte ergänzen:

```php
return $row ?: [
    'weekly_hours'                => 40.00,
    'vacation_days_per_year'      => 30,
    'break_minutes'               => 30,
    'bundesland'                  => 'NW',
    'tracking_start_date'         => null,
    'carryover_gleitzeit_minutes' => 0,
    'carryover_overtime_minutes'  => 0,
    'carryover_vacation'          => 0.0,
    'company_free_days'           => '',
];
```

- [ ] **Schritt 2: Committen**

```bash
git add includes/functions.php
git commit -m "feat: add company_free_days to getSettings() fallback"
```

---

## Task 3: getHolidays() erweitern

**Files:**
- Modify: `includes/holidays.php:128-129`

- [ ] **Schritt 1: Block vor ksort() einfügen**

In `includes/holidays.php`, direkt **vor** `ksort($days); return $days;` (Zeile 128) folgenden Block einfügen:

```php
// ── Betriebsfreie Tage ──────────────────────────────────────────────
$settings = getSettings();
$raw = $settings['company_free_days'] ?? '';
foreach (array_filter(array_map('trim', explode(',', $raw))) as $mmdd) {
    if (preg_match('/^\d{2}-\d{2}$/', $mmdd)) {
        $days[sprintf('%04d-%s', $y, $mmdd)] = 'Betriebsfrei';
    }
}
```

Hinweis: Die Variable heißt in dieser Funktion `$days` (nicht `$holidays`) und das Jahr ist `$y` (nicht `$year`).

- [ ] **Schritt 2: Manuell prüfen**

Browser öffnen: `http://localhost:8081/year.php`

Erwartung: Noch keine Änderung sichtbar (da noch kein Wert in den Settings).

- [ ] **Schritt 3: Committen**

```bash
git add includes/holidays.php
git commit -m "feat: extend getHolidays() with company_free_days from settings"
```

---

## Task 4: Settings-Formular & Speicherlogik

**Files:**
- Modify: `settings.php:18-62` (Speicherlogik)
- Modify: `settings.php:104-...` (Formular)

- [ ] **Schritt 1: Variable in Speicherlogik hinzufügen**

In `settings.php`, nach Zeile 26 (`$carryoverVacation = ...`) folgende Zeile einfügen:

```php
$companyFreeDays = trim($_POST['company_free_days'] ?? '');
```

- [ ] **Schritt 2: UPDATE-Statement erweitern**

Das bestehende `UPDATE settings SET ...`-Statement (Zeilen 40–50) so anpassen:

```php
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
```

- [ ] **Schritt 3: INSERT-Statement erweitern**

Das bestehende `INSERT INTO settings ...`-Statement (Zeilen 52–62) so anpassen:

```php
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
```

- [ ] **Schritt 4: Formularfeld hinzufügen**

In `settings.php` (Zeile 155) das Submit-Feld als `old_string`-Anker verwenden und das neue Label davor einfügen:

old_string:
```
            <button type="submit" class="btn btn-primary">Speichern</button>
```

new_string:
```html
            <label>Betriebsfreie Tage
                <input type="text" name="company_free_days"
                       value="<?= htmlspecialchars($settings['company_free_days'] ?? '') ?>"
                       placeholder="z. B. 12-24, 12-31">
                <span class="hint">Kommagetrennte Daten im Format MM-TT — gelten jedes Jahr automatisch.</span>
            </label>
            <button type="submit" class="btn btn-primary">Speichern</button>
```

- [ ] **Schritt 5: Manuell testen**

1. `http://localhost:8081/settings.php` öffnen
2. Feld „Betriebsfreie Tage" mit `12-24, 12-31` befüllen und speichern
3. Seite neu laden — Feld muss `12-24, 12-31` anzeigen
4. `http://localhost:8081/year.php` öffnen — 24.12. und 31.12. müssen als orangene, nicht-anwählbare Kacheln mit Label „Betriebsfrei" erscheinen
5. `http://localhost:8081/` (Dashboard) öffnen und zu einem Tag nach dem 24.12. navigieren — Tagesziel muss für 24.12. und 31.12. auf 0 fallen

- [ ] **Schritt 6: Committen**

```bash
git add settings.php
git commit -m "feat: add company_free_days field to settings form and save logic"
```

---

## Task 5: absences.php — Guard & Texte anpassen

**Files:**
- Modify: `absences.php:142-145` (Titel & Hinweis)
- Modify: `absences.php:152-169` (checkdate-Guard im Loop)

- [ ] **Schritt 1: Abschnittstitel und Hinweis anpassen**

Zeile 142 ändern:
```php
// Vorher:
<h3>Gesetzliche Feiertage <?= $year ?> — <?= htmlspecialchars(BUNDESLAENDER[$bl] ?? $bl) ?></h3>

// Nachher:
<h3>Feiertage &amp; betriebsfreie Tage <?= $year ?> — <?= htmlspecialchars(BUNDESLAENDER[$bl] ?? $bl) ?></h3>
```

Zeile 144 ändern:
```php
// Vorher:
Werden automatisch bei der Überstunden-Berechnung berücksichtigt.

// Nachher:
Werden automatisch bei der Soll-Berechnung berücksichtigt.
```

- [ ] **Schritt 2: checkdate()-Guard in den Feiertagsloop einfügen**

Am Anfang des `<?php`-Blocks innerhalb des `foreach ($holidays as $date => $name):`-Loops (aktuell Zeile 153–156), direkt als erste Zeile einfügen:

```php
[$y, $m, $d] = explode('-', $date);
if (!checkdate((int)$m, (int)$d, (int)$y)) continue;
```

Der Block sieht danach so aus:

```php
<?php foreach ($holidays as $date => $name): ?>
    <?php
    [$y, $m, $d] = explode('-', $date);
    if (!checkdate((int)$m, (int)$d, (int)$y)) continue;
    $manual = getAbsenceForDate($date);
    $isManual = $manual && empty($manual['auto']);
    ?>
```

- [ ] **Schritt 3: Manuell prüfen**

`http://localhost:8081/absences.php` öffnen:
- Abschnittstitel lautet „Feiertage & betriebsfreie Tage {Jahr} — {Bundesland}"
- 24. Dezember und 31. Dezember erscheinen mit Name „Betriebsfrei" in der Liste
- Keine PHP-Notices in der Log-Ausgabe

- [ ] **Schritt 4: Committen**

```bash
git add absences.php
git commit -m "feat: add checkdate guard and update section heading in absences"
```

---

## Task 6: README aktualisieren & PR erstellen

**Files:**
- Modify: `README.md`

- [ ] **Schritt 1: README ergänzen**

Im Abschnitt „Einstellungen → Arbeitszeit" der README eine neue Tabellenzeile ergänzen:

```markdown
| Betriebsfreie Tage | Kommagetrennte Daten im Format MM-TT (z. B. `12-24, 12-31`) — gelten jedes Jahr automatisch als bezahlte Freizeit | — |
```

- [ ] **Schritt 2: Committen & PR erstellen**

```bash
git add README.md
git commit -m "docs: document company_free_days setting in README"
git push
gh pr create \
  --title "feat: add configurable Betriebsfreie Tage" \
  --body "Adds company-specific free days (e.g. 24.12., 31.12.) configurable via Settings. Days are treated like public holidays — target drops to 0, non-selectable in year calendar, shown in the holiday list as 'Betriebsfrei'."
```
