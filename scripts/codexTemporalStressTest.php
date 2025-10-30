<?php
// =============================================================
// 🧪 Skyesoft Temporal Stress Test
// Version: 1.6 | PHP 5.6+ | Codex v5.3.8
// =============================================================
date_default_timezone_set('America/Phoenix');

$logPath = __DIR__ . '/../logs/codexTemporalStressReport.json';
$dir = dirname($logPath);
if (!is_dir($dir)) mkdir($dir, 0755, true);

$iterations = 100;
$entries = [];

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