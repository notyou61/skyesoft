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

#region SECTION 2 — Weather Configuration (CURRENT + 3-DAY FORECAST)

// ─────────────────────────────────────────────
// Load WEATHER_API_KEY from secure env
// ─────────────────────────────────────────────
$envPathPrimary = dirname(dirname($root)) . "/secure/.env";
$envPathLocal   = dirname(dirname($root)) . "/secure/env.local";
$envFile = file_exists($envPathPrimary) ? $envPathPrimary : (file_exists($envPathLocal) ? $envPathLocal : null);
if ($envFile === null) {
    throw new RuntimeException("Missing WEATHER env file");
}
// Robust .env parser
$env = [];
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || $line[0] === ';') continue;
    if (strpos($line, '=') === false) continue;
    [$k, $v] = explode('=', $line, 2);
    $env[trim($k)] = trim($v, "\"'");
}
$weatherKey = $env['WEATHER_API_KEY'] ?? null;
if (!$weatherKey) {
    throw new RuntimeException("Missing WEATHER_API_KEY");
}

// ─────────────────────────────────────────────
// Coordinates + API base
// ─────────────────────────────────────────────
$lat = (float) ($systemRegistry['weather']['latitude']  ?? 33.4484);
$lon = (float) ($systemRegistry['weather']['longitude'] ?? -112.0740);
$baseOW = rtrim($systemRegistry['api']['openWeatherBase'] ?? 'https://api.openweathermap.org/data/2.5', '/');

// ─────────────────────────────────────────────
// Weather freshness governance (15 min rule)
// ─────────────────────────────────────────────
$versionsPath = $root . '/data/authoritative/versions.json';
$versions = file_exists($versionsPath)
    ? json_decode(file_get_contents($versionsPath), true) ?: ['modules' => []]
    : ['modules' => []];

$lastWeatherUpdate = (int) ($versions['modules']['weather']['lastUpdatedUnix'] ?? 0);
$now = time();
$refreshInterval = 15 * 60;
$shouldRefreshWeather = ($now - $lastWeatherUpdate) >= $refreshInterval;
$weatherValid = false;

// ─────────────────────────────────────────────
// Cache path
// ─────────────────────────────────────────────
$cachePath = $root . '/data/runtimeEphemeral/weatherCache.json';

// ─────────────────────────────────────────────
// Try to load cached data if not refreshing
// ─────────────────────────────────────────────
$currentWeather = [
    'temp'            => null,
    'condition'       => null,
    'icon'            => null,
    'sunrise'         => null,
    'sunset'          => null,
    'sunriseUnix'     => null,
    'sunsetUnix'      => null,
    'daylightSeconds' => null,
    'nightSeconds'    => null,
    'source'          => 'openweathermap-unavailable'
];
$forecastDays = [];
// Load from cache if valid
if (!$shouldRefreshWeather && file_exists($cachePath)) {
    $cached = json_decode(file_get_contents($cachePath), true);

    if (
        is_array($cached)
        && isset($cached['current']['temp'])
        && isset($cached['current']['sunriseUnix'])
    ) {
        $currentWeather = $cached['current'];
        $forecastDays   = $cached['forecast'] ?? [];
        $currentWeather['source'] = 'cache';
        $weatherValid = true;
    }
}

// ─────────────────────────────────────────────
// Fetch weather only when needed
// ─────────────────────────────────────────────
if ($shouldRefreshWeather) {
    $currentUrl = "{$baseOW}/weather?lat={$lat}&lon={$lon}&units=imperial&appid={$weatherKey}";
    $forecastUrl = "{$baseOW}/forecast?lat={$lat}&lon={$lon}&units=imperial&appid={$weatherKey}";

    $ctx = stream_context_create(['http' => ['timeout' => $systemRegistry['api']['timeoutSeconds'] ?? 6]]);
    $currentRaw = @file_get_contents($currentUrl, false, $ctx);
    $forecastRaw = @file_get_contents($forecastUrl, false, $ctx);

    $currentData = $currentRaw ? json_decode($currentRaw, true) : null;
    $forecastData = $forecastRaw ? json_decode($forecastRaw, true) : null;

    // ─────────────────────────────────────────────
    // Parse current weather
    // ─────────────────────────────────────────────
    if ($currentData && isset($currentData['main'])) {
        $sunriseUnix = $currentData['sys']['sunrise'] ?? null;
        $sunsetUnix  = $currentData['sys']['sunset']  ?? null;
        $daylightSeconds = ($sunriseUnix && $sunsetUnix) ? max(0, $sunsetUnix - $sunriseUnix) : null;
        $nightSeconds = ($daylightSeconds !== null) ? max(0, 86400 - $daylightSeconds) : null;

        $currentWeather = [
            'temp'            => round($currentData['main']['temp']),
            'condition'       => $currentData['weather'][0]['description'] ?? null,
            'icon'            => $currentData['weather'][0]['icon'] ?? null,
            'sunrise'         => $sunriseUnix ? date('g:i A', $sunriseUnix) : null,
            'sunset'          => $sunsetUnix  ? date('g:i A', $sunsetUnix)  : null,
            'sunriseUnix'     => $sunriseUnix,
            'sunsetUnix'      => $sunsetUnix,
            'daylightSeconds' => $daylightSeconds,
            'nightSeconds'    => $nightSeconds,
            'source'          => 'openweathermap'
        ];

        // ─────────────────────────────────────────────
        // Parse forecast
        // ─────────────────────────────────────────────
        if ($forecastData && isset($forecastData['list'])) {
            $daily = [];
            foreach ($forecastData['list'] as $slot) {
                $dt = (int)$slot['dt'];
                $dateKey = date('Y-m-d', $dt);
                $hour = (int)date('G', $dt);

                if (!isset($daily[$dateKey])) {
                    $daily[$dateKey] = [
                        'dateUnix' => strtotime($dateKey),
                        'high' => null, 'low' => null,
                        'icon' => null, 'condition' => null,
                        'iconScore' => -999
                    ];
                }

                $tMax = $slot['main']['temp_max'] ?? null;
                $tMin = $slot['main']['temp_min'] ?? null;
                if ($tMax !== null) $daily[$dateKey]['high'] = $daily[$dateKey]['high'] === null ? $tMax : max($daily[$dateKey]['high'], $tMax);
                if ($tMin !== null) $daily[$dateKey]['low']  = $daily[$dateKey]['low']  === null ? $tMin : min($daily[$dateKey]['low'], $tMin);

                $score = -abs($hour - 12);
                if ($score > $daily[$dateKey]['iconScore']) {
                    $daily[$dateKey]['icon'] = $slot['weather'][0]['icon'] ?? null;
                    $daily[$dateKey]['condition'] = $slot['weather'][0]['description'] ?? null;
                    $daily[$dateKey]['iconScore'] = $score;
                }
            }
            ksort($daily);
            $labels = ['Today', 'Tomorrow'];
            $i = 0;
            foreach ($daily as $dayKey => $d) {
                if ($i >= 3) break;
                $forecastDays[] = [
                    'dateUnix'  => $d['dateUnix'],
                    'label'     => $labels[$i] ?? date('l', $d['dateUnix']),
                    'high'      => $d['high'] !== null ? round($d['high']) : null,
                    'low'       => $d['low']  !== null ? round($d['low'])  : null,
                    'condition' => $d['condition'],
                    'icon'      => $d['icon']
                ];
                $i++;
            }
        }

        // ─────────────────────────────────────────────
        // SUCCESS → update timestamp & save cache
        // ─────────────────────────────────────────────
        $versions['modules']['weather'] = [
            'lastUpdatedUnix' => $now,
            'source' => 'openweathermap'
        ];

        file_put_contents(
            $versionsPath,
            json_encode($versions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );

        file_put_contents(
            $cachePath,
            json_encode([
                'current'  => $currentWeather,
                'forecast' => $forecastDays
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    } else {
        // Optional: log failure for debugging
        // error_log("Weather refresh failed - no valid current data");
    }
}

#endregion

#region SECTION 3 — Time Context (TIS)#region SECTION 3 — Time Context (TIS)

/**
 * Build full time context snapshot
 */
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

        // --- Office Hours ---
        [$startH, $startM] = array_map('intval',
            explode(":", $systemRegistry["schedule"]["officeHours"]["start"])
        );
        [$endH, $endM] = array_map('intval',
            explode(":", $systemRegistry["schedule"]["officeHours"]["end"])
        );

        $workStartSecs = $startH * 3600 + $startM * 60;
        $workEndSecs   = $endH   * 3600 + $endM   * 60;
        $nowSecs       =
            ((int)$dt->format("G") * 3600) +
            ((int)$dt->format("i") * 60) +
            ((int)$dt->format("s"));

        // --- Compute Next Valid Work Start ---
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

        // --- Determine Interval ---
        if ($calendarType === "workday" && $nowSecs >= $workStartSecs && $nowSecs < $workEndSecs) {
            $intervalKey = "worktime";
            $intervalStartUnix = (clone $dt)->setTime($startH, $startM, 0)->format("U");
            $intervalEndUnix   = (clone $dt)->setTime($endH, $endM, 0)->format("U");
            $secondsRemaining  = max(0, $intervalEndUnix - $nowUnix);
        } elseif ($calendarType === "workday" && $nowSecs < $workStartSecs) {
            $intervalKey = "beforeWork";
            $intervalStartUnix = $nowUnix;
            $intervalEndUnix   = (clone $dt)->setTime($startH, $startM, 0)->format("U");
            $secondsRemaining  = max(0, $intervalEndUnix - $nowUnix);
        } elseif ($calendarType === "workday") {
            $intervalKey = "afterWork";
            $intervalStartUnix = $nowUnix;
            $intervalEndUnix   = $nextUnix;
            $secondsRemaining  = $secondsToNextWork;
        } else {
            // Weekend or Holiday
            $intervalKey = $calendarType;
            $intervalStartUnix = $nowUnix;
            $intervalEndUnix   = $nextUnix;
            $secondsRemaining  = $secondsToNextWork;
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

/**
 * Public accessor
 */
if (!function_exists('getTimeContext')) {
    function getTimeContext(DateTimeZone $tz, array $systemRegistry, string $holidayPath): array
    {
        $dt = new DateTime('@' . time());
        $dt->setTimezone($tz);
        return buildTimeContext($dt, $systemRegistry, $holidayPath);
    }
}

// Note: The final weather safety check has been MOVED to SECTION 4
// right before assembling $weather — do not keep it here.

#endregion

#region SECTION 4 — Build Time Context + Weather + Final Payload

// Compute time context
$timeContext = getTimeContext($tz, $systemRegistry, $paths["holiday"]);

// ─────────────────────────────────────────────
// FINAL WEATHER SAFETY BARRIER
// Ensures weather never emits as 'openweathermap-unavailable' if any
// valid cache exists. Runs immediately before payload assembly.
// ─────────────────────────────────────────────
if (!$weatherValid && file_exists($cachePath)) {
    $cached = json_decode(file_get_contents($cachePath), true);
    if (is_array($cached) && isset($cached['current'])) {
        $currentWeather = $cached['current'];
        $forecastDays   = $cached['forecast'] ?? [];
        $currentWeather['source'] = 'cache-fallback';
        $weatherValid = true;
    }
}

// Now safe to assemble final weather structure
$weather = $currentWeather;
$weather['forecast'] = $forecastDays;

// ------------------------------------------------------------
// Normalize active permits into flat array for frontend
// Source: permitRegistry.json → workOrders (map)
// ------------------------------------------------------------
$permitList = [];

if (isset($activePermits["workOrders"]) && is_array($activePermits["workOrders"])) {
    foreach ($activePermits["workOrders"] as $wo) {
        // Optional filter: exclude finaled / issued if desired later
        $permitList[] = [
            "wo"           => $wo["workOrder"] ?? "",
            "customer"     => $wo["customer"] ?? "",
            "jobsite"      => $wo["jobsite"] ?? "",
            "jurisdiction" => $wo["jurisdiction"] ?? "",
            "status"       => $wo["permit"]["status"] ?? ""
        ];
    }
}

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

    "kpi"             => $kpi,

    "activePermits"   => $permitList,

    "announcements"   => is_array($announcements)
        ? (isset($announcements["announcements"]) ? $announcements["announcements"] : $announcements)
        : [],

    "siteMeta" => [
        "siteVersion" => $versions["system"]["siteVersion"] ?? "unknown",
        "deployTime"  => $versions["system"]["deployTime"]  ?? null
    ]
];

#endregion

#region SECTION 5 — Output for SSE (Flush every update)
//header('Content-Type: application/json');
//echo json_encode($payload, JSON_PRETTY_PRINT);
//echo "data: " . json_encode($payload) . "\n\n";
//@ob_flush();
//flush();
return $payload;
#endregion