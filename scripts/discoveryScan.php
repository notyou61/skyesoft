<?php
declare(strict_types=1);

/**
 * discoveryScan.php
 *
 * Purpose:
 *  - One-shot repository discovery scan
 *  - Compare filesystem against repositoryInventory.json (SoT™)
 *  - Report:
 *      • Present but NOT indexed
 *      • Indexed but MISSING
 *  - NO writes, NO mutations, NO audit coupling
 *
 * Usage:
 *  php scripts/discoveryScan.php
 */

$root = realpath(dirname(__DIR__));
$inventoryPath = $root . '/data/records/repositoryInventory.json';

/* ============================================================
 * Canonical path normalization (SINGLE SOURCE OF TRUTH)
 * ========================================================== */
$normalizePath = function (string $absolutePath) use ($root): string {

    // Remove repo root
    $relative = str_replace($root, '', $absolutePath);

    // Normalize separators
    $relative = str_replace('\\', '/', $relative);

    // Trim leading slash
    return ltrim($relative, '/');
};

/* ============================================================
 * Hard exclusions (intentional blind spots)
 * ========================================================== */
$blockedPrefixes = [
    '.git/',
    'node_modules/',
    'vendor/'
];

$blockedExactFiles = [
    '.DS_Store',
    '.gitkeep'
];

/* ============================================================
 * Load inventory (SoT™)
 * ========================================================== */
if (!file_exists($inventoryPath)) {
    fwrite(STDERR, "ERROR: repositoryInventory.json not found\n");
    exit(1);
}

$inventory = json_decode(file_get_contents($inventoryPath), true);
if (
    !is_array($inventory) ||
    !isset($inventory['items']) ||
    !is_array($inventory['items'])
) {
    fwrite(STDERR, "ERROR: repositoryInventory.json malformed\n");
    exit(1);
}

/* ============================================================
 * Build declared path set (canonicalized)
 * ========================================================== */
$declared = [];

foreach ($inventory['items'] as $item) {
    if (!isset($item['path'])) {
        continue;
    }

    $path = str_replace('\\', '/', ltrim($item['path'], '/'));
    $declared[$path] = true;
}

/* ============================================================
 * Scan filesystem
 * ========================================================== */
$observed = [];

$dirIterator = new RecursiveDirectoryIterator(
    $root,
    FilesystemIterator::SKIP_DOTS
);

$filterIterator = new RecursiveCallbackFilterIterator(
    $dirIterator,
    function ($current) use ($normalizePath, $blockedPrefixes) {

        if (!$current instanceof SplFileInfo) {
            return false;
        }

        $fullPath = $current->getPathname();
        if ($fullPath === null) {
            return false;
        }

        $relative = $normalizePath($fullPath);

        if ($current->isDir()) {
            foreach ($blockedPrefixes as $blocked) {
                $blocked = rtrim($blocked, '/');

                if (
                    $relative === $blocked ||
                    str_starts_with($relative, $blocked . '/')
                ) {
                    return false; // ⛔ prune traversal
                }
            }
        }

        return true;
    }
);

$iterator = new RecursiveIteratorIterator(
    $filterIterator,
    RecursiveIteratorIterator::SELF_FIRST
);

foreach ($iterator as $fileInfo) {

    $fullPath = $fileInfo->getPathname();
    if ($fullPath === null) {
        continue;
    }

    $relative = $normalizePath($fullPath);

    if (in_array(basename($relative), $blockedExactFiles, true)) {
        continue;
    }

    $observed[$relative] = $fileInfo->isDir() ? 'dir' : 'file';
}

/* ============================================================
 * Compute diffs (canonical ↔ canonical)
 * ========================================================== */
$unindexed = array_diff_key($observed, $declared);
$missing   = array_diff_key($declared, $observed);

/* ============================================================
 * Output
 * ========================================================== */
echo "\n=== Repository Discovery Scan ===\n";
echo "Root: {$root}\n\n";

echo "--- Present but NOT indexed ---\n";
if (empty($unindexed)) {
    echo "(none)\n";
} else {
    foreach (array_keys($unindexed) as $path) {
        echo $path . "\n";
    }
}

echo "\n--- Indexed but MISSING ---\n";
if (empty($missing)) {
    echo "(none)\n";
} else {
    foreach (array_keys($missing) as $path) {
        echo $path . "\n";
    }
}

echo "\n--- Summary ---\n";
echo "Indexed artifacts:  " . count($declared) . "\n";
echo "Observed artifacts: " . count($observed) . "\n";
echo "Unindexed artifacts: " . count($unindexed) . "\n";
echo "Missing artifacts:   " . count($missing) . "\n\n";

exit(0);