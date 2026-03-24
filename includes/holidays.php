<?php
/**
 * Deutsche Feiertage nach Bundesland.
 * Gibt ein Array zurück: ['Y-m-d' => 'Feiertagsname']
 */

const BUNDESLAENDER = [
    'BW' => 'Baden-Württemberg',
    'BY' => 'Bayern',
    'BE' => 'Berlin',
    'BB' => 'Brandenburg',
    'HB' => 'Bremen',
    'HH' => 'Hamburg',
    'HE' => 'Hessen',
    'MV' => 'Mecklenburg-Vorpommern',
    'NI' => 'Niedersachsen',
    'NW' => 'Nordrhein-Westfalen',
    'RP' => 'Rheinland-Pfalz',
    'SL' => 'Saarland',
    'SN' => 'Sachsen',
    'ST' => 'Sachsen-Anhalt',
    'SH' => 'Schleswig-Holstein',
    'TH' => 'Thüringen',
];

function getEasterSunday(int $year): DateTimeImmutable {
    // Gauß'sche Osterformel
    $a = $year % 19;
    $b = intdiv($year, 100);
    $c = $year % 100;
    $d = intdiv($b, 4);
    $e = $b % 4;
    $f = intdiv($b + 8, 25);
    $g = intdiv($b - $f + 1, 3);
    $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4);
    $k = $c % 4;
    $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
    $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $month = intdiv($h + $l - 7 * $m + 114, 31);
    $day   = (($h + $l - 7 * $m + 114) % 31) + 1;
    return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
}

function easterOffset(DateTimeImmutable $easter, int $days): string {
    return $easter->modify("$days days")->format('Y-m-d');
}

/**
 * Gibt alle Feiertage für ein Jahr und Bundesland zurück.
 * @return array<string, string>  ['Y-m-d' => 'Name']
 */
function getHolidays(int $year, string $bundesland): array {
    $bl = strtoupper($bundesland);
    $easter = getEasterSunday($year);
    $y = $year;

    $days = [];

    $add = function(string $date, string $name) use (&$days) {
        $days[$date] = $name;
    };

    // ── Bundesweite Feiertage ────────────────────────────────────────
    $add(sprintf('%04d-01-01', $y),  'Neujahr');
    $add(easterOffset($easter, -2),  'Karfreitag');
    $add(easterOffset($easter,  1),  'Ostermontag');
    $add(sprintf('%04d-05-01', $y),  'Tag der Arbeit');
    $add(easterOffset($easter, 39),  'Christi Himmelfahrt');
    $add(easterOffset($easter, 50),  'Pfingstmontag');
    $add(sprintf('%04d-10-03', $y),  'Tag der Deutschen Einheit');
    $add(sprintf('%04d-12-25', $y),  '1. Weihnachtstag');
    $add(sprintf('%04d-12-26', $y),  '2. Weihnachtstag');

    // ── Heilige Drei Könige (06.01.) ─────────────────────────────────
    if (in_array($bl, ['BW', 'BY', 'ST'])) {
        $add(sprintf('%04d-01-06', $y), 'Heilige Drei Könige');
    }

    // ── Internationaler Frauentag (08.03.) ───────────────────────────
    if (in_array($bl, ['BE', 'MV'])) {
        $add(sprintf('%04d-03-08', $y), 'Internationaler Frauentag');
    }

    // ── Ostersonntag ─────────────────────────────────────────────────
    if (in_array($bl, ['BB'])) {
        $add($easter->format('Y-m-d'), 'Ostersonntag');
    }

    // ── Fronleichnam (Ostern + 60) ───────────────────────────────────
    if (in_array($bl, ['BW', 'BY', 'HE', 'NW', 'RP', 'SL', 'SN', 'TH'])) {
        $add(easterOffset($easter, 60), 'Fronleichnam');
    }

    // ── Mariä Himmelfahrt (15.08.) ───────────────────────────────────
    if (in_array($bl, ['BY', 'SL'])) {
        $add(sprintf('%04d-08-15', $y), 'Mariä Himmelfahrt');
    }

    // ── Weltkindertag (20.09.) ───────────────────────────────────────
    if ($bl === 'TH') {
        $add(sprintf('%04d-09-20', $y), 'Weltkindertag');
    }

    // ── Pfingstsonntag ───────────────────────────────────────────────
    if (in_array($bl, ['BB'])) {
        $add(easterOffset($easter, 49), 'Pfingstsonntag');
    }

    // ── Reformationstag (31.10.) ─────────────────────────────────────
    if (in_array($bl, ['BB', 'HB', 'HH', 'MV', 'NI', 'SN', 'ST', 'SH', 'TH'])) {
        $add(sprintf('%04d-10-31', $y), 'Reformationstag');
    }

    // ── Allerheiligen (01.11.) ───────────────────────────────────────
    if (in_array($bl, ['BW', 'BY', 'NW', 'RP', 'SL'])) {
        $add(sprintf('%04d-11-01', $y), 'Allerheiligen');
    }

    // ── Buß- und Bettag (Mittwoch vor 23.11.) ───────────────────────
    if ($bl === 'SN') {
        $nov23 = new DateTimeImmutable(sprintf('%04d-11-23', $y));
        $dow   = (int)$nov23->format('N'); // 1=Mo … 7=So
        $daysBack = ($dow >= 3) ? $dow - 3 : $dow + 4;
        $add($nov23->modify("-{$daysBack} days")->format('Y-m-d'), 'Buß- und Bettag');
    }

    // ── Betriebsfreie Tage ──────────────────────────────────────────────
    $settings = getSettings();
    $raw = $settings['company_free_days'] ?? '';
    foreach (array_filter(array_map('trim', explode(',', $raw))) as $mmdd) {
        if (preg_match('/^\d{2}-\d{2}$/', $mmdd)) {
            $days[sprintf('%04d-%s', $y, $mmdd)] = 'Betriebsfrei';
        }
    }

    ksort($days);
    return $days;
}

/**
 * Liefert den Feiertagsnamen für ein einzelnes Datum (oder null).
 */
function getHolidayName(string $date, string $bundesland): ?string {
    $year     = (int)date('Y', strtotime($date));
    $holidays = getHolidays($year, $bundesland);
    return $holidays[$date] ?? null;
}
