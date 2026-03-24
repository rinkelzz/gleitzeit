# Jahres-Kalenderüberblick — Design Spec

**Datum:** 2026-03-23
**Status:** Approved

---

## Ziel

Neue Seite `year.php`: alle 12 Monate als farbcodierte Mini-Kalender, Drag-Selektion für Abwesenheiten.

---

## Architektur

- Neue Datei `year.php` — selbes Pattern wie `month.php` (PHP + HTML + CSS + Vanilla JS)
- `requireLogin()` ganz oben, wie in allen anderen Seiten
- Klassisches Form-POST mit Redirect auf `/year.php?year=<aktuelles Jahr>`
- Datenhaltung in der bestehenden `absences`-Tabelle
- Navigation: Link „Jahr" in `includes/nav.php` hinzufügen

---

## Datenbank-Migration

`setup.php` wird um Migrationen erweitert, die idempotent per Try/Catch laufen:

```sql
-- 1. ENUM erweitern (gleitzeit + overtime_withdrawal)
ALTER TABLE absences
  MODIFY type ENUM('vacation','sick','holiday','gleitzeit','overtime_withdrawal','other') NOT NULL;

-- 2. UNIQUE KEY für date (ein Eintrag pro Tag)
--    Vor Ausführung prüfen ob Duplikate existieren:
--    SELECT date, COUNT(*) FROM absences GROUP BY date HAVING COUNT(*) > 1;
--    Duplikate müssen zuerst in absences.php manuell bereinigt werden.
ALTER TABLE absences ADD UNIQUE KEY uq_absences_date (date);
```

Migration 2 wird in einem eigenen try/catch-Block ausgeführt — schlägt sie fehl (Duplikate vorhanden), wird ein Hinweistext angezeigt statt die Seite zu crashen.

---

## Änderungen an bestehenden Dateien

| Datei | Änderung |
|-------|----------|
| `setup.php` | Migration für ENUM + UNIQUE KEY (s.o.) |
| `includes/functions.php` | 1. `absenceLabel()`: `'overtime_withdrawal' => 'Mehrarbeit-Entnahme'` ergänzen<br>2. `getAccountBalances()`: `overtime_withdrawal`-Tage in `$overtime`-Bucket buchen (s.u.) |
| `includes/nav.php` | `<a href="/year.php">Jahr</a>` hinzufügen |
| `absences.php` | `$validTypes` um `'gleitzeit'` und `'overtime_withdrawal'` ergänzen |

---

## Farbkodierung & Kürzel

`year.php` verwendet eine eigene Kürzel-Map — `absenceLabel()` wird **nicht** für Kachelbeschriftung genutzt:

| Kürzel | Typ (`absences.type`)  | Bedeutung               | Farbe          |
|--------|------------------------|-------------------------|----------------|
| TU     | `vacation`             | Tarifurlaub             | grün           |
| EGZ    | `gleitzeit`            | Entnahme Gleitzeitkonto | blau           |
| EMA    | `overtime_withdrawal`  | Entnahme Mehrarbeit     | lila           |
| AU     | `sick`                 | Krank                   | rot            |
| FT     | `holiday`              | Feiertag (auto)         | orange         |
| ½      | any mit `half_day=1`   | Halber Tag (readonly)   | helle Variante + „½" |
| —      | Wochenende (Sa/So)     | —                       | grau           |
| —      | Arbeitstag (leer)      | Normaler Tag            | weiß           |

---

## UI-Layout

- **Jahresnavigation** oben: `← 2025  2026  2027 →`
- **2×6 CSS-Grid**: 2 Spalten, 6 Monate je Spalte (Jan–Jun links, Jul–Dez rechts)
- Jeder Monatsblock: Monatsname, Wochentag-Header Mo–So, Tages-Kacheln
- Jede Kachel: Datumszahl + Kürzel-Label bei Abwesenheit
- **Legende** unterhalb des Kalenders

### Kachel-Datenattribute (für JS)

PHP rendert auf jeder Kachel:
```html
<div class="day-tile"
     data-date="2026-06-15"
     data-type="vacation"       <!-- leer wenn kein Eintrag -->
     data-id="42"               <!-- leer wenn kein Eintrag oder auto-Feiertag -->
     data-halfday="0"           <!-- 1 bei half_day -->
     data-selectable="1">      <!-- 0 für Wochenende, Feiertag, Half-Day -->
```

`data-selectable="0"` → Kachel nimmt nicht an Drag-Selektion teil und ist nicht klickbar.

Half-Day-Kacheln haben zwar eine `data-id`, sind aber `data-selectable="0"` — sie können nicht über den Jahreskalender gelöscht werden. Löschen erfolgt ausschließlich über `absences.php`.

---

## Drag-Selektion (JS)

```
dragActive  = false   // mousedown wurde gedrückt
dragMoved   = false   // mouseover auf andere Kachel ausgelöst
selectedDates = []
```

**Ablauf:**

1. `mousedown` auf Kachel mit `data-selectable="1"` → `dragActive = true`, `dragMoved = false`, Kachel zu `selectedDates` hinzufügen, visuelles Highlight setzen
2. `mouseover` auf andere Kachel (während `dragActive`) → `dragMoved = true`, Kachel zu `selectedDates` hinzufügen (wenn `data-selectable="1"`)
3. `mouseup` (document-level):
   - `dragActive = false`
   - Wenn `!dragMoved` (= reiner Klick):
     - Wenn Kachel hat `data-id` (manueller Eintrag) → Confirm-Popup „Eintrag löschen?" → Ja → POST `action=delete`
     - Wenn Kachel hat kein `data-id` oder `data-selectable="0"` → nichts
   - Wenn `dragMoved && selectedDates.length > 0` → Auswahl-Panel anzeigen

**Auswahl-Panel:**
- Typ-Buttons: TU / EGZ / EMA / AU
- Notiz-Feld (max. 255 Zeichen)
- „Eintragen"-Button → Formular mit `dates[]`-Hidden-Inputs abschicken
- „Abbrechen"-Button → Panel schließen, Highlights entfernen, `selectedDates = []`

---

## POST-Handling

Alle POST-Actions beginnen mit:
```php
requireLogin();
if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) die('CSRF-Fehler');
$year = (int)($_GET['year'] ?? date('Y'));
$year = ($year >= 2000 && $year <= 2100) ? $year : (int)date('Y'); // Bounds-Check
```

### `action=add`

```
Empfängt: dates[] (Array Y-m-d), type (string), note (optional), csrf_token
```

Server-Validierung:
- `$validTypes = ['vacation', 'sick', 'gleitzeit', 'overtime_withdrawal']`
- `in_array($type, $validTypes, true)` → bei Fehler: Redirect ohne Insert
- `dates[]` leer → Redirect ohne Insert
- Jedes Datum: `preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)` → ungültige überspringen
- `$note = substr(trim($_POST['note'] ?? ''), 0, 255)`

SQL pro Datum (dank UNIQUE KEY):
```sql
INSERT INTO absences (date, type, half_day, note)
VALUES (?, ?, 0, ?)
ON DUPLICATE KEY UPDATE type = VALUES(type), note = VALUES(note)
```

Redirect: `Location: /year.php?year=$year`

### `action=delete`

```
Empfängt: id (int), csrf_token
```

```php
$id = (int)($_POST['id'] ?? 0);
if ($id > 0) {
    $db->prepare('DELETE FROM absences WHERE id = ?')->execute([$id]);
}
```

Redirect: `Location: /year.php?year=$year`

---

## Business-Logik: `overtime_withdrawal` in `getAccountBalances()`

`dailyDeltaSeconds()` berechnet für `overtime_withdrawal`-Tage: `0 gearbeitet − volle Sollzeit = negativer Delta` (korrekt, da die Entnahme das Überstunden-Konto reduziert).

`isDayOvertime($date)` prüft nur das `day_flags`-DB-Flag — es gibt `false` zurück wenn kein Eintrag in `day_flags` existiert, also auch für nicht-gearbeitete `overtime_withdrawal`-Tage. Deshalb brauchen wir den Absence-Typ-Check als zusätzliche Bedingung.

**Fix in `getAccountBalances()`:**

```php
$delta = dailyDeltaSeconds($date);
if ($delta !== null) {
    $absence = getAbsenceForDate($date);
    if (isDayOvertime($date) || ($absence && $absence['type'] === 'overtime_withdrawal')) {
        $overtime += $delta;
    } else {
        $gleitzeit += $delta;
    }
}
```

Der Typ `holiday` wird im Add-Panel **nicht angeboten** — Feiertage werden automatisch aus `holidays.php` berechnet. Die Farb-/Kürzel-Map zeigt FT/orange nur zur Anzeige, nicht zum Eintragen.

---

## Out of Scope

- Halbe Tage per Drag eintragen (Anzeige bestehender Half-Day-Einträge: ja, aber readonly)
- AJAX
- Export
- `other`-Typ im Jahreskalender eintragen
