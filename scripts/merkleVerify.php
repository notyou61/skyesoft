<?php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(0);

// Ensure absolutely clean output (pipe-safe)
while (ob_get_level()) {
    ob_end_clean();
}

// ======================================================================
//  Skyesoft — merkleVerify.php
//  Tier: 3 — Integrity Verifier (MIS-R)
//  Emits STRICT auditor payload ONLY
//
//  Verifies:
//   • repositoryInventory.json
//   • filesystem hashes
//   • deterministic Merkle root
// ======================================================================

#region SECTION 0 — Fail Helper

function merkleFail(string $msg, string $code = "MERKLE_VERIFY_ERROR"): never {
    echo json_encode([
        "status" => "FAIL",
        "errors" => [[
            "code"    => $code,
            "path"    => null,
            "message" => $msg
        ]]
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

#endregion

#region SECTION I — Resolve Paths

$root = realpath(__DIR__ . "/..");
$root = rtrim(str_replace("\\", "/", (string)$root), "/");

$inventoryPath = "$root/codex/meta/repositoryInventory.json";
$treePath      = "$root/codex/meta/merkleTree.json";
$rootPath      = "$root/codex/meta/merkleRoot.txt";

foreach ([$inventoryPath, $treePath, $rootPath] as $p) {
    if (!file_exists($p)) {
        merkleFail(basename($p) . " missing.");
    }
}

#endregion

#region SECTION II — Load State

$inventory    = json_decode(file_get_contents($inventoryPath), true);
$storedTree   = json_decode(file_get_contents($treePath), true);
$expectedRoot = trim(file_get_contents($rootPath));

if (!is_array($inventory) || !isset($inventory["items"])) {
    merkleFail("Invalid repositoryInventory.json structure.");
}

if (!is_array($storedTree) || !isset($storedTree["root"], $storedTree["layers"])) {
    merkleFail("Stored Merkle tree invalid.");
}

#endregion

#region SECTION II.A — Canonical Promotion Guard

$inventoryMTime = filemtime($inventoryPath);
$rootMTime      = filemtime($rootPath);

if ($inventoryMTime === false || $rootMTime === false) {
    merkleFail(
        "Unable to determine modification times for canonical artifacts.",
        "CANONICAL_STATE_ERROR"
    );
}

// Inventory newer than root = promotion incomplete (expected transitional state)
if ($inventoryMTime > $rootMTime) {
    merkleFail(
        "Repository inventory is newer than Merkle root. Canonical promotion required.",
        "CANONICAL_PROMOTION_REQUIRED"
    );
}

#endregion

#region SECTION III — Recompute Leaf Hashes (MATCHES merkleBuild.php)

$leaves = [];

foreach ($inventory["items"] as $item) {

    if (($item["status"] ?? "") !== "active") continue;
    if (($item["type"] ?? "") !== "file") continue;

    // Respect Codex integrity doctrine
    if (($item["integrityScope"] ?? "MERKLE_INCLUDED") !== "MERKLE_INCLUDED") {
        continue;
    }

    $relPath  = ltrim((string)$item["path"], "/");
    $fullPath = $root . "/" . $relPath;

    if (!file_exists($fullPath)) {
        merkleFail("Missing file during verification: {$relPath}");
    }

    $contentHash = hash_file("sha256", $fullPath);
    $leafHash    = hash("sha256", $relPath . ":" . $contentHash);

    $leaves[$relPath] = $leafHash;
}

// Deterministic ordering (MIS rule)
ksort($leaves);

#endregion

#region SECTION IV — Rebuild Merkle Root

$layer = array_values($leaves);

while (count($layer) > 1) {
    $next = [];
    for ($i = 0; $i < count($layer); $i += 2) {
        $left  = $layer[$i];
        $right = $layer[$i + 1] ?? $left;
        $next[] = hash("sha256", $left . $right);
    }
    $layer = $next;
}

$computedRoot = $layer[0] ?? "";

if ($computedRoot === "") {
    merkleFail("Unable to compute Merkle root.");
}

#endregion

#region SECTION V — Compare Roots

$errors = [];

if ($computedRoot !== $expectedRoot) {
    $errors[] = [
        "code"    => "MERKLE_MISMATCH",
        "path"    => null,
        "message" => "Computed Merkle root does not match stored root.",
        "details" => [
            "expectedRoot" => $expectedRoot,
            "computedRoot" => $computedRoot
        ]
    ];
}

#endregion

#region SECTION VI — Emit Canonical Auditor Payload

echo json_encode([
    "status" => empty($errors) ? "PASS" : "FAIL",
    "errors" => $errors
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

exit;

#endregion