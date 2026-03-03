<?php
declare(strict_types=1);
session_start();

/*
|--------------------------------------------------------------------------
| Skyesoft — auth.php
| Version: 1.0.0
| Codex Tier: 4 — Session Mutation Endpoint
|
| Role:
|   Authoritative session authentication handler.
|   Handles login + logout only.
|
| Inputs:
|   POST JSON:
|       action   → 'login' | 'logout'
|       username → string  (login only)
|       password → string  (login only)
|
| Outputs:
|   JSON:
|       success  → bool
|       message  → string (optional)
|
| Constraints:
|   • No HTML output
|   • No SSE output
|   • No domain logic
|   • No session projection (handled by sse.php)
|--------------------------------------------------------------------------
*/

header("Content-Type: application/json; charset=UTF-8");

/* ─────────────────────────────────────────────
   Parse JSON input
   ───────────────────────────────────────────── */

$input  = json_decode(file_get_contents("php://input"), true) ?? [];
$action = $input['action'] ?? '';

/* ─────────────────────────────────────────────
   LOGIN
   ───────────────────────────────────────────── */

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

    // DB Connection (must exist in your environment bootstrap)
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

    /* ─────────────────────────────────────────
       Establish Authoritative Session
       ───────────────────────────────────────── */

    $_SESSION['authenticated'] = true;
    $_SESSION['userId']        = $user['id'];
    $_SESSION['username']      = $user['username'];
    $_SESSION['role']          = $user['role'];
    $_SESSION['lastActivity']  = time();

    echo json_encode(["success" => true]);
    exit;
}

/* ─────────────────────────────────────────────
   LOGOUT
   ───────────────────────────────────────────── */

if ($action === 'logout') {

    session_unset();
    session_destroy();

    echo json_encode(["success" => true]);
    exit;
}

/* ─────────────────────────────────────────────
   Invalid Action
   ───────────────────────────────────────────── */

echo json_encode([
    "success" => false,
    "message" => "Invalid action."
]);