<?php
// 📄 File: scripts/codexComplianceScan.php
// Version: 1.3 – Inventory-Aware Filtering: Skips legacy/safety per moduleInventory.json
// Changelog: v1.2 → v1.3: Integrated scanActiveModules v1.4 logic—load inventory, auto-skip non-actives.
// Codex-Aligned: Transparency (skip logs), Consistency (active-only scans), Scalability (JSON chaining), Resilience (fallback to full scan).

// === Load module inventory for exclusions ===
$inventoryPath = __DIR__ . '/../logs/moduleInventory.json';
$inventory = array();
if (file_exists($inventoryPath)) {
    $inventory = json_decode(file_get_contents($inventoryPath), true) ?: array();
} else {
    echo "⚠️ No inventory found at {$inventoryPath}; scanning all files.\n";
}

function scanFileForCodexViolations($filePath) {
    $rules = array(
        'Hardcoded Time' => '/\b(0?[1-9]|1[0-2]):[0-5][0-9]\s?(AM|PM)\b/i',
        'Static Dates' => '/\b(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\b/i',
        'Regex Classification' => '/preg_match\s*\(.*(work|time|day|sun(set|rise)|holiday).*\/[imsx]?/',
        'Hardcoded Path' => '/["\']https?:\/\/[^"\']+["\']/',
        'Conditional Violation' => '/if\s*\([^)]*["\'][^"\']+["\'][^)]*\)/',
        'Literal Constants' => '/[><=]\s*(\d{2,4})/'
    );

    $recommendations = array(
        'Hardcoded Time' => 'Replace with $tis[\'segmentsOffice\'] or $tis[\'segmentsShop\'] from SSE load.',
        'Static Dates' => 'Reference $holidaysDynamic from federalHolidays.php or SSE weatherData.',
        'Regex Classification' => 'Use Codex actions/subtypes or Semantic Responder for taxonomy matching—no regex.',
        'Hardcoded Path' => 'Replace with $siteMeta[\'baseUrl\'] or dynamic root from SSE siteMeta.',
        'Conditional Violation' => 'Map to semantic variables from ontologySchema.relationships—no string literals.',
        'Literal Constants' => 'Pull thresholds from SSE kpiData or Codex config values.'
    );

    $code = @file_get_contents($filePath);
    if (!$code) return array('error' => 'Unable to read file.');

    $results = array();
    foreach ($rules as $ruleName => $pattern) {
        preg_match_all($pattern, $code, $matches, PREG_OFFSET_CAPTURE);
        if (!empty($matches[0])) {
            $hits = array();
            foreach ($matches[0] as $match) {
                $line = substr_count($code, "\n", 0, $match[1]) + 1;  // Basic line calc (PHP 5.6 safe)
                $hits[] = $match[0] . ' (line ~' . $line . ')';
            }
            $results[$ruleName] = array_unique($hits);
        }
    }

    // Attach recs to results for JSON
    foreach ($results as $rule => &$hits) {
        if (isset($recommendations[$rule])) {
            $hits['recommendation'] = $recommendations[$rule];  // Nested for easy parsing
        }
    }

    return $results;
}

// Main Scanner
$targetDir = __DIR__ . '/../api/';
$allResults = array();  // Aggregate for JSON
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($targetDir));
$totalFiles = 0;
$skippedFiles = 0;
$totalViolations = 0;

foreach ($rii as $file) {
    if (pathinfo($file, PATHINFO_EXTENSION) !== 'php') continue;
    $totalFiles++;
    $relPath = basename($file);  // Use basename for inventory match (per scanActiveModules)

    // Skip legacy or safety files
    if (isset($inventory[$relPath]) && $inventory[$relPath] !== 'active') {
        $skipType = $inventory[$relPath];
        echo "⏭️ Skipping {$relPath} ({$skipType})\n";
        $skippedFiles++;
        continue;
    }

    $violations = scanFileForCodexViolations($file);
    if (!empty($violations) && !isset($violations['error'])) {
        $fileKey = (string)$file;
        $allResults[$fileKey] = $violations;
        echo "\n🔎 {$file}\n";
        foreach ($violations as $rule => $hits) {
            if (is_array($hits) && isset($hits['recommendation'])) {
                $hitList = implode(', ', array_slice(array_filter($hits, 'is_string'), 0, 3));
                echo "  ⚠️ {$rule}: {$hitList}\n";
                echo "     💡 Recommendation: {$hits['recommendation']}\n";
                unset($hits['recommendation']);  // Clean for CLI
            } else {
                echo "  ⚠️ {$rule}: " . implode(', ', array_slice($hits, 0, 3)) . "\n";
            }
            $totalViolations += count(array_filter($hits, 'is_string'));
        }
    }
}

// === 📄 Dual Output: CLI Summary + JSON Log ===
echo "\n📊 CLI Summary: {$totalViolations} violations across {$totalFiles} files ({$skippedFiles} skipped via inventory).\n";

$savePath = __DIR__ . '/../logs/codexComplianceReport.json';
$report = array(
    'meta' => array(
        'timestamp' => date('c'),  // ISO 8601 for audit
        'scanDate' => date('Y-m-d'),  // e.g., 2025-10-25
        'dayType' => 'Weekend',  // Hardcoded for now; future: load TIS dynamically
        'environment' => php_uname(),
        'mode' => 'dual',
        'version' => '1.3',
        'inventoryVersion' => '1.4',  // Ties to scanActiveModules
        'totalFiles' => $totalFiles,
        'skippedFiles' => $skippedFiles,
        'totalViolations' => $totalViolations
    ),
    'results' => $allResults
);

// Ensure logs dir exists (resilience)
$logDir = dirname($savePath);
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

// Write JSON (audit trail)
$jsonWrite = file_put_contents($savePath, json_encode($report, JSON_PRETTY_PRINT));
if ($jsonWrite === false) {
    echo "❌ Error: Could not write JSON log to {$savePath}.\n";
} else {
    echo "✅ Full report saved to: {$savePath} (JSON for Skyebot/RAG ingestion).\n";
}