<?php
// =============================================================
// 🧭 Skyesoft Codex Compliance Scanner v1.5
// Purpose: Adds context-aware fix recommendations for each violation.
// Audits all PHP scripts for DRY, Temporal, and CPD-03.A compliance.
// =============================================================

date_default_timezone_set('America/Phoenix');

$root = realpath(__DIR__ . '/../');
$logDir = $root . '/logs';
$reportFile = $logDir . '/codexComplianceReport.json';
$sseMetaFile = $root . '/assets/data/complianceMeta.json';

if (!is_dir($logDir)) mkdir($logDir, 0775, true);

$targets = [
    $root . '/api',
    $root . '/scripts'
];

$issues = [];
$totalFiles = 0;

// -------------------------------------------------------------
// 🔍 DEFINE FIX SUGGESTIONS
// -------------------------------------------------------------
$fixes = [
    'date()' => [
        'summary' => 'Replace direct PHP date() calls with $timeData values from SSE.',
        'example' => [
            '❌ $now = date("Y-m-d H:i:s");',
            '✅ $now = $timeData["currentDateTime"];'
        ]
    ],
    'strtotime' => [
        'summary' => 'Remove procedural time math; use Codex temporalResolvers or SSE deltas.',
        'example' => [
            '❌ $days = (strtotime($holiday) - time()) / 86400;',
            '✅ $days = $temporalResolvers["holidays"];'
        ]
    ],
    'timezone' => [
        'summary' => 'Replace hardcoded timezone with dynamic $timeData["timeZone"].',
        'example' => [
            '❌ date_default_timezone_set("America/Phoenix");',
            '✅ date_default_timezone_set($timeData["timeZone"]);'
        ]
    ],
    'temporalDoctrine' => [
        'summary' => 'Inject Codex doctrine context before using temporalReasoning.',
        'example' => [
            '✅ $temporalDoctrine = $codex["aiIntegration"]["temporalReasoning"];',
            '✅ $temporalResolvers = $codex["aiIntegration"]["temporalResolvers"];'
        ]
    ],
    'proceduralResolver' => [
        'summary' => 'Remove references to legacy resolver files (nextHolidayResolver, workdayResolver, etc).',
        'example' => [
            '❌ include("nextHolidayResolver.php");',
            '✅ Use Codex["aiIntegration"]["temporalResolvers"]["holidays"]'
        ]
    ]
];

// -------------------------------------------------------------
// 🔎 SCAN LOOP (v1.6-Legacy, PHP 5.6 Compatible)
// -------------------------------------------------------------
foreach ($targets as $dir) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    foreach ($iterator as $fileObj) {
        if (pathinfo($fileObj, PATHINFO_EXTENSION) !== 'php') continue;
        $totalFiles++;
        $file = (string)$fileObj;
        $lines = file($file, FILE_IGNORE_NEW_LINES);
        $fileIssues = array();

        foreach ($lines as $i => $line) {
            $lineNum = $i + 1;

            // 1️⃣ DRY Violation: date()
            if (preg_match('/\bdate\s*\(/i', $line)) {
                $fileIssues[] = array(
                    'line'     => $lineNum,
                    'snippet'  => trim($line),
                    'issue'    => '⚠️ Direct use of date() — use $timeData or Codex temporalReasoning.',
                    'category' => 'DRY',
                    'fix'      => $fixes['date()']
                );
            }

            // 2️⃣ Temporal: strtotime / mktime / DateTime
            if (preg_match('/strtotime|mktime|DateTime/i', $line)) {
                $fileIssues[] = array(
                    'line'     => $lineNum,
                    'snippet'  => trim($line),
                    'issue'    => '⚠️ Procedural time calculation — replace with Codex temporalResolvers.',
                    'category' => 'Temporal',
                    'fix'      => $fixes['strtotime']
                );
            }

            // 3️⃣ Hardcoded timezone
            if (preg_match('/America\\/Phoenix|UTC[-+0-9]/i', $line)
                && strpos($file, 'getDynamicData.php') === false) {
                $fileIssues[] = array(
                    'line'     => $lineNum,
                    'snippet'  => trim($line),
                    'issue'    => '⚠️ Hardcoded timezone detected — use $timeData["timeZone"].',
                    'category' => 'Temporal',
                    'fix'      => $fixes['timezone']
                );
            }

            // 4️⃣ Missing Doctrine Binding
            if (strpos($line, 'temporalDoctrine') === false &&
                strpos($line, 'temporalReasoning') !== false) {
                $fileIssues[] = array(
                    'line'     => $lineNum,
                    'snippet'  => trim($line),
                    'issue'    => '❌ temporalReasoning referenced but temporalDoctrine not injected.',
                    'category' => 'Temporal',
                    'fix'      => $fixes['temporalDoctrine']
                );
            }

            // 5️⃣ CPD-03.A Violation
            if (preg_match('/nextHolidayResolver|workdayResolver|holidayCalc/i', $line)) {
                $fileIssues[] = array(
                    'line'     => $lineNum,
                    'snippet'  => trim($line),
                    'issue'    => '❌ Procedural resolver reference — violates Codex CPD-03.A.',
                    'category' => 'Temporal',
                    'fix'      => $fixes['proceduralResolver']
                );
            }
        }

        if (!empty($fileIssues)) {
            $issues[$file] = $fileIssues;
        }
    }
}

// -------------------------------------------------------------
// 🧾 REPORT GENERATION (Unified v1.7 – PHP 5.6 Compatible)
// -------------------------------------------------------------
$fileReports = array();
foreach ($issues as $file => $warnings) {
    $fileEntry = array(
        'file'   => str_replace($root . DIRECTORY_SEPARATOR, '', $file),
        'issues' => array()
    );

    foreach ($warnings as $w) {
        $fileEntry['issues'][] = array(
            'line'     => isset($w['line']) ? $w['line'] : '?',
            'category' => isset($w['category']) ? $w['category'] : 'General',
            'issue'    => isset($w['issue']) ? $w['issue'] : '(No issue text)',
            'snippet'  => isset($w['snippet']) ? $w['snippet'] : '',
            'fix'      => isset($w['fix']) ? $w['fix'] : array()
        );
    }

    $fileReports[] = $fileEntry;
}

// 🧮 Calculate stats
$nonCompliant = count($issues);
$score = round((1 - ($nonCompliant / max(1, $totalFiles))) * 100, 2);

// 🧾 Build single merged JSON object
$report = array(
    'scanTimestamp'       => time(),
    'scanDate'            => date('Y-m-d H:i:s'),
    'environment'         => php_uname('n'),
    'scannedFiles'        => $totalFiles,
    'nonCompliantFiles'   => $nonCompliant,
    'complianceScore'     => $score,
    'codexVersion'        => 'v5.3.7',
    'governanceClause'    => 'CPD-03.A – Elimination of Procedural Temporal Resolvers',
    'status'              => $nonCompliant === 0 ? 'Compliant' : 'Non-Compliant',
    'files'               => $fileReports
);

file_put_contents($reportFile, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

/// -------------------------------------------------------------
// ✅ CLI OUTPUT (v1.5.2 - fully safe & normalized)
// -------------------------------------------------------------
echo "=== Codex Compliance Scan v1.5.2 ===\n";
echo "Scanned {$totalFiles} PHP files.\n";
echo "Compliance Score: {$score}%\n";

if ($nonCompliant === 0) {
    echo "✅ All files compliant.\n";
} else {
    echo "⚠️ Non-compliant files: {$nonCompliant}\n";

    foreach ($issues as $file => $warnings) {
        // Normalize $file (could be SplFileInfo)
        $filePath = is_object($file) && method_exists($file, 'getPathname')
            ? $file->getPathname()
            : (string) $file;

        echo "\n" . $filePath . "\n";

        // Normalize $warnings structure
        if (!is_array($warnings)) {
            echo "  - ⚠️ Unexpected issue format: " . json_encode($warnings) . "\n";
            continue;
        }

        foreach ($warnings as $w) {
            // Legacy string or array-of-arrays handling
            if (is_string($w)) {
                echo "  - $w\n";
                continue;
            }

            if (!is_array($w)) {
                echo "  - ⚠️ Unknown warning type: " . json_encode($w) . "\n";
                continue;
            }

            $issueText = isset($w['issue']) ? $w['issue'] : '(No issue text)';
            $category  = isset($w['category']) ? $w['category'] : 'General';
            $lineNum   = isset($w['line']) ? $w['line'] : '?';
            echo '  [Line ' . $lineNum . '] ';
            echo "  - {$issueText} ({$category})\n";

            if (!empty($w['fix']) && is_array($w['fix'])) {
                echo "    🔧 Fix: {$w['fix']['summary']}\n";
                if (!empty($w['fix']['example']) && is_array($w['fix']['example'])) {
                    foreach ($w['fix']['example'] as $ex) {
                        echo "      {$ex}\n";
                    }
                }
            }
        }
    }
}

echo "\nReport written to: {$reportFile}\n";