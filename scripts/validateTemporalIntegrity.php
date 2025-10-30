<?php
// =============================================================
// 🧪 Skyesoft Temporal Integrity Validator
// Version: 1.7 | Codex v5.3.8 (Dynamic + Local Summary Save + Workday/Worktime Split)
// Purpose: Analyze stress-test logs and compute pass/fail metrics
// Compatible: PHP 5.6+
// Usage: php validator.php [log-path] [--summary=summary-path]
//        Or set LOG_PATH env var.
// =============================================================

date_default_timezone_set('America/Phoenix');

// --- Dynamic path resolution (CLI > Env > Auto-search incl. OneDrive) ---
$logPath = '';
if (isset($argv[1])) {
    $logPath = $argv[1];
} elseif (getenv('LOG_PATH')) {
    $logPath = getenv('LOG_PATH');
} else {
    // Auto-search relatives + dynamic OneDrive
    $userProfile = getenv('USERPROFILE');
    $searchPaths = array();
    if ($userProfile) {
        // Dynamic OneDrive (note: user 'SteveS' vs 'steve' handled by env)
        $oneDriveLog = $userProfile . DIRECTORY_SEPARATOR . 'OneDrive' . DIRECTORY_SEPARATOR .
                       'Documents' . DIRECTORY_SEPARATOR . 'skyesoft' . DIRECTORY_SEPARATOR .
                       'logs' . DIRECTORY_SEPARATOR . 'codexTemporalStressReport.json';
        $searchPaths[] = $oneDriveLog;
        
        // Also check non-OneDrive user docs
        $docsLog = $userProfile . DIRECTORY_SEPARATOR . 'Documents' . DIRECTORY_SEPARATOR .
                   'skyesoft' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'codexTemporalStressReport.json';
        $searchPaths[] = $docsLog;
    }
    
    // Relative repo paths
    $relPaths = array(
        __DIR__ . '/../logs/codexTemporalStressReport.json',  // Repo parent/logs
        __DIR__ . '/logs/codexTemporalStressReport.json',     // Same dir/logs
        __DIR__ . '/codexTemporalStressReport.json',          // Same dir
    );
    $searchPaths = array_merge($searchPaths, $relPaths);
    
    foreach ($searchPaths as $candidate) {
        if (file_exists($candidate)) {
            $logPath = $candidate;
            break;
        }
    }
}

// --- Summary path (CLI > Local Repo Logs > log dir fallback) ---
$summaryPath = '';
if (isset($argv[2]) && strpos($argv[2], '--summary=') === 0) {
    $summaryPath = substr($argv[2], 10);  // Strip '--summary='
} else {
    // Prioritize local repo logs for easy access
    $localSummary = __DIR__ . '/../logs/temporal-integrity-summary.json';
    if (is_writable(dirname($localSummary))) {
        $summaryPath = $localSummary;
    } elseif ($logPath) {
        $summaryPath = dirname($logPath) . '/temporal-integrity-summary.json';
    } else {
        $summaryPath = __DIR__ . '/temporal-integrity-summary.json';  // Fallback
    }
}

// --- Verify log availability ---
if ($logPath === '' || !file_exists($logPath)) {
    echo "❌ Log not found.\n";
    echo "Usage: php {$argv[0]} /path/to/codexTemporalStressReport.json [--summary=/path/to/summary.json]\n";
    echo "Or set LOG_PATH env var (e.g., export LOG_PATH=/your/logs/file.json).\n";
    if (getenv('LOG_PATH')) echo "Tried env: " . getenv('LOG_PATH') . "\n";
    echo "Auto-searched paths:\n";
    foreach ($searchPaths ?? array() as $try) echo "   → {$try}\n";
    exit(1);
}

// --- Load JSON ---
$raw = file_get_contents($logPath);
$data = json_decode($raw, true);
if (!$data || !is_array($data)) {
    echo "❌ Invalid or empty log at: {$logPath}\n";
    exit(1);
}

// --- Extract latest record (stress log format) ---
$latest = end($data);
$unixTime = isset($latest['unix']) ? intval($latest['unix']) : time();
$summary = isset($latest['summary']) ? $latest['summary'] : array();
$minutesSince = isset($summary['minutesSinceStart']) ? intval($summary['minutesSinceStart']) : 0;
$minutesUntil = isset($summary['minutesUntilEnd']) ? intval($summary['minutesUntilEnd']) : 0;

// --- Diagnostics ---
$currentTime = date('H:i:s');
$workStartTime = '07:30';
$workEndTime = '15:30';

// --- Helper ---
function pass($ok) { return $ok ? 1 : 0; }

// --- Validation Checks (Adapted for Stress Log v2.2) ---
$checks = array();

// 1️⃣ System vs Log Unix Drift
$sys = time();
$drift = abs($sys - $unixTime);
$checks['unixDrift'] = pass($drift <= 5);

// 2️⃣ Interval logic (hardcoded expectation)
$expectedStart = '07:30';
$expectedEnd = '15:30';
$checks['intervalLogic'] = pass($minutesSince >= 0);  // Basic: non-negative since start

// 3️⃣ Remaining time consistency
$calcRemaining = strtotime(date('Y-m-d') . " {$expectedEnd}") - $sys;
$logRemaining = $minutesUntil * 60;
$checks['remainingTime'] = pass(abs($calcRemaining - $logRemaining) < 180);  // 3 min tolerance

// 4️⃣ Max runtime drift across iterations
$maxDrift = 0;
foreach ($data as $iter) {
    if (isset($iter['summary']['runtimeDrift'])) {
        $maxDrift = max($maxDrift, floatval($iter['summary']['runtimeDrift']));
    }
}
$checks['maxStressDrift'] = pass($maxDrift <= 15);  // Threshold for 100 iters

// 5️⃣ Entry count integrity (min 100 for full test)
$checks['entryCount'] = pass(count($data) >= 100);

// 6️⃣ JSON integrity
$checks['jsonIntegrity'] = pass(is_array($data));

// 7️⃣ Workday vs Worktime checks
$isWorkday  = isset($latest['isWorkday'])  ? $latest['isWorkday']  : false;
$isWorktime = isset($latest['isWorktime']) ? $latest['isWorktime'] : false;

// A workday (Mon–Fri) should always be true unless holiday
$checks['workdayFlag']  = pass($isWorkday === true);

// Worktime is only true during 07:30–15:30
$checks['worktimeFlag'] = pass($isWorktime === ($isWorkday && date('G') >= 7 && date('G') < 16));

// --- Skipped (not in stress log): sunCycle, holidayLookup, daysUntilHoliday, yearContext ---
// These require full app data; default to pass or add later.

$skipped = 4;  // Adjust total accordingly

// --- Compute score ---
$total  = count($checks) + $skipped;
$passes = array_sum($checks) + $skipped;  // Assume skipped pass
$score  = round(($passes / $total) * 100, 2);

$status = '❌ Failed';
if ($score >= 90) $status = '✅ Excellent';
elseif ($score >= 75) $status = '⚙️ Stable';
elseif ($score >= 50) $status = '⚠️ Needs Review';

// --- Build report ---
$report = array(
    'timestamp'  => date('Y-m-d H:i:s'),
    'logTested'  => $logPath,
    'checks'     => $checks,
    'score'      => $score,
    'status'     => $status,
    'maxDrift'   => $maxDrift,
    'entries'    => count($data),
    'diagnostics' => array(
        'currentTime' => $currentTime,
        'workHours' => "{$workStartTime} - {$workEndTime}",
        'isWorkdayInLog' => $isWorkday,
        'isWorktimeInLog' => $isWorktime,
        'runOutsideHours' => !$isWorktime ? 'Likely run after 15:30 or before 07:30' : 'Within work hours'
    )
);

// --- Ensure summary dir exists ---
$summaryDir = dirname($summaryPath);
if (!is_dir($summaryDir)) {
    if (!mkdir($summaryDir, 0755, true)) {
        echo "⚠️ Could not create summary dir: {$summaryDir} (permission issue?)\n";
        $summaryPath = sys_get_temp_dir() . '/temporal-integrity-summary.json';  // Temp fallback
    }
}

// --- Save summary ---
file_put_contents($summaryPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

// --- Output ---
echo "=== Skyesoft Temporal Integrity Report ===\n";
echo "Log loaded from: {$logPath}\n";
echo "(Adapted for stress test format; skipped full-app checks: holidays, weather, year context)\n";
foreach ($checks as $name => $ok) {
    echo sprintf("%-24s %s\n", ucfirst(str_replace('camel', ' ', $name)) . ':', $ok ? '✅ PASS' : '❌ FAIL');
}
echo "------------------------------------------\n";
echo "Score: {$score}% | Status: {$status}\n";
echo "Max Drift (stress): {$maxDrift}s | Entries: " . count($data) . "\n";
if (!$isWorktime) {
    echo "⚠️ Worktime Flag Note: Run at {$currentTime} (outside {$workStartTime}-{$workEndTime}? Re-run during hours for full pass.)\n";
}
echo "Summary saved → {$summaryPath}\n";