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

#region SECTION 5 — LOGIN

if ($action === "login") {

    $username = trim((string)($input["username"] ?? ""));
    $password = trim((string)($input["password"] ?? ""));

    $latitude  = is_numeric($input["latitude"] ?? null) ? (float)$input["latitude"] : null;
    $longitude = is_numeric($input["longitude"] ?? null) ? (float)$input["longitude"] : null;

    if ($username === "" || $password === "") {
        jsonOut(false, "Missing credentials.");
    }

    $pdo = getPDO();

    // --- Fetch user
    $stmt = $pdo->prepare("
        SELECT contactId, contactEmail, passwordHash, isActive
        FROM tblContacts
        WHERE contactEmail = :email
        LIMIT 1
    ");
    $stmt->execute(["email" => $username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (
        !$user ||
        (int)($user["isActive"] ?? 0) !== 1 ||
        !password_verify($password, (string)($user["passwordHash"] ?? ""))
    ) {
        logAuthAction($pdo, "auth.login.fail", $user['contactId'] ?? null, [
            "username"  => $username,
            "ip"        => safeIp(),
            "ua"        => safeUserAgent(),
            "activitySessionId" => session_id()   // still using current session for failed login
        ]);

        jsonOut(false, $user ? "Invalid password." : "User not found.");
    }

    // 🔥 SECURITY: Regenerate session on successful login
    session_regenerate_id(true);

    // 🔗 Authoritative Session Identity
    $activitySessionId = session_id();   // ← Update after regeneration

    $contactId = (int)$user["contactId"];

    // --- Store session data
    $_SESSION["authenticated"] = true;
    $_SESSION["contactId"]     = $contactId;
    $_SESSION["username"]      = $user["contactEmail"];
    $_SESSION["lastActivity"]  = time();
    $_SESSION["latitude"]      = $latitude;
    $_SESSION["longitude"]     = $longitude;

    // --- Log success
    logAuthAction($pdo, "auth.login", $contactId, [
        "username"       => $user["contactEmail"],
        "ip"             => safeIp(),
        "ua"             => safeUserAgent(),
        "latitude"       => $latitude,
        "longitude"      => $longitude,
        "activitySessionId" => $activitySessionId,
        "actionOrigin"   => 1
    ]);

    session_write_close();

    echo json_encode([
        "success"          => true,
        "activitySessionId" => $activitySessionId
    ], JSON_UNESCAPED_SLASHES);

    exit;
}

#endregion

#region SECTION 6 — LOGOUT

if ($action === "logout") {

    $contactId = $_SESSION["contactId"] ?? null;
    $username  = $_SESSION["username"] ?? null;

    $pdo = getPDO();

    if ($pdo && $contactId) {
        logAuthAction($pdo, "auth.logout", (int)$contactId, [
            "username"       => $username,
            "ip"             => safeIp(),
            "ua"             => safeUserAgent(),
            "activitySessionId" => $activitySessionId,
            "actionOrigin"   => 1
        ]);
    }

    $_SESSION = [];
    session_destroy();

    if (ini_get("session.use_cookies")) {
        setcookie(session_name(), '', time() - 42000, '/', '', false, true);
    }

    jsonOut(true);
}

#endregion

#region SECTION 7 — INVALID

jsonOut(false, "Invalid action.");

#endregion