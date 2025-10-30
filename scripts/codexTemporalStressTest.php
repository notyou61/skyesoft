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

for ($i = 0; $i < $iterations; $i++) {
    $now = time();
    $entry = [
        'timestamp' => date('Y-m-d H:i:s', $now),
        'unix' => $now,
        'codexVersion' => 'v5.3.8',
        'isWorkday' => date('N', $now) <= 5,
        'isWorktime' => (date('H', $now) >= 7 && date('H', $now) < 15),
        'minutesSinceStart' => (date('H', $now) - 7) * 60 + date('i', $now),
        'minutesUntilEnd' => (15 - date('H', $now)) * 60 - date('i', $now),
        'runtimeDrift' => microtime(true) - floor(microtime(true))
    ];
    $entries[] = $entry;
    usleep(100000); // 0.1s delay for realism
}

// Append or create log
if (file_exists($logPath)) {
    $existing = json_decode(file_get_contents($logPath), true);
    if (is_array($existing)) $entries = array_merge($existing, $entries);
}

file_put_contents($logPath, json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo "✅ Temporal stress test complete.\n📄 Log saved to: {$logPath}\n";