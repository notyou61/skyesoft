<?php
declare(strict_types=1);

// ======================================================================
//  Skyesoft — getDynamicData.php
//  Version: 1.1.0
//  Last Updated: 2026-09-07  Codex Tier: 4 — Backend Module
//  Provides: TIS + Weather + KPI + Permits + Permit News + Site Meta
//  NO Output • NO Loop • NO Exit — Consumed by sse.php only
// ======================================================================

#region SECTION 0 — Dependencies & Core Database Connection
require_once __DIR__ . '/holidayInterpreter.php';
require_once __DIR__ . '/utils/envLoader.php';
require_once __DIR__ . '/utils/openApplicationsReportData.php';

// ─────────────────────────────────────────
// DATABASE CONNECTION (FILE-WIDE SCOPE)
// Phase 1A — Establish shared PDO instance
// ─────────────────────────────────────────
skyesoftLoadEnv();

$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbName = getenv('DB_NAME') ?: '';
$dbUser = getenv('DB_USER') ?: '';
$dbPass = getenv('DB_PASS') ?: '';
$dbChar = getenv('DB_CHARSET') ?: 'utf8mb4';

$db = null;
$dbConnectionError = null;

try {
    $db = new PDO(
        "mysql:host={$dbHost};dbname={$dbName};charset={$dbChar}",
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Throwable $e) {
    $dbConnectionError = $e->getMessage();
    error_log('[DYNAMIC DB] Connection error: ' . $dbConnectionError);
}

// ─────────────────────────────────────────
// IDLE STATE (CONSUMES SHARED DB HANDLE)
// ─────────────────────────────────────────
$auth = $SKYE_CONTEXT['auth'] ?? ['authenticated' => false];
$contactId = (int)($auth['contactId'] ?? 0);
$isAuthenticated = $auth['authenticated'] ?? false;

$idleTimeoutSeconds = defined('SKYESOFT_IDLE_TIMEOUT')
    ? SKYESOFT_IDLE_TIMEOUT
    : 900;   // fallback only if somehow loaded outside SSE

$idle = [
    "state" => "anonymous",
    "remainingSeconds" => null,
    "timeoutSeconds" => $idleTimeoutSeconds,
    "lastActivity" => null
];

$idleDebug = null;

if ($isAuthenticated && $contactId > 0) {

    $lastActivity = null;
    $idleDebug = [
        "contactId" => $contactId,
        "isAuthenticated" => $isAuthenticated,
        "envCheck" => [
            "dbConfigured"       => (getenv('DB_NAME') !== false),
            "userConfigured"     => (getenv('DB_USER') !== false),
            "passwordConfigured" => (getenv('DB_PASS') !== false)
        ],
        "env" => [
            "host" => $dbHost,
            "db"   => $dbName,
            "user" => $dbUser
        ]
    ];

    if ($db !== null) {
        try {
            // DB identity
            $identityStmt = $db->query("SELECT DATABASE() as db, USER() as user");
            $idleDebug["dbIdentity"] = $identityStmt->fetch(PDO::FETCH_ASSOC);

            // Table sample
            $sampleStmt = $db->query("
                SELECT contactId, actionUnix
                FROM tblActions
                ORDER BY actionUnix DESC
                LIMIT 5
            ");
            $idleDebug["tableSample"] = $sampleStmt->fetchAll(PDO::FETCH_ASSOC);

            // Target query (Corrected schema mapping)
            $stmt = $db->prepare("
                SELECT actionUnix
                FROM tblActions
                WHERE contactId = :contactId
                ORDER BY actionUnix DESC
                LIMIT 1
            ");

            $stmt->bindValue(':contactId', $contactId, PDO::PARAM_INT);
            $stmt->execute();

            $value = $stmt->fetchColumn();

            $idleDebug["rawQueryResult"] = $value;

            $lastActivity = (int)($value ?: 0);
            $idleDebug["castLastActivity"] = $lastActivity;

        } catch (Throwable $e) {
            $idleDebug["error"] = $e->getMessage();
        }
    } else {
        $idleDebug["error"] = "Database connection unavailable: " . ($dbConnectionError ?? "Unknown error");
    }

    // Idle calculation
    if ($lastActivity > 0) {

        $idleSeconds = time() - $lastActivity;
        $remaining   = max(0, $idleTimeoutSeconds - $idleSeconds);

        $state = "active";

        if ($remaining <= 0) {
            $state = "expired";
        } elseif ($remaining <= 120) {
            $state = "warning";
        }

        $idle = [
            "state" => $state,
            "remainingSeconds" => $remaining,
            "timeoutSeconds" => $idleTimeoutSeconds,
            "lastActivity" => $lastActivity
        ];

    } else {

        $idle = [
            "state" => "active",
            "remainingSeconds" => $idleTimeoutSeconds,
            "timeoutSeconds" => $idleTimeoutSeconds,
            "lastActivity" => null
        ];
    }
}

error_log('[DYNAMIC AUTH] ' . json_encode($auth));

#endregion

// #region SECTION 1 — Registry Loading
$root = dirname(__DIR__);

$paths = [
    "codex"             => $root . "/codex/codex.json",
    "versions"          => $root . "/data/authoritative/versions.json",
    "holiday"           => $root . "/data/authoritative/holidayRegistry.json",
    "systemRegistry"    => $root . "/data/authoritative/systemRegistry.json",
    "roadmap"           => $root . "/data/authoritative/roadmap.json",
    "kpi"               => $root . "/data/runtimeEphemeral/kpiRegistry.json",
    "permits"           => $root . "/data/runtimeEphemeral/permitRegistry.json",
    "permitNews"        => $root . "/data/runtimeEphemeral/permitNews.json",
    "sentinel"          => $root . "/data/runtimeEphemeral/sentinelState.json",
    "audit"             => $root . "/data/records/auditResults.json"
];

foreach ($paths as $key => $path) {

    // Optional runtime-derived files
    if ($key === "sentinel" || $key === "audit") {
        continue;
    }

    if (!file_exists($path)) {
        throw new RuntimeException("Missing {$key} at {$path}");
    }
}

$codex          = json_decode(file_get_contents($paths["codex"]), true);
$versions       = json_decode(file_get_contents($paths["versions"]), true);
$systemRegistry = json_decode(file_get_contents($paths["systemRegistry"]), true);
$roadmap        = json_decode(file_get_contents($paths["roadmap"]), true);

$kpi            = json_decode(file_get_contents($paths["kpi"]), true);
$activePermits  = json_decode(file_get_contents($paths["permits"]), true); // Kept temporarily for comparison/fallback
$permitNews     = json_decode(file_get_contents($paths["permitNews"]), true);

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
            // Execution Layer (runtime health)
            "baselineEstablished"      => $baselineEstablished,
            "initialRunUnix"           => $baselineEstablished ? $initialRunUnix : null,
            "lastRunUnix"              => $lastRunUnix,
            "lastRunLocal"             => $dtSentinel->format("h:i:s A"),
            "runCount"                 => $runCount,
            "ageSeconds"               => $ageSeconds,
            "executionStatus"          => $status, // ok | stale | offline

            // Governance Layer (from Sentinel)
            "unresolvedViolations"     => (int)($sentinelRaw["unresolvedViolations"] ?? 0),
            "constitutionalViolations" => (int)($sentinelRaw["constitutionalViolations"] ?? 0),
            "governanceStatus"         => $sentinelRaw["governanceStatus"] ?? "unknown"
        ];

        // ------------------------------------------------------------
        // Governance Detail Projection (Unresolved Violations Only)
        // Derived from canonical auditResults.json
        // ------------------------------------------------------------
        $sentinelMeta["unresolved"] = []; // Always initialize

        $shouldProject = (int)($sentinelMeta["unresolvedViolations"] ?? 0) > 0;

        if ($shouldProject) {

            $auditPath = $paths["audit"] ?? null;

            if ($auditPath && file_exists($auditPath)) {

                $raw = file_get_contents($auditPath);

                if ($raw !== false && trim($raw) !== "") {

                    $auditDoc = json_decode($raw, true);

                    if (json_last_error() === JSON_ERROR_NONE && is_array($auditDoc)) {

                        $records = $auditDoc["violations"] ?? null;

                        if (is_array($records)) {

                            foreach ($records as $rec) {

                                // Only unresolved violations
                                if (($rec["resolved"] ?? null) !== null) {
                                    continue;
                                }

                                $sentinelMeta["unresolved"][] = [
                                    "violationId"      => $rec["violationId"] ?? null,
                                    "ruleId"           => $rec["ruleId"] ?? null,
                                    "observation"      => $rec["observation"] ?? null,
                                    "lastObserved"     => $rec["lastObserved"] ?? null,
                                    "observationCount" => $rec["observationCount"] ?? 1,
                                    "violationBatch"   => $rec["violationBatch"] ?? null
                                ];
                            }

                            // Reconcile counts to what we actually projected
                            $sentinelMeta["unresolvedViolations"] = count($sentinelMeta["unresolved"]);

                            $constitutionalRuleIds = [
                                "criticalArtifactPresence" => true
                            ];

                            $constitutionalCount = 0;
                            foreach ($sentinelMeta["unresolved"] as $u) {
                                $rid = (string)($u["ruleId"] ?? "");
                                if ($rid !== "" && isset($constitutionalRuleIds[$rid])) {
                                    $constitutionalCount++;
                                }
                            }
                            $sentinelMeta["constitutionalViolations"] = $constitutionalCount;

                        } else {
                            error_log("[sentinelMeta] auditResults.json missing 'violations' array");
                        }

                    } else {
                        error_log("[sentinelMeta] auditResults.json JSON decode error: " . json_last_error_msg());
                    }

                } else {
                    error_log("[sentinelMeta] auditResults.json read failed or empty");
                }

            } else {
                error_log("[sentinelMeta] auditResults.json missing at: " . ($auditPath ?: "null"));
            }
        }

        // Derived metrics (statistical, read-only)
        if ($baselineEstablished && $runCount > 1) {
            $uptimeSeconds = $now - $initialRunUnix;

            $sentinelMeta["uptimeSeconds"] = $uptimeSeconds;
            $sentinelMeta["averageIntervalSeconds"] =
                (int)round($uptimeSeconds / ($runCount - 1));
        }
    }
}

// #endregion

// #region SECTION 2 — Weather Configuration (CURRENT + 3-DAY FORECAST) — BOOTSTRAP + REFRESH

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

    // ── Forecast freshness safety check ──
    if ($cacheIsValid && !empty($cached['forecast'][0]['dateUnix'])) {

        $phoenix = new DateTime('now', new DateTimeZone('America/Phoenix'));
        $phoenix->setTime(0,0,0);
        $todayUnix = $phoenix->getTimestamp();

        if ($cached['forecast'][0]['dateUnix'] < $todayUnix) {
            $cacheIsValid = false;
            error_log("[weather] Cache forecast outdated → forcing refresh");
        }
    }

    if ($cacheIsValid) {

        $currentWeather = $cached['current'];
        $forecastDays   = $cached['forecast'] ?? [];
        $currentWeather['source'] = 'cache';
        $weatherValid = true;

        error_log("[weather] Using valid cache (last updated: " . date('Y-m-d H:i:s', $lastWeatherUpdate) . ")");

    } else {

        error_log("[weather] Cache exists but invalid/expired → forcing bootstrap fetch");

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
                    $forecastDays = [];

                    foreach ($f['list'] as $slot) {
                        $date = (new DateTime("@{$slot['dt']}"))
                            ->setTimezone(new DateTimeZone('America/Phoenix'))
                            ->format('Y-m-d');

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

                    ksort($daily);

                    $phoenix = new DateTime('now', new DateTimeZone('America/Phoenix'));
                    $phoenix->setTime(0,0,0);
                    $todayUnix = $phoenix->getTimestamp();

                    $count = 0;

                    foreach ($daily as $date => $d) {

                        $dateUnix = strtotime($date . ' America/Phoenix');

                        if ($dateUnix < $todayUnix) {
                            continue; // skip yesterday
                        }

                        if ($count >= 3) {
                            break;
                        }

                        $forecastDays[] = [
                            'dateUnix' => $dateUnix,
                            'high'     => round($d['high']),
                            'low'      => round($d['low']),
                            'icon'     => $d['icon'] ?? '04d'
                        ];

                        $count++;
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

// #endregion

#region SECTION 3 — Time Context (TIS)

if (!function_exists('buildTimeContext')) {
    function buildTimeContext(DateTime $dt, array $systemRegistry, string $holidayPath): array
    {
        $nowUnix = (int)$dt->format("U");
        $weekday = (int)$dt->format("N");

        $holidayState = resolveHolidayState($holidayPath, $dt);
        $isHoliday = $holidayState["isHoliday"];

        if ($isHoliday) {
            $calendarType = "holiday";
        } elseif ($weekday >= 6) {
            $calendarType = "weekend";
        } else {
            $calendarType = "workday";
        }

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

if (!function_exists('getTimeContext')) {
    function getTimeContext(DateTimeZone $tz, array $systemRegistry, string $holidayPath): array
    {
        $dt = new DateTime('@' . time());
        $dt->setTimezone($tz);
        return buildTimeContext($dt, $systemRegistry, $holidayPath);
    }
}

#endregion

#region SECTION 3.A — Active Application Projection (MySQL Database)

$permitList = [];
$permitProjectionSucceeded = false;
$lastApplicationUpdatedUnix = null;

if ($db !== null) {
    try {
        $rawApplications = loadOpenApplicationsReportData($db);
        $permitProjectionSucceeded = true;

        if (is_array($rawApplications)) {
            foreach ($rawApplications as $app) {

                $applicationUpdatedUnix = (int)(
                    $app["applicationUpdatedUnix"]
                    ?? 0
                );

                $applicationCreatedUnix = (int)(
                    $app["applicationCreatedUnix"]
                    ?? 0
                );

                $effectiveUpdatedUnix =
                    $applicationUpdatedUnix > 0
                        ? $applicationUpdatedUnix
                        : $applicationCreatedUnix;

                if (
                    $effectiveUpdatedUnix > 0 &&
                    (
                        $lastApplicationUpdatedUnix === null ||
                        $effectiveUpdatedUnix > $lastApplicationUpdatedUnix
                    )
                ) {
                    $lastApplicationUpdatedUnix =
                        $effectiveUpdatedUnix;
                }

                $permitList[] = [
                    "wo" =>
                        (string)($app["orderChristyNumber"] ?? ""),

                    "customer" =>
                        (string)($app["entityName"] ?? ""),

                    "jobsite" =>
                        (string)($app["locationName"] ?? ""),

                    "jurisdiction" =>
                        (string)($app["applicationJurisdiction"] ?? ""),

                    "stage" =>
                        (string)($app["applicationStageName"] ?? ""),

                    "status" =>
                        (string)($app["applicationStatusName"] ?? "")
                ];
            }
        }
    } catch (Throwable $e) {
        error_log(
            "[ACTIVE PERMITS DB PROJECTION ERROR] " .
            $e->getMessage()
        );
    }
}

#endregion

#region SECTION 3.B — Permit KPI Projection (MySQL Database)

$kpi = [
    "meta" => [
        "generatedOn" => $lastApplicationUpdatedUnix,
        "source"      => "database"
    ],

    "atAGlance" => [
        "totalActive" => 0,
        "averageTurnaroundDays" => 0
    ],

    // Legacy payload key retained temporarily for compatibility
    "statusBreakdown" => [],

    "stageBreakdown" => [],

    "stageStatusBreakdown" => [],

    "performance" => [
        "averageNotesPerPermit" => 0
    ],

    "workload" => [
        "oldestOpenApplication" => null,
        "applicationsWithOutstandingFees" => 0,
        "totalOutstandingFees" => 0.00,
        "applicationsWithActiveRequirements" => 0,
        "mostActiveJurisdiction" => null
    ],

    "lastPermitActivity" => [
        "contactId"     => null,
        "contactName"   => null,
        "actionTypeId"  => null,
        "actionName"    => null,
        "applicationID" => null,
        "wo"            => null,
        "customer"      => null,
        "actionUnix"    => null
    ]
];

if (
    $permitProjectionSucceeded &&
    isset($rawApplications) &&
    is_array($rawApplications)
) {
    $applicationCount = count($rawApplications);

    $totalOpenDays = 0;
    $totalNotes = 0;

    $stageBreakdown = [];
    $stageStatusBreakdown = [];

    $nowUnix = time();

    $oldestApplication = null;
    $oldestApplicationAgeDays = null;

    $applicationsWithOutstandingFees = 0;
    $totalOutstandingFees = 0.00;

    $applicationsWithActiveRequirements = 0;

    $jurisdictionCounts = [];

    foreach ($rawApplications as $app) {

        $stageName = trim((string)(
            $app["applicationStageName"] ?? ""
        ));

        $statusName = trim((string)(
            $app["applicationStatusName"] ?? ""
        ));

        // ------------------------------------------------------------
        // Stage Count
        // ------------------------------------------------------------
        if ($stageName !== "") {
            $stageBreakdown[$stageName] =
                ($stageBreakdown[$stageName] ?? 0) + 1;
        }

        // ------------------------------------------------------------
        // Stage / Status Count
        // ------------------------------------------------------------
        $stageStatusKey =
            $stageName . " / " . $statusName;

        if ($stageName !== "" || $statusName !== "") {
            $stageStatusBreakdown[$stageStatusKey] =
                ($stageStatusBreakdown[$stageStatusKey] ?? 0) + 1;
        }

        // ------------------------------------------------------------
        // Open Duration + Oldest Open Application
        // ------------------------------------------------------------
        $createdUnix = (int)(
            $app["applicationCreatedUnix"] ?? 0
        );

        if ($createdUnix > 0) {

            $openSeconds = max(
                0,
                $nowUnix - $createdUnix
            );

            $openDays =
                $openSeconds / 86400;

            $totalOpenDays +=
                $openDays;

            if (
                $oldestApplicationAgeDays === null ||
                $openDays > $oldestApplicationAgeDays
            ) {
                $oldestApplicationAgeDays =
                    $openDays;

                $oldestApplication = [
                    "applicationID" =>
                        (int)($app["applicationID"] ?? 0),

                    "wo" =>
                        (string)($app["orderChristyNumber"] ?? ""),

                    "customer" =>
                        (string)($app["entityName"] ?? ""),

                    "jobsite" =>
                        (string)($app["locationName"] ?? ""),

                    "stage" =>
                        $stageName,

                    "status" =>
                        $statusName,

                    "ageDays" =>
                        round($openDays, 1)
                ];
            }
        }

        // ------------------------------------------------------------
        // Notes
        // ------------------------------------------------------------
        $totalNotes += (int)(
            $app["applicationNoteCount"] ?? 0
        );

        // ------------------------------------------------------------
        // Outstanding Fees
        // ------------------------------------------------------------
        $outstandingFees = (float)(
            $app["applicationFeeTotalOutstanding"] ?? 0
        );

        if ($outstandingFees > 0) {
            $applicationsWithOutstandingFees++;

            $totalOutstandingFees +=
                $outstandingFees;
        }

        // ------------------------------------------------------------
        // Active Special Requirements
        // ------------------------------------------------------------
        $activeRequirementCount = (int)(
            $app["applicationActiveRequirementCount"] ?? 0
        );

        if ($activeRequirementCount > 0) {
            $applicationsWithActiveRequirements++;
        }

        // ------------------------------------------------------------
        // Jurisdiction Workload
        // ------------------------------------------------------------
        $jurisdiction = trim((string)(
            $app["applicationJurisdiction"] ?? ""
        ));

        if ($jurisdiction !== "") {
            $jurisdictionCounts[$jurisdiction] =
                ($jurisdictionCounts[$jurisdiction] ?? 0) + 1;
        }
    }

    // ------------------------------------------------------------
    // Average Open Duration
    // ------------------------------------------------------------
    $averageOpenDays =
        $applicationCount > 0
            ? round(
                $totalOpenDays / $applicationCount,
                1
            )
            : 0;

    // ------------------------------------------------------------
    // Average Notes per Application
    // ------------------------------------------------------------
    $averageNotes =
        $applicationCount > 0
            ? round(
                $totalNotes / $applicationCount,
                1
            )
            : 0;

    // ------------------------------------------------------------
    // Most Active Jurisdiction
    // ------------------------------------------------------------
    $mostActiveJurisdiction = null;

    if (!empty($jurisdictionCounts)) {
        arsort($jurisdictionCounts);

        $jurisdictionName =
            (string)array_key_first($jurisdictionCounts);

        $mostActiveJurisdiction = [
            "jurisdiction" =>
                $jurisdictionName,

            "count" =>
                (int)$jurisdictionCounts[$jurisdictionName]
        ];
    }

    // ------------------------------------------------------------
    // At-a-Glance KPI Projection
    // ------------------------------------------------------------
    $kpi["atAGlance"]["totalActive"] =
        $applicationCount;

    $kpi["atAGlance"]["averageTurnaroundDays"] =
        $averageOpenDays;

    // ------------------------------------------------------------
    // Stage / Status KPI Projection
    // ------------------------------------------------------------
    $kpi["stageBreakdown"] =
        $stageBreakdown;

    $kpi["stageStatusBreakdown"] =
        $stageStatusBreakdown;

    // ------------------------------------------------------------
    // Performance KPI Projection
    // ------------------------------------------------------------
    $kpi["performance"]["averageNotesPerPermit"] =
        $averageNotes;

    // ------------------------------------------------------------
    // Workload KPI Projection
    // ------------------------------------------------------------
    $kpi["workload"] = [
        "oldestOpenApplication" =>
            $oldestApplication,

        "applicationsWithOutstandingFees" =>
            $applicationsWithOutstandingFees,

        "totalOutstandingFees" =>
            round($totalOutstandingFees, 2),

        "applicationsWithActiveRequirements" =>
            $applicationsWithActiveRequirements,

        "mostActiveJurisdiction" =>
            $mostActiveJurisdiction
    ];
}

// ------------------------------------------------------------
// Most Recent Permit Activity — Who / What / Permit / When
// ------------------------------------------------------------
if ($db !== null) {
    try {

        $lastPermitActionStmt = $db->query("
            SELECT
                a.contactId,
                a.actionTypeId,
                a.actionUnix,
                a.actionPayloadData,
                a.actionResponseData,
                t.actionName,
                c.contactFirstName,
                c.contactLastName
            FROM tblActions a
            INNER JOIN tblActionTypes t
                ON t.actionTypeId = a.actionTypeId
            LEFT JOIN tblContacts c
                ON c.contactId = a.contactId
            WHERE t.actionName LIKE 'application.%'
            ORDER BY
                a.actionUnix DESC,
                a.actionId DESC
            LIMIT 50
        ");

        $lastPermitAction =
            $lastPermitActionStmt->fetch(PDO::FETCH_ASSOC);

        if (is_array($lastPermitAction)) {

            // --------------------------------------------------------
            // Resolve Application ID from Action Payload
            // --------------------------------------------------------
            $actionPayload = json_decode(
                (string)(
                    $lastPermitAction["actionPayloadData"] ?? ""
                ),
                true
            );

            $applicationID = 0;

            if (is_array($actionPayload)) {
                $applicationID = (int)(
                    $actionPayload["applicationID"]
                    ?? $actionPayload["requestedApplicationID"]
                    ?? 0
                );
            }

            // --------------------------------------------------------
            // Resolve Actor
            // --------------------------------------------------------
            $contactFirstName =
                trim((string)(
                    $lastPermitAction["contactFirstName"] ?? ""
                ));

            $contactLastName =
                trim((string)(
                    $lastPermitAction["contactLastName"] ?? ""
                ));

            $contactName =
                trim(
                    $contactFirstName .
                    " " .
                    $contactLastName
                );

            // --------------------------------------------------------
            // Resolve Application / WO / Customer
            // --------------------------------------------------------
            $permitActivityApplication = null;

            if ($applicationID > 0) {

                $permitActivityStmt = $db->prepare("
                    SELECT
                        a.applicationID,
                        o.orderChristyNumber,
                        e.entityName
                    FROM tblApplications a
                    INNER JOIN tblOrders o
                        ON o.orderID = a.applicationOrderID
                    INNER JOIN tblEntities e
                        ON e.entityId = a.applicationEntityID
                    WHERE a.applicationID = :applicationID
                    LIMIT 1
                ");

                $permitActivityStmt->bindValue(
                    ":applicationID",
                    $applicationID,
                    PDO::PARAM_INT
                );

                $permitActivityStmt->execute();

                $permitActivityApplication =
                    $permitActivityStmt->fetch(PDO::FETCH_ASSOC);
            }

            // --------------------------------------------------------
            // Populate Last Permit Activity
            // --------------------------------------------------------
            $lastPermitContactId =
                (int)(
                    $lastPermitAction["contactId"] ?? 0
                );

            $lastPermitActionTypeId =
                (int)(
                    $lastPermitAction["actionTypeId"] ?? 0
                );

            $lastPermitActionName =
                trim((string)(
                    $lastPermitAction["actionName"] ?? ""
                ));

            $lastPermitActionUnix =
                (int)(
                    $lastPermitAction["actionUnix"] ?? 0
                );

            $permitActivityWo =
                is_array($permitActivityApplication)
                    ? trim((string)(
                        $permitActivityApplication["orderChristyNumber"]
                        ?? ""
                    ))
                    : "";

            $permitActivityCustomer =
                is_array($permitActivityApplication)
                    ? trim((string)(
                        $permitActivityApplication["entityName"]
                        ?? ""
                    ))
                    : "";

            $kpi["lastPermitActivity"] = [
                "contactId" =>
                    $lastPermitContactId > 0
                        ? $lastPermitContactId
                        : null,

                "contactName" =>
                    $contactName !== ""
                        ? $contactName
                        : null,

                "actionTypeId" =>
                    $lastPermitActionTypeId > 0
                        ? $lastPermitActionTypeId
                        : null,

                "actionName" =>
                    $lastPermitActionName !== ""
                        ? $lastPermitActionName
                        : null,

                "applicationID" =>
                    $applicationID > 0
                        ? $applicationID
                        : null,

                "wo" =>
                    $permitActivityWo !== ""
                        ? $permitActivityWo
                        : null,

                "customer" =>
                    $permitActivityCustomer !== ""
                        ? $permitActivityCustomer
                        : null,

                "actionUnix" =>
                    $lastPermitActionUnix > 0
                        ? $lastPermitActionUnix
                        : null
            ];
        }

    } catch (Throwable $e) {
        error_log(
            "[LAST PERMIT ACTIVITY PROJECTION ERROR] " .
            $e->getMessage()
        );
    }
}

#endregion

#region SECTION 3.C — System Activity Projection (MySQL Database)

$systemActivity = [
    "meta" => [
        "generatedOn" => time(),
        "source"      => "database"
    ],

    "elc" => [
        "entities"  => 0,
        "locations" => 0,
        "contacts"  => 0
    ],

    "actions" => [
        "today" => 0,
        "total" => 0,

        "lastAction" => [
            "contactId"    => null,
            "contactName"  => null,
            "actionTypeId" => null,
            "actionName"   => null,
            "actionUnix"   => null
        ]
    ]
];

if ($db !== null) {
    try {

        // ------------------------------------------------------------
        // Entity Count
        // ------------------------------------------------------------
        $entityCountStmt = $db->query("
            SELECT COUNT(*)
            FROM tblEntities
        ");

        $systemActivity["elc"]["entities"] =
            (int)$entityCountStmt->fetchColumn();

        // ------------------------------------------------------------
        // Location Count
        // ------------------------------------------------------------
        $locationCountStmt = $db->query("
            SELECT COUNT(*)
            FROM tblLocations
        ");

        $systemActivity["elc"]["locations"] =
            (int)$locationCountStmt->fetchColumn();

        // ------------------------------------------------------------
        // Contact Count
        // ------------------------------------------------------------
        $contactCountStmt = $db->query("
            SELECT COUNT(*)
            FROM tblContacts
        ");

        $systemActivity["elc"]["contacts"] =
            (int)$contactCountStmt->fetchColumn();

        // ------------------------------------------------------------
        // Phoenix "Today" Boundary
        // ------------------------------------------------------------
        $phoenixToday = new DateTime(
            "today",
            new DateTimeZone("America/Phoenix")
        );

        $todayStartUnix =
            $phoenixToday->getTimestamp();

        // ------------------------------------------------------------
        // Actions Today
        // ------------------------------------------------------------
        $actionsTodayStmt = $db->prepare("
            SELECT COUNT(*)
            FROM tblActions
            WHERE actionUnix >= :todayStartUnix
        ");

        $actionsTodayStmt->bindValue(
            ":todayStartUnix",
            $todayStartUnix,
            PDO::PARAM_INT
        );

        $actionsTodayStmt->execute();

        $systemActivity["actions"]["today"] =
            (int)$actionsTodayStmt->fetchColumn();

        // ------------------------------------------------------------
        // Total Actions
        // ------------------------------------------------------------
        $actionsTotalStmt = $db->query("
            SELECT COUNT(*)
            FROM tblActions
        ");

        $systemActivity["actions"]["total"] =
            (int)$actionsTotalStmt->fetchColumn();

        // ------------------------------------------------------------
        // Most Recent Action — Who / What / When
        // ------------------------------------------------------------
        $lastActionStmt = $db->query("
            SELECT
                a.contactId,
                a.actionTypeId,
                a.actionUnix,
                t.actionName,
                c.contactFirstName,
                c.contactLastName
            FROM tblActions a
            LEFT JOIN tblActionTypes t
                ON t.actionTypeId = a.actionTypeId
            LEFT JOIN tblContacts c
                ON c.contactId = a.contactId
            ORDER BY
                a.actionUnix DESC,
                a.actionId DESC
            LIMIT 1
        ");

        $lastAction =
            $lastActionStmt->fetch(PDO::FETCH_ASSOC);

        if (is_array($lastAction)) {

            $lastActionContactId =
                (int)($lastAction["contactId"] ?? 0);

            $lastActionTypeId =
                (int)($lastAction["actionTypeId"] ?? 0);

            $lastActionUnix =
                (int)($lastAction["actionUnix"] ?? 0);

            $contactFirstName =
                trim((string)($lastAction["contactFirstName"] ?? ""));

            $contactLastName =
                trim((string)($lastAction["contactLastName"] ?? ""));

            $contactName =
                trim($contactFirstName . " " . $contactLastName);

            $actionName =
                trim((string)($lastAction["actionName"] ?? ""));

            $systemActivity["actions"]["lastAction"] = [
                "contactId" =>
                    $lastActionContactId > 0
                        ? $lastActionContactId
                        : null,

                "contactName" =>
                    $contactName !== ""
                        ? $contactName
                        : null,

                "actionTypeId" =>
                    $lastActionTypeId > 0
                        ? $lastActionTypeId
                        : null,

                "actionName" =>
                    $actionName !== ""
                        ? $actionName
                        : null,

                "actionUnix" =>
                    $lastActionUnix > 0
                        ? $lastActionUnix
                        : null
            ];
        }

    } catch (Throwable $e) {
        error_log(
            "[SYSTEM ACTIVITY DB PROJECTION ERROR] " .
            $e->getMessage()
        );
    }
}

#endregion

#region SECTION 4 — Build Time Context + Weather + Final Payload

// Compute time context
$timeContext = getTimeContext($tz, $systemRegistry, $paths["holiday"]);

// ─────────────────────────────────────────────
// FINAL WEATHER SAFETY BARRIER
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

$weather = $currentWeather;
$weather['forecast'] = $forecastDays;

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
    "siteVersion"    => $versions["system"]["siteVersion"] ?? "unknown",
    "lastUpdateUnix" => $lastUpdateUnix ?: null,
    "updateOccurred" => (bool)($versions["system"]["updateOccurred"] ?? false)
];

if ($lastUpdateUnix > 0) {
    $dt = new DateTime('@' . $lastUpdateUnix);
    $dt->setTimezone(new DateTimeZone('America/Phoenix'));

    $siteMeta["lastUpdateLocal"] =
        $dt->format('Y-m-d h:i:s A');

    $siteMeta["lastUpdateAgeSeconds"] =
        time() - $lastUpdateUnix;
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
    "systemActivity"  => $systemActivity,
    "roadmap"         => $roadmap,

    "activePermits"   => $permitList,
    "activePermitsMeta" => [
        "lastUpdatedUnix" => $lastApplicationUpdatedUnix
    ],

    "permitNews"      => is_array($permitNews) ? $permitNews : null,
    "siteMeta"        => $siteMeta,
    "idle"            => $idle,
    "idleDebug"       => $idleDebug,
    "sentinelMeta"    => $sentinelMeta
];

#endregion

#region SECTION 5 — Output for SSE
return $payload;
#endregion