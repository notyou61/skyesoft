<?php
// ======================================================================
//  Skyesoft — getDynamicData.php
//  SSE Provider • PHP 8 • Codex 1.1.0 Compliant
// ======================================================================

#region ARTICLE IX — System Integrity: Safe Error Handling
// ----------------------------------------------------------------------
declare(strict_types=1);
header("Content-Type: application/json; charset=UTF-8");

function safeError(string $msg) {
    echo json_encode([
        "error" => "❌ $msg"
    ], JSON_UNESCAPED_SLASHES);
    exit;
}
#endregion

#region LOAD CODEX (Tier 0–3)
// ----------------------------------------------------------------------
$codexPath = __DIR__ . "/../codex/codex.json";

if (!file_exists($codexPath)) {
    safeError("Codex file missing at codex/codex.json");
}

$codex = json_decode(file_get_contents($codexPath), true);
if (!is_array($codex)) {
    safeError("Codex file unreadable or invalid JSON.");
}
#endregion

#region LOAD HOLIDAY REGISTRY
// ----------------------------------------------------------------------
$holidayPath = __DIR__ . "/../assets/data/holidayRegistry.json";

if (!file_exists($holidayPath)) {
    safeError("Holiday registry missing at assets/data/holidayRegistry.json");
}

$holidayData = json_decode(file_get_contents($holidayPath), true);
if (!is_array($holidayData) || !isset($holidayData["holidayRegistry"]["holidays"])) {
    safeError("Holiday registry structure invalid.");
}

$holidays = $holidayData["holidayRegistry"]["holidays"];
#endregion

#region SSE TIMESTAMP BLOCK
// ----------------------------------------------------------------------
$now = new DateTime("now", new DateTimeZone("America/Phoenix"));

$timeDateArray = [
    "iso"      => $now->format(DateTime::ATOM),
    "date"     => $now->format("Y-m-d"),
    "time"     => $now->format("H:i:s"),
    "weekday"  => $now->format("l"),
    "month"    => $now->format("F"),
    "year"     => $now->format("Y")
];
#endregion

#region HOLIDAY RESOLVER
// ----------------------------------------------------------------------
function isHoliday(DateTime $date, array $holidays): ?array
{
    $month   = strtolower($date->format("M"));
    $day     = intval($date->format("d"));

    foreach ($holidays as $h) {

        // --- Fixed dates (jan-01, dec-25, etc.) ---
        if (preg_match('/^([a-z]{3})-(\d{2})$/', $h["dateRule"], $m)) {
            if ($month === strtolower($m[1]) && $day === intval($m[2])) {
                return $h;
            }
        }

        // --- Nth weekday (third-monday-january, etc.) ---
        if (str_contains($h["dateRule"], "-monday") ||
            str_contains($h["dateRule"], "-thursday") ||
            str_contains($h["dateRule"], "-friday")) {

            [$ordinal, $weekdayRule, $monthRule] = explode("-", $h["dateRule"]);

            if (strtolower($monthRule) !== strtolower($date->format("F"))) {
                continue;
            }

            $firstOfMonth  = new DateTime($date->format("Y") . "-" . date("m", strtotime($monthRule)) . "-01");
            $targetCount   = [
                "first"  => 1,
                "second" => 2,
                "third"  => 3,
                "fourth" => 4,
                "last"   => 5
            ][$ordinal] ?? null;

            if (!$targetCount) continue;

            $cursor = clone $firstOfMonth;
            $match  = 0;

            while ($cursor->format("n") === $firstOfMonth->format("n")) {
                if (strtolower($cursor->format("l")) === $weekdayRule) {
                    $match++;
                    if ($match === $targetCount || $ordinal === "last") {
                        if ($cursor->format("Y-m-d") === $date->format("Y-m-d")) {
                            return $h;
                        }
                    }
                }
                $cursor->modify("+1 day");
            }
        }

        // --- Easter Sunday (computus) ---
        if ($h["dateRule"] === "computus") {
            $y = intval($date->format("Y"));
            $e = easter_date($y);
            $eDT = (new DateTime())->setTimestamp($e);
            if ($eDT->format("Y-m-d") === $date->format("Y-m-d")) return $h;
        }

        // --- Good Friday (two-days-before-easter) ---
        if ($h["dateRule"] === "two-days-before-easter") {
            $y = intval($date->format("Y"));
            $e = easter_date($y);
            $gfDT = (new DateTime())->setTimestamp($e)->modify("-2 days");
            if ($gfDT->format("Y-m-d") === $date->format("Y-m-d")) return $h;
        }
    }

    return null;
}

$holidayMatch = isHoliday($now, $holidays);
#endregion

#region TIME INTERVAL STANDARD (TIS)
// ----------------------------------------------------------------------
function resolveInterval(DateTime $dt, ?array $holiday): string
{
    $dow  = strtolower($dt->format("l"));
    $hour = intval($dt->format("H"));

    if ($dow === "saturday" || $dow === "sunday") return "weekend";
    if ($holiday !== null) return "holiday";
    if ($hour >= 8 && $hour < 17) return "worktime";
    if ($hour < 8) return "beforeWork";
    return "afterWork";
}

$currentInterval = resolveInterval($now, $holidayMatch);
#endregion

#region WEATHER PROVIDER BLOCK (placeholder)
// ----------------------------------------------------------------------
$weather = [
    "tempF"     => null,
    "condition" => null,
    "source"    => "pending-provider"
];
// #endregion



// #region SITE META BLOCK (deployment, versions)
// ----------------------------------------------------------------------
$siteMeta = [
    "deployment" => "local",
    "notes"      => "Integrate getVersions.php next"
];
#endregion

#region SSE OUTPUT ASSEMBLY
// ----------------------------------------------------------------------
echo json_encode([
    "timestamp"        => $now->getTimestamp(),
    "timeDateArray"    => $timeDateArray,
    "currentInterval"  => $currentInterval,
    "holiday"          => $holidayMatch ?: null,
    "weather"          => $weather,
    "siteMeta"         => $siteMeta,
    "pulse"            => "ok",
    "connectionStatus" => "active"
], JSON_UNESCAPED_SLASHES);

exit;
#endregion