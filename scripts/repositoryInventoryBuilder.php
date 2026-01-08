<?php
declare(strict_types=1);

// ======================================================================
// Skyesoft — Repository Inventory Builder
// Codex Tier-3 Tooling (Explicitly Excluded from Inventory)
// PHP 8.3 Compatible
//
// Responsibility:
//   • Declare the canonical repository inventory
//   • Promote a new canonical state via Merkle commitment
//   • Verify integrity and register results with Sentinel
//
// Forbidden:
//   • NO Merkle logic implementation
//   • NO Sentinel logic implementation
//   • NO Codex mutation
// ======================================================================

ini_set('display_errors', '0');
error_reporting(0);

// Ensure clean, pipe-safe output
while (ob_get_level()) {
    ob_end_clean();
}

#region SECTION I — CONFIGURATION
$repoRoot   = realpath(__DIR__ . '/..');
$outputPath = $repoRoot . '/data/records/repositoryInventory.json';

$excluded = [
    '/.git',
    '/node_modules'
];

#endregion SECTION I

#region SECTION II — PATH UTILITIES

function normalizePath(string $fullPath, string $root): string
{
    return str_replace('\\', '/', str_replace($root, '', $fullPath));
}

function isExcluded(string $path, array $excluded): bool
{
    $path = rtrim($path, '/');

    foreach ($excluded as $ex) {
        $ex = rtrim($ex, '/');
        if ($path === $ex || str_starts_with($path . '/', $ex . '/')) {
            return true;
        }
    }
    return false;
}

#endregion SECTION II

#region SECTION III — FILESYSTEM SCAN

$items = [];

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($repoRoot, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

foreach ($iterator as $file) {
    /** @var SplFileInfo $file */

    $fullPath = $file->getPathname();
    $relPath  = normalizePath($fullPath, $repoRoot);

    // Skip the root directory itself
    if ($relPath === '') {
        continue;
    }

    // Skip explicitly excluded paths (e.g. .git, node_modules)
    if (isExcluded($relPath, $excluded)) {
        continue;
    }

    // Skip placeholder .keep files
    if (preg_match('/\.(?:keep|gitkeep)$/', $relPath)) {
        continue;
    }

    // Force canonical root-anchored path — this is now the single source of truth
    $canonicalPath = '/' . ltrim($relPath, '/');

    // Determine Merkle integrity scope using the canonical path
    $integrityScope = in_array($canonicalPath, [
        '/data/records/repositoryInventory.json',
        '/data/records/merkleTree.json',
        '/data/records/merkleRoot.txt',
    ], true) ? 'MERKLE_EXCLUDED' : 'MERKLE_INCLUDED';

    $items[] = [
        'id'             => 'TEMP',
        'path'           => $canonicalPath,           // Always root-anchored e.g. "/api", "/api/askOpenAI.php"
        'type'           => $file->isDir() ? 'dir' : 'file',
        'integrityScope' => $integrityScope,
        'purpose'        => 'Pre-SIS auto-generated inventory entry',
    ];
}

#endregion SECTION III

#region SECTION III+ — QUANTITATIVE COUNTS (NEW)

$dirCount  = 0;
$fileCount = 0;

foreach ($items as $item) {
    if ($item['type'] === 'dir') {
        $dirCount++;
    } else {
        $fileCount++;
    }
}

#endregion SECTION III+

#region SECTION IV — SORTING & ID ASSIGNMENT

usort($items, fn ($a, $b) => strcmp($a['path'], $b['path']));

foreach ($items as $i => &$item) {
    $item['id'] = sprintf('RNV-%04d', $i);
}
unset($item);

#endregion SECTION IV

#region SECTION IV+ — INVENTORY RUN COUNTER (NEW)

$newInventoryRuns = 1;

if (file_exists($outputPath)) {
    $existing = json_decode(file_get_contents($outputPath), true);
    // Check for existing run count
    if (isset($existing['meta']['newInventoryRuns'])) {
        $newInventoryRuns = (int)$existing['meta']['newInventoryRuns'] + 1;
    }
}

#endregion SECTION IV+

#region SECTION V — INVENTORY DECLARATION OUTPUT

$inventory = [
    'meta' => [
        'title'              => 'Skyesoft Repository Inventory',
        'generatedAt'        => time(), // UNIX epoch (governed)
        'generatedBy'        => 'scripts/repositoryInventoryBuilder.php',
        'codexTier'          => 'Tier-2',
        'state'              => 'DECLARED',
        'purpose'            => 'Declared repository inventory entry',
        'description'        => 'Canonical, exhaustive repository inventory declaring the intended filesystem state under Codex governance.',

        // ✳️ NEW GOVERNANCE METADATA
        'newInventoryRuns'   => $newInventoryRuns,
        'itemsDeclared'      => count($items),
        'directoriesDeclared'=> $dirCount,
        'filesDeclared'      => $fileCount,
    ],
    'items' => $items
];

// Atomic write
$tmpPath = $outputPath . '.tmp';
file_put_contents(
    $tmpPath,
    json_encode($inventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
);
rename($tmpPath, $outputPath);

// Human-readable confirmation only
fwrite(STDOUT, "✔ Repository inventory declared successfully\n");
fwrite(STDOUT, "• Items indexed: " . count($items) . "\n");
fwrite(STDOUT, "• Output: /data/records/repositoryInventory.json\n");

#endregion SECTION V

#region SECTION VI — PROMOTION & INTEGRITY CASCADE

$php     = escapeshellarg(PHP_BINARY);
$scripts = $repoRoot . '/scripts';

// Step VI.A — Merkle Commit
$buildCmd = $php . ' ' . escapeshellarg($scripts . '/merkleBuild.php');
exec($buildCmd, $buildOut, $buildCode);

if ($buildCode !== 0) {
    fwrite(STDOUT, implode("\n", $buildOut) . "\n");
    exit(1);
}

// Step VI.B — Merkle Verify (capture auditor payload)
$verifyCmd = $php . ' ' . escapeshellarg($scripts . '/merkleVerify.php');
exec($verifyCmd, $verifyOut, $verifyCode);

$auditorJson = implode("\n", $verifyOut);

// Step VI.C — Sentinel Consumption (STDIN-safe)
$sentinelCmd = $php . ' ' . escapeshellarg($scripts . '/sentinel.php');

$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];

$process = proc_open($sentinelCmd, $descriptors, $pipes);

if (!is_resource($process)) {
    fwrite(STDOUT, "❌ Unable to invoke sentinel\n");
    exit(1);
}

fwrite($pipes[0], $auditorJson);
fclose($pipes[0]);

$sentinelOut = stream_get_contents($pipes[1]);
fclose($pipes[1]);

proc_close($process);

// Emit final canonical result
fwrite(STDOUT, $sentinelOut . "\n");
exit;

#endregion SECTION VI