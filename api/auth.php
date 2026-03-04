<?php
declare(strict_types=1);
session_start();

// ======================================================================
//  Skyesoft — auth.php
//  Version: 1.1.1
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
//        username → string  (login only)
//        password → string  (login only)
//
//  Outputs:
//   • JSON
//        success  → bool
//        message  → string (optional)
//
//  Architecture:
//   • Mutates PHP session state
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
//   • This endpoint only mutates session state.
//   • UI state changes propagate via SSE on the next stream tick.
// ======================================================================

header("Content-Type: application/json; charset=UTF-8");

#region SECTION 0 — Environment Bootstrap

/** @var callable getPDO */

// Load database connector (must define getPDO())
/** @noinspection PhpUndefinedFunctionInspection */
require_once __DIR__ . "/dbConnect.php";

#endregion

#region SECTION 1 — Parse JSON Input

$input  = json_decode(file_get_contents("php://input"), true) ?? [];
$action = $input['action'] ?? '';

#endregion

#region SECTION 2 — LOGIN

if ($action === 'login') {

    $username = trim($input['username'] ?? '');
    $password = trim($input['password'] ?? '');

    if ($username === '' || $password === '') {

        echo json_encode([
            "success" => false,
            "message" => "Missing credentials."
        ]);
        exit;
    }

    // Establish database connection
    $pdo = getPDO();

    $stmt = $pdo->prepare("
        SELECT id, username, password_hash, role
        FROM users
        WHERE username = :username
        LIMIT 1
    ");

    $stmt->execute(['username' => $username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {

        echo json_encode([
            "success" => false,
            "message" => "User not found."
        ]);
        exit;
    }

    if (!password_verify($password, $user['password_hash'])) {

        echo json_encode([
            "success" => false,
            "message" => "Invalid password."
        ]);
        exit;
    }

    // Establish Authoritative Session
    $_SESSION['authenticated'] = true;
    $_SESSION['userId']        = $user['id'];
    $_SESSION['username']      = $user['username'];
    $_SESSION['role']          = $user['role'];
    $_SESSION['lastActivity']  = time();

    echo json_encode(["success" => true]);
    exit;
}

#endregion

#region SECTION 3 — LOGOUT

if ($action === 'logout') {

    session_unset();
    session_destroy();

    echo json_encode(["success" => true]);
    exit;
}

#endregion

#region SECTION 4 — Invalid Action

echo json_encode([
    "success" => false,
    "message" => "Invalid action."
]);

#endregion