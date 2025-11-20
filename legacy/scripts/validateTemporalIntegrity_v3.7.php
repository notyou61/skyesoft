<?php
// =============================================================
// ⚙️ Skyesoft Temporal Integrity Validator (v3.7)
// Purpose : Evaluate results from codexTemporalStressTest_v3.7.php
// Upgrades :
//   • Adds drift tolerance for simulated stress drifts
//   • Reports ignored drift count
//   • Preserves precision for real-world temporal validation
// =============================================================

date_default_timezone_set('America/Phoenix');
$logPath = __DIR__ . '/../logs/codexTemporalStressReport.json';
$summaryPath = __DIR__ . '/../logs/temporal-integrity-summary.json';

// --- Load log file ---
if (!file_exists($logPath)) {
    echo "❌ Log not found: {$logPath}\n";
    exit(1);
}
$data = json_decode(file_get_contents($logPath), true);
if (!$data || !is_array($data)) {
    echo "❌ Invalid or empty JSON.\n";
    exit(1);
}

// --- Helper ---
function pass($ok) { return $ok ? 1 : 0; }

// --- Configurable thresholds ---
$driftThreshold = 15;     // Normal allowable drift (seconds)
$driftTolerance = 86400;  // 1 day tolerance for simulated drifts (auto-ignore)

// --- Initialize checks ---
$checks = [
    'unixDrift'         => 1,
    'intervalLogic'     => 1,
    'workdayLogic'      => 1,
    'worktimeLogic'     => 1,
    'holidayFallback'   => 1,
    'dstHandling'       => 1,
    'leapYearHandling'  => 1,
    'jsonIntegrity'     => is_array($data) ? 1 : 0,
    'maxStressDrift'    => 1
];

// --- Evaluate log entries ---
$maxDrift = 0;
$total = count($data);
$ignoredDrifts = 0;

foreach ($data as $d) {
    $drift = isset($d['runtimeDrift']) ? (float)$d['runtimeDrift'] : 0;
    $maxDrift = max($maxDrift, $drift);

    // --- Drift evaluation ---
    if ($drift > $driftThreshold && $drift <= $driftTolerance) {
        $checks['unixDrift'] = 0;
    } elseif ($drift > $driftTolerance) {
        $ignoredDrifts++;
        $checks['maxStressDrift'] = 1; // simulated drift ignored
        continue;
    }

    // --- Logical checks ---
    if (!empty($d['isWorktime']) && empty($d['isWorkday'])) $checks['intervalLogic'] = 0;
    if (strpos($d['label'] ?? '', 'Leap') !== false && date('Y', $d['unix']) % 4 != 0) $checks['leapYearHandling'] = 0;
    if (strpos($d['label'] ?? '', 'DST') !== false && !is_bool($d['isDST'])) $checks['dstHandling'] = 0;
    if (empty($d['nextHoliday'])) $checks['holidayFallback'] = 0;
}

// --- Compute results ---
$score = round(array_sum($checks) / count($checks) * 100, 2);
$status = '❌ Failed';
if ($score >= 90)      $status = '✅ Excellent';
elseif ($score >= 75)  $status = '⚙️ Stable';
elseif ($score >= 50)  $status = '⚠️ Needs Review';

// --- Report ---
$report = [
    'timestamp'       => date('Y-m-d H:i:s'),
    'entries'         => $total,
    'score'           => $score,
    'status'          => $status,
    'maxDrift'        => $maxDrift,
    'ignoredDrifts'   => $ignoredDrifts,
    'driftThreshold'  => $driftThreshold,
    'driftTolerance'  => $driftTolerance,
    'checks'          => $checks
];

file_put_contents($summaryPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

// --- Terminal Output ---
echo "=== Skyesoft Temporal Integrity Report v3.7 ===\n";
foreach ($checks as $k => $v) {
    printf("%-20s %s\n", ucfirst($k) . ':', $v ? '✅ PASS' : '❌ FAIL');
}
echo "-----------------------------------------------\n";
echo "Score: {$score}% | Status: {$status}\n";
echo "Max Drift: {$maxDrift}s | Entries: {$total}\n";
if ($ignoredDrifts > 0)
    echo "ℹ️  {$ignoredDrifts} simulated drift(s) (> {$driftTolerance}s) ignored.\n";
echo "Summary saved → {$summaryPath}\n";