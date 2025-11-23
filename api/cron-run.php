<?php
// ======================================================================
// Skyesoft — Cron Runner
// Executes git-version-check.php safely
// Outputs ONLY JSON (no warnings, no HTML)
// ======================================================================

// Prevent any accidental warnings from breaking JSON
error_reporting(E_ALL);
ini_set('display_errors', 0);
ob_clean();

header('Content-Type: application/json');

// --------------------------------------------------------------
// Resolve paths
// --------------------------------------------------------------
$rootPath = realpath(dirname(__DIR__));
$scriptPath = $rootPath . '/scripts/git-version-check.php';

// --------------------------------------------------------------
// Safety checks
// --------------------------------------------------------------
if (!file_exists($scriptPath)) {
    echo json_encode([
        "success" => false,
        "cron"    => "error",
        "message" => "git-version-check.php not found",
        "path"    => $scriptPath
    ]);
    exit;
}

// --------------------------------------------------------------
// Execute git-version-check.php as safe include
// --------------------------------------------------------------
$result = include $scriptPath;

// If include returned non-array due to warning → sanitize
if (!is_array($result)) {
    $result = [
        "success" => false,
        "error"   => "git-version-check.php returned invalid data"
    ];
}

// --------------------------------------------------------------
// Log Cron Events
// --------------------------------------------------------------
$logPath = $rootPath . "/logs/version-events.log";
$logMsg  = date('Y-m-d H:i:s') . " — Cron executed\n";
@file_put_contents($logPath, $logMsg, FILE_APPEND);

// --------------------------------------------------------------
// Output clean JSON
// --------------------------------------------------------------
echo json_encode([
    "success" => true,
    "cron"    => "executed",
    "result"  => $result
], JSON_PRETTY_PRINT);

exit;
