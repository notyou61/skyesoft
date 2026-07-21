<?php
declare(strict_types=1);

// ======================================================================
// Skyesoft — sse.php
// Version: 1.5.6
// Real-Time Projection Engine - Production Stable (DB Activity + Geo)
// FIX: session_start() never called after SSE headers/output begin
// ======================================================================

#region ⚙️ SECTION 0 — Environment Bootstrap

// Hard silence any accidental output (critical for SSE purity)
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('html_errors', '0');
error_reporting(E_ALL);

// ─────────────────────────────────────────
// 🔐 SESSION BOOTSTRAP (CANONICAL) — MUST BE FIRST REAL WORK
// ─────────────────────────────────────────
require_once __DIR__ . '/sessionBootstrap.php';

// ─────────────────────────────────────────
// 📦 Dependencies
// ─────────────────────────────────────────
require_once __DIR__ . '/utils/authFunctions.php';
require_once __DIR__ . '/dbConnect.php';

// ─────────────────────────────────────────
// 🧠 Snapshot current session state (while still open)
// ─────────────────────────────────────────
$activitySessionId = session_id();   // ← CANONICAL VARIABLE

$initialSession = [
    'authenticated'     => !empty($_SESSION['authenticated']),
    'contactId'         => $_SESSION['contactId'] ?? null,
    'username'          => $_SESSION['username'] ?? null,
    'role'              => $_SESSION['role'] ?? 'user',
    'activitySessionId' => $activitySessionId
];

$contactIdForLog = $initialSession['contactId'] ?? null;
$sessionIdForLog = $activitySessionId;

// Keep a process-local copy of key session values so we can still
// read/modify them after session_write_close() without re-opening.
$localSession = $_SESSION;

error_log('[SSE BOOT] ' . json_encode($initialSession));

// 🔓 Release session lock early (CRITICAL for concurrent requests + SSE)
session_write_close();

$isSnapshot = isset($_GET['mode']) && $_GET['mode'] === 'snapshot';

#endregion

#region 📸 SECTION 1 — SNAPSHOT MODE (runs before any SSE output)

if ($isSnapshot) {
    // Safe to start session here — no output has been sent yet
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_id($activitySessionId);
        session_start();
    }

    $auth = [
        'authenticated' => !empty($_SESSION['authenticated']),
        'contactId'     => $_SESSION['contactId'] ?? null,
        'username'      => $_SESSION['username'] ?? null,
        'role'          => $_SESSION['role'] ?? 'user'
    ];

    $name = getContactName($auth['contactId']);
    $auth['firstName'] = $name['firstName'] ?? null;
    $auth['lastName']  = $name['lastName'] ?? null;

    $payload = require __DIR__ . "/getDynamicData.php";

    $payload["auth"]              = $auth;
    $payload["streamId"]          = "snapshot";
    $payload["activitySessionId"] = session_id();

    session_write_close();

    header("Content-Type: application/json; charset=UTF-8");
    header("Cache-Control: no-cache");

    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

#endregion

#region 📡 SECTION 2 — SSE HEADERS (no session_start after this point)

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

// First bytes of the stream — from this moment NO session_start() that
// can emit headers is allowed.
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

// Idle logout guard (process-local)
$idleLogoutProcessed = false;

// Micro-cache for lastActivity
$lastActivityCache = [
    'timestamp' => 0,
    'value'     => null
];

define('SKYESOFT_IDLE_TIMEOUT', 10);
$idleTimeoutSeconds = SKYESOFT_IDLE_TIMEOUT;

/**
 * Persist critical session flags after headers have already been sent.
 *
 * IMPORTANT: Once SSE headers/output have begun we must NEVER call
 * session_id() or session_start() — both emit warnings that corrupt
 * the event stream.  We therefore:
 *   1. Always update the process-local $localSession (caller does this).
 *   2. Best-effort write to the real session store ONLY if headers
 *      have not been sent yet.
 *   3. If headers are already sent we rely on the DB audit row
 *      (logAuthAction) + the forceLogout flag; other tabs will pick
 *      up the logout on their next full request / their own idle check.
 */
function sky_sse_persist_session(string $sessionId, array $updates): void
{
    // Absolute guard — never touch the session API after output started
    if (headers_sent() || $sessionId === '') {
        return;
    }

    // Still safe (headers not sent) — normal path (e.g. snapshot mode)
    session_id($sessionId);

    $prev = error_reporting();
    error_reporting($prev & ~E_WARNING);
    @session_start();
    error_reporting($prev);

    if (session_status() === PHP_SESSION_ACTIVE) {
        foreach ($updates as $k => $v) {
            $_SESSION[$k] = $v;
        }
        session_write_close();
    }
}

#endregion

#region 🚀 SECTION 4 — STREAM INITIALIZATION

$streamId   = bin2hex(random_bytes(8));
$lastPing   = 0;
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

        // -------------------------------------------------
        // Use the process-local copy. We do NOT call
        // session_start() here under normal conditions.
        // -------------------------------------------------
        $activitySessionId = $sessionIdForLog;

        // 🔒 HARD STOP — Session already logged out (flag set earlier)
        if (!empty($localSession['idleLogoutComplete'])) {

            $auth = [
                'authenticated' => false,
                'contactId'     => null,
                'username'      => null,
                'role'          => null,
                'firstName'     => null,
                'lastName'      => null
            ];

            $idle        = null;
            $forceLogout = true;

            $payload = require __DIR__ . "/getDynamicData.php";

            $payload["auth"]              = $auth;
            $payload["idle"]              = $idle;
            $payload["forceLogout"]       = true;
            $payload["streamId"]          = $streamId;
            $payload["activitySessionId"] = $activitySessionId;

            echo "data: " . json_encode($payload, JSON_UNESCAPED_SLASHES) . "\n\n";

            if (function_exists('ob_flush')) @ob_flush();
            @flush();

            continue;
        }

        // Single source of truth for auth (from local snapshot + updates)
        $wasAuthenticated = !empty($localSession['authenticated']);
        $contactId        = $localSession['contactId'] ?? null;

        // Optimized name resolution
        $name = $contactId
            ? getContactName($contactId)
            : ['firstName' => null, 'lastName' => null];

        $auth = [
            'authenticated' => $wasAuthenticated,
            'contactId'     => $contactId,
            'username'      => $localSession['username'] ?? null,
            'role'          => $localSession['role'] ?? 'user',
            'firstName'     => $name['firstName'] ?? null,
            'lastName'      => $name['lastName'] ?? null
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
        // 🔒 IDLE LOGOUT — Persist, Audit, Signal
        // ─────────────────────────────────────────
        $forceLogout = false;

        if (
            $idle['state'] === 'expired' &&
            $lastActivity !== null &&
            $wasAuthenticated &&
            $contactIdForLog !== null &&
            !$idleLogoutProcessed
        ) {
            error_log(
                '[IDLE] Threshold reached for contactId=' .
                $contactIdForLog
            );

            // Prevent repeat processing in this SSE process
            $idleLogoutProcessed = true;

            // Capture geo before we clear anything
            $latitude  = $localSession['latitude']  ?? null;
            $longitude = $localSession['longitude'] ?? null;

            // 1. Update the process-local copy immediately
            $localSession['idleLogoutComplete'] = true;
            $localSession['authenticated']      = false;
            $localSession['contactId']          = null;
            $localSession['username']           = null;
            $localSession['role']               = null;

            // 2. Persist the flag + cleared auth to real session storage
            //    (safe even after headers have been sent)
            sky_sse_persist_session($sessionIdForLog, [
                'idleLogoutComplete' => true,
                'authenticated'      => false,
                'contactId'          => null,
                'username'           => null,
                'role'               => null,
            ]);

            // 3. Build logged-out auth for this payload
            $auth = [
                'authenticated' => false,
                'contactId'     => null,
                'username'      => null,
                'role'          => null,
                'firstName'     => null,
                'lastName'      => null
            ];

            $idle = null;

            // 4. Write the logout row to tblActions (this is the missing piece)
            if ($pdo instanceof PDO) {
                try {
                    $lastAction = getLastAuthAction(
                        $pdo,
                        $contactIdForLog
                    );

                    error_log(
                        '[IDLE] Last auth action=' .
                        var_export($lastAction, true)
                    );

                    $alreadyLoggedOut = in_array(
                        $lastAction,
                        ['auth.logout', 'auth.session.logout'],
                        true
                    );

                    if (!$alreadyLoggedOut) {
                        error_log(
                            '[IDLE] Logging idle logout for contactId=' .
                            $contactIdForLog
                        );

                        $auditResult = logAuthAction(
                            $pdo,
                            'auth.logout',
                            $contactIdForLog,
                            [
                                'actionOrigin' => 'idle_timeout',
                                'ip'           => safeIp(),
                                'ua'           => safeUserAgent(),
                                'latitude'     => $latitude,
                                'longitude'    => $longitude,
                                'sessionId'    => $sessionIdForLog
                            ]
                        );

                        error_log(
                            '[IDLE] Logout audit insert result=' .
                            var_export($auditResult, true)
                        );
                    } else {
                        error_log(
                            '[IDLE] Logout audit skipped; already logged'
                        );
                    }
                } catch (Throwable $e) {
                    error_log(
                        '[SSE IDLE LOGOUT ERROR] ' .
                        $e->getMessage()
                    );
                }
            } else {
                error_log('[IDLE] Logout audit skipped: PDO unavailable');
            }

            $forceLogout     = true;
            $contactIdForLog = null;
        }

        // ─────────────────────────────────────────
        // 📦 BUILD & SEND PAYLOAD (AUTHORITATIVE)
        // ─────────────────────────────────────────

        $SKYE_CONTEXT = ['auth' => $auth];

        $payload = require __DIR__ . "/getDynamicData.php";

        $payload["auth"]              = $auth;
        $payload["idle"]              = $idle;
        $payload["streamId"]          = $streamId;
        $payload["activitySessionId"] = $activitySessionId;

        if (!empty($forceLogout)) {
            $payload["forceLogout"] = true;
        }

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);

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
