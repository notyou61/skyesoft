<?php
declare(strict_types=1);

// ======================================================================
// Skyesoft — auth.php
// Version: 1.4.1
// Session-Authoritative Identity (activitySessionId)
// LOGOUT: executeAuthLogout origin 0 (manual) with structured payload
// LOGIN:  logAuthAction promptText=auth.login + Phase 5 payload structures
// ======================================================================

#region SECTION 0 — Environment Bootstrap

ini_set('display_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/php-error.log');

require_once __DIR__ . '/sessionBootstrap.php';   // MUST call session_start()

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/dbConnect.php";
require_once __DIR__ . '/utils/actions.php';
require_once __DIR__ . '/utils/authFunctions.php';

#endregion

#region SECTION 1 — Helpers

function jsonOut(bool $success, string $message = ""): void
{
    echo json_encode([
        "success" => $success,
        "message" => $message
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

#endregion

#region SECTION 2 — Parse Input

$rawInput = file_get_contents("php://input");
$input    = $rawInput ? json_decode($rawInput, true) : [];

$action = trim((string)($input["action"] ?? ($_GET["action"] ?? "")));

// 🔥 CRITICAL — Server owns the canonical session ID
$activitySessionId = session_id();

#endregion

#region SECTION 3 — TOUCH

if ($action === "touch") {
    if (!empty($_SESSION["authenticated"])) {
        updateLastActivity();
    }
    jsonOut(true);
}

#endregion

#region SECTION 4 — SESSION CHECK

if ($action === "check") {

    if (!empty($_SESSION["authenticated"])) {
        $_SESSION["lastActivity"] = time();
    }

    echo json_encode([
        "authenticated" => $_SESSION["authenticated"] ?? false,
        "contactId"     => $_SESSION["contactId"] ?? null,
        "username"      => $_SESSION["username"] ?? null,
        "activitySessionId" => $activitySessionId  // Canonical variable
    ], JSON_UNESCAPED_SLASHES);

    session_write_close();
    exit;
}

#endregion

#region SECTION 5 — 🔐 LOGIN

$pdo = getPDO();  // 🔥 REQUIRED

if ($input['action'] === 'login') {

    $username = trim($input['username'] ?? '');
    $password = trim($input['password'] ?? '');

    if (!$username || !$password) {
        echo json_encode([
            'success' => false,
            'message' => 'Missing credentials.'
        ]);
        exit;
    }

    // 🔍 Lookup user
    $stmt = $pdo->prepare("SELECT * FROM tblContacts WHERE contactEmail = :email LIMIT 1");
    $stmt->execute(['email' => $username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($password, $user['passwordHash'])) {
        // Optional: audit failed attempt without revealing details
        logAuthAction($pdo, 'auth.login.fail', null, [
            'origin'            => 0,
            'response'          => 'login_failed',
            'latitude'          => $input['latitude']  ?? null,
            'longitude'         => $input['longitude'] ?? null,
            'activitySessionId' => $activitySessionId,
            'actionPayloadData' => [
                'source'   => 'manual',
                'origin'   => 0,
                'username' => $username,
            ],
            'actionResponseData' => [
                'result' => 'login_failed',
            ],
        ]);

        echo json_encode([
            'success' => false,
            'message' => 'Invalid credentials.'
        ]);
        exit;
    }

    // ✅ Start session
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $_SESSION['authenticated'] = true;
    $_SESSION['contactId']     = $user['contactId'];
    $_SESSION['username']      = $user['contactEmail'];
    // Save last known location for future actions (e.g. logout)
    if (isset($input['latitude']) && isset($input['longitude'])) {
        $_SESSION['lastLatitude']  = (float)$input['latitude'];
        $_SESSION['lastLongitude'] = (float)$input['longitude'];
    }

    // 🔥 LOG ACTION — promptText MUST be 'auth.login' for getLastAuthAction()
    // Never put password or credentials into payload/response structures.
    $contactId = (int)$user['contactId'];

    logAuthAction($pdo, 'auth.login', $contactId, [
        'origin'             => 0,
        'latitude'           => $input['latitude']  ?? null,
        'longitude'          => $input['longitude'] ?? null,
        'activitySessionId'  => $activitySessionId,
        'response'           => 'login_success',
        'actionPayloadData'  => [
            'source'            => 'login_form',
            'activitySessionId' => $activitySessionId,
            'contactId'         => $contactId,
        ],
        'actionResponseData' => [
            'result'        => 'login_success',
            'authenticated' => true,
        ],
    ]);

    echo json_encode([
        'success' => true,
        'username' => $user['contactEmail']
    ]);
    exit;
}
#endregion

#region SECTION 6 — 🔓 LOGOUT

if ($input['action'] === 'logout') {

    // Ensure database connection exists
    $pdo = getPDO();

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Preserve identity before clearing session
    $contactId = isset($_SESSION['contactId'])
        ? (int)$_SESSION['contactId']
        : 0;

    $username = $_SESSION['username'] ?? null;
    $activitySessionId = session_id();

    $latitude = $_SESSION['lastLatitude'] ?? null;
    $longitude = $_SESSION['lastLongitude'] ?? null;

    // Resolve client logout context
    $clientSource = strtolower(trim(
        (string)($input['source'] ?? '')
    ));

    $clientActionOrigin = strtolower(trim(
        (string)($input['actionOrigin'] ?? '')
    ));

    $clientOriginHint = $clientActionOrigin !== ''
        ? $clientActionOrigin
        : ($clientSource !== '' ? $clientSource : 'ui_logout');

    // SSE already inserted auth.logout (browser destroys session only)
    $isIdleCleanup =
        $clientSource === 'idle_timeout' ||
        $clientSource === 'sse_idle' ||
        $clientActionOrigin === 'idle_timeout' ||
        $clientActionOrigin === 'sse_idle';

    // Track whether an audit was inserted or intentionally skipped
    $auditInserted = false;
    $auditStatus = 'not_required';

    // Manual user logout
    if ($contactId > 0 && !$isIdleCleanup) {

        $auditInserted = executeAuthLogout(
            $pdo,
            $contactId,
            0, // User-initiated logout
            [
                'source'             => 'manual',
                'latitude'           => $latitude,
                'longitude'          => $longitude,
                'activitySessionId'  => $activitySessionId,
                'response'           => 'logout_success',
                'actionPayloadData'  => [
                    'source'            => 'manual',
                    'origin'            => 0,
                    'activitySessionId' => $activitySessionId,
                    'contactId'         => $contactId,
                    'clientOriginHint'  => $clientOriginHint,
                    'username'          => $username,
                ],
                'actionResponseData' => [
                    'result'      => 'logout_success',
                    'audit'       => 'inserted',
                    'forceLogout' => false,
                ],
            ]
        );

        $auditStatus = $auditInserted
            ? 'inserted'
            : 'failed';

        error_log(
            '[AUTH LOGOUT] Manual audit result=' .
            var_export($auditInserted, true) .
            ' contactId=' .
            $contactId
        );

    // Browser cleanup following authoritative SSE idle logout
    } elseif ($isIdleCleanup) {

        $auditStatus = 'already_recorded';

        error_log(
            '[AUTH LOGOUT] Idle cleanup — SSE audit already recorded; ' .
            'destroying PHP session only. contactId=' .
            $contactId .
            ' activitySessionId=' .
            $activitySessionId
        );

    // No authenticated identity is available
    } else {

        $auditStatus = 'no_contact';

        error_log(
            '[AUTH LOGOUT] Audit skipped — no contactId in session'
        );
    }

    // Always destroy the session (security boundary)
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();

        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();

    echo json_encode([
        'success'       => true,
        'auditStatus'   => $auditStatus,
        'sessionClosed' => true,
    ]);

    exit;
}

#endregion

#region SECTION 7 — INVALID

jsonOut(false, "Invalid action.");

#endregion