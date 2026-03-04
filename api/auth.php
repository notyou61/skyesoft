<?php
declare(strict_types=1);
session_start();

// ======================================================================
//  Skyesoft — auth.php
//  Version: 1.1.2
//  Last Updated: 2026-03-04
//  Codex Tier: 4 — Session Mutation Endpoint
//
//  Role:
//  Authoritative authentication endpoint for Skyesoft sessions.
//  Handles login and logout requests from the UI.
//
//  Inputs:
//   • POST JSON
//        action   → 'login' | 'logout'
//        username → string  (login only)  (maps to tblContacts.contactEmail)
//        password → string  (login only)
//
//  Outputs:
//   • JSON
//        success  → bool
//        message  → string (optional)
//
//  Architecture:
//   • Mutates PHP session state
//   • Persists login/logout actions to DB (tblActions)
//   • Does NOT project session state
//   • Session state is projected to the UI via sse.php
//
//  Forbidden:
//   • No HTML output
//   • No SSE output
//   • No domain logic
//   • No Codex mutation
//
//  Notes:
//   • This endpoint mutates session + logs action rows.
//   • UI state changes propagate via SSE on the next stream tick.
// ======================================================================

header("Content-Type: application/json; charset=UTF-8");

#region SECTION 0 — Environment Bootstrap

/** @var callable getPDO */
// Load database connector (must define getPDO())
/** @noinspection PhpUndefinedFunctionInspection */
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
    // If you later sit behind a proxy, adjust this carefully.
    return (string)($_SERVER["REMOTE_ADDR"] ?? "");
}

function safeUserAgent(): string
{
    return (string)($_SERVER["HTTP_USER_AGENT"] ?? "");
}

/**
 * Log auth action to tblActions (if present).
 * - Designed to be "best effort": auth should still work if logging fails.
 */
function logAuthAction(PDO $pdo, string $actionKey, ?int $contactId, array $meta = []): void
{
    // Normalize meta JSON
    $metaJson = json_encode($meta, JSON_UNESCAPED_SLASHES);

    // Attempt common schema (you can align columns later if needed)
    // Expected / typical columns (guess): actionTypeId, actionEntityId, actionLocationId, actionDate, actionNote
    // We'll insert minimal: actionTypeId via lookup + note payload as JSON.
    try {
        // Lookup actionTypeId by key/name if tblActionTypes exists
        $actionTypeId = null;

        // Try tblActionTypes(actionTypeKey) then (actionTypeName)
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

        // If no action type found, do not block auth.
        if ($actionTypeId === null) {
            return;
        }

        // Insert into tblActions with a conservative, common set of columns
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
        // Best-effort logging only (never break auth)
        return;
    }
}

#endregion

#region SECTION 2 — Parse JSON Input

$input  = json_decode(file_get_contents("php://input"), true) ?? [];
$action = trim((string)($input["action"] ?? ""));

#endregion

#region SECTION 3 — LOGIN

if ($action === "login") {

    $username = trim((string)($input["username"] ?? ""));
    $password = trim((string)($input["password"] ?? ""));

    if ($username === "" || $password === "") {
        jsonOut(false, "Missing credentials.");
    }

    $pdo = getPDO();

    // Map: username=email -> tblContacts.contactEmail
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
        // Log failed login attempt (no contactId)
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

    // Establish Authoritative Session
    $_SESSION["authenticated"] = true;
    $_SESSION["userId"]        = (int)$user["contactId"];
    $_SESSION["username"]      = (string)$user["contactEmail"];
    $_SESSION["role"]          = (string)($user["role"] ?? "user");
    $_SESSION["lastActivity"]  = time();

    // Optional: update tblContacts lastActivityUnix
    try {
        $stmt = $pdo->prepare("
            UPDATE tblContacts
            SET lastActivityUnix = :unix
            WHERE contactId = :id
            LIMIT 1
        ");
        $stmt->execute([
            "unix" => time(),
            "id"   => (int)$user["contactId"]
        ]);
    } catch (Throwable $e) {
        // Non-blocking
    }

    // Log successful login
    logAuthAction($pdo, "auth.login", (int)$user["contactId"], [
        "username" => (string)$user["contactEmail"],
        "role"     => (string)($_SESSION["role"] ?? "user"),
        "ip"       => safeIp(),
        "ua"       => safeUserAgent()
    ]);

    jsonOut(true);
}

#endregion

#region SECTION 4 — LOGOUT

if ($action === "logout") {

    $pdo = null;
    try { $pdo = getPDO(); } catch (Throwable $e) { $pdo = null; }

    $contactId = isset($_SESSION["userId"]) ? (int)$_SESSION["userId"] : null;
    $username  = $_SESSION["username"] ?? null;
    $role      = $_SESSION["role"] ?? null;

    // Log logout BEFORE destroying session (best effort)
    if ($pdo instanceof PDO) {
        logAuthAction($pdo, "auth.logout", $contactId, [
            "username" => $username,
            "role"     => $role,
            "ip"       => safeIp(),
            "ua"       => safeUserAgent()
        ]);
    }

    session_unset();
    session_destroy();

    jsonOut(true);
}

#endregion

#region SECTION 5 — Invalid Action

jsonOut(false, "Invalid action.");

#endregion