<?php
declare(strict_types=1);

// ======================================================================
// Skyesoft — sse.php
// Version: 1.3.1
// Real-Time Projection Engine
// ======================================================================

// ─────────────────────────────────────────
// 🧪 LOCAL DEBUG LOGGER (FILE-BASED)
// ─────────────────────────────────────────
//

#region ⚙️ SECTION 0 — Environment Bootstrap (Runtime Initialization / Session Attach)

ini_set('display_errors', 0);
error_reporting(E_ALL);

session_cache_limiter('');

// ─────────────────────────────────────────
// ⚙️ BOOTSTRAP / INCLUDES
// ─────────────────────────────────────────

require_once __DIR__ . '/auth.php'; // ✅ REQUIRED
require_once __DIR__ . '/dbConnect.php'; // ✅ REQUIRED

// ─────────────────────────────────────────
// 🔐 SESSION BOOTSTRAP (CANONICAL)
// Must match across ALL endpoints
// ─────────────────────────────────────────

session_name('SKYESOFTSESSID');

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
    ),
    'httponly' => true,
    'samesite' => 'None'
]);

// ─────────────────────────────────────────
// 🔐 ATTACH SESSION (CRITICAL FIX)
// ─────────────────────────────────────────
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$initialSession = [
    'authenticated' => !empty($_SESSION['authenticated']),
    'contactId'     => $_SESSION['contactId'] ?? null,
    'username'      => $_SESSION['username'] ?? null,
    'role'          => $_SESSION['role'] ?? 'user',
    'sessionId'     => session_id()
];

// 🔒 Frozen auth context (SSE-safe)
$contactIdForLog = $initialSession['contactId'] ?? null;
$sessionIdForLog = $initialSession['sessionId'] ?? null;

// Debug (safe for SSE)
error_log('[SSE BOOT] ' . json_encode($initialSession));

session_write_close();

// Optional debug BEFORE attach
if (!empty($_COOKIE[session_name()])) {
    error_log('[SSE BOOT] session cookie present');
}


// ─────────────────────────────────────────
// 🔐 SESSION ACCESS (SSE SAFE — READ ONLY)
// ─────────────────────────────────────────

// 👤 Resolve Contact Name (shared)
function getContactName(?int $contactId): array {

    if ($contactId === null) {
        return ['firstName' => null, 'lastName' => null];
    }

    try {

        $pdo = getPDO();

        $stmt = $pdo->prepare("
            SELECT contactFirstName, contactLastName
            FROM tblContacts
            WHERE contactId = :id
            LIMIT 1
        ");

        $stmt->execute([':id' => $contactId]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'firstName' => $row['contactFirstName'] ?? null,
            'lastName'  => $row['contactLastName'] ?? null
        ];

    } catch (Throwable $e) {
        error_log('[NAME RESOLVE ERROR] ' . $e->getMessage());
        return ['firstName' => null, 'lastName' => null];
    }
}

$isSnapshot =
    isset($_GET['mode']) &&
    $_GET['mode'] === 'snapshot';

// ─────────────────────────────────────────
// ⏱ IDLE SESSION TIMEOUT
// Production: 900 seconds (15 minutes)
// ─────────────────────────────────────────

define('SKYESOFT_IDLE_TIMEOUT', 30);
$idleTimeoutSeconds = SKYESOFT_IDLE_TIMEOUT;

#endregion

#region 📸 SECTION 1 — SNAPSHOT MODE

if ($isSnapshot) {

    // ─────────────────────────────────────────
    // ATTACH EXISTING SESSION
    // Snapshot requests must explicitly attach
    // to the browser's session cookie.
    // ─────────────────────────────────────────

    $sessionId = session_id();

    $isAuthenticated = !empty($_SESSION['authenticated']);

    // 🔐 AUTH BUILD (ENHANCED WITH NAME)
    $auth = [
        'authenticated' => !empty($_SESSION['authenticated']),
        'contactId'     => $_SESSION['contactId'] ?? null,
        'username'      => $_SESSION['username'] ?? null,
        'role'          => $_SESSION['role'] ?? 'user'
    ];

    // 👤 ADD NAME RESOLUTION
    $name = getContactName($auth['contactId']);

    $auth['firstName'] = $name['firstName'];
    $auth['lastName']  = $name['lastName'];

    // Inject context
    $SKYE_CONTEXT = [
        'auth' => $auth
    ];

    // Release session lock immediately
    session_write_close();

    // Load payload
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

#region 📡 SECTION 2 — SSE HEADERS

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

$liveSession = $initialSession;

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
echo "retry: 3000\n\n";

if (function_exists('ob_flush')) {
    @ob_flush();
}
@flush();

#endregion

#region ⚙️ SECTION 3 — PHP RUNTIME

set_time_limit(0);
ignore_user_abort(true);

#endregion

#region 🚀 SECTION 4 — STREAM INITIALIZATION

$streamId   = bin2hex(random_bytes(8));
$logoutLogged = false; // 🔒 prevent duplicate logs
$lastPing   = 0;
$lastSecond = 0;

#endregion

#region 🔄 SECTION 5 — STREAM LOOP

while (true) {

    if (connection_aborted()) {
        break;
    }

    $now = time();

    // ─────────────────────────────────────────
    // 💓 KEEPALIVE PING
    // ─────────────────────────────────────────
    if (($now - $lastPing) >= 15) {

        echo ": ping\n\n";

        if (function_exists('ob_flush')) {
            @ob_flush();
        }
        @flush();

        $lastPing = $now;
    }

    // ─────────────────────────────────────────
    // 📦 1HZ DATA UPDATE
    // ─────────────────────────────────────────
    if ($now > $lastSecond) {

        $lastSecond = $now;

        // 🔄 RE-ATTACH SESSION FIRST
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $sessionId = session_id();

        // 🔥 CAPTURE ONCE (CRITICAL)
        $wasAuthenticated = !empty($_SESSION['authenticated']);
        $contactId        = $_SESSION['contactId'] ?? null;

        // 🔐 AUTH BUILD
        $auth = [
            'authenticated' => $wasAuthenticated,
            'contactId'     => $contactId,
            'username'      => $_SESSION['username'] ?? null,
            'role'          => $_SESSION['role'] ?? 'user'
        ];

        // 👤 NAME RESOLUTION
        if ($contactId) {
            $name = getContactName($contactId);
            $auth['firstName'] = $name['firstName'];
            $auth['lastName']  = $name['lastName'];
        } else {
            $auth['firstName'] = null;
            $auth['lastName']  = null;
        }

        // ─────────────────────────────────────────
        // ⏱ IDLE STATE (CLEAN + SINGLE SOURCE)
        // ─────────────────────────────────────────

        $lastActivity = $_SESSION['lastActivity'] ?? null;

        // 🔒 Initialize defaults
        $elapsed   = null;
        $remaining = null;
        $idleState = 'inactive';

        if ($lastActivity !== null) {

            $elapsed   = $now - $lastActivity;
            $remaining = max(0, $idleTimeoutSeconds - $elapsed);

            if ($remaining <= 0) {
                $idleState = 'expired';
            } elseif ($remaining <= 60) {
                $idleState = 'warning';
            } else {
                $idleState = 'active';
            }
        }

        $idle = [
            'state'            => $idleState,
            'remainingSeconds' => $remaining,
            'lastActivity'     => $lastActivity,
            'timeoutSeconds'   => $idleTimeoutSeconds
        ];

        // ─────────────────────────────────────────
        // 🔒 HANDLE IDLE LOGOUT
        // ─────────────────────────────────────────
        if ($idleState === 'expired' && $contactIdForLog && !$logoutLogged && $wasAuthenticated && $lastActivity !== null) {

            try {

                //
                $pdo = getPDO();

                if ($pdo instanceof PDO) {

                    //

                    $result = logAuthAction($pdo, "auth.logout", $contactIdForLog, [
                        "actionOrigin" => "idle_timeout",
                        "ip"           => safeIp(),
                        "ua"           => safeUserAgent(),
                        "sessionId"    => $sessionIdForLog
                    ]);

                    //

                    $logoutLogged = true; // 🔒 prevent loop spam

                }

            } catch (Throwable $e) {

                //
            }

            // 🔄 Force logout state to UI
            $auth = [
                'authenticated' => false,
                'contactId'     => null,
                'username'      => null,
                'role'          => null,
                'firstName'     => null,
                'lastName'      => null
            ];
        }

        // 🔓 RELEASE SESSION LOCK
        session_write_close();

        // ─────────────────────────────────────────
        // 📦 BUILD PAYLOAD
        // ─────────────────────────────────────────
        $SKYE_CONTEXT = ['auth' => $auth];

        $payload = require __DIR__ . "/getDynamicData.php";

        $payload["auth"]      = $auth;
        $payload["idle"]      = $idle;
        $payload["streamId"]  = $streamId;
        $payload["sessionId"] = $sessionId;
        $payload["authDebug"] = [
            "sessionId"     => $sessionId,
            "authenticated" => $auth["authenticated"] ?? false,
            "idleState"     => $idleState
        ];

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);

        if ($json !== false && $json !== '') {

            echo "data: " . $json . "\n\n";

            if (function_exists('ob_flush')) {
                @ob_flush();
            }
            @flush();
        }
    }

    usleep(100000);
}

#endregion