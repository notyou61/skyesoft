<?php
// ======================================================================
//  FILE: repositoryCleanup.php
//  PURPOSE: Quarantine Obsolete Files Identified by Repository Audit
//  VERSION: v1.0.0
//  AUTHOR: CPAP-01 Parliamentarian Integration
// ======================================================================

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Resolve project root (skyesoft)
$root = realpath(dirname(__DIR__));
if ($root === false) {
    exit("❌ Unable to resolve project root.\n");
}

$auditPath = $root . '/api/repositoryAudit.json';
if (!file_exists($auditPath)) {
    exit("❌ repositoryAudit.json not found at $auditPath\n");
}

$json = file_get_contents($auditPath);
$data = json_decode($json, true);
if (!is_array($data) || !isset($data['obsoleteFiles'])) {
    exit("❌ Invalid or empty repositoryAudit.json structure.\n");
}

$obsolete = $data['obsoleteFiles'];
$timestamp = date('Y-m-d_H-i-s');
$quarantineDir = $root . '/quarantine/' . $timestamp;

if (!is_dir($quarantineDir)) {
    mkdir($quarantineDir, 0777, true);
}

$logPath = $root . '/scripts/cleanup-log.txt';
$log = fopen($logPath, 'a');
fwrite($log, "🧹 Repository Cleanup – " . date('Y-m-d H:i:s') . "\n");
fwrite($log, str_repeat('-', 60) . "\n");

$movedCount = 0;
$skippedCount = 0;

foreach ($obsolete as $file) {
    $file = str_replace('\\', '/', $file);
    $source = $root . '/' . ltrim($file, '/');
    $destination = $quarantineDir . '/' . basename($file);

    if (file_exists($source)) {
        if (@rename($source, $destination)) {
            fwrite($log, "✔️  Moved → $file\n");
            $movedCount++;
        } else {
            fwrite($log, "⚠️  Failed to move → $file\n");
        }
    } else {
        fwrite($log, "❌  Missing → $file\n");
        $skippedCount++;
    }
}

fwrite($log, str_repeat('-', 60) . "\n");
fwrite($log, "✅ Total moved: $movedCount | ❌ Missing: $skippedCount\n\n");
fclose($log);

echo "✅ Cleanup completed.\n";
echo "🗂️  Quarantine: $quarantineDir\n";
echo "📄 Log: $logPath\n";