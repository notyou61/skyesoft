<?php
declare(strict_types=1);

// ======================================================================
//  Skyesoft — auth.php
//  Version: 1.1.4
//  Last Updated: 2026-03-14
//  Codex Tier: 4 — Session Mutation Endpoint
// ======================================================================

#region SECTION 0 — Environment Bootstrap

header("Content-Type: application/json; charset=UTF-8");

// Debug (optional during dev)
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Session Security
$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => $secure,
    'httponly' => true,
    'samesite' => 'Lax'
]);

session_start();

// Action Origins (required by actions layer)
const ACTION_ORIGIN_USER = 1;
const ACTION_ORIGIN_SYSTEM = 2;
const ACTION_ORIGIN_AUTOMATION = 3;

// Database Connection
require_once __DIR__ . "/dbConnect.php";

// Actions Layer (Execution + Logging)
require_once __DIR__ . '/utils/actions.php';

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

// ─────────────────────────────────────────
// 🌐 REQUEST CONTEXT HELPERS
// Safe accessors for request metadata
// ─────────────────────────────────────────
function safeIp(): string
{
    return (string)($_SERVER["REMOTE_ADDR"] ?? "");
}

function safeUserAgent(): string
{
    return (string)($_SERVER["HTTP_USER_AGENT"] ?? "");
}


// ─────────────────────────────────────────
// ⏱ SESSION ACTIVITY HELPER
// Updates the user's last activity timestamp
// Used by API endpoints to reset idle timeout
// ─────────────────────────────────────────

function updateLastActivity(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (!empty($_SESSION["authenticated"])) {
        $_SESSION["lastActivity"] = time();
    }

    session_write_close();
}

// ─────────────────────────────────────────
// 📜 AUTH ACTION LOGGER
// Writes authentication events to tblActions
// ─────────────────────────────────────────
function logAuthAction(PDO $pdo, string $actionKey, ?int $contactId, array $meta = []): void
{
    // #region 🧾 Resolve Contact (REQUIRED)

    if (!$contactId) {
        error_log('[auth] missing contactId — skipping log');
        return;
    }

    // #endregion


    // #region 🧠 Map Auth Action → Intent

    $intent = match ($actionKey) {
        'auth.login'       => 'ui_login',
        'auth.logout'      => 'ui_logout',
        'auth.login.fail'  => 'auth_fail',
        default            => 'auth_event'
    };

    // #endregion


    // #region 🧠 Action Type Mapping

    $actionTypeId = match ($intent) {
        'ui_login'  => 1,
        'ui_logout' => 2,
        default     => 3
    };

    // #endregion


    // #region 🧠 Build Metadata

    $metaJson = !empty($meta)
        ? json_encode($meta, JSON_UNESCAPED_SLASHES)
        : null;

    $promptText = $actionKey;
    $response   = $metaJson;

    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

    // #endregion


    // #region 📥 Insert → tblActions

    try {

        $stmt = $pdo->prepare("
            INSERT INTO tblActions (
                actionTypeId,
                contactId,
                actionOrigin,
                actionUnix,
                promptText,
                responseText,
                intent,
                intentConfidence,
                ipAddress,
                userAgent
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $actionTypeId,
            $contactId,
            ACTION_ORIGIN_SYSTEM, // auth = system-triggered
            time(),
            $promptText,
            $response,
            $intent,
            1.0,
            $ipAddress,
            $userAgent
        ]);

        error_log('[auth] action logged: ' . $intent);

    } catch (Throwable $e) {
        error_log('[auth] log failed: ' . $e->getMessage());
    }

    // #endregion
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
        "userId"        => $_SESSION["userId"] ?? null,
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
    // INPUT VALIDATION
    // ─────────────────────────────────────────

    $username = trim((string)($input["username"] ?? ""));
    $password = trim((string)($input["password"] ?? ""));

    if ($username === "" || $password === "") {
        jsonOut(false, "Missing credentials.");
    }

    // ─────────────────────────────────────────
    // USER LOOKUP
    // ─────────────────────────────────────────

    $pdo = getPDO();

    // 🔥 DEBUG — STEP 1 (PLACE HERE)
    error_log('[LOGIN] STEP 1 - reached');

    require_once __DIR__ . '/utils/actions.php';
    error_log('[LOGIN] STEP 2 - actions included');

    error_log('[LOGIN] STEP 3 - PDO OK: ' . ($pdo instanceof PDO ? 'YES' : 'NO'));

    $debugContactId = $user["contactId"] ?? null;
    error_log('[LOGIN] STEP 4 - contactId (pre-session): ' . json_encode($debugContactId));

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
    // USER NOT FOUND
    // ─────────────────────────────────────────

    if (!$user) {

        logAuthAction($pdo, "auth.login.fail", null, [
            "username" => $username,
            "ip"       => safeIp(),
            "ua"       => safeUserAgent()
        ]);

        jsonOut(false, "User not found.");
    }

    // ─────────────────────────────────────────
    // ACCOUNT INACTIVE
    // ─────────────────────────────────────────

    if ((int)($user["isActive"] ?? 0) !== 1) {

        logAuthAction($pdo, "auth.login.fail", (int)$user["contactId"], [
            "username" => $username,
            "reason"   => "inactive",
            "ip"       => safeIp(),
            "ua"       => safeUserAgent()
        ]);

        jsonOut(false, "Account inactive.");
    }

    // ─────────────────────────────────────────
    // PASSWORD VALIDATION
    // ─────────────────────────────────────────

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
    // PREVENT SESSION FIXATION
    // ─────────────────────────────────────────

    session_regenerate_id(true);

    // ─────────────────────────────────────────
    // ESTABLISH AUTHENTICATED SESSION
    // ─────────────────────────────────────────

    $_SESSION["authenticated"] = true;
    $_SESSION["userId"]        = (int)$user["contactId"]; // legacy
    $_SESSION["contactId"]     = (int)$user["contactId"]; // canonical
    $_SESSION["username"]      = (string)$user["contactEmail"];
    $_SESSION["role"]          = (string)($user["role"] ?? "user");
    $_SESSION["lastActivity"]  = time();

    // ─────────────────────────────────────────
    // SESSION CONTEXT
    // ─────────────────────────────────────────

    $contactId = (int)$user["contactId"];
    $email     = (string)$user["contactEmail"];
    $role      = (string)($user["role"] ?? "user");
    $sessionId = session_id();

    error_log("LOGIN SESSION ID: " . $sessionId);
    error_log("LOGIN SESSION DATA: " . json_encode($_SESSION));

    // ─────────────────────────────────────────
    // AUTH EVENT LOG (Structured)
    // ─────────────────────────────────────────

    logAuthAction($pdo, "auth.login", $contactId, [
        "username"  => $email,
        "role"      => $role,
        "ip"        => safeIp(),
        "ua"        => safeUserAgent(),
        "sessionId" => $sessionId
    ]);

    // ─────────────────────────────────────────
    // ACTION EVENT LOG (Authoritative — tblActions)
    // Establishes session baseline for SSE
    // ─────────────────────────────────────────

    insertActionPrompt([
        "contactId"         => $contactId,
        "promptText"        => "system login",
        "responseText"      => "login",
        "intent"            => "ui_login",
        "origin"            => ACTION_ORIGIN_SYSTEM, // 🔥 ADD THIS
        "intentConfidence"  => 1.0,
        "createdUnixTime"   => time()
    ], $pdo);

    // ─────────────────────────────────────────
    // RELEASE SESSION LOCK + RESPOND
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
    } else {
        error_log('[LOGOUT] STEP 2 - session already active');
    }

    // ─────────────────────────────────────────
    // DB CONNECTION (SAFE)
    // ─────────────────────────────────────────

    $pdo = null;

    try {
        $pdo = getPDO();
        error_log('[LOGOUT] STEP 3 - PDO OK: ' . ($pdo instanceof PDO ? 'YES' : 'NO'));
    } catch (Throwable $e) {
        error_log('[LOGOUT] PDO ERROR: ' . $e->getMessage());
        $pdo = null;
    }

    // ─────────────────────────────────────────
    // CAPTURE SESSION CONTEXT (BEFORE DESTROY)
    // ─────────────────────────────────────────

    $contactId = $_SESSION["contactId"] ?? $_SESSION["userId"] ?? null;
    $contactId = $contactId !== null ? (int)$contactId : null;

    $username  = $_SESSION["username"] ?? null;
    $role      = $_SESSION["role"] ?? null;
    $sessionId = session_id();

    error_log('[LOGOUT] STEP 4 - session snapshot: ' . json_encode([
        'contactId' => $contactId,
        'username'  => $username,
        'role'      => $role,
        'sessionId' => $sessionId
    ]));

    // ─────────────────────────────────────────
    // AUTH EVENT LOG (STRUCTURED)
    // ─────────────────────────────────────────

    if ($pdo instanceof PDO) {

        error_log('[LOGOUT] STEP 5 - logAuthAction firing');

        logAuthAction($pdo, "auth.logout", $contactId, [
            "username"  => $username,
            "role"      => $role,
            "ip"        => safeIp(),
            "ua"        => safeUserAgent(),
            "sessionId" => $sessionId
        ]);

        // ─────────────────────────────────────────
        // ACTION EVENT LOG (AUTHORITATIVE)
        // ─────────────────────────────────────────

        error_log('[LOGOUT] STEP 6 - insertActionPrompt firing');

        try {
            insertActionPrompt([
                "contactId"        => $contactId ?: 999999, // 🔥 force insert
                "promptText"       => "system logout",
                "responseText"     => "logout",
                "intent"           => "ui_logout",
                "origin"           => defined('ACTION_ORIGIN_SYSTEM') ? ACTION_ORIGIN_SYSTEM : 'system',
                "intentConfidence" => 1.0,
                "createdUnixTime"  => time()
            ], $pdo);

            error_log('[LOGOUT] STEP 7 - insertActionPrompt completed');

        } catch (Throwable $e) {
            error_log('[LOGOUT] INSERT ERROR: ' . $e->getMessage());
        }

    } else {
        error_log('[LOGOUT] STEP 5 - PDO INVALID, skipping DB ops');
    }

    // ─────────────────────────────────────────
    // CLEAR SESSION
    // ─────────────────────────────────────────

    $_SESSION = [];
    error_log('[LOGOUT] STEP 8 - session cleared');

    // ─────────────────────────────────────────
    // REMOVE SESSION COOKIE
    // ─────────────────────────────────────────

    if (ini_get("session.use_cookies")) {

        error_log('[LOGOUT] STEP 9 - removing session cookie');

        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => '/',
            'secure'   => $secure ?? false,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    }

    // ─────────────────────────────────────────
    // DESTROY SESSION
    // ─────────────────────────────────────────

    session_destroy();
    error_log('[LOGOUT] STEP 10 - session destroyed');

    error_log('[LOGOUT] COMPLETE');

    jsonOut(true);
}

#endregion

#region SECTION 7 — Invalid Action

jsonOut(false, "Invalid action.");

#endregion