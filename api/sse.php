<?php
declare(strict_types=1);

// ======================================================================
//  Skyesoft — sse.php
//  Version: 1.0.0
//  Last Updated: 2025-11-29
//  Codex Tier: 4 — Backend Module (SSE Engine)
//  Dedicated SSE Stream Engine (1 Hz, No Drift)
//  Uses: getDynamicData.php registries + helper functions
// ======================================================================

#region SECTION 0 — SSE Headers
header("Content-Type: text/event-stream");
header("Cache-Control: no-cache");
header("Connection: keep-alive");
#endregion

#region SECTION 1 — Load Backend Module
require_once __DIR__ . "/getDynamicData.php";
#endregion

#region SECTION 2 — Loop Initialization
$lastSecond = null;
#endregion

#region SECTION 3 — SSE 1 Hz Continuous Loop
while (true) {

    if (connection_aborted()) {
        break;
    }

    $now = time();

    // Enforce 1-Hz cycle
    if ($now === $lastSecond) {
        usleep(20000); // 20ms micro-wait
        continue;
    }
    $lastSecond = $now;
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
        "weather"          => $currentWeather,
        "holiday"          => $holidayState,
        "kpi"              => $kpiFull,
        "activePermits"    => $activePermitsFull["activePermits"],
        "announcements"    => $announcementsFull["announcements"],
        "siteMeta"         => $siteMeta,
        "pulse"            => $pulse,
        "connectionStatus" => "connected"
    ];
#endregion

#region SECTION 8 — Stream Output (event: update)
    echo "event: update\n";
    echo "data: " . json_encode($payload, JSON_UNESCAPED_SLASHES) . "\n\n";
    echo ": 🫀 keep-alive\n\n"; // SSE heartbeat comment

    @ob_flush();
    @flush();
} // <-- closes while(true)
#endregion

#region SECTION END — Stream Terminated
exit;
#endregion