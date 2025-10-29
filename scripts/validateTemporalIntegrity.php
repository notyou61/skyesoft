<?php
// =============================================================
// 🧪 Skyesoft Temporal Integrity Validator
// Version: 1.0 | Codex v5.3.8
// Purpose: Analyze stress-test logs and compute pass/fail metrics
// Compatible: PHP 5.6+
// =============================================================

date_default_timezone_set('America/Phoenix');

$logPath = __DIR__ . '/../logs/codexTemporalStressReport.json';
$summaryPath = __DIR__ . '/../logs/temporal-integrity-summary.json';

// --- Load JSON ---
if (!file_exists($logPath)) {
    echo "❌ Log not found: {$logPath}\n";
    exit(1);
}
$raw = file_get_contents($logPath);
$data = json_decode($raw, true);
if (!$data || !is_array($data)) {
    echo "❌ Invalid or empty log.\n";
    exit(1);
}

// --- Find last record ---
$latest = isset($data[0]) ? end($data) : $data;

// --- Extract metrics ---
$timeDate  = isset($latest['timeDateArray']) ? $latest['timeDateArray'] : [];
$intervals = isset($latest['intervalsArray']) ? $latest['intervalsArray'] : [];
$holidays  = isset($latest['holidays']) ? $latest['holidays'] : [];
$weather   = isset($latest['weatherData']) ? $latest['weatherData'] : [];

// --- Helper ---
function pass($ok) { return $ok ? 1 : 0; }

// --- Validation Checks ---
$checks = [];

// 1. Drift accuracy
$sys = time();
$sse = isset($timeDate['currentUnixTime']) ? $timeDate['currentUnixTime'] : $sys;
$drift = abs($sys - $sse);
$checks['driftAccuracy'] = pass($drift <= 5);

// 2. Interval logic
$start = isset($intervals['workdayIntervals']['start']) ? $intervals['workdayIntervals']['start'] : '';
$end   = isset($intervals['workdayIntervals']['end']) ? $intervals['workdayIntervals']['end'] : '';
$checks['intervalLogic'] = pass($start == '07:30' && $end == '15:30');

// 3. Remaining time consistency
$remaining = isset($intervals['currentDaySecondsRemaining']) ? $intervals['currentDaySecondsRemaining'] : null;
$calcRemaining = strtotime(date('Y-m-d') . " {$end}") - $sys;
$checks['remainingTime'] = pass(abs($calcRemaining - $remaining) < 60);

// 4. Sunrise/sunset validity
$rise = isset($weather['sunrise']) ? $weather['sunrise'] : null;
$set  = isset($weather['sunset']) ? $weather['sunset'] : null;
$checks['sunCycle'] = pass($rise && $set);

// 5. Holiday lookup
$nextHoliday = null;
foreach ($holidays as $h) {
    $ts = strtotime($h['date']);
    if ($ts >= strtotime(date('Y-m-d'))) { $nextHoliday = $h; break; }
}
$checks['holidayLookup'] = pass(!empty($nextHoliday));

// 6. Days until holiday
if ($nextHoliday) {
    $diff = (strtotime($nextHoliday['date']) - strtotime(date('Y-m-d'))) / 86400;
    $checks['daysUntilHoliday'] = pass($diff >= 0 && $diff <= 365);
} else {
    $checks['daysUntilHoliday'] = 0;
}

// 7. Workday flag
$dayType = isset($intervals['dayType']) ? $intervals['dayType'] : '';
$checks['workdayFlag'] = pass($dayType == '0');

// 8. Year context
$totalDays = isset($timeDate['currentYearTotalDays']) ? $timeDate['currentYearTotalDays'] : 365;
$daysPassed = isset($timeDate['currentYearDayNumber']) ? $timeDate['currentYearDayNumber'] : 0;
$daysRemain = isset($timeDate['currentYearDaysRemaining']) ? $timeDate['currentYearDaysRemaining'] : 0;
$checks['yearContext'] = pass(abs(($daysPassed + $daysRemain) - $totalDays) <= 1);

// 9. JSON integrity
$checks['jsonIntegrity'] = pass(is_array($data));

// --- Compute score ---
$total = count($checks);
$passes = array_sum($checks);
$score = round(($passes / $total) * 100, 2);

$status = '❌ Failed';
if ($score >= 90) $status = '✅ Excellent';
elseif ($score >= 75) $status = '⚙️ Stable';
elseif ($score >= 50) $status = '⚠️ Needs Review';

// --- Build report ---
$report = [
    'timestamp' => date('Y-m-d H:i:s'),
    'fileTested' => basename($logPath),
    'checks' => $checks,
    'score' => $score,
    'status' => $status
];

// --- Save summary ---
file_put_contents($summaryPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

// --- Terminal Output ---
echo "=== Skyesoft Temporal Integrity Report ===\n";
foreach ($checks as $name => $ok) {
    echo sprintf("%-24s %s\n", ucfirst($name) . ':', $ok ? '✅ PASS' : '❌ FAIL');
}
echo "------------------------------------------\n";
echo "Score: {$score}% | Status: {$status}\n";
echo "Summary saved → {$summaryPath}\n";
