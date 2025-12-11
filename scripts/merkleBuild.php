<?php
// ======================================================================
//  Skyesoft — merkleBuild.php
//  MIS Builder (Codex Integrity Generator • Full Recursive Mode)
//  PHP 8.1+ • Strict Typing
// ======================================================================

declare(strict_types=1);
header("Content-Type: application/json; charset=UTF-8");

// ----------------------------------------------------------------------
// Fail Helper
// ----------------------------------------------------------------------
function fail(string $msg): never {
    echo json_encode([
        "success" => false,
        "error"   => $msg
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

// ----------------------------------------------------------------------
// Load Codex
// ----------------------------------------------------------------------
$root = dirname(__DIR__);
$codexPath = $root . "/codex/codex.json";
$treePath  = $root . "/codex/meta/merkleTree.json";
$rootPath  = $root . "/codex/meta/merkleRoot.txt";

if (!file_exists($codexPath)) fail("Codex missing.");

$raw = file_get_contents($codexPath);
$codex = json_decode($raw, true);

if (!is_array($codex)) fail("Codex JSON invalid.");

// ======================================================================
// FULL RECURSIVE CHUNKER (MIS Option A)
// ======================================================================
function recursiveChunks(mixed $node, string $path = ""): array {
    $chunks = [];

    // Leaf node (scalar)
    if (!is_array($node)) {
        $chunks[$path] = hash("sha256", json_encode($node, JSON_UNESCAPED_SLASHES));
        return $chunks;
    }

    // Object or array → recurse keys
    ksort($node); // MIS rule: lexicographic order

    foreach ($node as $key => $value) {
        $childPath = $path === "" ? $key : "$path.$key";
        $chunks += recursiveChunks($value, $childPath);
    }

    return $chunks;
}

// ======================================================================
// BUILD LEAVES
// ======================================================================
$leaves = recursiveChunks($codex);

// ----------------------------------------------------------------------
// Build Internal Nodes (deterministic binary merkle layering)
// ----------------------------------------------------------------------
function buildMerkleTree(array $hashes): array {

    // Convert to simple numeric array of hashes
    $layer = array_values($hashes);
    $layers = [$layer];

    while (count($layer) > 1) {
        $next = [];

        for ($i = 0; $i < count($layer); $i += 2) {
            $left  = $layer[$i];
            $right = $layer[$i + 1] ?? $layer[$i]; // duplicate if odd count

            $next[] = hash("sha256", $left . $right);
        }

        $layer = $next;
        $layers[] = $layer;
    }

    return [
        "root"  => $layer[0],
        "layers"=> $layers
    ];
}

$tree = buildMerkleTree($leaves);
$rootHash = $tree["root"];

// ======================================================================
// WRITE OUTPUT FILES (Codex SOT)
// ======================================================================

file_put_contents(
    $treePath,
    json_encode([
        "generated" => time(),
        "algo"      => "sha256",
        "leafCount" => count($leaves),
        "root"      => $rootHash,
        "leaves"    => $leaves,
        "layers"    => $tree["layers"]
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
);

file_put_contents($rootPath, $rootHash);

// ======================================================================
// Output
// ======================================================================
echo json_encode([
    "success"   => true,
    "message"   => "Merkle Tree generated (Full Recursive Mode).",
    "leafCount" => count($leaves),
    "root"      => $rootHash
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

exit;