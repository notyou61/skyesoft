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

#region SECTION 0 — SSE Headers
header("Content-Type: text/event-stream");
header("Cache-Control: no-cache");
header("Connection: keep-alive");
header("X-Accel-Buffering: no");  // Prevent proxy buffering
#endregion

#region SECTION 1 — PHP Runtime Controls (No Timeout, No Abort)
set_time_limit(0);
ignore_user_abort(true);
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

    if ($now === $lastSecond) {
        usleep(20000);
        continue;
    }

    $lastSecond = $now;

    // SINGLE SOURCE OF TRUTH
    $payload = require __DIR__ . "/getDynamicData.php";

    echo "data: " . json_encode($payload, JSON_UNESCAPED_SLASHES) . "\n\n";

    @ob_flush();
    flush();
}
#endregion