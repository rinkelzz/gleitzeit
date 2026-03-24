# Design: Betriebsfreie Tage

**Datum:** 2026-03-24
**Status:** Approved

---

## Kontext

Manche Unternehmen gewähren bezahlte freie Tage (z. B. 24.12. und 31.12.), die keine gesetzlichen Feiertage sind. Diese Tage sollen im Gleitzeit Tracker automatisch wie gesetzliche Feiertage behandelt werden — das Tagesziel fällt auf 0, die Tage sind nicht anwählbar im Jahreskalender, und sie erscheinen in der Feiertagsübersicht mit dem Label „Betriebsfrei".

---

## Ziel

- Betriebsfreie Tage einmalig in den Einstellungen konfigurieren (`MM-DD`-Format)
- Jedes Jahr automatisch erkannt — kein manuelles Nachtragen
- Verhalten identisch zu gesetzlichen Feiertagen (Tagesziel = 0, nicht anwählbar)
- Label: „Betriebsfrei"

---

## Datenbankschicht

**Migration** (in `setup.php`, `$migrations`-Array, bestehende try/catch-Struktur):
```sql
ALTER TABLE settings ADD COLUMN company_free_days VARCHAR(255) DEFAULT ''
```

Kein `IF NOT EXISTS` — der bestehende `catch (PDOException)`-Block toleriert MySQL-Fehler 1060 („Duplicate column name") bereits stillschweigend, genau wie alle anderen Migrationen in `setup.php`.

**Format:** Kommagetrennte `MM-DD`-Werte, z. B. `12-24,12-31`.
Leerzeichen um Kommas werden beim Parsen toleriert.

`getSettings()` liefert den Wert automatisch mit, da es `SELECT *` verwendet. Zusätzlich wird `'company_free_days' => ''` in das Fallback-Array von `getSettings()` in `includes/functions.php` eingetragen, um Konsistenz mit allen anderen Spalten zu wahren.

---

## Logik (`includes/holidays.php`)

### `getHolidays(int $year, string $bundesland): array`

Nach dem Aufbau der gesetzlichen Feiertage wird folgender Block angehängt:

```php
$settings = getSettings();
$raw = $settings['company_free_days'] ?? '';
foreach (array_filter(array_map('trim', explode(',', $raw))) as $mmdd) {
    if (preg_match('/^\d{2}-\d{2}$/', $mmdd)) {
        $holidays["$year-$mmdd"] = 'Betriebsfrei';
    }
}
```

**Validierungsverhalten:**
- Die Regex prüft nur die Syntax (`\d{2}-\d{2}`), nicht die semantische Korrektheit.
- Semantisch ungültige Werte (z. B. `13-45`, `00-00`) erzeugen Schlüssel wie `2026-13-45`, die niemals mit einem echten Datum übereinstimmen — sie werden stillschweigend ignoriert. Kein Laufzeitfehler.
- `02-29` in Nicht-Schaltjahren: Der Schlüssel `2025-02-29` existiert im `$holidays`-Array, wird aber von keiner Tageskachel oder Abwesenheitsabfrage gematcht. In `absences.php` würde `strtotime('2025-02-29')` `false` zurückgeben und zu einer fehlerhaften Datumsanzeige führen — **daher wird `02-29` beim Iterieren über `$holidays` in `absences.php` per `checkdate()` übersprungen** (siehe Abschnitt Anzeige).

### `getHolidayName(string $date, string $bundesland): ?string`

Diese Funktion delegiert bereits intern an `getHolidays()` (Zeile 135–139). Nach der Erweiterung von `getHolidays()` liefert `getHolidayName()` automatisch auch „Betriebsfrei" für konfigurierte Tage zurück — ohne weitere Änderungen. Damit fließen Betriebsfreie Tage automatisch in `getAbsenceForDate()` ein und damit in alle Berechnungen (Dashboard, Monatsansicht, Kontostand).

---

## Einstellungen (`settings.php`)

### Formularfeld

Neues Textfeld im Abschnitt „Arbeitszeit":

```html
<label>Betriebsfreie Tage
    <input type="text" name="company_free_days"
           value="<?= htmlspecialchars($settings['company_free_days'] ?? '') ?>"
           placeholder="z. B. 12-24, 12-31">
    <span class="hint">Kommagetrennte Daten im Format MM-TT — gelten jedes Jahr automatisch.</span>
</label>
```

### Speicherlogik

Der `update_settings`-Handler in `settings.php` besitzt zwei SQL-Statements (`UPDATE` und `INSERT`). Beide werden um `company_free_days` erweitert:

```php
$companyFreeDays = trim($_POST['company_free_days'] ?? '');
```

- PDO-Parametrisierung schützt gegen SQL-Injection.
- Keine weitere Validierung beim Speichern — ungültige Werte werden in `getHolidays()` stillschweigend ignoriert.
- Sowohl das `UPDATE`- als auch das `INSERT`-Statement in der Speicherlogik müssen die neue Spalte enthalten.

---

## Anzeige

### Jahreskalender (`year.php`)

Keine Änderungen nötig. Betriebsfreie Tage sind nach der Erweiterung von `getHolidays()` im `$holidays`-Array und werden von `renderTile()` automatisch als nicht-anwählbare Kachel mit dem Label „Betriebsfrei" und `tile-holiday`-Stil (orange) dargestellt.

### Abwesenheiten (`absences.php`)

Betriebsfreie Tage erscheinen in der bestehenden Feiertagsliste (Abschnitt „Gesetzliche Feiertage"), da dieser direkt `getHolidays()` iteriert.

**Abschnittstitel & Hinweistext:** Der Titel „Gesetzliche Feiertage" und der Hinweistext „Werden automatisch bei der Überstunden-Berechnung berücksichtigt." werden angepasst zu:
- Titel: `Feiertage & betriebsfreie Tage {$year} — {Bundesland}`
- Hinweis: `Werden automatisch bei der Soll-Berechnung berücksichtigt.`

**`02-29`-Schutz:** Beim Iterieren über `$holidays` in `absences.php` wird am Anfang des Schleifenkörpers (vor beiden `date()`-Aufrufen auf Zeile 158/159) ein `checkdate()`-Guard eingefügt:

```php
[$y, $m, $d] = explode('-', $date);
if (!checkdate((int)$m, (int)$d, (int)$y)) continue;
```

Ungültige Datumsschlüssel (z. B. `2025-02-29`) werden damit übersprungen, um PHP 8.1-Deprecation-Notices bei `date(..., false)` zu vermeiden.

### Dashboard & Monatsansicht (`index.php`, `month.php`)

Keine Änderungen nötig. `getAbsenceForDate()` nutzt `getHolidayName()`, das nach der Erweiterung Betriebsfreie Tage zurückliefert. Tagesziel wird automatisch auf 0 gesetzt.

---

## Betroffene Dateien

| Datei | Änderung |
|-------|----------|
| `setup.php` | Migration: neue Spalte `company_free_days` (in `$migrations`-Array) |
| `includes/holidays.php` | `getHolidays()` um Betriebsfreie Tage erweitern |
| `includes/functions.php` | `getSettings()`-Fallback um `company_free_days => ''` ergänzen |
| `settings.php` | Formularfeld + beide SQL-Statements erweitern |
| `absences.php` | `checkdate()`-Schutz + Abschnittstitel anpassen |

---

## Nicht im Scope

- Eigene CSS-Klasse für Betriebsfreie Tage (teilen `tile-holiday`-Stil)
- Halbtag-Unterstützung für Betriebsfreie Tage
- Jahresspezifische Konfiguration (gilt immer für alle Jahre)
- Semantische Validierung von MM-DD-Werten beim Speichern (ungültige Werte werden stillschweigend ignoriert)
