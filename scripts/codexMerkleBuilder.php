<?php
declare(strict_types=1);

/**
 * ======================================================================
 * Codex Merkle Builder (Authoritative — Codex-Only Scope)
 *
 * Purpose:
 *   • Build the governed Merkle tree and root exclusively from codex/codex.json
 *   • Enforce single-leaf Merkle design for the Codex artifact
 *   • Guarantee deterministic, reproducible hashing
 *
 * Authoritative input:
 *   - codex/codex.json
 *
 * Authoritative outputs:
 *   - data/records/codexMerkleTree.json
 *   - data/records/codexMerkleRoot.txt
 *
 * Governance rules:
 *   • Scope strictly limited to codex/codex.json
 *   • Deterministic JSON normalization (ksort recursive)
 *   • Single-leaf Merkle tree by design
 *   • No repository-wide hashing
 *
 * ======================================================================
 */

#region SECTION 0 — PATH CONSTANTS

const CODEX_PATH = __DIR__ . '/../codex/codex.json';
const TREE_OUT   = __DIR__ . '/../data/records/codexMerkleTree.json';
const ROOT_OUT   = __DIR__ . '/../data/records/codexMerkleRoot.txt';

#endregion SECTION 0

#region SECTION I — UTILITIES

// Recursively normalize JSON structure for deterministic encoding
function normalizeJson(mixed $data): mixed
{
    if (is_array($data)) {
        ksort($data, SORT_STRING);
        foreach ($data as $key => $value) {
            $data[$key] = normalizeJson($value);
        }
    }
    return $data;
}

// Compute leaf hash (SHA-256)
function hashLeaf(string $value): string
{
    return hash('sha256', $value);
}

// Build full Merkle tree levels from leaves
function buildMerkle(array $leaves): array
{
    $levels  = [];
    $current = $leaves;
    $levels[] = $current;

    while (count($current) > 1) {
        $next = [];
        for ($i = 0; $i < count($current); $i += 2) {
            $left  = $current[$i];
            $right = $current[$i + 1] ?? $left; // Duplicate last if odd count
            $next[] = hash('sha256', $left . $right);
        }
        $current = $next;
        $levels[] = $current;
    }

    return $levels;
}

#endregion SECTION I

#region SECTION II — LOAD & VALIDATE CODEX

if (!file_exists(CODEX_PATH)) {
    fwrite(STDERR, "❌ codex.json not found at " . CODEX_PATH . "\n");
    exit(1);
}

$raw = json_decode(file_get_contents(CODEX_PATH), true);

if ($raw === null || json_last_error() !== JSON_ERROR_NONE) {
    fwrite(STDERR, "❌ Invalid or malformed codex.json\n");
    exit(1);
}

#endregion SECTION II

#region SECTION III — NORMALIZE & HASH CODEX CONTENT

$normalized = normalizeJson($raw);
$encoded    = json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

$contentHash = hash('sha256', $encoded);
$leafHash    = hashLeaf($contentHash);

#endregion SECTION III

#region SECTION IV — BUILD SINGLE-LEAF MERKLE TREE

$leaves = [$leafHash];
$levels = buildMerkle($leaves);

$merkleRoot = $levels[count($levels) - 1][0] ?? null;

if ($merkleRoot === null) {
    fwrite(STDERR, "❌ Failed to compute Merkle root\n");
    exit(1);
}

#endregion SECTION IV

#region SECTION V — PERSIST GOVERNED ARTIFACTS

$treePayload = [
    'meta' => [
        'generatedAt' => time(),
        'generatedBy' => 'scripts/codexMerkleBuilder.php',
        'scope'       => 'codex/codex.json ONLY',
        'codexTier'   => 'Tier-1',
        'state'       => 'GOVERNED',
        'note'        => 'Single-leaf Merkle by design — Codex integrity only'
    ],
    'leaves' => [
        [
            'path'        => '/codex/codex.json',
            'contentHash' => $contentHash,
            'leafHash'    => $leafHash
        ]
    ],
    'levels' => $levels,
    'root'   => $merkleRoot
];

file_put_contents(
    TREE_OUT,
    json_encode($treePayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
);

file_put_contents(ROOT_OUT, $merkleRoot . PHP_EOL);

#endregion SECTION V

#region SECTION VI — SUCCESS REPORT

echo "✔ Codex Merkle rebuilt successfully\n";
echo "• Scope : codex/codex.json ONLY\n";
echo "• Root  : {$merkleRoot}\n";
echo "• Tree  : data/records/codexMerkleTree.json\n";
echo "• Note  : Single-leaf design — Codex edits now correctly trigger merkleIntegrity\n";

#endregion SECTION VI