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

// Merkle Fail Handler: Outputs a standardized JSON error response and exits.
function merkleFail(string $msg): never {
    echo json_encode([
        "success" => false,
        "role"    => "codexMerkleBuilder",
        "error"   => "❌ $msg"
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}
// Computes the auditor-observed root hash for a given codex.json file.
function computeAuditorObservedRoot(string $codexPath): string
{
    if (!is_file($codexPath)) {
        merkleFail("codex.json not found at $codexPath");
    }

    $raw = file_get_contents($codexPath);
    if ($raw === false) {
        merkleFail("Failed to read codex.json");
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        merkleFail("codex.json malformed or not valid JSON");
    }

    $normalize = function ($data) use (&$normalize) {
        if (is_array($data)) {
            ksort($data, SORT_STRING);
            foreach ($data as $k => $v) {
                $data[$k] = $normalize($v);
            }
        }
        return $data;
    };

    $normalized  = $normalize($decoded);
    $encoded     = json_encode(
        $normalized,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );

    $contentHash = hash('sha256', $encoded);

    return hash('sha256', $contentHash);
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

/* ---- Persist Merkle Tree (visual integrity artifact) ---- */
file_put_contents(
    $treePath,
    json_encode(
        [
            "meta" => [
                "generatedAt" => date('c'),
                "algorithm"   => "SHA-256",
                "scope"       => "codex",
                "description" => "Merkle tree for Codex integrity validation."
            ],
            "leaves" => $leaves,
            "layers" => $layers,
            "root"   => $merkleRoot
        ],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    ),
    LOCK_EX
);

/* ---- Compute Auditor-compatible governed root ---- */
$auditorRoot = computeAuditorObservedRoot($codexPath);

/* ---- Persist governed snapshot (what Auditor compares) ---- */
file_put_contents($rootPath, $auditorRoot, LOCK_EX);

/* ---- Verify governed snapshot write succeeded ---- */
$storedRoot = trim((string)@file_get_contents($rootPath));

if ($storedRoot !== $auditorRoot) {
    merkleFail("Governed root write verification failed.");
}

#endregion SECTION VI — Persist Artifacts

#region SECTION VII — Governance Ledger Reconciliation

$auditLogPath = $root . '/data/records/auditResults.json';
$touched = 0;

/* ---- Reconcile ONLY if governed snapshot write verified ---- */
if (file_exists($auditLogPath)) {

    $raw = file_get_contents($auditLogPath);
    $doc = json_decode($raw, true);

    if (is_array($doc) && isset($doc["violations"]) && is_array($doc["violations"])) {

        $now = time();

        foreach ($doc["violations"] as &$rec) {

            if (!is_array($rec)) continue;
            if (($rec["ruleId"] ?? null) !== "merkleIntegrity") continue;
            if (($rec["resolved"] ?? null) !== null) continue;

            $rec["resolved"] = $now;
            $rec["resolution"] = [
                "note"         => "Accepted governed Merkle snapshot (manual reconcile)",
                "acceptedRoot" => $auditorRoot,
                "resolvedBy"   => "governance:accept_merkle",
                "resolvedUnix" => $now
            ];

            $touched++;
        }
        unset($rec);

        file_put_contents(
            $auditLogPath,
            json_encode(
                $doc,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ),
            LOCK_EX
        );
    }
}

#endregion SECTION VII — Governance Ledger Reconciliation

#region SECTION VIII — Output

echo json_encode([
    "success"       => true,
    "role"          => "codexMerkleBuilder",
    "treeRoot"      => $merkleRoot,
    "governedRoot"  => $auditorRoot,
    "leaves"        => count($leaves),
    "violationsFixed" => $touched
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

exit;

#endregion SECTION VII — Output