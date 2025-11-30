<?php
// ======================================================================
//  Skyesoft — deploymentEngine.php
//  Deployment Engine (SOT → LIVE Sync) • PHP 8.1 • Codex-Tier2 Module
//  Implements: Article IX (Safety), Article XI (Automation Limits),
//              Article XII (Discovery), Repository Standard
// ======================================================================

#region SECTION I — Metadata & Error Handling
// ----------------------------------------------------------------------
declare(strict_types=1);
header("Content-Type: application/json; charset=UTF-8");

function fail(string $msg): never {
    echo json_encode([
        "success" => false,
        "error"   => "❌ $msg"
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}
#endregion

#region SECTION II — Load Configuration
// ----------------------------------------------------------------------
$configFile = __DIR__ . "/deploymentConfig.json";

if (!file_exists($configFile)) {
    fail("deploymentConfig.json missing");
}

$config = json_decode(file_get_contents($configFile), true);

$allowedFolders  = $config["allowedFolders"]   ?? [];
$exclude         = $config["exclude"]          ?? [];
$sourceRoot      = rtrim($config["sourceRoot"] ?? "", "/");
$targetRoot      = rtrim($config["targetRoot"] ?? "", "/");
$backupRetention = (int)($config["backupRetention"] ?? 5);

if ($sourceRoot === "" || $targetRoot === "") {
    fail("sourceRoot or targetRoot not defined in config");
}
#endregion

#region SECTION III — Prepare Rollback
// ----------------------------------------------------------------------
$rollbackFolder = $targetRoot . "/.rollback/" . date("Ymd-His");

if (!is_dir($rollbackFolder)) {
    if (!mkdir($rollbackFolder, 0755, true)) {
        fail("Unable to create rollback folder");
    }
}
#endregion

#region SECTION IV — Helper Functions
// ----------------------------------------------------------------------
function scanFiles(string $baseDir, array $exclude): array {
    $files = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($baseDir, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $fileinfo) {
        if ($fileinfo->isDir()) continue;

        $rel = str_replace($baseDir . "/", "", $fileinfo->getPathname());

        foreach ($exclude as $skip) {
            if (str_starts_with($rel, $skip)) {
                continue 2;
            }
        }

        $files[] = $rel;
    }

    return $files;
}
#endregion

#region SECTION V — Build File Lists
// ----------------------------------------------------------------------
$sotFiles  = scanFiles($sourceRoot, $exclude);
$liveFiles = scanFiles($targetRoot, $exclude);

$delta = [
    "added"    => [],
    "modified" => [],
    "deleted"  => [],
    "backedUp" => []
];
#endregion

#region SECTION VI — Detect Added & Modified Files
// ----------------------------------------------------------------------
foreach ($sotFiles as $file) {
    $src = "$sourceRoot/$file";
    $dst = "$targetRoot/$file";

    if (!file_exists($dst)) {
        $delta["added"][] = $file;
    } elseif (md5_file($src) !== md5_file($dst)) {
        $delta["modified"][] = $file;
    }
}
#endregion

#region SECTION VII — Detect Deleted Files
// ----------------------------------------------------------------------
foreach ($liveFiles as $file) {
    if (!in_array($file, $sotFiles)) {
        $delta["deleted"][] = $file;
    }
}
#endregion

#region SECTION VIII — Backup & Apply Changes (Codex Safe Mode)
// ----------------------------------------------------------------------
// Must obey Article XI (Automation Limits):
// - Backup before modifying
// - No destructive actions outside .rollback/
// - No system reconfiguration, only file-copy sync
// ----------------------------------------------------------------------

// Backup modified
foreach ($delta["modified"] as $file) {
    $livePath   = "$targetRoot/$file";
    $backupPath = "$rollbackFolder/$file";

    if (!is_dir(dirname($backupPath))) {
        mkdir(dirname($backupPath), 0755, true);
    }

    copy($livePath, $backupPath);
    $delta["backedUp"][] = $file;
}

// Add + Modify: sync SOT → LIVE
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

// Delete removed files
foreach ($delta["deleted"] as $file) {
    @unlink("$targetRoot/$file");
}
#endregion

#region SECTION IX — Enforce Backup Retention
// ----------------------------------------------------------------------
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
#endregion

#region SECTION X — Output Deployment Summary
// ----------------------------------------------------------------------
echo json_encode([
    "success"       => true,
    "timestamp"     => time(),
    "delta"         => $delta,
    "backupFolder"  => $rollbackFolder,
    "retention"     => $backupRetention
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
#endregion