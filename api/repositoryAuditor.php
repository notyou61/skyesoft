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
        "status"  => "FAIL",
        "error"   => "Unable to resolve repository root."
    ], JSON_PRETTY_PRINT);
    exit;
}
#endregion

#region SECTION 1 — Determine Execution Mode
$mode = "audit";

if (PHP_SAPI === "cli") {
    $mode = $argv[1] ?? "audit";
} else {
    $mode = $_GET["mode"] ?? "audit";
}
#endregion

#region SECTION 2 — System Exclusions
$excluded = [
    ".git"
];
#endregion

#region SECTION 3 — Filesystem Scanner Helper
function scanFilesystem(string $root, array $excluded): array {
    $scanned = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $root,
            RecursiveDirectoryIterator::SKIP_DOTS
        ),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $fileInfo) {

        $fullPath = str_replace("\\", "/", $fileInfo->getPathname());
        $relative = ltrim(str_replace($root, "", $fullPath), "/");

        foreach ($excluded as $ex) {
            if (str_starts_with($relative, $ex)) {
                continue 2;
            }
        }

        $scanned[$relative] = [
            "type" => $fileInfo->isDir() ? "dir" : "file"
        ];
    }

    return $scanned;
}
#endregion

#region SECTION 4 — MODE A: Inventory Generator
if ($mode === "index") {

    $items = [];
    $idCounter = 1;

    try {
        $scanned = scanFilesystem($root, $excluded);
    } catch (Throwable $e) {
        echo json_encode([
            "status"  => "FAIL",
            "error"   => $e->getMessage()
        ], JSON_PRETTY_PRINT);
        exit;
    }

    foreach ($scanned as $path => $meta) {

        if ($path === "api/repositoryAuditor.php") {
            continue;
        }

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

    array_unshift($items, [
        "id"       => "INV-0000",
        "path"     => "/",
        "type"     => "dir",
        "category" => "root",
        "tier"     => "Tier-2",
        "purpose"  => "Repository root",
        "status"   => "active"
    ]);

    usort($items, fn($a, $b) => strcmp($a["path"], $b["path"]));

    $inventoryPath = "$root/codex/meta/repositoryInventory.json";

    $output = [
        "meta" => [
            "title"       => "Skyesoft Repository Inventory",
            "generatedAt" => date("Y-m-d H:i:s"),
            "mode"        => "index",
            "description" => "Auto-generated canonical manifest of filesystem structure."
        ],
        "items" => $items
    ];

    if (file_put_contents($inventoryPath, json_encode($output, JSON_PRETTY_PRINT)) === false) {
        echo json_encode([
            "status" => "FAIL",
            "error"  => "Unable to write repositoryInventory.json"
        ], JSON_PRETTY_PRINT);
        exit;
    }

    echo json_encode([
        "status"    => "INDEX_COMPLETE",
        "writtenTo"=> $inventoryPath,
        "itemCount"=> count($items)
    ], JSON_PRETTY_PRINT);
    exit;
}
#endregion

#region SECTION 5 — MODE B: Load Inventory
$inventoryPath = "$root/codex/meta/repositoryInventory.json";

if (!file_exists($inventoryPath)) {
    echo json_encode([
        "status"  => "FAIL",
        "error"   => "repositoryInventory.json not found"
    ], JSON_PRETTY_PRINT);
    exit;
}

$inventoryRaw = json_decode(file_get_contents($inventoryPath), true);

if (!is_array($inventoryRaw) || !isset($inventoryRaw["items"])) {
    echo json_encode([
        "status" => "FAIL",
        "error"  => "Invalid inventory format"
    ], JSON_PRETTY_PRINT);
    exit;
}
#endregion

#region SECTION 6 — Normalize Inventory
$inventory = [];

foreach ($inventoryRaw["items"] as $item) {

    if (!isset($item["path"])) {
        continue;
    }

    if (trim($item["path"]) === "/") {
        continue;
    }

    $canonical = ltrim(str_replace("\\", "/", $item["path"]), "/");

    if (isset($item["type"])) {
        $t = strtolower($item["type"]);
        if (in_array($t, ["directory", "folder"], true)) {
            $t = "dir";
        }
        $item["type"] = $t;
    }

    $inventory[$canonical] = $item;
}
#endregion

#region SECTION 7 — Scan Live Filesystem
try {
    $scanned = scanFilesystem($root, $excluded);
} catch (Throwable $e) {
    echo json_encode([
        "status" => "FAIL",
        "error"  => $e->getMessage()
    ], JSON_PRETTY_PRINT);
    exit;
}
#endregion

#region SECTION 8 — Strict Audit Evaluation
$errors = [];

// 1. Unregistered items
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

// 2. Missing items
foreach ($inventory as $path => $meta) {
    if (!isset($scanned[$path])) {
        $errors[] = [
            "code"    => $meta["type"] === "dir"
                ? "MISSING_DIRECTORY"
                : "MISSING_FILE",
            "path"    => $path,
            "message" => ucfirst($meta["type"]) . " declared in inventory but missing."
        ];
    }
}

// 3. Naming rules (files only)
function isCamelOrLower(string $name): bool {
    return (bool)preg_match(
        '/^[a-z0-9]+([A-Z][a-z0-9]+)*(\.[a-z0-9]+)?$/',
        $name
    );
}

foreach ($scanned as $path => $meta) {
    if ($meta["type"] !== "file") continue;

    $base = basename($path);

    if ($base[0] === '.' && !isset($inventory[$path])) {
        $errors[] = [
            "code"    => "NAMING_VIOLATION",
            "path"    => $path,
            "message" => "Undeclared dotfile."
        ];
        continue;
    }

    if (!isCamelOrLower($base)) {
        $errors[] = [
            "code"    => "NAMING_VIOLATION",
            "path"    => $path,
            "message" => "Filename violates naming standard."
        ];
    }
}

// 4. Purpose validation
foreach ($inventory as $path => $meta) {
    if (($meta["type"] ?? "") !== "file") continue;
    if (empty(trim((string)($meta["purpose"] ?? "")))) {
        $errors[] = [
            "code"    => "MISSING_PURPOSE",
            "path"    => $path,
            "message" => "File missing required purpose."
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