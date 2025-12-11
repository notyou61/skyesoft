<?php
// ======================================================================
//  Skyesoft — auditor.php
//  Role: Auditor (EGS Section: auditorRole)
//  PHP 8.1+ • Strict Typing
//  Responsibility: Detect structural or Codex violations.
//  Prohibited: Writing to ANY SOT (Codex, registry, inventory).
// ======================================================================

declare(strict_types=1);
header("Content-Type: application/json; charset=UTF-8");

// ----------------------------------------------------------------------
// SECTION I — Utility Fail Handler (No mutation allowed)
// ----------------------------------------------------------------------
function auditorFail(string $msg): never {
    echo json_encode([
        "success" => false,
        "role"    => "auditor",
        "error"   => $msg
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

// ----------------------------------------------------------------------
// SECTION II — Load Codex + Inventory (Read-Only)
// ----------------------------------------------------------------------
$root = dirname(__DIR__);

$codexPath = $root . "/codex/codex.json";
$inventoryPath = $root . "/assets/data/repositoryInventory.json";

if (!file_exists($codexPath)) auditorFail("Codex missing.");
if (!file_exists($inventoryPath)) auditorFail("repositoryInventory.json missing.");

$codex = json_decode(file_get_contents($codexPath), true);
$inventory = json_decode(file_get_contents($inventoryPath), true);

if (!is_array($codex) || !is_array($inventory)) {
    auditorFail("Invalid JSON structure in Codex or repositoryInventory.json.");
}

// ----------------------------------------------------------------------
// SECTION III — Audit Stub (Future logic goes here)
// ----------------------------------------------------------------------
function runAudit(): array {
    return [
        "auditor"   => "Skyesoft Structural Auditor",
        "timestamp" => time(),
        "findings"  => [],
        "notes"     => "Stub only — no structural rules implemented yet."
    ];
}

// ----------------------------------------------------------------------
// SECTION IV — Output Raw Findings
// ----------------------------------------------------------------------
echo json_encode([
    "success"  => true,
    "role"     => "auditor",
    "findings" => runAudit()
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
exit;