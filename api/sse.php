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
// 🔐 SESSION BOOTSTRAP (CANONICAL — FINAL)
// Must match across ALL endpoints
// ─────────────────────────────────────────

// Force same session name across system
session_name('SKYESOFTSESSID');

// 🔥 FORCE HTTPS COOKIE (stable for GoDaddy)
$secure = true;

// Apply cookie policy BEFORE session binding
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/skyesoft/',
    'domain'   => 'skyelighting.com',
    'secure'   => true,
    'httponly' => true,
    'samesite' => 'Lax'
]);

// 🔥 Attach to existing session BEFORE start
if (!empty($_COOKIE[session_name()])) {
    session_id($_COOKIE[session_name()]);
    error_log('[SSE BOOT] using cookie session_id=' . $_COOKIE[session_name()]);
} else {
    error_log('[SSE BOOT] NO SESSION COOKIE FOUND');
}

// Start session ONCE (no read_and_close here)
session_start();

error_log('[SSE SESSION STARTED] id=' . session_id());

// ─────────────────────────────────────────
// 🔐 LIVE SESSION AUTH LOOKUP
// Reads authoritative PHP session state safely
// ─────────────────────────────────────────
function getLiveSessionAuth(): array
{
    // Release lock
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    // Re-open session
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

    // 🔒 Always release
    session_write_close();

    return $result;
}

// ─────────────────────────────────────────
// 📸 SNAPSHOT MODE DETECTION
// ─────────────────────────────────────────

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
// 🔐 CRITICAL — INITIAL SESSION ATTACH
// MUST occur BEFORE any output is sent
// Ensures SSE binds to correct PHP session
// ─────────────────────────────────────────

$initialSession = getLiveSessionAuth();

// Optional debug (safe during MTCO phase)
error_log('[SSE BOOT] ' . json_encode($initialSession));


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
// INITIAL STREAM PRIMER (DEFERRED)
// Now safe — session already attached
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
    // 🔐 REFRESH LIVE SESSION STATE
    // Each loop tick retrieves the authoritative
    // session truth so SSE reflects real login
    // and logout state.
    // ─────────────────────────────────────────

    $liveSession = getLiveSessionAuth();

    $auth  = $_SESSION['authenticated'] ?? false;
    $ready = $_SESSION['auth_ready'] ?? null;

    $isAuthenticated = ($auth && $ready !== null);
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

        // ─────────────────────────────────────────
        // ⛔ IDLE TIMEOUT ENFORCEMENT
        // Destroy session once timeout threshold
        // is reached.
        // ─────────────────────────────────────────

        // If user is idle and timeout has expired, destroy session
        if ($idleState === 'expired' && $isAuthenticated === true) {

            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }

           if (session_status() !== PHP_SESSION_ACTIVE) {
                session_start();
            }

            $_SESSION = [];

            if (ini_get("session.use_cookies")) {
                setcookie(session_name(), '', [
                    'expires'  => time() - 42000,
                    'path'     => '/',
                    'domain' => $_SERVER['HTTP_HOST'],
                    'secure'   => $secure,
                    'httponly' => true,
                    'samesite' => 'Lax'
                ]);
            }

            session_destroy();
            session_write_close();

            // 🔥 CRITICAL — update runtime state
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
    // 💓 KEEPALIVE PING
    // Prevents connection timeouts
    // ─────────────────────────────────────────

    if (($now - $lastPing) >= 15) {

        echo ": ping\n\n";
        $lastPing = $now;

        @flush();
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
            @flush();
        }
    }

    usleep(100000);
}

#endregion