<?php
declare(strict_types=1);

// Ini Set
ini_set('display_errors', '0');

// Prevent PHP session cache headers from being re-sent during SSE auth refresh
session_cache_limiter('');

// Session Start
session_start();

// ======================================================================
//  Skyesoft — sse.php
//  Version: 1.2.2
//  Last Updated: 2026-03-03
//  Codex Tier: 4 — SSE Engine / Real-Time Projection
//
//  Role:
//  Authoritative real-time stream endpoint for Skyesoft UI.
//  Modes:
//   • Default: Continuous SSE stream (1 Hz) of getDynamicData() payload
//   • ?mode=snapshot : One-time JSON snapshot and exit
//
//  Inputs:
//   • GET: mode (optional)
//   • Dependency: getDynamicData.php (MUST return array $payload)
//
//  Outputs:
//   • SSE: text/event-stream (streaming mode)
//   • JSON: application/json (snapshot mode)
//
//  Forbidden:
//   • No compression (gzip/br) on SSE responses
//   • No buffering that delays SSE delivery
//   • No early output before headers in streaming mode
//
//  Notes:
//   • SSE is sensitive to proxy/CDN transforms; prefer no-transform caching.
//   • Keepalive pings are recommended to prevent upstream timeouts.
// ======================================================================

#region SECTION 0 — Mode Detection
$isSnapshot =
    isset($_GET["mode"])
    && $_GET["mode"] === "snapshot";
#endregion

#region SECTION 1 — Snapshot Mode (Finite, Non-SSE)
if ($isSnapshot) {

    // Capture auth once
    $auth = [
        'authenticated' => ($_SESSION['authenticated'] ?? false) === true,
        'username'      => $_SESSION['username'] ?? null,
        'role'          => $_SESSION['role'] ?? null
    ];

    // Stable idle schema (Phase 1 scaffold)
    $idle = [
        'state'            => 'unknown',
        'remainingSeconds' => null,
        'timeoutSeconds'   => null,
        'lastActivity'     => null
    ];

    // Release session lock immediately
    session_write_close();

    header("Content-Type: application/json; charset=UTF-8");
    header("Cache-Control: no-cache");

    $payload = require __DIR__ . "/getDynamicData.php";
    $payload['auth'] = $auth;
    $payload['idle'] = $idle;

    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}
#endregion

#region SECTION 2 — SSE Headers (Streaming Mode)

// Disable PHP-level compression/buffering
@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', '0');
@ini_set('implicit_flush', '1');

// Disable Apache gzip if possible (does NOT stop br from Cloudflare)
if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', '1');
    @apache_setenv('dont-vary', '1');
}

// Clear buffers (do not flush)
while (ob_get_level() > 0) { @ob_end_clean(); }
@ob_implicit_flush(true);

// Required SSE headers
header('Content-Type: text/event-stream; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate, no-transform');
header('Pragma: no-cache');
header('Expires: 0');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

// Defensive: remove any server-added encoding (won't override Cloudflare br)
header_remove('Content-Encoding');
/* Kickstart streaming to defeat proxy buffering */
echo ":" . str_repeat(" ", 2048) . "\n\n";
@ob_flush();
@flush();
#endregion

#region SECTION 3 — PHP Runtime Controls
set_time_limit(0);
ignore_user_abort(true);
#endregion

#region SECTION 4 — Loop Initialization

// Capture auth snapshot
$auth = [
    'authenticated' => ($_SESSION['authenticated'] ?? false) === true,
    'username'      => $_SESSION['username'] ?? null,
    'role'          => $_SESSION['role'] ?? null
];

// Stable idle schema (Phase 1 scaffold)
$idle = [
    'state'            => 'unknown',
    'remainingSeconds' => null,
    'timeoutSeconds'   => null,
    'lastActivity'     => null
];

// Release session lock so SSE does not block other requests
session_write_close();

$streamId = bin2hex(random_bytes(8));

$lastPing        = 0;
$lastSecond      = 0;
$lastAuthRefresh = 0;   // allows auth state to update without reconnect

#endregion

#region SECTION 5 — SSE 1 Hz Continuous Loop

while (true) {

    // Exit if client disconnects
    if (connection_aborted()) {
        break;
    }

    $now = time();

    // ─────────────────────────────────────────────
    // AUTH REFRESH (force fresh session read)
    // Prevents stale session state in long-running SSE
    // ─────────────────────────────────────────────

    // Close any active session first
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    // Rebind to current browser session cookie
    if (isset($_COOKIE[session_name()])) {
        session_id($_COOKIE[session_name()]);
    }

    // Reopen session
    session_start();

    $auth = [
        'authenticated' => ($_SESSION['authenticated'] ?? false) === true,
        'username'      => $_SESSION['username'] ?? null,
        'role'          => $_SESSION['role'] ?? null
    ];

    // Release lock immediately
    session_write_close();


    // ─────────────────────────────────────────────
    // 15-Second Keepalive Ping (prevents proxy timeouts)
    // ─────────────────────────────────────────────
    if (($now - $lastPing) >= 15) {

        echo ": ping\n\n";
        $lastPing = $now;

        @ob_flush();
        @flush();
    }


    // ─────────────────────────────────────────────
    // 1 Hz Update Cadence
    // ─────────────────────────────────────────────
    if ($now > $lastSecond) {

        $lastSecond = $now;

        // SINGLE SOURCE OF TRUTH
        $payload = require __DIR__ . "/getDynamicData.php";

        // Inject stable state projections
        $payload['auth']     = $auth;
        $payload['idle']     = $idle;
        $payload['streamId'] = $streamId;

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);

        if ($json !== false && $json !== '') {

            echo "data: " . $json . "\n\n";

            @ob_flush();
            @flush();
        }
    }

    // Reduce CPU usage while maintaining responsiveness
    usleep(20000);

}

#endregion