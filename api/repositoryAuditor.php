<?php
declare(strict_types=1);
// ======================================================================
// Skyesoft — repositoryAuditor.php
// Version: 2.4.0
// Codex Tier: 2 — Audit Orchestrator
//
// Purpose:
// • Execute Operational Audits (continuous, lightweight)
// • Execute Governance Audits (scheduled, doctrinal — stubbed)
//
// Authority:
// • Inventory is authoritative
// • Codex is immutable
//
// Forbidden:
// • No writes except authorized audit record persistence
// • No Merkle generation
// • No Codex mutation
//
// Changes in 2.4.0:
// • Added persistent, append-only audit logging to data/records/repositoryAudit.json
//   - Timestamped historical record of all runs
//   - Enables tracking of violation resolution over time
//   - Atomic write with LOCK_EX
// • Improved registry suggestedFix: preserves original suffix, proper camelCase conversion
// • Header updated to authorize audit record write
// ======================================================================
header("Content-Type: application/json; charset=UTF-8");

#region SECTION 0 — Bootstrap
$root = realpath(__DIR__ . "/..");
$root = rtrim(str_replace("\\", "/", (string)$root), "/");
if (!$root || !is_dir($root)) {
    fail("Unable to resolve repository root.");
}
#endregion

#region SECTION 1 — Mode Resolution
$mode = PHP_SAPI === "cli"
    ? ($argv[1] ?? "operational")
    : ($_GET["mode"] ?? "operational");
if (!in_array($mode, ["operational", "governance"], true)) {
    fail("Invalid audit mode.");
}
#endregion

#region SECTION 2 — Canonical Exclusions
$excludedPrefixes = [".git", "node_modules"];
$excludedFiles = ["package.json", "package-lock.json"];
$excludedBasenames = [".keep", ".gitkeep"];
$industryExceptions = [
    "README.md",
    "LICENSE",
    "CHANGELOG.md",
    "CONTRIBUTING.md",
    ".gitignore",
    ".gitattributes",
    ".editorconfig",
    ".env",
    ".htaccess",
    ".nojekyll",
    "package.json",
    "composer.json"
];
#endregion

#region SECTION 3 — Helpers
function fail(string $message): never {
    echo json_encode([
        "success" => false,
        "role" => "repositoryAuditor",
        "error" => $message
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}
function scanFilesystem(
    string $root,
    array $excludedPrefixes,
    array $excludedFiles,
    array $excludedBasenames
): array {
    $items = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $info) {
        $full = str_replace("\\", "/", $info->getPathname());
        $rel = ltrim(str_replace($root, "", $full), "/");
        foreach ($excludedPrefixes as $prefix) {
            if ($rel === $prefix || str_starts_with($rel, "$prefix/")) {
                continue 2;
            }
        }
        if (in_array($rel, $excludedFiles, true)) continue;
        if (in_array(basename($rel), $excludedBasenames, true)) continue;
        $items[$rel] = [
            "type" => $info->isDir() ? "dir" : "file"
        ];
    }
    ksort($items); // deterministic
    return $items;
}
function explainViolation(array $v): array
{
    $path = $v["path"] ?? null;
    $file = $path ? basename($path) : null;

    $registrySuffixes = ["Registry.json", "Map.json", "Index.json"];

    switch ($v["code"]) {
        // ─────────────────────────────────────────────
        // Inventory / Structure
        // ─────────────────────────────────────────────
        case "INVENTORY_MISSING":
            return $v + [
                "category" => "repositoryInventory",
                "ruleRef" => "RepositoryInventory.Required",
                "message" =>
                    "The repository inventory file is missing.",
                "expected" =>
                    "A complete repositoryInventory.json must exist and enumerate all tracked files.",
                "suggestedFix" =>
                    "Generate or restore data/records/repositoryInventory.json."
            ];
        case "UNREGISTERED_FILE":
        case "UNREGISTERED_DIR":
            return $v + [
                "category" => "repositoryInventory",
                "ruleRef" => "RepositoryInventory.Completeness",
                "facts" => ["path" => $path],
                "message" =>
                    "This item exists on disk but is not registered in the repository inventory.",
                "expected" =>
                    "All files and directories must be declared in repositoryInventory.json.",
                "suggestedFix" =>
                    "Add this path to the inventory or remove it from the filesystem."
            ];
        case "MISSING_FILE":
        case "MISSING_DIR":
            return $v + [
                "category" => "repositoryInventory",
                "ruleRef" => "RepositoryInventory.Consistency",
                "facts" => ["path" => $path],
                "message" =>
                    "This item is declared in the repository inventory but missing on disk.",
                "expected" =>
                    "All inventory entries must physically exist.",
                "suggestedFix" =>
                    "Restore the missing item or remove it from the inventory."
            ];
        // ─────────────────────────────────────────────
        // Merkle / Integrity
        // ─────────────────────────────────────────────
        case "MERKLE_MISSING":
            return $v + [
                "category" => "integrity",
                "ruleRef" => "Merkle.Required",
                "facts" => ["path" => $path],
                "message" =>
                    "A required Merkle integrity file is missing.",
                "expected" =>
                    "Merkle root and tree files must exist to validate repository integrity.",
                "suggestedFix" =>
                    "Regenerate the Merkle artifacts using the authorized tooling."
            ];
        case "MERKLE_EMPTY":
            return $v + [
                "category" => "integrity",
                "ruleRef" => "Merkle.NonEmpty",
                "facts" => ["path" => $path],
                "message" =>
                    "A Merkle integrity file exists but is empty.",
                "expected" =>
                    "Merkle files must contain valid integrity data.",
                "suggestedFix" =>
                    "Rebuild the Merkle tree and root."
            ];
        // ─────────────────────────────────────────────
        // Critical Files
        // ─────────────────────────────────────────────
        case "CRITICAL_MISSING":
            return $v + [
                "category" => "criticalFiles",
                "ruleRef" => "CriticalFiles.Required",
                "facts" => ["path" => $path],
                "message" =>
                    "A critical system file is missing.",
                "expected" =>
                    "All critical files must be present for system stability.",
                "suggestedFix" =>
                    "Restore the missing file from version control or backup."
            ];
        // ─────────────────────────────────────────────
        // Execution / Runtime Invariants
        // ─────────────────────────────────────────────
        case "PHP_VERSION_UNSUPPORTED":
            return $v + [
                "category" => "runtime",
                "ruleRef" => "Runtime.PHPVersion",
                "message" =>
                    "The PHP runtime version is below the minimum supported version.",
                "expected" =>
                    "PHP 8.3 or higher must be used.",
                "suggestedFix" =>
                    "Upgrade the PHP runtime to version 8.3 or newer."
            ];

        // ─────────────────────────────────────────────
        // Naming Conventions
        // ─────────────────────────────────────────────
        case "EXTENSION_UPPERCASE":
            return $v + [
                "category" => "naming",
                "ruleRef" => "NamingConvention.Extensions",
                "facts" => ["filename" => $file],
                "message" =>
                    "The file extension contains uppercase characters.",
                "expected" =>
                    "All file extensions must be lowercase.",
                "suggestedFix" =>
                    "Rename the file to use a lowercase extension."
            ];
        case "REGISTRY_NAMING_INVALID":
            $detectedPattern = str_contains($file, "-") ? "kebab-case" :
                              (str_contains($file, "_") ? "snake_case" : "non-camelCase");

            $suggestedName = $file; // fallback
            foreach ($registrySuffixes as $suffix) {
                if (str_ends_with($file, $suffix)) {
                    $namePart = substr($file, 0, -strlen($suffix));
                    $camelPart = preg_replace_callback(
                        '/[-_]+([a-z])/i',
                        fn($m) => strtoupper($m[1]),
                        strtolower($namePart)
                    );
                    $suggestedName = lcfirst($camelPart) . $suffix;
                    break;
                }
            }
            return $v + [
                "category" => "canonicalRegistries",
                "ruleRef" => "NamingConvention.canonicalRegistries",
                "facts" => [
                    "filename" => $file,
                    "detectedPattern" => $detectedPattern,
                    "requiredPattern" => "camelCase + Registry.json/Map.json/Index.json suffix"
                ],
                "message" =>
                    "This file is treated as a canonical registry but does not follow the required naming convention.",
                "expected" =>
                    "Canonical registry filenames must use camelCase and end with Registry.json, Map.json, or Index.json.",
                "suggestedFix" =>
                    "Rename the file to {$suggestedName}."
            ];
        case "DIRECTORY_NAMING_UNUSUAL":
            return $v + [
                "category" => "directories",
                "ruleRef" => "NamingConvention.Directories",
                "facts" => ["directory" => $file],
                "message" =>
                    "The directory name deviates from common naming patterns.",
                "expected" =>
                    "Directories should use camelCase, kebab-case, or snake_case.",
                "suggestedFix" =>
                    "Rename the directory using a standard naming pattern."
            ];
        // ─────────────────────────────────────────────
        // Fallback (future-proof)
        // ─────────────────────────────────────────────
        default:
            return $v + [
                "category" => "unknown",
                "message" =>
                    "No detailed explanation is available for this violation code."
            ];
    }
}
#endregion

#region SECTION 4 — Operational Checks
function checkRepositoryInventory(
    string $root,
    array $excludedPrefixes,
    array $excludedFiles,
    array $excludedBasenames
): array {
    $violations = [];
    $path = "$root/data/records/repositoryInventory.json";
    if (!file_exists($path)) {
        return [[
            "severity" => "blocking",
            "code" => "INVENTORY_MISSING",
            "message" => "repositoryInventory.json not found"
        ]];
    }
    $raw = json_decode(file_get_contents($path), true);
    $inventory = [];
    foreach ($raw["items"] ?? [] as $item) {
        if (!isset($item["path"], $item["type"])) continue;
        $p = ltrim(str_replace("\\", "/", $item["path"]), "/");
        if ($p !== "") $inventory[$p] = $item["type"];
    }
    $fs = scanFilesystem($root, $excludedPrefixes, $excludedFiles, $excludedBasenames);
    foreach ($fs as $path => $meta) {
        if (!isset($inventory[$path])) {
            $violations[] = [
                "severity" => "blocking",
                "code" => "UNREGISTERED_" . strtoupper($meta["type"]),
                "path" => $path
            ];
        }
    }
    foreach ($inventory as $path => $type) {
        if (!isset($fs[$path])) {
            $violations[] = [
                "severity" => "blocking",
                "code" => "MISSING_" . strtoupper($type),
                "path" => $path
            ];
        }
    }
    return $violations;
}
function checkMerkleStatus(string $root): array {
    $violations = [];
    $files = [
        "data/records/merkleRoot.txt",
        "data/records/merkleTree.json"
    ];
    foreach ($files as $f) {
        $full = "$root/$f";
        if (!file_exists($full)) {
            $violations[] = [
                "severity" => "blocking",
                "code" => "MERKLE_MISSING",
                "path" => $f
            ];
        } elseif (filesize($full) === 0) {
            $violations[] = [
                "severity" => "blocking",
                "code" => "MERKLE_EMPTY",
                "path" => $f
            ];
        }
    }
    return $violations;
}
function checkCriticalFiles(string $root): array {
    $violations = [];
    $critical = [
        "codex/codex.json",
        "data/records/repositoryInventory.json",
        "data/records/errorRegistry.json"
    ];
    foreach ($critical as $f) {
        if (!file_exists("$root/$f")) {
            $violations[] = [
                "severity" => "blocking",
                "code" => "CRITICAL_MISSING",
                "path" => $f
            ];
        }
    }
    return $violations;
}
function checkExecutionInvariants(string $root): array {
    $violations = [];
    if (PHP_VERSION_ID < 80300) {
        $violations[] = [
            "severity" => "blocking",
            "code" => "PHP_VERSION_UNSUPPORTED",
            "message" => "PHP 8.3+ required"
        ];
    }
    return $violations;
}
function checkNamingConventions(
    string $root,
    array $excludedPrefixes,
    array $excludedFiles,
    array $excludedBasenames,
    array $industryExceptions
): array {
    $violations = [];
    $items = scanFilesystem($root, $excludedPrefixes, $excludedFiles, $excludedBasenames);

    $registrySuffixes = ["Registry.json", "Map.json", "Index.json"];

    foreach ($items as $path => $meta) {
        $base = basename($path);
        if (in_array($base, $industryExceptions, true)) continue;

        // Explicitly skip generated/transient records — they are non-doctrinal
        if (str_starts_with($path, "data/records/") && $meta["type"] === "file") {
            continue;
        }

        if ($meta["type"] === "file") {
            $ext = pathinfo($base, PATHINFO_EXTENSION);
            if ($ext !== "" && $ext !== strtolower($ext)) {
                $violations[] = [
                    "severity" => "blocking",
                    "code" => "EXTENSION_UPPERCASE",
                    "path" => $path,
                    "message" => "File extensions must be lowercase"
                ];
            }

            // Strict check for canonical registries
            foreach ($registrySuffixes as $suffix) {
                if (str_ends_with($base, $suffix)) {
                    $namePart = substr($base, 0, -strlen($suffix));
                    if (!preg_match('/^[a-z][a-zA-Z0-9]*$/', $namePart)) {
                        $violations[] = [
                            "severity" => "blocking",
                            "code" => "REGISTRY_NAMING_INVALID",
                            "path" => $path,
                            "message" => "Canonical registry filenames must use camelCase before the suffix"
                        ];
                    }
                    break;
                }
            }
        } elseif ($meta["type"] === "dir") {
            if (!preg_match('/^[a-z0-9][a-zA-Z0-9_-]*$/', $base)) {
                $violations[] = [
                    "severity" => "warning",
                    "code" => "DIRECTORY_NAMING_UNUSUAL",
                    "path" => $path,
                    "message" => "Directory name deviates from common patterns"
                ];
            }
        }
    }
    return $violations;
}
#endregion

#region SECTION 5 — Audit Runners
function runOperationalAudit(
    string $root,
    array $excludedPrefixes,
    array $excludedFiles,
    array $excludedBasenames,
    array $industryExceptions
): array {
    $rawViolations = array_merge(
        checkRepositoryInventory($root, $excludedPrefixes, $excludedFiles, $excludedBasenames),
        checkMerkleStatus($root),
        checkCriticalFiles($root),
        checkExecutionInvariants($root),
        checkNamingConventions($root, $excludedPrefixes, $excludedFiles, $excludedBasenames, $industryExceptions)
    );

    // Enrich all violations with structured explanations
    $violations = array_map('explainViolation', $rawViolations);

    $hasBlocking = false;
    $hasWarning = false;
    foreach ($violations as $v) {
        if ($v["severity"] === "blocking") {
            $hasBlocking = true;
        } elseif ($v["severity"] === "warning") {
            $hasWarning = true;
        }
    }

    $status = "PASS";
    if ($hasBlocking) {
        $status = "FAIL";
    } elseif ($hasWarning) {
        $status = "WARN";
    }

    return [
        "mode" => "operational",
        "timestamp" => time(),
        "status" => $status,
        "hasWarnings" => $hasWarning,
        "violations" => $violations
    ];
}
function runGovernanceAudit(): array {
    return [
        "mode" => "governance",
        "timestamp" => time(),
        "status" => "PENDING",
        "note" => "Doctrinal evaluation not yet implemented."
    ];
}
#endregion

#region SECTION 6 — Dispatch and Persistent Logging
$result = $mode === "operational"
    ? runOperationalAudit($root, $excludedPrefixes, $excludedFiles, $excludedBasenames, $industryExceptions)
    : runGovernanceAudit();

// Persistent append-only audit log
$auditFile = "$root/data/records/repositoryAudit.json";
@mkdir(dirname($auditFile), 0755, true); // ensure directory

$auditData = file_exists($auditFile)
    ? json_decode(file_get_contents($auditFile), true)
    : ["audits" => []];

if (!isset($auditData["audits"]) || !is_array($auditData["audits"])) {
    $auditData["audits"] = [];
}

$auditData["audits"][] = [
    "timestamp" => time(),
    "mode" => $result["mode"] ?? $mode,
    "status" => $result["status"] ?? "UNKNOWN",
    "hasWarnings" => $result["hasWarnings"] ?? false,
    "violations" => $result["violations"] ?? []
];

$jsonLog = json_encode($auditData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
file_put_contents($auditFile, $jsonLog, LOCK_EX);

// STDOUT report (unchanged contract)
echo json_encode([
    "success" => true,
    "role" => "repositoryAuditor",
    "result" => $result
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
exit;
#endregion