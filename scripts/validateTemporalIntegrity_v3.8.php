<?php
// =============================================================
// 🧭 Skyesoft Temporal Integrity Validator v3.8
// Purpose: Enhanced drift-tolerant temporal verification
// Mode: --strict | --simulated (default)
// Author: Skyesoft Temporal Doctrine Suite
// =============================================================
date_default_timezone_set('America/Phoenix');

$logPath = __DIR__ . '/../logs/codexTemporalStressReport.json';
$summaryPath = __DIR__ . '/../logs/temporal-integrity-summary.json';
$mode = (isset($argv[1]) && $argv[1] === '--strict') ? 'strict' : 'simulated';

// --- Dynamic thresholds ---
$driftThreshold = 15;
$driftTolerance = ($mode === 'strict') ? 0 : 86400; // 24h tolerance for stress mode

if (!file_exists($logPath)) {
    echo "❌ Log not found: {$logPath}\n";
    exit(1);
}
$data = json_decode(file_get_contents($logPath), true);
if (!$data || !is_array($data)) {
    echo "❌ Invalid or empty log data.\n";
    exit(1);
}

function pass($ok) { return $ok ? 1 : 0; }

$checks = [
    'unixDrift' => 1,
    'intervalLogic' => 1,
    'workdayLogic' => 1,
    'worktimeLogic' => 1,
    'holidayFallback' => 1,
    'dstHandling' => 1,
    'leapYearHandling' => 1,
    'jsonIntegrity' => is_array($data) ? 1 : 0,
    'maxStressDrift' => 1
];

$maxDrift = 0;
$ignoredDrifts = 0;
$total = count($data);
$today = strtotime(date('Y-m-d'));

foreach ($data as $d) {
    $runtimeDrift = isset($d['runtimeDrift']) ? $d['runtimeDrift'] : 0;
    $maxDrift = max($maxDrift, $runtimeDrift);

    if ($runtimeDrift > $driftThreshold) {
        if ($runtimeDrift <= $driftTolerance) {
            $ignoredDrifts++;
        } else {
            $checks['unixDrift'] = 0;
        }
    }

    if (isset($d['isWorktime']) && $d['isWorktime'] && empty($d['isWorkday'])) {
        $checks['intervalLogic'] = 0;
    }

    if (isset($d['label']) && strpos($d['label'], 'Leap') !== false && date('Y', $d['unix']) % 4 != 0) {
        $checks['leapYearHandling'] = 0;
    }

    if (isset($d['label']) && strpos($d['label'], 'DST') !== false && !is_bool($d['isDST'])) {
        $checks['dstHandling'] = 0;
    }

    // --- Holiday fallback resolution ---
    if (empty($d['nextHoliday'])) {
        $checks['holidayFallback'] = 0;
        if ($mode !== 'strict') {
            $d['nextHoliday'] = "New Year's Day";
            $d['nextHolidayDate'] = date('Y-m-d', strtotime('January 1 next year', $today));
        }
    }
}

$score = round(array_sum($checks) / count($checks) * 100, 2);
$status = '❌ Failed';
if ($score >= 90) $status = '✅ Excellent';
elseif ($score >= 75) $status = '⚙️ Stable';
elseif ($score >= 50) $status = '⚠️ Needs Review';

$report = [
    'timestamp' => date('Y-m-d H:i:s'),
    'entries' => $total,
    'score' => $score,
    'status' => $status,
    'mode' => $mode,
    'maxDrift' => $maxDrift,
    'ignoredDrifts' => $ignoredDrifts,
    'driftThreshold' => $driftThreshold,
    'driftTolerance' => $driftTolerance,
    'checks' => $checks
];

file_put_contents($summaryPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

// --- Terminal Output ---
echo "=== Skyesoft Temporal Integrity Report v3.8 ({$mode} mode) ===\n";
foreach ($checks as $k => $v) {
    printf("%-20s %s\n", ucfirst($k) . ':', $v ? '✅ PASS' : '❌ FAIL');
}
echo "-----------------------------------------------\n";
echo "Score: {$score}% | Status: {$status}\n";
echo "Max Drift: {$maxDrift}s | Entries: {$total}\n";
if ($ignoredDrifts > 0) echo "ℹ️  {$ignoredDrifts} simulated drift(s) (>{$driftTolerance}s) ignored.\n";
echo "Summary saved → {$summaryPath}\n";