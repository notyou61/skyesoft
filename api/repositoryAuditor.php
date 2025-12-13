<?php
declare(strict_types=1);

// ======================================================================
//  Skyesoft — repositoryAuditor.php
//  Version: 1.1.0
//  Last Updated: 2025-12-12
//  Codex Tier: 2 — Structural Audit / Repository Integrity
//
//  Role:
//  MODE A — One-time canonical repository inventory generator
//  MODE B — Strict repository auditor (default)
//
//  Requires:
//   • PHP 8.3+
//   • Codex repositoryInventory.json
//
//  Forbidden:
//   • No Codex mutation (audit-only)
//   • No Merkle writes
// ======================================================================

header("Content-Type: application/json; charset=UTF-8");

#region SECTION 0 — Resolve Repository Root
$root = realpath(__DIR__ . "/..");
$root = rtrim(str_replace("\\", "/", (string)$root), "/");

if (!$root || !is_dir($root)) {
    echo json_encode([
        "status" => "FAIL",
        "error"  => "Unable to resolve repository root."
    ], JSON_PRETTY_PRINT);
    exit;
}
#endregion

#region SECTION 1 — Determine Execution Mode
$mode = PHP_SAPI === "cli"
    ? ($argv[1] ?? "audit")
    : ($_GET["mode"] ?? "audit");
#endregion

#region SECTION 2 — Governance Exclusions (Authoritative)
$excludedPrefixes = [
    ".git",
    "node_modules",
    "documents",
    "reports"
];

$excludedFiles = [
    "package.json",
    "package-lock.json"
];

$excludedBasenames = [
    "README.md"
];

$allowedDotfiles = [
    ".nojekyll"
];
#endregion

#region SECTION 3 — Filesystem Scanner Helper
function scanFilesystem(
    string $root,
    array $excludedPrefixes,
    array $excludedFiles,
    array $excludedBasenames
): array {
    $scanned = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $info) {

        $fullPath = str_replace("\\", "/", $info->getPathname());
        $relative = ltrim(str_replace($root, "", $fullPath), "/");

        foreach ($excludedPrefixes as $prefix) {
            if (str_starts_with($relative, $prefix)) {
                continue 2;
            }
        }

        if (in_array($relative, $excludedFiles, true)) {
            continue;
        }

        if (in_array(basename($relative), $excludedBasenames, true)) {
            continue;
        }

        $scanned[$relative] = [
            "type" => $info->isDir() ? "dir" : "file"
        ];
    }

    return $scanned;
}
#endregion

#region SECTION 4 — MODE A: Inventory Generator
if ($mode === "index") {

    $items = [];
    $idCounter = 1;

    $scanned = scanFilesystem(
        $root,
        $excludedPrefixes,
        $excludedFiles,
        $excludedBasenames
    );

    foreach ($scanned as $path => $meta) {
        if ($path === "api/repositoryAuditor.php") continue;

        $items[] = [
            "id"       => "INV-" . str_pad((string)$idCounter++, 4, "0", STR_PAD_LEFT),
            "path"     => "/" . $path,
            "type"     => $meta["type"],
            "category" => "auto-indexed",
            "tier"     => "unassigned",
            "purpose"  => "auto-generated placeholder",
            "status"   => "active"
        ];
    }

    $inventoryPath = "$root/codex/meta/repositoryInventory.json";

    if (file_put_contents(
        $inventoryPath,
        json_encode(["items" => $items], JSON_PRETTY_PRINT)
    ) === false) {
        echo json_encode([
            "status" => "FAIL",
            "error"  => "Unable to write repositoryInventory.json"
        ], JSON_PRETTY_PRINT);
        exit;
    }

    echo json_encode([
        "status"    => "INDEX_COMPLETE",
        "itemCount" => count($items)
    ], JSON_PRETTY_PRINT);
    exit;
}
#endregion

#region SECTION 5 — Load Inventory
$inventoryPath = "$root/codex/meta/repositoryInventory.json";

if (!file_exists($inventoryPath)) {
    echo json_encode([
        "status" => "FAIL",
        "error"  => "repositoryInventory.json not found"
    ], JSON_PRETTY_PRINT);
    exit;
}

$inventoryRaw = json_decode(file_get_contents($inventoryPath), true);
#endregion

#region SECTION 6 — Normalize Inventory
$inventory = [];

foreach ($inventoryRaw["items"] ?? [] as $item) {
    if (!isset($item["path"])) continue;

    $canonical = ltrim(str_replace("\\", "/", $item["path"]), "/");
    if ($canonical === "") continue;

    $inventory[$canonical] = $item;
}
#endregion

#region SECTION 7 — Scan Live Filesystem
$scanned = scanFilesystem(
    $root,
    $excludedPrefixes,
    $excludedFiles,
    $excludedBasenames
);
#endregion

#region SECTION 8 — Strict Audit Evaluation
$errors = [];

// Unregistered filesystem items
foreach ($scanned as $path => $meta) {
    if (!isset($inventory[$path])) {
        $errors[] = [
            "code"    => $meta["type"] === "dir"
                ? "UNREGISTERED_DIRECTORY"
                : "UNREGISTERED_FILE",
            "path"    => $path,
            "message" => ucfirst($meta["type"]) . " exists but is not declared in inventory."
        ];
    }
}

// Missing inventory items
foreach ($inventory as $path => $meta) {
    if (!isset($scanned[$path])) {
        $errors[] = [
            "code"    => ($meta["type"] ?? "") === "dir"
                ? "MISSING_DIRECTORY"
                : "MISSING_FILE",
            "path"    => $path,
            "message" => "Declared item missing from filesystem."
        ];
    }
}
#endregion

#region SECTION 9 — Output Result
echo json_encode([
    "status" => empty($errors) ? "PASS" : "FAIL",
    "errors" => $errors
], JSON_PRETTY_PRINT);
exit;
#endregion