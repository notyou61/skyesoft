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

#region SECTION 2 — Weather Configuration (CURRENT + 3-DAY FORECAST) — FORCED SEED MODE

error_log('[env] WEATHER_API_KEY raw getenv = ' . var_export(getenv('WEATHER_API_KEY'), true));
error_log('[env] $_ENV key exists = ' . (isset($_ENV['WEATHER_API_KEY']) ? 'yes' : 'no'));
error_log('[env] $_SERVER key exists = ' . (isset($_SERVER['WEATHER_API_KEY']) ? 'yes' : 'no'));


$cachePath = $root . '/data/runtimeEphemeral/weatherCache.json';
$versionsPath = $root . '/data/authoritative/versions.json';

// Load versions for timestamp
$versions = file_exists($versionsPath) ? json_decode(file_get_contents($versionsPath), true) : [];
$versions['modules']['weather']['lastUpdatedUnix'] ??= 0;
$lastWeatherUpdate = (int)$versions['modules']['weather']['lastUpdatedUnix'];
$now = time();

// FORCE refresh if cache missing OR older than 24 hours (safety net)
$cacheExists = file_exists($cachePath) && filesize($cachePath) > 0;

// Force refresh condition (not used directly, but for reference)
$forceRefresh = !$cacheExists || ($now - $lastWeatherUpdate > 86400);

// Allow refresh if cache invalid OR stale
$shouldRefreshWeather = !$cacheExists || ($now - $lastWeatherUpdate >= 900);// 15 min normal, force if missing/stale

// Default fallback
$currentWeather = [
    'temp' => null, 'condition' => null, 'icon' => null,
    'sunrise' => null, 'sunset' => null, 'sunriseUnix' => null, 'sunsetUnix' => null,
    'daylightSeconds' => null, 'nightSeconds' => null,
    'source' => 'openweathermap-unavailable'
];
$forecastDays = [];
$weatherValid = false;

// Always try cache first (validate contents, not just existence)
if (file_exists($cachePath)) {
    $cached = json_decode(file_get_contents($cachePath), true);

    $cacheIsValid =
        is_array($cached) &&
        isset($cached['current']['temp']) &&
        $cached['current']['temp'] !== null &&
        isset($cached['current']['sunriseUnix']) &&
        $cached['current']['sunriseUnix'] !== null;

    if ($cacheIsValid) {
        $currentWeather = $cached['current'];
        $forecastDays   = $cached['forecast'] ?? [];
        $currentWeather['source'] = 'cache';
        $weatherValid = true;
    } else {
        // Cache exists but is invalid or empty → force live fetch
        $weatherValid = false;
    }
}

// ────────────────────────────────────────────────
// Weather API key (single source of truth)
// ────────────────────────────────────────────────
$weatherKey = trim(getenv('WEATHER_API_KEY') ?: '');

if ($weatherKey === '') {
    error_log('[weather][DIAG] WEATHER_API_KEY is EMPTY');
    $shouldRefreshWeather = false; // hard stop — prevent bad calls
} else {
    error_log('[weather][DIAG] WEATHER_API_KEY present (len=' . strlen($weatherKey) . ')');
}

// LIVE FETCH — only if needed
if (($shouldRefreshWeather || !$weatherValid) && $weatherKey !== '') {
    $lat = 33.4484;
    $lon = -112.0740;
    $key = $weatherKey;

    $urlCurrent  = "https://api.openweathermap.org/data/2.5/weather?lat={$lat}&lon={$lon}&units=imperial&appid={$key}";
    $urlForecast = "https://api.openweathermap.org/data/2.5/forecast?lat={$lat}&lon={$lon}&units=imperial&appid={$key}";

    $ctx = stream_context_create([
        'http' => [
            'timeout' => 12,
            'ignore_errors' => true
        ]
    ]);

    $rawCurrent  = @file_get_contents($urlCurrent, false, $ctx);
    $rawForecast = @file_get_contents($urlForecast, false, $ctx);

    $success = false;

    if ($rawCurrent === false) {
        error_log("[weather] file_get_contents failed (current) — likely network/SSL/allow_url_fopen/DNS issue");
    } else {
        $data = json_decode($rawCurrent, true);

        if (
            is_array($data) &&
            isset($data['cod']) &&
            (string)$data['cod'] === '200' &&           // string comparison — safer
            isset($data['main']['temp'])
        ) {
            // ── SUCCESS PATH ──
            $sunrise = $data['sys']['sunrise'] ?? null;
            $sunset  = $data['sys']['sunset']  ?? null;

            $currentWeather = [
                'temp'            => round($data['main']['temp']),
                'condition'       => $data['weather'][0]['description'] ?? 'unknown',
                'icon'            => $data['weather'][0]['icon']       ?? '01d',
                'sunrise'         => $sunrise ? date('g:i A', $sunrise) : null,
                'sunset'          => $sunset  ? date('g:i A', $sunset)  : null,
                'sunriseUnix'     => $sunrise,
                'sunsetUnix'      => $sunset,
                'daylightSeconds' => ($sunrise && $sunset) ? ($sunset - $sunrise) : null,
                'nightSeconds'    => ($sunrise && $sunset) ? (86400 - ($sunset - $sunrise)) : null,
                'source'          => 'openweathermap'
            ];

            // Forecast — best effort
            $forecastDays = [];

            if ($rawForecast !== false) {
                $f = json_decode($rawForecast, true);

                if (
                    is_array($f) &&
                    isset($f['cod']) &&
                    (string)$f['cod'] === '200' &&
                    isset($f['list'])
                ) {
                    $daily = [];
                    foreach ($f['list'] as $slot) {
                        $date = date('Y-m-d', $slot['dt']);
                        if (!isset($daily[$date])) {
                            $daily[$date] = ['high' => -999, 'low' => 999, 'icon' => ''];
                        }
                        $daily[$date]['high'] = max($daily[$date]['high'], $slot['main']['temp_max'] ?? -999);
                        $daily[$date]['low']  = min($daily[$date]['low'],  $slot['main']['temp_min'] ?? 999);
                        if (date('G', $slot['dt']) == 12) {
                            $daily[$date]['icon'] = $slot['weather'][0]['icon'] ?? '01d';
                        }
                    }

                    $labels = ['Today', 'Tomorrow'];
                    $i = 0;
                    foreach ($daily as $date => $d) {
                        if ($i >= 3) break;
                        $forecastDays[] = [
                            'label'     => $labels[$i] ?? date('l', strtotime($date)),
                            'high'      => round($d['high']),
                            'low'       => round($d['low']),
                            'icon'      => $d['icon'] ?: '01d',
                            'condition' => 'Partly Cloudy' // placeholder — can be improved later
                        ];
                        $i++;
                    }
                } else {
                    error_log("[weather] forecast unavailable or invalid response — using cache fallback if present");
                }
            } else {
                error_log("[weather] file_get_contents failed (forecast) — network issue");
            }

            $success = true;

        } else {
            // API returned error response (401, 429, 400, etc.)
            error_log(
                "[weather] current API error — cod=" .
                ($data['cod']    ?? 'unknown') .
                " msg=" .
                ($data['message'] ?? 'none')
            );
        }
    }

    // ── Only write cache if we actually got valid current weather ──
    if ($success) {
        // Protect existing forecast if new one is empty
        if (empty($forecastDays) && file_exists($cachePath)) {
            $old = json_decode(file_get_contents($cachePath), true);
            if (is_array($old) && !empty($old['forecast'])) {
                $forecastDays = $old['forecast'];
                error_log("[weather] forecast fetch failed — preserved previous forecast data");
            }
        }

        $cacheContent = [
            'current'  => $currentWeather,
            'forecast' => $forecastDays
        ];

        file_put_contents($cachePath, json_encode($cacheContent, JSON_PRETTY_PRINT), LOCK_EX);
        @chmod($cachePath, 0644);

        $versions['modules']['weather'] = [
            'lastUpdatedUnix' => $now,
            'source'          => 'openweathermap'
        ];
        file_put_contents($versionsPath, json_encode($versions, JSON_PRETTY_PRINT), LOCK_EX);

        $weatherValid = true;

        error_log("[weather] SUCCESS — cache seeded at " . date('Y-m-d H:i:s'));
    }
}

#endregion

#region SECTION 3 — Time Context (TIS)

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