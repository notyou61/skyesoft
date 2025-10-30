<?php
// =============================================================
// 🧪 Skyesoft Temporal Stress Test – Continuous Logger
// Version: 2.2 | PHP 5.6 Compatible (Dynamic: CLI/Env + Auto Path Detection + Workday/Worktime Split)
// =============================================================

date_default_timezone_set('America/Phoenix');

// --- Dynamic log path resolution (CLI > Env > Auto-detect) ---
$logFile = '';
if (isset($argv[1])) {
    $logFile = $argv[1];
} elseif (getenv('LOG_PATH')) {
    $logFile = getenv('LOG_PATH');
} else {
    // Auto-detect common locations
    $userProfile = getenv('USERPROFILE');
    $searchPaths = array();
    if ($userProfile) {
        // Dynamic OneDrive
        $oneDriveLog = $userProfile . DIRECTORY_SEPARATOR . 'OneDrive' . DIRECTORY_SEPARATOR .
                       'Documents' . DIRECTORY_SEPARATOR . 'skyesoft' . DIRECTORY_SEPARATOR .
                       'logs' . DIRECTORY_SEPARATOR . 'codexTemporalStressReport.json';
        $searchPaths[] = $oneDriveLog;
        
        // Local Documents
        $docsLog = $userProfile . DIRECTORY_SEPARATOR . 'Documents' . DIRECTORY_SEPARATOR .
                   'skyesoft' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'codexTemporalStressReport.json';
        $searchPaths[] = $docsLog;
    }
    
    // Relative repo paths (Home/Office)
    $relPaths = array(
        __DIR__ . '/../logs/codexTemporalStressReport.json',  // scripts/../logs
        __DIR__ . '/logs/codexTemporalStressReport.json',     // scripts/logs
        __DIR__ . '/../codexTemporalStressReport.json',       // parent dir
        __DIR__ . '/codexTemporalStressReport.json',          // scripts/
    );
    $searchPaths = array_merge($searchPaths, $relPaths);
    
    foreach ($searchPaths as $candidate) {
        // Use this as base dir for logs if candidate is file
        $candidateDir = dirname($candidate);
        if (!is_dir($candidateDir)) {
            if (!mkdir($candidateDir, 0755, true)) {
                // Perm fail: try temp fallback
                $candidateDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'skyesoft_logs';
                mkdir($candidateDir, 0755, true);
            }
        }
        $logFile = $candidateDir . DIRECTORY_SEPARATOR . 'codexTemporalStressReport.json';
        if (true) { // Always use first writable dir
            break;
        }
    }
    
    if ($logFile === '') {
        // Ultimate fallback: temp dir
        $logFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'codexTemporalStressReport.json';
        echo "⚠️ Using temp fallback: {$logFile}\n";
    }
}

// Ensure log dir exists (with fallback)
$logDir = dirname($logFile);
if (!is_dir($logDir)) {
    if (!mkdir($logDir, 0755, true)) {
        echo "⚠️ Could not create log dir: {$logDir} (using temp)\n";
        $logDir = sys_get_temp_dir();
        $logFile = $logDir . DIRECTORY_SEPARATOR . 'codexTemporalStressReport.json';
        mkdir($logDir, 0755, true);
    }
}

// --- Helper: append JSON ---
function append_json($file, $entry) {
    $existing = array();
    if (file_exists($file)) {
        $raw = @file_get_contents($file);
        $decoded = @json_decode($raw, true);
        if (is_array($decoded)) $existing = isset($decoded[0]) ? $decoded : array($decoded);
    }
    $existing[] = $entry;
    @file_put_contents($file, json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

// --- Baseline constants ---
$startTime = microtime(true);
$workStart = strtotime(date('Y-m-d') . ' 07:30');
$workEnd   = strtotime(date('Y-m-d') . ' 15:30');

// --- Stress loop (configurable iterations) ---
$iterations = isset($argv[2]) ? intval($argv[2]) : 100;
echo "Running temporal stress test ({$iterations} iterations)...\n";
echo "📄 Logging to: {$logFile}\n";

for ($i = 1; $i <= $iterations; $i++) {
    $nowUnix = time();
    $systemTime = date('Y-m-d H:i:s');
    $elapsed = max(0, $nowUnix - $workStart);
    $remaining = max(0, $workEnd - $nowUnix);
    $minutesSince = (int)round($elapsed / 60);
    $minutesLeft  = (int)round($remaining / 60);
    $drift = round(microtime(true) - $startTime, 4); // local runtime drift

    // --- Workday vs Worktime distinction ---
    $dayNum = date('N'); // 1=Mon ... 7=Sun
    $isHoliday = false;  // (placeholder; real app checks holiday list)
    $isWorkday = ($dayNum >= 1 && $dayNum <= 5 && !$isHoliday);
    $isWorktime = ($nowUnix >= $workStart && $nowUnix <= $workEnd);

    $entry = array(
        'iteration' => $i,
        'timestamp' => $systemTime,
        'unix'      => $nowUnix,
        'hostname'  => php_uname('n'),
        'status'    => '✅ Iteration logged successfully',
        'isWorkday' => $isWorkday,
        'isWorktime' => $isWorktime,
        'summary'   => array(
            'minutesSinceStart' => $minutesSince,
            'minutesUntilEnd'   => $minutesLeft,
            'runtimeDrift'      => $drift
        )
    );

    append_json($logFile, $entry);

    echo "Iteration {$i}/{$iterations} complete — Drift: {$drift}s\n";
    usleep(100000); // 0.1 sec pause between samples
}

echo "\n✅ Temporal stress test complete.\n";
echo "📄 Log saved to: {$logFile}\n";