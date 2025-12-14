<?php
declare(strict_types=1);

// ======================================================================
//  Skyesoft — repositoryAuditor.php
//  Version: 1.2.1
//  Last Updated: 2025-12-13
//  Codex Tier: 2 — Structural Audit / Repository Integrity
//
//  Role:
//   • Strict repository auditor ONLY
//   • Inventory is authoritative (audit-only)
//
//  Notes:
//   • Inventory generation is handled exclusively by
//     scripts/repositoryInventoryBuilder.php (Tier-3)
//
//  Requires:
//   • PHP 8.3+
//   • codex/meta/repositoryInventory.json
//
//  Forbidden:
//   • No Codex mutation
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

#region SECTION 1 — Execution Mode Guard (Audit Only)

$mode = PHP_SAPI === "cli"
    ? ($argv[1] ?? "audit")
    : ($_GET["mode"] ?? "audit");

if ($mode !== "audit") {
    echo json_encode([
        "status" => "FAIL",
        "error"  => "Invalid mode. Auditor supports audit-only execution."
    ], JSON_PRETTY_PRINT);
    exit;
}

#endregion

#region SECTION 2 — Governance Exclusions (MUST MATCH BUILDER)

$excludedPrefixes = [
    ".git",
    "node_modules"
];

$excludedFiles = [
    "package.json",
    "package-lock.json"
];

$excludedBasenames = [
    ".keep",
    ".gitkeep"
];

#endregion

#region SECTION 3 — Filesystem Scanner (Inventory-Aligned)

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

        // Excluded directory trees
        foreach ($excludedPrefixes as $prefix) {
            if ($relative === $prefix || str_starts_with($relative, $prefix . "/")) {
                continue 2;
            }
        }

        // Explicit file exclusions
        if (in_array($relative, $excludedFiles, true)) {
            continue;
        }

        // Basename exclusions (.keep, .gitkeep)
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

#region SECTION 4 — Load Inventory (Authoritative)

$inventoryPath = $root . "/codex/meta/repositoryInventory.json";

if (!file_exists($inventoryPath)) {
    echo json_encode([
        "status" => "FAIL",
        "error"  => "repositoryInventory.json not found"
    ], JSON_PRETTY_PRINT);
    exit;
}

$inventoryRaw = json_decode(file_get_contents($inventoryPath), true);

#endregion

#region SECTION 5 — Normalize Inventory Paths

$inventory = [];

foreach ($inventoryRaw["items"] ?? [] as $item) {

    if (!isset($item["path"], $item["type"])) {
        continue;
    }

    $canonical = ltrim(str_replace("\\", "/", $item["path"]), "/");
    if ($canonical === "") {
        continue;
    }

    $inventory[$canonical] = $item;
}

#endregion

#region SECTION 6 — Scan Live Filesystem

$scanned = scanFilesystem(
    $root,
    $excludedPrefixes,
    $excludedFiles,
    $excludedBasenames
);

#endregion

#region SECTION 7 — Strict Audit Evaluation

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

#region SECTION 8 — Output Result

echo json_encode([
    "status" => empty($errors) ? "PASS" : "FAIL",
    "errors" => $errors
], JSON_PRETTY_PRINT);

exit;

#endregion