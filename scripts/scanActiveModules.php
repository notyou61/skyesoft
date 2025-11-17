<?php
// 📄 scripts/scanActiveModules.php
// Version: 1.5 – Dual-Purpose: Inventory + Compliance Suggestions
// Changelog: v1.4 → v1.5: Added Phase 2 compliance scan with MD suggestions for active files only.
// Codex-Aligned: Transparency (suggestions log), Consistency (active-only), Scalability (chained to SSE/Codex), Resilience (non-destructive).

$root = realpath(__DIR__ . '/../api');
$rii  = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
$activeFiles = [];
$legacyFiles = [];
$safetyFiles = [];
$totalChecked = 0;

// Match include/require references
$pattern = '/\b(require|require_once|include|include_once)\s*\(?[\'"]([^\'" ]+\.php)[\'" ]?\)?/i';

// Classification patterns
$legacyPattern = '/(legacy|old|_bak|_backup|archive|deprecated)/i';
$safetyPattern = '/(\d+\.php$|safety|_1\.php$|_safe\.php$)/i';

foreach ($rii as $file) {
    if (!is_file($file) || pathinfo($file, PATHINFO_EXTENSION) !== 'php') continue;
    $totalChecked++;
    $fileName = basename($file);
    $relPath = str_replace($root . DIRECTORY_SEPARATOR, '', $file);

    // Classify legacy/safety (use basename for consistency)
    if (preg_match($legacyPattern, $fileName)) {
        $legacyFiles[$fileName] = 'legacy';  // Changed to basename
        continue;
    }
    if (preg_match($safetyPattern, $fileName)) {
        $safetyFiles[$fileName] = 'safety';  // Changed to basename
        continue;
    }

    // Detect active require/include references
    $code = @file_get_contents($file);
    if ($code === false) continue;

    if (preg_match_all($pattern, $code, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $ref = basename($match[2]);
            $activeFiles[$ref] = 'active';
        }
    }
}

// Add known entry points if missing
$entryPoints = ['askOpenAI.php', 'getDynamicData.php', 'generateReports.php', 'policyEngine.php'];
foreach ($entryPoints as $ep) {
    if (!isset($activeFiles[$ep]) && file_exists($root . '/' . $ep)) {
        $activeFiles[$ep] = 'active';
    }
}

// Merge all classes (all basenames now)
$inventory = array_merge($activeFiles, $legacyFiles, $safetyFiles);
ksort($inventory);

// Write JSON log
$savePath = __DIR__ . '/../logs/moduleInventory.json';
if (!is_dir(dirname($savePath))) mkdir(dirname($savePath), 0755, true);

file_put_contents($savePath, json_encode($inventory, JSON_PRETTY_PRINT));
echo "✅ Module classification log saved to logs/moduleInventory.json\n";

// Summary
echo "📊 Totals: Active=" . count($activeFiles) . " | Legacy=" . count($legacyFiles) . " | Safety=" . count($safetyFiles) . " | Checked={$totalChecked}\n";

// === Phase 2: Compliance-with-Suggestion Scan ===
$logPath = __DIR__ . '/../logs/moduleSuggestions.md';
$suggestions = [
  'Hardcoded Path'       => 'Use $siteMeta["baseUrl"] or Codex["apiMap"] instead of literal URLs.',
  'Hardcoded Time'       => 'Pull from $tis["segmentsShop"] or SSE dynamic schedule.',
  'Static Dates'         => 'Use federalHolidays.php dynamic list or $sse["weatherData"].',
  'Regex Classification' => 'Route via policyEngine semantic resolver instead of regex.',
  'Conditional Violation'=> 'Replace literal string checks with ontology variables.',
  'Literal Constants'    => 'Move numeric constants into Codex config or SSE KPI map.'
];
$rules = [
  'Hardcoded Path'        => '/["\']https?:\/\/[^"\']+["\']/i',
  'Hardcoded Time'        => '/\b(0?[1-9]|1[0-2]):[0-5][0-9]\s?(AM|PM)\b/i',
  'Static Dates'          => '/\b(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\b/i',
  'Regex Classification'  => '/preg_match\s*\(.*(work|time|day|sun(set|rise)|holiday).*\/[imsx]?/i',
  'Conditional Violation' => '/if\s*\([^)]*["\'][^"\']+["\'][^)]*\)/i',
  'Literal Constants'     => '/[><=]\s*(\d{2,4})/'
];

$md = "# 🧭 Skyesoft Codex Compliance Suggestions (" . date('Y-m-d') . ")\n\n";
$violationCount = 0;
foreach ($inventory as $fileName => $status) {
    if ($status !== 'active') continue;
    $path = findFilePath($fileName, $root);
    if (!$path) continue;
    $code = @file_get_contents($path);
    if (!$code) continue;

    $fileHasViolations = false;
    foreach ($rules as $rule => $pattern) {
        preg_match_all($pattern, $code, $matches, PREG_OFFSET_CAPTURE);
        if (!empty($matches[0])) {
            $fileHasViolations = true;
            foreach ($matches[0] as $match) {
                $line = substr_count($code, "\n", 0, $match[1]) + 1;
                $snippetStart = max(0, $match[1] - 40);
                $snippet = trim(substr($code, $snippetStart, 80));
                $md .= "📄 **{$fileName}**  \n";
                $md .= "⚠️ {$rule} (line ~{$line})  \n";
                $md .= "`{$snippet}`  \n";
                $md .= "💡 *" . ($suggestions[$rule] ?? 'Review logic for Codex compliance.') . "*  \n";
                $md .= "──────────────────────────────\n";
                $violationCount++;
            }
        }
    }
    if (!$fileHasViolations) {
        $md .= "📄 **{$fileName}**  \n";
        $md .= "✅ No violations detected.  \n";
        $md .= "──────────────────────────────\n";
    }
}

// Utility: locate file recursively
function findFilePath($fileName, $root) {
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
    foreach ($rii as $file) {
        if (basename($file) === $fileName) return (string)$file;
    }
    return null;
}

if (!is_dir(dirname($logPath))) mkdir(dirname($logPath), 0755, true);
file_put_contents($logPath, $md);
echo "✅ Suggestion report saved to logs/moduleSuggestions.md ( {$violationCount} suggestions )\n";
