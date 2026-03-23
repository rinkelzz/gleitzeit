<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/holidays.php';
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
    return $row ?: [
        'weekly_hours'                => 40.00,
        'vacation_days_per_year'      => 30,
        'break_minutes'               => 30,
        'bundesland'                  => 'NW',
        'tracking_start_date'         => null,
        'carryover_gleitzeit_minutes' => 0,
        'carryover_overtime_minutes'  => 0,
        'carryover_vacation'          => 0.0,
    ];
}

/** Returns the date from which balances are calculated (tracking start or Jan 1). */
function getTrackingStartDate(): string {
    $settings = getSettings();
    return $settings['tracking_start_date'] ?? date('Y-01-01');
}

// ── Day flags (Überstunden-Markierung) ──────────────────────────────

function isDayOvertime(string $date): bool {
    $stmt = getDB()->prepare('SELECT overtime FROM day_flags WHERE date = ?');
    $stmt->execute([$date]);
    $row = $stmt->fetch();
    return $row ? (bool)$row['overtime'] : false;
}

function setDayOvertime(string $date, bool $overtime): void {
    if ($overtime) {
        getDB()->prepare(
            'INSERT INTO day_flags (date, overtime) VALUES (?, 1)
             ON DUPLICATE KEY UPDATE overtime = 1'
        )->execute([$date]);
    } else {
        getDB()->prepare('DELETE FROM day_flags WHERE date = ?')->execute([$date]);
    }
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
 * Net delta seconds for a given date (worked - break - target).
 *
 * Returns null if the day is a weekend or a full-day absence that
 * does NOT affect any time account (vacation / sick / holiday).
 *
 * 'gleitzeit' absences DO produce a delta (target still applies —
 * you "spend" flex time).
 */
function dailyDeltaSeconds(string $date): ?int {
    $dow = (int)date('N', strtotime($date));
    if ($dow >= 6) return null;

    $absence = getAbsenceForDate($date);

    // Full-day off types that zero out the target and produce no delta
    $zeroTargetTypes = ['vacation', 'sick', 'holiday'];
    if ($absence && !$absence['half_day'] && in_array($absence['type'], $zeroTargetTypes, true)) {
        return null;
    }

    $settings  = getSettings();
    $breakSecs = (int)$settings['break_minutes'] * 60;
    $target    = dailyTargetHours() * 3600;

    // Gleitzeittag or other full-day: target still applies, worked = 0
    if ($absence && !$absence['half_day']) {
        // gleitzeit / other full-day: 0 worked, full target → negative delta
        $worked = 0;
        $breakSecs = 0;
    } elseif ($absence && $absence['half_day']) {
        $target    /= 2;
        $breakSecs  = (int)($breakSecs / 2);
        $worked     = max(0, workedSecondsOnDate($date) - $breakSecs);
    } else {
        $worked = max(0, workedSecondsOnDate($date) - $breakSecs);
    }

    return $worked - (int)$target;
}

/**
 * Legacy wrapper — kept for compatibility (used in week table display).
 */
function overtimeSecondsOnDate(string $date): int {
    return dailyDeltaSeconds($date) ?? 0;
}

// ── Gleitzeit & Überstunden Konten ──────────────────────────────────

/**
 * Returns [gleitzeit_seconds, overtime_seconds] for a date range.
 * Days flagged as overtime or with overtime_withdrawal absence type contribute to overtime_seconds,
 * all other working days contribute to gleitzeit_seconds.
 */
function getAccountBalances(string $fromDate, string $toDate): array {
    $gleitzeit = 0;
    $overtime  = 0;
    $current   = strtotime($fromDate);
    $end       = strtotime($toDate);

    while ($current <= $end) {
        $date  = date('Y-m-d', $current);
        $delta = dailyDeltaSeconds($date);
        if ($delta !== null) {
            $absence = getAbsenceForDate($date);
            if (isDayOvertime($date) || ($absence && $absence['type'] === 'overtime_withdrawal')) {
                $overtime += $delta;
            } else {
                $gleitzeit += $delta;
            }
        }
        $current = strtotime('+1 day', $current);
    }

    return ['gleitzeit' => $gleitzeit, 'overtime' => $overtime];
}

/**
 * Total balances including carry-overs from previous year.
 * Uses tracking_start_date from settings.
 */
function getTotalBalances(): array {
    $settings  = getSettings();
    $startDate = getTrackingStartDate();
    $today     = date('Y-m-d');

    $balances = getAccountBalances($startDate, $today);

    $carryoverGleitzeit = (int)($settings['carryover_gleitzeit_minutes'] ?? 0) * 60;
    $carryoverOvertime  = (int)($settings['carryover_overtime_minutes']  ?? 0) * 60;

    return [
        'gleitzeit' => $balances['gleitzeit'] + $carryoverGleitzeit,
        'overtime'  => $balances['overtime']  + $carryoverOvertime,
    ];
}

/** Cumulative overtime in seconds from $fromDate to $toDate (kept for export.php). */
function cumulativeOvertimeSeconds(string $fromDate, string $toDate): int {
    $balances = getAccountBalances($fromDate, $toDate);
    return $balances['gleitzeit'] + $balances['overtime'];
}

// ── Absence helpers ──────────────────────────────────────────────────

function getAbsenceForDate(string $date): ?array {
    // 1. Check manually entered absences first
    $stmt = getDB()->prepare('SELECT * FROM absences WHERE date = ? LIMIT 1');
    $stmt->execute([$date]);
    $row = $stmt->fetch();
    if ($row) return $row;

    // 2. Fall back to auto-calculated public holiday for the configured Bundesland
    $settings = getSettings();
    $bl = $settings['bundesland'] ?? 'NW';
    $name = getHolidayName($date, $bl);
    if ($name) {
        return [
            'id'       => null,
            'date'     => $date,
            'type'     => 'holiday',
            'half_day' => 0,
            'note'     => $name,
            'auto'     => true,
        ];
    }
    return null;
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
    $settings  = getSettings();
    $carryover = (float)($settings['carryover_vacation'] ?? 0);
    return $settings['vacation_days_per_year'] + $carryover - vacationDaysTakenThisYear();
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
        'vacation'             => 'Urlaub',
        'sick'                 => 'Krank',
        'holiday'              => 'Feiertag',
        'gleitzeit'            => 'Gleittag',
        'overtime_withdrawal'  => 'Mehrarbeit-Entnahme',
        default                => 'Sonstiges',
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
