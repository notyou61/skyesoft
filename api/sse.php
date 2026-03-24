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

// ─────────────────────────────────────────
// 🔐 SESSION BOOTSTRAP (CANONICAL)
// Must match across ALL endpoints
// ─────────────────────────────────────────

session_name('SKYESOFTSESSID');

$secure = true;

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/skyesoft/',
    'domain'   => 'skyelighting.com',
    'secure'   => true,
    'httponly' => true,
    'samesite' => 'Lax'
]);

// Attach to existing session BEFORE start
if (!empty($_COOKIE[session_name()])) {
    session_id($_COOKIE[session_name()]);
    error_log('[SSE BOOT] using cookie session_id=' . $_COOKIE[session_name()]);
} else {
    error_log('[SSE BOOT] NO SESSION COOKIE FOUND');
}

// Start session ONCE
session_start();

// Debug
file_put_contents(
    __DIR__ . '/sse_debug.log',
    "[SSE SESSION ID] " . session_id() . PHP_EOL .
    "[SSE SESSION DATA] " . json_encode($_SESSION) . PHP_EOL .
    "------------------------" . PHP_EOL,
    FILE_APPEND
);

// 🔐 LIVE SESSION AUTH LOOKUP
function getLiveSessionAuth(): array
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    session_start();

    $sessionId = session_id();
    $isAuth = !empty($_SESSION['authenticated']);

    $result = [
        'authenticated' => $isAuth,
        'userId'        => $isAuth ? (int)($_SESSION['userId'] ?? 0) : null,
        'username'      => $isAuth ? (string)($_SESSION['username'] ?? '') : null,
        'role'          => $isAuth ? (string)($_SESSION['role'] ?? 'user') : null,
        'sessionId'     => $sessionId
    ];

    session_write_close();

    return $result;
}

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

    if (!is_array($data) || !isset($data["entries"]) || !is_array($data["entries"])) {
        return null;
    }

    $entries = $data["entries"];

    // Scan from newest entry backwards
    for ($i = count($entries) - 1; $i >= 0; $i--) {

        $entry = $entries[$i];

        if (!is_array($entry)) {
            continue;
        }

        // Only consider events from this user
        if ((int)($entry["userId"] ?? 0) !== $userId) {
            continue;
        }

        // Ignore logout events
        $intent = $entry["intent"] ?? "";
        if ($intent === "ui_logout") {
            continue;
        }

        $timestamp = (int)($entry["createdUnixTime"] ?? 0);

        if ($timestamp > 0) {
            return $timestamp;
        }
    }

    return null;
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

define('SKYESOFT_IDLE_TIMEOUT', 900);   // TEMP TEST VALUE
$idleTimeoutSeconds = SKYESOFT_IDLE_TIMEOUT;

#endregion

#region 📸 SECTION 3 — SNAPSHOT MODE

if ($isSnapshot) {

    // ─────────────────────────────────────────
    // ATTACH EXISTING SESSION
    // Snapshot requests must explicitly attach
    // to the browser's session cookie.
    // ─────────────────────────────────────────

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
// 🔐 CRITICAL — INITIAL SESSION ATTACH
// MUST occur BEFORE any output is sent
// Ensures SSE binds to correct PHP session
// ─────────────────────────────────────────

$initialSession = getLiveSessionAuth();

// Optional debug (safe during MTCO phase)
error_log('[SSE BOOT] ' . json_encode($initialSession));


// ─────────────────────────────────────────
// SSE RESPONSE HEADERS (Clean + Stable)
// ─────────────────────────────────────────

header('Content-Type: text/event-stream; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate, no-transform');
header('Connection: keep-alive');

// 🔥 Prevent proxy buffering (NGINX / LiteSpeed)
header('X-Accel-Buffering: no');

// 🔥 LiteSpeed / GoDaddy anti-buffering
header('X-LiteSpeed-Cache-Control: no-cache');
header('X-LiteSpeed-Cache: no-cache');

// 🔥 Disable compression at PHP level
@ini_set('zlib.output_compression', 0);

// ─────────────────────────────────────────
// INITIAL STREAM PRIMER (SAFE + VALID)
// ─────────────────────────────────────────

echo ": connected\n\n";

// 🔥 Force LiteSpeed flush
echo str_repeat(" ", 1024) . "\n";

if (function_exists('ob_flush')) {
    @ob_flush();
}
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
    // 🔐 REFRESH LIVE SESSION STATE
    // Each loop tick retrieves the authoritative
    // session truth so SSE reflects real login
    // and logout state.
    // ─────────────────────────────────────────

    $liveSession = getLiveSessionAuth();

    $isAuthenticated = $liveSession['authenticated'];
    $userId          = $liveSession['userId'];
    $sessionId       = $liveSession['sessionId'];

    $auth = [
        'authenticated' => $liveSession['authenticated'],
        'username'      => $liveSession['username'],
        'role'          => $liveSession['role']
    ];

    // ─────────────────────────────────────────
    // ⏱ LEDGER-BASED LAST ACTIVITY
    // Uses canonical prompt ledger to determine
    // idle state for authenticated users.
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
        
        // Idle Notice Conditional
        if ($remaining <= 0) {

            $idleState = 'expired';

        } elseif ($remaining <= 120) {

            $idleState = 'warning';

        } elseif ($idleSeconds >= 60 && $idleSeconds <= 70) {

            $idleState = 'idle-active';

        } else {

            $idleState = 'active';
        }

        $idle = [
            'state'            => $idleState,
            'remainingSeconds' => $remaining,
            'timeoutSeconds'   => $idleTimeoutSeconds,
            'lastActivity'     => $lastActivity
        ];

        // ⛔ IDLE TIMEOUT ENFORCEMENT (SSE-safe)

        // DO NOT touch PHP session here
        if ($idleState === 'expired' && $isAuthenticated === true) {

            // 🔥 DO NOT reopen or destroy session in SSE

            // Just update runtime state
            $isAuthenticated = false;
            $userId = null;

            $auth = [
                'authenticated' => false,
                'username'      => null,
                'role'          => null,
                'reason'        => 'timeout'
            ];

            $idle = [
                'state'            => 'expired',
                'remainingSeconds' => 0,
                'timeoutSeconds'   => $idleTimeoutSeconds,
                'lastActivity'     => $lastActivity
            ];
        }

    } elseif ($isAuthenticated) {

        // Authenticated but no ledger activity yet
        $idle = [
            'state'            => 'active',
            'remainingSeconds' => $idleTimeoutSeconds,
            'timeoutSeconds'   => $idleTimeoutSeconds,
            'lastActivity'     => null
        ];

    } else {

        // Anonymous user
        $idle = [
            'state'            => 'anonymous',
            'remainingSeconds' => null,
            'timeoutSeconds'   => $idleTimeoutSeconds,
            'lastActivity'     => null
        ];
    }

    // ─────────────────────────────────────────
    // 💓 KEEPALIVE PING (LiteSpeed-safe)
    // ─────────────────────────────────────────

    if (($now - $lastPing) >= 15) {

        echo ": ping\n\n";

        // 🔥 CRITICAL — break LiteSpeed buffering
        echo str_repeat(" ", 512) . "\n";

        if (function_exists('ob_flush')) {
            @ob_flush();
        }
        @flush();

        $lastPing = $now;
    }

    // ─────────────────────────────────────────
    // 📦 1HZ DATA UPDATE
    // Sends dynamic projection payload
    // ─────────────────────────────────────────

    if ($now > $lastSecond) {

        $lastSecond = $now;

        $payload = require __DIR__ . "/getDynamicData.php";

        $payload["auth"]      = $auth;
        $payload["idle"]      = $idle;
        $payload["streamId"]  = $streamId;
        $payload["sessionId"] = $sessionId;
        $payload["authDebug"] = [
            "sessionId"     => $sessionId,
            "authenticated" => $auth["authenticated"] ?? false
        ];

        $json = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES
        );

        if ($json !== false && $json !== '') {
            echo "data: " . $json . "\n\n";

            // 🔥 CRITICAL: break LiteSpeed buffering
            echo str_repeat(" ", 1024) . "\n";

            if (function_exists('ob_flush')) {
                @ob_flush();
            }
            @flush();
        }
    }

    usleep(100000);
}

#endregion