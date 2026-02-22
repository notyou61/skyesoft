<?php
declare(strict_types=1);

// ======================================================================
//  Skyesoft — getDynamicData.php
//  Version: 1.0.3
//  Last Updated: 2026-02-22
//  Codex Tier: 4 — Backend Module
//  Provides: TIS + Weather + KPI + Permits + Permit News + Site Meta + Sentinel
//  NO Output • NO Loop • NO Exit — Consumed by sse.php only
// ======================================================================

#region SECTION 0 — Dependencies
require_once __DIR__ . '/holidayInterpreter.php';
#endregion

#region SECTION 1 — Registry Loading & Paths
// More reliable root: DOCUMENT_ROOT + known /skyesoft subfolder
$projectRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/') . '/skyesoft';
// Fallback if DOCUMENT_ROOT is empty/unreliable
if (!is_dir($projectRoot)) {
    $projectRoot = dirname(__DIR__);
}

$paths = [
    "codex"             => $projectRoot . "/codex/codex.json",
    "versions"          => $projectRoot . "/data/authoritative/versions.json",
    "holiday"           => $projectRoot . "/data/authoritative/holidayRegistry.json",
    "systemRegistry"    => $projectRoot . "/data/authoritative/systemRegistry.json",
    "roadmap"           => $projectRoot . "/data/authoritative/roadmap.json",
    "kpi"               => $projectRoot . "/data/runtimeEphemeral/kpiRegistry.json",
    "permits"           => $projectRoot . "/data/runtimeEphemeral/permitRegistry.json",
    "permitNews"        => $projectRoot . "/data/runtimeEphemeral/permitNews.json",
    "sentinel"          => $projectRoot . "/data/runtimeEphemeral/sentinelState.json",
    "audit"             => $projectRoot . "/auditResults.json"
];

// Debug paths — check error log after reload!
error_log("[PATH-DEBUG] projectRoot: " . $projectRoot);
error_log("[PATH-DEBUG] sentinel attempted: " . $paths["sentinel"] . " | exists? " . (file_exists($paths["sentinel"]) ? 'YES' : 'NO'));
error_log("[PATH-DEBUG] audit attempted: " . $paths["audit"] . " | exists? " . (file_exists($paths["audit"]) ? 'YES' : 'NO'));

foreach ($paths as $key => $path) {
    if (in_array($key, ['sentinel', 'audit'])) continue;
    if (!file_exists($path)) {
        throw new RuntimeException("Missing required file: {$key} at {$path}");
    }
}

$codex          = json_decode(file_get_contents($paths["codex"]), true) ?? [];
$versions       = json_decode(file_get_contents($paths["versions"]), true) ?? [];
$systemRegistry = json_decode(file_get_contents($paths["systemRegistry"]), true) ?? [];
$roadmap        = json_decode(file_get_contents($paths["roadmap"]), true) ?? [];

$kpi            = json_decode(file_get_contents($paths["kpi"]), true) ?? [];
$activePermits  = json_decode(file_get_contents($paths["permits"]), true) ?? [];
$permitNews     = json_decode(file_get_contents($paths["permitNews"]), true) ?? [];

$tz  = new DateTimeZone("America/Phoenix");
$now = time();
#endregion

#region SECTION 2 — Sentinel + Audit (authoritative unresolved list)
$sentinelMeta = [
    "baselineEstablished"      => false,
    "initialRunUnix"           => null,
    "lastRunUnix"              => null,
    "lastRunLocal"             => null,
    "runCount"                 => 0,
    "ageSeconds"               => 0,
    "executionStatus"          => "unknown",
    "unresolvedViolations"     => 0,
    "constitutionalViolations" => 0,
    "governanceStatus"         => "unknown",
    "unresolved"               => []
];

if (file_exists($paths["sentinel"])) {
    $sentinelRaw = @json_decode(@file_get_contents($paths["sentinel"]), true);
    if (is_array($sentinelRaw) && isset($sentinelRaw["lastRunUnix"])) {
        $lastRunUnix    = (int)$sentinelRaw["lastRunUnix"];
        $initialRunUnix = (int)($sentinelRaw["initialRunUnix"] ?? 0);
        $runCount       = (int)($sentinelRaw["runCount"] ?? 0);

        $ageSeconds = max(0, $now - $lastRunUnix);
        $status = ($ageSeconds <= 90) ? "ok" : (($ageSeconds <= 300) ? "stale" : "offline");

        $dtSentinel = new DateTime('@' . $lastRunUnix);
        $dtSentinel->setTimezone($tz);

        $sentinelMeta = [
            "baselineEstablished"      => ($initialRunUnix > 0),
            "initialRunUnix"           => ($initialRunUnix > 0) ? $initialRunUnix : null,
            "lastRunUnix"              => $lastRunUnix,
            "lastRunLocal"             => $dtSentinel->format("h:i:s A"),
            "runCount"                 => $runCount,
            "ageSeconds"               => $ageSeconds,
            "executionStatus"          => $status,
            "unresolvedViolations"     => (int)($sentinelRaw["unresolvedViolations"] ?? 0),
            "constitutionalViolations" => (int)($sentinelRaw["constitutionalViolations"] ?? 0),
            "governanceStatus"         => $sentinelRaw["governanceStatus"] ?? "unknown",
            "unresolved"               => []
        ];

        if ($initialRunUnix > 0 && $runCount > 1) {
            $uptime = $now - $initialRunUnix;
            $sentinelMeta["uptimeSeconds"] = $uptime;
            $sentinelMeta["averageIntervalSeconds"] = (int)round($uptime / ($runCount - 1));
        }
    } else {
        error_log("[SENTINEL-DEBUG] File exists but decode failed or missing lastRunUnix");
    }
}

// Enrich from audit (canonical) — run regardless of sentinel
if (file_exists($paths["audit"])) {
    $auditContent = @file_get_contents($paths["audit"]);
    if ($auditContent !== false) {
        $auditDoc = @json_decode($auditContent, true);
        if (is_array($auditDoc) && isset($auditDoc["violations"]) && is_array($auditDoc["violations"])) {
            $unresolved = [];
            foreach ($auditDoc["violations"] as $violation) {
                if (($violation["resolved"] ?? null) === null) {
                    $unresolved[] = [
                        "violationId" => $violation["violationId"] ?? null,
                        "ruleId"      => $violation["ruleId"] ?? null,
                        "observation" => $violation["observation"] ?? null,
                        "severity"    => $violation["severity"] ?? "standard"
                    ];
                }
            }
            $sentinelMeta["unresolved"] = $unresolved;

            if (isset($auditDoc["meta"]["unresolvedViolations"])) {
                $sentinelMeta["unresolvedViolations"] = (int)$auditDoc["meta"]["unresolvedViolations"];
            }
        }
    }
} else {
    error_log("[AUDIT-DEBUG] auditResults.json missing at " . $paths["audit"]);
}

error_log("SSE SENTINEL: sentinel=" . $paths["sentinel"] . " exists=" . (file_exists($paths["sentinel"]) ? 'yes' : 'no') .
          " | audit=" . $paths["audit"] . " unresolved=" . count($sentinelMeta["unresolved"]));
#endregion

#region SECTION 3 — Weather Configuration (CURRENT + 3-DAY FORECAST)
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
        $_SERVER[$key] = $value;
    }
    error_log("[env-loader] Loaded .env from $envPath");
} else {
    error_log("[env-loader] FAILED to load .env at $envPath");
}

$cachePath    = $root . '/data/runtimeEphemeral/weatherCache.json';
$versionsPath = $root . '/data/authoritative/versions.json';

$versions = file_exists($versionsPath) ? json_decode(file_get_contents($versionsPath), true) : [];
$versions['modules']['weather']['lastUpdatedUnix'] ??= 0;
$lastWeatherUpdate = (int)($versions['modules']['weather']['lastUpdatedUnix'] ?? 0);

$cacheExists = file_exists($cachePath) && filesize($cachePath) > 0;

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
        error_log("[weather] Using valid cache (last: " . date('Y-m-d H:i:s', $lastWeatherUpdate) . ")");
    }
}

$weatherKey = trim(getenv('WEATHER_API_KEY') ?: '');
$shouldFetch = $weatherKey !== '' && (!$weatherValid || ($now - $lastWeatherUpdate >= 900));

if ($shouldFetch) {
    error_log("[weather] Fetch triggered: " . (!$weatherValid ? 'bootstrap' : 'refresh (stale)'));
    $lat = 33.4484;
    $lon = -112.0740;
    $key = $weatherKey;

    $urlCurrent = "https://api.openweathermap.org/data/2.5/weather?lat={$lat}&lon={$lon}&units=imperial&appid={$key}";
    $ctx = stream_context_create(['http' => ['timeout' => 12, 'ignore_errors' => true]]);
    $rawCurrent = @file_get_contents($urlCurrent, false, $ctx);

    $success = false;

    if ($rawCurrent !== false) {
        $data = json_decode($rawCurrent, true);
        if (is_array($data) && ($data['cod'] ?? null) == 200 && isset($data['main']['temp'])) {
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

            // Forecast (best effort)
            $urlForecast = "https://api.openweathermap.org/data/2.5/forecast?lat={$lat}&lon={$lon}&units=imperial&appid={$key}";
            $rawForecast = @file_get_contents($urlForecast, false, $ctx);
            if ($rawForecast !== false) {
                $f = json_decode($rawForecast, true);
                if (is_array($f) && ($f['cod'] ?? null) == 200 && isset($f['list'])) {
                    $daily = [];
                    foreach ($f['list'] as $slot) {
                        $date = date('Y-m-d', $slot['dt']);
                        if (!isset($daily[$date])) $daily[$date] = ['high' => -INF, 'low' => INF, 'icon' => null];
                        $daily[$date]['high'] = max($daily[$date]['high'], $slot['main']['temp_max']);
                        $daily[$date]['low']  = min($daily[$date]['low'],  $slot['main']['temp_min']);
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
                }
            }
        }
    }

    if ($success) {
        $cacheContent = ['current' => $currentWeather, 'forecast' => $forecastDays];
        file_put_contents($cachePath, json_encode($cacheContent, JSON_PRETTY_PRINT), LOCK_EX);
        @chmod($cachePath, 0644);

        $versions['modules']['weather'] = ['lastUpdatedUnix' => $now, 'source' => 'openweathermap'];
        file_put_contents($versionsPath, json_encode($versions, JSON_PRETTY_PRINT), LOCK_EX);

        $weatherValid = true;
        error_log("[weather] Cache updated");
    }
} else if ($weatherValid) {
    error_log("[weather] Using cache (not stale)");
}
#endregion

#region SECTION 4 — Time Context (TIS)
if (!function_exists('buildTimeContext')) {
    function buildTimeContext(DateTime $dt, array $systemRegistry, string $holidayPath): array {
        $nowUnix = (int)$dt->format("U");
        $weekday = (int)$dt->format("N");

        $holidayState = resolveHolidayState($holidayPath, $dt);
        $isHoliday = $holidayState["isHoliday"];

        $calendarType = $isHoliday ? "holiday" : ($weekday >= 6 ? "weekend" : "workday");

        [$startH, $startM] = array_map('intval', explode(":", $systemRegistry["schedule"]["officeHours"]["start"]));
        [$endH, $endM]     = array_map('intval', explode(":", $systemRegistry["schedule"]["officeHours"]["end"]));

        $workStartSecs = $startH * 3600 + $startM * 60;
        $workEndSecs   = $endH   * 3600 + $endM   * 60;
        $nowSecs       = ((int)$dt->format("G") * 3600) + ((int)$dt->format("i") * 60) + ((int)$dt->format("s"));

        $next = clone $dt;
        if ($nowSecs >= $workEndSecs) $next->modify("+1 day");
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

        if ($calendarType === "workday" && $nowSecs >= $workStartSecs && $nowSecs < $workEndSecs) {
            $key = "worktime";
            $startUnix = (clone $dt)->setTime($startH, $startM, 0)->format("U");
            $endUnix   = (clone $dt)->setTime($endH, $endM, 0)->format("U");
            $remaining = max(0, $endUnix - $nowUnix);
        } elseif ($calendarType === "workday" && $nowSecs < $workStartSecs) {
            $key = "beforeWork";
            $startUnix = $nowUnix;
            $endUnix   = (clone $dt)->setTime($startH, $startM, 0)->format("U");
            $remaining = max(0, $endUnix - $nowUnix);
        } else {
            $key = $calendarType;
            $startUnix = $nowUnix;
            $endUnix   = $nextUnix;
            $remaining = $secondsToNextWork;
        }

        return [
            "calendarType" => $calendarType,
            "currentInterval" => [
                "key" => $key,
                "intervalStartUnix" => $startUnix,
                "intervalEndUnix" => $endUnix,
                "secondsIntoInterval" => max(0, $nowUnix - $startUnix),
                "secondsRemainingInterval" => $remaining,
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
    function getTimeContext(DateTimeZone $tz, array $systemRegistry, string $holidayPath): array {
        $dt = new DateTime('@' . time());
        $dt->setTimezone($tz);
        return buildTimeContext($dt, $systemRegistry, $holidayPath);
    }
}

$timeContext = getTimeContext($tz, $systemRegistry, $paths["holiday"]);
#endregion

#region SECTION 5 — Final Payload Assembly
// Weather safety fallback
if (!$weatherValid && file_exists($cachePath)) {
    $cached = json_decode(file_get_contents($cachePath), true);
    if (is_array($cached) && isset($cached['current'])) {
        $currentWeather = $cached['current'];
        $forecastDays   = $cached['forecast'] ?? [];
        $currentWeather['source'] = 'cache-fallback';
    }
}
$weather = $currentWeather;
$weather['forecast'] = $forecastDays;

// Active permits list
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

// Site meta
$lastUpdateUnix = (int)($versions["system"]["lastUpdateUnix"] ?? $versions["system"]["deployUnix"] ?? 0);
$siteMeta = [
    "siteVersion"     => $versions["system"]["siteVersion"] ?? "unknown",
    "lastUpdateUnix"  => $lastUpdateUnix ?: null,
    "updateOccurred"  => (bool)($versions["system"]["updateOccurred"] ?? false)
];

if ($lastUpdateUnix > 0) {
    $dt = new DateTime('@' . $lastUpdateUnix);
    $dt->setTimezone(new DateTimeZone('America/Phoenix'));
    $siteMeta["lastUpdateLocal"] = $dt->format('Y-m-d h:i:s A');
    $siteMeta["lastUpdateAgeSeconds"] = $now - $lastUpdateUnix;
}

// Final payload
$payload = [
    "systemRegistry"  => $systemRegistry,
    "calendarType"    => $timeContext["calendarType"],
    "currentInterval" => $timeContext["currentInterval"],
    "timeDateArray"   => $timeContext["timeDateArray"],
    "holidayState"    => $timeContext["holidayState"],
    "weather"         => $weather,
    "kpi"             => $kpi,
    "roadmap"         => $roadmap,
    "activePermits"   => $permitList,
    "permitNews"      => is_array($permitNews) ? $permitNews : null,
    "siteMeta"        => $siteMeta,
    "sentinelMeta"    => $sentinelMeta
];
#endregion

#region SECTION 6 — Output for SSE (Flush every update)
return $payload;
#endregion