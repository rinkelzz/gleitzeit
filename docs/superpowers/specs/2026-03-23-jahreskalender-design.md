# Jahres-Kalenderüberblick — Design Spec

**Datum:** 2026-03-23
**Status:** Approved

---

## Ziel

Eine neue Seite `year.php`, die alle 12 Monate eines Jahres als farbcodierte Mini-Kalender darstellt. Der Nutzer kann per Drag-Selektion mehrere Tage auswählen und ihnen einen Abwesenheitstyp zuweisen oder bestehende Einträge löschen.

---

## Architektur

- Neue Datei `year.php` — selbes Pattern wie `month.php` (PHP + HTML + CSS + Vanilla JS)
- Kein AJAX — klassisches Form-POST mit Redirect
- Datenhaltung in der bestehenden `absences`-Tabelle
- Neuer Abwesenheitstyp `overtime_withdrawal` (EMA) wird in `absenceLabel()` in `includes/functions.php` ergänzt
- Navigation: Link "Jahr" in `includes/nav.php` hinzufügen

---

## Farbkodierung

| Kürzel | Typ (`absences.type`) | Bedeutung               | Farbe   |
|--------|----------------------|-------------------------|---------|
| TU     | `vacation`           | Tarifurlaub             | grün    |
| EGZ    | `gleitzeit`          | Entnahme Gleitzeitkonto | blau    |
| EMA    | `overtime_withdrawal`| Entnahme Mehrarbeit     | lila    |
| AU     | `sick`               | Krank                   | rot     |
| —      | `holiday`            | Feiertag (auto)         | orange  |
| —      | Wochenende           | Sa/So                   | grau    |
| —      | Arbeitstag (leer)    | Normaler Tag            | weiß    |

---

## UI-Layout

- **Jahresnavigation** oben: `← 2025  2026  2027 →`
- **2×6 CSS-Grid**: 2 Spalten, 6 Monate pro Spalte (Jan–Jun links, Jul–Dez rechts)
- Jeder Monatsblock: Monatsname als Überschrift, Wochentag-Header (Mo–So), Tages-Kacheln
- Jede Kachel: Datumszahl, Hintergrundfarbe je Status, Kürzel-Label bei Abwesenheit

---

## Drag-Selektion

1. `mousedown` auf Kachel → Selektion startet, Kachel bekommt Highlight-Stil
2. `mouseover` über weitere Kacheln → Range wird aufgespannt; Wochenenden und Feiertage werden übersprungen (nicht selektierbar)
3. `mouseup` → Auswahl-Panel erscheint mit:
   - Typ-Auswahl: TU / EGZ / EMA / AU (Radio-Buttons oder Buttons)
   - Optionales Notiz-Textfeld
   - "Eintragen"-Button → POST
   - "Abbrechen"-Button → Panel schließen, Selektion aufheben
4. Selektierte Tage werden als `<input type="hidden" name="dates[]">` ins Formular geschrieben

---

## Löschen

- Klick (ohne Drag) auf einen bereits belegten Tag → kleines Popup/Confirm mit "Löschen"
- POST mit `action=delete` und `date=YYYY-MM-DD`
- Nur manuell eingetragene Einträge löschbar (auto-Feiertage nicht)

---

## POST-Handling in `year.php`

### `action=add`
- Empfängt: `dates[]` (Array), `type`, `note` (optional)
- Validierung: Typ muss gültig sein, Datumsformat prüfen
- `INSERT INTO absences (date, type, half_day, note) VALUES (?, ?, 0, ?) ON DUPLICATE KEY UPDATE type=?, note=?`
- CSRF-Token-Prüfung

### `action=delete`
- Empfängt: `date` (einzeln)
- `DELETE FROM absences WHERE date = ? AND auto IS NULL` (nur manuelle)
- CSRF-Token-Prüfung

---

## Änderungen an bestehenden Dateien

| Datei | Änderung |
|-------|----------|
| `includes/functions.php` | `absenceLabel()`: `'overtime_withdrawal' => 'Mehrarbeit-Entnahme'` ergänzen |
| `includes/nav.php` | Link `<a href="/year.php">Jahr</a>` hinzufügen |

---

## Out of Scope

- Halbe Tage im Jahreskalender (nur in `absences.php` möglich)
- AJAX/Live-Updates
- Export des Jahreskalenders
