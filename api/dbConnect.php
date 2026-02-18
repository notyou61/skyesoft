<?php

// ── Load .env from /secure ───────────
$envPath = dirname(__DIR__, 3) . '/secure/.env';

if (!file_exists($envPath)) {
    die('Environment file not found.');
}

$env = parse_ini_file($envPath, false, INI_SCANNER_TYPED);

if ($env === false) {
    die('Environment file parsing failed.');
}

$host = $env['DB_HOST'] ?? '';
$db   = $env['DB_NAME'] ?? '';
$user = $env['DB_USER'] ?? '';
$pass = $env['DB_PASS'] ?? '';
$charset = $env['DB_CHARSET'] ?? 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);

} catch (PDOException $e) {
    die('Database connection failed: ' . $e->getMessage());
}