<?php
declare(strict_types=1);

// ======================================================================
// Skyesoft — sse.php
// Version: 1.5.0
// Real-Time Projection Engine - Production Stable (DB Activity Source)
// ======================================================================

#region ⚙️ SECTION 0 — Environment Bootstrap

ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/utils/authFunctions.php';
require_once __DIR__ . '/dbConnect.php';

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

$contactIdForLog = $initialSession['contactId'] ?? null;
$sessionIdForLog = $initialSession['sessionId'] ?? null;

error_log('[SSE BOOT] ' . json_encode($initialSession));

session_write_close();

$isSnapshot = isset($_GET['mode']) && $_GET['mode'] === 'snapshot';

#endregion

#region 📸 SECTION 1 — SNAPSHOT MODE

if ($isSnapshot) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $auth = [
        'authenticated' => !empty($_SESSION['authenticated']),
        'contactId'     => $_SESSION['contactId'] ?? null,
        'username'      => $_SESSION['username'] ?? null,
        'role'          => $_SESSION['role'] ?? 'user'
    ];

    $name = getContactName($auth['contactId']);
    $auth['firstName'] = $name['firstName'];
    $auth['lastName']  = $name['lastName'];

    $payload = require __DIR__ . "/getDynamicData.php";

    $payload["auth"]      = $auth;
    $payload["streamId"]  = "snapshot";
    $payload["sessionId"] = session_id();

    session_write_close();

    header("Content-Type: application/json; charset=UTF-8");
    header("Cache-Control: no-cache");

    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

#endregion

#region 📡 SECTION 2 — SSE HEADERS

@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', '0');
@ini_set('implicit_flush', '1');

if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', '1');
    @apache_setenv('dont-vary', '1');
}

while (ob_get_level() > 0) {
    @ob_end_clean();
}
@ob_implicit_flush(true);

header('Content-Type: text/event-stream; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate, no-transform');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');
header('X-LiteSpeed-Cache-Control: no-cache');
header('X-LiteSpeed-Cache: no-cache');

echo ": connected\n\n";
echo "retry: 3000\n\n";

if (function_exists('ob_flush')) @ob_flush();
@flush();

#endregion

#region ⚙️ SECTION 3 — PHP RUNTIME + PRE-LOOP SETUP

set_time_limit(0);
ignore_user_abort(true);

// Safe PDO (once)
$pdo = null;
try {
    $pdo = getPDO();
} catch (Throwable $e) {
    error_log('[SSE PDO INIT ERROR] ' . $e->getMessage());
    $pdo = null;
}

// Idle logout guard
$idleLogoutProcessed = false;

// Micro-cache for lastActivity (prevents 1 query/sec)
$lastActivityCache = [
    'timestamp' => 0,
    'value'     => null
];

define('SKYESOFT_IDLE_TIMEOUT', 30);   // Change to 900 in production
$idleTimeoutSeconds = SKYESOFT_IDLE_TIMEOUT;

#endregion

#region 🚀 SECTION 4 — STREAM INITIALIZATION

$streamId = bin2hex(random_bytes(8));
$lastPing = 0;
$lastSecond = 0;

#endregion

#region 🔄 SECTION 5 — STREAM LOOP

while (true) {

    if (connection_aborted()) {
        break;
    }

    $now = time();

    // Keepalive ping
    if (($now - $lastPing) >= 15) {
        echo ": ping\n\n";
        if (function_exists('ob_flush')) @ob_flush();
        @flush();
        $lastPing = $now;
    }

    // 1Hz data update
    if ($now > $lastSecond) {

        $lastSecond = $now;

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $sessionId = session_id();

        // Single source of truth for auth
        $wasAuthenticated = !empty($_SESSION['authenticated']);
        $contactId        = $_SESSION['contactId'] ?? null;

        // Optimized name resolution
        $name = $contactId 
            ? getContactName($contactId) 
            : ['firstName' => null, 'lastName' => null];

        $auth = [
            'authenticated' => $wasAuthenticated,
            'contactId'     => $contactId,
            'username'      => $_SESSION['username'] ?? null,
            'role'          => $_SESSION['role'] ?? 'user',
            'firstName'     => $name['firstName'],
            'lastName'      => $name['lastName']
        ];

        // ─────────────────────────────────────────
        // 📊 FETCH LAST ACTIVITY FROM DATABASE (with micro-cache)
        // ─────────────────────────────────────────
        $lastActivity = null;

        if ($pdo instanceof PDO && $contactId) {
            if (
                $lastActivityCache['timestamp'] === 0 ||
                ($now - $lastActivityCache['timestamp']) >= 3
            ) {
                try {
                    $stmt = $pdo->prepare("
                        SELECT MAX(actionCreatedAt) AS lastAction
                        FROM tblActions
                        WHERE contactId = :id
                        LIMIT 1
                    ");

                    $stmt->execute([':id' => $contactId]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($row && $row['lastAction']) {
                        $lastActivity = strtotime($row['lastAction']);
                    }

                    $lastActivityCache['value']     = $lastActivity;
                    $lastActivityCache['timestamp'] = $now;

                } catch (Throwable $e) {
                    error_log('[SSE ACTIVITY QUERY ERROR] ' . $e->getMessage());
                    $lastActivity = $lastActivityCache['value']; // fallback to cache
                }
            } else {
                $lastActivity = $lastActivityCache['value'];
            }
        }

        // ─────────────────────────────────────────
        // ⏱ IDLE STATE CALCULATION (Original Logic Restored)
        // ─────────────────────────────────────────
        $idle = [
            'state'            => 'inactive',
            'remainingSeconds' => null,
            'lastActivity'     => $lastActivity,
            'timeoutSeconds'   => $idleTimeoutSeconds
        ];

        if ($lastActivity !== null) {
            $elapsed   = $now - $lastActivity;
            $remaining = max(0, $idleTimeoutSeconds - $elapsed);

            if ($remaining <= 0) {
                $idle['state'] = 'expired';
            } elseif ($remaining <= 60) {
                $idle['state'] = 'warning';
            } else {
                $idle['state'] = 'active';
            }

            $idle['remainingSeconds'] = $remaining;
        }

        // ─────────────────────────────────────────
        // 🔒 IDLE LOGOUT (Safe Single Handler)
        // ─────────────────────────────────────────
        if (
            $idle['state'] === 'expired' &&
            $lastActivity !== null &&
            $wasAuthenticated &&
            $contactIdForLog &&
            !$idleLogoutProcessed
        ) {

            $idleLogoutProcessed = true;

            sseDebugLog('idle_logout_start', [
                'contactId' => $contactIdForLog,
                'sessionId' => $sessionIdForLog
            ]);

            if ($pdo instanceof PDO) {
                try {
                    logAuthAction($pdo, "auth.logout", $contactIdForLog, [
                        "actionOrigin" => "idle_timeout",
                        "ip"           => safeIp(),
                        "ua"           => safeUserAgent(),
                        "sessionId"    => $sessionIdForLog
                    ]);
                } catch (Throwable $e) {
                    error_log('[SSE IDLE LOGOUT ERROR] ' . $e->getMessage());
                }
            }

            // Destroy session
            $_SESSION = [];
            session_destroy();

            // Force clean auth state
            $auth = [
                'authenticated' => false,
                'contactId'     => null,
                'username'      => null,
                'role'          => null,
                'firstName'     => null,
                'lastName'      => null
            ];

            $contactIdForLog = null;
            $idle['state'] = 'expired';
        }

        session_write_close();

        // ─────────────────────────────────────────
        // 📦 BUILD & SEND PAYLOAD
        // ─────────────────────────────────────────
        $SKYE_CONTEXT = ['auth' => $auth];

        $payload = require __DIR__ . "/getDynamicData.php";

        $payload["auth"]      = $auth;
        $payload["idle"]      = $idle;
        $payload["streamId"]  = $streamId;
        $payload["sessionId"] = $sessionId;

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);

        if ($json !== false && $json !== '') {
            echo "data: " . $json . "\n\n";
            if (function_exists('ob_flush')) @ob_flush();
            @flush();
        }
    }

    usleep(100000); // 100ms
}

#endregion