<?php
declare(strict_types=1);

// ======================================================================
//  Skyesoft — merkleBuild.php
//  Tier: 3 — Integrity Builder (MIS)
//  PHP 8.3+ • Strict Typing
//
//  Responsibility:
//   • Read authoritative repositoryInventory.json
//   • Hash active filesystem files
//   • Generate deterministic Merkle Tree
//   • Persist merkleTree.json and merkleRoot.txt
//
//  Forbidden:
//   • NO inventory generation
//   • NO Codex mutation
// ======================================================================

header("Content-Type: application/json; charset=UTF-8");

#region SECTION 0 — Fail Handler

function merkleFail(string $msg): never {
    echo json_encode([
        "success" => false,
        "role"    => "merkleBuild",
        "error"   => "❌ $msg"
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

#endregion SECTION 0 — Fail Handler

#region SECTION I — Resolve Repository Paths

$root = realpath(__DIR__ . "/..");
$root = rtrim(str_replace("\\", "/", (string)$root), "/");

$inventoryPath = $root . "/codex/meta/repositoryInventory.json";
$treePath      = $root . "/codex/meta/merkleTree.json";
$rootPath      = $root . "/codex/meta/merkleRoot.txt";

if (!$root || !is_dir($root)) {
    merkleFail("Unable to resolve repository root.");
}

#endregion SECTION I — Resolve Repository Paths

#region SECTION II — Load Inventory (Authoritative)

if (!file_exists($inventoryPath)) {
    merkleFail("repositoryInventory.json missing.");
}

$inventory = json_decode(file_get_contents($inventoryPath), true);

if (!is_array($inventory) || !isset($inventory["items"])) {
    merkleFail("Invalid repositoryInventory.json structure.");
}

#endregion SECTION II — Load Inventory

#region SECTION III — Build Leaf Hashes (MATCHES merkleVerify.php)

$leaves = [];

foreach ($inventory["items"] as $item) {

    if (($item["status"] ?? "") !== "active") continue;
    if (($item["type"] ?? "") !== "file") continue;

    // Codex integrity doctrine
    if (($item["integrityScope"] ?? "MERKLE_INCLUDED") !== "MERKLE_INCLUDED") {
        continue;
    }

    $relPath  = ltrim((string)$item["path"], "/");
    $fullPath = $root . "/" . $relPath;

    if (!file_exists($fullPath)) {
        merkleFail("Missing file during Merkle build: {$relPath}");
    }

    $contentHash = hash_file("sha256", $fullPath);
    $leafHash    = hash("sha256", $relPath . ":" . $contentHash);

    $leaves[$relPath] = $leafHash;
}

// Deterministic ordering (MIS rule)
ksort($leaves);

#endregion SECTION III

#region SECTION IV — Build Merkle Tree (CANONICAL)

$layer  = array_values($leaves);
$layers = [];
$layers[] = $layer;

while (count($layer) > 1) {

    $next = [];

    for ($i = 0; $i < count($layer); $i += 2) {
        $left  = $layer[$i];
        $right = $layer[$i + 1] ?? $left; // duplicate last if odd
        $next[] = hash("sha256", $left . $right);
    }

    $layers[] = $next;
    $layer    = $next;
}

$merkleRoot = $layer[0] ?? null;

if (!$merkleRoot) {
    merkleFail("Unable to compute Merkle root.");
}

#endregion SECTION IV

#region SECTION V — Persist Merkle Artifacts (SOT)

file_put_contents(
    $treePath,
    json_encode([
        "generated" => time(),
        "algo"      => "sha256",
        "leafCount" => count($leaves),
        "root"      => $merkleRoot,
        "layers"    => $layers
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
);

file_put_contents($rootPath, $merkleRoot);

#endregion SECTION V — Persist Merkle Artifacts

#region SECTION VI — Output

echo json_encode([
    "success"   => true,
    "role"      => "merkleBuild",
    "leafCount" => count($leaves),
    "root"      => $merkleRoot
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

exit;

#endregion SECTION VI — Output