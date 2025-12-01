<?php
declare(strict_types=1);

// ======================================================================
//  Skyesoft — sse.php
//  Version: 1.1.0
//  Last Updated: 2025-12-01
//  Codex Tier: 4 — SSE Engine
//  Continuous 1 Hz SSE Stream (No Drift, Stateless Cycle)
//  getDynamicData.php MUST return $payload (array).
// ======================================================================

#region SECTION 1 — SSE Headers
header("Content-Type: text/event-stream");
header("Cache-Control: no-cache");
header("Connection: keep-alive");
header("X-Accel-Buffering: no");  // Prevent proxy buffering
#endregion


#region SECTION 2 — Loop Initialization
$lastSecond = null;
#endregion


#region SECTION 3 — SSE 1 Hz Continuous Loop
while (true) {

    // Terminate if client disconnects
    if (connection_aborted()) {
        break;
    }

    $now = time();

    // Enforce exact 1 Hz refresh rate
    if ($now === $lastSecond) {
        usleep(20000); // 20 ms micro-wait
        continue;
    }
    $lastSecond = $now;

    // Pull fresh dynamic data snapshot
    $payload = require __DIR__ . "/getDynamicData.php";

    // Emit SSE message
    echo "data: " . json_encode($payload, JSON_UNESCAPED_SLASHES) . "\n\n";

    // Flush immediately
    @ob_flush();
    flush();
}
#endregion

#region SECTION 4 — Time & Interval (TIS Engine)
    $timeContext     = getTimeContext($tz, $systemRegistry, $paths["holiday"]);
    $calendarType    = $timeContext["calendarType"];
    $currentInterval = $timeContext["currentInterval"];
    $timeDateArray   = $timeContext["timeDateArray"];
    $holidayState    = $timeContext["holidayState"];
#endregion

#region SECTION 5 — Weather (Cached)
    $currentWeather = getWeatherCached(
        $now,
        $baseOW,
        (float)$lat,
        (float)$lon,
        $weatherKey
    );
#endregion

#region SECTION 6 — Site Meta & Pulse Metrics
    $siteMeta = [
        "siteVersion"  => $versions["system"]["siteVersion"],
        "codexVersion" => $versions["codex"]["version"],
        "deployTime"   => $versions["system"]["deployTime"],
        "commitHash"   => $versions["system"]["commitHash"],
        "lastUpdate"   => date(DateTime::ATOM)
    ];

    $deployUnix = strtotime($versions["system"]["deployTime"]) ?: $now;
    $pulse = [
        "streamHealth"  => "healthy",
        "uptimeSeconds" => $now - $deployUnix
    ];
#endregion

#region SECTION 7 — Payload Assembly
$payload = [
    "calendarType"     => $calendarType,
    "currentInterval"  => $currentInterval,
    "timeDateArray"    => $timeDateArray,

    // weather snapshot
    "weather"          => $currentWeather,

    // correct field name (not "holiday")
    "holidayState"     => $holidayState,

    // KPI normalized
    "kpi"              => $kpi,

    // flattened permit records
    "activePermits"    => $activePermits,

    // flattened announcements
    "announcements"    => $announcements,

    // site metadata
    "siteMeta"         => $siteMeta,

    // heartbeat information
    "pulse"            => $pulse,

    // required for SSE health debugging
    "connectionStatus" => "connected"
];
#endregion

#region SECTION 8 — Stream Output (event: update)
    echo "event: update\n";
    echo "data: " . json_encode($payload, JSON_UNESCAPED_SLASHES) . "\n\n";
    echo ": 🫀 keep-alive\n\n"; // SSE heartbeat comment

    @ob_flush();
    @flush();
// <-- closes while(true)
#endregion

#region SECTION END — Stream Terminated
exit;
#endregion