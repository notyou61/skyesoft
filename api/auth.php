<?php
declare(strict_types=1);

// ======================================================================
// Skyesoft — auth.php
// Version: 1.3.1
// Session-Authoritative Identity (activitySessionId)
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

    // 🔥 LOG ACTION
    logAction($pdo, [
        'actionName' => 'auth.session.login',
        'contactId'  => $user['contactId'],
        'intent'     => 'ui_login',
        'prompt'     => $username,
        'response'   => 'login_success',
        'confidence' => 1.00,
        'lat'        => $input['latitude'] ?? null,
        'lng'        => $input['longitude'] ?? null
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

    $pdo = getPDO(); // 🔥 ensure DB exists (safe even if already set)

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $contactId = $_SESSION['contactId'] ?? null;

    // 🔥 LOG ACTION (BEFORE DESTROY)
    logAction($pdo, [
        'actionName' => 'auth.session.logout',
        'contactId'  => $contactId,
        'intent'     => 'ui_logout',
        'prompt'     => 'logout',
        'response'   => 'logout_success',
        'confidence' => 1.00
    ]);

    // Destroy session
    $_SESSION = [];
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