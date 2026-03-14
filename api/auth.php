<?php
declare(strict_types=1);

// ======================================================================
//  Skyesoft — auth.php
//  Version: 1.1.4
//  Last Updated: 2026-03-14
//  Codex Tier: 4 — Session Mutation Endpoint
// ======================================================================

header("Content-Type: application/json; charset=UTF-8");

#region SESSION COOKIE CONFIGURATION (SSE COMPATIBLE)

$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => 'skyelighting.com',
    'secure'   => $secure,
    'httponly' => true,
    'samesite' => 'Lax'
]);

#endregion

#region SECTION 0 — Environment Bootstrap

require_once __DIR__ . "/dbConnect.php";

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

function safeIp(): string
{
    return (string)($_SERVER["REMOTE_ADDR"] ?? "");
}

function safeUserAgent(): string
{
    return (string)($_SERVER["HTTP_USER_AGENT"] ?? "");
}

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

#region SECTION — SESSION CHECK (diagnostic)

if ($action === "check") {

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    echo json_encode([
        "authenticated" => $_SESSION["authenticated"] ?? false,
        "username"      => $_SESSION["username"] ?? null,
        "role"          => $_SESSION["role"] ?? null,
        "sessionId"     => session_id()
    ], JSON_UNESCAPED_SLASHES);

    session_write_close();
    exit;
}

#endregion

#region SECTION 3 — LOGIN

if ($action === "login") {

    $username = trim((string)($input["username"] ?? ""));
    $password = trim((string)($input["password"] ?? ""));

    if ($username === "" || $password === "") {
        jsonOut(false, "Missing credentials.");
    }

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

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $_SESSION["authenticated"] = true;
    $_SESSION["userId"]        = (int)$user["contactId"];
    $_SESSION["username"]      = (string)$user["contactEmail"];
    $_SESSION["role"]          = (string)($user["role"] ?? "user");
    $_SESSION["lastActivity"]  = time();

    $contactId = (int)$user["contactId"];
    $email     = (string)$user["contactEmail"];
    $role      = (string)($user["role"] ?? "user");
    $sessionId = session_id();

    error_log("LOGIN SESSION ID: " . $sessionId);
    error_log("LOGIN SESSION DATA: " . json_encode($_SESSION));

    session_write_close();

    logAuthAction($pdo, "auth.login", $contactId, [
        "username" => $email,
        "role"     => $role,
        "ip"       => safeIp(),
        "ua"       => safeUserAgent(),
        "sessionId"=> $sessionId
    ]);

    jsonOut(true);
}

#endregion

#region SECTION 4 — LOGOUT

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

    $_SESSION = [];

    session_destroy();
    session_write_close();

    if (ini_get("session.use_cookies")) {
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => '/',
            'domain'   => 'skyelighting.com',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    }

    jsonOut(true);
}

#endregion

#region SECTION 5 — Invalid Action

jsonOut(false, "Invalid action.");

#endregion