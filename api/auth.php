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

// ─────────────────────────────────────────
// SESSION COOKIE POLICY
// Must match all other endpoints exactly
// ─────────────────────────────────────────

$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => '.skyelighting.com',
    'secure'   => $secure,
    'httponly' => true,
    'samesite' => 'Lax'
]);

// ─────────────────────────────────────────
// ATTACH EXISTING SESSION (if present)
// Ensures login writes into the same session
// used by SSE and other endpoints
// ─────────────────────────────────────────

$cookieName = session_name();

if (isset($_COOKIE[$cookieName])) {
    session_id($_COOKIE[$cookieName]);
}

session_start();

// ─────────────────────────────────────────
// DATABASE CONNECTION
// ─────────────────────────────────────────

require_once __DIR__ . "/dbConnect.php";

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
    $metaJson = json_encode($meta, JSON_UNESCAPED_SLASHES);

    try {

        $actionTypeId = null;

        $stmt = $pdo->prepare("
            SELECT actionTypeId
            FROM tblActionTypes
            WHERE actionTypeKey = :k
            LIMIT 1
        ");
        $stmt->execute(["k" => $actionKey]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && isset($row["actionTypeId"])) {

            $actionTypeId = (int)$row["actionTypeId"];

        } else {

            $stmt = $pdo->prepare("
                SELECT actionTypeId
                FROM tblActionTypes
                WHERE actionTypeName = :k
                LIMIT 1
            ");
            $stmt->execute(["k" => $actionKey]);

            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row && isset($row["actionTypeId"])) {
                $actionTypeId = (int)$row["actionTypeId"];
            }
        }

        if ($actionTypeId === null) {
            return;
        }

        $stmt = $pdo->prepare("
            INSERT INTO tblActions
                (actionTypeId, actionDate, actionNote)
            VALUES
                (:typeId, :unix, :note)
        ");

        $stmt->execute([
            "typeId" => $actionTypeId,
            "unix"   => time(),
            "note"   => $metaJson
        ]);

    } catch (Throwable $e) {
        return;
    }
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

    echo json_encode([
        "authenticated" => $_SESSION["authenticated"] ?? false,
        "userId"        => $_SESSION["userId"] ?? null,
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
    $_SESSION["userId"]        = (int)$user["contactId"];
    $_SESSION["username"]      = (string)$user["contactEmail"];
    $_SESSION["role"]          = (string)($user["role"] ?? "user");
    $_SESSION["lastActivity"]  = time();

    // ─────────────────────────────────────────
    // SESSION CONTEXT VARIABLES
    // ─────────────────────────────────────────

    $contactId = (int)$user["contactId"];
    $email     = (string)$user["contactEmail"];
    $role      = (string)($user["role"] ?? "user");
    $sessionId = session_id();

    error_log("LOGIN SESSION ID: " . $sessionId);
    error_log("LOGIN SESSION DATA: " . json_encode($_SESSION));

    // ─────────────────────────────────────────
    // AUTHENTICATION EVENT LOG
    // ─────────────────────────────────────────

    logAuthAction($pdo, "auth.login", $contactId, [
        "username"  => $email,
        "role"      => $role,
        "ip"        => safeIp(),
        "ua"        => safeUserAgent(),
        "sessionId" => $sessionId
    ]);

    // Release session lock before responding
    session_write_close();

    jsonOut(true);
}

#endregion

#region SECTION 6 — LOGOUT

if ($action === "logout") {

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $pdo = null;

    try { $pdo = getPDO(); } catch (Throwable $e) { $pdo = null; }

    $contactId = $_SESSION["userId"] ?? null;
    $username  = $_SESSION["username"] ?? null;
    $role      = $_SESSION["role"] ?? null;

    if ($pdo instanceof PDO) {
        logAuthAction($pdo, "auth.logout", is_numeric($contactId) ? (int)$contactId : null, [
            "username" => $username,
            "role"     => $role,
            "ip"       => safeIp(),
            "ua"       => safeUserAgent()
        ]);
    }

    // Clear session variables
    $_SESSION = [];

    // Remove session cookie
    if (ini_get("session.use_cookies")) {
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => '/',
            'domain'   => '.skyelighting.com',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    }

    // Destroy session
    session_destroy();

    jsonOut(true);
}

#endregion

#region SECTION 7 — Invalid Action

jsonOut(false, "Invalid action.");

#endregion