<?php
// ======================================================================
//  Skyesoft — merkleVerify.php
//  MIS Verifier (Codex Integrity Checker • Full Recursive Mode)
//  PHP 8.1+ • Strict Typing
// ======================================================================

declare(strict_types=1);
header("Content-Type: application/json; charset=UTF-8");

// ----------------------------------------------------------------------
// Fail Helper
// ----------------------------------------------------------------------
function fail(string $msg): never {
    echo json_encode([
        "success"  => false,
        "misValid" => false,
        "error"    => $msg
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

// ----------------------------------------------------------------------
// Required Paths (MIS Standard)
// ----------------------------------------------------------------------
$root       = dirname(__DIR__);
$codexPath  = $root . "/codex/codex.json";
$treePath   = $root . "/codex/meta/merkleTree.json";
$rootPath   = $root . "/codex/meta/merkleRoot.txt";

if (!file_exists($codexPath))  fail("Codex missing.");
if (!file_exists($treePath))   fail("merkleTree.json missing.");
if (!file_exists($rootPath))   fail("merkleRoot.txt missing.");

// ----------------------------------------------------------------------
// Load Codex + Expected Merkle Root
// ----------------------------------------------------------------------
$codexRaw      = file_get_contents($codexPath);
$codex         = json_decode($codexRaw, true);
$expectedRoot  = trim(file_get_contents($rootPath));
$treeStored    = json_decode(file_get_contents($treePath), true);

if (!is_array($codex))      fail("Codex JSON invalid.");
if (!is_array($treeStored)) fail("Stored Merkle tree JSON invalid.");

// ----------------------------------------------------------------------
// FULL RECURSIVE CHUNKER (same as merkleBuild.php)
// ----------------------------------------------------------------------
function recursiveChunks(mixed $node, string $path = ""): array {
    $chunks = [];

    // Leaf node (scalar)
    if (!is_array($node)) {
        $chunks[$path] = hash(
            "sha256",
            json_encode($node, JSON_UNESCAPED_SLASHES)
        );
        return $chunks;
    }

    // Recurse keys
    ksort($node);

    foreach ($node as $key => $value) {
        $childPath = $path === "" ? $key : "$path.$key";
        $chunks += recursiveChunks($value, $childPath);
    }

    return $chunks;
}

// ----------------------------------------------------------------------
// Recompute leaves exactly as merkleBuild.php does
// ----------------------------------------------------------------------
$computedLeaves = recursiveChunks($codex);

// ----------------------------------------------------------------------
// Build Merkle tree (binary pairing, recursive)
// ----------------------------------------------------------------------
function buildMerkle(array $hashes): array {
    $layer  = array_values($hashes);
    $levels = [$layer];

    while (count($layer) > 1) {
        $next = [];

        for ($i = 0; $i < count($layer); $i += 2) {
            $left  = $layer[$i];
            $right = $layer[$i + 1] ?? $layer[$i];
            $next[] = hash("sha256", $left . $right);
        }

        $layer = $next;
        $levels[] = $layer;
    }

    return [
        "root"   => $layer[0],
        "levels" => $levels
    ];
}

$computedTree = buildMerkle($computedLeaves);
$computedRoot = $computedTree["root"];

// ----------------------------------------------------------------------
// Diff: Identify changed leaves (if any)
// ----------------------------------------------------------------------
$changedLeaves = [];

foreach ($computedLeaves as $path => $hash) {
    $stored = $treeStored["leaves"][$path] ?? null;

    if ($stored === null || $stored !== $hash) {
        $changedLeaves[$path] = [
            "stored"   => $stored,
            "computed" => $hash
        ];
    }
}

// ----------------------------------------------------------------------
// Final Verification Output
// ----------------------------------------------------------------------
$misValid = ($expectedRoot === $computedRoot);

echo json_encode([
    "success"       => true,
    "misValid"      => $misValid,
    "expectedRoot"  => $expectedRoot,
    "computedRoot"  => $computedRoot,
    "leafCount"     => count($computedLeaves),
    "changedLeaves" => $changedLeaves,
    "timestamp"     => time()
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

exit;