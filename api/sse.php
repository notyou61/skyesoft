<?php
declare(strict_types=1);

// ======================================================================
// Skyesoft — sse.php
// Version: 1.3.1
// Real-Time Projection Engine
// ======================================================================

#region ⚙️ SECTION 0 — Environment Bootstrap (Runtime Initialization / Session Attach)

ini_set('display_errors', 0);
error_reporting(E_ALL);

session_cache_limiter('');

$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => $secure,
    'httponly' => true,
    'samesite' => 'Lax'
]);

// Start session once
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$sessionId = session_id();

$isAuthenticated = !empty($_SESSION['authenticated']);

$userId = isset($_SESSION['userId'])
    ? (int)$_SESSION['userId']
    : null;

$auth = [
    'authenticated' => $isAuthenticated,
    'username'      => $_SESSION['username'] ?? null,
    'role'          => $_SESSION['role'] ?? null
];

session_write_close();

$isSnapshot =
    isset($_GET['mode']) &&
    $_GET['mode'] === 'snapshot';

#endregion

#region 🧰 SECTION 1 — SESSION HELPERS

// ─────────────────────────────────────────
// ⏱ LAST PROMPT ACTIVITY LOOKUP
// Reads the canonical prompt ledger and finds
// the latest prompt timestamp for the given user.
// This is the authoritative idle source.
// ─────────────────────────────────────────
function getLastPromptActivity(int $userId): ?int
{
    $ledgerFile = __DIR__ . "/../data/authoritative/promptLedger.json";

    if (!file_exists($ledgerFile)) {
        return null;
    }

    $json = file_get_contents($ledgerFile);

    if ($json === false || trim($json) === '') {
        return null;
    }

    $data = json_decode($json, true);

    if (!is_array($data) || !isset($data["entries"])) {
        return null;
    }

    $entries = $data["entries"];
    $latest = null;

    foreach ($entries as $entry) {

        if (!is_array($entry)) {
            continue;
        }

        if ((int)($entry["userId"] ?? 0) !== $userId) {
            continue;
        }

        $timestamp = (int)($entry["createdUnixTime"] ?? 0);

        if ($timestamp <= 0) {
            continue;
        }

        if ($latest === null || $timestamp > $latest) {
            $latest = $timestamp;
        }
    }

    return $latest;
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

    // ─────────────────────────────────────────
    // ATTACH EXISTING SESSION
    // Snapshot requests must explicitly attach
    // to the browser's session cookie.
    // ─────────────────────────────────────────

    $cookieName = session_name();

    if (isset($_COOKIE[$cookieName])) {
        session_id($_COOKIE[$cookieName]);
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $sessionId = session_id();

    $isAuthenticated = !empty($_SESSION['authenticated']);

    $auth = [
        'authenticated' => $isAuthenticated,
        'username'      => $_SESSION['username'] ?? null,
        'role'          => $_SESSION['role'] ?? null
    ];

    // Release session lock immediately
    session_write_close();

    // ─────────────────────────────────────────
    // LOAD FULL PROJECTION PAYLOAD
    // dynamicData.php generates the full system
    // projection including idle state.
    // ─────────────────────────────────────────

    $payload = require __DIR__ . "/getDynamicData.php";

    // Attach session context
    $payload["auth"]      = $auth;
    $payload["streamId"]  = "snapshot";
    $payload["sessionId"] = $sessionId;

    header("Content-Type: application/json; charset=UTF-8");
    header("Cache-Control: no-cache");

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
    // ⏱ LEDGER-BASED LAST ACTIVITY
    // Uses bootstrap auth truth + canonical prompt ledger
    // ─────────────────────────────────────────

    $lastActivity = ($isAuthenticated && $userId)
        ? getLastPromptActivity($userId)
        : null;

    if ($isAuthenticated && $userId && $lastActivity) {

        $idleSeconds = $now - $lastActivity;

        $remaining = max(
            0,
            $idleTimeoutSeconds - $idleSeconds
        );

        if ($remaining <= 0) {
            $idleState = 'expired';
        } elseif ($remaining <= 120) {
            $idleState = 'warning';
        } else {
            $idleState = 'active';
        }

        $idle = [
            'state'            => $idleState,
            'remainingSeconds' => $remaining,
            'timeoutSeconds'   => $idleTimeoutSeconds,
            'lastActivity'     => $lastActivity
        ];

        // ⛔ Idle timeout reached
        // Reopen the real session only when enforcement is needed
        if ($idleState === 'expired' && $isAuthenticated) {

            if (isset($_COOKIE[$cookieName])) {
                session_id($_COOKIE[$cookieName]);
            }

            if (session_status() !== PHP_SESSION_ACTIVE) {
                session_start();
            }

            $_SESSION = [];
            session_destroy();

            session_write_close();

            $isAuthenticated = false;
            $userId = null;

            $auth = [
                'authenticated' => false,
                'username'      => null,
                'role'          => null,
                'reason'        => 'timeout'
            ];
        }

    } elseif ($isAuthenticated) {

        $idle = [
            'state'            => 'unknown',
            'remainingSeconds' => null,
            'timeoutSeconds'   => $idleTimeoutSeconds,
            'lastActivity'     => null
        ];

    } else {

        $idle = [
            'state'            => 'anonymous',
            'remainingSeconds' => null,
            'timeoutSeconds'   => $idleTimeoutSeconds,
            'lastActivity'     => null
        ];
    }

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

        $json = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES
        );

        if ($json !== false && $json !== '') {
            echo "data: " . $json . "\n\n";
            @flush();
        }
    }

    usleep(20000);
}

#endregion