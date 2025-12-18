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

#region CONFIG

$repoRoot   = realpath(__DIR__ . '/..');
// Output path for the generated inventory
$outputPath = $repoRoot . '/data/records/repositoryInventory.json';

$excluded = [
    '/.git',
    '/node_modules',
    '/package.json',
    '/package-lock.json',
    '/repoTree.txt',
    '/tree.txt'
];

#endregion CONFIG

#region HELPERS

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

/**
 * Codex-governed category resolver (path-based only)
 */
function resolveCategory(string $path, bool $isDir): string
{
    if (preg_match('#^/(codex|assets|api|documents|reports|scripts|modules|bulletinBoards|secure)$#', $path)) {
        return 'root';
    }

    if (str_starts_with($path, '/secure/')) {
        return 'security';
    }

    return match (true) {
        str_starts_with($path, '/codex/')        => 'structural',
        str_starts_with($path, '/api/')          => 'execution',
        str_starts_with($path, '/scripts/')      => 'execution',
        str_starts_with($path, '/modules/')      => 'execution',
        str_starts_with($path, '/assets/data/')  => 'system-registry',
        str_starts_with($path, '/assets/')       => 'asset',
        str_starts_with($path, '/documents/')    => 'documentation',
        str_starts_with($path, '/reports/')      => 'system-state',
        default                                  => 'content'
    };
}

/**
 * Tier resolution (pre-SIS safe)
 */
function resolveTier(string $category): string
{
    return match ($category) {
        'structural'      => 'Tier-2',
        'system-registry' => 'Tier-2',
        'security'        => 'Tier-2',
        'execution'       => 'Tier-3',
        'system-state'    => 'Tier-3',
        default           => 'unassigned'
    };
}

#endregion HELPERS

#region SCAN_FILESYSTEM

$items = [];

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($repoRoot, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

foreach ($iterator as $file) {

    $relPath = normalizePath($file->getPathname(), $repoRoot);

    if ($relPath === '') continue;
    if (isExcluded($relPath, $excluded)) continue;
    if (preg_match('/\.keep$|\.gitkeep$/', $relPath)) continue;

    $isDir    = $file->isDir();
    $category = resolveCategory($relPath, $isDir);
    $tier     = resolveTier($category);

    $integrityScope = in_array($relPath, [
        '/data/records/repositoryInventory.json',
        '/data/records/merkleTree.json',
        '/data/records/merkleRoot.txt'
    ], true) ? 'MERKLE_EXCLUDED' : 'MERKLE_INCLUDED';

    $items[] = [
        'id'             => 'TEMP',
        'path'           => $relPath,
        'type'           => $isDir ? 'dir' : 'file',
        'category'       => $category,
        'tier'           => $tier,
        'integrityScope' => $integrityScope,
        'purpose'        => 'Pre-SIS auto-generated inventory entry',
        'status'         => 'active',
        'name'           => basename($relPath)
    ];
}

#endregion SCAN_FILESYSTEM

#region SORT_AND_REINDEX

usort($items, fn ($a, $b) => strcmp($a['path'], $b['path']));

foreach ($items as $i => &$item) {
    $item['id'] = sprintf('RNV-%04d', $i);
}
unset($item);

#endregion SORT_AND_REINDEX

#region OUTPUT — INVENTORY DECLARATION

$inventory = [
    'meta' => [
        'title'       => 'Skyesoft Repository Inventory',
        'generatedAt' => date('c'),
        'generatedBy' => 'scripts/repositoryInventoryBuilder.php',
        'codexTier'   => 'Tier-2',
        'state'       => 'DECLARED',
        'sisPhase'    => 'pre',
        'description' => 'Canonical, exhaustive repository inventory declaring the intended filesystem state under Codex governance.'
    ],
    'items' => $items
];

// Atomic write
$tmpPath = $outputPath . '.tmp';
file_put_contents($tmpPath, json_encode($inventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
rename($tmpPath, $outputPath);

// Human-readable confirmation only
fwrite(STDOUT, "✔ Repository inventory declared successfully\n");
fwrite(STDOUT, "• Items indexed: " . count($items) . "\n");
fwrite(STDOUT, "• Output: /data/records/repositoryInventory.json\n");

#endregion OUTPUT

#region PROMOTION_AND_INTEGRITY_CASCADE

$php     = escapeshellarg(PHP_BINARY);
$scripts = $repoRoot . '/scripts';

// ------------------------------------------------------------------
// Step 2 — Merkle Commit
// ------------------------------------------------------------------
$buildCmd = $php . ' ' . escapeshellarg($scripts . '/merkleBuild.php');
exec($buildCmd, $buildOut, $buildCode);

if ($buildCode !== 0) {
    fwrite(STDOUT, implode("\n", $buildOut) . "\n");
    exit(1);
}

// ------------------------------------------------------------------
// Step 3 — Merkle Verify (capture auditor payload)
// ------------------------------------------------------------------
$verifyCmd = $php . ' ' . escapeshellarg($scripts . '/merkleVerify.php');
exec($verifyCmd, $verifyOut, $verifyCode);

$auditorJson = implode("\n", $verifyOut);

// ------------------------------------------------------------------
// Step 4 — Sentinel consume (STDIN-safe)
// ------------------------------------------------------------------
$sentinelCmd = $php . ' ' . escapeshellarg($scripts . '/sentinel.php');

$descriptors = [
    0 => ['pipe', 'r'], // STDIN
    1 => ['pipe', 'w'], // STDOUT
    2 => ['pipe', 'w'], // STDERR
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

#endregion