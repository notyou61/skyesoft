<?php
declare(strict_types=1);

// ======================================================================
// Skyesoft — sse.php
// Version: 1.5.4
// Real-Time Projection Engine - Production Stable (DB Activity + Geo)
// ======================================================================

#region ⚙️ SECTION 0 — Environment Bootstrap

ini_set('display_errors', 0);
error_reporting(E_ALL);

// ─────────────────────────────────────────
// 🔐 SESSION BOOTSTRAP (CANONICAL)
// ─────────────────────────────────────────
require_once __DIR__ . '/sessionBootstrap.php';

// ─────────────────────────────────────────
// 📦 Dependencies
// ─────────────────────────────────────────
require_once __DIR__ . '/utils/authFunctions.php';
require_once __DIR__ . '/dbConnect.php';

// ─────────────────────────────────────────
// 🧠 Snapshot current session state
// ─────────────────────────────────────────
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

// 🔓 Release session lock (CRITICAL for SSE)
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
header('Keep-Alive: timeout=60');
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

// Micro-cache for lastActivity
$lastActivityCache = [
    'timestamp' => 0,
    'value'     => null
];

define('SKYESOFT_IDLE_TIMEOUT', 900);   // Change to 900 in production
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

        // 🔒 HARD STOP — Session already logged out
        if (!empty($_SESSION['idleLogoutComplete']) && $_SESSION['idleLogoutComplete'] === true) {

            $auth = [
                'authenticated' => false,
                'contactId'     => null,
                'username'      => null,
                'role'          => null,
                'firstName'     => null,
                'lastName'      => null
            ];

            $idle = null;
            $forceLogout = true;

            session_write_close();

            $payload = require __DIR__ . "/getDynamicData.php";

            $payload["auth"]        = $auth;
            $payload["idle"]        = $idle;
            $payload["forceLogout"] = true;
            $payload["streamId"]    = $streamId;
            $payload["sessionId"]   = $sessionId;

            echo "data: " . json_encode($payload, JSON_UNESCAPED_SLASHES) . "\n\n";

            if (function_exists('ob_flush')) @ob_flush();
            @flush();

            continue; // 🔥 skip entire loop logic
        }


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
        // 📊 FETCH LAST ACTIVITY FROM tblActions (actionUnix)
        // ─────────────────────────────────────────
        $lastActivity = null;

        if ($pdo instanceof PDO && $contactId) {
            $doQuery = (
                $lastActivityCache['timestamp'] === 0 ||
                ($now - $lastActivityCache['timestamp']) >= 3
            );

            if ($doQuery) {
                try {
                    $stmt = $pdo->prepare("
                        SELECT MAX(actionUnix) AS lastAction
                        FROM tblActions
                        WHERE contactId = :id
                        LIMIT 1
                    ");

                    $stmt->execute([':id' => $contactId]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($row && $row['lastAction'] !== null) {
                        $lastActivity = (int)$row['lastAction'];
                    }

                    if ($lastActivity !== null) {
                        $lastActivityCache['value'] = $lastActivity;
                    }

                    $lastActivityCache['timestamp'] = $now;

                } catch (Throwable $e) {
                    error_log('[SSE ACTIVITY QUERY ERROR] ' . $e->getMessage());
                }
            } else {
                $lastActivity = $lastActivityCache['value'];
            }
        }

        if ($lastActivity === null) {
            $lastActivity = $lastActivityCache['value'];
        }

        // ─────────────────────────────────────────
        // ⏱ IDLE STATE CALCULATION
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
        // 🔒 IDLE LOGOUT (Signal Only — NO Mutation)
        // ─────────────────────────────────────────
        $forceLogout = false;

        if (
            $idle['state'] === 'expired' &&
            $lastActivity !== null &&
            $wasAuthenticated &&
            $contactIdForLog &&
            !$idleLogoutProcessed
        ) {

            $_SESSION['idleLogoutComplete'] = true;

            // 🔄 FORCE STATE TRANSITION (same frame)
            $auth = [
                'authenticated' => false,
                'contactId'     => null,
                'username'      => null,
                'role'          => null,
                'firstName'     => null,
                'lastName'      => null
            ];

            $idle = null;

            // Get geo from session (captured at login)
            $latitude  = $_SESSION['latitude']  ?? null;
            $longitude = $_SESSION['longitude'] ?? null;

            // Log ONCE (authoritative audit trail)
            // 🔒 Prevent duplicate logout entries
            if ($pdo instanceof PDO) {
                try {

                    $lastAction = getLastAuthAction($pdo, $contactIdForLog);

                    if ($lastAction !== 'auth.logout') {

                        logAuthAction($pdo, "auth.logout", $contactIdForLog, [
                            "actionOrigin" => "idle_timeout",
                            "ip"           => safeIp(),
                            "ua"           => safeUserAgent(),
                            "latitude"     => $latitude,
                            "longitude"    => $longitude,
                            "sessionId"    => $sessionIdForLog
                        ]);

                    }

                } catch (Throwable $e) {
                    error_log('[SSE IDLE LOGOUT ERROR] ' . $e->getMessage());
                }
            }

            // 🔔 SIGNAL ONLY (client will handle UI)
            $forceLogout = true;

            // Prevent re-trigger
            $contactIdForLog = null;
        }

        session_write_close();

        // ─────────────────────────────────────────
        // 📦 BUILD & SEND PAYLOAD (AUTHORITATIVE)
        // ─────────────────────────────────────────

        // Context for downstream modules (read-only)
        $SKYE_CONTEXT = ['auth' => $auth];

        // Get dynamic system data
        $payload = require __DIR__ . "/getDynamicData.php";

        // 🔐 Attach core state (server authoritative)
        $payload["auth"]      = $auth;
        $payload["idle"]      = $idle;
        $payload["streamId"]  = $streamId;
        $payload["sessionId"] = $sessionId;

        // 🔔 Apply force logout signal (if triggered earlier)
        if (!empty($forceLogout)) {
            $payload["forceLogout"] = true;
        }

        // Encode safely
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);

        // Send SSE frame
        if ($json !== false && $json !== '') {
            echo "data: {$json}\n\n";

            if (function_exists('ob_flush')) {
                @ob_flush();
            }

            @flush();
        }
    }

    usleep(100000); // 100ms
}

#endregion