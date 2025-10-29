<?php
// =============================================================
// 🧪 Skyesoft Temporal Stress Test – Continuous Logger
// Version: 2.0 | PHP 5.6 Compatible
// =============================================================

date_default_timezone_set('America/Phoenix');

// --- Log path ---
$logFile = 'C:\\Users\\steve\\OneDrive\\Documents\\skyesoft\\logs\\codexTemporalStressReport.json';
if (!is_dir(dirname($logFile))) mkdir(dirname($logFile), 0755, true);

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

// --- Stress loop (10 samples) ---
$iterations = 100;
echo "Running temporal stress test ({$iterations} iterations)...\n";

for ($i = 1; $i <= $iterations; $i++) {
    $nowUnix = time();
    $systemTime = date('Y-m-d H:i:s');
    $elapsed = max(0, $nowUnix - $workStart);
    $remaining = max(0, $workEnd - $nowUnix);
    $minutesSince = (int)round($elapsed / 60);
    $minutesLeft  = (int)round($remaining / 60);
    $drift = round(microtime(true) - $startTime, 4); // local runtime drift

    $entry = array(
        'iteration' => $i,
        'timestamp' => $systemTime,
        'unix'      => $nowUnix,
        'hostname'  => php_uname('n'),
        'status'    => '✅ Iteration logged successfully',
        'summary'   => array(
            'minutesSinceStart' => $minutesSince,
            'minutesUntilEnd'   => $minutesLeft,
            'isWorkday'         => ($nowUnix >= $workStart && $nowUnix <= $workEnd),
            'runtimeDrift'      => $drift
        )
    );

    append_json($logFile, $entry);

    echo "Iteration {$i}/{$iterations} complete — Drift: {$drift}s\n";
    usleep(100000); // 0.1 sec pause between samples
}

echo "\n✅ Temporal stress test complete.\n";
echo "📄 Log saved to: {$logFile}\n";