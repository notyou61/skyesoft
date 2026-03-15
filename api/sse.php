<?php
declare(strict_types=1);

// ======================================================================
// Skyesoft — sse.php
// Version: 1.3.1
// Real-Time Projection Engine
// ======================================================================

#region ⚙️ SECTION 0 — RUNTIME BOOTSTRAP & MODE DETECTION

// ─────────────────────────────────────────
// PHP RUNTIME SETTINGS
// ─────────────────────────────────────────

ini_set('display_errors','0');
session_cache_limiter('');


// ─────────────────────────────────────────
// SESSION COOKIE POLICY
// Ensures SSE + API endpoints share the same cookie scope
// ─────────────────────────────────────────

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => true,
    'httponly' => true,
    'samesite' => 'Lax'
]);


// ─────────────────────────────────────────
// ATTACH EXISTING SESSION (if present)
// ─────────────────────────────────────────

$cookieName = session_name();

if (isset($_COOKIE[$cookieName])) {
    session_id($_COOKIE[$cookieName]);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

session_write_close();


// ─────────────────────────────────────────
// MODE DETECTION
// Snapshot mode returns a single JSON payload
// instead of opening the SSE stream
// ─────────────────────────────────────────

$isSnapshot =
    isset($_GET["mode"]) &&
    $_GET["mode"] === "snapshot";

#endregion

#region 🧰 SECTION 1 — SESSION HELPERS

// ⏱ Update Last Activity
// Touches session activity only when user is authenticated.
// Useful for authenticated API endpoints or future manual touch hooks.
function updateLastActivity(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (!empty($_SESSION['authenticated'])) {
        $_SESSION['lastActivity'] = time();
    }

    session_write_close();
}

#endregion

#region ⏳ SECTION 2 — SESSION IDLE POLICY

// ─────────────────────────────────────────
// ⏱ IDLE SESSION TIMEOUT
// Production: 900 seconds (15 minutes)
// Test mode: 60 seconds
// ─────────────────────────────────────────

// ======================================================================
// ⏱ Idle Session Policy
// MTCO Test Mode — Timeout temporarily reduced to 60 seconds
// to verify server-side idle termination behavior.
// Restore to 900 seconds after validation.
// ======================================================================

define('SKYESOFT_IDLE_TIMEOUT', 60);   // TEMP TEST VALUE
$idleTimeoutSeconds = SKYESOFT_IDLE_TIMEOUT;

#endregion

#region 📸 SECTION 3 — SNAPSHOT MODE

if ($isSnapshot) {

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $sessionId = session_id();

    $auth = [
        'authenticated' => !empty($_SESSION['authenticated']),
        'username'      => $_SESSION['username'] ?? null,
        'role'          => $_SESSION['role'] ?? null
    ];

    $idle = [
        'state'            => 'unknown',
        'remainingSeconds' => null,
        'timeoutSeconds'   => $idleTimeoutSeconds,
        'lastActivity'     => $_SESSION['lastActivity'] ?? null
    ];

    session_write_close();

    header("Content-Type: application/json; charset=UTF-8");
    header("Cache-Control: no-cache");

    $payload = require __DIR__ . "/getDynamicData.php";

    $payload["auth"]      = $auth;
    $payload["idle"]      = $idle;
    $payload["sessionId"] = $sessionId;

    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

#endregion

#region 📡 SECTION 4 — SSE HEADERS

// ─────────────────────────────────────────
// STREAM OUTPUT CONFIGURATION
// SSE requires a raw, uncompressed, continuously
// flushing output stream. Any buffering or
// compression will corrupt the event stream.
// ─────────────────────────────────────────

// Disable PHP compression and buffering
@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', '0');
@ini_set('implicit_flush', '1');

// Disable Apache / LiteSpeed gzip if available
if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', '1');
    @apache_setenv('dont-vary', '1');
}

// Clear all active output buffers
while (ob_get_level() > 0) {
    @ob_end_clean();
}

// Force implicit flushing
@ob_implicit_flush(true);


// ─────────────────────────────────────────
// SSE RESPONSE HEADERS
// These headers ensure the browser treats the
// response as a persistent event stream and
// prevents proxy or CDN buffering.
// ─────────────────────────────────────────

header('Content-Type: text/event-stream; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate, no-transform');
header('Pragma: no-cache');
header('Expires: 0');
header('Connection: keep-alive');

// Prevent proxy buffering (NGINX / reverse proxies)
header('X-Accel-Buffering: no');

// Prevent compression which breaks SSE
header('Content-Encoding: none');
header_remove('Content-Encoding');


// ─────────────────────────────────────────
// INITIAL STREAM PRIMER
// Sends padding to force the browser to begin
// processing the stream immediately.
// ─────────────────────────────────────────

echo ":" . str_repeat(" ", 2048) . "\n\n";

@flush();

#endregion

#region ⚙️ SECTION 5 — PHP RUNTIME

set_time_limit(0);
ignore_user_abort(true);

#endregion

#region 🚀 SECTION 6 — STREAM INITIALIZATION

$streamId   = bin2hex(random_bytes(8));
$lastPing   = 0;
$lastSecond = 0;

$idle = [
    'state'            => 'unknown',
    'remainingSeconds' => null,
    'timeoutSeconds'   => $idleTimeoutSeconds,
    'lastActivity'     => null
];

#endregion

#region 🔄 SECTION 7 — STREAM LOOP

while (true) {

    if (connection_aborted()) {
        break;
    }

    $now = time();

    // ─────────────────────────────────────────
    // 🔐 AUTH REFRESH + IDLE TIMEOUT
    // ─────────────────────────────────────────

    if (isset($_COOKIE[$cookieName])) {
        session_id($_COOKIE[$cookieName]);
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $sessionId = session_id();

    $isAuthenticated = !empty($_SESSION['authenticated']);
    $lastActivity    = $_SESSION['lastActivity'] ?? null;

    $auth = [
        'authenticated' => $isAuthenticated,
        'username'      => $_SESSION['username'] ?? null,
        'role'          => $_SESSION['role'] ?? null
    ];

    // ⏱ Authenticated Idle State
    if ($isAuthenticated && $lastActivity) {

        $idleSeconds = $now - (int)$lastActivity;
        $remaining   = max(0, $idleTimeoutSeconds - $idleSeconds);

        $idle = [
            'state'            => $remaining > 0 ? 'active' : 'expired',
            'remainingSeconds' => $remaining,
            'timeoutSeconds'   => $idleTimeoutSeconds,
            'lastActivity'     => $lastActivity
        ];

        // ⛔ Idle timeout reached
        if ($remaining <= 0) {

            $_SESSION = [];
            session_destroy();

            $auth = [
                'authenticated' => false,
                'reason'        => 'timeout'
            ];
        }

    } else {

        // 👤 Anonymous / Logged-Out State
        $idle = [
            'state'            => 'anonymous',
            'remainingSeconds' => null,
            'timeoutSeconds'   => $idleTimeoutSeconds,
            'lastActivity'     => null
        ];
    }

    session_write_close();

    // ─────────────────────────────────────────
    // 💓 KEEPALIVE PING
    // ─────────────────────────────────────────

    if (($now - $lastPing) >= 15) {

        echo ": ping\n\n";
        $lastPing = $now;

        @flush();
    }

    // ─────────────────────────────────────────
    // 📦 1 HZ DATA UPDATE
    // ─────────────────────────────────────────

    if ($now > $lastSecond) {

        $lastSecond = $now;

        $payload = require __DIR__ . "/getDynamicData.php";

        $payload["auth"]      = $auth;
        $payload["idle"]      = $idle;
        $payload["streamId"]  = $streamId;
        $payload["sessionId"] = $sessionId;

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);

        if ($json !== false && $json !== '') {

            echo "data: " . $json . "\n\n";

            @flush();
        }
    }

    usleep(20000);
}

#endregion