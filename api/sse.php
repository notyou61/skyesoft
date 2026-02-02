<?php
declare(strict_types=1);

// ======================================================================
//  Skyesoft — sse.php
//  Version: 1.2.0
//  Last Updated: 2026-02-02
//  Codex Tier: 4 — SSE Engine
//
//  Modes:
//   • Default: Continuous 1 Hz SSE stream
//   • ?mode=snapshot : One-time JSON snapshot and exit
//
//  SINGLE SOURCE OF TRUTH:
//   getDynamicData.php MUST return $payload (array)
// ======================================================================

#region SECTION 0 — Mode Detection
$isSnapshot =
    isset($_GET["mode"])
    && $_GET["mode"] === "snapshot";
#endregion

#region SECTION 1 — Snapshot Mode (Finite, Non-SSE)
if ($isSnapshot) {

    header("Content-Type: application/json; charset=UTF-8");
    header("Cache-Control: no-cache");

    $payload = require __DIR__ . "/getDynamicData.php";

    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}
#endregion

#region SECTION 2 — SSE Headers (Streaming Mode)
header("Content-Type: text/event-stream");
header("Cache-Control: no-cache");
header("Connection: keep-alive");
header("X-Accel-Buffering: no");  // Prevent proxy buffering
#endregion

#region SECTION 3 — PHP Runtime Controls
set_time_limit(0);
ignore_user_abort(true);
#endregion

#region SECTION 4 — Loop Initialization
$lastSecond = null;
#endregion

#region SECTION 5 — SSE 1 Hz Continuous Loop
while (true) {

    if (connection_aborted()) {
        break;
    }

    $now = time();

    if ($now === $lastSecond) {
        usleep(20000); // 20ms backoff
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