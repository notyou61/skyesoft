<?php
declare(strict_types=1);

// ======================================================================
// Skyesoft — dbConnect.php
// Centralized PDO Connection Manager
// ======================================================================

require_once __DIR__ . '/utils/envLoader.php';

function getPDO(bool $forceReconnect = false): PDO
{
    static $pdo = null;

    // Force disposal of cached connection when requested
    if ($forceReconnect) {
        $pdo = null;
    }

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    // Load environment variables
    skyesoftLoadEnv();

    $dbHost = getenv('DB_HOST') ?: 'localhost';
    $dbName = getenv('DB_NAME') ?: '';
    $dbUser = getenv('DB_USER') ?: '';
    $dbPass = getenv('DB_PASS') ?: '';
    $dbChar = getenv('DB_CHARSET') ?: 'utf8mb4';

    if (!$dbUser || !$dbPass) {
        throw new RuntimeException("Database credentials missing from environment.");
    }

    $dsn =
        "mysql:host={$dbHost};" .
        "dbname={$dbName};" .
        "charset={$dbChar}";

    $pdo = new PDO(
        $dsn,
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false
        ]
    );

    return $pdo;
}