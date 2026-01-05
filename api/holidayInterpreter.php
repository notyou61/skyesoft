<?php
// ======================================================================
//  Skyesoft — holidayInterpreter.php
//  Holiday interpretation module (Codex-governed)
//  PHP 8.3 • Implements: Structural Code Standard, Holiday Interpretation Standard
// ======================================================================

#region SECTION I — Module-Local Error Handler (Non-Global)
// ----------------------------------------------------------------------
/**
 * Internal module-safe error handler
 * Does NOT collide with API fail() function.
 */
function holidayFail(string $msg): never
{
    echo json_encode([
        "success" => false,
        "error"   => "❌ HolidayInterpreter: $msg"
    ]);
    exit;
}
#endregion

#region SECTION II — Load Registry

/**
 * Loads holidayRegistry.json from assets/data.
 */
function loadHolidayRegistry(string $path): array
{
    if (!file_exists($path)) {
        fail("holidayRegistry.json not found at $path");
    }

    $json = file_get_contents($path);
    if (!$json) {
        fail("Unable to read holidayRegistry.json");
    }

    $data = json_decode($json, true);
    if (!is_array($data) || !isset($data['holidays'])) {
        fail("holidayRegistry.json malformed or missing 'holidays' key");
    }

    return $data['holidays'];
}

#endregion

#region SECTION III — Helper Functions (Date Parsers)

/**
 * Compute Easter Sunday (Western Gregorian) using Butcher’s algorithm
 */
function computeEaster(int $year): DateTime
{
    // Meeus/Jones/Butcher Gregorian computus
    $a = $year % 19;
    $b = intdiv($year, 100);
    $c = $year % 100;
    $d = intdiv($b, 4);
    $e = $b % 4;
    $f = intdiv($b + 8, 25);
    $g = intdiv($b - $f + 1, 3);
    $h = (19*$a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4);
    $k = $c % 4;
    $l = (32 + 2*$e + 2*$i - $h - $k) % 7;
    $m = intdiv($a + 11*$h + 22*$l, 451);
    $month = intdiv($h + $l - 7*$m + 114, 31);
    $day = (($h + $l - 7*$m + 114) % 31) + 1;

    return new DateTime("$year-$month-$day");
}

/**
 * Resolve fixed-date format like "jan-01"
 */
function resolveFixedDate(string $rule, int $year): ?DateTime
{
    if (!preg_match('/^([a-z]{3})-(\d{2})$/', $rule, $m)) {
        return null;
    }

    $monthMap = [
        "jan"=>1,"feb"=>2,"mar"=>3,"apr"=>4,"may"=>5,"jun"=>6,
        "jul"=>7,"aug"=>8,"sep"=>9,"oct"=>10,"nov"=>11,"dec"=>12
    ];

    $monthAbbr = strtolower($m[1]);
    $day       = intval($m[2]);

    if (!isset($monthMap[$monthAbbr])) {
        return null;
    }

    return new DateTime(sprintf("%04d-%02d-%02d", $year, $monthMap[$monthAbbr], $day));
}

/**
 * Resolve weekday-instance format like:
 * "third-monday-january"
 * "last-monday-may"
 * "fourth-thursday-november"
 */
function resolveWeekdayInstance(string $rule, int $year): ?DateTime
{
    if (!preg_match('/^(first|second|third|fourth|last)\-(monday|tuesday|wednesday|thursday|friday|saturday|sunday)\-([a-z]+)$/', $rule, $m)) {
        return null;
    }

    [$all, $ordinal, $weekdayStr, $monthStr] = $m;

    $ordMap = ["first"=>1,"second"=>2,"third"=>3,"fourth"=>4];

    $weekdayMap = [
        "monday"=>1, "tuesday"=>2, "wednesday"=>3,
        "thursday"=>4, "friday"=>5, "saturday"=>6, "sunday"=>7
    ];

    $monthMap = [
        "january"=>1,"february"=>2,"march"=>3,"april"=>4,"may"=>5,"june"=>6,
        "july"=>7,"august"=>8,"september"=>9,"october"=>10,"november"=>11,"december"=>12
    ];

    $weekday = $weekdayMap[strtolower($weekdayStr)] ?? null;
    $month   = $monthMap[strtolower($monthStr)] ?? null;
    if (!$weekday || !$month) return null;

    if ($ordinal === "last") {
        // Find last weekday of month
        $dt = new DateTime("last $weekdayStr of $year-$month");
        return $dt;
    }

    $nth = $ordMap[$ordinal] ?? null;
    if (!$nth) return null;

    // Compute nth weekday of month
    $dt = new DateTime("first day of $year-$month");
    $count = 0;

    while (true) {
        if ((int)$dt->format('N') === $weekday) {
            $count++;
            if ($count === $nth) {
                return $dt;
            }
        }
        $dt->modify("+1 day");
        if ((int)$dt->format('m') !== $month) break;
    }

    return null;
}

/**
 * Resolve anchor-relative rule:
 * e.g., "friday-after-fourth-thursday-november"
 */
function resolveAnchorRelative(string $rule, int $year): ?DateTime
{
    if (!preg_match('/^(monday|tuesday|wednesday|thursday|friday|saturday|sunday)\-after\-(.+)$/', $rule, $m)) {
        return null;
    }

    $targetWeekday = strtolower($m[1]);
    $anchorRule    = strtolower($m[2]);

    // First resolve the anchor
    $anchor = resolveWeekdayInstance($anchorRule, $year);
    if (!$anchor) return null;

    // Now walk forward until we hit the target weekday
    $weekdayMap = [
        "monday"=>1,"tuesday"=>2,"wednesday"=>3,
        "thursday"=>4,"friday"=>5,"saturday"=>6,"sunday"=>7
    ];

    $targetN = $weekdayMap[$targetWeekday];

    $dt = clone $anchor;
    while ((int)$dt->format('N') !== $targetN) {
        $dt->modify("+1 day");
    }

    return $dt;
}

/**
 * Resolve offset-from-Easter rule:
 * e.g., "two-days-before-easter"
 */
function resolveOffsetFromEaster(string $rule, int $year): ?DateTime
{
    if (!preg_match('/^(\w+)\-days\-(before|after)\-easter$/', $rule, $m)) {
        return null;
    }

    $num   = strtolower($m[1]);
    $dir   = strtolower($m[2]);

    // textual numbers → integers
    $numMap = [
        "one"=>1, "two"=>2, "three"=>3, "four"=>4, "five"=>5,
        "six"=>6, "seven"=>7, "eight"=>8, "nine"=>9, "ten"=>10
    ];
    $days = $numMap[$num] ?? null;
    if (!$days) return null;

    $easter = computeEaster($year);

    $dt = clone $easter;
    if ($dir === "before") {
        $dt->modify("-{$days} days");
    } else {
        $dt->modify("+{$days} days");
    }

    return $dt;
}

#endregion

#region SECTION IV — Core Holiday Interpretation

/**
 * Resolves a single holiday to:
 *   - resolvedDate (ISO)
 *   - observedDate (ISO)
 *   - rule
 *   - ruleType
 */
function interpretHoliday(array $h, int $year): ?array
{
    if (!isset($h['dateRule'])) return null;

    $rule = strtolower($h['dateRule']);
    $date = null;
    $ruleType = null;

    // 1. fixed-date rule?
    if (!$date) {
        $date = resolveFixedDate($rule, $year);
        if ($date) $ruleType = "fixed-date";
    }

    // 2. weekday-instance?
    if (!$date) {
        $date = resolveWeekdayInstance($rule, $year);
        if ($date) $ruleType = "weekday-instance";
    }

    // 3. anchor-relative?
    if (!$date) {
        $date = resolveAnchorRelative($rule, $year);
        if ($date) $ruleType = "anchor-relative";
    }

    // 4. computus?
    if (!$date && $rule === "computus") {
        $date = computeEaster($year);
        $ruleType = "computus";
    }

    // 5. offset-from-Easter?
    if (!$date) {
        $date = resolveOffsetFromEaster($rule, $year);
        if ($date) $ruleType = "offset-fixed";
    }

    if (!$date) {
        return [
            "error" => "Unsupported or malformed dateRule: {$h['dateRule']}"
        ];
    }

    // Observed-rule handling
    $observed = clone $date;
    if (isset($h['observedRule']) && $h['observedRule'] === "followsWeekend") {
        $dow = (int)$date->format("N");
        if ($dow === 6) { // Saturday → observed Friday
            $observed->modify("-1 day");
        } elseif ($dow === 7) { // Sunday → observed Monday
            $observed->modify("+1 day");
        }
    }

    return [
        "key"           => $h['key'],
        "name"          => $h['name'],
        "rule"          => $h['dateRule'],   // Output uses “rule”, input came from dateRule
        "ruleType"      => $ruleType,
        "date"          => $date->format("Y-m-d"),
        "observedDate"  => $observed->format("Y-m-d"),
        "type"          => $h['type'] ?? []
    ];
}

#endregion

#region SECTION V — Public Resolver API (for getDynamicData.php)

/**
 * Returns full holiday state for SSE:
 * - isHoliday
 * - holidayKey
 * - holidayName
 * - rule, ruleType
 * - nextHoliday
 */
function resolveHolidayState(string $registryPath, DateTime $now): array
{
    $year  = (int)$now->format("Y");
    $today = $now->format("Y-m-d");

    $holidays = loadHolidayRegistry($registryPath);
    $results  = [];

    foreach ($holidays as $h) {
        $resolved = interpretHoliday($h, $year);
        if (isset($resolved['error'])) continue;
        $results[] = $resolved;
    }

    // Determine today’s holiday, if any
    $current = null;
    foreach ($results as $r) {
        if ($r['observedDate'] === $today || $r['date'] === $today) {
            $current = $r;
            break;
        }
    }

    // Determine next holiday
    usort($results, fn($a, $b) => strcmp($a['observedDate'], $b['observedDate']));
    $next = null;
    foreach ($results as $r) {
        if ($r['observedDate'] > $today) {
            $next = [
                "key"        => $r['key'],
                "name"       => $r['name'],
                "date"       => $r['date'],
                "observedDate"=> $r['observedDate'],
                "rule"       => $r['rule'],
                "daysAway"   => (new DateTime($r['observedDate']))->diff($now)->days
            ];
            break;
        }
    }

    // Output format: Parliamentarian-approved
    if ($current) {
        return [
            "isHoliday"     => true,
            "holidayKey"    => $current['key'],
            "holidayName"   => $current['name'],
            "ruleType"      => $current['ruleType'],
            "rule"          => $current['rule'],
            "date"          => $current['date'],
            "observedDate"  => $current['observedDate'],
            "type"          => $current['type'],
            "nextHoliday"   => $next,
            "source"        => "holidayRegistry.json → holidayInterpreter"
        ];
    }

    // Non-holiday case
    return [
        "isHoliday"     => false,
        "holidayKey"    => null,
        "holidayName"   => null,
        "ruleType"      => null,
        "rule"          => null,
        "nextHoliday"   => $next,
        "source"        => "holidayRegistry.json → holidayInterpreter"
    ];
}

#endregion