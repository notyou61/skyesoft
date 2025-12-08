<?php
// ======================================================================
// Skyesoft — repositoryAuditor.php
// MODE A: Full Repository Index Generator (One-Time Use)
// MODE B: Strict Auditor (default)
// ======================================================================

header('Content-Type: application/json');

// ------------------------------------------------------------
// PHP 5.6 COMPAT — polyfill str_starts_with
// ------------------------------------------------------------
if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle) {
        return substr($haystack, 0, strlen($needle)) === $needle;
    }
}

// ------------------------------------------------------------
// Resolve repository root
// ------------------------------------------------------------
$root = realpath(__DIR__ . "/..");
$root = rtrim(str_replace("\\", "/", $root), "/");
if (!$root || !is_dir($root)) {
    echo json_encode([
        ["code" => "ROOT_ERROR", "message" => "Unable to resolve repository root."]
    ], JSON_PRETTY_PRINT);
    exit;
}

// ------------------------------------------------------------
// Support both ?mode=index and CLI: php repo.php index
// ------------------------------------------------------------
$mode = "audit";
if (php_sapi_name() === "cli") {
    $mode = isset($argv[1]) ? $argv[1] : "audit";
} else {
    $mode = isset($_GET["mode"]) ? $_GET["mode"] : "audit";
}

// ------------------------------------------------------------
// System-level exclusions
// ------------------------------------------------------------
$excluded = [".git"];

// ------------------------------------------------------------
// Shared: Scan filesystem into $scanned array
// ------------------------------------------------------------
function scanFilesystem($root, $excluded) {
    $scanned = [];
    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
    } catch (Exception $e) {
        throw new Exception("SCAN_INIT_FAILED: " . $e->getMessage());
    }
    foreach ($iterator as $fileInfo) {
        try {
            $full = str_replace("\\", "/", $fileInfo->getPathname());
            $rel = ltrim(str_replace($root, "", $full), "/");
            // Skip excluded
            foreach ($excluded as $ex) {
                if (str_starts_with($rel, $ex)) continue 2;
            }
            $scanned[$rel] = [
                "type" => $fileInfo->isDir() ? "dir" : "file"
            ];
        } catch (Exception $e) {
            // Log per-item error but continue
            error_log("Scan error on {$rel}: " . $e->getMessage());
            continue;
        }
    }
    return $scanned;
}

// ======================================================================
// MODE A — ONE-TIME INDEX GENERATOR
// ======================================================================
if ($mode === "index") {
    $items = [];
    $idCounter = 1;
    try {
        $scanned = scanFilesystem($root, $excluded);
    } catch (Exception $e) {
        echo json_encode([
            ["code" => $e->getCode(), "message" => $e->getMessage()]
        ], JSON_PRETTY_PRINT);
        exit;
    }
    foreach ($scanned as $rel => $meta) {
        // Skip this script
        if ($rel === "api/repositoryAuditor.php") continue;
        $items[] = [
            "id" => "INV-" . str_pad($idCounter++, 4, "0", STR_PAD_LEFT),
            "path" => "/" . $rel,
            "type" => $meta["type"],
            "category" => "auto-indexed",
            "tier" => "unassigned",
            "purpose" => "auto-generated placeholder",
            "status" => "active"
        ];
    }
    // Include root directory
    array_unshift($items, [
        "id" => "INV-0000",
        "path" => "/",
        "type" => "dir",
        "category" => "root",
        "tier" => "Tier-2",
        "purpose" => "Repository root",
        "status" => "active"
    ]);
    // Sort paths alphabetically for canonicalization
    usort($items, function($a, $b) {
        return strcmp($a["path"], $b["path"]);
    });
    // ------------------------------------------------------------
    // Write inventory
    // ------------------------------------------------------------
    $inventoryPath = $root . "/codex/meta/repositoryInventory.json";
    $output = [
        "meta" => [
            "title" => "Skyesoft Repository Inventory",
            "generated" => date("Y-m-d H:i:s"),
            "mode" => "index",
            "description" => "Auto-generated canonical manifest of filesystem structure."
        ],
        "items" => $items
    ];
    if (file_put_contents($inventoryPath, json_encode($output, JSON_PRETTY_PRINT)) === false) {
        echo json_encode([
            ["code" => "WRITE_FAILED", "message" => "Could not write to {$inventoryPath}."]
        ], JSON_PRETTY_PRINT);
        exit;
    }
    echo json_encode([
        "status" => "INDEX_COMPLETE",
        "writtenTo" => $inventoryPath,
        "itemCount" => count($items)
    ], JSON_PRETTY_PRINT);
    exit;
}

// ======================================================================
// MODE B — STRICT AUDIT
// ======================================================================

// ------------------------------------------------------------
// Resolve inventory path
// ------------------------------------------------------------
$inventoryPath = $root . "/codex/meta/repositoryInventory.json";

if (!file_exists($inventoryPath)) {
    echo json_encode([
        [
            "code" => "INVENTORY_MISSING",
            "path" => $inventoryPath,
            "message" => "repositoryInventory.json not found in /codex/meta."
        ]
    ], JSON_PRETTY_PRINT);
    exit;
}

// ------------------------------------------------------------
// Load and decode inventory
// ------------------------------------------------------------
$raw = json_decode(file_get_contents($inventoryPath), true);

if (!is_array($raw) || !isset($raw["items"]) || !is_array($raw["items"])) {
    echo json_encode([
        [
            "code"    => "INVENTORY_FORMAT_INVALID",
            "path"    => $inventoryPath,
            "message" => "repositoryInventory.json missing required 'items' array."
        ]
    ], JSON_PRETTY_PRINT);
    exit;
}

$inventoryItems = $raw["items"];

// ------------------------------------------------------------
// Normalize inventory lookup
// ------------------------------------------------------------
$inventory = [];

foreach ($inventoryItems as $item) {
    if (!isset($item["path"])) continue;
    // Skip root "/"
    if (trim($item["path"]) === "/") continue;
    $canonical = ltrim(str_replace("\\", "/", $item["path"]), "/");
    // Normalize type
    if (isset($item["type"])) {
        $t = strtolower($item["type"]);
        if ($t === "directory" || $t === "folder") $t = "dir";
        $item["type"] = $t;
    }
    $inventory[$canonical] = $item;
}

// ------------------------------------------------------------
// Scan filesystem (reuse helper)
// ------------------------------------------------------------
try {
    $scanned = scanFilesystem($root, $excluded);
} catch (Exception $e) {
    echo json_encode([
        [
            "code"    => $e->getCode(),
            "message" => $e->getMessage()
        ]
    ], JSON_PRETTY_PRINT);
    exit;
}

// ------------------------------------------------------------
// Begin Strict-Mode Evaluation
// ------------------------------------------------------------
$errors = [];

// ------------------------------------------------------------
// 1. UNREGISTERED DIRECTORY / FILE
// ------------------------------------------------------------
foreach ($scanned as $path => $meta) {
    if (!isset($inventory[$path])) {
        $errors[] = [
            "code"    => ($meta["type"] === "dir" ? "UNREGISTERED_DIRECTORY" : "UNREGISTERED_FILE"),
            "path"    => $path,
            "message" => ucfirst($meta["type"]) . " exists but is not declared in repositoryInventory.json."
        ];
    }
}

// ------------------------------------------------------------
// 2. MISSING DIRECTORY / FILE
// ------------------------------------------------------------
foreach ($inventory as $path => $meta) {
    if (!isset($scanned[$path])) {
        $errors[] = [
            "code"    => ($meta["type"] === "dir" ? "MISSING_DIRECTORY" : "MISSING_FILE"),
            "path"    => $path,
            "message" => ucfirst($meta["type"]) . " declared in inventory but missing in filesystem."
        ];
    }
}

// ------------------------------------------------------------
// 3. Naming Rule (camelCase or lowercase) — FILES ONLY
// ------------------------------------------------------------
function isCamelOrLower($name) {
    return preg_match('/^[a-z0-9]+([A-Z][a-z0-9]+)*(\.[a-z0-9]+)?$/', $name);
}

foreach ($scanned as $path => $meta) {
    if ($meta["type"] !== "file") continue;
    $base = basename($path);
    // Dotfile allowed only if declared
    if ($base[0] === '.' && isset($inventory[$path])) continue;
    // Undeclared dotfile = violation
    if ($base[0] === '.') {
        $errors[] = [
            "code"    => "NAMING_VIOLATION",
            "path"    => $path,
            "expected"=> "dotfiles allowed only when declared",
            "message" => "Dotfile is not in inventory."
        ];
        continue;
    }
    if (!isCamelOrLower($base)) {
        $errors[] = [
            "code"      => "NAMING_VIOLATION",
            "path"      => $path,
            "expected"  => "camelCase or lowercase",
            "message"   => "Filename violates naming rules."
        ];
    }
}

// ------------------------------------------------------------
// 4. Purpose validation
// ------------------------------------------------------------
foreach ($inventory as $path => $meta) {
    if (($meta["type"] ?? "") !== "file") continue;
    if (!isset($meta["purpose"]) || trim($meta["purpose"]) === "") {
        $errors[] = [
            "code"    => "MISSING_PURPOSE",
            "path"    => $path,
            "message" => "File is missing required 'purpose' attribute."
        ];
    }
}

// ------------------------------------------------------------
// Output (wrapped for consistency)
// ------------------------------------------------------------
$result = [
    "status" => empty($errors) ? "PASS" : "FAIL",
    "errors" => $errors
];
echo json_encode($result, JSON_PRETTY_PRINT);
exit;