<?php
// ======================================================================
//  Skyesoft — auditor.php
//  Structural Auditor (EGS + MIS Integration)
//  PHP 8.1+ • Strict Typing
//  Purpose: Detect structural issues, Codex drift, Merkle mismatch,
//           inventory violations, missing SOT files, unapproved paths.
//  Forbidden: Writing to ANY SOT file.
// ======================================================================

declare(strict_types=1);
header("Content-Type: application/json; charset=UTF-8");

// ======================================================================
// Fail Handler
// ======================================================================
function auditorFail(string $msg): never {
    echo json_encode([
        "success" => false,
        "role"    => "auditor",
        "error"   => $msg
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

// ======================================================================
// Load SOT Files (Read-Only)
// ======================================================================
$root = dirname(__DIR__);

$codexPath   = $root . "/codex/codex.json";
$inventoryPath = $root . "/assets/data/repositoryInventory.json";
$errorRegistry = $root . "/assets/data/errorRegistry.json";
$auditLog      = $root . "/assets/data/repositoryAudit.json";

$merkleTreePath = $root . "/codex/meta/merkleTree.json";
$merkleRootPath = $root . "/codex/meta/merkleRoot.txt";

foreach ([
    "Codex"                     => $codexPath,
    "repositoryInventory.json"  => $inventoryPath,
    "errorRegistry.json"        => $errorRegistry,
    "repositoryAudit.json"      => $auditLog,
    "merkleTree.json"           => $merkleTreePath,
    "merkleRoot.txt"            => $merkleRootPath
] as $label => $path) {
    if (!file_exists($path)) {
        auditorFail("$label missing at $path");
    }
}

$codex        = json_decode(file_get_contents($codexPath), true);
$inventory    = json_decode(file_get_contents($inventoryPath), true);
$merkleTree   = json_decode(file_get_contents($merkleTreePath), true);
$storedRoot   = trim(file_get_contents($merkleRootPath));

if (!is_array($codex))     auditorFail("Codex JSON invalid.");
if (!is_array($inventory)) auditorFail("Repository inventory invalid.");
if (!is_array($merkleTree)) auditorFail("Merkle tree JSON invalid.");

if (!preg_match("/^[a-f0-9]{64}$/", $storedRoot)) {
    auditorFail("Stored Merkle root is invalid.");
}

// ======================================================================
// MIS RECURSIVE CHUNKING (must match merkleBuild.php exactly)
// ======================================================================
function recursiveChunks(mixed $node, string $path = ""): array {
    $chunks = [];

    if (!is_array($node)) {
        $chunks[$path] = hash("sha256", json_encode($node, JSON_UNESCAPED_SLASHES));
        return $chunks;
    }

    ksort($node);

    foreach ($node as $key => $value) {
        $full = $path === "" ? $key : "$path.$key";
        $chunks += recursiveChunks($value, $full);
    }

    return $chunks;
}

function buildMerkle(array $leaves): string {
    $layer = array_values($leaves);

    while (count($layer) > 1) {
        $next = [];

        for ($i = 0; $i < count($layer); $i += 2) {
            $left  = $layer[$i];
            $right = $layer[$i+1] ?? $layer[$i];
            $next[] = hash("sha256", $left . $right);
        }

        $layer = $next;
    }

    return $layer[0];
}

// ======================================================================
// AUDIT FUNCTION
// ======================================================================
function runAudit(array $codex, array $inventory, array $merkleTree, string $storedRoot): array {

    $findings = [];

    // --------------------------------------------------
    // (1) Required Codex Sections
    // --------------------------------------------------
    foreach (["meta", "constitution", "standards", "modules"] as $section) {
        if (!array_key_exists($section, $codex)) {
            $findings[] = [
                "type"        => "syntax_error",
                "name"        => "Codex Missing Section",
                "description" => "Codex missing required section: {$section}",
                "details"     => []
            ];
        }
    }

    // --------------------------------------------------
    // (2) Ensure SOT Files Exist (EGS rule)
    // --------------------------------------------------
    foreach ([
        "assets/data/errorRegistry.json",
        "assets/data/repositoryAudit.json"
    ] as $required) {

        if (!file_exists(dirname(__DIR__) . "/$required")) {
            $findings[] = [
                "type"        => "structural_validation",
                "name"        => "Missing SOT File",
                "description" => "$required must always exist.",
                "details"     => []
            ];
        }
    }

    // --------------------------------------------------
    // (3) MIS Merkle Verification
    // --------------------------------------------------
    $liveLeaves = recursiveChunks($codex);
    $liveRoot   = buildMerkle($liveLeaves);

    if ($liveRoot !== $storedRoot) {

        // Find changed leaf keys (drift location)
        $changed = [];
        foreach ($liveLeaves as $key => $hash) {
            if (!isset($merkleTree["leaves"][$key])) {
                $changed[] = $key;
                continue;
            }
            if ($merkleTree["leaves"][$key] !== $hash) {
                $changed[] = $key;
            }
        }

        $findings[] = [
            "type"        => "policy_violation",
            "name"        => "Codex Drift Detected",
            "description" => "Computed Merkle root does not match stored root.",
            "details"     => [
                "storedRoot" => $storedRoot,
                "liveRoot"   => $liveRoot,
                "changedKeys" => $changed
            ]
        ];
    }

    // --------------------------------------------------
    // (4) Stub: Structural rules will expand later
    // --------------------------------------------------
    $findings[] = [
        "type"        => "runtime_error",
        "name"        => "Auditor Stub",
        "description" => "Auditor executed, MIS verified, but structural rules not yet implemented.",
        "details"     => []
    ];

    return [
        "auditor"   => "Skyesoft Structural Auditor",
        "timestamp" => time(),
        "findings"  => $findings
    ];
}

// ======================================================================
// OUTPUT (never mutate SOT)
// ======================================================================
echo json_encode([
    "success"  => true,
    "role"     => "auditor",
    "findings" => runAudit($codex, $inventory, $merkleTree, $storedRoot)
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

exit;