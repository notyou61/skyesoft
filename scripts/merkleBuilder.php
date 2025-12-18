<?php
declare(strict_types=1);

// ======================================================================
// Skyesoft — Codex Merkle Builder
// Tier: 3 — Codex Integrity Builder (CIS)
// PHP 8.3+ • Strict Typing
//
// Responsibility:
//   • Generate Merkle tree for Codex content integrity
//   • Hash top-level Codex sections deterministically
//   • Persist Codex integrity artifacts to records domain
//
// Forbidden:
//   • NO repository scanning
//   • NO inventory logic
//   • NO Sentinel invocation
// ======================================================================

header("Content-Type: application/json; charset=UTF-8");

#region SECTION 0 — Fail Handler

function merkleFail(string $msg): never {
    echo json_encode([
        "success" => false,
        "role"    => "codexMerkleBuilder",
        "error"   => "❌ $msg"
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

#endregion SECTION 0 — Fail Handler

#region SECTION I — Resolve Paths

$root = realpath(__DIR__ . '/..');
if (!$root) {
    merkleFail("Unable to resolve repository root.");
}

$codexPath = $root . '/codex/codex.json';
$treePath  = $root . '/data/records/codexMerkleTree.json';
$rootPath  = $root . '/data/records/codexMerkleRoot.txt';

if (!file_exists($codexPath)) {
    merkleFail("codex.json missing.");
}

#endregion SECTION I — Resolve Paths

#region SECTION II — Load Codex

$codexRaw = file_get_contents($codexPath);
$codex    = json_decode($codexRaw, true);

if (!is_array($codex)) {
    merkleFail("Invalid codex.json structure.");
}

#endregion SECTION II — Load Codex

#region SECTION III — Deterministic Serialization

function stableJSON(mixed $value): string {
    if (is_array($value)) {
        if (array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value);
        }
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = $v;
        }
        return json_encode($out, JSON_UNESCAPED_SLASHES);
    }
    return json_encode($value, JSON_UNESCAPED_SLASHES);
}

function hashNode(string $data): string {
    return hash('sha256', $data);
}

#endregion SECTION III — Deterministic Serialization

#region SECTION IV — Build Leaf Hashes (Top-Level Codex Sections)

$leaves = [];

foreach ($codex as $sectionKey => $sectionData) {
    $serialized = stableJSON($sectionData);
    $leaves[$sectionKey] = hashNode($serialized);
}

ksort($leaves); // deterministic ordering

#endregion SECTION IV — Build Leaf Hashes

#region SECTION V — Build Merkle Tree

$layers   = [];
$current  = array_values($leaves);
$layers[] = $current;

while (count($current) > 1) {
    $next = [];

    for ($i = 0; $i < count($current); $i += 2) {
        $left  = $current[$i];
        $right = $current[$i + 1] ?? $left;
        $next[] = hashNode($left . $right);
    }

    $layers[] = $next;
    $current  = $next;
}

$merkleRoot = $current[0] ?? null;

if (!$merkleRoot) {
    merkleFail("Unable to compute Codex Merkle root.");
}

#endregion SECTION V — Build Merkle Tree

#region SECTION VI — Persist Artifacts

file_put_contents(
    $treePath,
    json_encode([
        "meta" => [
            "generatedAt" => date('c'),
            "algorithm"   => "SHA-256",
            "scope"       => "codex",
            "description" => "Merkle tree for Codex integrity validation."
        ],
        "leaves" => $leaves,
        "layers" => $layers,
        "root"   => $merkleRoot
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
);

file_put_contents($rootPath, $merkleRoot);

#endregion SECTION VI — Persist Artifacts

#region SECTION VII — Output

echo json_encode([
    "success" => true,
    "role"    => "codexMerkleBuilder",
    "root"    => $merkleRoot,
    "leaves"  => count($leaves)
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

exit;

#endregion SECTION VII — Output