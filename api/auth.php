<?php
declare(strict_types=1);
session_start();

header("Content-Type: application/json; charset=UTF-8");

$input = json_decode(file_get_contents("php://input"), true);
$action = $input['action'] ?? '';

if ($action === 'login') {

    $username = trim($input['username'] ?? '');
    $password = trim($input['password'] ?? '');

    $pdo = getPDO(); // your DB connector

    $stmt = $pdo->prepare("
        SELECT id, username, password_hash, role
        FROM users
        WHERE username = :username
        LIMIT 1
    ");

    $stmt->execute(['username' => $username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        echo json_encode(["success" => false, "message" => "User not found."]);
        exit;
    }

    if (!password_verify($password, $user['password_hash'])) {
        echo json_encode(["success" => false, "message" => "Invalid password."]);
        exit;
    }

    $_SESSION['authenticated'] = true;
    $_SESSION['userId']        = $user['id'];
    $_SESSION['username']      = $user['username'];
    $_SESSION['role']          = $user['role'];
    $_SESSION['lastActivity']  = time();

    echo json_encode(["success" => true]);
    exit;
}

if ($action === 'logout') {
    session_unset();
    session_destroy();
    echo json_encode(["success" => true]);
    exit;
}

echo json_encode(["success" => false, "message" => "Invalid action."]);