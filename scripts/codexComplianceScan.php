<?php
// 📄 File: scripts/codexComplianceScan.php
// Version: 1.0 – Static code audit based on Codex principles

function scanFileForCodexViolations($filePath) {
    $rules = array(
        'Hardcoded Time' => '/\b(0?[1-9]|1[0-2]):[0-5][0-9]\s?(AM|PM)\b/i',
        'Static Dates' => '/\b(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\b/i',
        'Regex Classification' => '/preg_match\s*\(.*(work|time|day|sun(set|rise)|holiday).*\/[imsx]?/',
        'Hardcoded Path' => '/["\']https?:\/\/[^"\']+["\']/',
        'Conditional Violation' => '/if\s*\([^)]*["\'][^"\']+["\'][^)]*\)/',
        'Literal Constants' => '/[><=]\s*(\d{2,4})/'
    );

    $code = @file_get_contents($filePath);
    if (!$code) return array('error' => 'Unable to read file.');

    $results = array();
    foreach ($rules as $ruleName => $pattern) {
        if (preg_match_all($pattern, $code, $matches)) {
            $results[$ruleName] = array_unique($matches[0]);
        }
    }

    return $results;
}

// Usage example
$targetDir = __DIR__ . '/../api/';
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($targetDir));
foreach ($rii as $file) {
    if (pathinfo($file, PATHINFO_EXTENSION) === 'php') {
        $violations = scanFileForCodexViolations($file);
        if (!empty($violations)) {
            echo "\n🔎 {$file}\n";
            foreach ($violations as $rule => $hits) {
                echo "  ⚠️ {$rule}: " . implode(', ', array_slice($hits, 0, 3)) . "\n";
            }
        }
    }
}