<?php
// ======================================================================
//  FILE: codex_testDocuments.php
//  PURPOSE: Validate Codex document type compliance + folder conventions
//  VERSION: v1.2 (Fixed Registry Validation)
//  AUTHOR: CPAP-01 (Parliamentarian) • MTCO Implementation
//  PHP 5.6 Compatible
// ======================================================================

error_reporting(E_ALL);
ini_set('display_errors', 1);

// ------------------------------------------------------------
//  Locate and load Codex
// ------------------------------------------------------------
$root       = dirname(__DIR__, 2);
$codexPath = 'C:\Users\steve\OneDrive\Documents\skyesoft\assets\data\codex.json';

if (!file_exists($codexPath)) {
    die("❌ Codex not found at: $codexPath\n");
}

$codex = json_decode(file_get_contents($codexPath), true);
if (!$codex || !is_array($codex)) {
    die("❌ Invalid or unreadable Codex JSON.\n");
}

// ======================================================================
//  Phase I — Document Registry Compliance
// ======================================================================

echo "🧾 Skyesoft Codex Document Test — " . date('F j, Y g:i A') . "\n";
echo "------------------------------------------------------------\n";

// --- Extract document type registry (canonical path) ---
$registry = array();
$registryPath = 'documentStandards.documentTypesRegistry';

if (
    isset($codex['documentStandards']['documentTypesRegistry']['items']) &&
    is_array($codex['documentStandards']['documentTypesRegistry']['items'])
) {
    foreach ($codex['documentStandards']['documentTypesRegistry']['items'] as $entry) {
        if (isset($entry['key']) && isset($entry['type'])) {
            $registry[$entry['key']] = $entry['type'];
        }
    }
    echo "✅ DocumentTypesRegistry loaded successfully (" . count($registry) . " items).\n";
} else {
    die("❌ documentTypesRegistry missing or malformed under canonical path: $registryPath\n");
}

// ------------------------------------------------------------
//  Phase II — Folder Conventions Audit (DRY Integration)
// ------------------------------------------------------------
echo "\n📂 Skyesoft Folder Conventions Audit\n";
echo "------------------------------------------------------------\n";

// Handle both flat map and doctrinal directive-with-items[]
if (isset($codex['folderConventions']['items'])
    && is_array($codex['folderConventions']['items'])) {
    $folderItems = $codex['folderConventions']['items'];          // doctrinal form
} elseif (is_array($codex['folderConventions'])) {
    $folderItems = $codex['folderConventions'];                   // flat form
} else {
    echo "⚠️  No folderConventions map defined in Codex.\n";
    exit;
}

$folderCount  = 0;
$missingCount = 0;

foreach ($folderItems as $key => $info) {
    // Support both indexed array (has 'key') and associative map
    if (is_array($info) && isset($info['key'])) {
        $key = $info['key'];
    }
    $expectedPath = isset($info['path']) ? $info['path'] : $key;
    $expectedType = isset($info['type']) ? $info['type'] : 'unspecified';

    if (is_dir($root . '/' . $expectedPath)) {
        printf("✅ %-25s | %-12s | %s\n", $key, $expectedType, $expectedPath);
        $folderCount++;
    } else {
        printf("⚠️  %-25s | %-12s | MISSING: %s\n", $key, $expectedType, $expectedPath);
        $missingCount++;
    }
}

echo "------------------------------------------------------------\n";
echo "📘 Valid folders: $folderCount / " . count($folderItems) . "\n";

if ($missingCount > 0) {
    echo "⚠️  $missingCount folders missing or mismatched.\n";
} else {
    echo "✅ All required folders conform to the Codex Folder Conventions.\n";
}

echo "============================================================\n";
echo "🏁 Codex Validation Complete — DRY Integration Successful\n";