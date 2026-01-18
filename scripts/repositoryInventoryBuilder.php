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

// Explicitly excluded namespaces
// These paths are operational tooling or external dependencies
// and are not subject to repository inventory governance.
$excluded = [
    '/.git',
    '/node_modules',
    '/tools/ops'
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

#region SECTION III — FILESYSTEM SCAN (REBUILT, NO HARDCODING)

$items = [];
$dirHasCanonicalChild = [];
$intentionalDirs = [];

// First pass: detect intentional directories via placeholders
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($repoRoot, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

foreach ($iterator as $file) {
    $fullPath = $file->getPathname();
    $relPath  = normalizePath($fullPath, $repoRoot);

    if ($relPath === '' || isExcluded($relPath, $excluded)) {
        continue;
    }

    // Detect placeholder intent (but do not inventory placeholder files)
    if (preg_match('/\/\.(?:keep|gitkeep)$/', $relPath)) {
        $intentionalDirs[dirname('/' . ltrim($relPath, '/'))] = true;
    }
}

// Second pass: inventory canonical artifacts only
foreach ($iterator as $file) {
    /** @var SplFileInfo $file */

    $fullPath = $file->getPathname();
    $relPath  = normalizePath($fullPath, $repoRoot);

    if ($relPath === '' || isExcluded($relPath, $excluded)) {
        continue;
    }

    // Skip placeholder files themselves
    if (preg_match('/\.(?:keep|gitkeep)$/', $relPath)) {
        continue;
    }

    $canonicalPath = '/' . ltrim($relPath, '/');

    // Files must physically exist to be canonical
    if ($file->isFile()) {
        $dirHasCanonicalChild[dirname($canonicalPath)] = true;

        $integrityScope = in_array($canonicalPath, [
            '/data/records/repositoryInventory.json',
            '/data/records/merkleTree.json',
            '/data/records/merkleRoot.txt',
        ], true) ? 'MERKLE_EXCLUDED' : 'MERKLE_INCLUDED';

        $items[] = [
            'id'             => 'TEMP',
            'path'           => $canonicalPath,
            'type'           => 'file',
            'integrityScope' => $integrityScope,
            'purpose'        => 'Pre-SIS auto-generated inventory entry',
        ];
    }
}

// Third pass: include directories only if they have intent
foreach ($iterator as $file) {
    if (!$file->isDir()) {
        continue;
    }

    $relPath = normalizePath($file->getPathname(), $repoRoot);

    if ($relPath === '' || isExcluded($relPath, $excluded)) {
        continue;
    }

    $canonicalPath = '/' . ltrim($relPath, '/');

    if (
        isset($intentionalDirs[$canonicalPath]) ||
        isset($dirHasCanonicalChild[$canonicalPath])
    ) {
        $items[] = [
            'id'             => 'TEMP',
            'path'           => $canonicalPath,
            'type'           => 'dir',
            'integrityScope' => 'MERKLE_INCLUDED',
            'purpose'        => 'Pre-SIS auto-generated inventory entry',
        ];
    }
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