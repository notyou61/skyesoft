<?php
declare(strict_types=1);

// ======================================================================
//  Skyesoft — getDynamicData.php
//  Version: 1.0.0
//  Last Updated: 2025-11-29
//  Codex Tier: 4 — Backend Module
//  Provides: TIS + Weather + KPI + Permits + Announcements + Site Meta
//  NO Output • NO Loop • NO Exit — Consumed by sse.php only
// ======================================================================

#region SECTION 0 — Dependencies
require_once __DIR__ . '/holidayInterpreter.php';
#endregion

#region SECTION 1 — Registry Loading
$root = dirname(__DIR__);

$paths = [
    "codex"          => $root . "/codex/codex.json",
    "versions"       => $root . "/data/authoritative/versions.json",
    "holiday"        => $root . "/data/authoritative/holidayRegistry.json",
    "systemRegistry" => $root . "/data/authoritative/systemRegistry.json",
    "kpi"            => $root . "/data/runtimeEphemeral/kpi.json",
    "permits"        => $root . "/data/runtimeEphemeral/permitRegistry.json",
    "announcements"  => $root . "/data/runtimeEphemeral/announcements.json",
];

foreach ($paths as $key => $path) {
    if (!file_exists($path)) {
        throw new RuntimeException("Missing {$key} at {$path}");
    }
}

$codex          = json_decode(file_get_contents($paths["codex"]), true);
$versions       = json_decode(file_get_contents($paths["versions"]), true);
$systemRegistry = json_decode(file_get_contents($paths["systemRegistry"]), true);

$kpi            = json_decode(file_get_contents($paths["kpi"]), true);
$activePermits  = json_decode(file_get_contents($paths["permits"]), true);
$announcements  = json_decode(file_get_contents($paths["announcements"]), true);

$tz = new DateTimeZone("America/Phoenix");
#endregion

#region SECTION 2 — Weather Configuration
$envPathPrimary = dirname($root) . "/secure/.env";
$envPathLocal   = dirname($root) . "/secure/env.local";

if (file_exists($envPathPrimary)) {
    $env = parse_ini_file($envPathPrimary);
} elseif (file_exists($envPathLocal)) {
    $env = parse_ini_file($envPathLocal);
} else {
    throw new RuntimeException("Missing WEATHER env file");
}

$weatherKey = $env["WEATHER_API_KEY"] ?? null;
if (!$weatherKey) {
    throw new RuntimeException("Missing WEATHER_API_KEY");
}

$lat    = (float)$systemRegistry["weather"]["latitude"];
$lon    = (float)$systemRegistry["weather"]["longitude"];
$baseOW = $systemRegistry["api"]["openWeatherBase"];

$currentWeather  = null;
$lastWeatherUnix = 0;
#endregion

#region SECTION 3 — Public Helper Functions
if (!function_exists('buildTimeContext')) {
    function buildTimeContext(DateTime $dt, array $systemRegistry, string $holidayPath): array
    {
        $nowUnix = (int)$dt->format("U");
        $weekday = (int)$dt->format("N");

        // --- Determine Calendar Type (Holiday > Weekend > Workday) ---
        $holidayState = resolveHolidayState($holidayPath, $dt);
        $isHoliday = $holidayState["isHoliday"];

        if ($isHoliday) {
            $calendarType = "holiday";
        } elseif ($weekday >= 6) {
            $calendarType = "weekend";
        } else {
            $calendarType = "workday";
        }

        // --- Office Hours (HH:MM from registry) ---
        list($startH, $startM) = array_map('intval', explode(":", $systemRegistry["schedule"]["officeHours"]["start"]));
        list($endH, $endM)     = array_map('intval', explode(":", $systemRegistry["schedule"]["officeHours"]["end"]));

        $workStartSecs = $startH * 3600 + $startM * 60;
        $workEndSecs   = $endH   * 3600 + $endM   * 60;
        $nowSecs       = (int)$dt->format("G") * 3600 + (int)$dt->format("i") * 60 + (int)$dt->format("s");

        // --- Compute Next Valid Work Start (skip weekends/holidays) ---
        $next = clone $dt;
        if ($nowSecs >= $workEndSecs) {
            $next->modify("+1 day");
        }
        $next->setTime($startH, $startM, 0);

        while (true) {
            $w = (int)$next->format("N");
            $h = resolveHolidayState($holidayPath, $next)["isHoliday"];
            if ($h || $w >= 6) {
                $next->modify("+1 day");
                $next->setTime($startH, $startM, 0);
                continue;
            }
            break;
        }

        $nextUnix = (int)$next->format("U");
        $secondsToNextWork = max(0, $nextUnix - $nowUnix);

        // =============================================================
        // Determine Interval Key & End Time
        // =============================================================
        if ($calendarType === "workday" && $nowSecs >= $workStartSecs && $nowSecs < $workEndSecs) {
            $intervalKey = "worktime";
            $intervalStartUnix = (clone $dt)->setTime($startH, $startM, 0)->format("U");
            $intervalEndUnix   = (clone $dt)->setTime($endH, $endM, 0)->format("U");
            $secondsRemaining   = max(0, $intervalEndUnix - $nowUnix);
        } elseif ($nowSecs < $workStartSecs && $calendarType === "workday") {
            $intervalKey = "beforeWork";
            $intervalEndUnix   = (clone $dt)->setTime($startH, $startM, 0)->format("U");
            $intervalStartUnix = $nowUnix;
            $secondsRemaining = max(0, $intervalEndUnix - $nowUnix);
        } elseif ($calendarType === "workday") {
            $intervalKey = "afterWork";
            $intervalStartUnix = $nowUnix;
            $intervalEndUnix   = $nextUnix;
            $secondsRemaining  = $secondsToNextWork;
        } else {
            // Weekend or Holiday
            $intervalKey = $calendarType; // "weekend" or "holiday"
            $intervalStartUnix = $nowUnix;
            $intervalEndUnix   = $nextUnix;
            $secondsRemaining = $secondsToNextWork;
        }

        return [
            "calendarType" => $calendarType,
            "currentInterval" => [
                "key" => $intervalKey,
                "intervalStartUnix" => $intervalStartUnix,
                "intervalEndUnix" => $intervalEndUnix,
                "secondsIntoInterval" => max(0, $nowUnix - $intervalStartUnix),
                "secondsRemainingInterval" => $secondsRemaining,
                "source" => "TIS"
            ],
            "timeDateArray" => [
                "currentUnixTime" => $nowUnix,
                "currentLocalTime" => $dt->format("h:i:s A"),
                "currentLocalTimeShort" => $dt->format("g:i A"),
                "currentDate" => $dt->format("Y-m-d"),
                "currentMonthNumber" => (int)$dt->format("n"),
                "currentWeekdayNumber" => $weekday,
                "currentDayNumber" => (int)$dt->format("j")
            ],
            "holidayState" => $holidayState
        ];
    }
}

if (!function_exists('getTimeContext')) {
    function getTimeContext(DateTimeZone $tz, array $systemRegistry, string $holidayPath): array
    {
        $dt = new DateTime('@' . time());
        $dt->setTimezone($tz);
        return buildTimeContext($dt, $systemRegistry, $holidayPath);
    }
}

if (!function_exists('getWeatherCached')) {
    function getWeatherCached(int $now, string $baseOW, float $lat, float $lon, string $key): array
    {
        global $currentWeather, $lastWeatherUnix;

        if ($currentWeather !== null && ($now - $lastWeatherUnix) < 600) {
            return $currentWeather;
        }

        $json = json_decode(@file_get_contents(
            "{$baseOW}/weather?lat={$lat}&lon={$lon}&units=imperial&appid={$key}"
        ), true);

        $currentWeather = [
            "temp"      => $json["main"]["temp"] ?? null,
            "condition" => $json["weather"][0]["description"] ?? null,
            "icon"      => $json["weather"][0]["icon"] ?? null,
            "sunrise"   => isset($json["sys"]["sunrise"]) ? date("g:i A", $json["sys"]["sunrise"]) : null,
            "sunset"    => isset($json["sys"]["sunset"]) ? date("g:i A", $json["sys"]["sunset"]) : null,
            "source"    => "openweathermap"
        ];

        $lastWeatherUnix = $now;
        return $currentWeather;
    }
}
#endregion

#region SECTION 4 — Build Time Context + Weather + Final Payload

// Compute time context
$timeContext = getTimeContext($tz, $systemRegistry, $paths["holiday"]);

// Weather snapshot
$now = time();
$weather = getWeatherCached(
    $now,
    $systemRegistry["api"]["openWeatherBase"],
    $systemRegistry["weather"]["latitude"],
    $systemRegistry["weather"]["longitude"],
    $weatherKey
);

// ------------------------------------------------------------
// FINAL PAYLOAD — Always flat + always normalized
// ------------------------------------------------------------
$payload = [
    "systemRegistry"  => $systemRegistry,
    "calendarType"    => $timeContext["calendarType"],
    "currentInterval" => $timeContext["currentInterval"],
    "timeDateArray"   => $timeContext["timeDateArray"],
    "holidayState"    => $timeContext["holidayState"],
    "weather"         => $weather,

    // KPI — already normalized
    "kpi"             => $kpi,

    // ACTIVE PERMITS — force flatten
    "activePermits"   => $activePermits["activePermits"] ?? [],

    // ANNOUNCEMENTS — force flatten
    "announcements"   => $announcements["announcements"] ?? [],

    "siteMeta"        => [
        "siteVersion" => $versions["siteVersion"] ?? "unknown"
    ]
];

#endregion

#region SECTION 5 — Output for SSE (Flush every update)
//echo "data: " . json_encode($payload) . "\n\n";
//@ob_flush();
//flush();
return $payload;
#endregion

#region SECTION END — No Output / No Headers / No Exit
#endregion