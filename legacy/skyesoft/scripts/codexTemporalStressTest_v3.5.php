<?php
// =============================================================
// 🧪 Skyesoft Temporal Stress Test Harness (v3.5)
// Purpose : Simulate 21 time scenarios for Codex temporal logic
// Compatible : PHP 5.6+
// =============================================================
date_default_timezone_set('America/Phoenix');

// --- Log Path ---
$logFile = __DIR__ . '/../logs/codexTemporalStressReport.json';
if (!is_dir(dirname($logFile))) mkdir(dirname($logFile), 0755, true);

// --- Helper ---
function addEntry(&$arr, $label, $simDate, $result) {
    $arr[] = array_merge([
        'label' => $label,
        'simDate' => $simDate,
    ], $result);
}

// --- Define 21 scenarios (MTCO set) ---
$scenarios = [
 ['Pre-Workday','2025-10-30 06:00:00'],
 ['Worktime Midpoint','2025-10-30 10:00:00'],
 ['Post-Workday','2025-10-30 17:00:00'],
 ['Weekend Sat','2025-11-01 09:00:00'],
 ['Weekend Sun','2025-11-02 09:00:00'],
 ['Thanksgiving','2025-11-27 12:00:00'],
 ['Day After Holiday','2025-11-28 09:00:00'],
 ['Christmas Day','2025-12-25 10:00:00'],
 ['After Christmas','2025-12-26 09:00:00'],
 ['New Year Eve Late','2025-12-31 23:50:00'],
 ['New Year Day','2026-01-01 10:00:00'],
 ['Leap Day','2028-02-29 10:00:00'],
 ['DST Start','2026-03-08 02:30:00'],
 ['DST End','2026-11-01 01:30:00'],
 ['Solar Sunrise','2025-10-29 06:44:00'],
 ['Solar Sunset','2025-10-29 17:39:00'],
 ['Post New Year','2026-01-02 09:00:00'],
 ['Short Week Monday','2025-07-07 08:00:00'],
 ['End Of Quarter','2025-09-30 15:29:00'],
 ['Mid-Night Roll','2025-10-30 00:01:00'],
 ['Future Forecast','2026-12-31 09:00:00']
];

// --- Constants (Phoenix office) ---
$workStart = strtotime('07:30:00');
$workEnd   = strtotime('15:30:00');
$holidays = [
    '2025-11-27'=>'Thanksgiving Day',
    '2025-11-28'=>'Day After Thanksgiving',
    '2025-12-25'=>'Christmas Day',
    '2026-01-01'=>'New Year\'s Day',
    '2028-02-29'=>'Leap Day (test)',
];
$results = [];

// --- Iterate scenarios ---
foreach ($scenarios as $s) {
    list($label,$sim) = $s;
    $ts = strtotime($sim);
    $day = date('Y-m-d',$ts);
    $time = date('H:i',$ts);
    $weekday = date('N',$ts);

    // Workday/worktime logic
    $isWeekend = ($weekday >= 6);
    $isHoliday = isset($holidays[$day]);
    $isWorkday = (!$isWeekend && !$isHoliday);
    $simSeconds = strtotime($time);
    $isWorktime = ($isWorkday && $simSeconds >= $workStart && $simSeconds <= $workEnd);

    // Next holiday lookup
    $nextHoliday = null;
    foreach ($holidays as $hDate=>$hName) {
        if (strtotime($hDate) > $ts) { $nextHoliday = $hName; break; }
    }
    if (!$nextHoliday) $nextHoliday = "New Year's Day (Next Year)";

    // DST flag (simulation)
    $isDST = (date('I',$ts) == 1);

    // Build result
    $result = [
        'isWeekend'=>$isWeekend,
        'isHoliday'=>$isHoliday,
        'isWorkday'=>$isWorkday,
        'isWorktime'=>$isWorktime,
        'weekdayNumber'=>$weekday,
        'isDST'=>$isDST,
        'nextHoliday'=>$nextHoliday,
        'unix'=>$ts,
        'runtimeDrift'=>round(abs(time()-$ts),4)
    ];
    addEntry($results,$label,$sim,$result);
}

// --- Write log ---
file_put_contents($logFile,json_encode($results,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));

echo "✅ Temporal stress test complete.\n";
echo "📄 Log saved to: {$logFile}\n";
echo "Scenarios simulated: " . count($results) . "\n";