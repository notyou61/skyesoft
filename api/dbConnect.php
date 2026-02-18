<?php

// ── Load DB credentials from /secure/db.env ───────────
$dbEnvPath = dirname(__DIR__, 3) . '/secure/db.env';

if (!file_exists($dbEnvPath)) {
    die('Database config file not found.');
}

$dbConfig = parse_ini_file($dbEnvPath, false, INI_SCANNER_TYPED);

if ($dbConfig === false) {
    die('Database config parsing failed.');
}

$host = $dbConfig['DB_HOST'] ?? '';
$db   = $dbConfig['DB_NAME'] ?? '';
$user = $dbConfig['DB_USER'] ?? '';
$pass = $dbConfig['DB_PASS'] ?? '';
$charset = $dbConfig['DB_CHARSET'] ?? 'utf8mb4';

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