<?php
declare(strict_types=1);

// ======================================================================
//  Skyesoft — dbConnect.php
//  Version: 1.0.0
//  Last Updated: 2026-03-04
//  Codex Tier: 3 — Infrastructure / Database Connector
//
//  Role:
//  Provides the authoritative database connection factory for Skyesoft.
//  Exposes a reusable PDO instance via getPDO().
//
//  Inputs:
//   • Secure configuration file
//        /home/notyou64/secure/db.env
//
//  Outputs:
//   • PDO connection object
//
//  Architecture:
//   • Lazy-loaded PDO singleton
//   • Used by API endpoints (auth.php, repositoryAuditor.php, etc.)
//   • No direct output or side effects
//
//  Forbidden:
//   • No HTML output
//   • No SSE output
//   • No session mutation
//   • No Codex mutation
//
//  Notes:
//   • Configuration is stored outside the public web root.
//   • Connection instance is cached for the lifetime of the request.
// ======================================================================

#region SECTION 0 — Configuration Load

$config = parse_ini_file('/home/notyou64/secure/db.env');

if (!$config) {
    throw new RuntimeException("Database config parsing failed.");
}

#endregion

#region SECTION 1 — PDO Factory

function getPDO(): PDO
{
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    $config = parse_ini_file('/home/notyou64/secure/db.env');

    $dsn =
        "mysql:host={$config['DB_HOST']};" .
        "dbname={$config['DB_NAME']};" .
        "charset={$config['DB_CHARSET']}";

    $pdo = new PDO(
        $dsn,
        $config['DB_USER'],
        $config['DB_PASS'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );

    return $pdo;
}

#endregion