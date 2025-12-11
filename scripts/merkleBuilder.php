<?php
// ======================================================================
//  Skyesoft — merkleBuilder.php
//  Codex Merkle Tree Generator • PHP 8.3
//  Implements: Merkle Integrity Standard (MIS)
// ======================================================================

declare(strict_types=1);

# -------------------------------------------------------------
# Load Codex
# -------------------------------------------------------------
$codexPath = __DIR__ . "/../codex/codex.json";
if (!file_exists($codexPath)) {
    echo "Codex missing.\n";
    exit(1);
}
$codexRaw = file_get_contents($codexPath);
$codex = json_decode($codexRaw, true);

# -------------------------------------------------------------
# Helper: deterministic hashing
# -------------------------------------------------------------
function hashNode(string $data): string {
    return hash("sha256", $data);
}

# -------------------------------------------------------------
# Helper: deterministic key-order serialization
# -------------------------------------------------------------
function stableJSON($value): string {
    if (is_array($value)) {
        if (array_keys($value) !== range(0, count($value)-1)) {
            ksort($value); // associative
        }
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = $v;
        }
        return json_encode($out, JSON_UNESCAPED_SLASHES);
    }
    return json_encode($value, JSON_UNESCAPED_SLASHES);
}

# -------------------------------------------------------------
# Step 1: build leaf hashes (top-level sections only)
# -------------------------------------------------------------
$leaves = [];
foreach ($codex as $key => $section) {
    $serialized = stableJSON($section);
    $leaves[$key] = hashNode($serialized);
}

# -------------------------------------------------------------
# Step 2: build Merkle tree upward
# -------------------------------------------------------------
$nodes = [];
$current = array_values($leaves);

while (count($current) > 1) {
    $next = [];
    for ($i = 0; $i < count($current); $i += 2) {
        $a = $current[$i];
        $b = $current[$i+1] ?? $a; // duplicate last if odd count
        $combined = hashNode($a . $b);
        $nodes[] = $combined;
        $next[] = $combined;
    }
    $current = $next;
}

$merkleRoot = $current[0];

# -------------------------------------------------------------
# Step 3: write outputs
# -------------------------------------------------------------
$treeOut = [
    "meta" => [
        "version" => "1.0.0",
        "generatedAt" => time(),
        "algorithm" => "SHA-256",
        "description" => "Merkle Tree for Codex integrity validation."
    ],
    "leaves" => $leaves,
    "nodes"  => $nodes,
    "root"   => $merkleRoot
];

file_put_contents(__DIR__ . "/../codex/meta/merkleTree.json", json_encode($treeOut, JSON_PRETTY_PRINT));
file_put_contents(__DIR__ . "/../codex/meta/merkleRoot.txt", $merkleRoot);

echo "Merkle Tree generated. Root: $merkleRoot\n";