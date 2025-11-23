<?php
// =====================================================================
// Skyesoft Cron Runner
// Executes once per minute via GoDaddy cron
// Calls: scripts/git-version-check.php
// PHP 5.6 safe
// =====================================================================

// Disable output buffering (required for cron)
if (function_exists('ob_end_clean')) { @ob_end_clean(); }

// Set correct paths
$root = realpath(__DIR__ . '/..');         // /skyesoft/api/..
$scripts = $root . '/scripts';             // /skyesoft/scripts
$data    = $root . '/assets/data';         // /skyesoft/assets/data

$versionScript = $scripts . '/git-version-check.php';

// Validate existence
if (!file_exists($versionScript)) {
    header('Content-Type: application/json');
    echo json_encode(array(
        "success" => false,
        "error"   => "git-version-check.php missing",
        "path"    => $versionScript
    ));
    exit;
}

// Execute the version update logic
$result = include $versionScript;

// If script returned output, forward it
header('Content-Type: application/json');
echo json_encode(array(
    "success" => true,
    "cron"    => "executed",
    "result"  => $result
));
exit;