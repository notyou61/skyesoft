<?php
// =============================================================
// 🧪 Skyesoft Holiday Rollover Tester (Codex v5.4.2-Aligned)
// PHP 5.6 compatible – verifies next-year rollover & fallback logic
// =============================================================

date_default_timezone_set('America/Phoenix');

// --- Load Codex holiday registry
$codexPath = __DIR__ . '/../assets/data/codex.json';
$holidays = array();
if (file_exists($codexPath)) {
    $codex = json_decode(file_get_contents($codexPath), true);
    if (isset($codex['timeIntervalStandards']['holidayRegistry']['holidays'])) {
        $holidays = $codex['timeIntervalStandards']['holidayRegistry']['holidays'];
    }
}

// --- Simulation dates
$testDates = array(
    '2025-12-24',
    '2025-12-25',
    '2025-12-26',
    '2025-12-31',
    '2026-01-01',
    '2026-01-02'
);

foreach ($testDates as $testDate) {
    $now = strtotime($testDate . ' 10:00:00');
    $currentYear = (int) date('Y', $now);
    $todayUnix = strtotime($testDate);
    $nextHoliday = null;

    // 🅰️  A. Same-year search
    foreach ($holidays as $h) {
        if (!isset($h['rule'])) continue;
        $rule = $h['rule'];
        $ts = strtotime($rule . ' ' . $currentYear);
        if ($ts && $ts > $todayUnix) {   // ← changed from >= to >
            $nextHoliday = array(
                'name' => $h['name'],
                'date' => date('Y-m-d', $ts),
                'rollover' => false
            );
            break;
        }
    }


    // 🅱️  B. Year-rollover (after final holiday)
    if (!$nextHoliday && !empty($holidays)) {
        foreach ($holidays as $h) {
            if (!isset($h['categories']) || !isset($h['rule'])) continue;
            $cats = array_map('strtolower', $h['categories']);
            // Only roll forward for company/federal holidays
            if (in_array('company', $cats) || in_array('federal', $cats)) {
                $rule = $h['rule'];
                $ts = strtotime($rule . ' ' . ($currentYear + 1));
                if ($ts) {
                    $nextHoliday = array(
                        'name' => $h['name'],
                        'date' => date('Y-m-d', $ts),
                        'rollover' => true
                    );
                    error_log("🎆 Year rollover → " . $nextHoliday['name'] . " (" . $nextHoliday['date'] . ")");
                    break;
                }
            }
        }
    }

    // 🅲️  C. Fallback safety
    if (!$nextHoliday) {
        $nextHoliday = array(
            'name' => 'Undetermined (Provisional)',
            'date' => date('Y-m-d', strtotime('+7 days', $now)),
            'rollover' => true
        );
        error_log("⚠️ Fallback Tier 3 → provisional 7-day placeholder used.");
    }

    // --- Output
    echo "📅 Test Date: " . date('Y-m-d', $now) . "\n";
    echo "➡️  Next Holiday: " . $nextHoliday['name'] . " on " . $nextHoliday['date'] .
         "  [rollover=" . ($nextHoliday['rollover'] ? 'true' : 'false') . "]\n";
    echo "----------------------------------------\n";
}
