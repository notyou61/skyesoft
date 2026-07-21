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

    $pdo = getPDO(); // Ensure DB connection exists

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // 1. Preserve identity BEFORE clearing session
    $contactId = isset($_SESSION['contactId']) ? (int)$_SESSION['contactId'] : 0;
    $username  = $_SESSION['username'] ?? null;
    $activitySessionId = session_id();

    $latitude  = $_SESSION['lastLatitude']  ?? null;
    $longitude = $_SESSION['lastLongitude'] ?? null;

    // Optional: client may send source (e.g. idle_timeout follow-up from browser)
    // Manual UI logout is always Codex origin 0. Browser forceLogout after SSE
    // idle should still use origin 0 here only if it re-audits; preferred path is
    // SSE already wrote origin 1 and getLastAuthAction blocks a duplicate.
    $clientOriginHint = trim((string)($input['actionOrigin'] ?? $input['source'] ?? 'ui_logout'));

    // 2. Audit first via shared executeAuthLogout (origin 0 = user-initiated)
    $auditInserted = false;

    if ($contactId > 0) {
        $auditInserted = executeAuthLogout(
            $pdo,
            $contactId,
            0,   // Codex origin: user-initiated logout
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

        error_log(
            '[AUTH LOGOUT] executeAuthLogout result=' .
            var_export($auditInserted, true) .
            ' contactId=' . $contactId
        );
    } else {
        error_log('[AUTH LOGOUT] Skipped audit — no contactId in session');
    }

    // 3. ONLY THEN destroy session
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();

    echo json_encode([
        'success' => true
    ]);
    exit;
}

#endregion

#region SECTION 7 — INVALID

jsonOut(false, "Invalid action.");

#endregion