<?php
declare(strict_types=1);

// ======================================================================
// Skyesoft — sse.php
// Version: 1.3.1
// Real-Time Projection Engine
// ======================================================================

ini_set('display_errors','0');
session_cache_limiter('');

// Ensure session cookie scope matches the entire site
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => true,
    'httponly' => true,
    'samesite' => 'Lax'
]);

// Attach existing browser session
$cookieName = session_name();

if (isset($_COOKIE[$cookieName])) {
    session_id($_COOKIE[$cookieName]);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

session_write_close();

#region SECTION 0 — MODE DETECTION

$isSnapshot =
    isset($_GET["mode"]) &&
    $_GET["mode"] === "snapshot";

#endregion

#region SECTION 1 — SNAPSHOT MODE

if ($isSnapshot) {

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $auth = [
        'authenticated' => !empty($_SESSION['authenticated']),
        'username'      => $_SESSION['username'] ?? null,
        'role'          => $_SESSION['role'] ?? null
    ];

    $sessionId = session_id();

    session_write_close();

    $idle = [
        'state'            => 'unknown',
        'remainingSeconds' => null,
        'timeoutSeconds'   => null,
        'lastActivity'     => null
    ];

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

#region SECTION 2 — SSE HEADERS

@ini_set('zlib.output_compression','0');
@ini_set('output_buffering','0');
@ini_set('implicit_flush','1');

if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip','1');
    @apache_setenv('dont-vary','1');
}

while (ob_get_level() > 0) {
    @ob_end_clean();
}

@ob_implicit_flush(true);

header('Content-Type: text/event-stream; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate, no-transform');
header('Pragma: no-cache');
header('Expires: 0');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

header_remove('Content-Encoding');

echo ":" . str_repeat(" ",2048) . "\n\n";
@flush();

#endregion

#region SECTION 3 — PHP RUNTIME

set_time_limit(0);
ignore_user_abort(true);

#endregion

#region SECTION 4 — STREAM INITIALIZATION

$streamId   = bin2hex(random_bytes(8));
$lastPing   = 0;
$lastSecond = 0;

$idle = [
    'state'            => 'unknown',
    'remainingSeconds' => null,
    'timeoutSeconds'   => null,
    'lastActivity'     => null
];

#endregion

#region SECTION 5 — STREAM LOOP

while (true) {

    if (connection_aborted()) {
        break;
    }

    $now = time();

    // ─────────────────────────────────────────
    // AUTH REFRESH
    // ─────────────────────────────────────────

    if (isset($_COOKIE[$cookieName])) {
        session_id($_COOKIE[$cookieName]);
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $sessionId = session_id();

    $auth = [
        'authenticated' => !empty($_SESSION['authenticated']),
        'username'      => $_SESSION['username'] ?? null,
        'role'          => $_SESSION['role'] ?? null
    ];

    session_write_close();

    // ─────────────────────────────────────────
    // KEEPALIVE PING
    // ─────────────────────────────────────────

    if (($now - $lastPing) >= 15) {

        echo ": ping\n\n";
        $lastPing = $now;

        @flush();
    }

    // ─────────────────────────────────────────
    // DATA UPDATE
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