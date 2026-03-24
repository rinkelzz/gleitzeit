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

**Migration** (in `setup.php`):
```sql
ALTER TABLE settings ADD COLUMN IF NOT EXISTS company_free_days VARCHAR(255) DEFAULT ''
```

**Format:** Kommagetrennte `MM-DD`-Werte, z. B. `12-24,12-31`.
Leerzeichen um Kommas werden beim Parsen toleriert.

`getSettings()` liefert den Wert automatisch mit — keine weiteren Änderungen an der Settings-Abfragelogik nötig.

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

### `getHolidayName(string $date, string $bundesland): ?string`

Diese Funktion wird so angepasst, dass sie `getHolidays()` intern aufruft und das Ergebnis für das gesuchte Datum zurückliefert. Damit fließen Betriebsfreie Tage automatisch in `getAbsenceForDate()` ein — und damit in alle Berechnungen (Dashboard, Monatsansicht, Kontostand).

---

## Einstellungen (`settings.php`)

Neues Formularfeld im Abschnitt „Arbeitszeit":

```html
<label>Betriebsfreie Tage
    <input type="text" name="company_free_days"
           value="..." placeholder="z. B. 12-24, 12-31">
    <span class="hint">Kommagetrennte Daten im Format MM-TT — gelten jedes Jahr automatisch.</span>
</label>
```

Beim Speichern wird der Wert nach `trim()` und Basis-Sanitierung in die `settings`-Tabelle geschrieben.

---

## Anzeige

### Jahreskalender (`year.php`)

Keine Änderungen nötig. Betriebsfreie Tage sind nach der Änderung in `getHolidays()` im `$holidays`-Array enthalten und werden von `renderTile()` automatisch als nicht-anwählbare Kachel mit dem Label „Betriebsfrei" und `tile-holiday`-Stil (orange) dargestellt.

### Abwesenheiten (`absences.php`)

Betriebsfreie Tage erscheinen automatisch in der Feiertagsliste, da diese direkt `getHolidays()` verwendet. Kein eigener Abschnitt nötig — „Betriebsfrei" taucht als Name in der Liste auf.

### Dashboard & Monatsansicht (`index.php`, `month.php`)

Keine Änderungen nötig. `getAbsenceForDate()` nutzt `getHolidayName()`, das nach der Anpassung Betriebsfreie Tage zurückliefert. Tagesziel wird automatisch auf 0 gesetzt.

---

## Betroffene Dateien

| Datei | Änderung |
|-------|----------|
| `setup.php` | Migration: neue Spalte `company_free_days` |
| `includes/holidays.php` | `getHolidays()` + `getHolidayName()` erweitern |
| `settings.php` | Neues Formularfeld + Speicherlogik |

---

## Nicht im Scope

- Eigene CSS-Klasse für Betriebsfreie Tage (teilen `tile-holiday`-Stil)
- Halbtag-Unterstützung für Betriebsfreie Tage
- Jahresspezifische Konfiguration (gilt immer für alle Jahre)
