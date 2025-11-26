<?php
/**
 * Skyesoft Deployment Engine (Simple MTCO Version)
 * PHP 8+
 * 
 * Compares SOT (sourceRoot) -> LIVE (targetRoot)
 * Copies changed files safely
 * Creates backups for modified/deleted files
 * Generates a JSON deployment report
 */

declare(strict_types=1);
header("Content-Type: application/json; charset=utf-8");

// -------------------------------------
// Load deploymentConfig.json
// -------------------------------------
$configFile = __DIR__ . "/deploymentConfig.json";

if (!file_exists($configFile)) {
    echo json_encode(["error" => "deploymentConfig.json missing"], JSON_PRETTY_PRINT);
    exit;
}

$config = json_decode(file_get_contents($configFile), true);

$allowedFolders = $config["allowedFolders"];
$exclude        = $config["exclude"];
$sourceRoot     = rtrim($config["sourceRoot"], "/");
$targetRoot     = rtrim($config["targetRoot"], "/");
$backupRetention = (int) $config["backupRetention"];

// -------------------------------------
// Prepare rollback folder
// -------------------------------------
$rollbackFolder = $targetRoot . "/.rollback/" . date("Ymd-His");
if (!is_dir($rollbackFolder)) {
    mkdir($rollbackFolder, 0755, true);
}

// -------------------------------------
// Helper: recursive folder scan
// -------------------------------------
function scanFiles(string $baseDir, array $exclude): array {
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($baseDir));

    foreach ($iterator as $fileinfo) {
        if ($fileinfo->isDir()) continue;

        $rel = str_replace($baseDir . "/", "", $fileinfo->getPathname());

        foreach ($exclude as $skip) {
            if (str_starts_with($rel, $skip)) continue 2;
        }

        $files[] = $rel;
    }
    return $files;
}

// -------------------------------------
// Build file lists
// -------------------------------------
$sotFiles  = scanFiles($sourceRoot, $exclude);
$liveFiles = scanFiles($targetRoot, $exclude);

// -------------------------------------
// Determine delta
// -------------------------------------
$delta = [
    "added"    => [],
    "modified" => [],
    "deleted"  => [],
    "backedUp" => []
];

// added + modified
foreach ($sotFiles as $file) {
    $src = "$sourceRoot/$file";
    $dst = "$targetRoot/$file";

    if (!file_exists($dst)) {
        $delta["added"][] = $file;
    } elseif (md5_file($src) !== md5_file($dst)) {
        $delta["modified"][] = $file;
    }
}

// deleted
foreach ($liveFiles as $file) {
    if (!in_array($file, $sotFiles)) {
        $delta["deleted"][] = $file;
    }
}

// -------------------------------------
// Backup + Apply Changes
// -------------------------------------
foreach ($delta["modified"] as $file) {
    $livePath = "$targetRoot/$file";
    $backupPath = "$rollbackFolder/$file";

    if (!is_dir(dirname($backupPath))) {
        mkdir(dirname($backupPath), 0755, true);
    }

    copy($livePath, $backupPath);
    $delta["backedUp"][] = $file;
}

foreach (["added", "modified"] as $type) {
    foreach ($delta[$type] as $file) {
        $src = "$sourceRoot/$file";
        $dst = "$targetRoot/$file";

        if (!is_dir(dirname($dst))) {
            mkdir(dirname($dst), 0755, true);
        }
        copy($src, $dst);
    }
}

foreach ($delta["deleted"] as $file) {
    @unlink("$targetRoot/$file");
}

// -------------------------------------
// Cleanup old backups (retention)
// -------------------------------------
$rollbackBase = $targetRoot . "/.rollback/";
$rollbackList = array_diff(scandir($rollbackBase), ['.', '..']);

rsort($rollbackList);

if (count($rollbackList) > $backupRetention) {
    $toDelete = array_slice($rollbackList, $backupRetention);
    foreach ($toDelete as $dir) {
        $path = $rollbackBase . $dir;
        exec("rm -rf " . escapeshellarg($path));
    }
}

// -------------------------------------
// Output summary
// -------------------------------------
echo json_encode([
    "status"        => "success",
    "timestamp"     => time(),
    "delta"         => $delta,
    "backupFolder"  => $rollbackFolder,
    "retention"     => $backupRetention
], JSON_PRETTY_PRINT);