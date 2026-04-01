<?php
declare(strict_types=1);

// ======================================================================
// Skyesoft — auth.php
// Version: 1.2.1
// Codex Tier: 4 — Session Mutation Endpoint (with Geo Support)
// ======================================================================

#region SECTION 0 — Environment Bootstrap

ini_set('display_errors', 1);
error_reporting(E_ALL);

session_name('SKYESOFTSESSID');

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
    ),
    'httponly' => true,
    'samesite' => 'Lax'
]);

session_start();

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/dbConnect.php";
require_once __DIR__ . '/utils/actions.php';
require_once __DIR__ . '/utils/authFunctions.php';

#endregion

#region SECTION 1 — Helpers

function jsonOut(bool $success, string $message = ""): void
{
    $out = ["success" => $success];
    if ($message !== "") {
        $out["message"] = $message;
    }
    echo json_encode($out, JSON_UNESCAPED_SLASHES);
    exit;
}
function getLastAuthAction(PDO $pdo, int $contactId): ?string
{
    $stmt = $pdo->prepare("
        SELECT promptText
        FROM tblActions
        WHERE contactId = :contactId
        AND promptText IN ('auth.login','auth.logout')
        ORDER BY actionUnix DESC
        LIMIT 1
    ");

    $stmt->execute(['contactId' => $contactId]);

    return $stmt->fetchColumn() ?: null;
}

#endregion

#region SECTION 2 — Parse JSON Input

$rawInput = file_get_contents("php://input");
$input = [];

if ($rawInput !== false && trim($rawInput) !== "") {
    $decoded = json_decode($rawInput, true);
    if (!is_array($decoded)) {
        jsonOut(false, "Invalid JSON input.");
    }
    $input = $decoded;
}

$action = trim((string)($input["action"] ?? ($_GET["action"] ?? "")));

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
        "role"          => $_SESSION["role"] ?? null,
        "sessionId"     => session_id()
    ], JSON_UNESCAPED_SLASHES);

    session_write_close();
    exit;
}

#endregion

#region SECTION 5 — LOGIN (with Geo Support + State Guard)

if ($action === "login") {

    $username  = trim((string)($input["username"] ?? ""));
    $password  = trim((string)($input["password"] ?? ""));

    // ─────────────────────────────────────────
    // 🌍 Extract Geo (safe)
    // ─────────────────────────────────────────
    $latitude  = null;
    $longitude = null;

    if (isset($input["latitude"]) && $input["latitude"] !== null && $input["latitude"] !== "") {
        $latitude = is_numeric($input["latitude"]) ? (float)$input["latitude"] : null;
    }

    if (isset($input["longitude"]) && $input["longitude"] !== null && $input["longitude"] !== "") {
        $longitude = is_numeric($input["longitude"]) ? (float)$input["longitude"] : null;
    }

    // ─────────────────────────────────────────
    // 🔍 Validation
    // ─────────────────────────────────────────
    if ($username === "" || $password === "") {
        jsonOut(false, "Missing credentials.");
    }

    $pdo = getPDO();

    // ─────────────────────────────────────────
    // 👤 Fetch User
    // ─────────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT contactId, contactEmail, passwordHash, role, isActive
        FROM tblContacts
        WHERE contactEmail = :email
        LIMIT 1
    ");

    $stmt->execute(["email" => $username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // ─────────────────────────────────────────
    // ❌ Failure Logging
    // ─────────────────────────────────────────
    if (!$user) {
        logAuthAction($pdo, "auth.login.fail", null, [
            "username"     => $username,
            "ip"           => safeIp(),
            "ua"           => safeUserAgent(),
            "actionOrigin" => 2
        ]);
        jsonOut(false, "User not found.");
    }

    if ((int)($user["isActive"] ?? 0) !== 1) {
        logAuthAction($pdo, "auth.login.fail", (int)$user["contactId"], [
            "username"     => $username,
            "reason"       => "inactive",
            "ip"           => safeIp(),
            "ua"           => safeUserAgent(),
            "actionOrigin" => 2
        ]);
        jsonOut(false, "Account inactive.");
    }

    if (!password_verify($password, (string)($user["passwordHash"] ?? ""))) {
        logAuthAction($pdo, "auth.login.fail", (int)$user["contactId"], [
            "username"     => $username,
            "reason"       => "bad_password",
            "ip"           => safeIp(),
            "ua"           => safeUserAgent(),
            "actionOrigin" => 2
        ]);
        jsonOut(false, "Invalid password.");
    }

    // ─────────────────────────────────────────
    // 🚫 STATE GUARD (prevent double login)
    // ─────────────────────────────────────────
    $contactId = (int)$user["contactId"];

    $lastAction = getLastAuthAction($pdo, $contactId);

    if ($lastAction === 'auth.login') {
        jsonOut(false, "User already logged in.");
    }

    // ─────────────────────────────────────────
    // ✅ SUCCESS - SESSION SETUP
    // ─────────────────────────────────────────
    $_SESSION["authenticated"] = true;
    $_SESSION["contactId"]     = $contactId;
    $_SESSION["username"]      = (string)$user["contactEmail"];
    $_SESSION["role"]          = (string)($user["role"] ?? "user");
    $_SESSION["lastActivity"]  = time();
    $_SESSION["latitude"]      = $latitude;
    $_SESSION["longitude"]     = $longitude;

    session_regenerate_id(true);

    $email     = (string)$user["contactEmail"];
    $role      = (string)($user["role"] ?? "user");
    $sessionId = session_id();

    // ─────────────────────────────────────────
    // 📜 Log Successful Login (with Geo)
    // ─────────────────────────────────────────
    logAuthAction($pdo, "auth.login", $contactId, [
        "username"     => $email,
        "role"         => $role,
        "ip"           => safeIp(),
        "ua"           => safeUserAgent(),
        "latitude"     => $latitude,
        "longitude"    => $longitude,
        "sessionId"    => $sessionId,
        "actionOrigin" => 1
    ]);

    session_write_close();

    jsonOut(true);
}

#endregion

#region SECTION 6 — LOGOUT (with Geo + State Guard)

if ($action === "logout") {

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $pdo = null;
    try {
        $pdo = getPDO();
    } catch (Throwable $e) {
        error_log('[LOGOUT] PDO ERROR: ' . $e->getMessage());
    }

    $contactId = $_SESSION["contactId"] ?? null;
    $username  = $_SESSION["username"] ?? null;
    $role      = $_SESSION["role"] ?? null;
    $sessionId = session_id();

    $latitude  = $_SESSION["latitude"]  ?? null;
    $longitude = $_SESSION["longitude"] ?? null;

    // ─────────────────────────────────────────
    // 🚫 STATE GUARD (prevent duplicate logout)
    // ─────────────────────────────────────────
    if ($pdo instanceof PDO && $contactId !== null) {

        $lastAction = getLastAuthAction($pdo, (int)$contactId);

        // ❌ Already logged out → skip duplicate
        if ($lastAction === 'auth.logout') {

            error_log('[LOGOUT] Skipping duplicate logout');

            $_SESSION = [];
            session_destroy();

            jsonOut(true);
        }
    }

    // ─────────────────────────────────────────
    // 📜 Log Logout (only if valid transition)
    // ─────────────────────────────────────────
    if ($pdo instanceof PDO && $contactId !== null) {
        logAuthAction($pdo, "auth.logout", (int)$contactId, [
            "username"     => $username,
            "role"         => $role,
            "ip"           => safeIp(),
            "ua"           => safeUserAgent(),
            "latitude"     => $latitude,
            "longitude"    => $longitude,
            "sessionId"    => $sessionId,
            "actionOrigin" => 1   // USER
        ]);
    }

    // ─────────────────────────────────────────
    // 🧹 Destroy Session
    // ─────────────────────────────────────────
    $_SESSION = [];
    session_destroy();

    if (ini_get("session.use_cookies")) {
        $secure = (
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
            ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
        );

        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    }

    jsonOut(true);
}

#endregion

#region SECTION 7 — Invalid Action

jsonOut(false, "Invalid action.");

#endregion