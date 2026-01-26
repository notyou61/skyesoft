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

// ── Diagnostic output (TEMP — remove once verified) ─────────────────
echo "<pre>";
echo "getenv('OPENAI_API_KEY'): " .
     (getenv('OPENAI_API_KEY') ? 'present (len ' . strlen(getenv('OPENAI_API_KEY')) . ')' : 'EMPTY') . "\n";
echo "getenv('WEATHER_API_KEY'): " .
     (getenv('WEATHER_API_KEY') ? 'present (len ' . strlen(getenv('WEATHER_API_KEY')) . ')' : 'EMPTY') . "\n";
echo "OPENAI_API_KEY in _ENV: " . (isset($_ENV['OPENAI_API_KEY']) ? 'YES' : 'NO') . "\n";
echo "WEATHER_API_KEY in _ENV: " . (isset($_ENV['WEATHER_API_KEY']) ? 'YES' : 'NO') . "\n";
echo "</pre>";


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

#region SECTION 2 — Weather Configuration (CURRENT + 3-DAY FORECAST) — BOOTSTRAP + REFRESH

// ────────────────────────────────────────────────────────────────
// SMOKE TEST v2 — PRINT TO BROWSER + LOG
// This will show results directly when you visit the URL
// ────────────────────────────────────────────────────────────────

echo "<pre style='background:#111; color:#0f0; padding:15px; font-family:monospace; border:1px solid #333;'>";
echo "<strong>Weather API Smoke Test (run at " . date('Y-m-d H:i:s T') . ")</strong>\n\n";

$smokeKey = trim(getenv('WEATHER_API_KEY') ?: '');
//$smokeKey = '0fd7b16fe667ade38033ebb5c871aab8';

if ($smokeKey === '') {
    echo "[ERROR] WEATHER_API_KEY is empty or not set in environment\n";
    error_log("[weather-smoke] ERROR: WEATHER_API_KEY is empty or not set");
} else {
    echo "[OK] API key loaded (length: " . strlen($smokeKey) . ")\n";
    error_log("[weather-smoke] API key loaded (length: " . strlen($smokeKey) . ")");

    $lat = 33.4484;
    $lon = -112.0740;
    $url = "https://api.openweathermap.org/data/2.5/weather?lat={$lat}&lon={$lon}&units=imperial&appid={$smokeKey}";

    $ctx = stream_context_create([
        'http' => [
            'timeout'       => 10,
            'ignore_errors' => true,
        ]
    ]);

    $raw = @file_get_contents($url, false, $ctx);

    if ($raw === false) {
        echo "[FAIL] Fetch failed — likely network, SSL, allow_url_fopen or hosting restriction\n";
        $err = error_get_last();
        if ($err) {
            echo "Error details: " . $err['message'] . "\n";
            error_log("[weather-smoke] FETCH FAILED: " . $err['message']);
        }
    } else {
        $data = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            echo "[FAIL] JSON parse error: " . json_last_error_msg() . "\n";
            echo "Raw response (first 200 chars): " . substr($raw, 0, 200) . "\n";
            error_log("[weather-smoke] JSON parse error: " . json_last_error_msg());
        } elseif (!is_array($data) || !isset($data['cod'])) {
            echo "[FAIL] Invalid response — no 'cod' field\n";
            echo "Raw sample: " . substr($raw, 0, 200) . "\n";
        } else {
            $cod = $data['cod'];
            $hasTemp = isset($data['main']['temp']);
            $codStr = (string)$cod;

            echo "[INFO] cod: $codStr  |  has temp: " . ($hasTemp ? 'YES' : 'NO') . "\n";

            if (($codStr === '200' || (int)$cod === 200) && $hasTemp) {
                $temp = round($data['main']['temp']);
                $desc = $data['weather'][0]['description'] ?? 'unknown';
                $sunrise = date('g:i A', $data['sys']['sunrise'] ?? time());
                $sunset  = date('g:i A', $data['sys']['sunset'] ?? time());
                echo "[SUCCESS] Valid data received!\n";
                echo "  Temperature: {$temp} °F\n";
                echo "  Condition:  $desc\n";
                echo "  Sunrise:    $sunrise\n";
                echo "  Sunset:     $sunset\n";
                error_log("[weather-smoke] SUCCESS — Temp: {$temp}°F, $desc");
            } else {
                $msg = $data['message'] ?? 'no message';
                echo "[FAILURE] cod = $codStr, message = '$msg'\n";
                echo "Raw sample: " . substr($raw, 0, 300) . "\n";
                error_log("[weather-smoke] FAILURE — cod=$codStr, msg='$msg'");
            }
        }
    }
}

echo "</pre>\n\n<hr>\n";

// ────────────────────────────────────────────────────────────────
// Rest of your normal SECTION 2 code continues below...
// ────────────────────────────────────────────────────────────────

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
    $urlForecast = "https://api.openweathermap.org/data/2.5/forecast?lat={$lat}&lon={$lon}&units=imperial&appid={$key}";

    $ctx = stream_context_create([
        'http' => [
            'timeout'       => 12,
            'ignore_errors' => true
        ]
    ]);

    $rawCurrent  = @file_get_contents($urlCurrent, false, $ctx);
    $rawForecast = @file_get_contents($urlForecast, false, $ctx);

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