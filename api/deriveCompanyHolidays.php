<?php
// 📁 File: api/deriveCompanyHolidays.php
// 🧭 Purpose: Derive actual holiday dates from Codex (holidayRegistry.holidays)
// 🔧 PHP 5.6 Compatible | DRY | Works in CLI, HTTP, or internal include

#region 🪪 Headers & Mode Detection
$isCli = (php_sapi_name() === 'cli');
if (!$isCli) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache');
    header('Access-Control-Allow-Origin: *');
}
#endregion

#region ⚙️ Initialization & Codex Load
$year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');
$codexPath = realpath(__DIR__ . '/../assets/data/codex.json');

if (!$codexPath || !is_readable($codexPath)) {
    returnOutput(array('error' => '❌ Codex not found or unreadable.', 'path' => $codexPath));
    exit;
}

$codex = json_decode(file_get_contents($codexPath), true);
if (!is_array($codex)) {
    returnOutput(array('error' => '❌ Invalid Codex JSON structure.'));
    exit;
}

if (!isset($codex['timeIntervalStandards']['holidayRegistry']['holidays'])) {
    returnOutput(array('error' => '❌ Missing holidayRegistry.holidays in Codex.'));
    exit;
}

$registry = $codex['timeIntervalStandards']['holidayRegistry']['holidays'];
#endregion

#region 🧮 Holiday Resolver
function resolveHolidayDate($rule, $year) {
    // Fixed date (e.g. "Jan 1")
    if (preg_match('/^[A-Za-z]{3,9}\s+\d{1,2}$/', $rule)) {
        return date('Y-m-d', strtotime($rule . ' ' . $year));
    }

    // Nth weekday of month
    if (preg_match('/(First|Second|Third|Fourth)\s+([A-Za-z]+)\s+of\s+([A-Za-z]+)/i', $rule, $m)) {
        $nthMap = array('First'=>1,'Second'=>2,'Third'=>3,'Fourth'=>4);
        $n = $nthMap[ucfirst(strtolower($m[1]))];
        $weekday = ucfirst(strtolower($m[2]));
        $month = ucfirst(strtolower($m[3]));
        $monthNum = date('n', strtotime($month . ' 1 ' . $year));

        $firstDay = strtotime("$year-$monthNum-01");
        $firstWeekday = date('N', $firstDay);
        $targetWeekday = date('N', strtotime($weekday));
        $offsetDays = ($targetWeekday - $firstWeekday + 7) % 7;
        $day = 1 + $offsetDays + 7 * ($n - 1);

        return date('Y-m-d', mktime(0, 0, 0, $monthNum, $day, $year));
    }

    // Last weekday of month
    if (preg_match('/Last\s+([A-Za-z]+)\s+of\s+([A-Za-z]+)/i', $rule, $m)) {
        $weekday = ucfirst(strtolower($m[1]));
        $month = ucfirst(strtolower($m[2]));
        $monthNum = date('n', strtotime($month . ' 1 ' . $year));

        $lastDay = date('t', strtotime("$year-$monthNum-01"));
        $lastDate = strtotime("$year-$monthNum-$lastDay");
        $targetWeekday = date('N', strtotime($weekday));
        $lastWeekday = date('N', $lastDate);
        $offsetDays = ($lastWeekday - $targetWeekday + 7) % 7;
        $day = $lastDay - $offsetDays;

        return date('Y-m-d', mktime(0, 0, 0, $monthNum, $day, $year));
    }

    return null;
}
#endregion

#region 🧩 Compute Derived List
$derived = array();
foreach ($registry as $h) {
    $date = resolveHolidayDate($h['rule'], $year);
    if ($date) {
        $derived[] = array(
            'name'     => $h['name'],
            'date'     => $date,
            'category' => isset($h['category']) ? $h['category'] : 'unspecified'
        );
    }
}
#endregion

#region 📤 Unified Output Function
function returnOutput($data) {
    $isCli = (php_sapi_name() === 'cli');
    $isInternal = defined('SKYESOFT_INTERNAL');

    if ($isInternal) {
        // Used inside getDynamicData.php
        if (isset($data['holidays'])) return $data['holidays'];
        return $data;
    }

    // Print JSON if run via CLI or web
    if ($isCli || !headers_sent()) {
        echo json_encode($data, JSON_PRETTY_PRINT);
    }
    return $data;
}
#endregion

#region 🚀 Finalize and Output
$result = array(
    'status'   => 'ok',
    'year'     => $year,
    'count'    => count($derived),
    'holidays' => $derived
);

returnOutput($result);
#endregion