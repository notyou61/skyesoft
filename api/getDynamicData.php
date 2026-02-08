<?php
declare(strict_types=1);

// ======================================================================
//  Skyesoft — getDynamicData.php
//  Version: 1.0.0
//  Last Updated: 2025-11-29
//  Codex Tier: 4 — Backend Module
//  Provides: TIS + Weather + KPI + Permits + Permit News + Site Meta
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
    "kpi"            => $root . "/data/runtimeEphemeral/kpiRegistry.json",
    "permits"        => $root . "/data/runtimeEphemeral/permitRegistry.json",
    "permitNews"     => $root . "/data/runtimeEphemeral/permitNews.json",
    "sentinel"       => $root . "/data/runtimeEphemeral/sentinelState.json" // 👈 NEW
];

foreach ($paths as $key => $path) {
    if ($key === "sentinel") continue; // Sentinel is optional
    if (!file_exists($path)) {
        throw new RuntimeException("Missing {$key} at {$path}");
    }
}

$codex          = json_decode(file_get_contents($paths["codex"]), true);
$versions       = json_decode(file_get_contents($paths["versions"]), true);
$systemRegistry = json_decode(file_get_contents($paths["systemRegistry"]), true);

$kpi            = json_decode(file_get_contents($paths["kpi"]), true);
$activePermits  = json_decode(file_get_contents($paths["permits"]), true);
$permitNews = json_decode(file_get_contents($paths["permitNews"]), true);

$tz = new DateTimeZone("America/Phoenix");

$sentinelMeta = null;

if (file_exists($paths["sentinel"])) {
    // Load sentinel state (observational only)
    $sentinelRaw = json_decode(file_get_contents($paths["sentinel"]), true);

    if (is_array($sentinelRaw) && isset($sentinelRaw["lastRunUnix"])) {
        $now            = time();
        $lastRunUnix    = (int)$sentinelRaw["lastRunUnix"];
        $initialRunUnix = (int)($sentinelRaw["initialRunUnix"] ?? 0);
        $runCount       = (int)($sentinelRaw["runCount"] ?? 0);

        // Age since last heartbeat
        $ageSeconds = max(0, $now - $lastRunUnix);

        // Health classification
        if ($ageSeconds <= 90) {
            $status = "ok";
        } elseif ($ageSeconds <= 300) {
            $status = "stale";
        } else {
            $status = "offline";
        }

        // Phoenix-local formatting (presentation only)
        $dtSentinel = new DateTime('@' . $lastRunUnix);
        $dtSentinel->setTimezone($tz);

        // Baseline state (prime run awareness)
        $baselineEstablished = ($initialRunUnix > 0);

        $sentinelMeta = [
            "baselineEstablished" => $baselineEstablished,
            "initialRunUnix"      => $baselineEstablished ? $initialRunUnix : null,
            "lastRunUnix"         => $lastRunUnix,
            "lastRunLocal"        => $dtSentinel->format("h:i:s A"),
            "runCount"            => $runCount,
            "ageSeconds"          => $ageSeconds,
            "status"              => $status
        ];

        // Derived metrics (statistical, read-only)
        if ($baselineEstablished && $runCount > 1) {
            $uptimeSeconds = $now - $initialRunUnix;

            $sentinelMeta["uptimeSeconds"] = $uptimeSeconds;
            $sentinelMeta["averageIntervalSeconds"] =
                (int)round($uptimeSeconds / ($runCount - 1));
        }
    }
}

#endregion

#region SECTION 2 — Weather Configuration (CURRENT + 3-DAY FORECAST) — BOOTSTRAP + REFRESH

// ── Load .env from /secure (cPanel-safe, absolute anchor) ───────────
$envPath = dirname(__DIR__, 3) . '/secure/.env';

if (file_exists($envPath) && is_readable($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#')) continue;
        if (strpos($line, '=') === false) continue;

        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value, " \t\n\r\0\x0B\"'");

        putenv("$key=$value");
        $_ENV[$key]    = $value;
        $_SERVER[$key] = $value; // for shared-host compatibility
    }

    error_log("[env-loader] Loaded .env from $envPath");
} else {
    error_log("[env-loader] FAILED to load .env at $envPath");
}

$cachePath    = $root . '/data/runtimeEphemeral/weatherCache.json';
$versionsPath = $root . '/data/authoritative/versions.json';

// ── Load versions ──
$versions = file_exists($versionsPath) ? json_decode(file_get_contents($versionsPath), true) : [];
$versions['modules']['weather']['lastUpdatedUnix'] ??= 0;
$lastWeatherUpdate = (int)$versions['modules']['weather']['lastUpdatedUnix'];
$now = time();

// ── Cache status ──
$cacheExists = file_exists($cachePath) && filesize($cachePath) > 0;

// ── Default fallback ──
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
$weatherValid = false;

// ── Try cache first ──
if ($cacheExists) {
    $cached = json_decode(file_get_contents($cachePath), true);

    $cacheIsValid = is_array($cached)
        && isset($cached['current']['temp']) && $cached['current']['temp'] !== null
        && isset($cached['current']['sunriseUnix']) && $cached['current']['sunriseUnix'] !== null;

    if ($cacheIsValid) {
        $currentWeather = $cached['current'];
        $forecastDays   = $cached['forecast'] ?? [];
        $currentWeather['source'] = 'cache';
        $weatherValid = true;
        error_log("[weather] Using valid cache (last updated: " . date('Y-m-d H:i:s', $lastWeatherUpdate) . ")");
    } else {
        error_log("[weather] Cache exists but invalid/empty → forcing bootstrap fetch");
    }
} else {
    error_log("[weather] No cache found → forcing bootstrap fetch");
}

// ── API key check ──
$weatherKey = trim(getenv('WEATHER_API_KEY') ?: '');
if ($weatherKey === '') {
    error_log('[weather][DIAG] WEATHER_API_KEY is EMPTY — cannot fetch');
} else {
    error_log('[weather][DIAG] WEATHER_API_KEY present (len=' . strlen($weatherKey) . ')');
}

// ── Decide whether to fetch ──
// Bootstrap: always fetch if no valid data
// Refresh: only if stale (≥15 min) AND we have key
$shouldFetch = $weatherKey !== ''
    && (
        !$weatherValid                       // Bootstrap: no good data → fetch NOW
        || ($now - $lastWeatherUpdate >= 900) // Refresh: stale cache → update
    );

if ($shouldFetch) {
    error_log("[weather] Fetch triggered: " . (!$weatherValid ? 'bootstrap (no valid data)' : 'refresh (stale ≥15min)'));

    $lat = 33.4484;
    $lon = -112.0740;
    $key = $weatherKey;

    $urlCurrent  = "https://api.openweathermap.org/data/2.5/weather?lat={$lat}&lon={$lon}&units=imperial&appid={$key}";
    $ctx = stream_context_create([
        'http' => [
            'timeout'       => 12,
            'ignore_errors' => true
        ]
    ]);
    $rawCurrent  = @file_get_contents($urlCurrent, false, $ctx);

    $success = false;

    if ($rawCurrent === false) {
        error_log("[weather] file_get_contents failed (current) — network/SSL/allow_url_fopen/DNS? " . print_r(error_get_last(), true));
    } else {
        $data = json_decode($rawCurrent, true);

    if (
        is_array($data) &&
        array_key_exists('cod', $data) &&
        ((string)$data['cod'] === '200' || (int)$data['cod'] === 200) &&
        isset($data['main']) && is_array($data['main']) &&
        array_key_exists('temp', $data['main']) && $data['main']['temp'] !== null
    ) {
        error_log("[weather] MAIN SUCCESS PATH — cod = " . $data['cod'] . ", temp = " . $data['main']['temp']);

        // ── SUCCESS PATH ──
        $sunrise = $data['sys']['sunrise'] ?? null;
        $sunset  = $data['sys']['sunset']  ?? null;

        $currentWeather = [
            'temp'            => round($data['main']['temp']),
            'condition'       => $data['weather'][0]['description'] ?? 'unknown',
            'icon'            => $data['weather'][0]['icon']       ?? '04d',
            'sunrise'         => $sunrise ? date('g:i A', $sunrise) : null,
            'sunset'          => $sunset  ? date('g:i A', $sunset)  : null,
            'sunriseUnix'     => $sunrise,
            'sunsetUnix'      => $sunset,
            'daylightSeconds' => ($sunrise && $sunset) ? ($sunset - $sunrise) : null,
            'nightSeconds'    => ($sunrise && $sunset) ? (86400 - ($sunset - $sunrise)) : null,
            'source'          => 'openweathermap'
        ];

        $success = true;

        // ── Fetch 3-day forecast (best-effort, non-blocking) ──
        $urlForecast = "https://api.openweathermap.org/data/2.5/forecast?lat={$lat}&lon={$lon}&units=imperial&appid={$key}";
        $rawForecast = @file_get_contents($urlForecast, false, $ctx);

        if ($rawForecast !== false) {
            $f = json_decode($rawForecast, true);

            if (
                is_array($f) &&
                isset($f['cod']) &&
                ((string)$f['cod'] === '200' || (int)$f['cod'] === 200) &&
                isset($f['list']) && is_array($f['list'])
            ) {
                $daily = [];

                foreach ($f['list'] as $slot) {
                    $date = date('Y-m-d', $slot['dt']);

                    if (!isset($daily[$date])) {
                        $daily[$date] = [
                            'high' => -INF,
                            'low'  => INF,
                            'icon' => null
                        ];
                    }

                    $daily[$date]['high'] = max($daily[$date]['high'], $slot['main']['temp_max']);
                    $daily[$date]['low']  = min($daily[$date]['low'],  $slot['main']['temp_min']);

                    // Prefer midday icon
                    if (date('G', $slot['dt']) >= 11 && date('G', $slot['dt']) <= 14) {
                        $daily[$date]['icon'] = $slot['weather'][0]['icon'] ?? '04d';
                    }
                }

                $i = 0;
                foreach ($daily as $date => $d) {
                    if ($i >= 3) break;

                    $forecastDays[] = [
                        'dateUnix' => strtotime($date),
                        'high'     => round($d['high']),
                        'low'      => round($d['low']),
                        'icon'     => $d['icon'] ?? '04d'
                    ];
                    $i++;
                }

                error_log("[weather] Forecast populated (" . count($forecastDays) . " days)");
            } else {
                error_log("[weather] Forecast API returned invalid structure");
            }
        } else {
            error_log("[weather] Forecast fetch failed (non-fatal)");
        }


        } else {
            error_log("[weather] current API error — cod=" . ($data['cod'] ?? 'unknown') .
                      " msg=" . ($data['message'] ?? 'none') .
                      " sample=" . substr($rawCurrent, 0, 150));
        }
    }

    if ($success) {
        // Preserve old forecast on partial failure
        if (empty($forecastDays) && file_exists($cachePath)) {
            $old = json_decode(file_get_contents($cachePath), true);
            if (is_array($old) && !empty($old['forecast'])) {
                $forecastDays = $old['forecast'];
                error_log("[weather] forecast fetch failed — preserved previous forecast");
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

        error_log("[weather] SUCCESS — cache seeded/updated at " . date('Y-m-d H:i:s'));
    } else {
        error_log("[weather] Fetch failed — keeping fallback (will retry next run)");
    }
} else {
    error_log("[weather] No fetch needed: valid cache + not stale yet");
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
// Site Meta — Canonical (Unix-only, UI-aligned)
// ------------------------------------------------------------
$lastUpdateUnix = (int)(
    $versions["system"]["lastUpdateUnix"]
    ?? $versions["system"]["deployUnix"]
    ?? 0
);

// ------------------------------------------------------------
// Update Decay — Canonical (server-authoritative)
// ------------------------------------------------------------
$siteMeta = [
    "siteVersion"     => $versions["system"]["siteVersion"] ?? "unknown",
    "lastUpdateUnix"  => $lastUpdateUnix ?: null,
    "updateOccurred"  => (bool)($versions["system"]["updateOccurred"] ?? false)
];

// Derived, presentation-only (non-authoritative)
if ($lastUpdateUnix > 0) {
    $siteMeta["lastUpdateLocal"]      = date("Y-m-d h:i:s A", $lastUpdateUnix);
    $siteMeta["lastUpdateAgeSeconds"] = time() - $lastUpdateUnix;
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
    "permitNews"      => is_array($permitNews) ? $permitNews : null,
    "siteMeta"        => $siteMeta,
    // Sentinel Runtime Meta
    "sentinelMeta"    => $sentinelMeta
];

#endregion

#region SECTION 5 — Output for SSE (Flush every update) (portions commented out)
//header('Content-Type: application/json');
//echo json_encode($payload, JSON_PRETTY_PRINT);
//echo "data: " . json_encode($payload) . "\n\n";
//@ob_flush();
//flush();
return $payload;
#endregion