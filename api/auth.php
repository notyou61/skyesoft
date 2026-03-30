<?php
declare(strict_types=1);

// ======================================================================
//  Skyesoft — auth.php
//  Version: 1.1.4
//  Last Updated: 2026-03-14
//  Codex Tier: 4 — Session Mutation Endpoint
// ======================================================================

#region SECTION 0 — Environment Bootstrap

// Debug
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Force same session name across system
session_name('SKYESOFTSESSID');

// 🔥 FORCE HTTPS COOKIE (do NOT auto-detect)
$secure = true;

// Apply cookie policy FIRST (CRITICAL)
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

// Start session
session_start();

error_log('[AUTH SESSION PATH] ' . session_save_path());

// 🔥 THEN send headers
header("Content-Type: application/json; charset=UTF-8");

// Action Origins (required by actions layer)
const ACTION_ORIGIN_USER = 1;
const ACTION_ORIGIN_SYSTEM = 2;
const ACTION_ORIGIN_AUTOMATION = 3;

// Database Connection
require_once __DIR__ . "/dbConnect.php";

// Actions Layer (Execution + Logging)
require_once __DIR__ . '/utils/actions.php';

// Auth Utilities (Shared Helpers)
require_once __DIR__ . '/utils/authFunctions.php';

#endregion

#region SECTION 1 — Helpers

// ─────────────────────────────────────────
// 📤 JSON RESPONSE HELPER
// Standardized JSON response output
// ─────────────────────────────────────────

function jsonOut(bool $success, string $message = ""): void
{
    $out = ["success" => $success];

    if ($message !== "") {
        $out["message"] = $message;
    }

    echo json_encode($out, JSON_UNESCAPED_SLASHES);
    exit;
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

#region SECTION 3 — TOUCH (Activity Update)

if ($action === "touch") {

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (!empty($_SESSION["authenticated"])) {
        updateLastActivity();
    }

    jsonOut(true);
}

#endregion

#region SECTION 4 — SESSION CHECK (diagnostic)

if ($action === "check") {

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    // Refresh session activity timestamp
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

#region SECTION 5 — LOGIN

if ($action === "login") {

    // ─────────────────────────────────────────
    // 📥 INPUT
    // ─────────────────────────────────────────
    $username = trim((string)($input["username"] ?? ""));
    $password = trim((string)($input["password"] ?? ""));

    if ($username === "" || $password === "") {
        jsonOut(false, "Missing credentials.");
    }

    // ─────────────────────────────────────────
    // 🗄 DB LOOKUP
    // ─────────────────────────────────────────
    $pdo = getPDO();

    $stmt = $pdo->prepare("
        SELECT
            contactId,
            contactEmail,
            passwordHash,
            role,
            isActive
        FROM tblContacts
        WHERE contactEmail = :email
        LIMIT 1
    ");

    $stmt->execute(["email" => $username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // ─────────────────────────────────────────
    // ❌ VALIDATION
    // ─────────────────────────────────────────
    if (!$user) {
        logAuthAction($pdo, "auth.login.fail", null, [
            "username" => $username,
            "ip"       => safeIp(),
            "ua"       => safeUserAgent()
        ]);
        jsonOut(false, "User not found.");
    }

    if ((int)($user["isActive"] ?? 0) !== 1) {
        logAuthAction($pdo, "auth.login.fail", (int)$user["contactId"], [
            "username" => $username,
            "reason"   => "inactive",
            "ip"       => safeIp(),
            "ua"       => safeUserAgent()
        ]);
        jsonOut(false, "Account inactive.");
    }

    if (!password_verify($password, (string)($user["passwordHash"] ?? ""))) {
        logAuthAction($pdo, "auth.login.fail", (int)$user["contactId"], [
            "username" => $username,
            "reason"   => "bad_password",
            "ip"       => safeIp(),
            "ua"       => safeUserAgent()
        ]);
        jsonOut(false, "Invalid password.");
    }

    // ─────────────────────────────────────────
    // 🔐 SESSION SET (CORE FIX)
    // ─────────────────────────────────────────

    $_SESSION["authenticated"] = true;
    $_SESSION["contactId"]     = (int)$user["contactId"];
    $_SESSION["username"]      = (string)$user["contactEmail"];
    $_SESSION["role"]          = (string)($user["role"] ?? "user");
    $_SESSION["lastActivity"]  = time();

    // Regenerate AFTER setting session
    session_regenerate_id(true);

    $contactId = (int)$user["contactId"];
    $email     = (string)$user["contactEmail"];
    $role      = (string)($user["role"] ?? "user");
    $sessionId = session_id();

    error_log('[LOGIN SUCCESS] session_id=' . $sessionId);
    error_log('[LOGIN SUCCESS] data=' . json_encode($_SESSION));

    // ─────────────────────────────────────────
    // 📜 LOG ACTION
    // ─────────────────────────────────────────

    logAuthAction($pdo, "auth.login", $contactId, [
        "username"  => $email,
        "role"      => $role,
        "ip"        => safeIp(),
        "ua"        => safeUserAgent(),
        "sessionId" => $sessionId
    ]);

    // ─────────────────────────────────────────
    // 🔥 CRITICAL: CLOSE SESSION (THIS FIXES SSE)
    // ─────────────────────────────────────────
    session_write_close();

    jsonOut(true);
}

#endregion

#region SECTION 6 — LOGOUT

if ($action === "logout") {

    error_log('[LOGOUT] STEP 1 - reached');

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
        error_log('[LOGOUT] STEP 2 - session started');
    }

    // ─────────────────────────────────────────
    // DB CONNECTION
    // ─────────────────────────────────────────

    $pdo = null;

    try {
        $pdo = getPDO();
    } catch (Throwable $e) {
        error_log('[LOGOUT] PDO ERROR: ' . $e->getMessage());
    }

    // ─────────────────────────────────────────
    // CAPTURE SESSION CONTEXT
    // ─────────────────────────────────────────

    $contactId = isset($_SESSION["contactId"]) ? (int)$_SESSION["contactId"] : null;
    $username  = $_SESSION["username"] ?? null;
    $role      = $_SESSION["role"] ?? null;
    $sessionId = session_id();

    error_log('[LOGOUT] snapshot: ' . json_encode([
        'contactId' => $contactId,
        'username'  => $username,
        'role'      => $role,
        'sessionId' => $sessionId
    ]));

    // ─────────────────────────────────────────
    // AUTH LOG
    // ─────────────────────────────────────────

    if ($pdo instanceof PDO) {
        logAuthAction($pdo, "auth.logout", $contactId, [
            "username"  => $username,
            "role"      => $role,
            "ip"        => safeIp(),
            "ua"        => safeUserAgent(),
            "sessionId" => $sessionId
        ]);
    }

    // ─────────────────────────────────────────
    // DESTROY SESSION
    // ─────────────────────────────────────────

    $_SESSION = [];
    session_destroy();

    // ─────────────────────────────────────────
    // REMOVE COOKIE (MATCH LOGIN CONFIG)
    // ─────────────────────────────────────────

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

    error_log('[LOGOUT] COMPLETE');

    jsonOut(true);
}

#endregion

#region SECTION 7 — Invalid Action

jsonOut(false, "Invalid action.");

#endregion