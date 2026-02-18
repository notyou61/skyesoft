<?php

// ── Load .env from /secure (cPanel-safe, absolute anchor) ───────────
$envPath = dirname(__DIR__, 3) . '/secure/.env';

if (!file_exists($envPath)) {
    http_response_code(500);
    exit('Environment file not found.');
}

$env = parse_ini_file($envPath);

$host = $env['DB_HOST'] ?? '';
$db   = $env['DB_NAME'] ?? '';
$user = $env['DB_USER'] ?? '';
$pass = $env['DB_PASS'] ?? '';
$charset = $env['DB_CHARSET'] ?? 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    error_log($e->getMessage());
    http_response_code(500);
    exit('Database connection failed.');
}