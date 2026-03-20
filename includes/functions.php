<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../config.php';

date_default_timezone_set(TIMEZONE);

// ── Time entry helpers ──────────────────────────────────────────────

function getOpenEntry(): ?array {
    $stmt = getDB()->query('SELECT * FROM time_entries WHERE checkout_time IS NULL ORDER BY checkin_time DESC LIMIT 1');
    $row = $stmt->fetch();
    return $row ?: null;
}

function checkin(?string $note = null): array {
    $open = getOpenEntry();
    if ($open) {
        return ['success' => false, 'message' => 'Bereits eingecheckt seit ' . formatTime($open['checkin_time'])];
    }
    $now = date('Y-m-d H:i:s');
    $stmt = getDB()->prepare('INSERT INTO time_entries (checkin_time, note) VALUES (?, ?)');
    $stmt->execute([$now, $note]);
    return ['success' => true, 'message' => 'Eingecheckt um ' . date('H:i'), 'time' => $now];
}

function checkout(?string $note = null): array {
    $open = getOpenEntry();
    if (!$open) {
        return ['success' => false, 'message' => 'Kein offener Eintrag gefunden'];
    }
    $now = date('Y-m-d H:i:s');
    $noteUpdate = $note ?? $open['note'];
    $stmt = getDB()->prepare('UPDATE time_entries SET checkout_time = ?, note = ? WHERE id = ?');
    $stmt->execute([$now, $noteUpdate, $open['id']]);
    $duration = formatDuration(strtotime($open['checkin_time']), strtotime($now));
    return ['success' => true, 'message' => 'Ausgecheckt um ' . date('H:i') . ' (Dauer: ' . $duration . ')', 'time' => $now];
}

// ── Settings ────────────────────────────────────────────────────────

function getSettings(): array {
    $row = getDB()->query('SELECT * FROM settings LIMIT 1')->fetch();
    return $row ?: ['weekly_hours' => 40.00, 'vacation_days_per_year' => 30, 'break_minutes' => 30];
}

// ── Hours calculation ────────────────────────────────────────────────

/** Returns daily target hours (Mon–Fri, equal distribution). */
function dailyTargetHours(): float {
    $settings = getSettings();
    return round((float)$settings['weekly_hours'] / 5, 4);
}

/** Sum of worked seconds for a given date string (Y-m-d). */
function workedSecondsOnDate(string $date): int {
    $stmt = getDB()->prepare(
        'SELECT checkin_time, checkout_time FROM time_entries
         WHERE DATE(checkin_time) = ? AND checkout_time IS NOT NULL'
    );
    $stmt->execute([$date]);
    $total = 0;
    foreach ($stmt->fetchAll() as $row) {
        $total += strtotime($row['checkout_time']) - strtotime($row['checkin_time']);
    }
    return $total;
}

/**
 * Overtime seconds for a given date.
 * Returns 0 for weekends and absence days (vacation/holiday/sick).
 * For half-day absences, target is reduced by half.
 */
function overtimeSecondsOnDate(string $date): int {
    $dow = (int)date('N', strtotime($date)); // 1=Mon … 7=Sun
    if ($dow >= 6) return 0;

    $absence = getAbsenceForDate($date);
    if ($absence && !$absence['half_day']) return 0; // full day off → no overtime delta

    $settings = getSettings();
    $breakSecs = (int)$settings['break_minutes'] * 60;

    $target = dailyTargetHours() * 3600;
    if ($absence && $absence['half_day']) {
        $target /= 2;
        $breakSecs = (int)($breakSecs / 2);
    }

    $worked = max(0, workedSecondsOnDate($date) - $breakSecs);
    return $worked - (int)$target;
}

/** Cumulative overtime in seconds from $fromDate to $toDate (inclusive). */
function cumulativeOvertimeSeconds(string $fromDate, string $toDate): int {
    $total = 0;
    $current = strtotime($fromDate);
    $end = strtotime($toDate);
    while ($current <= $end) {
        $total += overtimeSecondsOnDate(date('Y-m-d', $current));
        $current = strtotime('+1 day', $current);
    }
    return $total;
}

// ── Absence helpers ──────────────────────────────────────────────────

function getAbsenceForDate(string $date): ?array {
    $stmt = getDB()->prepare('SELECT * FROM absences WHERE date = ? LIMIT 1');
    $stmt->execute([$date]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function vacationDaysTakenThisYear(): float {
    $year = date('Y');
    $stmt = getDB()->prepare(
        "SELECT SUM(IF(half_day, 0.5, 1)) AS total FROM absences
         WHERE type = 'vacation' AND YEAR(date) = ?"
    );
    $stmt->execute([$year]);
    return (float)($stmt->fetchColumn() ?? 0);
}

function remainingVacationDays(): float {
    $settings = getSettings();
    return $settings['vacation_days_per_year'] - vacationDaysTakenThisYear();
}

// ── Formatting ───────────────────────────────────────────────────────

function formatTime(string $datetime): string {
    return date('H:i', strtotime($datetime));
}

function formatDuration(int $from, int $to): string {
    $secs = max(0, $to - $from);
    $h = (int)($secs / 3600);
    $m = (int)(($secs % 3600) / 60);
    return sprintf('%d:%02d h', $h, $m);
}

function formatSeconds(int $secs): string {
    $neg = $secs < 0;
    $secs = abs($secs);
    $h = (int)($secs / 3600);
    $m = (int)(($secs % 3600) / 60);
    return ($neg ? '-' : '+') . sprintf('%d:%02d h', $h, $m);
}

function absenceLabel(string $type): string {
    return match($type) {
        'vacation' => 'Urlaub',
        'sick'     => 'Krank',
        'holiday'  => 'Feiertag',
        default    => 'Sonstiges',
    };
}

// ── Week helpers ─────────────────────────────────────────────────────

function getWeekDays(string $mondayDate): array {
    $days = [];
    $mon = strtotime($mondayDate);
    for ($i = 0; $i < 5; $i++) {
        $days[] = date('Y-m-d', strtotime("+$i days", $mon));
    }
    return $days;
}

function getCurrentMonday(): string {
    return date('Y-m-d', strtotime('monday this week'));
}
