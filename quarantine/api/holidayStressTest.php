<?php
/**
 * 🧪 Skyesoft Holiday Stress Test (PHP 5.6-safe, direct file output)
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Explicit paths
$codexPath = 'C:/Users/steve/OneDrive/Documents/skyesoft/assets/data/codex.json';
$outputPath = dirname(__DIR__) . '/holidayStressResults.txt';

// Confirm codex file exists
if (!file_exists($codexPath)) {
    file_put_contents($outputPath, "❌ Codex not found at: $codexPath\n");
    exit;
}

// Load and parse Codex
$codexRaw = file_get_contents($codexPath);
$codex = json_decode($codexRaw, true);
if (!$codex || !isset($codex['timeIntervalStandards']['holidayRegistry']['holidays'])) {
    file_put_contents($outputPath, "❌ Codex invalid or missing holiday registry.\n");
    exit;
}

$registry = $codex['timeIntervalStandards']['holidayRegistry']['holidays'];

// --- Date resolver ---
function resolveHolidayDate($rule, $year) {
    if (preg_match('/^[A-Za-z]{3,9}\s+\d{1,2}$/', $rule))
        return date('Y-m-d', strtotime("$rule $year"));
    if (preg_match('/(First|Second|Third|Fourth)\s+([A-Za-z]+)\s+of\s+([A-Za-z]+)/i', $rule, $m)) {
        $nth = array('First'=>1,'Second'=>2,'Third'=>3,'Fourth'=>4);
        $n = $nth[ucfirst(strtolower($m[1]))];
        $weekday = ucfirst(strtolower($m[2]));
        $month = ucfirst(strtolower($m[3]));
        $monthNum = date('n', strtotime("$month 1 $year"));
        $firstDay = strtotime("$year-$monthNum-01");
        $firstWeekday = date('N', $firstDay);
        $targetWeekday = date('N', strtotime($weekday));
        $offset = ($targetWeekday - $firstWeekday + 7) % 7;
        $day = 1 + $offset + 7 * ($n - 1);
        return date('Y-m-d', mktime(0,0,0,$monthNum,$day,$year));
    }
    if (preg_match('/Last\s+([A-Za-z]+)\s+of\s+([A-Za-z]+)/i', $rule, $m)) {
        $weekday = ucfirst(strtolower($m[1]));
        $month = ucfirst(strtolower($m[2]));
        $monthNum = date('n', strtotime("$month 1 $year"));
        $lastDay = date('t', strtotime("$year-$monthNum-01"));
        $lastDate = strtotime("$year-$monthNum-$lastDay");
        $targetWeekday = date('N', strtotime($weekday));
        $lastWeekday = date('N', $lastDate);
        $offset = ($lastWeekday - $targetWeekday + 7) % 7;
        $day = $lastDay - $offset;
        return date('Y-m-d', mktime(0,0,0,$monthNum,$day,$year));
    }
    return null;
}

// --- Derive company holidays ---
$year = date('Y');
$holidays = array();
foreach ($registry as $h) {
    if (strtolower($h['category']) !== 'company') continue;
    $date = resolveHolidayDate($h['rule'], $year);
    if ($date) $holidays[] = array('name'=>$h['name'],'date'=>$date);
}

// --- Helper funcs ---
function isHoliday($date,$holidays){foreach($holidays as $h)if($h['date']===$date)return true;return false;}
function isWorkday($date,$holidays){$d=date('w',strtotime($date));return($d!=0&&$d!=6&&!isHoliday($date,$holidays));}

// --- Compose output ---
$out = "🧪 Skyesoft Holiday Stress Test ($year)\r\n\r\n";
$out .= str_pad("Date",12).str_pad("Day",14)."Type\r\n";
$out .= str_repeat("-",42)."\r\n";

foreach (range(1,12) as $m){
    $days=cal_days_in_month(CAL_GREGORIAN,$m,$year);
    for($d=1;$d<=$days;$d++){
        $date=sprintf('%04d-%02d-%02d',$year,$m,$d);
        $dayName=date('l',strtotime($date));
        $type=isHoliday($date,$holidays)?'2 (Company Holiday)':(!isWorkday($date,$holidays)?'1 (Weekend)':'0 (Workday)');
        $out.=str_pad($date,12).str_pad($dayName,14).$type."\r\n";
    }
}

$out.="\r\nDerived ".count($holidays)." company holidays:\r\n";
foreach($holidays as $h){$out.="• {$h['name']} ({$h['date']})\r\n";}
$out.="✅ Test completed at ".date('Y-m-d H:i:s')."\r\n";

file_put_contents($outputPath,$out);
echo "✅ Results written to: $outputPath\n";
flush();
