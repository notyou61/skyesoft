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

$envFile = file_exists($envPathPrimary)
    ? $envPathPrimary
    : (file_exists($envPathLocal) ? $envPathLocal : null);

if ($envFile === null) {
    throw new RuntimeException("Missing WEATHER env file");
}

// Tolerant .env parser
$env = [];
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || $line[0] === ';') continue;
    if (!str_contains($line, '=')) continue;

    [$k, $v] = explode('=', $line, 2);
    $env[trim($k)] = trim($v, "\"'");
}

$weatherKey = $env['WEATHER_API_KEY'] ?? null;
if (!$weatherKey) {
    throw new RuntimeException("Missing WEATHER_API_KEY");
}

// ─────────────────────────────────────────────
// Coordinates (Phoenix) + endpoint
// ─────────────────────────────────────────────

$lat = (float) ($systemRegistry['weather']['latitude']  ?? 33.4484);
$lon = (float) ($systemRegistry['weather']['longitude'] ?? -112.0740);

$oneCallUrl =
    "https://api.openweathermap.org/data/3.0/onecall" .
    "?lat={$lat}&lon={$lon}" .
    "&exclude=minutely,hourly,alerts" .
    "&units=imperial" .
    "&appid={$weatherKey}";

// ─────────────────────────────────────────────
// Defaults (guaranteed shape)
// ─────────────────────────────────────────────

$currentWeather = [
    'temp'             => null,
    'condition'        => null,
    'icon'             => null,
    'sunrise'          => null,
    'sunset'           => null,
    'sunriseUnix'      => null,
    'sunsetUnix'       => null,
    'daylightSeconds'  => null,
    'nightSeconds'     => null,
    'source'           => 'openweathermap-unavailable'
];

$forecastDays = []; // 🔑 always defined

// ─────────────────────────────────────────────
// Fetch weather (current + daily)
// ─────────────────────────────────────────────

$ctx = stream_context_create([
    'http' => [
        'timeout' => $systemRegistry['api']['timeoutSeconds'] ?? 6
    ]
]);

$response = @file_get_contents($oneCallUrl, false, $ctx);
$weatherData = $response ? json_decode($response, true) : null;

if ($weatherData && isset($weatherData['current'])) {

    // ───────── CURRENT WEATHER ─────────

    $sunriseUnix = (int) $weatherData['current']['sunrise'];
    $sunsetUnix  = (int) $weatherData['current']['sunset'];

    $daylightSeconds = max(0, $sunsetUnix - $sunriseUnix);
    $nightSeconds    = max(0, (86400 - $daylightSeconds));

    $currentWeather = [
        'temp'            => round($weatherData['current']['temp']),
        'condition'       => $weatherData['current']['weather'][0]['description'] ?? null,
        'icon'            => $weatherData['current']['weather'][0]['icon'] ?? null,

        // Display strings (Phoenix-safe)
        'sunrise'         => date('g:i A', $sunriseUnix),
        'sunset'          => date('g:i A', $sunsetUnix),

        // Canonical values
        'sunriseUnix'     => $sunriseUnix,
        'sunsetUnix'      => $sunsetUnix,
        'daylightSeconds' => $daylightSeconds,
        'nightSeconds'    => $nightSeconds,

        'source'          => 'openweathermap'
    ];

    // ───────── 3-DAY FORECAST ─────────

    $labels = ['Today', 'Tomorrow'];

    for ($i = 0; $i < 3; $i++) {
        if (!isset($weatherData['daily'][$i])) continue;

        $d = $weatherData['daily'][$i];

        $forecastDays[] = [
            'dateUnix'  => $d['dt'],
            'label'     => $labels[$i] ?? date('l', $d['dt']),
            'high'      => round($d['temp']['max']),
            'low'       => round($d['temp']['min']),
            'condition' => $d['weather'][0]['description'] ?? null,
            'icon'      => $d['weather'][0]['icon'] ?? null
        ];
    }
}

#endregion

#region SECTION 4 — Build Time Context + Weather + Final Payload

// Compute time context
$timeContext = getTimeContext($tz, $systemRegistry, $paths["holiday"]);

// Weather already fetched above; just assemble final structure
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