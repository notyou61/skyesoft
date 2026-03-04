<?php
declare(strict_types=1);

// ======================================================================
//  Skyesoft — dbConnect.php
//  Version: 1.0.0
//  Last Updated: 2026-03-04
//  Codex Tier: 3 — Infrastructure / Database Connector
//
//  Role:
//   Central PDO factory for Skyesoft API endpoints.
//   Loads DB credentials from secure/db.env (outside public_html).
//
//  Notes:
//   • Uses the same secure-path strategy as askOpenAI.php
//   • db.env must exist alongside secure/.env
// ======================================================================

#region SECTION 0 — Configuration Load

$secureDir = dirname(__DIR__, 3) . "/secure";
$envFile   = $secureDir . "/db.env";

$config = parse_ini_file($envFile);

if (!$config) {
    throw new RuntimeException("Database config parsing failed at: {$envFile}");
}

#endregion

#region SECTION 1 — PDO Factory

function getPDO(): PDO
{
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    $secureDir = dirname(__DIR__, 3) . "/secure";
    $envFile   = $secureDir . "/db.env";

    $config = parse_ini_file($envFile);

    if (!$config) {
        throw new RuntimeException("Database config parsing failed at: {$envFile}");
    }

    $dsn =
        "mysql:host={$config['DB_HOST']};" .
        "dbname={$config['DB_NAME']};" .
        "charset={$config['DB_CHARSET']}";

    $pdo = new PDO(
        $dsn,
        $config['DB_USER'],
        $config['DB_PASS'],
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );

    return $pdo;
}

#endregion